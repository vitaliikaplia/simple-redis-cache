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
	public const CONFIG_VERSION = 4;

	/**
	 * Longest a cached 404 or search result may live, in Redis and at the edge.
	 * Any anonymous visitor can mint these by the thousand, and a 404 can turn into
	 * a real page the moment a post is published.
	 */
	public const SHORT_PAGE_TTL = 600;

	/**
	 * Signed page payload format: this marker, a 64-hex HMAC-SHA256 over the cache
	 * key and the serialized payload, then the serialized payload. The MAC keeps
	 * another writer on a shared Redis from planting HTML for this site, and the
	 * key binding stops a valid payload from being replayed under another URL.
	 */
	public const PAGE_PAYLOAD_PREFIX = "SRCP4\0";

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
				// Home, posts page, generic archives, search and 404 pages list content
				// rather than being one post, so they share a single listing token.
				'invalidate_listings_on_post_update' => true,
				// Menus, widgets, the Customizer, the theme and updates change every page.
				'purge_on_site_change'    => true,
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
			// Cloudflare purging runs only in the normal admin/plugin runtime, never in
			// the drop-ins. It lives in the shared schema so one sanitizer and one
			// generated file stay authoritative, the same way Redis credentials do.
			'cloudflare'     => array(
				'zone_id'              => '',
				'api_token'            => '',
				'purge_on_clear_all'   => false,
				'purge_on_clear_page'  => false,
				'purge_on_post_update' => false,
			),
			'site'           => array(
				'host'            => '',
				'port'            => 0,
				'fallback_prefix' => '',
				// Which installation generated this config; see install_identity().
				'identity'        => '',
				// HMAC key for page payloads, so a foreign writer cannot plant HTML.
				'secret'          => '',
			),
		);
	}

	/**
	 * Fingerprint of the installation the running code belongs to.
	 *
	 * A copied site (staging, a restored backup) inherits the generated config,
	 * the drop-ins and the saved settings, and with them the original's key
	 * prefix. Comparing this fingerprint lets the copy notice it is not the site
	 * that wrote the config before it shares the original's Redis namespace.
	 *
	 * It must be identical for every runtime of one site. ABSPATH is not: WP-CLI
	 * keeps --path as typed, symlinks and all, while the web resolves __DIR__. So
	 * the real path of wp-content — where this config lives, and usually shared
	 * across release directories — stands for the installation, with the database
	 * name and table prefix. DB_HOST is left out: CLI and web may reach the same
	 * server under different names.
	 */
	public static function install_identity(): string {
		return self::identity_for( (string) WP_CONTENT_DIR );
	}

	/** The identity of an installation whose wp-content lives at $content_dir. */
	public static function identity_for( string $content_dir ): string {
		global $table_prefix;

		$real = realpath( $content_dir );

		return substr(
			hash(
				'sha256',
				implode(
					"\n",
					array(
						rtrim( str_replace( '\\', '/', is_string( $real ) ? $real : $content_dir ), '/' ),
						defined( 'DB_NAME' ) ? (string) DB_NAME : '',
						isset( $table_prefix ) && is_string( $table_prefix ) ? $table_prefix : '',
					)
				)
			),
			0,
			32
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

		$defaults = self::defaults();
		$config   = self::merge( $defaults, $config );
		// A damaged file must not turn a section into a scalar every request then trips over.
		foreach ( $defaults as $section => $default ) {
			if ( is_array( $default ) && ! is_array( $config[ $section ] ?? null ) ) {
				$config[ $section ] = $default;
			}
		}
		unset( $config['site']['copy_detected'] );

		/*
		 * A config written by another installation must not run here: its prefix
		 * and connection belong to the original site. Both layers stay inert until
		 * an administrator request regenerates this site's own identity.
		 */
		$identity = (string) ( $config['site']['identity'] ?? '' );
		if ( '' !== $identity && ! hash_equals( $identity, self::install_identity() ) ) {
			$config['object']['enabled']     = false;
			$config['page']['enabled']       = false;
			$config['site']['copy_detected'] = true;
		}

		self::$config = $config;

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

	/** HMAC that binds a serialized page payload to the exact key it was stored under. */
	public static function page_payload_mac( string $cache_key, string $serialized, string $secret ): string {
		return hash_hmac( 'sha256', $cache_key . "\0" . $serialized, $secret );
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
