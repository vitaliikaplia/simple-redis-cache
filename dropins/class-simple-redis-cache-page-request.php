<?php
/**
 * Dependency-free request validation and page-cache key generation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

final class Simple_Redis_Cache_Page_Request {
	private const UNSAFE_QUERY_PARAMETERS = array(
		'_wpnonce',
		'_wp_http_referer',
		'preview',
		'preview_id',
		'preview_nonce',
		'customize_changeset_uuid',
		'customize_theme',
		'customize_messenger_channel',
		'rest_route',
		// Comment-moderation links from wp-comments-post.php render a pending comment
		// for the author. Core only limits that view for ten minutes at render time,
		// so caching it would expose the pending comment for the whole page TTL.
		'unapproved',
		'moderation-hash',
		'wc-ajax',
		'add-to-cart',
		'remove_item',
		'undo_item',
		'edd_action',
		'doing_wp_cron',
		'elementor-preview',
		'fl_builder',
		'et_fb',
		'bricks',
	);

	private bool $head_request;
	private bool $auth_cookie_variant;
	private string $canonical;

	private function __construct( bool $head_request, bool $auth_cookie_variant, string $canonical ) {
		$this->head_request        = $head_request;
		$this->auth_cookie_variant = $auth_cookie_variant;
		$this->canonical           = $canonical;
	}

	/**
	 * Validate the current request using only data available before WordPress and
	 * the database have loaded.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function from_globals( array $config ): ?self {
		$page = (array) ( $config['page'] ?? array() );
		$site = (array) ( $config['site'] ?? array() );

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return null;
		}

		if (
			( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ||
			( defined( 'WP_ADMIN' ) && WP_ADMIN ) ||
			( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
			( defined( 'DOING_CRON' ) && DOING_CRON ) ||
			( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
			( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ||
			( defined( 'WP_CLI' ) && WP_CLI )
		) {
			return null;
		}

		if (
			'' !== (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) ||
			'' !== (string) ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) ||
			'' !== (string) ( $_SERVER['PHP_AUTH_USER'] ?? '' )
		) {
			return null;
		}

		/*
		 * A cached 200 response cannot safely implement HTTP preconditions or byte
		 * ranges. Let WordPress/the web server produce the correct 304, 412, or 206.
		 * An explicit request no-store directive also remains a bypass. Browser
		 * reload directives such as no-cache, max-age=0, and Pragma: no-cache do
		 * not bypass the server-side Redis cache.
		 */
		if ( self::bypasses_cache_by_request_headers() ) {
			return null;
		}

		$request_authority = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
		$scheme            = self::request_scheme();
		$configured_host   = self::normalize_host( (string) ( $site['host'] ?? '' ) );
		$request_host      = self::normalize_host( $request_authority );
		if ( '' === $configured_host || '' === $request_host || $configured_host !== $request_host ) {
			return null;
		}

		$request_port    = self::normalize_port( $request_authority, $scheme );
		$configured_port = max( 0, (int) ( $site['port'] ?? 0 ) );
		if ( $request_port < 1 || ( $configured_port > 0 && $request_port !== $configured_port ) ) {
			return null;
		}

		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( '' === $request_uri || preg_match( '/[\x00-\x1F\x7F]/', $request_uri ) ) {
			return null;
		}

		$parts = parse_url( $request_uri );
		if ( false === $parts || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		if ( isset( $parts['host'] ) && self::normalize_host( (string) $parts['host'] ) !== $configured_host ) {
			return null;
		}

		$path = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( ! str_starts_with( $path, '/' ) || preg_match( '/%(?![0-9A-Fa-f]{2})/', $path ) ) {
			return null;
		}

		$decoded_path = rawurldecode( $path );
		if (
			preg_match( '/[\x00-\x1F\x7F]/', $decoded_path ) ||
			self::is_system_path( $decoded_path ) ||
			self::matches_path_list( $decoded_path, (array) ( $page['excluded_paths'] ?? array() ) )
		) {
			return null;
		}

		$user_agent = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		foreach ( (array) ( $page['excluded_user_agents'] ?? array() ) as $pattern ) {
			if ( self::pattern_matches( $user_agent, (string) $pattern, true, true ) ) {
				return null;
			}
		}

		$cookies        = is_array( $_COOKIE ?? null ) ? $_COOKIE : array();
		$auth_cookies   = array();
		$vary_cookies   = array();
		$vary_patterns  = array_values( array_map( 'strval', (array) ( $page['vary_cookies'] ?? array() ) ) );
		$exclude_patterns = array_values( array_map( 'strval', (array) ( $page['excluded_cookies'] ?? array() ) ) );

		foreach ( $cookies as $cookie_name => $cookie_value ) {
			if ( ! is_scalar( $cookie_name ) || ! is_scalar( $cookie_value ) ) {
				return null;
			}

			$cookie_name  = (string) $cookie_name;
			$cookie_value = (string) $cookie_value;
			$is_auth      = self::is_wordpress_auth_cookie( $cookie_name );
			$is_vary      = self::cookie_name_matches( $cookie_name, $vary_patterns );

			if ( $is_auth ) {
				if ( empty( $page['cache_logged_in'] ) ) {
					return null;
				}
				$auth_cookies[ $cookie_name ] = hash( 'sha256', $cookie_value );
			}

			if ( $is_vary ) {
				$vary_cookies[ $cookie_name ] = hash( 'sha256', $cookie_value );
			}

			foreach ( $exclude_patterns as $exclude_pattern ) {
				if ( ! self::cookie_name_matches( $cookie_name, array( $exclude_pattern ) ) ) {
					continue;
				}

				// Logged-in caching replaces the two default auth exclusions with
				// a per-session key. Explicit non-auth exclusions still win.
				if ( $is_auth && ! empty( $page['cache_logged_in'] ) && self::is_default_auth_pattern( $exclude_pattern ) ) {
					continue;
				}

				// A configured vary cookie is explicitly safe to vary by value.
				if ( $is_vary ) {
					continue;
				}

				return null;
			}
		}

		$query = self::canonical_query(
			(string) ( $parts['query'] ?? '' ),
			(array) ( $page['ignored_query_parameters'] ?? array() )
		);
		if ( null === $query ) {
			return null;
		}

		if ( '' !== $query['query'] && empty( $page['cache_query_strings'] ) ) {
			$search_parameters = array( 's', 'paged', 'post_type' );
			$is_search_query   = ! empty( $page['cache_search'] ) && in_array( 's', $query['names'], true );
			if ( ! $is_search_query || array_diff( $query['names'], $search_parameters ) ) {
				return null;
			}
		}

		ksort( $auth_cookies, SORT_STRING );
		ksort( $vary_cookies, SORT_STRING );
		sort( $vary_patterns, SORT_STRING );

		$cookie_variant = hash(
			'sha256',
			(string) json_encode(
				array(
					'auth'      => $auth_cookies,
					'vary_spec' => $vary_patterns,
					'vary'      => $vary_cookies,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);

		$canonical = implode(
			"\n",
			array(
				'simple-redis-cache-page-v1',
				$scheme,
				$configured_host,
				(string) $request_port,
				$path,
				$query['query'],
				$cookie_variant,
			)
		);

		return new self( 'HEAD' === $method, ! empty( $auth_cookies ), $canonical );
	}

	/** @param array<string, mixed> $config */
	public function cache_key( int $generation, array $config ): string {
		return Simple_Redis_Cache_Early_Config::prefix( $config )
			. 'page:'
			. max( 1, $generation )
			. ':'
			. hash( 'sha256', $this->canonical );
	}

	public function is_head(): bool {
		return $this->head_request;
	}

	/**
	 * Whether the key contains a WordPress auth-cookie variant. Such requests
	 * must boot WordPress and validate the session before a cached body is served.
	 */
	public function has_auth_cookie_variant(): bool {
		return $this->auth_cookie_variant;
	}

	/**
	 * Match a cookie name against exact names, wildcard patterns, or conventional
	 * WordPress prefix patterns ending in an underscore.
	 *
	 * @param string[] $patterns
	 */
	public static function cookie_name_matches( string $name, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}

			if ( str_contains( $pattern, '*' ) || str_contains( $pattern, '?' ) ) {
				if ( self::pattern_matches( $name, $pattern, false, false ) ) {
					return true;
				}
			} elseif ( $name === $pattern || ( str_ends_with( $pattern, '_' ) && str_starts_with( $name, $pattern ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function normalize_host( string $host ): string {
		$host = trim( strtolower( $host ) );
		if ( '' === $host || preg_match( '/[\x00-\x20\x7F\/@\\\\]/', $host ) ) {
			return '';
		}
		if ( filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return trim( $host, '[]' );
		}

		$parts = parse_url( 'http://' . $host );
		if (
			! is_array( $parts ) ||
			! is_string( $parts['host'] ?? null ) ||
			'' === $parts['host'] ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			isset( $parts['path'] ) ||
			isset( $parts['query'] ) ||
			isset( $parts['fragment'] ) ||
			( isset( $parts['port'] ) && ( $parts['port'] < 1 || $parts['port'] > 65535 ) )
		) {
			return '';
		}

		$parsed = trim( rtrim( strtolower( $parts['host'] ), '.' ), '[]' );
		return preg_match( '/^[a-z0-9.-]+$/', $parsed ) || filter_var( $parsed, FILTER_VALIDATE_IP ) ? $parsed : '';
	}

	private static function request_scheme(): string {
		$https = strtolower( (string) ( $_SERVER['HTTPS'] ?? '' ) );
		return ( '' !== $https && 'off' !== $https ) || '443' === (string) ( $_SERVER['SERVER_PORT'] ?? '' ) ? 'https' : 'http';
	}

	private static function normalize_port( string $authority, string $scheme ): int {
		$parts = parse_url( 'http://' . trim( $authority ) );
		if ( ! is_array( $parts ) ) {
			return 0;
		}

		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			return $port >= 1 && $port <= 65535 ? $port : 0;
		}

		return 'https' === $scheme ? 443 : 80;
	}

	private static function bypasses_cache_by_request_headers(): bool {
		foreach (
			array(
				'HTTP_RANGE',
				'HTTP_IF_RANGE',
				'HTTP_IF_MATCH',
				'HTTP_IF_NONE_MATCH',
				'HTTP_IF_MODIFIED_SINCE',
				'HTTP_IF_UNMODIFIED_SINCE',
			) as $server_key
		) {
			$value = $_SERVER[ $server_key ] ?? '';
			if ( ! is_scalar( $value ) || '' !== trim( (string) $value ) ) {
				return true;
			}
		}

		$cache_control = $_SERVER['HTTP_CACHE_CONTROL'] ?? '';
		if ( ! is_scalar( $cache_control ) ) {
			return true;
		}

		foreach ( explode( ',', (string) $cache_control ) as $directive ) {
			$parts = explode( '=', trim( $directive ), 2 );
			$name  = strtolower( trim( $parts[0] ) );
			if ( 'no-store' === $name ) {
				return true;
			}
		}

		$pragma = $_SERVER['HTTP_PRAGMA'] ?? '';
		if ( ! is_scalar( $pragma ) ) {
			return true;
		}

		return false;
	}

	private static function is_system_path( string $path ): bool {
		$path = '/' . ltrim( strtolower( str_replace( '\\', '/', $path ) ), '/' );
		$exact_paths = array(
			'/wp-login.php',
			'/wp-register.php',
			'/wp-cron.php',
			'/wp-comments-post.php',
			'/xmlrpc.php',
			'/wp-trackback.php',
		);

		if ( in_array( rtrim( $path, '/' ), $exact_paths, true ) ) {
			return true;
		}

		foreach ( array( '/wp-admin', '/wp-json', '/wp-content', '/wp-includes' ) as $prefix ) {
			if ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match a request path against the configured exclusions.
	 *
	 * Matching is case-insensitive. An administrator writing /My-Account/* means the
	 * same page as /my-account/, and a silently ineffective exclusion is far worse
	 * than an extra bypass. This also matches how ignored query parameters behave.
	 *
	 * @param string[] $patterns
	 */
	private static function matches_path_list( string $path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}

			if ( ! str_starts_with( $pattern, '/' ) ) {
				$pattern = '/' . $pattern;
			}

			if ( str_ends_with( $pattern, '/*' ) && 0 === strcasecmp( rtrim( $path, '/' ), substr( $pattern, 0, -2 ) ) ) {
				return true;
			}

			if ( self::pattern_matches( $path, $pattern, true, false ) ) {
				return true;
			}
		}

		return false;
	}

	private static function is_wordpress_auth_cookie( string $name ): bool {
		return str_starts_with( $name, 'wordpress_logged_in_' ) || str_starts_with( $name, 'wordpress_sec_' );
	}

	private static function is_default_auth_pattern( string $pattern ): bool {
		return in_array( rtrim( $pattern, '*' ), array( 'wordpress_logged_in_', 'wordpress_sec_' ), true );
	}

	/**
	 * @param string[] $ignored_patterns
	 * @return array{query: string, names: string[]}|null
	 */
	private static function canonical_query( string $query, array $ignored_patterns ): ?array {
		if ( '' === $query ) {
			return array( 'query' => '', 'names' => array() );
		}

		$pairs = array();
		$names = array();
		foreach ( explode( '&', $query ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$parts     = explode( '=', $segment, 2 );
			$raw_name  = $parts[0];
			$raw_value = $parts[1] ?? '';
			if ( preg_match( '/%(?![0-9A-Fa-f]{2})/', $raw_name . $raw_value ) ) {
				return null;
			}

			$name  = urldecode( $raw_name );
			$value = urldecode( $raw_value );
			if ( '' === $name || preg_match( '/[\x00-\x1F\x7F]/', $name . $value ) ) {
				return null;
			}

			/*
			 * Compare the name WordPress will actually receive, not the raw one. PHP
			 * rewrites query-parameter names before they reach $_GET, so "rest.route"
			 * arrives as "rest_route" and ".wpnonce" as "_wpnonce". Matching the raw
			 * form would let most of the unsafe list slip through and cache, say, a
			 * REST response or a page rendering a pending comment.
			 */
			$php_name   = self::php_variable_name( $name );
			$lower_name = strtolower( $name );
			$bracket    = strpos( $lower_name, '[' );
			$base_name  = false === $bracket ? $lower_name : substr( $lower_name, 0, $bracket );
			if (
				in_array( $php_name, self::UNSAFE_QUERY_PARAMETERS, true ) ||
				in_array( $lower_name, self::UNSAFE_QUERY_PARAMETERS, true ) ||
				in_array( $base_name, self::UNSAFE_QUERY_PARAMETERS, true )
			) {
				return null;
			}

			$ignored = false;
			foreach ( $ignored_patterns as $ignored_pattern ) {
				if ( self::pattern_matches( $php_name, (string) $ignored_pattern, true, false ) ) {
					$ignored = true;
					break;
				}
			}
			if ( $ignored ) {
				continue;
			}

			$pairs[] = array( $name, $value );
			$names[] = $php_name;
		}

		/*
		 * Preserve query-pair order. PHP gives order semantic meaning for repeated
		 * parameters (the last scalar value wins) and for bracket arrays. Sorting
		 * would let two requests that produce different $_GET values share a key.
		 */
		$encoded = array_map(
			static fn( array $pair ): string => rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] ),
			$pairs
		);

		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );

		return array( 'query' => implode( '&', $encoded ), 'names' => $names );
	}

	/**
	 * Reproduce the query-parameter name WordPress will actually see.
	 *
	 * PHP's php_register_variable_ex() drops leading spaces, then rewrites every
	 * space, dot and "[" to an underscore — except that a "[" which is actually
	 * closed later starts an array, in which case the name is truncated there.
	 * So "a[b]" becomes "a", but the unterminated "a[b" becomes "a_b". Any check
	 * that reasons about what WordPress will do with a parameter has to reason
	 * about this name, not the one on the wire.
	 */
	private static function php_variable_name( string $name ): string {
		$name    = ltrim( $name, ' ' );
		$bracket = strpos( $name, '[' );
		if ( false !== $bracket && false !== strpos( $name, ']', $bracket ) ) {
			$name = substr( $name, 0, $bracket );
		}

		return strtolower( strtr( $name, ' .[', '___' ) );
	}

	private static function pattern_matches( string $value, string $pattern, bool $case_insensitive, bool $substring_without_wildcard ): bool {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			return false;
		}

		$has_wildcards = str_contains( $pattern, '*' ) || str_contains( $pattern, '?' );
		if ( $substring_without_wildcard && ! $has_wildcards ) {
			return $case_insensitive ? false !== stripos( $value, $pattern ) : false !== strpos( $value, $pattern );
		}

		$quoted = preg_quote( $pattern, '~' );
		$quoted = str_replace( array( '\\*', '\\?' ), array( '.*', '.' ), $quoted );
		return 1 === preg_match( '~^' . $quoted . '$~' . ( $case_insensitive ? 'i' : '' ), $value );
	}
}
