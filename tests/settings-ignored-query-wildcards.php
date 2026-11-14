<?php
/**
 * Saving settings refuses an ignored query parameter made only of wildcards.
 *
 * "*" (or "?", "*?"…) in Ignored query parameters would drop every parameter from
 * the cache key, so /?p=2 would be served the cached home page. The line is
 * dropped on save and the administrator is told why; the rest is kept.
 *
 * Run directly with: php tests/settings-ignored-query-wildcards.php
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', '/srv/example/' );
define( 'WP_CONTENT_DIR', '/srv/example/wp-content' );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['src_test_settings_errors'] = array();

function get_option( string $name, mixed $default = false ): mixed {
	return 'simple_redis_cache_settings' === $name ? array() : $default;
}

function sanitize_text_field( string $value ): string {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? '' );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
	$GLOBALS['src_test_settings_errors'][] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-config.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$saved = Simple_Redis_Cache_Config::sanitize(
	array( 'page' => array( 'ignored_query_parameters' => "utm_*\n*\ngclid\n ? \n*?*\n_ga\n_*" ) )
);
$assert( array( 'utm_*', 'gclid', '_ga', '_*' ) === $saved['page']['ignored_query_parameters'], 'A wildcard-only ignored query parameter was saved, or a real one was lost: ' . json_encode( $saved['page']['ignored_query_parameters'] ) );

$errors = $GLOBALS['src_test_settings_errors'];
$assert( 1 === count( $errors ), 'Dropping wildcard-only patterns did not report exactly one settings error.' );
$assert( Simple_Redis_Cache_Config::OPTION === ( $errors[0]['setting'] ?? '' ), 'The settings error is not attached to the plugin option.' );
$assert( 'src_wildcard_ignored_query_parameter' === ( $errors[0]['code'] ?? '' ), 'The settings error has an unexpected code.' );
$assert( str_contains( (string) ( $errors[0]['message'] ?? '' ), '/?p=2' ), 'The settings error does not explain what the pattern would break.' );

$GLOBALS['src_test_settings_errors'] = array();
$clean = Simple_Redis_Cache_Config::sanitize( array( 'page' => array( 'ignored_query_parameters' => "utm_*\ngclid" ) ) );
$assert( array( 'utm_*', 'gclid' ) === $clean['page']['ignored_query_parameters'], 'Ordinary ignored query parameters were changed.' );
$assert( array() === $GLOBALS['src_test_settings_errors'], 'Ordinary ignored query parameters raised a settings error.' );

// The list form a migration passes through must be cleaned the same way.
$migrated = Simple_Redis_Cache_Config::sanitize( array( 'page' => array( 'ignored_query_parameters' => array( 'fbclid', '*' ) ) ) );
$assert( array( 'fbclid' ) === $migrated['page']['ignored_query_parameters'], 'A stored list kept its wildcard-only pattern through sanitize().' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: wildcard-only ignored query parameters are refused on save with an explanation.\n";
