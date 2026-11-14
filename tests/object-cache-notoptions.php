<?php
/**
 * Core's notoptions list must not bring back options another request created
 * meanwhile (#11418). A long request reads the list early; another request
 * creates `foo` and removes it from the list; when the long request later
 * writes its own copy, a plain SET would put `foo => true` back for every
 * request until the entry expires, and get_option( 'foo' ) would return the
 * default although the row exists.
 *
 * The object cache writes notoptions as a three-way merge instead: the list
 * Redis holds now, plus what this request added since it last synchronized the
 * list, minus what it removed, under WATCH. This covers the race, wp-admin with
 * cache_wp_admin off (which never reads the list), a write that loses the WATCH
 * once or keeps losing it, and an OOM reply.
 *
 * Each WP_Object_Cache below stands for one request. Only keys under this run's
 * random WP_CACHE_KEY_SALT are touched.
 *
 * Usage: php tests/object-cache-notoptions.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$test_content = sys_get_temp_dir() . '/simple-redis-cache-notoptions-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $test_content, 0700 ) && ! is_dir( $test_content ) ) {
	throw new RuntimeException( 'Could not create the notoptions test directory.' );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'WP_CACHE_KEY_SALT', 'src-notoptions-test-' . bin2hex( random_bytes( 8 ) ) );
// Every request below runs in wp-admin; cache_wp_admin decides what it may read.
define( 'WP_ADMIN', true );

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

if ( ! extension_loaded( 'redis' ) || version_compare( (string) phpversion( 'redis' ), '6.0.0', '<' ) ) {
	$remove_test_content();
	fwrite( STDERR, "SKIP: PhpRedis 6 or newer is not installed.\n" );
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

/** A connection that lets another request write just before its MULTI, or answers SET with an error reply. */
final class SRC_Test_Notoptions_Redis extends Redis {
	/** @var (callable(): void)|null */
	public $before_multi = null;
	public int $interruptions = 0;
	public ?string $set_reply = null;

	public function multi( int $value = Redis::MULTI ): Redis|bool {
		if ( Redis::PIPELINE !== $value && null !== $this->before_multi && $this->interruptions > 0 ) {
			--$this->interruptions;
			( $this->before_multi )();
		}
		return parent::multi( $value );
	}

	public function set( string $key, mixed $value, mixed $options = null ): Redis|string|bool {
		if ( null !== $this->set_reply ) {
			return parent::eval( 'return redis.error_reply(ARGV[1])', array( $this->set_reply ), 0 );
		}
		return parent::set( $key, $value, $options );
	}
}

$front                             = Simple_Redis_Cache_Early_Config::defaults();
$front['redis']['host']            = $host;
$front['redis']['port']            = $port;
$front['redis']['timeout']         = 0.5;
$front['redis']['read_timeout']    = 0.5;
$front['object']['enabled']        = true;
$front['object']['max_ttl']        = 300;
$front['object']['cache_wp_admin'] = true;
$admin                             = $front;
$admin['object']['cache_wp_admin'] = false;

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$early_config = new ReflectionProperty( Simple_Redis_Cache_Early_Config::class, 'config' );
$cache_class  = new ReflectionClass( WP_Object_Cache::class );
$redis_class  = new ReflectionClass( Simple_Redis_Cache_Redis::class );

/** One request's object cache, built by the real constructor from $config. */
$request = static function ( array $config ) use ( $early_config ): WP_Object_Cache {
	Simple_Redis_Cache_Early_Config::reset();
	$early_config->setValue( null, $config );
	return new WP_Object_Cache();
};

/** @return array{0: WP_Object_Cache, 1: SRC_Test_Notoptions_Redis} */
$hooked = static function ( array $config ) use ( $request, $cache_class, $redis_class, $host, $port ): array {
	$cache  = $request( $config );
	$client = new SRC_Test_Notoptions_Redis();
	$client->connect( $host, $port, 0.5, null, 0, 0.5 );
	$wrapper = $cache_class->getProperty( 'redis' )->getValue( $cache );
	$redis_class->getProperty( 'client' )->setValue( $wrapper, $client );
	$redis_class->getProperty( 'attempted' )->setValue( $wrapper, true );
	return array( $cache, $client );
};

