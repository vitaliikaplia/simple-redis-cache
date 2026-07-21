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
					'missingStatus'   => __( 'The frontend response did not contain a page-cache status.', 'simple-redis-cache' ),
					'bypassMessage'   => __( 'The page cache bypassed this URL.', 'simple-redis-cache' ),
					'notStored'       => __( 'Redis did not return a cache HIT after the page was rendered.', 'simple-redis-cache' ),
					'alreadyCached'   => __( 'Already cached', 'simple-redis-cache' ),
					'warmed'          => __( 'Warmed now', 'simple-redis-cache' ),
					'bypassed'        => __( 'Bypassed', 'simple-redis-cache' ),
					'failed'          => __( 'Failed', 'simple-redis-cache' ),
					'unknownError'    => __( 'Unknown error.', 'simple-redis-cache' ),
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
				echo '<p>' . esc_html__( 'PhpRedis and one standalone Redis server are supported. Credentials are stored in the WordPress database.', 'simple-redis-cache' ) . '</p>';
			},
			self::section_page( 'redis' )
		);

		self::add_field( 'redis', 'scheme', __( 'Connection type', 'simple-redis-cache' ), 'select', array( 'tcp' => 'TCP', 'tls' => 'TLS', 'unix' => __( 'Unix socket', 'simple-redis-cache' ) ) );
		self::add_field( 'redis', 'host', __( 'Host', 'simple-redis-cache' ), 'text', array(), __( 'For example: 127.0.0.1. Ignored for Unix sockets.', 'simple-redis-cache' ) );
		self::add_field( 'redis', 'port', __( 'Port', 'simple-redis-cache' ), 'number', array( 'min' => 1, 'max' => 65535, 'step' => 1 ) );
		self::add_field( 'redis', 'path', __( 'Unix socket path', 'simple-redis-cache' ), 'text' );
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

		self::add_field( 'page', 'enabled', __( 'Enable page cache', 'simple-redis-cache' ), 'checkbox', array(), __( "Enabling this option also adds define( 'WP_CACHE', true ); to wp-config.php when needed.", 'simple-redis-cache' ) );
		self::add_field( 'page', 'ttl', __( 'Page TTL', 'simple-redis-cache' ), 'number', array( 'min' => 60, 'max' => MONTH_IN_SECONDS, 'step' => 1 ), __( 'Seconds.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'cache_logged_in', __( 'Cache logged-in users', 'simple-redis-cache' ), 'checkbox', array(), __( 'Disabled is safest. Logged-in variants are isolated by session when enabled.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'cache_home', __( 'Cache home page', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_singular', __( 'Cache posts, pages and CPTs', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_archives', __( 'Cache archives', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_search', __( 'Cache search results', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_404', __( 'Cache 404 responses', 'simple-redis-cache' ), 'checkbox' );
		self::add_field( 'page', 'cache_query_strings', __( 'Cache query strings', 'simple-redis-cache' ), 'checkbox', array(), __( 'Unknown query parameters bypass page cache unless this is enabled.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'ignored_query_parameters', __( 'Ignored query parameters', 'simple-redis-cache' ), 'textarea', array(), __( 'One name or prefix ending in * per line. These parameters are removed from the cache key.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'excluded_paths', __( 'Excluded paths', 'simple-redis-cache' ), 'textarea', array(), __( 'One path pattern per line; * is supported.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'excluded_cookies', __( 'Excluded request cookies', 'simple-redis-cache' ), 'textarea', array(), __( 'A matching cookie bypasses page cache. One name or prefix per line.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'excluded_user_agents', __( 'Excluded user agents', 'simple-redis-cache' ), 'textarea', array(), __( 'One substring per line.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'vary_cookies', __( 'Cookies that vary the cache key', 'simple-redis-cache' ), 'textarea', array(), __( 'One cookie name per line. Useful for language cookies that safely select a page variant.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'allowed_set_cookies', __( 'Allowed response Set-Cookie names', 'simple-redis-cache' ), 'textarea', array(), __( 'Responses setting only these cookie names may be stored; Set-Cookie headers are never replayed from cache.', 'simple-redis-cache' ) );
		self::add_field( 'page', 'debug_header', __( 'Debug response header', 'simple-redis-cache' ), 'checkbox', array(), __( 'Send X-Simple-Redis-Cache with HIT, MISS or BYPASS status.', 'simple-redis-cache' ) );
	}

	/**
	 * Sanitize settings and schedule a sync even when update_option() detects
	 * that the option value itself did not change. This lets an administrator
	 * fix filesystem permissions or remove a foreign drop-in, then press Save
	 * again without toggling an unrelated setting.
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

		$config = Simple_Redis_Cache_Config::sanitize( $input );

		add_action(
			'shutdown',
			static function () use ( $config ): void {
				Simple_Redis_Cache_Plugin::synchronize( $config, true );
			},
			PHP_INT_MAX
		);

		return $config;
	}

	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = self::current_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Simple Redis Cache', 'simple-redis-cache' ); ?></h1>
			<p><?php esc_html_e( 'Redis-only object and full-page cache for a single WordPress site. Cache entries are isolated by WP_CACHE_KEY_SALT when it is defined.', 'simple-redis-cache' ); ?></p>

			<?php self::render_tabs( $tab ); ?>

			<?php if ( 'status' === $tab ) : ?>
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

		$redis = new Simple_Redis_Cache_Redis( Simple_Redis_Cache_Config::get() );
		if ( $redis->ping() ) {
			$version = '';
			try {
				$info    = $redis->client()?->info( 'server' );
				$version = is_array( $info ) ? (string) ( $info['redis_version'] ?? '' ) : '';
			} catch ( Throwable ) {
				$version = '';
			}

			self::queue_notice(
				$version
					? sprintf( /* translators: %s: Redis version. */ __( 'Redis connection succeeded (Redis %s).', 'simple-redis-cache' ), $version )
					: __( 'Redis connection succeeded.', 'simple-redis-cache' ),
				'success'
			);
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

		$scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';
		if ( ! in_array( $scope, array( 'all', 'object', 'page' ), true ) ) {
			wp_die( esc_html__( 'Invalid cache purge scope.', 'simple-redis-cache' ), '', array( 'response' => 400 ) );
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
		$object_state = Simple_Redis_Cache_Dropins::status( 'object' );
		$page_state   = Simple_Redis_Cache_Dropins::status( 'page' );
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
			<tr><th scope="row"><?php esc_html_e( 'Object drop-in', 'simple-redis-cache' ); ?></th><td><?php echo esc_html( $status_text[ $object_state ] ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Page drop-in', 'simple-redis-cache' ); ?></th><td><?php echo esc_html( $status_text[ $page_state ] ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'WP_CACHE', 'simple-redis-cache' ); ?></th><td><?php echo defined( 'WP_CACHE' ) && WP_CACHE ? esc_html__( 'true', 'simple-redis-cache' ) : esc_html__( 'not true', 'simple-redis-cache' ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Generated early config', 'simple-redis-cache' ); ?></th><td><code><?php echo esc_html( Simple_Redis_Cache_Early_Config::path() ); ?></code> — <?php echo is_readable( Simple_Redis_Cache_Early_Config::path() ) ? esc_html__( 'readable', 'simple-redis-cache' ) : esc_html__( 'missing or unreadable', 'simple-redis-cache' ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Redis key prefix', 'simple-redis-cache' ); ?></th><td><code><?php echo esc_html( Simple_Redis_Cache_Early_Config::prefix( $config ) ); ?></code><br><span class="description"><?php esc_html_e( 'Define WP_CACHE_KEY_SALT in wp-config.php to control the leading prefix for every key.', 'simple-redis-cache' ); ?></span></td></tr>
		</tbody></table>

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

	private static function render_warm_page(): void {
		$config       = Simple_Redis_Cache_Config::get();
		$page         = (array) ( $config['page'] ?? array() );
		$page_enabled = ! empty( $page['enabled'] );
		$post_types   = Simple_Redis_Cache_Warmer::public_post_types();
		$taxonomies   = Simple_Redis_Cache_Warmer::public_taxonomies();
		?>
		<div id="src-cache-warm" class="src-cache-warm">
			<h2><?php esc_html_e( 'Cache warming', 'simple-redis-cache' ); ?></h2>
			<p><?php esc_html_e( 'Select public WordPress content to request anonymously and store in the Redis HTML page cache. Existing cache entries are verified and left unchanged.', 'simple-redis-cache' ); ?></p>

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
						<p class="description"><?php esc_html_e( 'Includes existing pagination for the posts, author and date archives.', 'simple-redis-cache' ); ?></p>
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
						<p class="description"><?php esc_html_e( 'Each selection includes all public, non-password-protected singular items and its public archive with pagination when available.', 'simple-redis-cache' ); ?></p>
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
						<p class="description"><?php esc_html_e( 'Each selection includes non-empty public term archives and their known pagination.', 'simple-redis-cache' ); ?></p>
					</fieldset>
				</div>

				<p class="description"><?php esc_html_e( 'Warming generates the anonymous default-language variant. Keep this tab open until the process finishes.', 'simple-redis-cache' ); ?></p>
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
		$url = add_query_arg(
			array( 'action' => self::PURGE_ACTION, 'scope' => $scope ),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::PURGE_ACTION . '_' . $scope );
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
