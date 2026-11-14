<?php
/**
 * The *_multiple object-cache calls batch their Redis commands (#11419):
 *
 * 1. round trips: get_multiple() of 100 hits and 100 misses in a fresh request
 *    costs two (generations, then one pipeline), add/set/delete_multiple() one
 *    each, a refused add_multiple() two, a single get() hit one;
 * 2. every key gets exactly what the matching single call gives it: answers,
 *    L1, Redis, the database mirror, wp-admin with cache_wp_admin off, the
 *    plugin's own settings, invalid and repeated keys;
 * 3. failures inside a batch keep every rule of the single calls: an error
 *    reply fails the request without tripping the breaker and, when the DEL of
 *    the refused keys works, leaves no dirty marker; a lost connection trips
 *    the breaker and marks the cache dirty; a lost add_multiple() fails open
 *    and marks nothing; Redis is not used again in that request.
 *
 * Each WP_Object_Cache below stands for one request. Round trips are counted
 * by a PhpRedis subclass, which can also fail on demand: an error reply is a
 * real one it asks the server for, a dropped connection an exception it throws.
 * Only keys under this run's random WP_CACHE_KEY_SALT are touched.
 *
 * Usage: php tests/object-cache-batching.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$test_content = sys_get_temp_dir() . '/simple-redis-cache-batch-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $test_content, 0700 ) && ! is_dir( $test_content ) ) {
	throw new RuntimeException( 'Could not create the batching test directory.' );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'WP_CACHE_KEY_SALT', 'src-batch-test-' . bin2hex( random_bytes( 8 ) ) );
// Every request below runs in wp-admin; cache_wp_admin decides what it may read.
define( 'WP_ADMIN', true );

/* An in-memory options table standing in for the database mirror. */
$GLOBALS['src_test_options']      = array();
$GLOBALS['src_test_doing_wrong']  = 0;
$GLOBALS['src_test_suspend_adds'] = false;

