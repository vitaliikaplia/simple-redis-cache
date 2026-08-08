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
	private bool $debug_header;
	private bool $finalized = false;
	private bool $released = false;
	private bool $content_version_prepared = false;
	private string $content_resource_type = '';
	private int $content_resource_id = 0;
	private string $content_version = '';

	/** @param array<string, mixed> $context */
	private function __construct( array $context ) {
		$this->context     = $context;
		$config            = (array) ( $context['config'] ?? array() );
		$this->page_config = (array) ( $config['page'] ?? array() );
		$this->debug_header = ! empty( $context['debug_header'] ) || ! empty( $this->page_config['debug_header'] );
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

		if ( $capture->debug_header && function_exists( 'add_action' ) ) {
			add_action( 'send_headers', array( $capture, 'send_debug_header' ), PHP_INT_MAX );
		}

		if ( $capture->uses_content_versions() ) {
			if ( ! empty( $context['deferred_logged_in'] ) ) {
				$capture->prepare_content_version();
			} elseif ( function_exists( 'add_action' ) ) {
				add_action( 'wp', array( $capture, 'prepare_content_version' ), PHP_INT_MAX );
			}
		}

		if ( ob_start( array( $capture, 'handle_output' ) ) ) {
			// Claim the lock for as long as the buffer is open, so a shutdown callback
			// that runs before the buffer is finalized cannot drop it.
			Simple_Redis_Cache_Advanced_Cache_Loader::hold_lock_for_capture( (string) ( $context['lock_key'] ?? '' ) );
			return;
		}

		$capture->release_lock();
	}

	/**
	 * Output-buffer callback. The original body is always returned unchanged.
	 *
	 * A discarded buffer reports PHP_OUTPUT_HANDLER_FINAL just like a flushed one,
	 * so FINAL alone cannot tell "the visitor received this" from "this was thrown
	 * away". Anything that calls ob_end_clean() late — a maintenance-mode plugin
	 * replacing the response, or the common `echo apply_filters( 'x', ob_get_clean() )`
	 * pattern — would otherwise store a document nobody was served. Normal
	 * termination reports phase 9 (FINAL|START); a discard reports 11 (adds CLEAN).
	 */
	public function handle_output( string $body, int $phase = 0 ): string {
		if ( $this->finalized || 0 === ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return $body;
		}

		if ( 0 !== ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
			// Discarded, so there is nothing to store and nothing left to protect.
			Simple_Redis_Cache_Advanced_Cache_Loader::finish_capture_lock( (string) ( $this->context['lock_key'] ?? '' ) );
			$this->release_lock();
			return $body;
		}

		$this->finalized = true;
		// The buffer is being finalized now, so the lock no longer has to outlive a
		// premature shutdown release.
		Simple_Redis_Cache_Advanced_Cache_Loader::finish_capture_lock( (string) ( $this->context['lock_key'] ?? '' ) );

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

		// Only record the release when it actually happened. A shutdown callback that
		// runs before the buffer is finalized is declined, and must not stop the real
		// release from being attempted again once the payload has been written.
		if ( Simple_Redis_Cache_Advanced_Cache_Loader::release_lock( $this->context ) ) {
			$this->released = true;
		}
	}

	/**
	 * Snapshot the queried post or taxonomy term's content version after the main
	 * query is known and before its template renders. The final write rechecks it.
	 *
	 * @param mixed $wp Unused value supplied by the WordPress `wp` action.
	 */
	public function prepare_content_version( mixed $wp = null ): void {
		unset( $wp );
		if ( $this->content_version_prepared ) {
			return;
		}
		$this->content_version_prepared = true;
		$resource = $this->queried_content_resource();
		if ( null === $resource ) {
			return;
		}
		$this->content_resource_type = $resource['type'];
		$this->content_resource_id   = $resource['id'];

		$redis = $this->context['redis'] ?? null;
		if ( ! $redis instanceof Simple_Redis_Cache_Redis ) {
			return;
		}

		$version = $redis->page_content_version( $this->content_resource_type, $this->content_resource_id );
		if ( is_string( $version ) ) {
			$this->content_version = $version;
		}
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

		$resource_type    = '';
		$resource_id      = 0;
		$resource_version = '';
		if ( $this->uses_content_versions() ) {
			if ( ! $this->content_version_prepared ) {
				return;
			}

			$resource = $this->queried_content_resource();
			if (
				( null === $resource && '' !== $this->content_resource_type ) ||
				( null !== $resource && ( $resource['type'] !== $this->content_resource_type || $resource['id'] !== $this->content_resource_id ) ) ||
				( null !== $resource && '' === $this->content_version )
			) {
				return;
			}
			if ( null !== $resource ) {
				$resource_type    = $resource['type'];
				$resource_id      = $resource['id'];
				$resource_version = $this->content_version;
			}
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
				'version'   => 3,
				'status'    => $status,
				'type'      => $type,
				'headers'   => $headers,
				'body'      => $body,
				'stored_at' => time(),
				'resource_type' => $resource_type,
				'resource_id' => $resource_id,
				'resource_version' => $resource_version,
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
		$arguments = array( $lock_key, $cache_key, $lock_token, $payload, (string) $ttl );
		$key_count = 2;

		if ( $resource_id > 0 ) {
			$config       = (array) ( $this->context['config'] ?? array() );
			$versions_key = Simple_Redis_Cache_Early_Config::page_content_versions_key( $config );
			$script       = <<<'LUA'
if redis.call('get', KEYS[1]) ~= ARGV[1] then
	return 0
end
if redis.call('hget', KEYS[3], ARGV[4]) ~= ARGV[5] then
	redis.call('del', KEYS[1])
	return -1
end
redis.call('set', KEYS[2], ARGV[2], 'EX', ARGV[3])
redis.call('del', KEYS[1])
return 1
LUA;
			$arguments = array( $lock_key, $cache_key, $versions_key, $lock_token, $payload, (string) $ttl, $resource_type . ':' . $resource_id, $resource_version );
			$key_count = 3;
		}

		$result = $client->eval( $script, $arguments, $key_count );

		if ( in_array( (int) $result, array( -1, 1 ), true ) ) {
			$this->released = true;
		}
	}

	private function uses_content_versions(): bool {
		return ! empty( $this->page_config['invalidate_on_post_update'] ) || ! empty( $this->page_config['invalidate_term_archives_on_post_update'] );
	}

	/** @return array{type:string,id:int}|null */
	private function queried_content_resource(): ?array {
		if ( ! function_exists( 'get_queried_object' ) ) {
			return null;
		}

		$object = get_queried_object();
		if ( ! empty( $this->page_config['invalidate_on_post_update'] ) && class_exists( 'WP_Post', false ) && $object instanceof WP_Post ) {
			$post_id   = (int) $object->ID;
			$post_type = $post_id > 0 && function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : false;
			if ( is_string( $post_type ) && '' !== $post_type && function_exists( 'get_post_type_object' ) && function_exists( 'is_post_type_viewable' ) ) {
				$post_type_object = get_post_type_object( $post_type );
				if ( is_object( $post_type_object ) && is_post_type_viewable( $post_type_object ) ) {
					return array( 'type' => 'post', 'id' => $post_id );
				}
			}
		}

		if (
			! empty( $this->page_config['invalidate_term_archives_on_post_update'] ) &&
			class_exists( 'WP_Term', false ) &&
			$object instanceof WP_Term &&
			function_exists( 'get_taxonomy' ) &&
			function_exists( 'is_taxonomy_viewable' )
		) {
			$term_taxonomy_id = (int) $object->term_taxonomy_id;
			$taxonomy_object  = get_taxonomy( (string) $object->taxonomy );
			if ( $term_taxonomy_id > 0 && is_object( $taxonomy_object ) && is_taxonomy_viewable( $taxonomy_object ) ) {
				return array( 'type' => 'term', 'id' => $term_taxonomy_id );
			}
		}

		return null;
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
