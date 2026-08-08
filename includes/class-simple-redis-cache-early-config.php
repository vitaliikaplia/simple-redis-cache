<?php
/**
 * Dependency-free configuration reader used by WordPress drop-ins.
 *
 * This file must remain safe to load before WordPress plugins and the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Early_Config {
	public const CONFIG_VERSION = 3;

	/** @var array<string, mixed>|null */
	private static ?array $config = null;

	/**
	 * Return defaults shared by the admin plugin and both drop-ins.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'config_version' => self::CONFIG_VERSION,
			'redis'          => array(
				'scheme'         => 'tcp',
				'host'           => '127.0.0.1',
				'port'           => 6379,
				'path'           => '',
				'database'       => 0,
				'username'       => '',
				'password'       => '',
				'timeout'        => 1.0,
				'read_timeout'   => 1.0,
				'retry_interval' => 100,
				'persistent'     => false,
			),
			'object'         => array(
				'enabled'               => false,
				'max_ttl'               => 86400,
				'cache_wp_admin'        => true,
				'transient_db_fallback' => false,
				'non_persistent_groups' => array(),
			),
			'page'           => array(
				'enabled'                 => false,
				'ttl'                     => 3600,
				// Seconds a CDN or reverse proxy may keep a cache HIT (s-maxage). Zero, the
				// default, sends no Cache-Control at all and keeps today's behaviour: shared
				// caches will not store the page, so every visit reaches the origin.
				'shared_max_age'          => 0,
				'invalidate_on_post_update' => false,
				'invalidate_term_archives_on_post_update' => false,
				'cache_logged_in'         => false,
				'cache_home'              => true,
				'cache_singular'          => true,
				'cache_archives'          => true,
				'cache_search'            => false,
				'cache_404'               => false,
				'cache_query_strings'     => false,
				'ignored_query_parameters' => array(
					'utm_*',
					'gclid',
					'fbclid',
					'msclkid',
					'yclid',
					'ysclid',
					'srsltid',
					'_ga',
					'mc_cid',
					'mc_eid',
				),
				'excluded_paths'          => array(
					'/cart/*',
					'/checkout/*',
					'/my-account/*',
				),
				'excluded_cookies'        => array(
					'wordpress_logged_in_',
					'wordpress_sec_',
					'wp-postpass_',
					'comment_author_',
					'woocommerce_items_in_cart',
					'woocommerce_cart_hash',
					'wp_woocommerce_session_',
					'edd_items_in_cart',
					'PHPSESSID',
				),
				'excluded_user_agents'    => array(),
				'vary_cookies'             => array(
					'wp_loc_current_language',
					'wp_loc_current_locale',
					'_icl_current_language',
					'wp-wpml_current_language',
					'pll_language',
				),
				'allowed_set_cookies'      => array(
					'wp_loc_current_language',
					'wp_loc_current_locale',
					'_icl_current_language',
					'wp-wpml_current_language',
					'pll_language',
				),
				'debug_header'            => false,
				'lock_ttl'                => 10,
			),
			'site'           => array(
				'host'            => '',
				'port'            => 0,
				'fallback_prefix' => '',
			),
		);
	}

	/**
	 * Read the generated early configuration file.
	 *
	 * @return array<string, mixed>
	 */
	public static function load(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}

		$config = array();
		$path   = self::path();

		if ( is_readable( $path ) ) {
			try {
				$loaded = include $path;
			} catch ( Throwable ) {
				$loaded = array();
			}
			if ( is_array( $loaded ) ) {
				$config = $loaded;
			}
		}

		self::$config = self::merge( self::defaults(), $config );

		return self::$config;
	}

	public static function reset(): void {
		self::$config = null;
	}

	public static function path(): string {
		return WP_CONTENT_DIR . '/simple-redis-cache-config.php';
	}

	/**
	 * Prefix every key with WP_CACHE_KEY_SALT, followed by our own namespace.
	 */
	public static function prefix( ?array $config = null ): string {
		$config = $config ?? self::load();
		$salt   = defined( 'WP_CACHE_KEY_SALT' ) ? (string) WP_CACHE_KEY_SALT : '';

		if ( '' === $salt ) {
			$salt = (string) ( $config['site']['fallback_prefix'] ?? '' );
		}

		if ( '' === $salt ) {
			$salt = substr( hash( 'sha256', ABSPATH ), 0, 16 );
		}

		// Preserve the constant byte-for-byte. Salts such as "site" and "site:"
		// must remain distinct when several WordPress installs share one Redis DB.
		return $salt . ':src:';
	}

	public static function meta_key( string $name, ?array $config = null ): string {
		return self::prefix( $config ) . 'meta:' . $name;
	}

	/** Shared hash containing post and taxonomy-term HTML content versions. */
	public static function page_content_versions_key( ?array $config = null ): string {
		return self::meta_key( 'page-content-versions', $config );
	}

	/**
	 * Recursively merge associative arrays while replacing list arrays.
	 *
	 * @param array<string, mixed> $defaults
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function merge( array $defaults, array $values ): array {
		foreach ( $values as $key => $value ) {
			if (
				isset( $defaults[ $key ] ) &&
				is_array( $defaults[ $key ] ) &&
				is_array( $value ) &&
				! array_is_list( $defaults[ $key ] )
			) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}

		return $defaults;
	}
}