function get_option( string $name, mixed $default_value = false ): mixed {
	return array_key_exists( $name, $GLOBALS['src_test_options'] ) ? $GLOBALS['src_test_options'][ $name ] : $default_value;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['src_test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	$existed = array_key_exists( $name, $GLOBALS['src_test_options'] );
	unset( $GLOBALS['src_test_options'][ $name ] );
	return $existed;
}

function _doing_it_wrong( string $function_name, string $message, string $version ): void {
	unset( $function_name, $message, $version );
	++$GLOBALS['src_test_doing_wrong'];
}

function wp_suspend_cache_addition(): bool {
	return $GLOBALS['src_test_suspend_adds'];
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

/**
 * Counts round trips (a pipeline is one, sent on exec()) and fails on demand.
 * Keys are matched by the digest that ends every object-cache storage key.
 */
final class SRC_Test_Batch_Redis extends Redis {
	public int $round_trips = 0;
	/** @var array<string, string> Digest => error reply the server sends for that key's SET. */
	public array $set_replies = array();
	/** Error reply the server sends for every DEL, or null. */
	public ?string $del_reply = null;
	/** @var array<string, true> Digest => the next SET NX answers "refused" although the key is absent. */
	public array $phantom_refusals = array();
	/** Commands still allowed before the connection "drops"; null: it never does. */
	public ?int $drop_after = null;
	private bool $piped = false;

	public static function digest( string $group, string $key ): string {
		return hash( 'sha256', $group . "\0" . $key );
	}

	private function sent(): void {
		if ( null !== $this->drop_after ) {
			if ( $this->drop_after <= 0 ) {
				throw new RedisException( 'read error on connection to 127.0.0.1:6379' );
			}
			--$this->drop_after;
		}
		if ( ! $this->piped ) {
			++$this->round_trips;
		}
	}

	private function error_reply( string $message ): mixed {
		return parent::eval( 'return redis.error_reply(ARGV[1])', array( $message ), 0 );
	}

	public function get( string $key ): mixed {
		$this->sent();
		return parent::get( $key );
	}

	public function mget( array $keys ): Redis|array|false {
		$this->sent();
		return parent::mget( $keys );
	}

	public function set( string $key, mixed $value, mixed $options = null ): Redis|string|bool {
		$this->sent();
		$digest = substr( $key, -64 );
		if ( isset( $this->set_replies[ $digest ] ) ) {
			return $this->error_reply( $this->set_replies[ $digest ] );
		}
		if ( isset( $this->phantom_refusals[ $digest ] ) ) {
			unset( $this->phantom_refusals[ $digest ] );
			// A nil reply, exactly what a refused SET NX sends.
			return parent::eval( 'return false', array(), 0 );
		}
		return parent::set( $key, $value, $options );
	}

	public function del( array|string $key, string ...$other_keys ): Redis|int|false {
		$this->sent();
		if ( null !== $this->del_reply ) {
			return $this->error_reply( $this->del_reply );
		}
		return parent::del( $key, ...$other_keys );
	}

	public function exists( mixed $key, mixed ...$other_keys ): Redis|int|bool {
		$this->sent();
		return parent::exists( $key, ...$other_keys );
	}

	public function ttl( string $key ): Redis|int|false {
		$this->sent();
		return parent::ttl( $key );
	}

	public function eval( string $script, array $args = array(), int $num_keys = 0 ): mixed {
		$this->sent();
		return parent::eval( $script, $args, $num_keys );
	}

	public function watch( array|string $key, string ...$other_keys ): Redis|bool {
		$this->sent();
		return parent::watch( $key, ...$other_keys );
	}

	public function unwatch(): Redis|bool {
		$this->sent();
		return parent::unwatch();
	}

	public function multi( int $value = Redis::MULTI ): Redis|bool {
		if ( Redis::PIPELINE === $value ) {
			$this->piped = true;
		} else {
			// PhpRedis sends MULTI and every command queued after it at once.
			$this->sent();
		}
		return parent::multi( $value );
	}

	public function exec(): Redis|array|false {
		$this->piped = false;
		++$this->round_trips;
		return parent::exec();
	}

	public function discard(): Redis|bool {
		if ( $this->piped ) {
			$this->piped = false;
		} else {
			$this->sent();
		}
		return parent::discard();
	}
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
$redis_class  = new ReflectionClass( Simple_Redis_Cache_Redis::class );

/** One request's object cache, built by the real constructor from $config. */
$request = static function ( array $config ) use ( $early_config ): WP_Object_Cache {
	Simple_Redis_Cache_Early_Config::reset();
	$early_config->setValue( null, $config );
	return new WP_Object_Cache();
};

/** @return array{0: WP_Object_Cache, 1: SRC_Test_Batch_Redis} A request whose connection is the counting client. */
$counted = static function ( array $config ) use ( $request, $cache_class, $redis_class, $host, $port ): array {
	$cache  = $request( $config );
	$client = new SRC_Test_Batch_Redis();
	$client->connect( $host, $port, 0.5, null, 0, 0.5 );
	$wrapper = $cache_class->getProperty( 'redis' )->getValue( $cache );
	$redis_class->getProperty( 'client' )->setValue( $wrapper, $client );
	$redis_class->getProperty( 'attempted' )->setValue( $wrapper, true );
	return array( $cache, $client );
};

$marker  = WP_CONTENT_DIR . '/simple-redis-cache-object-dirty';
$breaker = Simple_Redis_Cache_Redis::breaker_path();
$exists  = static function ( string $path ): bool {
	clearstatcache( true, $path );
	return is_file( $path );
};
$reset_markers = static function () use ( $marker, $breaker, $exists ): void {
	foreach ( array( $marker, $marker . '.claimed', $breaker ) as $path ) {
		if ( $exists( $path ) ) {
			unlink( $path );
		}
	}
};
$generation_key = Simple_Redis_Cache_Early_Config::meta_key( 'object-generation', $front );
$digest         = static fn ( string $group, string $key ): string => SRC_Test_Batch_Redis::digest( $group, $key );

try {
	/* 1. Round trips. */
	$seed = $request( $front );
	$hits = array();
	for ( $i = 0; $i < 100; ++$i ) {
		$hits[ 'hit-' . $i ] = 'value-' . $i;
	}
	$seed->set_multiple( $hits, 'posts' );

	[ $cache, $client ] = $counted( $front );
	$keys = array();
	for ( $i = 0; $i < 100; ++$i ) {
		$keys[] = 'hit-' . $i;
		$keys[] = 'miss-' . $i;
	}
	$values = $cache->get_multiple( $keys, 'posts' );
	$expected = array();
	foreach ( $keys as $key ) {
		$expected[ $key ] = $hits[ $key ] ?? false;
	}
	$assert( $expected === $values, 'get_multiple() returned wrong values.' );
	$assert( 2 === $client->round_trips, 'get_multiple() of 100 hits and 100 misses took ' . $client->round_trips . ' round trips in a fresh request, not 2.' );
	$assert( 100 === $cache->cache_hits && 100 === $cache->cache_misses, 'get_multiple() counted ' . $cache->cache_hits . ' hits and ' . $cache->cache_misses . ' misses.' );

	// Hits now come from L1; the misses are looked up again, as get() does, in one round trip.
	$client->round_trips = 0;
	$assert( $expected === $cache->get_multiple( $keys, 'posts' ) && 1 === $client->round_trips, 'A repeated get_multiple() took ' . $client->round_trips . ' round trips, not 1.' );
	$client->round_trips = 0;
	$assert( $hits === $cache->get_multiple( array_keys( $hits ), 'posts' ) && 0 === $client->round_trips, 'A repeated get_multiple() of hits did not come from L1.' );
	$assert( 'value-0' === $cache->get( 'hit-0', 'posts', true ) && 1 === $client->round_trips, 'A forced get() hit took more than one round trip.' );

	$client->round_trips = 0;
	$new = array();
	for ( $i = 0; $i < 100; ++$i ) {
		$new[ 'new-' . $i ] = 'n' . $i;
	}
	$assert( array_fill_keys( array_keys( $new ), true ) === $cache->add_multiple( $new, 'posts' ), 'add_multiple() of new keys did not succeed for every key.' );
	$assert( 1 === $client->round_trips, 'add_multiple() of 100 new keys took ' . $client->round_trips . ' round trips, not 1.' );

	[ $other, $other_client ] = $counted( $front );
	$other->get( 'warm-up', 'posts' );
	$other_client->round_trips = 0;
	$assert( array_fill_keys( array_keys( $new ), false ) === $other->add_multiple( $new, 'posts' ), 'add_multiple() of existing keys was not refused for every key.' );
	$assert( 2 === $other_client->round_trips, 'A refused add_multiple() of 100 keys took ' . $other_client->round_trips . ' round trips, not 2.' );

	$client->round_trips = 0;
	$changed = array_map( static fn ( string $value ): string => $value . '-changed', $hits );
	$assert( array_fill_keys( array_keys( $hits ), true ) === $cache->set_multiple( $changed, 'posts' ), 'set_multiple() did not succeed for every key.' );
	$assert( 1 === $client->round_trips, 'set_multiple() of 100 keys took ' . $client->round_trips . ' round trips, not 1.' );
	$assert( $changed === $request( $front )->get_multiple( array_keys( $hits ), 'posts' ), 'set_multiple() did not reach Redis.' );

	$client->round_trips = 0;
	$assert( array_fill_keys( array_keys( $new ), true ) === $cache->delete_multiple( array_keys( $new ), 'posts' ), 'delete_multiple() did not delete every key.' );
	$assert( 1 === $client->round_trips, 'delete_multiple() of 100 keys took ' . $client->round_trips . ' round trips, not 1.' );
	$assert( array_fill_keys( array_keys( $new ), false ) === $request( $front )->get_multiple( array_keys( $new ), 'posts' ), 'delete_multiple() left keys in Redis.' );

	[ $single, $single_client ] = $counted( $front );
	$single->get( 'hit-0', 'posts' );
	$assert( 2 === $single_client->round_trips, 'The first get() hit took ' . $single_client->round_trips . ' round trips, not 2 (generations, then GET with TTL).' );
	$single->get( 'hit-1', 'posts' );
	$assert( 3 === $single_client->round_trips, 'A get() hit took more than one round trip.' );

	/* 2. Each key gets what the single call would give it. */
	$both = static function ( string $label, callable $prepare, callable $multi, callable $single ) use ( $assert ): void {
		$prepare( 'multi-' . $label );
		$prepare( 'single-' . $label );
		$by_multi  = $multi( 'multi-' . $label );
		$by_single = $single( 'single-' . $label );
		$assert( $by_single === $by_multi, $label . ': the batched call differs from the single calls: ' . var_export( array( 'multi' => $by_multi, 'single' => $by_single ), true ) );
	};
	$after = static function ( array $config, string $group, array $keys ) use ( $request ): array {
		$later  = $request( $config );
		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = $later->get( $key, $group );
		}
		return $result;
	};
	// Closures are cloned into L1, so compare them by type.
	$plain      = static fn ( $value ) => $value instanceof Closure ? 'closure' : $value;
	$seed_group = static function ( string $group ) use ( $request, $front ): void {
		$cache = $request( $front );
		$cache->set( 'old', 'in-redis', $group );
		$cache->set( 7, 'seven', $group );
	};

	$both(
		'get',
		$seed_group,
		static function ( string $group ) use ( $request, $front ): array {
			$cache = $request( $front );
			$cache->set( 'local', 'l1', $group );
			$before = $GLOBALS['src_test_doing_wrong'];
			$values = $cache->get_multiple( array( 'old', 7, '7', 'missing', '', 'local', 'old' ), $group );
			return array( $values, $GLOBALS['src_test_doing_wrong'] - $before, $cache->get_multiple( array( 'old', 'local' ), $group, true ) );
		},
		static function ( string $group ) use ( $request, $front ): array {
			$cache = $request( $front );
			$cache->set( 'local', 'l1', $group );
			$before = $GLOBALS['src_test_doing_wrong'];
			$values = array();
			foreach ( array( 'old', 7, '7', 'missing', '', 'local', 'old' ) as $key ) {
				$values[ $key ] = $cache->get( $key, $group );
			}
			return array( $values, $GLOBALS['src_test_doing_wrong'] - $before, array( 'old' => $cache->get( 'old', $group, true ), 'local' => $cache->get( 'local', $group, true ) ) );
		}
	);

	$closure = static function (): void {};
	$add_data = array( 'old' => 'mine', 'fresh' => 'mine', 7 => 'mine', 'local' => 'mine', 'closure' => $closure, ' ' => 'blank' );
	foreach ( array( 'front' => $front, 'admin' => $admin ) as $mode => $config ) {
		$both(
			'add-' . $mode,
			$seed_group,
			static function ( string $group ) use ( $request, $config, $add_data, $after ): array {
				$cache = $request( $config );
				$cache->set( 'local', 'l1', $group );
				$results = $cache->add_multiple( $add_data, $group );
				return array( $results, $cache->cache[ $group ] ?? array(), $after( $config, $group, array( 'old', 'fresh', 7, 'closure' ) ) );
			},
			static function ( string $group ) use ( $request, $config, $add_data, $after ): array {
				$cache = $request( $config );
				$cache->set( 'local', 'l1', $group );
				$results = array();
				foreach ( $add_data as $key => $value ) {
					$results[ $key ] = $cache->add( $key, $value, $group );
				}
				return array( $results, $cache->cache[ $group ] ?? array(), $after( $config, $group, array( 'old', 'fresh', 7, 'closure' ) ) );
			}
		);
	}

	$GLOBALS['src_test_suspend_adds'] = true;
	$assert( array( 'a' => false, 'b' => false ) === $request( $front )->add_multiple( array( 'a' => 1, 'b' => 2 ), 'posts' ), 'add_multiple() ignored wp_suspend_cache_addition().' );
	$GLOBALS['src_test_suspend_adds'] = false;

	$both(
		'set',
		$seed_group,
		static function ( string $group ) use ( $request, $front, $closure, $after, $plain ): array {
			$cache = $request( $front );
			$results = $cache->set_multiple( array( 'old' => $closure, 7 => 'eight', 'new' => 'value', '' => 'invalid' ), $group );
			return array( $results, array_map( $plain, $cache->cache[ $group ] ?? array() ), $after( $front, $group, array( 'old', 7, 'new' ) ) );
		},
		static function ( string $group ) use ( $request, $front, $closure, $after, $plain ): array {
			$cache = $request( $front );
			$results = array();
			foreach ( array( 'old' => $closure, 7 => 'eight', 'new' => 'value', '' => 'invalid' ) as $key => $value ) {
				$results[ $key ] = $cache->set( $key, $value, $group );
			}
			return array( $results, array_map( $plain, $cache->cache[ $group ] ?? array() ), $after( $front, $group, array( 'old', 7, 'new' ) ) );
		}
	);

	$both(
		'delete',
		$seed_group,
		static function ( string $group ) use ( $request, $front, $after ): array {
			$cache = $request( $front );
			$cache->set( 'local', 'l1', $group );
			$results = $cache->delete_multiple( array( 'old', 'old', 7, 'missing', 'local', '' ), $group );
			return array( $results, $cache->cache[ $group ] ?? array(), $after( $front, $group, array( 'old', 7 ) ) );
		},
		static function ( string $group ) use ( $request, $front, $after ): array {
			$cache = $request( $front );
			$cache->set( 'local', 'l1', $group );
			$results = array();
			foreach ( array( 'old', 'old', 7, 'missing', 'local', '' ) as $key ) {
				$results[ $key ] = $cache->delete( $key, $group );
			}
			return array( $results, $cache->cache[ $group ] ?? array(), $after( $front, $group, array( 'old', 7 ) ) );
		}
	);

	// The plugin's own settings stay out of Redis whichever call writes them.
	$secret   = 'cf-token-' . bin2hex( random_bytes( 8 ) );
	$settings = $request( $front );
	$settings->set_multiple( array( 'simple_redis_cache_settings' => array( 'token' => $secret ), 'control' => 'reaches-redis' ), 'options' );
	$settings->add_multiple( array( 'simple_redis_cache_settings' => array( 'token' => $secret ), 'control-add' => 'reaches-redis' ), 'options' );
	$later = $request( $front );
	$assert( false === $later->get( 'simple_redis_cache_settings', 'options' ), 'set_multiple() or add_multiple() wrote the plugin settings to Redis.' );
	$assert( 'reaches-redis' === $later->get( 'control', 'options' ) && 'reaches-redis' === $later->get( 'control-add', 'options' ), 'The control options did not reach Redis, so the settings check proves nothing.' );

	// wp-admin with cache_wp_admin off reads no persistent group from Redis, batched or not.
	[ $no_reads, $no_reads_client ] = $counted( $admin );
	$no_reads->get( 'warm-up', 'transient' );
	$no_reads_client->round_trips = 0;
	$assert( array( 'hit-0' => false, 'hit-1' => false ) === $no_reads->get_multiple( array( 'hit-0', 'hit-1' ), 'posts' ) && 0 === $no_reads_client->round_trips, 'get_multiple() read Redis in wp-admin with cache_wp_admin off.' );

	// The database mirror: a transient missing from Redis is read from its row and rewarmed.
	$GLOBALS['src_test_options']['_transient_from-db']         = 'db-value';
	$GLOBALS['src_test_options']['_transient_timeout_from-db'] = time() + 120;
	$mirror = $request( $front );
	$assert( array( 'from-db' => 'db-value', 'nowhere' => false ) === $mirror->get_multiple( array( 'from-db', 'nowhere' ), 'transient' ), 'get_multiple() lost the database mirror fallback.' );
	unset( $GLOBALS['src_test_options']['_transient_from-db'], $GLOBALS['src_test_options']['_transient_timeout_from-db'] );
	$assert( 'db-value' === $request( $front )->get( 'from-db', 'transient' ), 'get_multiple() did not rewarm Redis from the database mirror.' );
	$request( $front )->set_multiple( array( 'mirrored' => 'm', 'unserializable' => $closure ), 'transient' );
	$assert( 'm' === ( $GLOBALS['src_test_options']['_transient_mirrored'] ?? null ), 'set_multiple() did not write the database mirror.' );
	$assert( ! array_key_exists( '_transient_unserializable', $GLOBALS['src_test_options'] ), 'set_multiple() mirrored a value that cannot be serialized.' );

	// An invalid payload found by get_multiple() is a miss and is deleted.
	$garbage_key = Simple_Redis_Cache_Early_Config::prefix( $front ) . 'object:' . $probe->get( $generation_key ) . ':' . $probe->get( Simple_Redis_Cache_Early_Config::meta_key( 'object-group-' . hash( 'sha256', 'posts' ) . '-generation', $front ) ) . ':' . $digest( 'posts', 'garbage' );
	$probe->set( $garbage_key, 'not-a-payload' );
	$assert( array( 'garbage' => false, 'hit-0' => 'value-0-changed' ) === $request( $front )->get_multiple( array( 'garbage', 'hit-0' ), 'posts' ), 'get_multiple() served an invalid payload.' );
	$assert( 0 === (int) $probe->exists( $garbage_key ), 'get_multiple() left an invalid payload in Redis.' );

	/* 3. Failures inside a batch. */
	$failure_reply = array(
		'err' => 'ERR simulated error reply',
		'oom' => "OOM command not allowed when used memory > 'maxmemory'.",
		'ro'  => "READONLY You can't write against a read only replica.",
	);

	// a. One SET of the batch gets an error reply that does not abort the pipeline.
	$reset_markers();
	$request( $front )->set_multiple( array( 'p1' => 'old', 'p2' => 'old', 'p3' => 'old' ), 'posts' );
	$generation_before = (int) $probe->get( $generation_key );
	[ $partial, $partial_client ] = $counted( $front );
	$partial->get( 'warm-up', 'posts' );
	$partial_client->set_replies[ $digest( 'posts', 'p2' ) ] = $failure_reply['err'];
	$assert( array( 'p1' => true, 'p2' => true, 'p3' => true ) === $partial->set_multiple( array( 'p1' => 'new', 'p2' => 'new', 'p3' => 'new' ), 'posts' ), 'set_multiple() stopped failing open after an error reply.' );
	$partial_client->set_replies = array();
	$assert( ! $exists( $breaker ), 'An error reply inside set_multiple() tripped the breaker.' );
	$assert( ! $exists( $marker ), 'A refused SET whose old copy was deleted marked the cache dirty.' );
	$assert( 'new' === $partial->get( 'p2', 'posts' ), 'The request lost its own L1 copy of the refused key.' );
	$partial_client->round_trips = 0;
	$assert( false === $partial->get( 'hit-0', 'posts' ) && 0 === $partial_client->round_trips, 'The request kept using Redis after an error reply.' );
	$assert( array( 'p1' => 'new', 'p2' => false, 'p3' => 'new' ) === $after( $front, 'posts', array( 'p1', 'p2', 'p3' ) ), 'After a partly refused set_multiple(), Redis holds the wrong values.' );
	$assert( $generation_before === (int) $probe->get( $generation_key ), 'A scrubbed set_multiple() invalidated the namespace.' );

	// b. An OOM reply aborts the pipeline; the DEL of the whole batch still works.
	$reset_markers();
	$request( $front )->set_multiple( array( 'p1' => 'old', 'p2' => 'old', 'p3' => 'old' ), 'posts' );
	[ $oom, $oom_client ] = $counted( $front );
	$oom->get( 'warm-up', 'posts' );
	$oom_client->set_replies[ $digest( 'posts', 'p2' ) ] = $failure_reply['oom'];
	$oom->set_multiple( array( 'p1' => 'new', 'p2' => 'new', 'p3' => 'new' ), 'posts' );
	$assert( in_array( $failure_reply['oom'], $oom->errors, true ), 'The OOM reply inside set_multiple() was not recorded.' );
	$assert( ! $exists( $breaker ), 'An OOM reply inside set_multiple() tripped the breaker.' );
	$assert( ! $exists( $marker ), 'An OOM-refused set_multiple() whose keys were deleted marked the cache dirty.' );
	$assert( array( 'p1' => false, 'p2' => false, 'p3' => false ) === $after( $front, 'posts', array( 'p1', 'p2', 'p3' ) ), 'After an OOM-refused set_multiple(), Redis still holds old copies.' );

	// c. The same, but the DEL is refused too: the old copies may survive, so the cache is dirty.
	$reset_markers();
	[ $readonly, $readonly_client ] = $counted( $front );
	$readonly->get( 'warm-up', 'posts' );
	$readonly_client->set_replies[ $digest( 'posts', 'p2' ) ] = $failure_reply['ro'];
	$readonly_client->del_reply = $failure_reply['ro'];
	$readonly->set_multiple( array( 'p1' => 'new', 'p2' => 'new' ), 'posts' );
	$assert( ! $exists( $breaker ), 'A READONLY reply inside set_multiple() tripped the breaker.' );
	$assert( $exists( $marker ), 'A refused set_multiple() whose old copies could not be deleted left no dirty marker.' );

	// d-f. The connection drops in the middle of a batch.
	$drops = array(
		'set_multiple'    => array( true, static fn ( WP_Object_Cache $cache ) => $cache->set_multiple( array( 'd1' => 'v', 'd2' => 'v', 'd3' => 'v' ), 'posts' ) ),
		'delete_multiple' => array( true, static fn ( WP_Object_Cache $cache ) => $cache->delete_multiple( array( 'd1', 'd2', 'd3' ), 'posts' ) ),
		'get_multiple'    => array( false, static fn ( WP_Object_Cache $cache ) => $cache->get_multiple( array( 'hit-0', 'hit-1', 'hit-2' ), 'posts' ) ),
		'add_multiple'    => array( false, static fn ( WP_Object_Cache $cache ) => $cache->add_multiple( array( 'd4' => 'v', 'd5' => 'v', 'd6' => 'v' ), 'posts' ) ),
	);
	foreach ( $drops as $name => [ $dirties, $operation ] ) {
		$reset_markers();
		[ $dropped, $dropped_client ] = $counted( $front );
		$dropped->get( 'warm-up', 'posts' );
		$dropped_client->drop_after = 1;
		$result = $operation( $dropped );
		$dropped_client->drop_after = null;
		$assert( in_array( 'read error on connection to 127.0.0.1:6379', $dropped->errors, true ), $name . '(): the dropped connection was not recorded.' );
		$assert( $exists( $breaker ), $name . '(): a dropped connection did not trip the breaker.' );
		$assert( $dirties === $exists( $marker ), $name . '(): ' . ( $dirties ? 'changes lost with the connection left no dirty marker.' : 'a call that cannot leave a stale copy marked the cache dirty.' ) );
		if ( 'get_multiple' === $name ) {
			$assert( array( 'hit-0' => false, 'hit-1' => false, 'hit-2' => false ) === $result, 'get_multiple() served values after the connection dropped.' );
		}
		if ( 'add_multiple' === $name ) {
			$assert( array( 'd4' => true, 'd5' => true, 'd6' => true ) === $result && 'v' === $dropped->get( 'd5', 'posts' ), 'add_multiple() did not fail open into L1 when the connection dropped.' );
		}
		if ( 'set_multiple' === $name ) {
			$assert( 'v' === $dropped->get( 'd2', 'posts' ), 'set_multiple() lost its L1 copy when the connection dropped.' );
		}
		$dropped_client->round_trips = 0;
		$dropped->get( 'hit-3', 'posts' );
		$assert( 0 === $dropped_client->round_trips, $name . '(): the request kept using Redis after the connection dropped.' );
	}
	$reset_markers();

	// g. An OOM reply refuses add_multiple(): it fails open and marks nothing.
	[ $add_oom, $add_oom_client ] = $counted( $front );
	$add_oom->get( 'warm-up', 'posts' );
	$add_oom_client->set_replies[ $digest( 'posts', 'o2' ) ] = $failure_reply['oom'];
	$assert( array( 'o1' => true, 'o2' => true ) === $add_oom->add_multiple( array( 'o1' => 'v', 'o2' => 'v' ), 'posts' ), 'add_multiple() did not fail open after an OOM reply.' );
	$assert( ! $exists( $breaker ) && ! $exists( $marker ), 'An OOM-refused add_multiple() tripped the breaker or marked the cache dirty.' );
	$reset_markers();

	// i. Redis fails while get_multiple() works through its keys (here, rewarming a
	// mirrored transient): like get(), the keys after that are not served from Redis.
	$request( $front )->set( 'in-redis', 'redis-value', 'transient' );
	unset( $GLOBALS['src_test_options']['_transient_in-redis'], $GLOBALS['src_test_options']['_transient_timeout_in-redis'] );
	$GLOBALS['src_test_options']['_transient_db-only']         = 'db-value';
	$GLOBALS['src_test_options']['_transient_timeout_db-only'] = time() + 120;
	[ $midway, $midway_client ] = $counted( $front );
	$midway->get( 'warm-up', 'transient' );
	$midway_client->drop_after = 3; // MGET and two TTLs, then the rewarming SET NX fails.
	$values = $midway->get_multiple( array( 'db-only', 'in-redis' ), 'transient' );
	$midway_client->drop_after = null;
	$assert( array( 'db-only' => 'db-value', 'in-redis' => false ) === $values, 'After Redis failed inside get_multiple(), a later key was still served from Redis: ' . var_export( $values, true ) );
	unset( $GLOBALS['src_test_options']['_transient_db-only'], $GLOBALS['src_test_options']['_transient_timeout_db-only'] );
	$reset_markers();

	// h. A key that vanishes between SET NX and EXISTS gets add()'s one retry.
	[ $phantom, $phantom_client ] = $counted( $front );
	$phantom->get( 'warm-up', 'posts' );
	$phantom_client->phantom_refusals[ $digest( 'posts', 'h2' ) ] = true;
	$assert( array( 'h1' => true, 'h2' => true ) === $phantom->add_multiple( array( 'h1' => 'v', 'h2' => 'v' ), 'posts' ), 'add_multiple() did not retry a key that vanished after its refusal.' );
	$assert( 'v' === $request( $front )->get( 'h2', 'posts' ), 'The retried add_multiple() key did not reach Redis.' );
	$assert( array() === $phantom->errors, 'The retried add_multiple() recorded an error: ' . implode( ', ', $phantom->errors ) );
	/* Replies read ahead by get_multiple() never answer after this request changed the cache. */
	$prefetch = new ReflectionMethod( WP_Object_Cache::class, 'prefetch' );
	foreach ( array( 'delete', 'set', 'flush_runtime', 'flush_group', 'flush' ) as $change ) {
		$seed = $request( $front );
		$seed->set( 'pre-a', 'fresh-a', 'posts' );
		$seed->set( 'pre-b', 'stale-b', 'posts' );

		$cache = $request( $front );
		// What get_multiple() does first; a hook inside one of its get() calls then changes the cache.
		$prefetch->invoke( $cache, array( 'pre-a', 'pre-b' ), 'posts', false );
		match ( $change ) {
			'delete'        => $cache->delete( 'pre-b', 'posts' ),
			'set'           => $request( $front )->set( 'pre-b', 'newer-b', 'posts' ) && $cache->flush_runtime(),
			'flush_runtime' => $request( $front )->delete( 'pre-b', 'posts' ) && $cache->flush_runtime(),
			'flush_group'   => $cache->flush_group( 'posts' ),
			'flush'         => $cache->flush(),
		};
		$expected = 'set' === $change ? 'newer-b' : false;
		$actual   = $cache->get( 'pre-b', 'posts' );
		$assert( $expected === $actual, "After {$change}() during get_multiple(), get() answered with the value read ahead: " . var_export( $actual, true ) );
	}
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

echo "OK: *_multiple calls batch their Redis commands, answer key by key exactly as the single calls, and keep every failure rule inside a batch.\n";
