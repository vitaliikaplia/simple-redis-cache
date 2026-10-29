<?php
/**
 * Object-cache entries that must be served from L1:
 *
 * 1. with cache_wp_admin off, add() keeps the value in L1 even when Redis
 *    refuses SET NX, so get_option() in wp-admin stops rereading the database
 *    (#11417), yet still reports the refusal and leaves the database mirror
 *    alone, so wp_cache_add() keeps working as a lock;
 * 2. the plugin's own settings, which hold the Redis password and the
 *    Cloudflare token, never reach Redis (#11420); the database mirror covers
 *    transient groups only, so it never sees them either;
 * 3. the $errors diagnostics list stays distinct and bounded (#11421);
 * 4. a transient that can never be serialized drops its old mirror row.
 *
 * Each WP_Object_Cache below stands for one request. Only keys under this run's
 * random WP_CACHE_KEY_SALT are touched.
 *
 * Usage: php tests/object-cache-l1.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

$test_content = sys_get_temp_dir() . '/simple-redis-cache-l1-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $test_content, 0700 ) && ! is_dir( $test_content ) ) {
	throw new RuntimeException( 'Could not create the L1 test directory.' );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'WP_CACHE_KEY_SALT', 'src-l1-test-' . bin2hex( random_bytes( 8 ) ) );
// Every request below runs in wp-admin; cache_wp_admin decides what it may read.
define( 'WP_ADMIN', true );

/* Options-table stubs that record every call the object cache makes. */
$GLOBALS['src_test_option_calls'] = array();

function get_option( string $name, mixed $default_value = false ): mixed {
	$GLOBALS['src_test_option_calls'][] = 'get_option:' . $name;
	return $default_value;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	unset( $value, $autoload );
	$GLOBALS['src_test_option_calls'][] = 'update_option:' . $name;
	return true;
}

function delete_option( string $name ): bool {
	$GLOBALS['src_test_option_calls'][] = 'delete_option:' . $name;
	return false;
}

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

$front                                    = Simple_Redis_Cache_Early_Config::defaults();
$front['redis']['host']                   = $host;
$front['redis']['port']                   = $port;
$front['redis']['timeout']                = 0.5;
$front['redis']['read_timeout']           = 0.5;
$front['object']['enabled']               = true;
$front['object']['max_ttl']               = 300;
$front['object']['cache_wp_admin']        = true;
$front['object']['transient_db_fallback'] = true;
$admin                                    = $front;
$admin['object']['cache_wp_admin']        = false;

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$early_config = new ReflectionProperty( Simple_Redis_Cache_Early_Config::class, 'config' );
$cache_class  = new ReflectionClass( WP_Object_Cache::class );

/** One request's object cache, built by the real constructor from $config. */
$request = static function ( array $config ) use ( $early_config ): WP_Object_Cache {
	Simple_Redis_Cache_Early_Config::reset();
	$early_config->setValue( null, $config );
	return new WP_Object_Cache();
};

/** @return array<string, string> Every payload stored under this run's prefix. */
$stored_payloads = static function () use ( $probe, $front ): array {
	$payloads = array();
	$iterator = null;
	do {
		$keys = $probe->scan( $iterator, Simple_Redis_Cache_Early_Config::prefix( $front ) . '*', 1000 );
		foreach ( is_array( $keys ) ? $keys : array() as $key ) {
			$payloads[ $key ] = (string) $probe->get( $key );
		}
	} while ( $iterator > 0 );
	return $payloads;
};

