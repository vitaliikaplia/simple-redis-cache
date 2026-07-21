<?php
/**
 * Namespace-generation cache invalidation and transient mirror cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Purger {
	/**
	 * Purge a logical cache namespace without FLUSHDB/FLUSHALL.
	 *
	 * @return array{object_generation?:int,page_generation?:int,db_rows_deleted?:int}|WP_Error
	 */
	public static function purge( string $scope = 'all' ): array|WP_Error {
		if ( is_multisite() ) {
			return new WP_Error( 'src_multisite_unsupported', __( 'Simple Redis Cache supports single-site WordPress installations only.', 'simple-redis-cache' ) );
		}

		if ( ! in_array( $scope, array( 'all', 'object', 'page' ), true ) ) {
			return new WP_Error( 'src_invalid_purge_scope', __( 'Invalid cache purge scope.', 'simple-redis-cache' ) );
		}

		$redis  = new Simple_Redis_Cache_Redis( Simple_Redis_Cache_Config::get() );
		$result = array();
		$errors = new WP_Error();

		if ( 'all' === $scope || 'object' === $scope ) {
			$generation = $redis->bump_generation( 'object' );
			if ( false === $generation ) {
				$errors->add(
					'src_object_purge_failed',
					sprintf(
						/* translators: %s: Redis connection error. */
						__( 'Could not clear object cache: %s', 'simple-redis-cache' ),
						$redis->display_error() ?: __( 'Redis is unavailable.', 'simple-redis-cache' )
					)
				);
			} else {
				$result['object_generation'] = $generation;
			}

			$deleted = self::delete_transient_mirrors();
			if ( is_wp_error( $deleted ) ) {
				foreach ( $deleted->get_error_messages() as $message ) {
					$errors->add( 'src_transient_cleanup_failed', $message );
				}
			} else {
				$result['db_rows_deleted'] = $deleted;
			}
		}

		if ( 'all' === $scope || 'page' === $scope ) {
			$generation = $redis->bump_generation( 'page' );
			if ( false === $generation ) {
				$errors->add(
					'src_page_purge_failed',
					sprintf(
						/* translators: %s: Redis connection error. */
						__( 'Could not clear page cache: %s', 'simple-redis-cache' ),
						$redis->display_error() ?: __( 'Redis is unavailable.', 'simple-redis-cache' )
					)
				);
			} else {
				$result['page_generation'] = $generation;
			}
		}

		return $errors->has_errors() ? $errors : $result;
	}

	/** Delete only WordPress transient value/timeout rows from wp_options. */
	private static function delete_transient_mirrors(): int|WP_Error {
		global $wpdb;

		$patterns = array(
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
		);

		$sql = $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			...$patterns
		);

		$deleted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is supplied by wpdb; values are prepared above.
		if ( false === $deleted ) {
			return new WP_Error( 'src_transient_db_delete_failed', __( 'Could not delete transient cache mirrors from the database.', 'simple-redis-cache' ) );
		}

		return (int) $deleted;
	}
}
