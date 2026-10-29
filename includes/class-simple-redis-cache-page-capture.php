<?php
/**
 * Captures a rendered WordPress HTML response and stores it in Redis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Page_Capture {
	/** The token shared by every listing page: home, generic archives, search, 404. */
	public const LISTING_RESOURCE_ID = 1;

	/** A stored payload larger than this is not worth the Redis memory. */
	private const MAX_PAYLOAD_BYTES = 2097152;

	/**
	 * Vary-cookie variants one URL may hold per page generation. Real language
	 * cookies give one variant per language however many of them a plugin sets;
	 * the cap stops arbitrary values and their combinations from minting keys.
	 */
	private const MAX_VARIANTS_PER_URL = 16;

	/** @var array<string, mixed> */
	private array $context;
	/** @var array<string, mixed> */
	private array $page_config;
	private bool $debug_header;
	private bool $finalized = false;
	private bool $released = false;
	private bool $content_version_prepared = false;
	/** @var array<int, array{type:string,id:int,version:string}> Resources snapshotted before rendering. */
	private array $content_resources = array();
	/** Whether the vary-cookie values name one of the site's languages; null until checked. */
	private ?bool $vary_values_known = null;

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

		/*
		 * Language plugins are asked before rendering, never from the output
		 * handler: PHP forbids output buffering inside one, and any third-party
		 * code that starts a buffer there would be a fatal error.
		 */
		if ( ! empty( $context['vary_values'] ) ) {
			if ( ! empty( $context['deferred_logged_in'] ) ) {
				$capture->prepare_vary_values();
			} elseif ( function_exists( 'add_action' ) ) {
				add_action( 'wp', array( $capture, 'prepare_vary_values' ), PHP_INT_MAX );
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
	 * Snapshot the content versions of everything this page is bound to after the
	 * main query is known and before its template renders. The final write
	 * rechecks each of them atomically.
	 *
	 * @param mixed $wp Unused value supplied by the WordPress `wp` action.
	 */
	public function prepare_content_version( mixed $wp = null ): void {
		unset( $wp );
		if ( $this->content_version_prepared ) {
			return;
		}
		$this->content_version_prepared = true;

		$redis = $this->context['redis'] ?? null;
		foreach ( $this->queried_content_resources() as $resource ) {
			$version = $redis instanceof Simple_Redis_Cache_Redis
				? $redis->page_content_version( $resource['type'], $resource['id'] )
				: false;
			// An empty version makes maybe_store() refuse: an unverifiable binding is no binding.
			$this->content_resources[] = array(
				'type'    => $resource['type'],
				'id'      => $resource['id'],
				'version' => is_string( $version ) ? $version : '',
			);
		}
	}

	/**
	 * Decide, while WordPress and its language plugins are fully loaded, whether
	 * this request's vary-cookie values name one of the site's languages.
	 *
	 * @param mixed $wp Unused value supplied by the WordPress `wp` action.
	 */
	public function prepare_vary_values( mixed $wp = null ): void {
		unset( $wp );
		if ( null === $this->vary_values_known ) {
			$this->vary_values_known = self::known_vary_values( array_map( 'strval', (array) ( $this->context['vary_values'] ?? array() ) ) );
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

		$resources = array();
		if ( $this->uses_content_versions() ) {
			if ( ! $this->content_version_prepared ) {
				return;
			}

			// The page must still be bound to exactly what was snapshotted, and every
			// snapshot must have produced a version.
			$current = $this->queried_content_resources();
			$snapped = array_map(
				static fn( array $resource ): array => array( 'type' => $resource['type'], 'id' => $resource['id'] ),
				$this->content_resources
			);
			if ( $current !== $snapped ) {
				return;
			}
			foreach ( $this->content_resources as $resource ) {
				if ( '' === $resource['version'] ) {
					return;
				}
			}
			$resources = $this->content_resources;
		}

		if ( ! self::is_complete_html( $body ) ) {
			return;
		}

		// Unchecked counts as unknown: the `wp` action never ran for this response.
		if ( ! empty( $this->context['vary_values'] ) && true !== $this->vary_values_known ) {
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

		/*
		 * Compress the body when it helps: a rendered page shrinks several-fold, and
		 * every anonymous 404, search and vary variant is a full page in memory.
		 */
		$encoding    = '';
		$stored_body = $body;
		if ( function_exists( 'gzdeflate' ) ) {
			$deflated = gzdeflate( $body, 6 );
			if ( is_string( $deflated ) && strlen( $deflated ) < strlen( $body ) ) {
				$encoding    = 'deflate';
				$stored_body = $deflated;
			}
		}

		$serialized = serialize(
			array(
				'version'   => 4,
				'status'    => $status,
				'type'      => $type,
				'headers'   => $headers,
				'body'      => $stored_body,
				'encoding'  => $encoding,
				'stored_at' => time(),
				'resources' => $resources,
			)
		);

		$config     = (array) ( $this->context['config'] ?? array() );
		$cache_key  = (string) ( $this->context['cache_key'] ?? '' );
		$lock_key   = (string) ( $this->context['lock_key'] ?? '' );
		$lock_token = (string) ( $this->context['lock_token'] ?? '' );
		if ( '' === $cache_key || '' === $lock_key || '' === $lock_token ) {
			return;
		}

		$payload = Simple_Redis_Cache_Early_Config::PAGE_PAYLOAD_PREFIX
			. Simple_Redis_Cache_Early_Config::page_payload_mac( $cache_key, $serialized, (string) ( $config['site']['secret'] ?? '' ) )
			. $serialized;
		if ( strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) {
			return;
		}

		$ttl = min( 31 * DAY_IN_SECONDS, max( 1, (int) ( $this->page_config['ttl'] ?? HOUR_IN_SECONDS ) ) );
		if ( in_array( $type, array( '404', 'search' ), true ) ) {
			$ttl = min( $ttl, Simple_Redis_Cache_Early_Config::SHORT_PAGE_TTL );
		}

		/*
		 * One atomic script: refuse if any bound content token changed since the
		 * snapshot (an update during rendering), refuse a new vary-cookie variant
		 * once its URL already holds MAX_VARIANTS_PER_URL in this generation,
		 * otherwise store. The renderer that took the lock stores even if the lock expired
		 * meanwhile — under load a render can outlast it, and refusing would leave
		 * the cache permanently cold. It only ever deletes the lock it still owns.
		 */
		$script = <<<'LUA'
local owns = redis.call('get', KEYS[1]) == ARGV[1]
local count = tonumber(ARGV[4])
local index = 6
for i = 1, count do
	if redis.call('hget', KEYS[3], ARGV[index]) ~= ARGV[index + 1] then
		if owns then redis.call('del', KEYS[1]) end
		return -1
	end
	index = index + 2
end
if KEYS[4] and redis.call('sismember', KEYS[4], ARGV[index]) == 0 then
	if redis.call('scard', KEYS[4]) >= tonumber(ARGV[5]) then
		if owns then redis.call('del', KEYS[1]) end
		return -2
	end
	redis.call('sadd', KEYS[4], ARGV[index])
	redis.call('expire', KEYS[4], 2678400)
end
redis.call('set', KEYS[2], ARGV[2], 'EX', ARGV[3])
if owns then redis.call('del', KEYS[1]) end
return 1
LUA;

		$keys      = array( $lock_key, $cache_key, Simple_Redis_Cache_Early_Config::page_content_versions_key( $config ) );
		$arguments = array( $lock_token, $payload, (string) $ttl, (string) count( $resources ), (string) self::MAX_VARIANTS_PER_URL );
		foreach ( $resources as $resource ) {
			$arguments[] = $resource['type'] . ':' . $resource['id'];
			$arguments[] = $resource['version'];
		}

		$variant_registry = (string) ( $this->context['variant_registry'] ?? '' );
		if ( '' !== $variant_registry ) {
			$keys[]      = $variant_registry;
			$arguments[] = substr( hash( 'sha256', $cache_key ), 0, 32 );
		}

		$result = $client->eval( $script, array_merge( $keys, $arguments ), count( $keys ) );

		if ( in_array( (int) $result, array( -2, -1, 1 ), true ) ) {
			$this->released = true;
		}
	}

	/**
	 * Whether the vary-cookie values together name one language the site serves.
	 *
	 * Each language is a group of every spelling a plugin may store in a cookie:
	 * Polylang's slug, locale and W3C tag; the code, locale and tag WPML and WP-LOC
	 * report through wpml_active_languages; WP-LOC's own slug and locale. Groups
	 * that share a spelling are one language. The simple_redis_cache_vary_cookie_values
	 * filter can add groups for other plugins. All values must fall in one group,
	 * so a mix such as an English slug with a Ukrainian locale — a combination no
	 * real visitor carries — is never stored. When no source knows any language,
	 * the per-URL variant cap is the only bound.
	 *
	 * @param array<string, string> $vary_values
	 */
	private static function known_vary_values( array $vary_values ): bool {
		$groups = array();

		if ( function_exists( 'pll_languages_list' ) ) {
			$fields = array();
			foreach ( array( 'slug', 'locale', 'w3c' ) as $field ) {
				$fields[] = array_values( (array) pll_languages_list( array( 'fields' => $field ) ) );
			}
			foreach ( $fields[0] as $index => $slug ) {
				$groups[] = array( $slug, $fields[1][ $index ] ?? '', $fields[2][ $index ] ?? '' );
			}
		}

		if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wpml_active_languages' ) ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $language ) {
				$group = array( $code );
				foreach ( array( 'code', 'language_code', 'default_locale', 'tag' ) as $field ) {
					if ( is_array( $language ) && is_scalar( $language[ $field ] ?? null ) ) {
						$group[] = $language[ $field ];
					}
				}
				$groups[] = $group;
			}
		}

		if ( class_exists( 'WP_LOC_Languages', false ) && method_exists( 'WP_LOC_Languages', 'get_active_languages' ) ) {
			foreach ( (array) WP_LOC_Languages::get_active_languages() as $slug => $language ) {
				$groups[] = array( $slug, is_array( $language ) && is_scalar( $language['locale'] ?? null ) ? $language['locale'] : '' );
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$groups = (array) apply_filters( 'simple_redis_cache_vary_cookie_values', $groups, $vary_values );
		}

		// Merge groups that share a spelling, so every plugin's view of one language joins up.
		$languages = array();
		foreach ( $groups as $group ) {
			$group = array_filter( array_map( 'strval', is_array( $group ) ? $group : array( $group ) ), 'strlen' );
			foreach ( $languages as $index => $language ) {
				if ( array_intersect( $language, $group ) ) {
					$group = array_merge( $group, $language );
					unset( $languages[ $index ] );
				}
			}
			if ( ! empty( $group ) ) {
				$languages[] = array_values( array_unique( $group ) );
			}
		}

		if ( empty( $languages ) ) {
			return true;
		}

		foreach ( $languages as $language ) {
			if ( ! array_diff( $vary_values, $language ) ) {
				return true;
			}
		}

		return false;
	}

	private function uses_content_versions(): bool {
		return ! empty( $this->page_config['invalidate_on_post_update'] ) ||
			! empty( $this->page_config['invalidate_term_archives_on_post_update'] ) ||
			! empty( $this->page_config['invalidate_listings_on_post_update'] );
	}

	/**
	 * Everything the current page's content depends on, in a stable order: the
	 * queried post or taxonomy term, plus the shared listing token for pages that
	 * list content rather than being one entry — the front page and posts page,
	 * generic archives, search results and 404s. A taxonomy archive uses its own
	 * term token when that option is enabled and the listing token otherwise.
	 *
	 * @return array<int, array{type:string,id:int}>
	 */
	private function queried_content_resources(): array {
		$resources = array();
		$object    = function_exists( 'get_queried_object' ) ? get_queried_object() : null;

		if ( ! empty( $this->page_config['invalidate_on_post_update'] ) && class_exists( 'WP_Post', false ) && $object instanceof WP_Post ) {
			$post_id   = (int) $object->ID;
			$post_type = $post_id > 0 && function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : false;
			if ( is_string( $post_type ) && '' !== $post_type && function_exists( 'get_post_type_object' ) && function_exists( 'is_post_type_viewable' ) ) {
				$post_type_object = get_post_type_object( $post_type );
				if ( is_object( $post_type_object ) && is_post_type_viewable( $post_type_object ) ) {
					$resources[] = array( 'type' => 'post', 'id' => $post_id );
				}
			}
		}

		$term_bound = false;
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
				$resources[] = array( 'type' => 'term', 'id' => $term_taxonomy_id );
				$term_bound  = true;
			}
		}

		if ( ! empty( $this->page_config['invalidate_listings_on_post_update'] ) ) {
			$type = $this->page_type();
			if ( in_array( $type, array( 'home', 'search', '404' ), true ) || ( 'archive' === $type && ! $term_bound ) ) {
				$resources[] = array( 'type' => 'list', 'id' => self::LISTING_RESOURCE_ID );
			}
		}

		return $resources;
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
