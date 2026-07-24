<?php
/**
 * Focused Redis-generation regression test.
 *
 * Usage: php tests/generation-integration.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) );
define( 'WP_CACHE_KEY_SALT', 'src-generation-test-' . bin2hex( random_bytes( 8 ) ) );

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

$probe = new Redis();
try {
	if ( ! $probe->connect( $host, $port, 0.5, null, 0, 0.5 ) ) {
		throw new RuntimeException( 'connect returned false' );
	}
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'SKIP: Redis is unavailable: ' . $throwable->getMessage() . "\n" );
	exit( 0 );
}

$namespaces = array(
	'object',
	'page',
	'object-group-' . hash( 'sha256', 'test-group' ),
);
$keys = array_map(
	static fn( string $namespace ): string => Simple_Redis_Cache_Early_Config::meta_key( $namespace . '-generation', $config ),
	$namespaces
);

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$minimum_generation = PHP_INT_SIZE >= 8 ? 1099511627776 : 1048576;

try {
	$probe->del( $keys );

	foreach ( $namespaces as $index => $namespace ) {
		$redis = new Simple_Redis_Cache_Redis( $config );
		$first = $redis->generation( $namespace );
		$assert( null === $redis->error(), $namespace . ': initial generation failed' );
		$assert( $first >= $minimum_generation, $namespace . ': initial generation did not use the safe random range' );

		// Simulate eviction of persistent generation metadata while payloads remain.
		$probe->del( $keys[ $index ] );
		$redis  = new Simple_Redis_Cache_Redis( $config );
		$second = $redis->generation( $namespace );
		$assert( null === $redis->error(), $namespace . ': post-eviction generation failed' );
		$assert( $second >= $minimum_generation, $namespace . ': eviction recreated a low generation' );
		$assert( $second !== $first, $namespace . ': eviction recreated the previous generation' );

		$probe->del( $keys[ $index ] );
		$redis  = new Simple_Redis_Cache_Redis( $config );
		$bumped = $redis->bump_generation( $namespace );
		$assert( false !== $bumped, $namespace . ': missing-key bump failed' );
		$assert( $bumped > $minimum_generation, $namespace . ': missing-key bump recreated generation 1' );
	}

	// Existing integer generations remain compatible with Redis INCR.
	$probe->set( $keys[0], '41' );
	$redis = new Simple_Redis_Cache_Redis( $config );
	$assert( 42 === $redis->bump_generation( 'object' ), 'Existing generation was not incremented atomically.' );

	echo "OK: object, page, and group generations passed eviction and INCR checks.\n";
} finally {
	$probe->del( $keys );
	$probe->close();
}