/** What core's option functions do with the list, against one request's cache. */
$remember_missing = static function ( WP_Object_Cache $cache, string $option ): void {
	$notoptions = $cache->get( 'notoptions', 'options' );
	if ( ! is_array( $notoptions ) ) {
		$notoptions = array();
	}
	$notoptions[ $option ] = true;
	$cache->set( 'notoptions', $notoptions, 'options' );
};
$forget_missing = static function ( WP_Object_Cache $cache, string $option ): void {
	$notoptions = $cache->get( 'notoptions', 'options' );
	if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
		unset( $notoptions[ $option ] );
		$cache->set( 'notoptions', $notoptions, 'options' );
	}
};
/** The list as a new frontend request sees it, sorted. */
$shared = static function () use ( $request, $front ): mixed {
	$list = $request( $front )->get( 'notoptions', 'options' );
	if ( is_array( $list ) ) {
		ksort( $list );
	}
	return $list;
};
$sorted = static function ( mixed $list ): mixed {
	if ( is_array( $list ) ) {
		ksort( $list );
	}
	return $list;
};

$marker  = WP_CONTENT_DIR . '/simple-redis-cache-object-dirty';
$breaker = Simple_Redis_Cache_Redis::breaker_path();
$exists  = static function ( string $path ): bool {
	clearstatcache( true, $path );
	return is_file( $path );
};

