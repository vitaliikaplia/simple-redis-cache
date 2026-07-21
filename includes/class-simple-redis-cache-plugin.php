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

		new Simple_Redis_Cache_GitHub_Updater();
		Simple_Redis_Cache_Admin::init();
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

		self::synchronize( $value, true );
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
