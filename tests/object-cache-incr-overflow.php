<?php
/**
 * incr()/decr() near the integer limits (#8518). A counter that would pass
 * PHP_INT_MAX used to become a float in Redis while the call cast it to int:
 * PHP_INT_MIN on every version, plus "not representable as an int" warnings
 * on PHP 8.5. Now a counter saturates at PHP_INT_MAX and never goes below
 * zero, and what Redis holds, what L1 holds and what the call returns are the
 * same integer, with no warning, both through Redis and through L1 only.
 *
 * Each WP_Object_Cache below stands for one request. Only keys under this run's
 * random WP_CACHE_KEY_SALT are touched.
 *
 * Usage: php tests/object-cache-incr-overflow.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$test_content = sys_get_temp_dir() . '/simple-redis-cache-incr-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $test_content, 0700 ) && ! is_dir( $test_content ) ) {
	throw new RuntimeException( 'Could not create the incr test directory.' );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'WP_CACHE_KEY_SALT', 'src-incr-test-' . bin2hex( random_bytes( 8 ) ) );

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-redis.php';
require_once dirname( __DIR__ ) . '/dropins/class-simple-redis-cache-object-cache.php';

$remove_test_content = static function () use ( $test_content ): void {
	if ( ! is_dir( $test_content ) ) {
		return;
	}
	foreach ( scandir( $test_content ) ?: array() as $name ) {
		if ( is_file( $test_content . '/' . $name ) ) {
			unlink( $test_content . '/' . $name );
		}
	}
	rmdir( $test_content );
};

if ( ! extension_loaded( 'redis' ) ) {
	$remove_test_content();
	fwrite( STDERR, "SKIP: PhpRedis is not installed.\n" );
	exit( 0 );
}

$host  = getenv( 'SRC_TEST_REDIS_HOST' ) ?: '127.0.0.1';
$port  = (int) ( getenv( 'SRC_TEST_REDIS_PORT' ) ?: 6379 );
$probe = new Redis();
try {
	if ( ! $probe->connect( $host, $port, 0.5, null, 0, 0.5 ) ) {
		throw new RuntimeException( 'connect returned false' );
	}
} catch ( Throwable $throwable ) {
	$remove_test_content();
	fwrite( STDERR, 'SKIP: Redis is unavailable: ' . $throwable->getMessage() . "\n" );
	exit( 0 );
}

$config                            = Simple_Redis_Cache_Early_Config::defaults();
$config['redis']['host']           = $host;
$config['redis']['port']           = $port;
$config['redis']['timeout']        = 0.5;
$config['redis']['read_timeout']   = 0.5;
$config['object']['enabled']       = true;
$config['object']['max_ttl']       = 300;

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$early_config = new ReflectionProperty( Simple_Redis_Cache_Early_Config::class, 'config' );
$request      = static function () use ( $early_config, $config ): WP_Object_Cache {
	Simple_Redis_Cache_Early_Config::reset();
	$early_config->setValue( null, $config );
	$cache = new WP_Object_Cache();
	$cache->add_non_persistent_groups( array( 'request-only' ) );
	return $cache;
};

/** The payload Redis holds for a key, decoded, or a string saying why there is none. */
$stored = static function ( string $key, string $group ) use ( $probe, $config ): mixed {
	$prefix            = Simple_Redis_Cache_Early_Config::prefix( $config );
	$object_generation = $probe->get( Simple_Redis_Cache_Early_Config::meta_key( 'object-generation', $config ) );
	$group_generation  = $probe->get( Simple_Redis_Cache_Early_Config::meta_key( 'object-group-' . hash( 'sha256', $group ) . '-generation', $config ) );
	$payload           = $probe->get( $prefix . 'object:' . $object_generation . ':' . $group_generation . ':' . hash( 'sha256', $group . "\0" . $key ) );
	if ( ! is_string( $payload ) || ! str_starts_with( $payload, "SRC1\0" ) ) {
		return 'no payload';
	}
	return unserialize( substr( $payload, 5 ) );
};

$warnings = array();
set_error_handler(
	static function ( int $severity, string $message ) use ( &$warnings ): bool {
		$warnings[] = $message;
		return true;
	}
);

try {
	foreach ( array( 'posts', 'request-only' ) as $group ) {
		$persistent = 'posts' === $group;
		$label      = $persistent ? 'Through Redis' : 'In L1 only';

		$cases = array(
			// name => [ stored value, method, offset, expected ]
			'incr past the limit'             => array( PHP_INT_MAX - 1, 'incr', 5, PHP_INT_MAX ),
			'incr at the limit'               => array( PHP_INT_MAX, 'incr', 1, PHP_INT_MAX ),
			'incr by a float offset'          => array( 1, 'incr', 1e20, PHP_INT_MAX ),
			'incr of a float left by 0.9.0'   => array( (float) PHP_INT_MAX + 1024.0, 'incr', 1, PHP_INT_MAX ),
			'incr of a huge numeric string'   => array( '99999999999999999999', 'incr', 1, PHP_INT_MAX ),
			'incr of NAN'                     => array( NAN, 'incr', 3, 3 ),
			'incr of a non-number'            => array( 'abc', 'incr', 2, 2 ),
			'decr below zero'                 => array( 3, 'decr', 10, 0 ),
			'decr from the limit'             => array( PHP_INT_MAX, 'decr', 1, PHP_INT_MAX - 1 ),
			'decr by PHP_INT_MIN'             => array( 5, 'decr', PHP_INT_MIN, PHP_INT_MAX ),
			'incr by PHP_INT_MIN'             => array( 5, 'incr', PHP_INT_MIN, 0 ),
			'incr of an ordinary counter'     => array( 41, 'incr', 1, 42 ),
			'decr of a numeric string'        => array( '10', 'decr', 4, 6 ),
		);

		foreach ( $cases as $name => [ $initial, $method, $offset, $expected ] ) {
			$key   = 'counter-' . md5( $name );
			$cache = $request();
			$cache->set( $key, $initial, $group );
			$warnings = array();
			$result   = $cache->$method( $key, $offset, $group );
			$assert( array() === $warnings, $label . ', ' . $name . ': ' . implode( ' | ', $warnings ) );
			$assert( $expected === $result, $label . ', ' . $name . ' returned ' . var_export( $result, true ) . ', not ' . var_export( $expected, true ) . '.' );
			$assert( $expected === $cache->get( $key, $group ), $label . ', ' . $name . ': L1 holds ' . var_export( $cache->get( $key, $group ), true ) . '.' );
			if ( $persistent ) {
				$assert( $expected === $stored( $key, $group ), $label . ', ' . $name . ': Redis holds ' . var_export( $stored( $key, $group ), true ) . '.' );
				$assert( $expected === $request()->get( $key, $group ), $label . ', ' . $name . ': a later request reads ' . var_export( $request()->get( $key, $group ), true ) . '.' );
			}
		}
	}
} finally {
	restore_error_handler();
	// Only this run's keys: everything lives under the random WP_CACHE_KEY_SALT.
	$prefix   = Simple_Redis_Cache_Early_Config::prefix( $config );
	$iterator = null;
	do {
		$keys = $probe->scan( $iterator, $prefix . '*', 1000 );
		if ( is_array( $keys ) && array() !== $keys ) {
			$probe->del( $keys );
		}
	} while ( $iterator > 0 );
	$probe->close();
	$remove_test_content();
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: incr()/decr() saturate at PHP_INT_MAX and zero, store what they return, and never warn.\n";
