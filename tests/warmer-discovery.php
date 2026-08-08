<?php
/**
 * Dependency-light regression test for cache-warming URL deduplication.
 *
 * WordPress hands back the same page in different shapes: home_url('/') keeps the
 * trailing slash while get_post_type_archive_link('post') does not. Comparing raw
 * strings therefore warms a posts-on-front home page twice and overstates the
 * discovered count.
 *
 * Run directly with: php tests/warmer-discovery.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'SIMPLE_REDIS_CACHE_VERSION', '0.0.0-test' );

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-warmer.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$reflection = new ReflectionClass( Simple_Redis_Cache_Warmer::class );
$key        = $reflection->getMethod( 'deduplication_key' );

$same = static function ( string $left, string $right ) use ( $key ): bool {
	return $key->invoke( null, $left ) === $key->invoke( null, $right );
};

$assert( $same( 'https://example.test/', 'https://example.test' ), 'The home page with and without a trailing slash was treated as two pages.' );
$assert( $same( 'https://example.test/blog/', 'https://example.test/blog' ), 'An inner path with and without a trailing slash was treated as two pages.' );
$assert( $same( 'https://Example.Test/blog/', 'https://example.test/blog' ), 'Host casing produced two different pages.' );
$assert( $same( 'https://example.test:443/news/', 'https://example.test/news' ), 'The default HTTPS port produced a different page than an implicit one.' );

$assert( ! $same( 'https://example.test/a/', 'https://example.test/b/' ), 'Two different paths were collapsed into one.' );
$assert( ! $same( 'https://example.test/a/', 'https://example.test/a/?paged=2' ), 'Pagination was collapsed into the base URL.' );
$assert( ! $same( 'https://example.test/a/?p=1', 'https://example.test/a/?p=2' ), 'Different query strings were collapsed into one page.' );
$assert( ! $same( 'https://example.test/a/', 'http://example.test/a/' ), 'Two schemes were collapsed into one page.' );

// Paths stay case-sensitive: WordPress can serve /A/ and /a/ as different posts.
$assert( ! $same( 'https://example.test/Sale/', 'https://example.test/sale/' ), 'Path casing was collapsed, which can merge two distinct posts.' );

$bound = $reflection->getConstant( 'MAX_DISCOVERED_URLS' );
$assert( is_int( $bound ) && $bound > 0, 'Discovery no longer declares an upper bound on the URL set.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: warming deduplicates equivalent URLs and keeps discovery bounded.\n";
