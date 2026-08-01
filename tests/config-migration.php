<?php
/** Focused, dependency-free configuration migration regression test. */

declare(strict_types=1);

define( 'ABSPATH', '/srv/example/' );
define( 'WP_CONTENT_DIR', '/srv/example/wp-content' );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['src_test_option'] = array(
	'config_version' => 1,
	'redis'          => array(
		'host'     => 'cache.internal',
		'port'     => 6380,
		'password' => 'preserve-me',
	),
	'object'         => array(
		'enabled' => true,
		'max_ttl' => 7200,
	),
	'page'           => array(
		'enabled'    => true,
		'ttl'        => 900,
		'cache_home' => false,
	),
	'obsolete'       => 'remove-me',
);

function get_option( string $name, mixed $default = false ): mixed {
	return 'simple_redis_cache_settings' === $name ? $GLOBALS['src_test_option'] : $default;
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

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-config.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( Simple_Redis_Cache_Config::needs_migration(), 'Version 1 should require migration.' );
$migrated = Simple_Redis_Cache_Config::migrate();
$assert( is_array( $migrated ), 'Migration did not return a current configuration.' );
$assert( 3 === $migrated['config_version'], 'Migration did not advance config_version.' );
$assert( 'cache.internal' === $migrated['redis']['host'], 'Migration lost the Redis host.' );
$assert( 'preserve-me' === $migrated['redis']['password'], 'Migration lost the Redis password.' );
$assert( true === $migrated['object']['enabled'] && 7200 === $migrated['object']['max_ttl'], 'Migration changed object settings.' );
$assert( true === $migrated['page']['enabled'] && 900 === $migrated['page']['ttl'], 'Migration changed page settings.' );
$assert( false === $migrated['page']['cache_home'], 'Migration replaced an explicit false value with a default.' );
$assert( false === $migrated['page']['invalidate_on_post_update'], 'Migration did not initialize post-update invalidation safely.' );
$assert( false === $migrated['page']['invalidate_term_archives_on_post_update'], 'Migration did not initialize taxonomy-archive invalidation safely.' );
$assert( ! array_key_exists( 'obsolete', $migrated ), 'Migration retained an unknown root field.' );

$GLOBALS['src_test_option']['config_version'] = 4;
$assert( ! Simple_Redis_Cache_Config::needs_migration(), 'A newer stored schema must not be downgraded.' );
$assert( null === Simple_Redis_Cache_Config::migrate(), 'A newer stored schema returned migration output.' );

echo "OK: config schema migration preserves settings and refuses downgrades.\n";
