<?php
/**
 * Plugin lifecycle and subsystem orchestration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Plugin {
	private static bool $initialized = false;
	private static ?string $last_config_hash = null;
	private static bool $upgrade_checked = false;
	/** @var array<string, bool> */
	private static array $settings_attempts = array();

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );

		if ( is_multisite() ) {
			add_action( 'admin_notices', array( self::class, 'multisite_notice' ) );
			add_action( 'network_admin_notices', array( self::class, 'multisite_notice' ) );
			return;
		}

		if ( Simple_Redis_Cache_Config::needs_migration() ) {
			self::maybe_upgrade();
		} elseif ( is_admin() ) {
			add_action(
				'admin_init',
				static function (): void {
					if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
						self::maybe_recover_runtime();
					}
				},
				0
			);
		}

		new Simple_Redis_Cache_GitHub_Updater();
		Simple_Redis_Cache_Admin::init();
		Simple_Redis_Cache_Page_Invalidator::init();
		add_action( 'update_option_' . Simple_Redis_Cache_Config::OPTION, array( self::class, 'settings_updated' ), 10, 3 );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'simple-redis-cache', false, dirname( SIMPLE_REDIS_CACHE_BASENAME ) . '/languages' );
	}

	/** Refuse activation on multisite and initialize safe defaults/drop-ins. */
	public static function activate( bool $network_wide = false ): void {
		self::load_textdomain();

		if ( is_multisite() ) {
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( SIMPLE_REDIS_CACHE_BASENAME, true, $network_wide );
			}

			wp_die(
				esc_html__( 'Simple Redis Cache supports single-site WordPress installations only and was not activated.', 'simple-redis-cache' ),
				esc_html__( 'Plugin activation refused', 'simple-redis-cache' ),
				array( 'back_link' => true )
			);
		}

		$config = Simple_Redis_Cache_Config::sanitize( Simple_Redis_Cache_Config::get() );
		if ( false === get_option( Simple_Redis_Cache_Config::OPTION, false ) ) {
			add_option( Simple_Redis_Cache_Config::OPTION, $config, '', false );
		} else {
			update_option( Simple_Redis_Cache_Config::OPTION, $config, false );
		}

		self::invalidate_lifecycle_namespaces( $config );
		self::synchronize( $config, true );
	}

	/** Disable the early config and remove only drop-ins owned by this plugin. */
	public static function deactivate(): void {
		$config = Simple_Redis_Cache_Config::get();
		self::invalidate_lifecycle_namespaces( $config );
		$config['object']['enabled'] = false;
		$config['page']['enabled']   = false;

		Simple_Redis_Cache_Config::write_early_config( $config );
		Simple_Redis_Cache_Dropins::remove_all();
		Simple_Redis_Cache_Config::remove_early_config();
	}

	/** @param mixed $old_value @param mixed $value */
	public static function settings_updated( mixed $old_value, mixed $value, string $option ): void {
		if ( Simple_Redis_Cache_Config::OPTION !== $option || ! is_array( $value ) ) {
			return;
		}

		$runtime    = Simple_Redis_Cache_Config::runtime();
		$old_config = $runtime;
		if ( null === $runtime ) {
			$old_config = Simple_Redis_Cache_Config::sanitize( is_array( $old_value ) ? $old_value : array() );
		}
		$new_config = Simple_Redis_Cache_Config::sanitize( $value );
		if ( null === $runtime && is_array( $old_value ) && is_array( $old_value['site'] ?? null ) ) {
			/* Keep the previous generated site identity long enough to invalidate it. */
			$old_site = (array) $old_value['site'];
			$old_config['site'] = array(
				'host'            => (string) ( $old_site['host'] ?? '' ),
				'port'            => (int) ( $old_site['port'] ?? 0 ),
				'fallback_prefix' => (string) ( $old_site['fallback_prefix'] ?? '' ),
			);
		}

		self::apply_saved_config( $old_config, $new_config );
	}

	/**
	 * Apply saved settings even when update_option() short-circuited because the
	 * database value was unchanged. This is also the retry path after a previous
	 * Redis invalidation failure left the early runtime safely disabled.
	 *
	 * @param array<string, mixed> $old_config
	 * @param array<string, mixed> $new_config
	 */
	public static function apply_saved_config( array $old_config, array $new_config ): bool {
		return self::apply_saved_config_attempt( $old_config, $new_config, 0 );
	}

	/**
	 * Optimistically revalidate the authoritative DB option before and after the
	 * filesystem switch. If concurrent saves keep moving the target, disable the
	 * runtime rather than leave an older snapshot active.
	 *
	 * @param array<string, mixed> $old_config
	 * @param array<string, mixed> $new_config
	 */
	private static function apply_saved_config_attempt( array $old_config, array $new_config, int $attempt ): bool {
		$authoritative = Simple_Redis_Cache_Config::get();
		if ( ! self::same_config( $authoritative, $new_config ) ) {
			$new_config = $authoritative;
		}
		if ( $attempt >= 3 ) {
			$runtime = Simple_Redis_Cache_Config::runtime( true ) ?? $old_config;
			self::defer_runtime_config( $runtime, 'concurrent' );
			return false;
		}

		$fingerprint = hash( 'sha256', serialize( array( $old_config, $new_config ) ) );
		if ( array_key_exists( $fingerprint, self::$settings_attempts ) ) {
			return self::$settings_attempts[ $fingerprint ];
		}

		$result = self::invalidate_changed_settings( $old_config, $new_config );
		if ( ! empty( $result['failed'] ) ) {
			self::defer_failed_invalidation( $old_config, $new_config );
			self::$settings_attempts[ $fingerprint ] = false;
			return false;
		}

		$authoritative = Simple_Redis_Cache_Config::get();
		if ( ! self::same_config( $authoritative, $new_config ) ) {
			return self::apply_saved_config_attempt( $old_config, $authoritative, $attempt + 1 );
		}

		$applied = self::synchronize( $new_config, true );
		if ( ! $applied ) {
			self::$settings_attempts[ $fingerprint ] = false;
			return false;
		}

		$authoritative = Simple_Redis_Cache_Config::get();
		if ( ! self::same_config( $authoritative, $new_config ) ) {
			return self::apply_saved_config_attempt( $new_config, $authoritative, $attempt + 1 );
		}

		if ( $result['object'] && ( ! empty( $old_config['object']['transient_db_fallback'] ) || ! empty( $new_config['object']['transient_db_fallback'] ) ) ) {
			Simple_Redis_Cache_Admin::queue_notice(
				__( 'The Redis object namespace was invalidated, but existing database transients were retained. Use the confirmed Clear object cache action if those database values must also be removed.', 'simple-redis-cache' ),
				'warning'
			);
		}

		self::$settings_attempts[ $fingerprint ] = true;
		return true;
	}

	public static function multisite_notice(): void {
		if ( current_user_can( 'manage_network_plugins' ) || current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Simple Redis Cache is disabled because this plugin supports single-site WordPress installations only.', 'simple-redis-cache' ) . '</p></div>';
		}
	}

	/** @param array<string, mixed> $config */
	public static function synchronize( array $config, bool $notify = true ): bool {
		$hash = hash( 'sha256', serialize( $config ) );
		if ( self::$last_config_hash === $hash ) {
			return true;
		}
		self::$last_config_hash = $hash;

		$ok      = true;
		$written = Simple_Redis_Cache_Config::write_early_config( $config );
		if ( is_wp_error( $written ) ) {
			if ( $notify ) {
				self::queue_errors( $written );
			}

			// Never run installed drop-ins with an older connection/privacy config.
			// Removing both layers is the only safe response to a config write failure.
			$removed = Simple_Redis_Cache_Dropins::remove_all();
			if ( is_wp_error( $removed ) && $notify ) {
				self::queue_errors( $removed );
			}
			return false;
		}

		$synced = Simple_Redis_Cache_Dropins::sync( $config );
		if ( is_wp_error( $synced ) ) {
			$ok = false;
			if ( $notify ) {
				self::queue_errors( $synced );
			}
		}

		return $ok;
	}

	private static function queue_errors( WP_Error $error ): void {
		foreach ( $error->get_error_messages() as $message ) {
			Simple_Redis_Cache_Admin::queue_notice( $message, 'error' );
		}
	}

	/** Retry a runtime config that was safely deferred after Redis failure. */
	private static function maybe_recover_runtime(): void {
		$runtime = Simple_Redis_Cache_Config::runtime();
		if ( null === $runtime ) {
			return;
		}

		$saved = Simple_Redis_Cache_Config::get();
		if ( hash_equals( hash( 'sha256', serialize( $runtime ) ), hash( 'sha256', serialize( $saved ) ) ) ) {
			return;
		}

		self::load_textdomain();
		self::apply_saved_config( $runtime, $saved );
	}

	/**
	 * Perform schema migration and filesystem self-healing once after an update.
	 * The direct-DB config_version check is cheap on normal requests and all
	 * writes, Redis invalidation, and drop-in synchronization happen only while
	 * an older persisted schema is actually present.
	 */
	private static function maybe_upgrade(): void {
		if ( self::$upgrade_checked ) {
			return;
		}
		self::$upgrade_checked = true;

		if ( ! Simple_Redis_Cache_Config::needs_migration() ) {
			return;
		}

		/* Migration errors can occur before the normal init-priority textdomain load. */
		self::load_textdomain();

		$old_config = Simple_Redis_Cache_Config::get();
		$new_config = Simple_Redis_Cache_Config::migrate();
		if ( null === $new_config ) {
			return;
		}

		update_option( Simple_Redis_Cache_Config::OPTION, $new_config, false );
		$stored = Simple_Redis_Cache_Config::stored();
		if ( (int) ( $stored['config_version'] ?? 0 ) !== Simple_Redis_Cache_Early_Config::CONFIG_VERSION ) {
			Simple_Redis_Cache_Admin::queue_notice(
				__( 'Simple Redis Cache could not save its configuration migration. The existing cache runtime was left unchanged.', 'simple-redis-cache' ),
				'error'
			);
			return;
		}

		/* A schema change may alter cache semantics even when visible fields match. */
		$result = self::invalidate_namespaces( $old_config, $new_config, true, true );
		if ( ! empty( $result['failed'] ) ) {
			self::defer_failed_invalidation( $old_config, $new_config );
			return;
		}

		self::synchronize( $new_config, true );
	}

	/**
	 * Invalidate only the cache layer whose explicit settings changed. Connection
	 * target changes invalidate each active layer on both old and new targets, so
	 * switching away and later back cannot revive data under the same key prefix.
	 * Timeout/retry/persistence tuning does not change the Redis data target.
	 *
	 * @param array<string, mixed> $old_config
	 * @param array<string, mixed> $new_config
	 * @return array{failed:string[],object:bool,page:bool}
	 */
	private static function invalidate_changed_settings( array $old_config, array $new_config ): array {
		$object_keys = array( 'enabled', 'max_ttl', 'cache_wp_admin', 'transient_db_fallback', 'non_persistent_groups' );
		$page_keys   = array(
			'enabled',
			'ttl',
			'invalidate_on_post_update',
			'invalidate_term_archives_on_post_update',
			'cache_logged_in',
			'cache_home',
			'cache_singular',
			'cache_archives',
			'cache_search',
			'cache_404',
			'cache_query_strings',
			'ignored_query_parameters',
			'excluded_paths',
			'excluded_cookies',
			'excluded_user_agents',
			'vary_cookies',
			'allowed_set_cookies',
		);

		$object_changed = self::subset( (array) ( $old_config['object'] ?? array() ), $object_keys )
			!== self::subset( (array) ( $new_config['object'] ?? array() ), $object_keys );
		$page_changed = self::subset( (array) ( $old_config['page'] ?? array() ), $page_keys )
			!== self::subset( (array) ( $new_config['page'] ?? array() ), $page_keys );
		$target_changed = self::storage_identity( $old_config ) !== self::storage_identity( $new_config );

		if ( $target_changed ) {
			$object_changed = $object_changed || ! empty( $old_config['object']['enabled'] ) || ! empty( $new_config['object']['enabled'] );
			$page_changed   = $page_changed || ! empty( $old_config['page']['enabled'] ) || ! empty( $new_config['page']['enabled'] );
		}

		return self::invalidate_namespaces( $old_config, $new_config, $object_changed, $page_changed );
	}

	/**
	 * @param array<string, mixed> $old_config
	 * @param array<string, mixed> $new_config
	 * @return array{failed:string[],object:bool,page:bool}
	 */
	private static function invalidate_namespaces( array $old_config, array $new_config, bool $object, bool $page ): array {
		$object = $object && ( ! empty( $old_config['object']['enabled'] ) || ! empty( $new_config['object']['enabled'] ) );
		$page   = $page && ( ! empty( $old_config['page']['enabled'] ) || ! empty( $new_config['page']['enabled'] ) );
		if ( ! $object && ! $page ) {
			return array( 'failed' => array(), 'object' => false, 'page' => false );
		}

		$configs = array( $new_config );
		if ( self::storage_identity( $old_config ) !== self::storage_identity( $new_config ) ) {
			array_unshift( $configs, $old_config );
		}

		$seen   = array();
		$failed = array();
		foreach ( $configs as $config ) {
			$identity = self::storage_identity( $config );
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;

			$redis = new Simple_Redis_Cache_Redis( $config );
			if ( $object && false === $redis->bump_generation( 'object' ) ) {
				$failed['object'] = true;
				self::queue_invalidation_error( 'object', $redis );
			}
			if ( $page && false === $redis->bump_generation( 'page' ) ) {
				$failed['page'] = true;
				self::queue_invalidation_error( 'page', $redis );
			}
		}

		return array(
			'failed' => array_keys( $failed ),
			'object' => $object,
			'page'   => $page,
		);
	}

	/**
	 * Re-read authoritative and early state after a failed Redis operation. A
	 * concurrent Save may have completed while PhpRedis was blocked, so the early
	 * file must be read fresh instead of restoring the caller's stale snapshot.
	 * Both layers are then disabled: this is intentionally conservative and keeps
	 * any concurrently changed but not originally targeted layer from running with
	 * policy that was never invalidated.
	 *
	 * @param array<string, mixed> $fallback_runtime
	 * @param array<string, mixed> $expected_config
	 */
	private static function defer_failed_invalidation( array $fallback_runtime, array $expected_config ): void {
		$authoritative = Simple_Redis_Cache_Config::get();
		$runtime       = Simple_Redis_Cache_Config::runtime( true ) ?? $fallback_runtime;
		$reason        = self::same_config( $authoritative, $expected_config ) ? 'redis' : 'concurrent';

		self::defer_runtime_config( $runtime, $reason );
	}

	/**
	 * Keep the last trustworthy connection/site identity for retry, but make both
	 * managed early layers inert. Removing both owned drop-ins is a second safety
	 * net if the generated config cannot be rewritten or remains opcode-cached.
	 *
	 * @param array<string, mixed> $runtime_config
	 */
	private static function defer_runtime_config( array $runtime_config, string $reason = 'redis' ): void {
		$safe_config = $runtime_config;
		$safe_config['object']['enabled'] = false;
		$safe_config['page']['enabled']   = false;

		$written = Simple_Redis_Cache_Config::write_early_config( $safe_config );
		if ( is_wp_error( $written ) ) {
			self::queue_errors( $written );
		}

		$removed = Simple_Redis_Cache_Dropins::remove_all();
		if ( is_wp_error( $removed ) ) {
			self::queue_errors( $removed );
		}

		self::$last_config_hash = null;
		Simple_Redis_Cache_Admin::queue_notice(
			'concurrent' === $reason
				? __( 'Cache settings changed concurrently while the runtime was being synchronized. Both managed cache layers were disabled and will be retried from the authoritative saved settings on the next administrator request.', 'simple-redis-cache' )
				: __( 'The new cache settings are pending because Redis invalidation failed. Both managed cache layers were disabled to prevent stale entries and will be retried on the next administrator request.', 'simple-redis-cache' ),
			'error'
		);
	}

	private static function queue_invalidation_error( string $namespace, Simple_Redis_Cache_Redis $redis ): void {
		$message = 'object' === $namespace
			? __( 'Could not clear object cache: %s', 'simple-redis-cache' )
			: __( 'Could not clear page cache: %s', 'simple-redis-cache' );
		Simple_Redis_Cache_Admin::queue_notice(
			sprintf( $message, $redis->display_error() ?: __( 'Redis is unavailable.', 'simple-redis-cache' ) ),
			'error'
		);
	}

	/** @param array<string, mixed> $values @param string[] $keys @return array<string, mixed> */
	private static function subset( array $values, array $keys ): array {
		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = $values[ $key ] ?? null;
		}
		return $result;
	}

	/** @param array<string, mixed> $left @param array<string, mixed> $right */
	private static function same_config( array $left, array $right ): bool {
		return hash_equals( hash( 'sha256', serialize( $left ) ), hash( 'sha256', serialize( $right ) ) );
	}

	/** @param array<string, mixed> $config */
	private static function storage_identity( array $config ): string {
		$redis  = (array) ( $config['redis'] ?? array() );
		$site   = (array) ( $config['site'] ?? array() );
		$scheme = (string) ( $redis['scheme'] ?? '' );
		$data   = array(
			'scheme'   => $scheme,
			'endpoint' => 'unix' === $scheme
				? (string) ( $redis['path'] ?? '' )
				: array(
					'host' => (string) ( $redis['host'] ?? '' ),
					'port' => (int) ( $redis['port'] ?? 0 ),
				),
			'database' => (int) ( $redis['database'] ?? 0 ),
			'username' => (string) ( $redis['username'] ?? '' ),
			'password' => (string) ( $redis['password'] ?? '' ),
			'prefix'   => Simple_Redis_Cache_Early_Config::prefix( $config ),
			'site'     => array(
				'host' => (string) ( $site['host'] ?? '' ),
				'port' => (int) ( $site['port'] ?? 0 ),
			),
		);

		return hash( 'sha256', serialize( $data ) );
	}

	/**
	 * Prevent objects or HTML captured before a lifecycle transition from being
	 * revived when the managed drop-ins are installed again. In particular, the
	 * active_plugins option changes after WordPress runs activation/deactivation
	 * hooks, when one side of the transition may not have an external cache loaded.
	 * Redis failure remains non-fatal; normal requests still fail open.
	 *
	 * @param array<string, mixed> $config
	 */
	private static function invalidate_lifecycle_namespaces( array $config ): void {
		$redis = new Simple_Redis_Cache_Redis( $config );
		if ( ! empty( $config['object']['enabled'] ) ) {
			$redis->bump_generation( 'object' );
		}
		if ( ! empty( $config['page']['enabled'] ) ) {
			$redis->bump_generation( 'page' );
		}
	}
}
