<?php
/**
 * Tools for AI agents through the WordPress Abilities API (WordPress 6.9+).
 *
 * Simple MCP (2.7+) and the official WordPress MCP Adapter show abilities marked
 * with meta.mcp.public to their agents as MCP tools, calling them as the user
 * who owns the MCP key or connection. Nothing here depends on either: without
 * an MCP server the abilities simply sit in the core registry, and before
 * WordPress 6.9 none are registered. Core validates the input against the
 * schema and runs the permission callback before execute.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Abilities {
	public const CATEGORY = 'simple-redis-cache';

	/** Names this plugin registers, in the order an agent sees them. */
	public const NAMES = array(
		'simple-redis-cache/status',
		'simple-redis-cache/flush',
		'simple-redis-cache/flush-post',
	);

	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// The category must exist before the abilities, or core silently drops them.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Simple Redis Cache', 'simple-redis-cache' ),
				'description' => __( 'Full-page HTML cache and object cache in Redis.', 'simple-redis-cache' ),
			)
		);
	}

	public static function register_abilities(): void {
		foreach ( self::definitions() as $name => $definition ) {
			wp_register_ability( $name, $definition );
		}
	}

	/**
	 * Ability arguments by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array(
			'simple-redis-cache/status'     => array(
				'label'               => __( 'Cache status', 'simple-redis-cache' ),
				'description'         => __( 'Read the state of Simple Redis Cache on this site: whether Redis is reachable, whether the object cache and the full-page HTML cache are enabled and their drop-ins installed, the page cache lifetime, which content changes clear cached pages automatically, the Cloudflare purge settings, outage markers and the background removal of old cache generations. Redis memory and hit/miss numbers describe the whole Redis server, which other sites may share. Read-only: call it before clearing anything, or to find out why a change is not visible to visitors yet.', 'simple-redis-cache' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( self::class, 'status' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => array(
					'mcp'         => array( 'public' => true ),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
			'simple-redis-cache/flush'      => array(
				'label'               => __( 'Clear cache', 'simple-redis-cache' ),
				'description'         => __( 'Clear a whole cache layer of Simple Redis Cache on this site. scope "page" clears every cached HTML page (and the Cloudflare zone when the site enabled that for clearing the page cache); "object" clears the Redis object cache and also deletes every standard WordPress transient from the database, including transients of other plugins; "all" does both, like the "Clear all cache" button. Clearing is instant, but the next visits are slower until the cache fills again. Usually not needed after editing content through WordPress: the site clears changed pages itself when it is set up to (see simple-redis-cache-status), and simple-redis-cache-flush-post clears one post. Needs the manage_options capability.', 'simple-redis-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scope' => array(
							'type'        => 'string',
							'enum'        => array( 'page', 'object', 'all' ),
							'description' => __( 'What to clear: "page" (HTML pages), "object" (object cache and database transients) or "all".', 'simple-redis-cache' ),
						),
					),
					'required'             => array( 'scope' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'flush' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => array(
					'mcp'         => array( 'public' => true ),
					'annotations' => array(
						'readonly'    => false,
						// Transients of every plugin go with the object cache.
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			),
			'simple-redis-cache/flush-post' => array(
				'label'               => __( 'Clear a post’s cached page', 'simple-redis-cache' ),
				'description'         => __( 'Clear the cached HTML of one published post or page and of its translations, so visitors get the current version; with related set to true, also the archives of its categories, tags and other public terms and the listing pages (home page, blog page, archives, search). The same addresses are purged at Cloudflare when the site enabled Cloudflare purging on post updates. Works only when the site uses per-post invalidation (page_cache.invalidation in simple-redis-cache-status); otherwise it returns an error, and simple-redis-cache-flush with scope "page" clears all pages instead. Needs the right to edit that post.', 'simple-redis-cache' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'ID of the post, page or other public entry whose cached page to clear.', 'simple-redis-cache' ),
						),
						'related' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Also clear its term archives and the listing pages. Default false.', 'simple-redis-cache' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'flush_post' ),
				'permission_callback' => array( self::class, 'can_edit_post' ),
				'meta'                => array(
					'mcp'         => array( 'public' => true ),
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/** @param mixed $input */
	public static function can_manage( mixed $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/** @param mixed $input */
	public static function can_edit_post( mixed $input = null ): bool {
		$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function status( mixed $input = null ): array {
		$config    = Simple_Redis_Cache_Config::get();
		$redis     = new Simple_Redis_Cache_Redis( $config );
		$connected = $redis->ping();
		$client    = $connected ? $redis->client() : null;
		$page      = (array) ( $config['page'] ?? array() );
		$object    = (array) ( $config['object'] ?? array() );
		$cleanup   = null !== $client ? Simple_Redis_Cache_Cleanup::state( $client, $config ) : array();
		$foreign   = Simple_Redis_Cache_Config::is_foreign( $config );

		$status = array(
			'plugin_version' => SIMPLE_REDIS_CACHE_VERSION,
			'redis'          => array(
				'connected'  => $connected,
				'error'      => $connected ? null : ( $redis->display_error() ?: __( 'Redis is unavailable.', 'simple-redis-cache' ) ),
				'database'   => (int) ( $config['redis']['database'] ?? 0 ),
				'key_prefix' => Simple_Redis_Cache_Early_Config::prefix( $config ),
				'server'     => null !== $client ? self::server_info( $client ) : null,
			),
			'object_cache'   => array(
				'enabled'                  => ! empty( $object['enabled'] ),
				'drop_in'                  => Simple_Redis_Cache_Dropins::status( 'object' ),
				'max_ttl_seconds'          => (int) ( $object['max_ttl'] ?? 0 ),
				'transients_mirrored_in_db' => ! empty( $object['transient_db_fallback'] ),
			),
			'page_cache'     => array(
				'enabled'                   => ! empty( $page['enabled'] ),
				'drop_in'                   => Simple_Redis_Cache_Dropins::status( 'page' ),
				'wp_cache_constant'         => defined( 'WP_CACHE' ) && WP_CACHE,
				'ttl_seconds'               => (int) ( $page['ttl'] ?? 0 ),
				'cdn_max_age_seconds'       => (int) ( $page['shared_max_age'] ?? 0 ),
				'logged_in_users_cached'    => ! empty( $page['cache_logged_in'] ),
				'invalidation'              => array(
					'post_on_update'          => ! empty( $page['invalidate_on_post_update'] ),
					'term_archives_on_update' => ! empty( $page['invalidate_term_archives_on_post_update'] ),
					'listings_on_update'      => ! empty( $page['invalidate_listings_on_post_update'] ),
					'everything_on_site_change' => ! empty( $page['purge_on_site_change'] ),
					'whole_cache_on_content_change' => Simple_Redis_Cache_Page_Invalidator::content_purge_scope( $config ),
				),
			),
			'cloudflare'     => array(
				'configured'              => Simple_Redis_Cache_Cloudflare::is_configured( $config ),
				'purge_on_clear_all'      => ! empty( $config['cloudflare']['purge_on_clear_all'] ),
				'purge_on_clear_page'     => ! empty( $config['cloudflare']['purge_on_clear_page'] ),
				'purge_on_post_update'    => ! empty( $config['cloudflare']['purge_on_post_update'] ),
				'purge_on_content_change' => ! empty( $config['cloudflare']['purge_on_content_change'] ),
			),
			'outage'         => self::outage_state(),
			'cleanup'        => self::cleanup_summary( $cleanup ),
		);

		if ( $foreign ) {
			$status['copied_site'] = __( 'These cache settings were copied from another site; caching and purging stay off until an administrator opens any wp-admin page, which gives this site its own cache namespace.', 'simple-redis-cache' );
		}

		return $status;
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function flush( mixed $input = null ): array|WP_Error {
		$scope  = is_array( $input ) ? (string) ( $input['scope'] ?? '' ) : '';
		$result = Simple_Redis_Cache_Purger::purge( $scope );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = array(
			'scope'   => $scope,
			'cleared' => array(
				'page'   => isset( $result['page_generation'] ),
				'object' => isset( $result['object_generation'] ),
			),
		);
		if ( isset( $result['db_rows_deleted'] ) ) {
			$response['database_transient_rows_deleted'] = (int) $result['db_rows_deleted'];
		}
		$response['cloudflare'] = self::cloudflare_outcome( $result );

		return $response;
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function flush_post( mixed $input = null ): array|WP_Error {
		$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
		$related = is_array( $input ) && ! empty( $input['related'] );

		return Simple_Redis_Cache_Page_Invalidator::purge_post_now( $post_id, $related );
	}

	/**
	 * Server-wide figures. INFO can be denied by an ACL; whatever is missing is left out.
	 *
	 * @return array<string, mixed>
	 */
	private static function server_info( Redis $client ): array {
		$info = array();
		foreach ( array( 'server', 'memory', 'stats' ) as $section ) {
			try {
				$values = $client->info( $section );
				if ( is_array( $values ) ) {
					$info += $values;
				}
			} catch ( Throwable ) {
				// Optional metadata.
			}
		}

		$result = array();
		foreach (
			array(
				'redis_version'     => 'version',
				'maxmemory_policy'  => 'maxmemory_policy',
				'used_memory_human' => 'used_memory',
				'maxmemory_human'   => 'maxmemory',
			) as $source => $target
		) {
			if ( isset( $info[ $source ] ) && '' !== (string) $info[ $source ] ) {
				$result[ $target ] = (string) $info[ $source ];
			}
		}

		if ( isset( $info['keyspace_hits'], $info['keyspace_misses'] ) ) {
			$hits   = (int) $info['keyspace_hits'];
			$misses = (int) $info['keyspace_misses'];

			$result['keyspace_hits']    = $hits;
			$result['keyspace_misses']  = $misses;
			$result['hit_rate_percent'] = $hits + $misses > 0 ? round( 100 * $hits / ( $hits + $misses ), 1 ) : null;
		}

		return $result;
	}

	/** @return array<string, mixed> */
	private static function outage_state(): array {
		$dirty   = WP_CONTENT_DIR . '/simple-redis-cache-object-dirty';
		$breaker = Simple_Redis_Cache_Redis::breaker_path();
		clearstatcache();

		$tripped = is_file( $breaker ) ? time() - (int) filemtime( $breaker ) : null;

		return array(
			'object_changes_lost_during_outage' => is_file( $dirty ),
			'redis_skipped_after_failure'       => null !== $tripped && $tripped >= 0 && $tripped < 15,
		);
	}

	/**
	 * @param array<string, string> $state
	 * @return array<string, mixed>
	 */
	private static function cleanup_summary( array $state ): array {
		$time = static function ( string $key ) use ( $state ): ?string {
			$value = (int) ( $state[ $key ] ?? 0 );

			return $value > 0 ? gmdate( 'c', $value ) : null;
		};

		return array(
			'in_progress'           => '' !== (string) ( $state['cursor'] ?? '' ),
			'last_run'              => $time( 'last_run' ),
			'last_completed'        => $time( 'last_completed' ),
			'keys_removed_last_pass' => isset( $state['last_deleted'] ) ? (int) $state['last_deleted'] : null,
			'error'                 => '' !== (string) ( $state['error'] ?? '' ) ? (string) $state['error'] : null,
			'next_run'              => ( $next = wp_next_scheduled( Simple_Redis_Cache_Cleanup::HOOK ) ) ? gmdate( 'c', (int) $next ) : null,
		);
	}

	/**
	 * @param array<string, mixed> $result Purger result.
	 */
	private static function cloudflare_outcome( array $result ): string {
		if ( ! isset( $result['cloudflare'] ) ) {
			return 'not purged (not set up for this action)';
		}

		return true === $result['cloudflare']
			? 'zone purged'
			: 'purge failed: ' . (string) ( $result['cloudflare_error'] ?? '' );
	}
}
