<?php
/**
 * Discovers public frontend URLs and verifies manual HTML-cache warming.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Warmer {
	public const SYSTEM_HOME    = 'home';
	public const SYSTEM_AUTHORS = 'authors';
	public const SYSTEM_DATES   = 'dates';

	private const QUERY_BATCH_SIZE = 250;
	private const WARM_HEADER      = 'X-Simple-Redis-Cache-Warm';

	/** @return array<string, WP_Post_Type> */
	public static function public_post_types(): array {
		$objects = get_post_types( array( 'public' => true ), 'objects' );
		return is_array( $objects ) ? $objects : array();
	}

	/** @return array<string, WP_Taxonomy> */
	public static function public_taxonomies(): array {
		$objects = get_taxonomies( array( 'public' => true ), 'objects' );
		return is_array( $objects ) ? $objects : array();
	}

	/** @return array<string, string> */
	public static function system_sources(): array {
		return array(
			self::SYSTEM_HOME    => __( 'Homepage and posts page', 'simple-redis-cache' ),
			self::SYSTEM_AUTHORS => __( 'Author archives', 'simple-redis-cache' ),
			self::SYSTEM_DATES   => __( 'Date archives', 'simple-redis-cache' ),
		);
	}

	public static function attachment_pages_enabled(): bool {
		return '1' === (string) get_option( 'wp_attachment_pages_enabled', '0' );
	}

	/**
	 * Build the finite set of anonymous public URLs represented by the submitted
	 * source checkboxes. Submitted names are intersected with the live WordPress
	 * registry before any query runs.
	 *
	 * @param string[]             $system
	 * @param string[]             $post_types
	 * @param string[]             $taxonomies
	 * @param array<string, mixed> $config
	 * @return array{urls: string[], warnings: string[], selected_sources: int}|WP_Error
	 */
	public static function discover( array $system, array $post_types, array $taxonomies, array $config ): array|WP_Error {
		$page = (array) ( $config['page'] ?? array() );
		if ( empty( $page['enabled'] ) ) {
			return new WP_Error( 'src_warm_page_cache_disabled', __( 'Enable HTML page cache before warming it.', 'simple-redis-cache' ) );
		}

		$system_allowlist = array_keys( self::system_sources() );
		$system           = array_values( array_intersect( self::clean_names( $system ), $system_allowlist ) );
		$post_objects     = self::public_post_types();
		$taxonomy_objects = self::public_taxonomies();
		$post_types       = array_values( array_intersect( self::clean_names( $post_types ), array_keys( $post_objects ) ) );
		$taxonomies       = array_values( array_intersect( self::clean_names( $taxonomies ), array_keys( $taxonomy_objects ) ) );

		$urls     = array();
		$seen     = array();
		$warnings = array();
		$add_url  = static function ( mixed $candidate ) use ( &$urls, &$seen ): void {
			if ( ! is_string( $candidate ) ) {
				return;
			}

			$url = Simple_Redis_Cache_Warmer::site_url( $candidate );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				return;
			}

			$seen[ $url ] = true;
			$urls[]       = $url;
		};

		$posts_per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );

		if ( in_array( self::SYSTEM_HOME, $system, true ) ) {
			if ( ! empty( $page['cache_home'] ) ) {
				self::add_home_urls( $add_url, $posts_per_page );
			} else {
				$warnings[] = __( 'Homepage URLs were skipped because home-page caching is disabled.', 'simple-redis-cache' );
			}
		}

		if ( in_array( self::SYSTEM_AUTHORS, $system, true ) ) {
			if ( ! empty( $page['cache_archives'] ) ) {
				self::add_author_urls( $add_url, $posts_per_page );
			} else {
				$warnings[] = __( 'Author archives were skipped because archive caching is disabled.', 'simple-redis-cache' );
			}
		}

		if ( in_array( self::SYSTEM_DATES, $system, true ) ) {
			if ( ! empty( $page['cache_archives'] ) ) {
				self::add_date_urls( $add_url, $posts_per_page );
			} else {
				$warnings[] = __( 'Date archives were skipped because archive caching is disabled.', 'simple-redis-cache' );
			}
		}

		foreach ( $post_types as $post_type ) {
			$object = $post_objects[ $post_type ];
			if ( ! is_post_type_viewable( $object ) ) {
				$warnings[] = sprintf(
					/* translators: %s: post type or taxonomy label. */
					__( '%s was skipped because it has no public frontend view.', 'simple-redis-cache' ),
					$object->labels->name
				);
				continue;
			}

			if ( 'attachment' === $post_type && ! self::attachment_pages_enabled() ) {
				$warnings[] = __( 'Media attachment pages were skipped because WordPress attachment pages are disabled.', 'simple-redis-cache' );
				continue;
			}

			$needs_singular = ! empty( $page['cache_singular'] );
			$archive_url    = 'post' === $post_type
				? ( ! empty( $page['cache_home'] ) ? get_post_type_archive_link( 'post' ) : false )
				: ( ! empty( $page['cache_archives'] ) ? get_post_type_archive_link( $post_type ) : false );
			if ( ! $needs_singular && ! is_string( $archive_url ) ) {
				$warnings[] = sprintf(
					/* translators: %s: post type label. */
					__( '%s was skipped because the applicable page-cache types are disabled.', 'simple-redis-cache' ),
					$object->labels->name
				);
				continue;
			}

			$total = self::add_post_type_urls( $post_type, $needs_singular, $add_url );
			if ( is_string( $archive_url ) ) {
				self::add_archive_with_pagination( $archive_url, $total, $posts_per_page, $add_url );
			}
		}

		if ( ! empty( $taxonomies ) && empty( $page['cache_archives'] ) ) {
			$warnings[] = __( 'Taxonomy archives were skipped because archive caching is disabled.', 'simple-redis-cache' );
		} elseif ( ! empty( $page['cache_archives'] ) ) {
			foreach ( $taxonomies as $taxonomy ) {
				$object = $taxonomy_objects[ $taxonomy ];
				if ( ! is_taxonomy_viewable( $object ) ) {
					$warnings[] = sprintf(
						/* translators: %s: post type or taxonomy label. */
						__( '%s was skipped because it has no public frontend view.', 'simple-redis-cache' ),
						$object->labels->name
					);
					continue;
				}
				self::add_taxonomy_urls( $taxonomy, $posts_per_page, $add_url );
			}
		}

		return array(
			'urls'             => $urls,
			'warnings'         => array_values( array_unique( $warnings ) ),
			'selected_sources' => count( $system ) + count( $post_types ) + count( $taxonomies ),
		);
	}

	/**
	 * Server-side fallback for installations whose frontend and wp-admin use
	 * different origins. The URL must already carry a valid per-user signature.
	 *
	 * @return array{result:string,http_status:int,cache_status:string,message:string}
	 */
	public static function warm_remote_url( string $url ): array {
		$url = self::site_url( $url );
		if ( '' === $url ) {
			return self::warm_result( 'failed', 0, '', __( 'The generated URL is not a valid URL for this site.', 'simple-redis-cache' ) );
		}

		$probe = self::remote_request( $url, 'HEAD' );
		if ( is_wp_error( $probe ) ) {
			return self::warm_result( 'failed', 0, '', $probe->get_error_message() );
		}
		if ( 'HIT' === $probe['cache_status'] ) {
			return self::warm_result( 'cached', $probe['http_status'], 'HIT', '' );
		}
		if ( 'MISS' !== $probe['cache_status'] ) {
			return self::warm_result( 'bypass', $probe['http_status'], $probe['cache_status'], __( 'The page cache bypassed this URL.', 'simple-redis-cache' ) );
		}

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$render = self::remote_request( $url, 'GET' );
			if ( is_wp_error( $render ) ) {
				return self::warm_result( 'failed', 0, '', $render->get_error_message() );
			}
			if ( 'HIT' === $render['cache_status'] ) {
				return self::warm_result( 'cached', $render['http_status'], 'HIT', '' );
			}
			if ( 'MISS' !== $render['cache_status'] ) {
				return self::warm_result( 'bypass', $render['http_status'], $render['cache_status'], __( 'The page cache bypassed this URL.', 'simple-redis-cache' ) );
			}

			$verify = self::remote_request( $url, 'HEAD' );
			if ( is_wp_error( $verify ) ) {
				return self::warm_result( 'failed', 0, '', $verify->get_error_message() );
			}
			if ( 'HIT' === $verify['cache_status'] ) {
				return self::warm_result( 'warmed', $verify['http_status'], 'HIT', '' );
			}
			if ( 'MISS' !== $verify['cache_status'] ) {
				return self::warm_result( 'bypass', $verify['http_status'], $verify['cache_status'], __( 'The page cache bypassed this URL.', 'simple-redis-cache' ) );
			}
		}

		return self::warm_result( 'failed', $verify['http_status'] ?? 0, 'MISS', __( 'Redis did not return a cache HIT after the page was rendered.', 'simple-redis-cache' ) );
	}

	public static function sign_url( string $url, int $user_id ): string {
		return hash_hmac( 'sha256', $user_id . "\0" . $url, wp_salt( 'nonce' ) );
	}

	public static function verify_url_signature( string $url, string $signature, int $user_id ): bool {
		$expected = self::sign_url( $url, $user_id );
		return '' !== $signature && hash_equals( $expected, $signature );
	}

	public static function site_url( string $url ): string {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url || str_contains( $url, '#' ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		if (
			$scheme !== strtolower( (string) ( $home['scheme'] ?? '' ) ) ||
			$host !== strtolower( rtrim( (string) ( $home['host'] ?? '' ), '.' ) ) ||
			self::effective_port( $parts ) !== self::effective_port( $home )
		) {
			return '';
		}

		return $url;
	}

	/** @param callable(string):void $add_url */
	private static function add_home_urls( callable $add_url, int $posts_per_page ): void {
		$add_url( home_url( '/' ) );

		$archive_url = get_post_type_archive_link( 'post' );
		if ( ! is_string( $archive_url ) ) {
			$posts_page_id = (int) get_option( 'page_for_posts', 0 );
			$archive_url   = $posts_page_id > 0 ? get_permalink( $posts_page_id ) : home_url( '/' );
		}

		if ( is_string( $archive_url ) ) {
			self::add_archive_with_pagination( $archive_url, self::count_posts( 'post' ), $posts_per_page, $add_url );
		}
	}

	/** @param callable(string):void $add_url */
	private static function add_author_urls( callable $add_url, int $posts_per_page ): void {
		global $wpdb;

		$statuses = self::public_statuses();
		if ( ! isset( $wpdb->posts ) || empty( $statuses ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = "SELECT post_author, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$placeholders}) AND post_password = '' AND post_author > 0 GROUP BY post_author ORDER BY post_author ASC";
		$params       = array_merge( array( 'post' ), $statuses );
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user_id = (int) ( $row->post_author ?? 0 );
			if ( $user_id > 0 ) {
				self::add_archive_with_pagination( get_author_posts_url( $user_id ), (int) ( $row->total ?? 0 ), $posts_per_page, $add_url );
			}
		}
	}

	/** @param callable(string):void $add_url */
	private static function add_date_urls( callable $add_url, int $posts_per_page ): void {
		global $wpdb;

		$statuses = self::public_statuses();
		if ( ! isset( $wpdb->posts ) || empty( $statuses ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = "SELECT YEAR(post_date) AS year_number, MONTH(post_date) AS month_number, DAY(post_date) AS day_number, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$placeholders}) AND post_password = '' AND post_date > '1000-01-01 00:00:00' GROUP BY YEAR(post_date), MONTH(post_date), DAY(post_date) ORDER BY year_number ASC, month_number ASC, day_number ASC";
		$params       = array_merge( array( 'post' ), $statuses );
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$years        = array();
		$months       = array();
		$days         = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$year  = (int) ( $row->year_number ?? 0 );
			$month = (int) ( $row->month_number ?? 0 );
			$day   = (int) ( $row->day_number ?? 0 );
			$total = (int) ( $row->total ?? 0 );
			if ( $year < 1 || $month < 1 || $day < 1 || $total < 1 ) {
				continue;
			}
			$years[ $year ]              = ( $years[ $year ] ?? 0 ) + $total;
			$months[ $year ][ $month ]   = ( $months[ $year ][ $month ] ?? 0 ) + $total;
			$days[ $year ][ $month ][ $day ] = $total;
		}

		foreach ( $years as $year => $year_total ) {
			self::add_archive_with_pagination( get_year_link( (int) $year ), (int) $year_total, $posts_per_page, $add_url );
			foreach ( $months[ $year ] ?? array() as $month => $month_total ) {
				self::add_archive_with_pagination( get_month_link( (int) $year, (int) $month ), (int) $month_total, $posts_per_page, $add_url );
				foreach ( $days[ $year ][ $month ] ?? array() as $day => $day_total ) {
					self::add_archive_with_pagination( get_day_link( (int) $year, (int) $month, (int) $day ), (int) $day_total, $posts_per_page, $add_url );
				}
			}
		}
	}

	/** @param callable(string):void $add_url */
	private static function add_post_type_urls( string $post_type, bool $add_singular, callable $add_url ): int {
		$statuses = self::public_statuses();
		if ( 'attachment' === $post_type ) {
			$statuses[] = 'inherit';
			$statuses   = array_values( array_unique( $statuses ) );
		}

		$paged = 1;
		$total = 0;
		do {
			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => $statuses,
					'posts_per_page'         => $add_singular ? self::QUERY_BATCH_SIZE : 1,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'has_password'           => false,
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => false,
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => true,
				)
			);

			$total = max( $total, (int) $query->found_posts );
			if ( $add_singular ) {
				foreach ( $query->posts as $post_id ) {
					if ( 'attachment' === $post_type ) {
						$parent_id = wp_get_post_parent_id( (int) $post_id );
						$status    = $parent_id > 0 ? get_post_status( $parent_id ) : 'publish';
						$status_object = is_string( $status ) ? get_post_status_object( $status ) : null;
						if ( ! $status_object || empty( $status_object->public ) ) {
							continue;
						}
					}
					$add_url( get_permalink( (int) $post_id ) );
				}
			}

			$max_pages = $add_singular ? max( 1, (int) $query->max_num_pages ) : 1;
			$paged++;
		} while ( $add_singular && $paged <= $max_pages );

		return $total;
	}

	/** @param callable(string):void $add_url */
	private static function add_taxonomy_urls( string $taxonomy, int $posts_per_page, callable $add_url ): void {
		$offset = 0;
		do {
			$terms = get_terms(
				array(
					'taxonomy'               => $taxonomy,
					'hide_empty'             => true,
					'number'                 => self::QUERY_BATCH_SIZE,
					'offset'                 => $offset,
					'orderby'                => 'term_id',
					'order'                  => 'ASC',
					'update_term_meta_cache' => false,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				break;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term, $taxonomy );
				if ( ! is_wp_error( $link ) ) {
					self::add_archive_with_pagination( $link, max( 0, (int) $term->count ), $posts_per_page, $add_url );
				}
			}

			$count   = count( $terms );
			$offset += $count;
		} while ( self::QUERY_BATCH_SIZE === $count );
	}

	/** @param callable(string):void $add_url */
	private static function add_archive_with_pagination( string $base_url, int $total, int $posts_per_page, callable $add_url ): void {
		$add_url( $base_url );
		$pages = (int) ceil( max( 0, $total ) / max( 1, $posts_per_page ) );
		for ( $page = 2; $page <= $pages; $page++ ) {
			$add_url( self::paged_url( $base_url, $page ) );
		}
	}

	private static function paged_url( string $base_url, int $page ): string {
		global $wp_rewrite;

		$parts = wp_parse_url( $base_url );
		if ( empty( $parts['query'] ) && get_option( 'permalink_structure' ) && isset( $wp_rewrite ) && is_object( $wp_rewrite ) ) {
			$pagination_base = trim( (string) $wp_rewrite->pagination_base, '/' );
			$pagination_base = '' !== $pagination_base ? $pagination_base : 'page';
			return user_trailingslashit( trailingslashit( $base_url ) . $pagination_base . '/' . max( 2, $page ), 'paged' );
		}

		return add_query_arg( 'paged', max( 2, $page ), $base_url );
	}

	private static function count_posts( string $post_type ): int {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => self::public_statuses(),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		return max( 0, (int) $query->found_posts );
	}

	/** @return string[] */
	private static function public_statuses(): array {
		$statuses = get_post_stati( array( 'public' => true ), 'names' );
		$statuses = array_values( array_map( 'strval', is_array( $statuses ) ? $statuses : array() ) );
		return ! empty( $statuses ) ? $statuses : array( 'publish' );
	}

	/** @param mixed[] $names @return string[] */
	private static function clean_names( array $names ): array {
		$clean = array();
		foreach ( $names as $name ) {
			if ( is_string( $name ) ) {
				$name = sanitize_key( $name );
				if ( '' !== $name ) {
					$clean[] = $name;
				}
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/** @param array<string, mixed> $parts */
	private static function effective_port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80;
	}

	/** @return array{http_status:int,cache_status:string}|WP_Error */
	private static function remote_request( string $url, string $method ): array|WP_Error {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 30,
				'redirection' => 0,
				'sslverify'   => true,
				'cookies'     => array(),
				'headers'     => array(
					'Accept'        => 'text/html,application/xhtml+xml',
					'Cache-Control' => 'no-cache',
					self::WARM_HEADER => '1',
				),
				'user-agent'  => 'Simple Redis Cache/' . SIMPLE_REDIS_CACHE_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$cache_status = strtoupper( trim( (string) wp_remote_retrieve_header( $response, 'x-simple-redis-cache' ) ) );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'src_warm_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The frontend returned HTTP %d.', 'simple-redis-cache' ),
					$status
				)
			);
		}

		if ( ! in_array( $cache_status, array( 'HIT', 'MISS', 'BYPASS' ), true ) ) {
			return new WP_Error( 'src_warm_missing_status', __( 'The frontend response did not contain a page-cache status.', 'simple-redis-cache' ) );
		}

		return array( 'http_status' => $status, 'cache_status' => $cache_status );
	}

	/** @return array{result:string,http_status:int,cache_status:string,message:string} */
	private static function warm_result( string $result, int $http_status, string $cache_status, string $message ): array {
		return array(
			'result'       => $result,
			'http_status'  => $http_status,
			'cache_status' => $cache_status,
			'message'      => $message,
		);
	}
}
