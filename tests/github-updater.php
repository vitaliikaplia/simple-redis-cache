<?php
/**
 * Dependency-light regression checks for the master-branch GitHub updater.
 *
 * Run directly with: php tests/github-updater.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SIMPLE_REDIS_CACHE_VERSION', '0.2.0' );
define( 'SIMPLE_REDIS_CACHE_BASENAME', 'custom-installed/simple-redis-cache.php' );

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function trailingslashit( string $value ): string {
	return untrailingslashit( $value ) . '/';
}

function get_site_transient( string $key ): mixed {
	return $GLOBALS['src_test_update_metadata'] ?? false;
}

function is_admin(): bool {
	return false;
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-github-updater.php';

$reflection = new ReflectionClass( Simple_Redis_Cache_GitHub_Updater::class );
$updater    = $reflection->newInstanceWithoutConstructor();
$checks     = 0;

$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

/** @return mixed */
$invoke = static function ( object $instance, string $method, mixed ...$arguments ) use ( $reflection ): mixed {
	$reflected_method = $reflection->getMethod( $method );
	$reflected_method->setAccessible( true );

	return $reflected_method->invoke( $instance, ...$arguments );
};

$assert(
	'2.4.0' === $invoke( $updater, 'parse_plugin_version', "<?php\n/**\n * Version: 2.4.0\n */\n" ),
	'A valid plugin version header was not parsed.'
);
$assert(
	'2.4.0-beta.1+build.7' === $invoke( $updater, 'parse_plugin_version', "/**\n * Version:\t2.4.0-beta.1+build.7\n */" ),
	'A valid prerelease/build version was not parsed.'
);
$assert(
	null === $invoke( $updater, 'parse_plugin_version', "<?php\n/* Plugin Name: Simple Redis Cache */\n" ),
	'Contents without a Version header were accepted.'
);
$assert(
	null === $invoke( $updater, 'parse_plugin_version', "/* Version: v2.4.0 */" ),
	'A version that does not begin with a digit was accepted.'
);
$assert(
	null === $invoke( $updater, 'parse_plugin_version', "/* Version: 2.4.0 <script> */" ),
	'A version containing unsafe characters was accepted.'
);
$assert(
	null === $invoke( $updater, 'parse_plugin_version', '/* Version: 1' . str_repeat( '0', 64 ) . ' */' ),
	'A version longer than the updater limit was accepted.'
);

$assert(
	'https://raw.githubusercontent.com/vitaliikaplia/simple-redis-cache/master/simple-redis-cache.php'
		=== $invoke( $updater, 'get_remote_plugin_file_url' ),
	'The updater does not read the version header from the master branch.'
);
$assert(
	'https://github.com/vitaliikaplia/simple-redis-cache/archive/refs/heads/master.zip'
		=== $invoke( $updater, 'get_package_url' ),
	'The updater package URL is not the master branch archive.'
);
$assert(
	'https://github.com/vitaliikaplia/simple-redis-cache' === $invoke( $updater, 'get_repository_url' ),
	'The updater repository URL changed unexpectedly.'
);

$GLOBALS['src_test_update_metadata'] = array(
	'version'      => '0.3.0',
	'package'      => 'https://github.com/vitaliikaplia/simple-redis-cache/archive/refs/heads/master.zip',
	'url'          => 'https://github.com/vitaliikaplia/simple-redis-cache',
	'branch'       => 'master',
	'last_checked' => time(),
);

$newer_transient = (object) array(
	'checked'   => array( SIMPLE_REDIS_CACHE_BASENAME => '0.2.0' ),
	'no_update' => array(
		SIMPLE_REDIS_CACHE_BASENAME => (object) array( 'new_version' => '0.2.0' ),
	),
);
$newer_result    = $updater->filter_update_plugins_transient( $newer_transient );
$assert( isset( $newer_result->response[ SIMPLE_REDIS_CACHE_BASENAME ] ), 'A newer master-branch version was not offered as an update.' );
$assert(
	'0.3.0' === $newer_result->response[ SIMPLE_REDIS_CACHE_BASENAME ]->new_version,
	'The offered update has the wrong remote version.'
);
$assert(
	$GLOBALS['src_test_update_metadata']['package'] === $newer_result->response[ SIMPLE_REDIS_CACHE_BASENAME ]->package,
	'The offered update does not use the master branch archive.'
);
$assert(
	! isset( $newer_result->no_update[ SIMPLE_REDIS_CACHE_BASENAME ] ),
	'A stale no-update response survived after a newer version was found.'
);

