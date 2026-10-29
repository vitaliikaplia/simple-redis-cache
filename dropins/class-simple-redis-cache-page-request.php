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

	/**
	 * A vary-cookie value has to look like a language or locale code. Anything
	 * else bypasses, so an anonymous client cannot mint a cache variant per value.
	 */
	private const VARY_COOKIE_VALUE_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/D';

	private bool $head_request;
	private bool $auth_cookie_variant;
	private string $canonical;
	private string $variant_group;
	private bool $storable;
	/** @var array<string, string> */
	private array $vary_cookie_values;

	/** @param array<string, string> $vary_cookie_values */
	private function __construct( bool $head_request, bool $auth_cookie_variant, string $canonical, string $variant_group, bool $storable, array $vary_cookie_values ) {
		$this->head_request        = $head_request;
		$this->auth_cookie_variant = $auth_cookie_variant;
		$this->canonical           = $canonical;
		$this->variant_group       = $variant_group;
		$this->storable            = $storable;
		$this->vary_cookie_values  = $vary_cookie_values;
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

		/*
		 * WordPress resolves /News/Post/ and /news/post/ to the same 200 page, so
		 * every case variant would become its own cache entry. Slugs are always
		 * lower case; a path that is not may still be served from cache but is
		 * never stored. Percent-encoding is normalized for the same reason.
		 */
		$lower_path   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $decoded_path, 'UTF-8' ) : strtolower( $decoded_path );
		$case_variant = $lower_path !== $decoded_path;
		$normalized   = self::normalize_path_encoding( $path );
		$path         = $normalized['path'];
		$case_variant = $case_variant || $normalized['encoded_unreserved'];

		$user_agent = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		foreach ( (array) ( $page['excluded_user_agents'] ?? array() ) as $pattern ) {
			if ( self::pattern_matches( $user_agent, (string) $pattern, true, true ) ) {
				return null;
			}
		}

		$cookies        = is_array( $_COOKIE ?? null ) ? $_COOKIE : array();
		$auth_cookies   = array();
		$vary_cookies   = array();
		$vary_values    = array();
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
				if ( 1 !== preg_match( self::VARY_COOKIE_VALUE_PATTERN, $cookie_value ) ) {
					return null;
				}
				$vary_cookies[ $cookie_name ] = hash( 'sha256', $cookie_value );
				$vary_values[ $cookie_name ]  = $cookie_value;
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
		ksort( $vary_values, SORT_STRING );
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

		// v2: the path is percent-encoding-normalized before it enters the key.
		$location  = array(
			'simple-redis-cache-page-v2',
			$scheme,
			$configured_host,
			(string) $request_port,
			$path,
			$query['query'],
		);
		$canonical = implode( "\n", array_merge( $location, array( $cookie_variant ) ) );

		/*
		 * Ignored parameters leave the key but not $_GET or REQUEST_URI, and core
		 * pagination and language switchers echo them into links. Such a request
		 * may read the canonical page but must never store its own rendering.
		 */
		$storable = ! $case_variant && ! $query['ignored'];

		return new self( 'HEAD' === $method, ! empty( $auth_cookies ), $canonical, hash( 'sha256', implode( "\n", $location ) ), $storable, $vary_values );
	}

	/**
	 * One URL regardless of its cookie variants. The write path counts how many
	 * vary-cookie variants each URL holds, so cookie combinations cannot multiply.
	 */
	public function variant_group(): string {
		return $this->variant_group;
	}

	/**
	 * Whether a MISS for this request may be written to the cache. A request that
	 * may not still reads an existing entry under the same key.
	 */
	public function is_storable(): bool {
		return $this->storable;
	}

	/**
	 * Vary-cookie names and their raw values for this request, sorted by name.
	 * A page varied by cookie must never reach a shared cache keyed only by URL.
	 *
	 * @return array<string, string>
	 */
	public function vary_cookie_values(): array {
		return $this->vary_cookie_values;
	}

	/**
	 * Normalize the spellings WordPress itself treats as one path: escapes use
	 * upper-case hex and raw bytes outside the path character set are escaped,
	 * because core sanitizes both forms of a slug alike.
	 *
	 * An escaped unreserved character is different. WordPress matches the raw
	 * path first, so /n%65ws/ looks up a slug "n%65ws" and renders a 404 where
	 * /news/ renders the post. Decoding it would let anyone store that 404 under
	 * the live page's key; it stays escaped instead, and the request is reported
	 * so it is never stored at all.
	 *
	 * @return array{path: string, encoded_unreserved: bool}
	 */
	private static function normalize_path_encoding( string $path ): array {
		$encoded_unreserved = false;
		$normalized         = (string) preg_replace_callback(
			'~%[0-9A-Fa-f]{2}|[^A-Za-z0-9\-._\~!$&\'()*+,;=:@/]~',
			static function ( array $match ) use ( &$encoded_unreserved ): string {
				if ( '%' !== $match[0][0] || 1 === strlen( $match[0] ) ) {
					return '%' . strtoupper( bin2hex( $match[0] ) );
				}

				if ( 1 === preg_match( '/^[A-Za-z0-9\-._~]$/', chr( (int) hexdec( substr( $match[0], 1 ) ) ) ) ) {
					$encoded_unreserved = true;
				}
				return '%' . strtoupper( substr( $match[0], 1 ) );
			},
			$path
		);

		return array( 'path' => $normalized, 'encoded_unreserved' => $encoded_unreserved );
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
	 * @return array{query: string, names: string[], ignored: bool}|null
	 */
	private static function canonical_query( string $query, array $ignored_patterns ): ?array {
		if ( '' === $query ) {
			return array( 'query' => '', 'names' => array(), 'ignored' => false );
		}

		$pairs   = array();
		$names   = array();
		$ignored = false;
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

			$is_ignored = false;
			foreach ( $ignored_patterns as $ignored_pattern ) {
				if ( self::pattern_matches( $php_name, (string) $ignored_pattern, true, false ) ) {
					$is_ignored = true;
					break;
				}
			}
			if ( $is_ignored ) {
				$ignored = true;
				continue;
			}

			$pairs[] = array( $name, $value );
			// PHP keys are case-sensitive: ?S= is not WordPress's search parameter.
			$names[] = self::php_variable_name( $name, false );
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

		return array( 'query' => implode( '&', $encoded ), 'names' => $names, 'ignored' => $ignored );
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
	private static function php_variable_name( string $name, bool $lowercase = true ): string {
		$name    = ltrim( $name, ' ' );
		$bracket = strpos( $name, '[' );
		if ( false !== $bracket && false !== strpos( $name, ']', $bracket ) ) {
			$name = substr( $name, 0, $bracket );
		}

		$name = strtr( $name, ' .[', '___' );
		return $lowercase ? strtolower( $name ) : $name;
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
