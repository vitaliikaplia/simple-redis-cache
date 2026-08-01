<?php
/**
 * Focused post/term page-content version regression test.
 *
 * Usage: php tests/page-content-invalidation-integration.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) );

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-redis.php';

if ( ! extension_loaded( 'redis' ) ) {
	fwrite( STDERR, "SKIP: PhpRedis is not installed.\n" );
	exit( 0 );
}

$host   = getenv( 'SRC_TEST_REDIS_HOST' ) ?: '127.0.0.1';
$port   = (int) ( getenv( 'SRC_TEST_REDIS_PORT' ) ?: 6379 );
$config = Simple_Redis_Cache_Early_Config::defaults();
$config['redis']['host'] = $host;
$config['redis']['port'] = $port;
$config['redis']['timeout'] = 0.5;
$config['redis']['read_timeout'] = 0.5;
$config['site']['fallback_prefix'] = 'src-page-content-test-' . bin2hex( random_bytes( 8 ) );

$probe = new Redis();
try {
	if ( ! $probe->connect( $host, $port, 0.5, null, 0, 0.5 ) ) {
		throw new RuntimeException( 'connect returned false' );
	}
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'SKIP: Redis is unavailable: ' . $throwable->getMessage() . "\n" );
	exit( 0 );
}

$versions_key  = Simple_Redis_Cache_Early_Config::page_content_versions_key( $config );
$generation_key = Simple_Redis_Cache_Early_Config::meta_key( 'page-generation', $config );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

try {
	$probe->del( array( $versions_key, $generation_key ) );

	$redis          = new Simple_Redis_Cache_Redis( $config );
	$post_10_before = $redis->page_content_version( 'post', 10 );
	$term_30_before = $redis->page_content_version( 'term', 30 );
	$assert( is_string( $post_10_before ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $post_10_before ), 'Missing post version was not initialized safely.' );
	$assert( is_string( $term_30_before ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $term_30_before ), 'Missing term version was not initialized safely.' );
	$assert( $post_10_before === ( new Simple_Redis_Cache_Redis( $config ) )->page_content_version( 'post', 10 ), 'Existing post version was not stable.' );

	$count = $redis->bump_page_content_versions(
		array(
			'post' => array( 10, 20, 10, 0, -1 ),
			'term' => array( 30, 40, 30, 0 ),
		)
	);
	$assert( 4 === $count, 'Unique positive post and term resources were not invalidated atomically.' );
	$post_10_after = ( new Simple_Redis_Cache_Redis( $config ) )->page_content_version( 'post', 10 );
	$post_20_after = ( new Simple_Redis_Cache_Redis( $config ) )->page_content_version( 'post', 20 );
	$term_30_after = ( new Simple_Redis_Cache_Redis( $config ) )->page_content_version( 'term', 30 );
	$term_40_after = ( new Simple_Redis_Cache_Redis( $config ) )->page_content_version( 'term', 40 );
	$assert( is_string( $post_10_after ) && $post_10_before !== $post_10_after, 'Updated post retained its previous content version.' );
	$assert( is_string( $post_20_after ), 'Translated post content version was not created.' );
	$assert( is_string( $term_30_after ) && $term_30_before !== $term_30_after, 'Updated taxonomy archive retained its previous content version.' );
	$assert( is_string( $term_40_after ), 'Related taxonomy archive content version was not created.' );

	// Losing the metadata hash must create a different token, never revive a
	// payload that was stored with the old token.
	$probe->del( $versions_key );
	$post_10_recovered = ( new Simple_Redis_Cache_Redis( $config ) )->page_content_version( 'post', 10 );
	$assert( is_string( $post_10_recovered ) && $post_10_after !== $post_10_recovered, 'Metadata eviction revived an old post content version.' );

	$redis = new Simple_Redis_Cache_Redis( $config );
	$assert( false !== $redis->bump_generation( 'page' ), 'Page generation bump failed.' );
	$assert( ! $probe->exists( $versions_key ), 'Global page invalidation did not reset the content-version hash.' );

	$probe->hSet( $versions_key, 'term:30', 'invalid' );
	$redis = new Simple_Redis_Cache_Redis( $config );
	$assert( false === $redis->page_content_version( 'term', 30 ), 'Malformed term content metadata was accepted.' );
	$assert( null !== $redis->error(), 'Malformed term content metadata did not expose a diagnostic error.' );

	echo "OK: post and taxonomy page versions initialize, invalidate, recover, and reset safely.\n";
} finally {
	$probe->del( array( $versions_key, $generation_key ) );
	$probe->close();
}