try {
	/* 1. The race: a long request writes back a list read before another request created foo. */
	$request( $front )->set( 'notoptions', array( 'foo' => true, 'old' => true ), 'options' );

	$long = $request( $front );
	$assert( array( 'foo' => true, 'old' => true ) === $long->get( 'notoptions', 'options' ), 'The long request did not read the seeded list.' );

	$forget_missing( $request( $front ), 'foo' ); // add_option( 'foo' ) in another request.
	$assert( array( 'old' => true ) === $shared(), 'The other request did not remove foo from the list.' );

	$remember_missing( $long, 'bar' ); // get_option( 'bar' ) misses in the long request.
	$assert( array( 'bar' => true, 'old' => true ) === $shared(), 'The long request brought foo back into the shared list: ' . var_export( $shared(), true ) );
	$assert( array( 'bar' => true, 'old' => true ) === $sorted( $long->get( 'notoptions', 'options' ) ), 'The long request kept a list in L1 other than the one it wrote.' );

	/* 2. Its removals still apply, and a concurrent addition survives them. */
	$remember_missing( $request( $front ), 'baz' );
	$forget_missing( $long, 'old' ); // The long request creates "old"; its L1 copy has no baz.
	$assert( array( 'bar' => true, 'baz' => true ) === $shared(), 'A removal lost a concurrent addition or did not apply: ' . var_export( $shared(), true ) );

	/* 3. wp-admin with cache_wp_admin off never reads the list, yet must neither wipe it nor resurrect entries. */
	$admin_request = $request( $admin );
	$remember_missing( $admin_request, 'qux' );
	$assert( array( 'bar' => true, 'baz' => true, 'qux' => true ) === $shared(), 'A wp-admin request overwrote the shared list with its own: ' . var_export( $shared(), true ) );
	$assert( array( 'qux' => true ) === $admin_request->get( 'notoptions', 'options' ), 'A wp-admin request that may not read Redis took the shared list into L1.' );
	$forget_missing( $admin_request, 'qux' ); // add_option( 'qux' ) in wp-admin.
	$assert( array( 'bar' => true, 'baz' => true ) === $shared(), 'add_option() in wp-admin did not remove its entry, or removed others: ' . var_export( $shared(), true ) );

	/* 4. A write that loses the WATCH once merges again with what won. */
	[ $contended, $client ] = $hooked( $front );
	$contended->get( 'notoptions', 'options' );
	$client->interruptions = 1;
	$client->before_multi  = static function () use ( $request, $front, $remember_missing ): void {
		$remember_missing( $request( $front ), 'winner' );
	};
	$remember_missing( $contended, 'loser' );
	$assert( 0 === $client->interruptions, 'The concurrent write never ran inside the WATCH window.' );
	$assert( array( 'bar' => true, 'baz' => true, 'loser' => true, 'winner' => true ) === $shared(), 'A write that lost the WATCH dropped the winner or its own change: ' . var_export( $shared(), true ) );
	$assert( array() === $contended->errors && ! $exists( $marker ), 'A WATCH conflict was treated as a failure.' );

	/* 5. One that keeps losing drops the shared list rather than guess, and keeps its own. */
	[ $starved, $client ] = $hooked( $front );
	$starved->get( 'notoptions', 'options' );
	$client->interruptions = 100;
	$client->before_multi  = static function () use ( $request, $front, $remember_missing ): void {
		$remember_missing( $request( $front ), 'busy-' . bin2hex( random_bytes( 4 ) ) );
	};
	$remember_missing( $starved, 'starved' );
	$client->before_multi = null;
	$assert( 95 === $client->interruptions, 'The notoptions write did not give up after five WATCH conflicts: ' . ( 100 - $client->interruptions ) . ' attempts.' );
	$assert( false === $shared(), 'A write that kept losing the WATCH left a list behind: ' . var_export( $shared(), true ) );
	$assert( true === ( $starved->get( 'notoptions', 'options' )['starved'] ?? null ), 'A write that kept losing the WATCH lost its own L1 list.' );
	$assert( ! $exists( $marker ), 'Dropping the contended list marked the cache dirty.' );

	/* 6. An OOM reply: the shared list is deleted instead, so nothing is left to invalidate. */
	$remember_missing( $request( $front ), 'before-oom' );
	[ $refused, $client ] = $hooked( $front );
	$refused->get( 'notoptions', 'options' );
	$client->set_reply = "OOM command not allowed when used memory > 'maxmemory'.";
	$remember_missing( $refused, 'during-oom' );
	$client->set_reply = null;
	$assert( array() !== $refused->errors, 'The OOM reply was not recorded.' );
	$assert( ! $exists( $breaker ), 'The OOM reply tripped the breaker.' );
	$assert( ! $exists( $marker ), 'A refused notoptions write whose shared copy was deleted marked the cache dirty.' );
	$assert( false === $shared(), 'A refused notoptions write left the old shared list behind: ' . var_export( $shared(), true ) );

	/* 7. A list that is not an array is written as it is, and the next one starts afresh. */
	$odd = $request( $front );
	$odd->set( 'notoptions', 'not-a-list', 'options' );
	$assert( 'not-a-list' === $shared(), 'A non-array notoptions value was not stored as it is.' );
	$remember_missing( $odd, 'after-odd' );
	$assert( array( 'after-odd' => true ) === $shared(), 'The list written after a non-array value was wrong: ' . var_export( $shared(), true ) );

	/*
	 * 8. A request that dropped its copy (flush_runtime(), delete()) no longer
	 * knows the entries it read, so a later write must not remove them.
	 */
	$flushed = $request( $front );
	$flushed->get( 'notoptions', 'options' ); // after-odd
	$flushed->flush_runtime();
	$flushed->set( 'notoptions', array( 'post-flush' => true ), 'options' );
	$assert( array( 'after-odd' => true, 'post-flush' => true ) === $shared(), 'After flush_runtime(), a write removed entries the request no longer had: ' . var_export( $shared(), true ) );

	$deleted = $request( $front );
	$deleted->get( 'notoptions', 'options' );
	$deleted->delete( 'notoptions', 'options' );
	$request( $front )->set( 'notoptions', array( 'after-odd' => true ), 'options' ); // Another request rebuilds it.
	$deleted->set( 'notoptions', array( 'fresh' => true ), 'options' );
	$assert( array( 'after-odd' => true, 'fresh' => true ) === $shared(), 'After delete(), a write removed entries the request had deleted with the list: ' . var_export( $shared(), true ) );

	// An empty list is a reset (a plugin inserted options with SQL): it clears the
	// shared list even when this request never read it.
	$request( $front )->set( 'notoptions', array( 'inserted_via_sql' => true, 'other' => true ), 'options' );
	$resetter = $request( $front );
	$resetter->get( 'unrelated', 'posts' );
	$resetter->set( 'notoptions', array(), 'options' );
	$assert( in_array( $shared(), array( false, array() ), true ), 'An empty notoptions write did not reset the shared list: ' . var_export( $shared(), true ) );
	$assert( array() === $resetter->get( 'notoptions', 'options' ), 'The resetting request does not see an empty list itself.' );
	$assert( ! $exists( $marker ), 'Resetting notoptions left the dirty marker.' );
} finally {
	// Only this run's keys: everything lives under the random WP_CACHE_KEY_SALT.
	$prefix   = Simple_Redis_Cache_Early_Config::prefix( $front );
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

echo "OK: notoptions is written as a three-way merge, so a stale list never brings back an option another request created.\n";
