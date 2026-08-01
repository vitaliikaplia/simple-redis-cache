<?php
/**
 * Native WordPress administration UI and privileged actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Admin {
	private const PAGE         = 'simple-redis-cache';
	private const NOTICE_KEY   = '_simple_redis_cache_notices';
	private const PURGE_ACTION = 'simple_redis_cache_purge';
	private const PURGE_CONFIRM_ACTION = 'confirm_purge';
	private const TEST_ACTION  = 'simple_redis_cache_test_redis';
	private const WARM_PREPARE_ACTION = 'simple_redis_cache_prepare_warm';
	private const WARM_URL_ACTION     = 'simple_redis_cache_warm_url';
	private const WARM_NONCE          = 'simple_redis_cache_warm';
	private const DEFAULT_TAB  = 'redis';
	private const SETTINGS_TAB = '_settings_tab';
	private const FORM_TABS    = array( 'redis', 'object', 'page' );

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'admin_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_notices', array( self::class, 'admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( self::class, 'handle_test' ) );
		add_action( 'admin_post_' . self::PURGE_ACTION, array( self::class, 'handle_purge' ) );
		add_action( 'wp_ajax_' . self::WARM_PREPARE_ACTION, array( self::class, 'ajax_prepare_warm' ) );
		add_action( 'wp_ajax_' . self::WARM_URL_ACTION, array( self::class, 'ajax_warm_url' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 100 );
		add_filter( 'plugin_action_links_' . SIMPLE_REDIS_CACHE_BASENAME, array( self::class, 'plugin_action_links' ) );
	}

	public static function admin_menu(): void {
		add_options_page(
			__( 'Simple Redis Cache', 'simple-redis-cache' ),
			__( 'Redis Cache', 'simple-redis-cache' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'settings_page' )
		);
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix || 'warm' !== self::current_tab() ) {
			return;
		}

		wp_enqueue_style(
			'simple-redis-cache-warm',
			plugins_url( 'assets/css/admin-warm-cache.css', SIMPLE_REDIS_CACHE_FILE ),
			array(),
			SIMPLE_REDIS_CACHE_VERSION
		);
		wp_enqueue_script(
			'simple-redis-cache-warm',
			plugins_url( 'assets/js/admin-warm-cache.js', SIMPLE_REDIS_CACHE_FILE ),
			array(),
			SIMPLE_REDIS_CACHE_VERSION,
			true
		);
		/* translators: %s: URL currently being warmed. */
		$warming_string = __( 'Warming: %s', 'simple-redis-cache' );
		/* translators: 1: processed URL count, 2: total URL count. */
		$progress_string = __( 'Processed %1$d of %2$d URLs.', 'simple-redis-cache' );
		wp_localize_script(
			'simple-redis-cache-warm',
			'SimpleRedisCacheWarm',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'prepareAction' => self::WARM_PREPARE_ACTION,
				'proxyAction'   => self::WARM_URL_ACTION,
				'nonce'         => wp_create_nonce( self::WARM_NONCE ),
				'retryDelay'    => 250,
				'requestTimeout' => 30000,
				'strings'       => array(
					'preparing'       => __( 'Discovering public URLs…', 'simple-redis-cache' ),
					'noSources'       => __( 'Select at least one source to warm.', 'simple-redis-cache' ),
					'noUrls'          => __( 'No cacheable public URLs were found for the selected sources.', 'simple-redis-cache' ),
					'warming'         => $warming_string,
					'progress'        => $progress_string,
					'success'         => __( 'Cache warming completed successfully.', 'simple-redis-cache' ),
					'partial'         => __( 'Cache warming completed with some URLs not cached.', 'simple-redis-cache' ),
					'stopped'         => __( 'Cache warming was stopped.', 'simple-redis-cache' ),
					'prepareFailed'   => __( 'Could not prepare cache warming.', 'simple-redis-cache' ),
					'requestFailed'   => __( 'The frontend request failed.', 'simple-redis-cache' ),
					'requestTimedOut' => __( 'The frontend request timed out.', 'simple-redis-cache' ),
					'missingStatus'   => __( 'The frontend response did not contain a page-cache status.', 'simple-redis-cache' ),
					'bypassMessage'   => __( 'The page cache bypassed this URL.', 'simple-redis-cache' ),
					'notStored'       => __( 'Redis did not return a cache HIT after the page was rendered.', 'simple-redis-cache' ),
					'alreadyCached'   => __( 'Already cached', 'simple-redis-cache' ),
					'warmed'          => __( 'Warmed now', 'simple-redis-cache' ),
					'bypassed'        => __( 'Bypassed', 'simple-redis-cache' ),
					'failed'          => __( 'Failed', 'simple-redis-cache' ),
					'unknownError'    => __( 'Unknown error.', 'simple-redis-cache' ),
					/* translators: %d: number of additional issues hidden from the list. */
					'moreIssues'      => __( '%d more issues not shown.', 'simple-redis-cache' ),
				),
			)
		);
	}

	public static function register_settings(): void {
		register_setting(
			'simple_redis_cache',
			Simple_Redis_Cache_Config::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => Simple_Redis_Cache_Early_Config::defaults(),
			)
		);

		add_settings_section(
			'src_redis',
			__( 'Redis connection', 'simple-redis-cache' ),
			static function (): void {
				echo '<p>' . esc_html__( 'PhpRedis and one standalone Redis server are supported. Credentials are stored in the WordPress database and in the generated early configuration file required by the drop-ins.', 'simple-redis-cache' ) . '</p>';
			},
			self::section_page( 'redis' )
		);

		self::add_field( 'redis', 'scheme', __( 'Connection type', 'simple-redis-cache' ), 'select', array( 'tcp' => 'TCP', 'tls' => 'TLS', 'unix' => __( 'Unix socket', 'simple-redis-cache' ) ) );
		self::add_field( 'redis', 'host', __( 'Host', 'simple-redis-cache' ), 'text', array(), __( 'For example: 127.0.0.1. Ignored for Unix sockets.', 'simple-redis-cache' ) );
		self::add_field( 'redis', 'port', __( 'Port', 'simple-redis-cache' ), 'number', array( 'min' => 1, 'max' => 65535, 'step' => 1 ) );
		self::add_field( 'redis', 'path', __( 'Unix socket path', 'simple-redis-cache' ), 'text', array(), __( 'Enter a raw absolute filesystem path, for example: /home/account/.system/redis.sock. Do not add unix://; Host and Port are ignored.', 'simple-redis-cache' ) );
		self::add_field( 'redis', 'database', __( 'Database', 'simple-redis-cache' ), 'number', array( 'min' => 0, 'max' => 255, 'step' => 1 ) );
		self::add_field( 'redis', 'username', __( 'ACL username', 'simple-redis-cache' ), 'text' );
		self::add_field( 'redis', 'password', __( 'Password', 'simple-redis-cache' ), 'password', array(), __( 'Leave blank to retain the stored password when the option below is checked.', 'simple-redis-cache' ) );
		self::add_field( 'redis', 'keep_password', __( 'Stored password', 'simple-redis-cache' ), 'keep_password' );
		self::add_field( 'redis', 'timeout', __( 'Connect timeout', 'simple-redis-cache' ), 'number', array( 'min' => 0.1, 'max' => 30, 'step' => 0.1 ), __( 'Seconds.', 'simple-redis-cache' ) );
		self::add_field( 'redis', 'read_timeout', __( 'Read timeout', 'simple-redis-cache' ), 'number', array( 'min' => 0.1, 'max' => 30, 'step' => 0.1 ), __( 'Seconds.', 'simple-redis-cache' ) );
		self::add_field( 'redis', 'retry_interval', __( 'Retry interval', 'simple-redis-cache' ), 'number', array( 'min' => 0, 'max' => 5000, 'step' => 1 ), __( 'Milliseconds.', 'simple-redis-cache' ) );
		self::add_field( 'redis', 'persistent', __( 'Persistent connection', 'simple-redis-cache' ), 'checkbox' );

		add_settings_section(
			'src_object',
			__( 'Object cache', 'simple-redis-cache' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Stores WordPress Object Cache API values in Redis through object-cache.php.', 'simple-redis-cache' ) . '</p>';
			},
			self::section_page( 'object' )
		);

		self::add_field( 'object', 'enabled', __( 'Enable object cache', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'object', 'max_ttl', __( 'Maximum TTL', 'simple-redis-cache' ), 'number', array( 'min' => 60, 'max' => YEAR_IN_SECONDS, 'step' => 1 ), __( 'Seconds. A caller-provided shorter TTL remains unchanged; zero or a longer TTL is capped here.', 'simple-redis-cache' ) );
		self::add_field( 'object', 'cache_wp_admin', __( 'Read object cache in wp-admin', 'simple-redis-cache' ), 'checkbox', array(), __( 'When disabled, normal wp-admin reads bypass Redis. Writes and invalidations still reach Redis; transients remain readable from Redis when no database mirror is enabled.', 'simple-redis-cache' ) );
		self::add_field( 'object', 'transient_db_fallback', __( 'Database transient mirror', 'simple-redis-cache' ), 'checkbox', array(), __( 'Write transient and site-transient values to both Redis and the database, and read the database on a Redis miss.', 'simple-redis-cache' ) );
		self::add_field( 'object', 'non_persistent_groups', __( 'Non-persistent groups', 'simple-redis-cache' ), 'textarea', array(), __( 'One exact WordPress cache group per line.', 'simple-redis-cache' ) );

		add_settings_section(
			'src_page',
			__( 'HTML page cache', 'simple-redis-cache' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Stores eligible public HTML responses in Redis through advanced-cache.php. No cached pages are written to disk.', 'simple-redis-cache' ) . '</p>';
			},
			self::section_page( 'page' )
		);

		self::add_field( 'page', 'enabled', __( 'Enable page cache', 'simple-redis-cache' ), 'checkbox', array(), __( "The plugin attempts to add define( 'WP_CACHE', true ); to wp-config.php when needed. If automatic enabling fails, set WP_CACHE to true manually and verify the Status tab.", 'simple-redis-cache' ) );
		self::add_field( 'page', 'ttl', __( 'Page TTL', 'simple-redis-cache' ), 'number', array( 'min' => 60, 'max' => MONTH_IN_SECONDS, 'step' => 1 ), __( 'Seconds.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'invalidate_on_post_update', __( "Clear a page's cache when it is updated", 'simple-redis-cache' ), 'checkbox', array(), __( 'Invalidates only the updated post, page or custom post type and all translations detected through WPML or Polylang. Unrelated home, archive and other derived pages are not cleared.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'invalidate_term_archives_on_post_update', __( 'Clear related taxonomy archives when a post is updated', 'simple-redis-cache' ), 'checkbox', array(), __( 'Invalidates archives of current, previous and new assigned terms in public standard and custom taxonomies, including hierarchical parents and terms assigned to WPML or Polylang translations. Unrelated taxonomy archives remain cached.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'cache_logged_in', __( 'Cache logged-in users', 'simple-redis-cache' ), 'checkbox', array(), __( 'Disabled is safest. Logged-in variants are isolated by session when enabled.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'cache_home', __( 'Cache home page', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_singular', __( 'Cache posts, pages and CPTs', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_archives', __( 'Cache archives', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_search', __( 'Cache search results', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_404', __( 'Cache 404 responses', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_query_strings', __( 'Cache query strings', 'simple-redis-cache' ), 'checkbox', array(), __( 'Non-ignored query parameters normally bypass page cache unless this is enabled; supported search parameters are a limited exception. Built-in unsafe parameters always bypass.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'ignored_query_parameters', __( 'Ignored query parameters', 'simple-redis-cache' ), 'textarea', array(), __( 'One exact name or wildcard pattern per line; * and ? are supported. Matching non-sensitive parameters are removed from the cache key; built-in unsafe names cannot be ignored.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'excluded_paths', __( 'Excluded paths', 'simple-redis-cache' ), 'textarea', array(), __( 'One path pattern per line; * and ? are supported.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'excluded_cookies', __( 'Excluded request cookies', 'simple-redis-cache' ), 'textarea', array(), __( 'Matching cookies normally bypass page cache. Vary-cookie entries and the default WordPress auth prefixes when logged-in caching is enabled use isolated variants instead. Use one exact name, wildcard pattern, or conventional prefix ending in _ per line.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'excluded_user_agents', __( 'Excluded user agents', 'simple-redis-cache' ), 'textarea', array(), __( 'One case-insensitive substring or wildcard pattern per line.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'vary_cookies', __( 'Cookies that vary the cache key', 'simple-redis-cache' ), 'textarea', array(), __( 'One cookie name per line. Useful for language cookies that safely select a page variant.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'allowed_set_cookies', __( 'Allowed response Set-Cookie names', 'simple-redis-cache' ), 'textarea', array(), __( 'Responses setting only these cookie names may be stored; Set-Cookie headers are never replayed from cache.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'debug_header', __( 'Debug response header', 'simple-redis-cache' ), 'checkbox', array(), __( 'Send X-Simple-Redis-Cache with HIT, MISS or BYPASS status.', 'simple-redis-cache' ) );
	}

	/**
	 * Sanitize settings and schedule an apply/retry even when update_option()
	 * detects that the database value itself did not change. This lets an
	 * administrator recover a deferred Redis invalidation or repair filesystem
	 * state, then press Save again without toggling an unrelated setting.
	 *
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( mixed $input ): array {
		if ( is_array( $input ) && array_key_exists( self::SETTINGS_TAB, $input ) ) {
			$tab = is_string( $input[ self::SETTINGS_TAB ] )
				? sanitize_key( $input[ self::SETTINGS_TAB ] )
				: '';
			unset( $input[ self::SETTINGS_TAB ] );

			if ( ! in_array( $tab, self::FORM_TABS, true ) || ! isset( $input[ $tab ] ) || ! is_array( $input[ $tab ] ) ) {
				add_settings_error(
					Simple_Redis_Cache_Config::OPTION,
					'src_invalid_settings_tab',
					__( 'The settings were not saved because the submitted section was invalid. Please reload the page and try again.', 'simple-redis-cache' ),
					'error'
				);

				return Simple_Redis_Cache_Config::get();
			}

			$merged         = Simple_Redis_Cache_Config::get();
			$merged[ $tab ] = $input[ $tab ];
			$input          = $merged;
		}

		$runtime = Simple_Redis_Cache_Config::runtime() ?? Simple_Redis_Cache_Config::get();
		$config  = Simple_Redis_Cache_Config::sanitize( $input );

		add_action(
			'shutdown',
			static function () use ( $runtime, $config ): void {
				Simple_Redis_Cache_Plugin::apply_saved_config( $runtime, $config );
			},
			PHP_INT_MAX
		);

		return $config;
	}

	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$confirm_scope = self::requested_purge_confirmation();
		$tab           = null !== $confirm_scope ? 'status' : self::current_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Simple Redis Cache', 'simple-redis-cache' ); ?></h1>
			<p><?php esc_html_e( 'Redis-only object and full-page cache for a single WordPress site. Cache entries use a non-empty WP_CACHE_KEY_SALT when defined, or a generated per-site fallback prefix.', 'simple-redis-cache' ); ?></p>

			<?php self::render_tabs( $tab ); ?>

			<?php if ( null !== $confirm_scope ) : ?>
				<?php self::render_purge_confirmation( $confirm_scope ); ?>
			<?php elseif ( 'status' === $tab ) : ?>
				<?php self::render_diagnostics(); ?>
			<?php elseif ( 'warm' === $tab ) : ?>
				<?php self::render_warm_page(); ?>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php
					settings_fields( 'simple_redis_cache' );
					?>
					<input type="hidden" name="<?php echo esc_attr( Simple_Redis_Cache_Config::OPTION . '[' . self::SETTINGS_TAB . ']' ); ?>" value="<?php echo esc_attr( $tab ); ?>">
					<?php
					do_settings_sections( self::section_page( $tab ) );
					submit_button();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string, mixed> $args */
	public static function render_field( array $args ): void {
		$config  = Simple_Redis_Cache_Config::get();
		$group   = (string) $args['group'];
		$key     = (string) $args['key'];
		$type    = (string) $args['type'];
		$options = (array) ( $args['options'] ?? array() );
		$value   = $config[ $group ][ $key ] ?? '';
		$name    = Simple_Redis_Cache_Config::OPTION . '[' . $group . '][' . $key . ']';
		$id      = 'src-' . $group . '-' . str_replace( '_', '-', $key );

		if ( 'checkbox' === $type ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
			echo '<label><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $value ), true, false ) . '> ' . esc_html__( 'Enabled', 'simple-redis-cache' ) . '</label>';
		} elseif ( 'keep_password' === $type ) {
			$name = Simple_Redis_Cache_Config::OPTION . '[redis][keep_password]';
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
			echo '<label><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1" checked> ' . esc_html__( 'Keep the currently stored password when the password field is blank', 'simple-redis-cache' ) . '</label>';
			if ( '' !== (string) ( $config['redis']['password'] ?? '' ) ) {
				echo '<p class="description">' . esc_html__( 'A password is currently stored.', 'simple-redis-cache' ) . '</p>';
			}
		} elseif ( 'select' === $type ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $options as $option_value => $label ) {
				echo '<option value="' . esc_attr( (string) $option_value ) . '" ' . selected( (string) $value, (string) $option_value, false ) . '>' . esc_html( (string) $label ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'textarea' === $type ) {
			$text = is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : (string) $value;
			echo '<textarea class="large-text code" rows="5" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $text ) . '</textarea>';
		} else {
			$input_value = 'password' === $type ? '' : (string) $value;
			$attributes  = '';
			foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
				if ( isset( $options[ $attribute ] ) ) {
					$attributes .= ' ' . $attribute . '="' . esc_attr( (string) $options[ $attribute ] ) . '"';
				}
			}
			echo '<input class="regular-text" id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $input_value ) . '"' . $attributes . ( 'password' === $type ? ' autocomplete="new-password"' : '' ) . '>';
		}

		if ( ! empty( $args['description'] ) && 'keep_password' !== $type ) {
			echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
		}
	}

	public static function handle_test(): void {
		self::authorize( self::TEST_ACTION );

		$config = Simple_Redis_Cache_Config::get();
		$redis  = new Simple_Redis_Cache_Redis( $config );
		if ( $redis->ping() ) {
			$round_trip = self::redis_round_trip( $redis, $config );
			if ( is_wp_error( $round_trip ) ) {
				self::queue_notice(
					sprintf(
						/* translators: %s: Redis read/write/delete test error. */
						__( 'Redis connected, but the isolated write/read/delete test failed: %s', 'simple-redis-cache' ),
						implode( ' ', $round_trip->get_error_messages() )
					),
					'error'
				);
			} else {
				$info    = self::redis_info( $redis );
				$version = (string) ( $info['version'] ?? '' );
				self::queue_notice(
					$version
						? sprintf( /* translators: %s: Redis version. */ __( 'Redis connection and isolated write/read/delete test succeeded (Redis %s).', 'simple-redis-cache' ), $version )
						: __( 'Redis connection and isolated write/read/delete test succeeded.', 'simple-redis-cache' ),
					'success'
				);
				$policy = (string) ( $info['policy'] ?? '' );
				if ( str_starts_with( $policy, 'allkeys-' ) ) {
					self::queue_notice(
						sprintf(
							/* translators: %s: Redis maxmemory policy. */
							__( 'Redis uses %s, which may evict persistent cache-generation metadata. Prefer noeviction or a volatile-* policy.', 'simple-redis-cache' ),
							$policy
						),
						'warning'
					);
				}
			}
		} else {
			self::queue_notice(
				sprintf(
					/* translators: %s: Redis error. */
					__( 'Redis connection failed: %s', 'simple-redis-cache' ),
					$redis->display_error() ?: __( 'Unknown error.', 'simple-redis-cache' )
				),
				'error'
			);
		}

		self::redirect_back();
	}

	public static function handle_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clear this cache.', 'simple-redis-cache' ), '', array( 'response' => 403 ) );
		}

		$scope_value = $_POST['scope'] ?? $_GET['scope'] ?? 'all';
		$scope       = is_string( $scope_value ) ? sanitize_key( wp_unslash( $scope_value ) ) : 'all';
		if ( ! in_array( $scope, array( 'all', 'object', 'page' ), true ) ) {
			wp_die( esc_html__( 'Invalid cache purge scope.', 'simple-redis-cache' ), '', array( 'response' => 400 ) );
		}
		$confirmed = isset( $_POST['confirmed'] ) && is_string( $_POST['confirmed'] )
			? sanitize_key( wp_unslash( $_POST['confirmed'] ) )
			: '';
		if ( in_array( $scope, array( 'all', 'object' ), true ) && '1' !== $confirmed ) {
			wp_die( esc_html__( 'Clearing object cache requires explicit confirmation.', 'simple-redis-cache' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::PURGE_ACTION . '_' . $scope );
		$result = Simple_Redis_Cache_Purger::purge( $scope );

		if ( is_wp_error( $result ) ) {
			self::queue_notice( implode( ' ', $result->get_error_messages() ), 'error' );
		} else {
			$labels = array(
				'all'    => __( 'All Redis caches were cleared.', 'simple-redis-cache' ),
				'object' => __( 'Object cache was cleared.', 'simple-redis-cache' ),
				'page'   => __( 'HTML page cache was cleared.', 'simple-redis-cache' ),
			);
			self::queue_notice( $labels[ $scope ], 'success' );
		}

		self::redirect_back();
	}

	public static function ajax_prepare_warm(): void {
		self::authorize_ajax_warm();

		wp_raise_memory_limit( 'admin' );
		$system     = self::request_name_list( $_POST['system'] ?? array() );
		$post_types = self::request_name_list( $_POST['post_types'] ?? array() );
		$taxonomies = self::request_name_list( $_POST['taxonomies'] ?? array() );
		$result     = Simple_Redis_Cache_Warmer::discover( $system, $post_types, $taxonomies, Simple_Redis_Cache_Config::get() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => implode( ' ', $result->get_error_messages() ) ), 400 );
		}

		if ( $result['selected_sources'] < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one source to warm.', 'simple-redis-cache' ) ), 400 );
		}

		$user_id = get_current_user_id();
		$items   = array();
		foreach ( $result['urls'] as $url ) {
			$items[] = array(
				'url'       => $url,
				'signature' => Simple_Redis_Cache_Warmer::sign_url( $url, $user_id ),
			);
		}

		wp_send_json_success(
			array(
				'items'    => $items,
				'warnings' => $result['warnings'],
			)
		);
	}

	public static function ajax_warm_url(): void {
		self::authorize_ajax_warm();

		$url       = isset( $_POST['url'] ) && is_string( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$signature = isset( $_POST['signature'] ) && is_string( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
		if ( '' === $url || ! Simple_Redis_Cache_Warmer::verify_url_signature( $url, $signature, get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'The cache-warming URL could not be verified.', 'simple-redis-cache' ) ), 400 );
		}

		wp_send_json_success( Simple_Redis_Cache_Warmer::warm_remote_url( $url ) );
	}

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'simple-redis-cache',
				'title' => __( 'Caching', 'simple-redis-cache' ),
				'href'  => self::settings_url(),
				'meta'  => array( 'title' => __( 'Caching', 'simple-redis-cache' ) ),
			)
		);

		$bar->add_node( array( 'parent' => 'simple-redis-cache', 'id' => 'src-clear-all', 'title' => __( 'Clear all', 'simple-redis-cache' ), 'href' => self::purge_url( 'all' ) ) );
		$bar->add_node( array( 'parent' => 'simple-redis-cache', 'id' => 'src-clear-page', 'title' => __( 'Clear HTML page cache', 'simple-redis-cache' ), 'href' => self::purge_url( 'page' ) ) );
		$bar->add_node( array( 'parent' => 'simple-redis-cache', 'id' => 'src-clear-object', 'title' => __( 'Clear object cache', 'simple-redis-cache' ), 'href' => self::purge_url( 'object' ) ) );
		$bar->add_node( array( 'parent' => 'simple-redis-cache', 'id' => 'src-warm-page', 'title' => __( 'Warm cache', 'simple-redis-cache' ), 'href' => self::settings_url( 'warm' ) ) );
		$bar->add_node( array( 'parent' => 'simple-redis-cache', 'id' => 'src-settings', 'title' => __( 'Settings', 'simple-redis-cache' ), 'href' => self::settings_url() ) );
	}

	/** @param string[] $links @return string[] */
	public static function plugin_action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'simple-redis-cache' ) . '</a>' );
		return $links;
	}

	public static function queue_notice( string $message, string $type = 'info' ): void {
		$type    = in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info';
		$user_id = get_current_user_id();
		$notices = self::read_queued_notices( $user_id );
		$notices[] = array( 'message' => $message, 'type' => $type );
		$notices = array_slice( $notices, -10 );

		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::NOTICE_KEY, $notices );
		} else {
			update_option( self::NOTICE_KEY, $notices, false );
		}
	}

	public static function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$notices = self::read_queued_notices( $user_id );
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::NOTICE_KEY );
		} else {
			delete_option( self::NOTICE_KEY );
		}

		foreach ( is_array( $notices ) ? $notices : array() as $notice ) {
			$type = in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
		}

		$config = Simple_Redis_Cache_Config::get();
		if ( ! extension_loaded( 'redis' ) ) {
			self::notice_html( __( 'Simple Redis Cache requires the PhpRedis extension; caching will fail open until it is installed.', 'simple-redis-cache' ), 'error' );
		}

		foreach ( array( 'object', 'page' ) as $type ) {
			if ( ! empty( $config[ $type ]['enabled'] ) ) {
				$state = Simple_Redis_Cache_Dropins::status( $type );
				$cache_label = 'object' === $type
					? _x( 'Object cache', 'cache type in an admin notice', 'simple-redis-cache' )
					: _x( 'HTML page cache', 'cache type in an admin notice', 'simple-redis-cache' );
				if ( 'foreign' === $state ) {
					self::notice_html(
						sprintf(
							/* translators: %s: localized cache type. */
							__( '%s cannot start because another plugin owns its WordPress drop-in.', 'simple-redis-cache' ),
							$cache_label
						),
						'error'
					);
				} elseif ( 'missing' === $state ) {
					self::notice_html(
						sprintf(
							/* translators: %s: localized cache type. */
							__( '%s is enabled in settings, but its WordPress drop-in is not installed.', 'simple-redis-cache' ),
							$cache_label
						),
						'error'
					);
				}
			}
		}

		if ( ! empty( $config['page']['enabled'] ) && ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) ) {
			self::notice_html( __( "HTML page cache is enabled, but WP_CACHE is not true in the current request. Check wp-config.php; a newly added definition takes effect on the next request.", 'simple-redis-cache' ), 'warning' );
		}
	}

	private static function render_diagnostics(): void {
		$config       = Simple_Redis_Cache_Config::get();
		$redis        = new Simple_Redis_Cache_Redis( $config );
		$redis_ok     = $redis->ping();
		$redis_info   = $redis_ok ? self::redis_info( $redis ) : array();
		$early_status = self::early_config_diagnostics( $config );
		$object_state = Simple_Redis_Cache_Dropins::status( 'object' );
		$page_state   = Simple_Redis_Cache_Dropins::status( 'page' );
		$object_path  = WP_CONTENT_DIR . '/object-cache.php';
		$page_path    = WP_CONTENT_DIR . '/advanced-cache.php';
		$object_mode  = self::file_mode( $object_path );
		$page_mode    = self::file_mode( $page_path );
		$status_text  = array(
			'owned'   => __( 'Installed by Simple Redis Cache', 'simple-redis-cache' ),
			'foreign' => __( 'Foreign drop-in (not modified)', 'simple-redis-cache' ),
			'missing' => __( 'Not installed', 'simple-redis-cache' ),
		);
		?>
		<h2><?php esc_html_e( 'Status and diagnostics', 'simple-redis-cache' ); ?></h2>
		<table class="widefat striped" style="max-width:900px"><tbody>
			<tr><th scope="row"><?php esc_html_e( 'PhpRedis', 'simple-redis-cache' ); ?></th><td><?php echo extension_loaded( 'redis' ) ? esc_html__( 'Available', 'simple-redis-cache' ) : esc_html__( 'Missing', 'simple-redis-cache' ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Redis connection', 'simple-redis-cache' ); ?></th><td><?php echo $redis_ok ? esc_html__( 'Connected', 'simple-redis-cache' ) : esc_html( $redis->display_error() ?: __( 'Unavailable', 'simple-redis-cache' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Redis server', 'simple-redis-cache' ); ?></th><td>
				<?php if ( $redis_ok ) : ?>
					<?php echo ! empty( $redis_info['version'] ) ? esc_html( 'Redis ' . $redis_info['version'] ) : esc_html__( 'Version unavailable', 'simple-redis-cache' ); ?>
					· <?php esc_html_e( 'maxmemory policy', 'simple-redis-cache' ); ?>: <code><?php echo esc_html( (string) ( $redis_info['policy'] ?? __( 'unavailable', 'simple-redis-cache' ) ) ); ?></code>
					<?php if ( str_starts_with( (string) ( $redis_info['policy'] ?? '' ), 'allkeys-' ) ) : ?>
						<br><span class="notice-warning"><?php esc_html_e( 'This policy can evict persistent generation metadata and should be changed to noeviction or volatile-* when possible.', 'simple-redis-cache' ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<?php esc_html_e( 'Unavailable', 'simple-redis-cache' ); ?>
				<?php endif; ?>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Connection target', 'simple-redis-cache' ); ?></th><td><code><?php echo esc_html( strtoupper( (string) ( $config['redis']['scheme'] ?? 'tcp' ) ) ); ?></code> · <?php echo esc_html( sprintf( /* translators: %d: Redis database number. */ __( 'database %d', 'simple-redis-cache' ), (int) ( $config['redis']['database'] ?? 0 ) ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Object drop-in', 'simple-redis-cache' ); ?></th><td><?php echo esc_html( $status_text[ $object_state ] ); ?><br><code><?php echo esc_html( $object_path ); ?></code><?php echo '' !== $object_mode ? ' · ' . esc_html( sprintf( /* translators: %s: octal file mode. */ __( 'mode %s', 'simple-redis-cache' ), $object_mode ) ) : ''; ?><?php if ( self::file_is_group_or_other_writable( $object_path ) ) : ?><br><span class="notice-warning"><?php esc_html_e( 'The drop-in is writable by its group or by other system users.', 'simple-redis-cache' ); ?></span><?php endif; ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Page drop-in', 'simple-redis-cache' ); ?></th><td><?php echo esc_html( $status_text[ $page_state ] ); ?><br><code><?php echo esc_html( $page_path ); ?></code><?php echo '' !== $page_mode ? ' · ' . esc_html( sprintf( /* translators: %s: octal file mode. */ __( 'mode %s', 'simple-redis-cache' ), $page_mode ) ) : ''; ?><?php if ( self::file_is_group_or_other_writable( $page_path ) ) : ?><br><span class="notice-warning"><?php esc_html_e( 'The drop-in is writable by its group or by other system users.', 'simple-redis-cache' ); ?></span><?php endif; ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'WP_CACHE', 'simple-redis-cache' ); ?></th><td><?php echo defined( 'WP_CACHE' ) && WP_CACHE ? esc_html__( 'true', 'simple-redis-cache' ) : esc_html__( 'not true', 'simple-redis-cache' ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Generated early config', 'simple-redis-cache' ); ?></th><td><code><?php echo esc_html( Simple_Redis_Cache_Early_Config::path() ); ?></code><br><?php echo esc_html( (string) $early_status['label'] ); ?><?php echo '' !== (string) $early_status['mode'] ? ' · ' . esc_html( sprintf( /* translators: %s: octal file mode. */ __( 'mode %s', 'simple-redis-cache' ), (string) $early_status['mode'] ) ) : ''; ?><?php if ( ! empty( $early_status['permissions_warning'] ) ) : ?><br><span class="notice-warning"><?php esc_html_e( 'The generated configuration permissions are broader than the recommended 0640 or stricter.', 'simple-redis-cache' ); ?></span><?php endif; ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Redis key prefix', 'simple-redis-cache' ); ?></th><td><code><?php echo esc_html( Simple_Redis_Cache_Early_Config::prefix( $config ) ); ?></code><br><span class="description"><?php esc_html_e( 'Define WP_CACHE_KEY_SALT with a non-empty value in wp-config.php to control the leading prefix for every plugin key.', 'simple-redis-cache' ); ?></span></td></tr>
		</tbody></table>

		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Clear object cache and Clear all cache also delete every standard WordPress transient from this site database, including transients created by other plugins. Clear page cache does not.', 'simple-redis-cache' ); ?></p></div>
		<div style="display:flex;gap:8px;align-items:center;margin-top:16px;flex-wrap:wrap">
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>">
				<?php wp_nonce_field( self::TEST_ACTION ); ?>
				<?php submit_button( __( 'Test saved connection', 'simple-redis-cache' ), 'secondary', 'submit', false ); ?>
			</form>
			<a class="button" href="<?php echo esc_url( self::purge_url( 'all' ) ); ?>"><?php esc_html_e( 'Clear all cache', 'simple-redis-cache' ); ?></a>
			<a class="button" href="<?php echo esc_url( self::purge_url( 'page' ) ); ?>"><?php esc_html_e( 'Clear page cache', 'simple-redis-cache' ); ?></a>
			<a class="button" href="<?php echo esc_url( self::purge_url( 'object' ) ); ?>"><?php esc_html_e( 'Clear object cache', 'simple-redis-cache' ); ?></a>
		</div>
		<?php
	}

	private static function render_purge_confirmation( string $scope ): void {
		$all = 'all' === $scope;
		?>
		<h2><?php echo $all ? esc_html__( 'Confirm clearing all cache', 'simple-redis-cache' ) : esc_html__( 'Confirm clearing object cache', 'simple-redis-cache' ); ?></h2>
		<div class="notice notice-warning inline"><p>
			<?php
			echo esc_html(
				$all
					? __( 'This will invalidate both Redis cache layers and delete every standard WordPress transient from this site database, including transients created by other plugins.', 'simple-redis-cache' )
					: __( 'This will invalidate the Redis object cache and delete every standard WordPress transient from this site database, including transients created by other plugins.', 'simple-redis-cache' )
			);
			?>
		</p></div>
		<p><?php esc_html_e( 'This action cannot restore the deleted database transients. Redis payloads are invalidated by generation and expire later according to their TTL.', 'simple-redis-cache' ); ?></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PURGE_ACTION ); ?>">
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<input type="hidden" name="confirmed" value="1">
			<?php wp_nonce_field( self::PURGE_ACTION . '_' . $scope ); ?>
			<?php submit_button( $all ? __( 'Yes, clear all cache', 'simple-redis-cache' ) : __( 'Yes, clear object cache', 'simple-redis-cache' ), 'delete', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( self::settings_url( 'status' ) ); ?>"><?php esc_html_e( 'Cancel', 'simple-redis-cache' ); ?></a>
		</form>
		<?php
	}

	/** @return array{version:string,policy:string} */
	private static function redis_info( Simple_Redis_Cache_Redis $redis ): array {
		$result = array( 'version' => '', 'policy' => '' );
		$client = $redis->client();
		if ( null === $client ) {
			return $result;
		}

		try {
			$server = $client->info( 'server' );
			if ( is_array( $server ) ) {
				$result['version'] = (string) ( $server['redis_version'] ?? '' );
			}
		} catch ( Throwable ) {
			// INFO can be denied by Redis ACL; connection diagnostics remain usable.
		}

		try {
			$memory = $client->info( 'memory' );
			if ( is_array( $memory ) ) {
				$result['policy'] = strtolower( (string) ( $memory['maxmemory_policy'] ?? '' ) );
			}
		} catch ( Throwable ) {
			// Treat optional server metadata as unavailable rather than a failure.
		}

		return $result;
	}

	private static function redis_round_trip( Simple_Redis_Cache_Redis $redis, array $config ): true|WP_Error {
		$client = $redis->client();
		if ( null === $client ) {
			return new WP_Error( 'src_redis_diagnostic_unavailable', $redis->display_error() ?: __( 'Redis is unavailable.', 'simple-redis-cache' ) );
		}

		$key     = Simple_Redis_Cache_Early_Config::prefix( $config ) . 'meta:diagnostic:' . hash( 'sha256', wp_generate_uuid4() );
		$value   = hash( 'sha256', wp_generate_password( 64, true, true ) );
		$created = false;
		$deleted = false;

		try {
			$created = true === $client->set( $key, $value, array( 'nx', 'ex' => 30 ) );
			if ( ! $created ) {
				throw new RuntimeException( __( 'Redis refused the temporary diagnostic write.', 'simple-redis-cache' ) );
			}

			$read = $client->get( $key );
			if ( ! is_string( $read ) || ! hash_equals( $value, $read ) ) {
				throw new RuntimeException( __( 'Redis returned a different value for the temporary diagnostic key.', 'simple-redis-cache' ) );
			}

			$deleted = 1 === (int) $client->del( $key );
			if ( ! $deleted ) {
				throw new RuntimeException( __( 'Redis did not delete the temporary diagnostic key.', 'simple-redis-cache' ) );
			}

			return true;
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'src_redis_diagnostic_failed', $throwable->getMessage() );
		} finally {
			if ( $created && ! $deleted ) {
				try {
					$client->del( $key );
				} catch ( Throwable ) {
					// The key has a short TTL if best-effort cleanup is denied.
				}
			}
		}
	}

	/** @return array{label:string,mode:string,permissions_warning:bool} */
	private static function early_config_diagnostics( array $saved_config ): array {
		$path = Simple_Redis_Cache_Early_Config::path();
		$mode = self::file_mode( $path );
		$bits = self::file_mode_bits( $path );
		$result = array(
			'label'               => __( 'missing or unreadable', 'simple-redis-cache' ),
			'mode'                => $mode,
			'permissions_warning' => null !== $bits && 0 !== ( $bits & 0037 ),
		);

		if ( ! is_readable( $path ) ) {
			return $result;
		}

		$head = file_get_contents( $path, false, null, 0, 512 );
		if ( ! is_string( $head ) || ! str_contains( $head, 'Generated by Simple Redis Cache' ) ) {
			$result['label'] = __( 'readable, but not owned by Simple Redis Cache', 'simple-redis-cache' );
			return $result;
		}

		try {
			$generated = include $path;
		} catch ( Throwable ) {
			$generated = null;
		}

		if ( ! is_array( $generated ) ) {
			$result['label'] = __( 'owned, but invalid', 'simple-redis-cache' );
			return $result;
		}

		$result['label'] = hash_equals( hash( 'sha256', serialize( $saved_config ) ), hash( 'sha256', serialize( $generated ) ) )
			? __( 'readable and synchronized with saved settings', 'simple-redis-cache' )
			: __( 'readable, but different from saved settings', 'simple-redis-cache' );

		return $result;
	}

	private static function file_mode( string $path ): string {
		$bits = self::file_mode_bits( $path );
		return null === $bits ? '' : sprintf( '%04o', $bits );
	}

	private static function file_mode_bits( string $path ): ?int {
		$permissions = @fileperms( $path );
		return false === $permissions ? null : ( $permissions & 0777 );
	}

	private static function file_is_group_or_other_writable( string $path ): bool {
		$bits = self::file_mode_bits( $path );
		return null !== $bits && 0 !== ( $bits & 0022 );
	}

	private static function render_warm_page(): void {
		$config       = Simple_Redis_Cache_Config::get();
		$page         = (array) ( $config['page'] ?? array() );
		$page_enabled = ! empty( $page['enabled'] );
		$post_types   = Simple_Redis_Cache_Warmer::public_post_types();
		$taxonomies   = Simple_Redis_Cache_Warmer::public_taxonomies();
		?>
		<div id="src-cache-warm" class="src-cache-warm">
			<h2><?php esc_html_e( 'Cache warming', 'simple-redis-cache' ); ?></h2>
			<p><?php esc_html_e( 'Select public WordPress content to request anonymously and store in the Redis HTML page cache. Valid existing cache HITs are verified and left unchanged.', 'simple-redis-cache' ); ?></p>

			<?php if ( ! $page_enabled ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Enable HTML page cache before warming it.', 'simple-redis-cache' ); ?></p></div>
			<?php endif; ?>

			<form id="src-cache-warm-form">
				<div class="src-warm-toolbar">
					<button type="button" class="button" id="src-warm-select-all"<?php disabled( ! $page_enabled ); ?>><?php esc_html_e( 'Select all', 'simple-redis-cache' ); ?></button>
					<button type="button" class="button" id="src-warm-select-none"<?php disabled( ! $page_enabled ); ?>><?php esc_html_e( 'Select none', 'simple-redis-cache' ); ?></button>
				</div>

				<div class="src-warm-groups">
					<fieldset class="src-warm-group">
						<legend><?php esc_html_e( 'System pages', 'simple-redis-cache' ); ?></legend>
						<?php foreach ( Simple_Redis_Cache_Warmer::system_sources() as $name => $label ) : ?>
							<?php
							$available = Simple_Redis_Cache_Warmer::SYSTEM_HOME === $name
								? ! empty( $page['cache_home'] )
								: ! empty( $page['cache_archives'] );
							$available = $page_enabled && $available;
							?>
							<label class="src-warm-option<?php echo $available ? '' : ' is-disabled'; ?>">
								<input type="checkbox" name="system[]" value="<?php echo esc_attr( $name ); ?>" checked <?php disabled( ! $available ); ?>>
								<span><strong><?php echo esc_html( $label ); ?></strong></span>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Includes estimated pagination for the posts, author and date archives.', 'simple-redis-cache' ); ?></p>
					</fieldset>

					<fieldset class="src-warm-group">
						<legend><?php esc_html_e( 'Public post types', 'simple-redis-cache' ); ?></legend>
						<?php foreach ( $post_types as $name => $object ) : ?>
							<?php
							$has_archive = 'post' === $name ? ! empty( $page['cache_home'] ) : ( ! empty( $page['cache_archives'] ) && (bool) get_post_type_archive_link( $name ) );
							$available   = $page_enabled && is_post_type_viewable( $object ) && ( ! empty( $page['cache_singular'] ) || $has_archive );
							$attachment_disabled = 'attachment' === $name && ! Simple_Redis_Cache_Warmer::attachment_pages_enabled();
							$available   = $available && ! $attachment_disabled;
							?>
							<label class="src-warm-option<?php echo $available ? '' : ' is-disabled'; ?>">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $name ); ?>" checked <?php disabled( ! $available ); ?>>
								<span><strong><?php echo esc_html( $object->labels->name ); ?></strong> <code><?php echo esc_html( $name ); ?></code><small><?php echo ! empty( $object->_builtin ) ? esc_html__( 'Standard', 'simple-redis-cache' ) : esc_html__( 'Custom', 'simple-redis-cache' ); ?></small></span>
							</label>
							<?php if ( $attachment_disabled ) : ?>
								<p class="description src-warm-option-note"><?php esc_html_e( 'WordPress attachment pages are disabled, so media URLs will not be warmed.', 'simple-redis-cache' ); ?></p>
							<?php endif; ?>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Each selection includes public, non-password-protected singular items and its public archive with estimated pagination when available.', 'simple-redis-cache' ); ?></p>
					</fieldset>

					<fieldset class="src-warm-group">
						<legend><?php esc_html_e( 'Public taxonomies', 'simple-redis-cache' ); ?></legend>
						<?php foreach ( $taxonomies as $name => $object ) : ?>
							<?php $available = $page_enabled && ! empty( $page['cache_archives'] ) && is_taxonomy_viewable( $object ); ?>
							<label class="src-warm-option<?php echo $available ? '' : ' is-disabled'; ?>">
								<input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $name ); ?>" checked <?php disabled( ! $available ); ?>>
								<span><strong><?php echo esc_html( $object->labels->name ); ?></strong> <code><?php echo esc_html( $name ); ?></code><small><?php echo ! empty( $object->_builtin ) ? esc_html__( 'Standard', 'simple-redis-cache' ) : esc_html__( 'Custom', 'simple-redis-cache' ); ?></small></span>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Each selection includes non-empty public term archives and their estimated pagination.', 'simple-redis-cache' ); ?></p>
					</fieldset>
				</div>

				<p class="description"><?php esc_html_e( 'Sites using plain/query-string permalinks must enable query-string page caching before those URLs can be warmed.', 'simple-redis-cache' ); ?></p>
				<p class="description"><?php esc_html_e( 'When enabled, search results and 404 pages can be cached as they are requested, but they do not have a finite discoverable URL list and are not included in warming.', 'simple-redis-cache' ); ?></p>
				<p class="description"><?php esc_html_e( 'Warming generates the anonymous no-cookie variant. Language variants selected by vary cookies are not warmed. Keep this tab open until the process finishes.', 'simple-redis-cache' ); ?></p>
				<p class="submit">
					<button type="submit" class="button button-primary" id="src-warm-start"<?php disabled( ! $page_enabled ); ?>><?php esc_html_e( 'Warm cache', 'simple-redis-cache' ); ?></button>
					<button type="button" class="button" id="src-warm-stop" hidden><?php esc_html_e( 'Stop', 'simple-redis-cache' ); ?></button>
				</p>
			</form>

			<div id="src-warm-progress-wrap" class="src-warm-progress" hidden>
				<progress id="src-warm-progress" value="0" max="1"></progress>
				<p id="src-warm-progress-text" aria-live="polite"></p>
				<div id="src-warm-counts" class="src-warm-counts"></div>
			</div>
			<div id="src-warm-result" aria-live="polite"></div>
			<ul id="src-warm-errors" class="src-warm-errors" hidden></ul>
		</div>
		<?php
	}

	/** @param array<string, mixed> $options */
	private static function add_field( string $group, string $key, string $title, string $type, array $options = array(), string $description = '' ): void {
		add_settings_field(
			'src-' . $group . '-' . $key,
			$title,
			array( self::class, 'render_field' ),
			self::section_page( $group ),
			'src_' . $group,
			array(
				'group'       => $group,
				'key'         => $key,
				'type'        => $type,
				'options'     => $options,
				'description' => $description,
				'label_for'   => 'src-' . $group . '-' . str_replace( '_', '-', $key ),
			)
		);
	}

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Redis cache settings.', 'simple-redis-cache' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action );
	}

	private static function authorize_ajax_warm(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Cache warming accepts POST requests only.', 'simple-redis-cache' ) ), 405 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to warm this cache.', 'simple-redis-cache' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::WARM_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'The cache-warming security token is invalid or expired.', 'simple-redis-cache' ) ), 403 );
		}
	}

	/** @param mixed $value @return string[] */
	private static function request_name_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$names = array();
		foreach ( wp_unslash( $value ) as $name ) {
			if ( is_string( $name ) ) {
				$name = sanitize_key( $name );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	private static function purge_url( string $scope ): string {
		if ( in_array( $scope, array( 'all', 'object' ), true ) ) {
			return add_query_arg(
				array(
					'page'       => self::PAGE,
					'tab'        => 'status',
					'src_action' => self::PURGE_CONFIRM_ACTION,
					'scope'      => $scope,
				),
				admin_url( 'options-general.php' )
			);
		}

		$url = add_query_arg(
			array( 'action' => self::PURGE_ACTION, 'scope' => $scope ),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::PURGE_ACTION . '_' . $scope );
	}

	private static function requested_purge_confirmation(): ?string {
		$action = isset( $_GET['src_action'] ) && is_string( $_GET['src_action'] )
			? sanitize_key( wp_unslash( $_GET['src_action'] ) )
			: '';
		$scope = isset( $_GET['scope'] ) && is_string( $_GET['scope'] )
			? sanitize_key( wp_unslash( $_GET['scope'] ) )
			: '';

		return self::PURGE_CONFIRM_ACTION === $action && in_array( $scope, array( 'all', 'object' ), true )
			? $scope
			: null;
	}

	private static function settings_url( string $tab = '' ): string {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		if ( in_array( $tab, array_merge( self::FORM_TABS, array( 'warm', 'status' ) ), true ) ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}

		return $url;
	}

	private static function redirect_back(): never {
		$redirect = wp_get_referer();
		if ( $redirect ) {
			$query = (string) wp_parse_url( $redirect, PHP_URL_QUERY );
			$args  = array();
			parse_str( $query, $args );
			if (
				self::PAGE === ( $args['page'] ?? '' ) &&
				self::PURGE_CONFIRM_ACTION === ( $args['src_action'] ?? '' )
			) {
				$redirect = self::settings_url( 'status' );
			}
		}
		if ( ! $redirect || false !== strpos( $redirect, 'admin-post.php' ) ) {
			$redirect = self::settings_url( 'status' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/** @return array<string, string> */
	private static function tabs(): array {
		return array(
			'redis'  => __( 'Redis connection', 'simple-redis-cache' ),
			'object' => __( 'Object cache', 'simple-redis-cache' ),
			'page'   => __( 'HTML page cache', 'simple-redis-cache' ),
			'warm'   => __( 'Cache warming', 'simple-redis-cache' ),
			'status' => __( 'Status', 'simple-redis-cache' ),
		);
	}

	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] )
			? sanitize_key( wp_unslash( $_GET['tab'] ) )
			: self::DEFAULT_TAB;

		return array_key_exists( $tab, self::tabs() ) ? $tab : self::DEFAULT_TAB;
	}

	private static function section_page( string $tab ): string {
		return self::PAGE . '-' . $tab;
	}

	private static function render_tabs( string $current_tab ): void {
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Cache settings sections', 'simple-redis-cache' ) . '">';
		foreach ( self::tabs() as $tab => $label ) {
			$active = $current_tab === $tab;
			echo '<a class="nav-tab' . ( $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::settings_url( $tab ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private static function notice_html( string $message, string $type ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/** Read action feedback from the database, bypassing the cache being tested. */
	private static function read_queued_notices( int $user_id ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			$value = $user_id > 0 ? get_user_meta( $user_id, self::NOTICE_KEY, true ) : get_option( self::NOTICE_KEY, array() );
			return is_array( $value ) ? $value : array();
		}

		if ( $user_id > 0 && ! empty( $wpdb->usermeta ) ) {
			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id DESC LIMIT 1",
					$user_id,
					self::NOTICE_KEY
				)
			);
		} elseif ( ! empty( $wpdb->options ) ) {
			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					self::NOTICE_KEY
				)
			);
		} else {
			$raw = null;
		}

		$value = null === $raw ? array() : maybe_unserialize( $raw );
		return is_array( $value ) ? $value : array();
	}
}