try {
	/* 1. wp-admin with cache_wp_admin off, while the frontend has filled Redis. */
	$alloptions = array( 'siteurl' => 'https://example.test', 'blogname' => 'Example' );
	$assert( true === $request( $front )->add( 'alloptions', $alloptions, 'options' ), 'The frontend could not seed alloptions.' );

	// wp_load_alloptions(): read the cache, fall back to the DB, then wp_cache_add().
	$wp_admin = $request( $admin );
	$db_reads = 0;
	for ( $call = 0; $call < 5; ++$call ) {
		if ( false === $wp_admin->get( 'alloptions', 'options' ) ) {
			++$db_reads;
			$wp_admin->add( 'alloptions', array( 'siteurl' => 'https://example.test', 'blogname' => 'From DB' ), 'options' );
		}
	}
	$assert( 1 === $db_reads, 'With cache_wp_admin off, ' . $db_reads . ' of 5 get_option() calls reread alloptions from the database.' );
	$assert( 'From DB' === ( $wp_admin->get( 'alloptions', 'options' )['blogname'] ?? null ), 'wp-admin did not keep its own database copy in L1.' );

	$second = $request( $admin );
	$assert( false === $second->add( 'alloptions', array( 'blogname' => 'B' ), 'options' ), 'add() hid the NX refusal in a request that cannot read Redis, so it cannot serve as a lock.' );
	$assert( 'B' === ( $second->get( 'alloptions', 'options' )['blogname'] ?? null ), 'The refused add() did not keep the caller\'s copy in L1.' );
	$assert( false === $second->add( 'alloptions', array( 'blogname' => 'C' ), 'options' ), 'add() overwrote a value already in this request\'s L1.' );
	$assert( 'B' === ( $second->get( 'alloptions', 'options' )['blogname'] ?? null ), 'A second add() replaced the L1 copy.' );
	$closure = $request( $admin );
	$assert( false === $closure->add( 'closure', static function (): void {}, 'options' ), 'add() accepted a value that can never be serialized.' );
	$assert( false === $closure->get( 'closure', 'options' ), 'add() kept a value that can never be serialized in L1.' );

	// The same refusal for a mirrored transient must not write the database mirror.
	$assert( true === $request( $front )->add( 'lock', 'frontend', 'transient' ), 'The frontend could not take the transient lock.' );
	$GLOBALS['src_test_option_calls'] = array();
	$locked = $request( $admin );
	$assert( false === $locked->add( 'lock', 'admin', 'transient' ), 'wp-admin took a transient lock the frontend holds in Redis.' );
	$assert( 'admin' === $locked->get( 'lock', 'transient' ), 'The refused transient add() did not keep the caller\'s copy in L1.' );
	$mirror_writes = preg_grep( '/^update_option:/', $GLOBALS['src_test_option_calls'] ) ?: array();
	$assert( array() === $mirror_writes, 'A refused add() wrote the database mirror: ' . implode( ', ', $mirror_writes ) );

	// The Redis copy stays the frontend's, and the frontend keeps strict NX semantics.
	$assert( $alloptions === $request( $front )->get( 'alloptions', 'options' ), 'wp-admin overwrote the Redis copy the frontend wrote.' );
	$strict = $request( $front );
	$assert( false === $strict->add( 'alloptions', array( 'blogname' => 'D' ), 'options' ), 'add() succeeded against an existing Redis value in a request that can read it.' );
	$assert( $alloptions === $strict->get( 'alloptions', 'options' ), 'A refused frontend add() replaced the Redis value in L1.' );

	/* 2. The plugin's own settings never leave L1. */
	$secret   = array(
		'redis'      => array( 'password' => 'redis-pw-' . bin2hex( random_bytes( 8 ) ) ),
		'cloudflare' => array( 'api_token' => 'cf-token-' . bin2hex( random_bytes( 8 ) ) ),
	);
	$replaced = $secret;
	$replaced['cloudflare']['api_token'] = 'cf-token-' . bin2hex( random_bytes( 8 ) );

	$settings = $request( $front );
	$assert( true === $settings->add( 'simple_redis_cache_settings', $secret, 'options' ), 'add() of the plugin settings failed.' );
	$assert( $secret === $settings->get( 'simple_redis_cache_settings', 'options' ), 'The plugin settings were not kept in L1.' );
	$assert( true === $settings->set( 'simple_redis_cache_settings', $secret, 'options' ), 'set() of the plugin settings failed.' );
	$assert( true === $settings->replace( 'simple_redis_cache_settings', $replaced, 'options' ), 'replace() of the plugin settings failed.' );
	$assert( $replaced === $settings->get( 'simple_redis_cache_settings', 'options', true ), 'A forced read of the plugin settings did not come from L1.' );
	$settings->set( 'control_option', 'reaches-redis', 'options' );

	$payloads = $stored_payloads();
	$assert( array() !== array_filter( $payloads, static fn ( string $payload ): bool => str_contains( $payload, 'reaches-redis' ) ), 'The control option never reached Redis, so this check proves nothing.' );
	foreach ( $payloads as $payload ) {
		foreach ( array( $secret['redis']['password'], $secret['cloudflare']['api_token'], $replaced['cloudflare']['api_token'] ) as $value ) {
			$assert( ! str_contains( $payload, $value ), 'A plugin secret was written to Redis.' );
		}
	}

	$found = null;
	$assert( false === $request( $front )->get( 'simple_redis_cache_settings', 'options', false, $found ) && false === $found, 'Another request found the plugin settings in Redis.' );
	$assert( 'reaches-redis' === $request( $front )->get( 'control_option', 'options' ), 'Ordinary options stopped reaching Redis.' );
	$assert( true === $settings->delete( 'simple_redis_cache_settings', 'options' ), 'delete() of the plugin settings failed.' );
	$assert( false === $settings->get( 'simple_redis_cache_settings', 'options' ), 'The deleted plugin settings were still served.' );
	$assert( false === $settings->replace( 'simple_redis_cache_settings', $secret, 'options' ), 'replace() succeeded for plugin settings that were not cached.' );

	/* 3. $errors stays distinct and bounded in a long-running process. */
	$noisy = $request( $front );
	for ( $write = 0; $write < 50; ++$write ) {
		$noisy->set( 'closure-' . $write, static function (): void {}, 'posts' );
	}
	$assert( 1 === count( $noisy->errors ), 'The same serialization error was recorded ' . count( $noisy->errors ) . ' times.' );

	$record = $cache_class->getMethod( 'record_redis_error' );
	for ( $error = 0; $error < 500; ++$error ) {
		$record->invoke( $noisy, 'Simulated Redis error #' . $error );
	}
	$assert( count( $noisy->errors ) <= 20, '$errors grew to ' . count( $noisy->errors ) . ' entries.' );
	$assert( in_array( 'Simulated Redis error #499', $noisy->errors, true ), '$errors dropped the most recent error.' );

	/* 4. The mirror refuses an unserializable transient, so its old row must go. */
	$GLOBALS['src_test_option_calls'] = array();
	$request( $front )->set( 'callback', static function (): void {}, 'transient' );
	$calls = $GLOBALS['src_test_option_calls'];
	$assert( in_array( 'delete_option:_transient_callback', $calls, true ), 'set() of an unserializable transient left its previous database row: ' . implode( ', ', $calls ) );
	$assert( array() === ( preg_grep( '/^update_option:/', $calls ) ?: array() ), 'set() of an unserializable transient wrote the database mirror.' );
} finally {
	// Only this run's keys: everything lives under the random WP_CACHE_KEY_SALT.
	$keys = array_keys( $stored_payloads() );
	if ( array() !== $keys ) {
		$probe->del( $keys );
	}
	$probe->close();
	$remove_test_content();
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: wp-admin keeps refused add() values in L1 but reports the refusal, plugin secrets never reach Redis, \$errors stays bounded, and unserializable transients drop their mirror row.\n";
