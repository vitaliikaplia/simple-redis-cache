<?php
/**
 * The installation identity must not depend on how a path to wp-content was
 * typed on a case-insensitive filesystem: WP-CLI with `--path` in another case
 * would otherwise take the site for a copy of itself.
 *
 * Usage: php tests/identity-case.php
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$test_root = sys_get_temp_dir() . '/src-identity-' . bin2hex( random_bytes( 6 ) );
$content   = $test_root . '/My-Site/Wp-Content';
mkdir( $content, 0755, true );
register_shutdown_function(
	static function () use ( $test_root, $content ): void {
		@rmdir( $content );
		@rmdir( dirname( $content ) );
		@rmdir( $test_root );
	}
);

define( 'ABSPATH', $test_root . '/' );
// Spelled in another case than on disk, as a document root typed in lower case would be.
define( 'WP_CONTENT_DIR', in_array( PHP_OS_FAMILY, array( 'Darwin', 'Windows' ), true ) ? $test_root . '/my-site/wp-content' : $content );

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$real  = rtrim( str_replace( '\\', '/', (string) realpath( $content ) ), '/' );
$lower = substr( $real, 0, -strlen( '/My-Site/Wp-Content' ) ) . '/my-site/wp-content';

// The component walk works on any OS when asked to behave like a case-insensitive one.
$assert( $real === Simple_Redis_Cache_Early_Config::canonical_case( $lower, 'Darwin' ), 'A path typed in another case was not mapped to its on-disk spelling.' );
$assert( $real === Simple_Redis_Cache_Early_Config::canonical_case( $real, 'Darwin' ), 'A path in its on-disk spelling was changed.' );
$assert( $lower === Simple_Redis_Cache_Early_Config::canonical_case( $lower, 'Linux' ), 'A case-sensitive OS changed the case of a path.' );
$assert( '//server/Share/wp-content' === Simple_Redis_Cache_Early_Config::canonical_case( '//server/Share/wp-content', 'Windows' ), 'A UNC path lost its leading slashes or was rewritten.' );
$missing = '/src-missing-' . bin2hex( random_bytes( 4 ) ) . '/Foo';
$assert( $missing === Simple_Redis_Cache_Early_Config::canonical_case( $missing, 'Darwin' ), 'A path that does not exist was rewritten.' );

// On a case-insensitive filesystem the identity itself must match.
if ( is_dir( $lower ) && in_array( PHP_OS_FAMILY, array( 'Darwin', 'Windows' ), true ) ) {
	$assert( Simple_Redis_Cache_Early_Config::identity_for( $lower ) === Simple_Redis_Cache_Early_Config::install_identity(), 'wp-content typed in another case produced another installation identity.' );
	$assert( Simple_Redis_Cache_Early_Config::identity_for( $real ) === Simple_Redis_Cache_Early_Config::install_identity(), 'The on-disk spelling produced another installation identity.' );
	// An identity stored before 0.10.0 from the typed spelling still names this site.
	$legacy = Simple_Redis_Cache_Early_Config::identity_for( (string) WP_CONTENT_DIR, false );
	$assert( $legacy !== Simple_Redis_Cache_Early_Config::install_identity(), 'The fixture does not exercise a legacy spelling.' );
	$assert( Simple_Redis_Cache_Early_Config::is_install_identity( $legacy ), 'An identity stored from the old spelling made this site look like a copy after the update.' );
}
$assert( Simple_Redis_Cache_Early_Config::is_install_identity( Simple_Redis_Cache_Early_Config::install_identity() ), 'The current identity is not recognized.' );
$assert( ! Simple_Redis_Cache_Early_Config::is_install_identity( str_repeat( 'e', 32 ) ), 'A foreign identity was recognized as this installation.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: the installation identity ignores the case a path to wp-content was typed in.\n";
