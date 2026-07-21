<?php
/**
 * Safe installation and removal of the WordPress cache drop-ins.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Dropins {
	private const MARKER = 'Simple Redis Cache Drop-In';

	/**
	 * Bring both drop-ins in line with the saved configuration.
	 *
	 * @param array<string, mixed>|null $config
	 */
	public static function sync( ?array $config = null ): bool|WP_Error {
		$config = $config ?? Simple_Redis_Cache_Config::get();
		$errors = new WP_Error();

		self::sync_one( 'object', ! empty( $config['object']['enabled'] ), $errors );

		if ( ! empty( $config['page']['enabled'] ) ) {
			$page_install = self::install( 'page' );
			self::merge_error( $errors, $page_install );

			// Never enable WP_CACHE for a foreign advanced-cache.php collision.
			if ( ! is_wp_error( $page_install ) ) {
				self::merge_error( $errors, self::ensure_wp_cache() );
			}
		} else {
			self::merge_error( $errors, self::remove( 'page' ) );
		}

		return $errors->has_errors() ? $errors : true;
	}

	/** Remove only drop-ins carrying our ownership marker. */
	public static function remove_all(): bool|WP_Error {
		$errors = new WP_Error();

		self::merge_error( $errors, self::remove( 'object' ) );
		self::merge_error( $errors, self::remove( 'page' ) );

		return $errors->has_errors() ? $errors : true;
	}

	/** Install one drop-in without overwriting a foreign implementation. */
	public static function install( string $type ): bool|WP_Error {
		$paths = self::paths( $type );
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}

		$source = $paths['source'];
		$target = $paths['target'];

		if ( ! is_readable( $source ) ) {
			return new WP_Error(
				'src_dropin_source_missing',
				sprintf(
					/* translators: %s: drop-in source path. */
					__( 'The Simple Redis Cache drop-in template is not readable: %s', 'simple-redis-cache' ),
					$source
				)
			);
		}

		$content = file_get_contents( $source );
		if ( false === $content || ! str_contains( $content, self::MARKER ) ) {
			return new WP_Error(
				'src_dropin_source_invalid',
				__( 'The Simple Redis Cache drop-in template is invalid.', 'simple-redis-cache' )
			);
		}

		$placeholder = "'__SIMPLE_REDIS_CACHE_LOADER__'";
		$loader      = SIMPLE_REDIS_CACHE_DIR . 'dropins/' . ( 'object' === $type ? 'object-cache-loader.php' : 'advanced-cache-loader.php' );
		if ( ! str_contains( $content, $placeholder ) ) {
			return new WP_Error(
				'src_dropin_loader_placeholder_missing',
				__( 'The Simple Redis Cache drop-in loader placeholder is missing.', 'simple-redis-cache' )
			);
		}
		$content = str_replace( $placeholder, var_export( $loader, true ), $content );

		if ( file_exists( $target ) && ! self::is_owned( $target ) ) {
			return new WP_Error(
				'src_dropin_collision_' . $type,
				sprintf(
					/* translators: %s: existing drop-in path. */
					__( 'Simple Redis Cache did not overwrite the foreign drop-in at %s. Disable the plugin that owns it or remove it manually.', 'simple-redis-cache' ),
					$target
				)
			);
		}

		$temp = $target . '.src-' . wp_generate_password( 10, false, false ) . '.tmp';
		if ( false === @file_put_contents( $temp, $content, LOCK_EX ) ) {
			return new WP_Error(
				'src_dropin_write_failed_' . $type,
				sprintf(
					/* translators: %s: drop-in path. */
					__( 'Could not write the temporary drop-in for %s.', 'simple-redis-cache' ),
					$target
				)
			);
		}

		@chmod( $temp, 0644 );

		// Check ownership again immediately before the atomic replacement.
		if ( file_exists( $target ) && ! self::is_owned( $target ) ) {
			@unlink( $temp );
			return new WP_Error(
				'src_dropin_collision_' . $type,
				__( 'The drop-in changed while Simple Redis Cache was installing it. No file was overwritten.', 'simple-redis-cache' )
			);
		}

		if ( ! @rename( $temp, $target ) ) {
			@unlink( $temp );
			return new WP_Error(
				'src_dropin_install_failed_' . $type,
				sprintf(
					/* translators: %s: target drop-in path. */
					__( 'Could not install the drop-in at %s.', 'simple-redis-cache' ),
					$target
				)
			);
		}

		clearstatcache( true, $target );
		self::invalidate_opcache( $target );
		return true;
	}

	/** Remove one drop-in if, and only if, it belongs to this plugin. */
	public static function remove( string $type ): bool|WP_Error {
		$paths = self::paths( $type );
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}

		$target = $paths['target'];
		if ( ! file_exists( $target ) || ! self::is_owned( $target ) ) {
			return true;
		}

		if ( ! @unlink( $target ) ) {
			return new WP_Error(
				'src_dropin_remove_failed_' . $type,
				sprintf(
					/* translators: %s: target drop-in path. */
					__( 'Could not remove the Simple Redis Cache drop-in at %s.', 'simple-redis-cache' ),
					$target
				)
			);
		}

		clearstatcache( true, $target );
		self::invalidate_opcache( $target );
		return true;
	}

	/** Return missing, owned, or foreign for a drop-in target. */
	public static function status( string $type ): string {
		$paths = self::paths( $type );
		if ( is_wp_error( $paths ) || ! file_exists( $paths['target'] ) ) {
			return 'missing';
		}

		return self::is_owned( $paths['target'] ) ? 'owned' : 'foreign';
	}

	/**
	 * Make sure WordPress loads advanced-cache.php on subsequent requests.
	 * Existing WP_CACHE=false declarations are changed to true; WP_CACHE is
	 * deliberately never removed or disabled by this plugin.
	 */
	public static function ensure_wp_cache(): bool|WP_Error {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return true;
		}

		$wp_config = self::find_wp_config();
		if ( null === $wp_config || ! is_readable( $wp_config ) || ! is_writable( $wp_config ) ) {
			return new WP_Error(
				'src_wp_cache_config_unwritable',
				__( "Page cache was enabled, but wp-config.php is not writable. Add define( 'WP_CACHE', true ); manually.", 'simple-redis-cache' )
			);
		}

		$content = file_get_contents( $wp_config );
		if ( false === $content ) {
			return new WP_Error( 'src_wp_config_read_failed', __( 'Could not read wp-config.php.', 'simple-redis-cache' ) );
		}

		// Match only an active, standalone define statement. A commented example
		// must not be mistaken for the definition that enables the drop-in.
		$define_pattern = '/^[\t ]*define[\t ]*\([\t ]*([\'\"])WP_CACHE\1[\t ]*,[\t ]*([^\)\r\n]+)\)[\t ]*;[\t ]*(?:(?:\/\/|#).*)?$/mi';
		if ( preg_match( $define_pattern, $content, $match ) ) {
			$value = strtolower( trim( (string) $match[2] ) );
			if ( 'true' === $value || '1' === $value ) {
				return true;
			}

			if ( 'false' !== $value && '0' !== $value ) {
				return new WP_Error(
					'src_wp_cache_dynamic_define',
					__( "WP_CACHE uses a dynamic value in wp-config.php. Set it to true manually before enabling page cache.", 'simple-redis-cache' )
				);
			}

			$updated = preg_replace( $define_pattern, "define( 'WP_CACHE', true );", $content, 1 );
		} else {
			if ( defined( 'WP_CACHE' ) ) {
				return new WP_Error(
					'src_wp_cache_dynamic_define',
					__( "WP_CACHE is already defined by a non-standard expression. Set it to true manually before enabling page cache.", 'simple-redis-cache' )
				);
			}

			$line       = "define( 'WP_CACHE', true );\n\n";
			$updated    = null;
			$stop_token = strpos( $content, "/* That's all, stop editing!" );

			if ( false !== $stop_token ) {
				$updated = substr( $content, 0, $stop_token ) . $line . substr( $content, $stop_token );
			} elseif ( preg_match( '/^\s*require_once\s+ABSPATH\s*\.\s*[\'\"]wp-settings\.php[\'\"]\s*;/mi', $content, $match, PREG_OFFSET_CAPTURE ) ) {
				$offset  = (int) $match[0][1];
				$updated = substr( $content, 0, $offset ) . $line . substr( $content, $offset );
			} elseif ( preg_match( '/<\?php\s*/', $content, $match, PREG_OFFSET_CAPTURE ) ) {
				$offset  = (int) $match[0][1] + strlen( (string) $match[0][0] );
				$updated = substr( $content, 0, $offset ) . "\n" . $line . substr( $content, $offset );
			}
		}

		if ( ! is_string( $updated ) ) {
			return new WP_Error(
				'src_wp_cache_insert_failed',
				__( "Could not find a safe insertion point in wp-config.php. Add define( 'WP_CACHE', true ); manually.", 'simple-redis-cache' )
			);
		}

		$temp = $wp_config . '.src-' . wp_generate_password( 10, false, false ) . '.tmp';
		if ( false === @file_put_contents( $temp, $updated, LOCK_EX ) ) {
			return new WP_Error( 'src_wp_config_write_failed', __( 'Could not write a temporary wp-config.php file.', 'simple-redis-cache' ) );
		}

		$mode = @fileperms( $wp_config );
		if ( false !== $mode ) {
			@chmod( $temp, $mode & 0777 );
		}

		// Do not replace a wp-config.php that changed during this operation.
		$current = file_get_contents( $wp_config );
		if ( false === $current || ! hash_equals( hash( 'sha256', $content ), hash( 'sha256', $current ) ) ) {
			@unlink( $temp );
			return new WP_Error( 'src_wp_config_changed', __( 'wp-config.php changed while it was being updated. No changes were made.', 'simple-redis-cache' ) );
		}

		if ( ! @rename( $temp, $wp_config ) ) {
			@unlink( $temp );
			return new WP_Error(
				'src_wp_config_replace_failed',
				__( "Could not update wp-config.php. Add define( 'WP_CACHE', true ); manually.", 'simple-redis-cache' )
			);
		}

		clearstatcache( true, $wp_config );
		self::invalidate_opcache( $wp_config );
		return true;
	}

	private static function sync_one( string $type, bool $enabled, WP_Error $errors ): void {
		$result = $enabled ? self::install( $type ) : self::remove( $type );
		self::merge_error( $errors, $result );
	}

	private static function is_owned( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		$head = (string) fread( $handle, 16384 );
		fclose( $handle );

		return str_contains( $head, self::MARKER );
	}

	/** @return array{source:string,target:string}|WP_Error */
	private static function paths( string $type ): array|WP_Error {
		if ( 'object' === $type ) {
			return array(
				'source' => SIMPLE_REDIS_CACHE_DIR . 'dropins/object-cache.php',
				'target' => WP_CONTENT_DIR . '/object-cache.php',
			);
		}

		if ( 'page' === $type ) {
			return array(
				'source' => SIMPLE_REDIS_CACHE_DIR . 'dropins/advanced-cache.php',
				'target' => WP_CONTENT_DIR . '/advanced-cache.php',
			);
		}

		return new WP_Error( 'src_unknown_dropin', __( 'Unknown cache drop-in type.', 'simple-redis-cache' ) );
	}

	private static function find_wp_config(): ?string {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( untrailingslashit( ABSPATH ) ) . '/wp-config.php',
		);

		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	private static function merge_error( WP_Error $target, bool|WP_Error $result ): void {
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		foreach ( $result->get_error_codes() as $code ) {
			foreach ( $result->get_error_messages( $code ) as $message ) {
				$target->add( $code, $message, $result->get_error_data( $code ) );
			}
		}
	}

	private static function invalidate_opcache( string $path ): void {
		if ( function_exists( 'wp_opcache_invalidate' ) ) {
			wp_opcache_invalidate( $path, true );
		} elseif ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}
	}
}