$GLOBALS['src_test_update_metadata']['version'] = '0.2.0';
$current_updater   = $reflection->newInstanceWithoutConstructor();
$current_transient = (object) array(
	'checked'  => array( SIMPLE_REDIS_CACHE_BASENAME => '0.2.0' ),
	'response' => array(
		SIMPLE_REDIS_CACHE_BASENAME => (object) array( 'new_version' => '9.9.9' ),
	),
);
$current_result    = $current_updater->filter_update_plugins_transient( $current_transient );
$assert( isset( $current_result->no_update[ SIMPLE_REDIS_CACHE_BASENAME ] ), 'The current version was not recorded as up to date.' );
$assert(
	'0.2.0' === $current_result->no_update[ SIMPLE_REDIS_CACHE_BASENAME ]->new_version,
	'The no-update response has the wrong remote version.'
);
$assert(
	! isset( $current_result->response[ SIMPLE_REDIS_CACHE_BASENAME ] ),
	'A stale update response survived when the installed version was current.'
);

$source_selection_root = sys_get_temp_dir() . '/simple-redis-cache-source-selection-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $source_selection_root, 0700 ) && ! is_dir( $source_selection_root ) ) {
	throw new RuntimeException( 'Could not create the updater source-selection directory.' );
}

$remove_tree = static function ( string $path ) use ( &$remove_tree ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = scandir( $path );
	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$remove_tree( $path . DIRECTORY_SEPARATOR . $item );
			}
		}
	}
	rmdir( $path );
};

try {
	$github_source = $source_selection_root . '/simple-redis-cache-master';
	if ( ! mkdir( $github_source, 0700 ) ) {
		throw new RuntimeException( 'Could not create the GitHub archive directory.' );
	}
	file_put_contents( $github_source . '/simple-redis-cache.php', "<?php\n/* Version: 0.2.0 */\n", LOCK_EX );

	$normalized = $updater->normalize_github_source_directory(
		trailingslashit( $github_source ),
		trailingslashit( $source_selection_root ),
		null,
		array( 'plugin' => SIMPLE_REDIS_CACHE_BASENAME )
	);
	$installed_directory = $source_selection_root . '/custom-installed';
	$assert(
		trailingslashit( $installed_directory ) === $normalized,
		'The simple-redis-cache-master archive was not normalized to the custom installed directory.'
	);
	$assert(
		is_file( $installed_directory . '/simple-redis-cache.php' ),
		'The normalized master archive lost its main plugin file.'
	);

	$already_normalized = $updater->normalize_github_source_directory(
		trailingslashit( $installed_directory ),
		trailingslashit( $source_selection_root ),
		null,
		array( 'plugin' => SIMPLE_REDIS_CACHE_BASENAME )
	);
	$assert(
		trailingslashit( $installed_directory ) === $already_normalized,
		'An already normalized custom installed directory was changed unexpectedly.'
	);

	$unrelated_source = $source_selection_root . '/another-plugin-master';
	if ( ! mkdir( $unrelated_source, 0700 ) ) {
		throw new RuntimeException( 'Could not create the unrelated archive directory.' );
	}
	$unchanged = $updater->normalize_github_source_directory(
		trailingslashit( $unrelated_source ),
		trailingslashit( $source_selection_root ),
		null,
		array( 'plugin' => SIMPLE_REDIS_CACHE_BASENAME )
	);
	$assert( trailingslashit( $unrelated_source ) === $unchanged, 'An unrelated archive directory was renamed.' );
} finally {
	unset( $GLOBALS['src_test_update_metadata'] );
	$remove_tree( $source_selection_root );
}

fwrite( STDOUT, sprintf( "OK: master-branch GitHub updater passed %d regression checks.\n", $checks ) );
