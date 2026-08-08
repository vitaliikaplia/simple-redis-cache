<?php
/**
 * Early Redis full-page cache runtime.
 *
 * This file is loaded by wp-content/advanced-cache.php before WordPress has
 * loaded plugins or connected to the database. Every failure must therefore
 * fall through to a normal WordPress request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$simple_redis_cache_dir = dirname( __DIR__ );
$simple_redis_cache_dependencies = array(
	$simple_redis_cache_dir . '/includes/class-simple-redis-cache-early-config.php',
	$simple_redis_cache_dir . '/includes/class-simple-redis-cache-redis.php',
	__DIR__ . '/class-simple-redis-cache-page-request.php',
);

foreach ( $simple_redis_cache_dependencies as $simple_redis_cache_dependency ) {
	if ( ! is_readable( $simple_redis_cache_dependency ) ) {
		unset( $simple_redis_cache_dir, $simple_redis_cache_dependencies, $simple_redis_cache_dependency );
		return;
	}
	require_once $simple_redis_cache_dependency;
}

unset( $simple_redis_cache_dependencies, $simple_redis_cache_dependency );

if ( ! class_exists( 'Simple_Redis_Cache_Advanced_Cache_Loader', false ) ) {
	final class Simple_Redis_Cache_Advanced_Cache_Loader {
		/** @param array<string, mixed> $config */
		public static function run( array $config, string $plugin_dir ): void {
			$page  = (array) ( $config['page'] ?? array() );
			$debug = ! empty( $page['debug_header'] ) || self::is_warm_request();

			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				self::debug_header( $debug, 'BYPASS' );
				return;
			}

			if ( empty( $page['enabled'] ) ) {
				return;
			}

			$request = Simple_Redis_Cache_Page_Request::from_globals( $config );
			if ( null === $request ) {
				self::debug_header( $debug, 'BYPASS' );
				return;
			}

			$redis  = new Simple_Redis_Cache_Redis( $config );
			$client = $redis->client();
			if ( null === $client ) {
				self::debug_header( $debug, 'BYPASS' );
				return;
			}

			$generation = $redis->generation( 'page' );
			if ( null !== $redis->error() ) {
				self::debug_header( $debug, 'BYPASS' );
				return;
			}
			$cache_key  = $request->cache_key( $generation, $config );
			$context    = array(
				'config'             => $config,
				'redis'              => $redis,
				'cache_key'          => $cache_key,
				'head_request'       => $request->is_head(),
				'capture_file'       => $plugin_dir . '/includes/class-simple-redis-cache-page-capture.php',
				'deferred_logged_in' => $request->has_auth_cookie_variant(),
				'early_ob_level'      => ob_get_level(),
				'debug_header'        => $debug,
			);

			/*
			 * Never serve an auth-cookie variant before WordPress has validated the
			 * cookie and its session token. Logged-in cache handling is deferred until
			 * the end of template_redirect; a fake or revoked cookie is a bypass.
			 */
			if ( $request->has_auth_cookie_variant() ) {
				self::schedule_postload( $context, $debug );
				return;
			}

			try {
				$stored = $client->get( $cache_key );
			} catch ( Throwable ) {
				self::debug_header( $debug, 'BYPASS' );
				return;
			}

			if ( is_string( $stored ) && '' !== $stored ) {
				$payload = self::decode_payload( $stored, $page );
				if ( null !== $payload ) {
					$content_version_matches = self::payload_content_version_matches( $payload, $redis, $page );
					if ( null === $content_version_matches ) {
						self::debug_header( $debug, 'BYPASS' );
						return;
					}
					if ( $content_version_matches ) {
						if ( headers_sent() ) {
							return;
						}
						self::serve( $payload, $request->is_head(), $debug, (int) ( $page['shared_max_age'] ?? 0 ) );
					}
				}

				// An invalid or obsolete payload must never make the request fail.
				try {
					$client->del( $cache_key );
				} catch ( Throwable ) {
					// Fail open.
				}
			}

			self::debug_header( $debug, 'MISS' );

			// A HEAD miss cannot produce a reusable HTML body. It may still use a
			// future GET cache hit because GET and HEAD deliberately share a key.
			if ( $request->is_head() ) {
				return;
			}

			$context = self::acquire_lock( $context, $page );
			if ( null === $context ) {
				return;
			}

			self::schedule_postload( $context, $debug );
		}

		/** @param array<string, mixed> $context */
		private static function schedule_postload( array $context, bool $debug ): bool {
			if ( function_exists( 'wp_cache_postload' ) ) {
				self::release_lock( $context );
				self::debug_header( $debug, 'BYPASS' );
				return false;
			}

			$GLOBALS['simple_redis_cache_page_context'] = $context;

			/** Continue cache handling after WordPress and active plugins have loaded. */
			function wp_cache_postload(): void {
				$context = $GLOBALS['simple_redis_cache_page_context'] ?? null;
				unset( $GLOBALS['simple_redis_cache_page_context'] );

				if ( ! is_array( $context ) ) {
					return;
				}

				if ( ! empty( $context['deferred_logged_in'] ) ) {
					Simple_Redis_Cache_Advanced_Cache_Loader::defer_logged_in( $context );
					return;
				}

				Simple_Redis_Cache_Advanced_Cache_Loader::start_capture( $context );
			}

			return true;
		}

		/**
		 * Register the auth-aware late cache handler. No cached value has been read
		 * and no lock has been taken at this point.
		 *
		 * @param array<string, mixed> $context
		 */
		public static function defer_logged_in( array $context ): void {
			$config = (array) ( $context['config'] ?? array() );
			$page   = (array) ( $config['page'] ?? array() );
			$debug  = ! empty( $context['debug_header'] ) || ! empty( $page['debug_header'] );

			if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_filter' ) ) {
				self::debug_header( $debug, 'BYPASS' );
				return;
			}

			$GLOBALS['simple_redis_cache_logged_in_context'] = $context;
			add_action( 'template_redirect', array( self::class, 'register_logged_in_template_filter' ), PHP_INT_MAX );
		}

		/**
		 * Register after normal template_redirect access-control callbacks have had
		 * an opportunity to redirect, exit, or mark the response uncacheable.
		 */
		public static function register_logged_in_template_filter(): void {
			$context = $GLOBALS['simple_redis_cache_logged_in_context'] ?? null;
			if ( ! is_array( $context ) ) {
				return;
			}

			/*
			 * Core exits HEAD requests immediately after template_redirect, before the
			 * final template/access filters. Bypass private HEAD caching rather than
			 * serving before those checks. Anonymous HEAD hits remain available early.
			 */
			if ( ! empty( $context['head_request'] ) ) {
				unset( $GLOBALS['simple_redis_cache_logged_in_context'] );
				$config = (array) ( $context['config'] ?? array() );
				$page   = (array) ( $config['page'] ?? array() );
				self::debug_header( ! empty( $context['debug_header'] ) || ! empty( $page['debug_header'] ), 'BYPASS' );
				return;
			}

			add_filter( 'template_include', array( self::class, 'logged_in_template_include' ), PHP_INT_MAX );
		}

		/**
		 * Validate the WordPress logged-in cookie/session before consulting Redis.
		 * A valid HIT runs only after template_redirect and earlier template_include
		 * access/template filters. On a MISS, capture starts immediately before core
		 * includes the final returned template.
		 *
		 * @param mixed $template Final template path selected by WordPress.
		 * @return mixed
		 */
		public static function logged_in_template_include( mixed $template ): mixed {
			if ( ! self::is_last_template_include_callback() ) {
				$context = $GLOBALS['simple_redis_cache_logged_in_context'] ?? null;
				unset( $GLOBALS['simple_redis_cache_logged_in_context'] );
				if ( is_array( $context ) ) {
					$config = (array) ( $context['config'] ?? array() );
					$page   = (array) ( $config['page'] ?? array() );
					self::debug_header( ! empty( $context['debug_header'] ) || ! empty( $page['debug_header'] ), 'BYPASS' );
				}
				return $template;
			}

			return self::process_logged_in_template( $template );
		}

		/**
		 * Never exit from our HIT while another callback at the same maximum filter
		 * priority is still pending. This preserves late access/template filters
		 * registered after our dynamically-added callback.
		 */
		private static function is_last_template_include_callback(): bool {
			global $wp_filter;

			$hook = $wp_filter['template_include'] ?? null;
			if ( ! is_object( $hook ) || ! isset( $hook->callbacks[ PHP_INT_MAX ] ) || ! is_array( $hook->callbacks[ PHP_INT_MAX ] ) ) {
				return true;
			}

			$found_self = false;
			foreach ( $hook->callbacks[ PHP_INT_MAX ] as $callback ) {
				$function = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;
				if ( array( self::class, 'logged_in_template_include' ) === $function ) {
					$found_self = true;
					continue;
				}

				if ( $found_self ) {
					return false;
				}
			}

			return $found_self;
		}

		/**
		 * @param mixed $template
		 * @return mixed
		 */
		private static function process_logged_in_template( mixed $template ): mixed {
			$context = $GLOBALS['simple_redis_cache_logged_in_context'] ?? null;
			unset( $GLOBALS['simple_redis_cache_logged_in_context'] );

			if ( ! is_array( $context ) ) {
				return $template;
			}

			$config = (array) ( $context['config'] ?? array() );
			$page   = (array) ( $config['page'] ?? array() );
			$debug  = ! empty( $context['debug_header'] ) || ! empty( $page['debug_header'] );

			try {
				if (
					( function_exists( 'is_multisite' ) && is_multisite() ) ||
					( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ||
					headers_sent() ||
					! function_exists( 'wp_validate_auth_cookie' ) ||
					! function_exists( 'is_user_logged_in' ) ||
					! function_exists( 'get_current_user_id' )
				) {
					self::debug_header( $debug, 'BYPASS' );
					return $template;
				}

				$validated_user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
				if (
					$validated_user_id < 1 ||
					! is_user_logged_in() ||
					$validated_user_id !== (int) get_current_user_id()
				) {
					self::debug_header( $debug, 'BYPASS' );
					return $template;
				}

				$context = self::apply_user_access_variant( $context, $validated_user_id );
				if ( null === $context ) {
					self::debug_header( $debug, 'BYPASS' );
					return $template;
				}

				$current = self::current_response( $page );
				if ( null === $current || ! self::late_headers_allow_logged_in_cache() ) {
					self::debug_header( $debug, 'BYPASS' );
					return $template;
				}

				$redis = $context['redis'] ?? null;
				if ( ! $redis instanceof Simple_Redis_Cache_Redis ) {
					self::debug_header( $debug, 'BYPASS' );
					return $template;
				}

				$client = $redis->client();
				if ( null === $client ) {
					self::debug_header( $debug, 'BYPASS' );
					return $template;
				}

				$cache_key = (string) ( $context['cache_key'] ?? '' );
				try {
					$stored = '' !== $cache_key ? $client->get( $cache_key ) : false;
				} catch ( Throwable ) {
					self::debug_header( $debug, 'BYPASS' );
					return $template;
				}

				if ( is_string( $stored ) && '' !== $stored ) {
					$payload = self::decode_payload( $stored, $page );
					$content_version_matches = null !== $payload
						? self::payload_content_version_matches( $payload, $redis, $page )
						: false;
					if ( null === $content_version_matches ) {
						self::debug_header( $debug, 'BYPASS' );
						return $template;
					}
					if (
						null !== $payload &&
						$content_version_matches &&
						$payload['type'] === $current['type'] &&
						(int) $payload['status'] === $current['status']
					) {
						self::serve( $payload, ! empty( $context['head_request'] ), $debug, (int) ( $page['shared_max_age'] ?? 0 ) );
					}

					try {
						$client->del( $cache_key );
					} catch ( Throwable ) {
						// Fail open.
					}
				}

				self::debug_header( $debug, 'MISS' );
				if ( ! empty( $context['head_request'] ) ) {
					return $template;
				}

				$context = self::acquire_lock( $context, $page );
				if ( null !== $context ) {
					self::start_capture( $context );
				}
			} catch ( Throwable ) {
				self::release_lock( $context );
				self::debug_header( $debug, 'BYPASS' );
			}

			return $template;
		}

		/** @param array<string, mixed> $context */
		public static function start_capture( array $context ): void {
			/*
			 * An anonymous HIT exits before active plugins load. If a plugin opened an
			 * outer transforming buffer while its file loaded, storing from a later
			 * inner buffer would cache the pre-transform body and the early HIT could
			 * never reproduce that transformation. Bypass this uncommon setup safely.
			 * Logged-in HITs boot plugins and therefore do reproduce their buffers.
			 */
			if (
				empty( $context['deferred_logged_in'] ) &&
				ob_get_level() !== max( 0, (int) ( $context['early_ob_level'] ?? 0 ) )
			) {
				self::release_lock( $context );
				$config = (array) ( $context['config'] ?? array() );
				$page   = (array) ( $config['page'] ?? array() );
				self::debug_header( ! empty( $context['debug_header'] ) || ! empty( $page['debug_header'] ), 'BYPASS' );
				return;
			}

			$capture_file = (string) ( $context['capture_file'] ?? '' );
			if ( is_readable( $capture_file ) ) {
				require_once $capture_file;
			}

			if ( class_exists( 'Simple_Redis_Cache_Page_Capture', false ) ) {
				Simple_Redis_Cache_Page_Capture::start( $context );
				return;
			}

			self::release_lock( $context );
		}

		/**
		 * Add the current user's resolved roles/capabilities to the already
		 * session-specific key. WordPress does not revoke auth cookies on a role
		 * change, so this prevents a downgraded session from receiving privileged
		 * HTML cached under its previous capability set.
		 *
		 * @param array<string, mixed> $context
		 * @return array<string, mixed>|null
		 */
		private static function apply_user_access_variant( array $context, int $validated_user_id ): ?array {
			if ( ! function_exists( 'wp_get_current_user' ) ) {
				return null;
			}

			$user = wp_get_current_user();
			if ( ! is_object( $user ) || (int) ( $user->ID ?? 0 ) !== $validated_user_id ) {
				return null;
			}

			$roles = array_values( array_map( 'strval', is_array( $user->roles ?? null ) ? $user->roles : array() ) );
			sort( $roles, SORT_STRING );

			$resolved_caps = method_exists( $user, 'get_role_caps' ) ? $user->get_role_caps() : ( $user->allcaps ?? array() );
			if ( ! is_array( $resolved_caps ) ) {
				return null;
			}

			$caps = array();
			foreach ( $resolved_caps as $capability => $granted ) {
				$caps[ (string) $capability ] = (bool) $granted;
			}
			ksort( $caps, SORT_STRING );

			$encoded = json_encode(
				array(
					'user_id' => $validated_user_id,
					'roles'   => $roles,
					'caps'    => $caps,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			$cache_key = (string) ( $context['cache_key'] ?? '' );
			if ( false === $encoded || '' === $cache_key ) {
				return null;
			}

			$context['validated_user_id'] = $validated_user_id;
			$context['cache_key'] = $cache_key . ':access:' . hash( 'sha256', $encoded );

			return $context;
		}

		/**
		 * @param array<string, mixed> $context
		 * @param array<string, mixed> $page
		 * @return array<string, mixed>|null
		 */
		private static function acquire_lock( array $context, array $page ): ?array {
			$redis = $context['redis'] ?? null;
			if ( ! $redis instanceof Simple_Redis_Cache_Redis ) {
				return null;
			}

			$client    = $redis->client();
			$cache_key = (string) ( $context['cache_key'] ?? '' );
			if ( null === $client || '' === $cache_key ) {
				return null;
			}

			$lock_ttl = min( 60, max( 1, (int) ( $page['lock_ttl'] ?? 10 ) ) );
			$lock_key = $cache_key . ':lock';
			$token    = self::lock_token();

			try {
				$owns_lock = (bool) $client->set( $lock_key, $token, array( 'nx', 'ex' => $lock_ttl ) );
			} catch ( Throwable ) {
				return null;
			}

			if ( ! $owns_lock ) {
				return null;
			}

			$context['lock_key']   = $lock_key;
			$context['lock_token'] = $token;

			/*
			 * Anonymous locks are acquired before core registers its shutdown output
			 * flush. Registering a release callback there would delete the lock before
			 * Page_Capture can store. Its short TTL covers bootstrap fatals. Deferred
			 * authenticated locks are acquired after core's shutdown handler, so this
			 * extra release safely covers failures before capture starts.
			 */
			if ( ! empty( $context['validated_user_id'] ) ) {
				register_shutdown_function( array( self::class, 'release_lock' ), $context );
			}

			return $context;
		}

		/**
		 * Return the currently resolved cacheable WordPress response type/status.
		 *
		 * @param array<string, mixed> $page
		 * @return array{type:string,status:int}|null
		 */
		private static function current_response( array $page ): ?array {
			foreach ( array( 'is_feed', 'is_preview', 'is_trackback', 'is_robots', 'is_favicon', 'is_embed' ) as $conditional ) {
				if ( function_exists( $conditional ) && $conditional() ) {
					return null;
				}
			}

			if (
				( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ||
				( function_exists( 'post_password_required' ) && post_password_required() )
			) {
				return null;
			}

			$status = http_response_code();
			$status = false === $status ? 200 : (int) $status;
			if ( function_exists( 'is_404' ) && is_404() ) {
				return 404 === $status && ! empty( $page['cache_404'] ) ? array( 'type' => '404', 'status' => 404 ) : null;
			}

			if ( 200 !== $status ) {
				return null;
			}

			if ( function_exists( 'is_search' ) && is_search() ) {
				return ! empty( $page['cache_search'] ) ? array( 'type' => 'search', 'status' => 200 ) : null;
			}

			if (
				( function_exists( 'is_front_page' ) && is_front_page() ) ||
				( function_exists( 'is_home' ) && is_home() )
			) {
				return ! empty( $page['cache_home'] ) ? array( 'type' => 'home', 'status' => 200 ) : null;
			}

			if ( function_exists( 'is_singular' ) && is_singular() ) {
				return ! empty( $page['cache_singular'] ) ? array( 'type' => 'singular', 'status' => 200 ) : null;
			}

			if ( function_exists( 'is_archive' ) && is_archive() ) {
				return ! empty( $page['cache_archives'] ) ? array( 'type' => 'archive', 'status' => 200 ) : null;
			}

			return null;
		}

		/**
		 * Respect request-specific late redirect/auth/cache-control decisions that
		 * earlier template_redirect callbacks may already have made. WordPress core's
		 * standard logged-in no-cache policy is safe because the Redis entry is
		 * session-specific and the policy is preserved in the cached payload.
		 */
		private static function late_headers_allow_logged_in_cache(): bool {
			foreach ( headers_list() as $line ) {
				if ( ! is_string( $line ) || preg_match( '/[\r\n\x00]/', $line ) ) {
					return false;
				}

				$parts = explode( ':', $line, 2 );
				if ( 2 !== count( $parts ) ) {
					continue;
				}

				$name  = strtolower( trim( $parts[0] ) );
				$value = trim( $parts[1] );
				if ( in_array( $name, array( 'authorization', 'clear-site-data', 'content-encoding', 'location', 'refresh', 'www-authenticate' ), true ) ) {
					return false;
				}

				if (
					'cache-control' === $name &&
					preg_match( '/(?:^|[\s,])(?:private|no-store|no-cache)(?:[\s,=]|$)/i', $value ) &&
					! self::is_standard_wordpress_nocache_control( $value )
				) {
					return false;
				}

				if ( 'pragma' === $name && false !== stripos( $value, 'no-cache' ) ) {
					return false;
				}

				if ( 'vary' === $name ) {
					$vary_fields = array_values(
						array_filter(
							array_map(
								static fn( string $field ): string => strtolower( trim( $field ) ),
								explode( ',', $value )
							),
							'strlen'
						)
					);
					if ( empty( $vary_fields ) || array_diff( $vary_fields, array( 'accept-encoding' ) ) ) {
						return false;
					}
				}
			}

			return true;
		}

		private static function is_standard_wordpress_nocache_control( string $value ): bool {
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

			return $directives === $legacy || $directives === $current;
		}

		/** @param array<string, mixed> $context */
		public static function release_lock( array $context ): void {
			$redis = $context['redis'] ?? null;
			if ( ! $redis instanceof Simple_Redis_Cache_Redis ) {
				return;
			}

			$client = $redis->client();
			if ( null === $client ) {
				return;
			}

			$key   = (string) ( $context['lock_key'] ?? '' );
			$token = (string) ( $context['lock_token'] ?? '' );
			if ( '' === $key || '' === $token ) {
				return;
			}

			try {
				$client->eval(
					"if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
					array( $key, $token ),
					1
				);
			} catch ( Throwable ) {
				// The lock has a short TTL, so a Redis failure is safe to ignore.
			}
		}

		/**
		 * @param array<string, mixed> $page_config
		 * @return array{version: int, status: int, type: string, headers: array<int, array{name: string, value: string}>, body: string, stored_at: int, resource_type: string, resource_id: int, resource_version: string}|null
		 */
		private static function decode_payload( string $stored, array $page_config ): ?array {
			$payload = @unserialize( $stored, array( 'allowed_classes' => false ) );
			$version = is_array( $payload ) ? (int) ( $payload['version'] ?? 0 ) : 0;
			if ( ! is_array( $payload ) || ! in_array( $version, array( 1, 2, 3 ), true ) || ! is_string( $payload['body'] ?? null ) ) {
				return null;
			}
			if ( ! empty( $page_config['invalidate_on_post_update'] ) && $version < 2 ) {
				return null;
			}
			if ( ! empty( $page_config['invalidate_term_archives_on_post_update'] ) && $version < 3 ) {
				return null;
			}

			$status = (int) ( $payload['status'] ?? 0 );
			$type   = (string) ( $payload['type'] ?? '' );
			if ( ! self::page_type_enabled( $type, $status, $page_config ) ) {
				return null;
			}

			$headers = $payload['headers'] ?? null;
			if ( ! is_array( $headers ) ) {
				return null;
			}

			$safe_headers = array();
			foreach ( $headers as $header ) {
				if ( ! is_array( $header ) ) {
					return null;
				}

				$name  = (string) ( $header['name'] ?? '' );
				$value = (string) ( $header['value'] ?? '' );
				if ( ! self::is_replayable_header( $name, $value ) ) {
					return null;
				}
				$safe_headers[] = array( 'name' => $name, 'value' => $value );
			}

			$resource_type    = '';
			$resource_id      = 0;
			$resource_version = '';
			if ( 2 === $version ) {
				$resource_id      = (int) ( $payload['post_id'] ?? 0 );
				$resource_type    = $resource_id > 0 ? 'post' : '';
				$resource_version = (string) ( $payload['post_version'] ?? '' );
			} elseif ( 3 === $version ) {
				$resource_type    = (string) ( $payload['resource_type'] ?? '' );
				$resource_id      = (int) ( $payload['resource_id'] ?? 0 );
				$resource_version = (string) ( $payload['resource_version'] ?? '' );
			}
			if (
				! in_array( $resource_type, array( '', 'post', 'term' ), true ) ||
				$resource_id < 0 ||
				( '' === $resource_type && ( 0 !== $resource_id || '' !== $resource_version ) ) ||
				( '' !== $resource_type && ( $resource_id < 1 || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $resource_version ) ) )
			) {
				return null;
			}

			return array(
				'version'   => $version,
				'status'    => $status,
				'type'      => $type,
				'headers'   => $safe_headers,
				'body'      => $payload['body'],
				'stored_at' => max( 0, (int) ( $payload['stored_at'] ?? 0 ) ),
				'resource_type' => $resource_type,
				'resource_id' => $resource_id,
				'resource_version' => $resource_version,
			);
		}

		/**
		 * Check the late-bound post or term token before serving its payload. A
		 * Redis error returns null so the request falls through to WordPress.
		 *
		 * @param array<string, mixed> $payload
		 * @param array<string, mixed> $page_config
		 */
		private static function payload_content_version_matches( array $payload, Simple_Redis_Cache_Redis $redis, array $page_config ): ?bool {
			$resource_type = (string) ( $payload['resource_type'] ?? '' );
			$enabled       = ( 'post' === $resource_type && ! empty( $page_config['invalidate_on_post_update'] ) ) ||
				( 'term' === $resource_type && ! empty( $page_config['invalidate_term_archives_on_post_update'] ) );
			if ( ! $enabled ) {
				return true;
			}

			$resource_id = max( 0, (int) ( $payload['resource_id'] ?? 0 ) );
			if ( $resource_id < 1 ) {
				return true;
			}

			$current = $redis->page_content_version( $resource_type, $resource_id );
			if ( ! is_string( $current ) ) {
				return null;
			}

			return hash_equals( (string) ( $payload['resource_version'] ?? '' ), $current );
		}

		/** @param array<string, mixed> $page_config */
		private static function page_type_enabled( string $type, int $status, array $page_config ): bool {
			if ( '404' === $type ) {
				return 404 === $status && ! empty( $page_config['cache_404'] );
			}

			if ( 200 !== $status ) {
				return false;
			}

			$map = array(
				'home'     => 'cache_home',
				'singular' => 'cache_singular',
				'archive'  => 'cache_archives',
				'search'   => 'cache_search',
			);

			return isset( $map[ $type ] ) && ! empty( $page_config[ $map[ $type ] ] );
		}

		/** @param array<string, mixed> $payload */
		private static function serve( array $payload, bool $head_request, bool $debug, int $shared_max_age = 0 ): never {
			if ( ! headers_sent() ) {
				http_response_code( (int) $payload['status'] );
				$seen_headers = array();
				foreach ( $payload['headers'] as $header ) {
					$lower_name = strtolower( $header['name'] );
					header( $header['name'] . ': ' . $header['value'], ! isset( $seen_headers[ $lower_name ] ) );
					$seen_headers[ $lower_name ] = true;
				}
				self::shared_cache_header( $shared_max_age, isset( $seen_headers['cache-control'] ) );
				self::debug_header( $debug, 'HIT' );
			}

			if ( ! $head_request ) {
				echo $payload['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stored complete response body.
			}
			exit;
		}

		/**
		 * Tell shared caches (CDN, reverse proxy) that this HIT may be stored.
		 *
		 * WordPress emits no useful Cache-Control for anonymous responses, and this drop-in only
		 * replays the headers it captured — so a cached page arrives at the edge with nothing to
		 * act on, and a CDN's only safe assumption is "do not store". The page then travels to
		 * the origin on every single request, which defeats much of the point of having an edge.
		 *
		 * `s-maxage` targets shared caches only; `max-age=0` keeps browsers revalidating, so a
		 * visitor never sits on a stale page while an editor is publishing. Off by default:
		 * turning it on is a deployment decision, because the operator must also be willing to
		 * purge the CDN when content changes.
		 *
		 * A Cache-Control already present in the stored response is never overwritten — if the
		 * site deliberately marked something private, that intent wins.
		 */
		private static function shared_cache_header( int $shared_max_age, bool $already_present ): void {
			if ( $shared_max_age < 1 || $already_present ) {
				return;
			}

			header( 'Cache-Control: public, max-age=0, s-maxage=' . $shared_max_age );
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

		private static function debug_header( bool $enabled, string $value ): void {
			if ( $enabled && ! headers_sent() ) {
				header( 'X-Simple-Redis-Cache: ' . $value, true );
			}
		}

		private static function is_warm_request(): bool {
			$value = $_SERVER['HTTP_X_SIMPLE_REDIS_CACHE_WARM'] ?? '';
			return is_scalar( $value ) && '1' === trim( (string) $value );
		}

		private static function lock_token(): string {
			try {
				return bin2hex( random_bytes( 16 ) );
			} catch ( Throwable ) {
				return hash( 'sha256', uniqid( 'src-lock-', true ) );
			}
		}
	}
}

try {
	$config = Simple_Redis_Cache_Early_Config::load();
	Simple_Redis_Cache_Advanced_Cache_Loader::run( $config, $simple_redis_cache_dir );
} catch ( Throwable ) {
	// A cache failure must never prevent WordPress from serving the request.
}

unset( $config, $simple_redis_cache_dir );
