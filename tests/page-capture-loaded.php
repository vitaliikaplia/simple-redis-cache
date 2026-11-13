<?php
/**
 * Dependency-light guard for the page invalidator's class dependency.
 *
 * The invalidator reads Simple_Redis_Cache_Page_Capture::LISTING_RESOURCE_ID at
 * shutdown, but the advanced-cache drop-in only loads that class for requests it
 * may capture. wp-admin, AJAX, REST and cron saves bypass it, so the main plugin
 * file has to load the class too, or every such save ends in a fatal error.
 *
 * Run directly with: php tests/page-capture-loaded.php
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$plugin = (string) file_get_contents( $root . '/simple-redis-cache.php' );

$loaded = (bool) preg_match(
	"/^require_once SIMPLE_REDIS_CACHE_DIR \. 'includes\/class-simple-redis-cache-page-capture\.php';$/m",
	$plugin
);

if ( ! $loaded ) {
	fwrite( STDERR, "FAIL: simple-redis-cache.php does not load the page capture class the invalidator depends on.\n" );
	exit( 1 );
}

echo "OK: the page capture class is loaded on every request, not only by the advanced-cache drop-in.\n";
