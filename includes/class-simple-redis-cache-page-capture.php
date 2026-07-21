<?php
/**
 * Captures a rendered WordPress HTML response and stores it in Redis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Page_Capture {
	/** @var array<string, mixed> */
	private array $context;
	/** @var array<string, mixed> */
	private array $page_config;
	private bool $finalized = false;
	private bool $released = false;

	/** @param array<string, mixed> $context */
	private function __construct( array $context ) {
		$this->context     = $context;
		$config            = (array) ( $context['config'] ?? array() );
		$this->page_config = (array) ( $config['page'] ?? array() );
	}

	/** @param array<string, mixed> $context */
	public static function start( array $context ): void {
		$redis = $context['redis'] ?? null;
		if (
			! $redis instanceof Simple_Redis_Cache_Redis ||
			'' === (string) ( $context['cache_key'] ?? '' ) ||
			'' === (string) ( $context['lock_key'] ?? '' ) ||
			'' === (string) ( $context['lock_token'] ?? '' )
		) {
			Simple_Redis_Cache_Advanced_Cache_Loader::release_lock( $context );
			return;
		}

		$capture = new self( $context );
		register_shutdown_function( array( $capture, 'release_lock' ) );

		if ( ! empty( $capture->page_config['debug_header'] ) && function_exists( 'add_action' ) ) {
			add_action( 'send_headers', array( $capture, 'send_debug_header' ), PHP_INT_MAX );
		}

		if ( ! ob_start( array( $capture, 'handle_output' ) ) ) {
			$capture->release_lock();
		}
	}

	/**
	 * Output-buffer callback. The original body is always returned unchanged.
	 */
	public function handle_output( string $body, int $phase = 0 ): string {
		if ( $this->finalized || 0 === ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return $body;
		}

		$this->finalized = true;

		try {
			$this->maybe_store( $body );
		} catch ( Throwable ) {
			// Rendering must succeed even when validation or Redis fails.
		} finally {
			$this->release_lock();
		}

		return $body;
	}

	/** @param mixed $wp */
	public function send_debug_header( mixed $wp = null ): void {
		unset( $wp );
		if ( headers_sent() ) {
			return;
		}

		$value = null === $this->page_type() ? 'BYPASS' : 'MISS';
		header( 'X-Simple-Redis-Cache: ' . $value, true );
	}

	public function release_lock(): void {
		if ( $this->released ) {
			return;
		}

		$this->released = true;
		Simple_Redis_Cache_Advanced_Cache_Loader::release_lock( $this->context );
	}

	private function maybe_store( string $body ): void {
		if (
			( function_exists( 'is_multisite' ) && is_multisite() ) ||
			( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ||
			( defined( 'WP_ADMIN' ) && WP_ADMIN ) ||
			( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
			( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
			( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
		) {
			return;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return;
		}

		$validated_user_id = max( 0, (int) ( $this->context['validated_user_id'] ?? 0 ) );
		$is_logged_in      = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$current_user_id   = $is_logged_in && function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		// A user-specific response is cacheable only through the deferred path that
		// validated the exact WordPress logged-in cookie/session for this key.
		if (
			( $is_logged_in && ( $validated_user_id < 1 || $current_user_id !== $validated_user_id ) ) ||
			( $validated_user_id > 0 && ( ! $is_logged_in || $current_user_id !== $validated_user_id ) )
		) {
			return;
		}

		foreach ( array( 'is_feed', 'is_preview', 'is_trackback', 'is_robots', 'is_favicon', 'is_embed' ) as $conditional ) {
			if ( function_exists( $conditional ) && $conditional() ) {
				return;
			}
		}

		if (
			( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ||
			( function_exists( 'post_password_required' ) && post_password_required() )
		) {
			return;
		}

		$status = http_response_code();
		$status = false === $status ? 200 : (int) $status;
		$type   = $this->page_type();
		if ( null === $type || ( 404 === $status && '404' !== $type ) ) {
			return;
		}

		if ( ( '404' === $type && 404 !== $status ) || ( '404' !== $type && 200 !== $status ) ) {
			return;
		}

		if ( ! self::is_complete_html( $body ) ) {
			return;
		}

		$headers = $this->cacheable_headers( $type, $status, $validated_user_id > 0 );
		if ( null === $headers ) {
			return;
		}

		$redis = $this->context['redis'] ?? null;
		if ( ! $redis instanceof Simple_Redis_Cache_Redis ) {
			return;
		}

		$client = $redis->client();
		if ( null === $client ) {
			return;
		}

		$payload = serialize(
			array(
				'version'   => 1,
				'status'    => $status,
				'type'      => $type,
				'headers'   => $headers,
				'body'      => $body,
				'stored_at' => time(),
			)
		);

		$cache_key = (string) ( $this->context['cache_key'] ?? '' );
		$lock_key  = (string) ( $this->context['lock_key'] ?? '' );
		$lock_token = (string) ( $this->context['lock_token'] ?? '' );
		$ttl       = min( 31 * DAY_IN_SECONDS, max( 1, (int) ( $this->page_config['ttl'] ?? HOUR_IN_SECONDS ) ) );
		if ( '' === $cache_key || '' === $lock_key || '' === $lock_token ) {
			return;
		}

		// Store only while this request still owns the stampede lock, then
		// release that exact lock atomically. A slow renderer can never delete or
		// overwrite a newer request's lock/value after its own lock expires.
		$script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
	redis.call('set', KEYS[2], ARGV[2], 'EX', ARGV[3])
	redis.call('del', KEYS[1])
	return 1
end
return 0
LUA;

		$result = $client->eval(
			$script,
			array( $lock_key, $cache_key, $lock_token, $payload, (string) $ttl ),
			2
		);

		if ( 1 === (int) $result ) {
			$this->released = true;
		}
	}

	private function page_type(): ?string {
		if ( function_exists( 'is_404' ) && is_404() ) {
			return ! empty( $this->page_config['cache_404'] ) ? '404' : null;
		}

		if ( function_exists( 'is_search' ) && is_search() ) {
			return ! empty( $this->page_config['cache_search'] ) ? 'search' : null;
		}

		if (
			( function_exists( 'is_front_page' ) && is_front_page() ) ||
			( function_exists( 'is_home' ) && is_home() )
		) {
			return ! empty( $this->page_config['cache_home'] ) ? 'home' : null;
		}

		if ( function_exists( 'is_singular' ) && is_singular() ) {
			return ! empty( $this->page_config['cache_singular'] ) ? 'singular' : null;
		}

		if ( function_exists( 'is_archive' ) && is_archive() ) {
			return ! empty( $this->page_config['cache_archives'] ) ? 'archive' : null;
		}

		return null;
	}

	/**
	 * Validate response headers and return only headers safe to replay. Set-Cookie
	 * headers may allow storage by explicit name, but are never stored themselves.
	 *
	 * @return array<int, array{name: string, value: string}>|null
	 */
	private function cacheable_headers( string $type, int $status, bool $validated_logged_in ): ?array {
		$safe_headers    = array();
		$allowed_cookies = array_values( array_map( 'strval', (array) ( $this->page_config['allowed_set_cookies'] ?? array() ) ) );
		$anonymous_404      = ! $validated_logged_in && '404' === $type && 404 === $status;
		$allow_core_nocache = $validated_logged_in || $anonymous_404;

		foreach ( headers_list() as $line ) {
			if ( ! is_string( $line ) || preg_match( '/[\r\n\x00]/', $line ) ) {
				return null;
			}

			$parts = explode( ':', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$name       = trim( $parts[0] );
			$value      = trim( $parts[1] );
			$lower_name = strtolower( $name );

			if ( 'set-cookie' === $lower_name ) {
				if ( ! preg_match( '/^([^=;\s]+)=/', $value, $cookie_match ) ) {
					return null;
				}
				if ( ! Simple_Redis_Cache_Page_Request::cookie_name_matches( $cookie_match[1], $allowed_cookies ) ) {
					return null;
				}
				continue;
			}

			if ( in_array( $lower_name, array( 'authorization', 'clear-site-data', 'location', 'refresh', 'www-authenticate' ), true ) ) {
				return null;
			}

			if (
				'cache-control' === $lower_name &&
				preg_match( '/(?:^|[\s,])(?:private|no-store|no-cache)(?:[\s,=]|$)/i', $value )
			) {
				if ( ! $allow_core_nocache || ! self::is_standard_wordpress_nocache_control( $value, $validated_logged_in || $anonymous_404 ) ) {
					return null;
				}
			}

			if ( 'pragma' === $lower_name && false !== stripos( $value, 'no-cache' ) ) {
				return null;
			}

			if ( 'vary' === $lower_name ) {
				$vary_fields = array_values(
					array_filter(
						array_map(
							static fn( string $field ): string => strtolower( trim( $field ) ),
							explode( ',', $value )
						),
						'strlen'
					)
				);

				/*
				 * The page key does not vary by arbitrary request headers. Accept-Encoding
				 * is safe because PHP stores the uncompressed body and the web server may
				 * encode it afterwards. Any other Vary dimension must bypass storage.
				 */
				if ( empty( $vary_fields ) || array_diff( $vary_fields, array( 'accept-encoding' ) ) ) {
					return null;
				}
			}

			if ( 'content-type' === $lower_name ) {
				$content_type = strtolower( trim( explode( ';', $value, 2 )[0] ) );
				if ( ! in_array( $content_type, array( 'text/html', 'application/xhtml+xml' ), true ) ) {
					return null;
				}
			}

			if ( 'content-encoding' === $lower_name ) {
				return null;
			}

			if ( self::is_replayable_header( $name, $value ) ) {
				$safe_headers[] = array( 'name' => $name, 'value' => $value );
			}
		}

		return $safe_headers;
	}

	/**
	 * Recognize only WordPress core's standard no-cache policy. This exception is
	 * used for validated per-session pages and opted-in anonymous 404 responses;
	 * arbitrary private/no-store application responses remain uncacheable.
	 */
	private static function is_standard_wordpress_nocache_control( string $value, bool $allow_legacy = false ): bool {
		$directives = array_values(
			array_filter(
				array_map(
					static function ( string $directive ): string {
						$directive = strtolower( trim( $directive ) );
						return (string) preg_replace( '/\s*=\s*/', '=', $directive );
					},
					explode( ',', $value )
				),
				'strlen'
			)
		);

		sort( $directives, SORT_STRING );
		$legacy = array( 'max-age=0', 'must-revalidate', 'no-cache' );
		$current = array( 'max-age=0', 'must-revalidate', 'no-cache', 'no-store', 'private' );
		sort( $legacy, SORT_STRING );
		sort( $current, SORT_STRING );

		return $directives === $current || ( $allow_legacy && $directives === $legacy );
	}

	private static function is_complete_html( string $body ): bool {
		if ( '' === $body || str_contains( $body, "\0" ) ) {
			return false;
		}

		if (
			! self::has_safe_document_start( $body ) ||
			1 !== preg_match( '/<body(?:\s|>)/i', $body ) ||
			1 !== preg_match( '/<\/body\s*>/i', $body )
		) {
			return false;
		}

		$closing = strripos( $body, '</html' );
		if ( false === $closing ) {
			return false;
		}

		$closing_fragment = substr( $body, $closing );
		if ( 1 !== preg_match( '/^<\/html\s*>/i', $closing_fragment, $closing_match ) ) {
			return false;
		}

		// Some plugins append diagnostics after the document. Only whitespace and
		// complete HTML comments are safe after the final closing tag.
		$tail = substr( $closing_fragment, strlen( $closing_match[0] ) );
		while ( '' !== trim( $tail ) ) {
			$tail = ltrim( $tail );
			if ( ! str_starts_with( $tail, '<!--' ) ) {
				return false;
			}

			$comment_end = strpos( $tail, '-->' );
			if ( false === $comment_end ) {
				return false;
			}

			$tail = substr( $tail, $comment_end + 3 );
		}

		return true;
	}

	/**
	 * Allow only normal document preamble tokens before the opening html element.
	 * PHP warnings, debug output, or other arbitrary text must never enter a
	 * public page-cache payload.
	 */
	private static function has_safe_document_start( string $body ): bool {
		$remaining = str_starts_with( $body, "\xEF\xBB\xBF" ) ? substr( $body, 3 ) : $body;

		while ( true ) {
			$remaining = ltrim( $remaining );
			if ( 1 === preg_match( '/^<html(?:\s|>)/i', $remaining ) ) {
				return true;
			}

			if ( str_starts_with( $remaining, '<!--' ) ) {
				$comment_end = strpos( $remaining, '-->' );
				if ( false === $comment_end ) {
					return false;
				}
				$remaining = substr( $remaining, $comment_end + 3 );
				continue;
			}

			if ( 1 === preg_match( '/^<!doctype\b[^>]*>/i', $remaining, $doctype ) ) {
				$remaining = substr( $remaining, strlen( $doctype[0] ) );
				continue;
			}

			if ( 1 === preg_match( '/^<\?xml\b.*?\?>/is', $remaining, $xml ) ) {
				$remaining = substr( $remaining, strlen( $xml[0] ) );
				continue;
			}

			return false;
		}
	}

	private static function is_replayable_header( string $name, string $value ): bool {
		if ( ! preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name ) || preg_match( '/[\r\n\x00]/', $value ) ) {
			return false;
		}

		$blocked = array(
			'authorization',
			'clear-site-data',
			'connection',
			'content-encoding',
			'content-length',
			'content-md5',
			'keep-alive',
			'location',
			'proxy-authenticate',
			'proxy-authorization',
			'refresh',
			'server',
			'set-cookie',
			'te',
			'trailer',
			'transfer-encoding',
			'upgrade',
			'www-authenticate',
			'x-powered-by',
			'x-simple-redis-cache',
		);

		return ! in_array( strtolower( $name ), $blocked, true );
	}
}
