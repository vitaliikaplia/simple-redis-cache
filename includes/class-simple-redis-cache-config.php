<?php
/**
 * WordPress option and generated early-config management.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Config {
	public const OPTION = 'simple_redis_cache_settings';

	/** Return the stored option without defaults or object-cache mediation. */
	public static function stored(): array {
		/*
		 * This option contains the connection details needed to repair or purge the
		 * cache itself. Read it from the authoritative database instead of the
		 * object cache: after a Redis outage, an older cached copy could otherwise
		 * keep pointing diagnostics and purge actions at the failed server.
		 */
		global $wpdb;

		$stored = array();
		if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) && method_exists( $wpdb, 'get_var' ) ) {
			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					self::OPTION
				)
			);

			if ( null !== $raw ) {
				$stored = maybe_unserialize( $raw );
			}
		} else {
			$stored = get_option( self::OPTION, array() );
		}

		return is_array( $stored ) ? $stored : array();
	}

	/** @return array<string, mixed> */
	public static function get(): array {
		return self::merge( Simple_Redis_Cache_Early_Config::defaults(), self::stored() );
	}

	/**
	 * Return the plugin-owned configuration currently used by the early drop-ins.
	 * A null result means that no trustworthy runtime config is available.
	 * Pass true after a potentially blocking operation to discard the request-local
	 * static cache and observe a concurrent atomic file replacement.
	 *
	 * @param bool $fresh
	 * @return array<string, mixed>|null
	 */
	public static function runtime( bool $fresh = false ): ?array {
		$path = Simple_Redis_Cache_Early_Config::path();
		if ( ! self::owns_early_config( $path ) ) {
			return null;
		}
		if ( $fresh ) {
			Simple_Redis_Cache_Early_Config::reset();
		}

		return Simple_Redis_Cache_Early_Config::load();
	}

	/** Whether the persisted schema predates the code currently running. */
	public static function needs_migration(): bool {
		$stored = self::stored();
		return (int) ( $stored['config_version'] ?? 0 ) < Simple_Redis_Cache_Early_Config::CONFIG_VERSION;
	}

	/**
	 * Normalize an older stored schema through the current sanitizer.
	 *
	 * Future version-specific transforms belong here before sanitize(). A newer
	 * stored schema is never downgraded by an older copy of the plugin.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function migrate(): ?array {
		$stored  = self::stored();
		$version = (int) ( $stored['config_version'] ?? 0 );
		if ( $version >= Simple_Redis_Cache_Early_Config::CONFIG_VERSION ) {
			return null;
		}

		return self::sanitize( self::merge( Simple_Redis_Cache_Early_Config::defaults(), $stored ) );
	}

	/**
	 * Sanitize Settings API input.
	 *
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$old   = self::get();
		$input = is_array( $input ) ? $input : array();

		$redis_input = is_array( $input['redis'] ?? null ) ? $input['redis'] : array();
		$object_input = is_array( $input['object'] ?? null ) ? $input['object'] : array();
		$page_input = is_array( $input['page'] ?? null ) ? $input['page'] : array();

		$scheme = (string) ( $redis_input['scheme'] ?? 'tcp' );
		if ( ! in_array( $scheme, array( 'tcp', 'tls', 'unix' ), true ) ) {
			$scheme = 'tcp';
		}

		$password = array_key_exists( 'password', $redis_input )
			? (string) $redis_input['password']
			: (string) $old['redis']['password'];
		if ( '' === $password && ! empty( $redis_input['keep_password'] ) ) {
			$password = (string) $old['redis']['password'];
		}

		$config = Simple_Redis_Cache_Early_Config::defaults();
		$config['redis'] = array(
			'scheme'         => $scheme,
			'host'           => sanitize_text_field( (string) ( $redis_input['host'] ?? '127.0.0.1' ) ),
			'port'           => min( 65535, max( 1, absint( $redis_input['port'] ?? 6379 ) ) ),
			'path'           => sanitize_text_field( (string) ( $redis_input['path'] ?? '' ) ),
			'database'       => min( 255, max( 0, absint( $redis_input['database'] ?? 0 ) ) ),
			'username'       => sanitize_text_field( (string) ( $redis_input['username'] ?? '' ) ),
			'password'       => $password,
			'timeout'        => self::float_range( $redis_input['timeout'] ?? 1, 0.1, 30.0 ),
			'read_timeout'   => self::float_range( $redis_input['read_timeout'] ?? 1, 0.1, 30.0 ),
			'retry_interval' => min( 5000, max( 0, absint( $redis_input['retry_interval'] ?? 100 ) ) ),
			'persistent'     => ! empty( $redis_input['persistent'] ),
		);

		$config['object'] = array(
			'enabled'               => ! empty( $object_input['enabled'] ),
			'max_ttl'               => min( YEAR_IN_SECONDS, max( 60, absint( $object_input['max_ttl'] ?? DAY_IN_SECONDS ) ) ),
			'cache_wp_admin'        => ! empty( $object_input['cache_wp_admin'] ),
			'transient_db_fallback' => ! empty( $object_input['transient_db_fallback'] ),
			'non_persistent_groups' => self::lines( $object_input['non_persistent_groups'] ?? '' ),
		);

		$config['page'] = array(
			'enabled'                  => ! empty( $page_input['enabled'] ),
			'ttl'                      => min( MONTH_IN_SECONDS, max( 60, absint( $page_input['ttl'] ?? HOUR_IN_SECONDS ) ) ),
			'invalidate_on_post_update' => ! empty( $page_input['invalidate_on_post_update'] ),
			'invalidate_term_archives_on_post_update' => ! empty( $page_input['invalidate_term_archives_on_post_update'] ),
			'cache_logged_in'          => ! empty( $page_input['cache_logged_in'] ),
			'cache_home'               => ! empty( $page_input['cache_home'] ),
			'cache_singular'           => ! empty( $page_input['cache_singular'] ),
			'cache_archives'           => ! empty( $page_input['cache_archives'] ),
			'cache_search'             => ! empty( $page_input['cache_search'] ),
			'cache_404'                => ! empty( $page_input['cache_404'] ),
			'cache_query_strings'      => ! empty( $page_input['cache_query_strings'] ),
			'ignored_query_parameters' => self::lines( $page_input['ignored_query_parameters'] ?? '' ),
			'excluded_paths'           => self::lines( $page_input['excluded_paths'] ?? '' ),
			'excluded_cookies'         => self::lines( $page_input['excluded_cookies'] ?? '' ),
			'excluded_user_agents'     => self::lines( $page_input['excluded_user_agents'] ?? '' ),
			'vary_cookies'              => self::lines( $page_input['vary_cookies'] ?? '' ),
			'allowed_set_cookies'       => self::lines( $page_input['allowed_set_cookies'] ?? '' ),
			'debug_header'             => ! empty( $page_input['debug_header'] ),
			'lock_ttl'                 => 10,
		);

		$home_url    = home_url( '/' );
		$home_scheme = strtolower( (string) wp_parse_url( $home_url, PHP_URL_SCHEME ) );
		$home_port   = (int) wp_parse_url( $home_url, PHP_URL_PORT );
		if ( $home_port < 1 ) {
			$home_port = 'https' === $home_scheme ? 443 : 80;
		}

		$config['site'] = array(
			'host'            => strtolower( (string) wp_parse_url( $home_url, PHP_URL_HOST ) ),
			'port'            => $home_port,
			'fallback_prefix' => substr( hash( 'sha256', home_url( '/' ) . '|' . ABSPATH ), 0, 16 ),
		);

		return $config;
	}

	/** @param array<string, mixed>|null $config */
	public static function write_early_config( ?array $config = null ): bool|WP_Error {
		$config  = $config ?? self::get();
		$content = "<?php\n/** Generated by Simple Redis Cache. */\nif ( ! defined( 'ABSPATH' ) ) { return array(); }\n\nreturn " . var_export( $config, true ) . ";\n";
		$target  = Simple_Redis_Cache_Early_Config::path();
		$temp    = $target . '.' . wp_generate_password( 8, false, false ) . '.tmp';

		if ( file_exists( $target ) && ! self::owns_early_config( $target ) ) {
			return new WP_Error(
				'src_config_collision',
				__( 'The early cache configuration file is owned by another component and was not overwritten.', 'simple-redis-cache' )
			);
		}

		if ( false === @file_put_contents( $temp, $content, LOCK_EX ) ) {
			return new WP_Error( 'src_config_write_failed', __( 'Could not write the early Redis cache configuration.', 'simple-redis-cache' ) );
		}

		@chmod( $temp, 0640 );
		// Check ownership again immediately before the atomic replacement.
		if ( file_exists( $target ) && ! self::owns_early_config( $target ) ) {
			@unlink( $temp );
			return new WP_Error(
				'src_config_collision',
				__( 'The early cache configuration changed during installation and was not overwritten.', 'simple-redis-cache' )
			);
		}

		if ( ! @rename( $temp, $target ) ) {
			@unlink( $temp );
			return new WP_Error( 'src_config_rename_failed', __( 'Could not install the early Redis cache configuration.', 'simple-redis-cache' ) );
		}

		self::invalidate_opcache( $target );
		Simple_Redis_Cache_Early_Config::reset();
		return true;
	}

	/** Remove only the generated early config owned by this plugin. */
	public static function remove_early_config(): bool|WP_Error {
		$target = Simple_Redis_Cache_Early_Config::path();
		if ( ! file_exists( $target ) ) {
			return true;
		}

		$head = is_readable( $target ) ? file_get_contents( $target, false, null, 0, 512 ) : false;
		if ( false === $head || ! str_contains( $head, 'Generated by Simple Redis Cache' ) ) {
			return new WP_Error( 'src_config_not_owned', __( 'The early cache configuration file is not owned by Simple Redis Cache and was not removed.', 'simple-redis-cache' ) );
		}

		if ( ! @unlink( $target ) ) {
			return new WP_Error( 'src_config_remove_failed', __( 'Could not remove the generated early Redis cache configuration.', 'simple-redis-cache' ) );
		}

		self::invalidate_opcache( $target );
		Simple_Redis_Cache_Early_Config::reset();
		return true;
	}

	/** @return string[] */
	private static function lines( mixed $value ): array {
		if ( is_array( $value ) ) {
			$lines = $value;
		} else {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $value ) ?: array();
		}

		$lines = array_map(
			static fn( mixed $line ): string => trim( sanitize_text_field( (string) $line ) ),
			$lines
		);

		return array_values( array_unique( array_filter( $lines, 'strlen' ) ) );
	}

	private static function float_range( mixed $value, float $min, float $max ): float {
		$value = (float) $value;
		return min( $max, max( $min, $value ) );
	}

	private static function invalidate_opcache( string $path ): void {
		if ( function_exists( 'wp_opcache_invalidate' ) ) {
			wp_opcache_invalidate( $path, true );
		} elseif ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}
	}

	private static function owns_early_config( string $path ): bool {
		$head = is_readable( $path ) ? file_get_contents( $path, false, null, 0, 512 ) : false;
		return is_string( $head ) && str_contains( $head, 'Generated by Simple Redis Cache' );
	}

	/** @param array<string, mixed> $defaults @param array<string, mixed> $values @return array<string, mixed> */
	private static function merge( array $defaults, array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) && ! array_is_list( $defaults[ $key ] ) ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}
}
