<?php
/**
 * A persistent change that never reached Redis must not let the old Redis copy
 * outlive the outage until max_ttl (#11414), and the object cache must use the
 * cross-request breaker (#11416) for connection failures only, never for an
 * error reply. A request that connects while another one invalidates the
 * namespace runs uncached instead of reading the old namespace, and a damaged
 * generated config falls back to core's runtime cache.
 *
 * Each WP_Object_Cache below stands for one request. Redis that is down for a
 * whole request is a config aimed at a closed local port; a connection lost
 * mid-request is a PhpRedis subclass that starts throwing, and an error reply
 * is a real one the subclass asks the server for. The shared Redis server is
 * never stopped or reconfigured, and only keys under this run's random
 * WP_CACHE_KEY_SALT are touched.
 *
 * Usage: php tests/object-cache-dirty-marker.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

$test_content = sys_get_temp_dir() . '/simple-redis-cache-dirty-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $test_content, 0700 ) && ! is_dir( $test_content ) ) {
	throw new RuntimeException( 'Could not create the dirty-marker test directory.' );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'WP_CACHE_KEY_SALT', 'src-dirty-test-' . bin2hex( random_bytes( 8 ) ) );

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

$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
if ( false === $listener ) {
	$remove_test_content();
	fwrite( STDERR, 'SKIP: could not reserve a closed port: ' . $errstr . "\n" );
	exit( 0 );
}
$closed_port = (int) substr( (string) strrchr( (string) stream_socket_get_name( $listener, false ), ':' ), 1 );
fclose( $listener );

/** A live connection that can be told to fail, like one that just dropped. */
final class SRC_Test_Faulty_Redis extends Redis {
	public bool $broken = false;
	public bool $broken_eval = false;
	/** @var (callable(): void)|null Runs once before the next EVAL, i.e. inside a generation bump. */
	public $before_eval = null;
	/** @var array<string, string> Method => error reply the server sends instead of running it. */
	public array $replies = array();

	private function fail_if( bool $condition ): void {
		if ( $condition ) {
			throw new RedisException( 'read error on connection to 127.0.0.1:6379' );
		}
	}

	/** A real error reply: PhpRedis throws for OOM or READONLY and returns false for ERR. */
	private function error_reply( string $message ): mixed {
		return parent::eval( 'return redis.error_reply(ARGV[1])', array( $message ), 0 );
	}

	public function set( string $key, mixed $value, mixed $options = null ): Redis|string|bool {
		if ( isset( $this->replies['set'] ) ) {
			return $this->error_reply( $this->replies['set'] );
		}
		$this->fail_if( $this->broken );
		return parent::set( $key, $value, $options );
	}

	public function get( string $key ): mixed {
		$this->fail_if( $this->broken );
		return parent::get( $key );
	}

	public function del( array|string $key, string ...$other_keys ): Redis|int|false {
		$this->fail_if( $this->broken );
		return parent::del( $key, ...$other_keys );
	}

	public function watch( array|string $key, string ...$other_keys ): Redis|bool {
		if ( isset( $this->replies['watch'] ) ) {
			return (bool) $this->error_reply( $this->replies['watch'] );
		}
		$this->fail_if( $this->broken );
		return parent::watch( $key, ...$other_keys );
	}

	public function eval( string $script, array $args = array(), int $num_keys = 0 ): mixed {
		if ( null !== $this->before_eval ) {
			$hook              = $this->before_eval;
			$this->before_eval = null;
			$hook();
		}
		if ( isset( $this->replies['eval'] ) ) {
			return $this->error_reply( $this->replies['eval'] );
		}
		$this->fail_if( $this->broken || $this->broken_eval );
		return parent::eval( $script, $args, $num_keys );
	}
}

$healthy                          = Simple_Redis_Cache_Early_Config::defaults();
$healthy['redis']['host']         = $host;
$healthy['redis']['port']         = $port;
$healthy['redis']['timeout']      = 0.5;
$healthy['redis']['read_timeout'] = 0.5;
$healthy['object']['enabled']     = true;
$healthy['object']['max_ttl']     = 300;
$down                             = $healthy;
$down['redis']['host']            = '127.0.0.1';
$down['redis']['port']            = $closed_port;

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

/** A connected client that fails on demand. */
$faulty = static function () use ( $host, $port ): SRC_Test_Faulty_Redis {
	$client = new SRC_Test_Faulty_Redis();
	$client->connect( $host, $port, 0.5, null, 0, 0.5 );
	return $client;
};

/** Hand the cache's Redis wrapper a client before its first connection. */
$inject_wrapper_client = static function ( WP_Object_Cache $cache, Redis $client ) use ( $cache_class, $redis_class ): void {
	$wrapper = $cache_class->getProperty( 'redis' )->getValue( $cache );
	$redis_class->getProperty( 'client' )->setValue( $wrapper, $client );
	$redis_class->getProperty( 'attempted' )->setValue( $wrapper, true );
};

/** Swap the connection a cache already uses, as if it dropped mid-request. */
$drop_connection = static function ( WP_Object_Cache $cache, SRC_Test_Faulty_Redis $client ) use ( $cache_class ): void {
	$client->broken = true;
	$cache_class->getProperty( 'redis_client' )->setValue( $cache, $client );
};

$marker  = WP_CONTENT_DIR . '/simple-redis-cache-object-dirty';
$claim   = $marker . '.claimed';
$breaker = Simple_Redis_Cache_Redis::breaker_path();
$busy    = 'Redis was skipped while another request invalidates the object cache.';
$exists  = static function ( string $path ): bool {
	clearstatcache( true, $path );
	return is_file( $path );
};
/** Seconds since a file changed, or null when it does not exist. */
$age = static function ( string $path ): ?int {
	clearstatcache( true, $path );
	return is_file( $path ) ? time() - (int) filemtime( $path ) : null;
};
$breaker_open = static function () use ( $breaker, $age ): bool {
	$seconds = $age( $breaker );
	return null !== $seconds && $seconds < 15;
};
$leftovers = static function () use ( $marker ): array {
	return array_merge( glob( $marker . '.*' ) ?: array(), glob( WP_CONTENT_DIR . '/*.tmp' ) ?: array() );
};
// Stand-in for the 15 seconds after which the breaker lets requests try again.
$expire_breaker = static function () use ( $breaker, $exists ): void {
	if ( $exists( $breaker ) ) {
		touch( $breaker, time() - 60 );
		clearstatcache( true, $breaker );
	}
};
$reset_markers = static function () use ( $marker, $claim, $breaker, $exists ): void {
	foreach ( array( $marker, $claim, $breaker ) as $path ) {
		if ( $exists( $path ) ) {
			unlink( $path );
		}
	}
};
$object_generation_key = Simple_Redis_Cache_Early_Config::meta_key( 'object-generation', $healthy );

try {
	/* 1. Healthy requests never leave a marker. */
	$warm = $request( $healthy );
	$warm->set( 'post', 'old', 'posts' );
	$warm->set( 'meta', 'old', 'post_meta' );
	$warm->add( 'counter', 5, 'posts' );
	$warm->replace( 'post', 'old', 'posts' );
	$warm->incr( 'counter', 1, 'posts' );
	$warm->delete( 'missing', 'posts' );
	$warm->flush_group( 'unrelated' );
	$assert( ! $exists( $marker ), 'A request whose changes all reached Redis left a dirty marker.' );
	$assert( 'old' === $request( $healthy )->get( 'post', 'posts' ), 'The healthy write did not reach Redis.' );

	/* 2. Redis down for a whole request: the lost delete is not forgotten. */
	$outage = $request( $down );
	$assert( true === $outage->set( 'post', 'new', 'posts' ), 'set() stopped failing open during an outage.' );
	$outage->delete( 'meta', 'post_meta' );
	$assert( 'new' === $outage->get( 'post', 'posts' ), 'The outage request lost its own L1 write.' );
	$assert( $exists( $marker ), 'Changes that never reached Redis left no dirty marker.' );
	$assert( $exists( $breaker ), 'The object cache did not trip the breaker on a connection failure.' );

	$expire_breaker();
	$after = $request( $healthy );
	$assert( false === $after->get( 'post', 'posts' ), 'After the outage, a request read the overwritten Redis copy of a value.' );
	$assert( false === $after->get( 'meta', 'post_meta' ), 'After the outage, a request read the Redis copy of a deleted value.' );
	$assert( ! $exists( $marker ), 'The recovering request did not clear the dirty marker.' );
	$assert( array() === $leftovers(), 'Clearing the marker left claimed or temporary files: ' . implode( ', ', $leftovers() ) );

	/* 3. Every kind of lost change marks dirty on its own. */
	$operations = array(
		'set'         => static fn ( WP_Object_Cache $cache ) => $cache->set( 'k', 'v', 'posts' ),
		'replace'     => static fn ( WP_Object_Cache $cache ) => $cache->replace( 'k', 'v', 'posts' ),
		'delete'      => static fn ( WP_Object_Cache $cache ) => $cache->delete( 'k', 'posts' ),
		'incr'        => static fn ( WP_Object_Cache $cache ) => $cache->incr( 'k', 1, 'posts' ),
		'decr'        => static fn ( WP_Object_Cache $cache ) => $cache->decr( 'k', 1, 'posts' ),
		'flush'       => static fn ( WP_Object_Cache $cache ) => $cache->flush(),
		'flush_group' => static fn ( WP_Object_Cache $cache ) => $cache->flush_group( 'posts' ),
	);
	foreach ( $operations as $name => $operation ) {
		$reset_markers();
		$operation( $request( $down ) );
		$assert( $exists( $marker ), $name . '() during an outage left no dirty marker.' );
	}

	/* 4. Changes that cannot leave a stale Redis copy never mark dirty. */
	$reset_markers();
	$quiet = $request( $down );
	$quiet->add_non_persistent_groups( array( 'request-only' ) );
	$quiet->set( 'k', 'v', 'request-only' );
	$quiet->replace( 'k', 'w', 'request-only' );
	$quiet->incr( 'n', 1, 'request-only' );
	$quiet->delete( 'k', 'request-only' );
	$quiet->flush_group( 'request-only' );
	$assert( ! $exists( $marker ), 'A non-persistent group marked the object cache dirty.' );
	$assert( true === $quiet->add( 'fresh', 'v', 'posts' ), 'add() stopped failing open during an outage.' );
	$assert( ! $exists( $marker ), 'A lost add(), which cannot leave a stale copy, marked the cache dirty.' );
	$quiet->set( 'simple_redis_cache_settings', array( 'secret' ), 'options' );
	$assert( ! $exists( $marker ), 'The L1-only plugin settings marked the object cache dirty.' );

	/* 5. While the breaker is open, Redis is skipped, changes mark dirty, and the breaker is not extended. */
	$reset_markers();
	$request( $healthy )->set( 'visible', 'in-redis', 'posts' );
	$tripped_at = time() - 5;
	touch( $breaker, $tripped_at );
	clearstatcache( true, $breaker );
	$skipping = $request( $healthy );
	$assert( false === $skipping->get( 'visible', 'posts' ), 'An open breaker did not make the object cache skip Redis.' );
	$assert( in_array( 'Redis was skipped after a recent connection failure.', $skipping->errors, true ), 'The skipped connection was not recorded.' );
	$skipping->set( 'visible', 'changed', 'posts' );
	$assert( $exists( $marker ), 'A change made while the breaker skipped Redis left no dirty marker.' );
	clearstatcache( true, $breaker );
	$assert( $tripped_at === filemtime( $breaker ), 'A request skipped by the breaker extended it.' );

	$expire_breaker();
	$assert( false === $request( $healthy )->get( 'visible', 'posts' ), 'A change made while the breaker was open was lost after recovery.' );

	/* 6. A connection lost mid-request: the breaker trips and the rest of the request marks dirty. */
	$reset_markers();
	$mid = $request( $healthy );
	$mid->set( 'option', 'before', 'options' );
	$assert( 'before' === $request( $healthy )->get( 'option', 'options' ), 'The pre-failure write did not reach Redis.' );
	$drop_connection( $mid, $faulty() );
	$assert( true === $mid->set( 'option', 'after', 'options' ), 'set() stopped failing open after a lost connection.' );
	$assert( 'after' === $mid->get( 'option', 'options' ), 'The request lost its own L1 write after the connection dropped.' );
	$assert( $exists( $marker ), 'A write lost to a dropped connection left no dirty marker.' );
	$assert( $exists( $breaker ), 'A runtime Redis error did not trip the breaker.' );
	$assert( in_array( 'read error on connection to 127.0.0.1:6379', $mid->errors, true ), 'The runtime Redis error was not recorded.' );

	$expire_breaker();
	$assert( false === $request( $healthy )->get( 'option', 'options' ), 'After a mid-request failure, a later request read the stale Redis copy.' );

	/* 7. A marker written while another request is clearing an older one survives. */
	$reset_markers();
	$still_failing = $request( $down );
	$still_failing->get( 'anything', 'posts' );
	$still_failing->set( 'first', 'v', 'posts' );
	$assert( $exists( $marker ), 'The failing request did not write its first marker.' );
	$expire_breaker();

	$generation_before = (int) $probe->get( $object_generation_key );
	$clearing          = $request( $healthy );
	$hook              = $faulty();
	$hook->before_eval = static function () use ( $still_failing, $marker, $exists, $assert ): void {
		$assert( ! $exists( $marker ), 'The recovering request did not claim the marker before invalidating.' );
		// The failing request loses another change while the claim is in progress.
		$still_failing->delete( 'second', 'posts' );
	};
	$inject_wrapper_client( $clearing, $hook );
	$clearing->get( 'anything', 'posts' );
	$assert( null === $hook->before_eval, 'The recovering request never invalidated the namespace.' );
	$assert( (int) $probe->get( $object_generation_key ) > $generation_before, 'The recovering request did not bump the object generation.' );
	$assert( $exists( $marker ), 'A marker written during another request\'s clear was deleted with the old one.' );
	$assert( array() === $leftovers(), 'The concurrent clear left claimed or temporary files: ' . implode( ', ', $leftovers() ) );

	$request( $healthy )->get( 'anything', 'posts' );
	$assert( ! $exists( $marker ), 'The next request did not clear the surviving marker.' );

	/* 8. A failed invalidation hands the claim back and runs the request uncached. */
	$reset_markers();
	$request( $healthy )->set( 'stale', 'old', 'posts' );
	$request( $down )->delete( 'stale', 'posts' );
	$expire_breaker();

	$unlucky            = $request( $healthy );
	$failing            = $faulty();
	$failing->broken_eval = true;
	$inject_wrapper_client( $unlucky, $failing );
	$claim_seconds = (int) $cache_class->getProperty( 'claim_seconds' )->getValue( $unlucky );
	$assert( false === $unlucky->get( 'stale', 'posts' ), 'A request whose invalidation failed still read the stale namespace.' );
	// Backdated, the claim reads as abandoned: the next request claims it again.
	$assert( $exists( $marker ) || (int) $age( $claim ) > $claim_seconds, 'A failed invalidation did not hand its claim back.' );
	$assert( array() === ( glob( WP_CONTENT_DIR . '/*.tmp' ) ?: array() ), 'A failed invalidation left a temporary file behind.' );
	$assert( $exists( $breaker ) && (int) $age( $breaker ) <= 2, 'A failed invalidation did not trip the breaker.' );

	$expire_breaker();
	$generation_before = (int) $probe->get( $object_generation_key );
	$assert( false === $request( $healthy )->get( 'stale', 'posts' ), 'The handed-back claim did not invalidate on the next request.' );
	$assert( (int) $probe->get( $object_generation_key ) > $generation_before, 'The next request did not take the handed-back claim over.' );
	$assert( ! $exists( $marker ) && array() === $leftovers(), 'The handed-back claim was not cleared once Redis worked: ' . implode( ', ', $leftovers() ) );

	/* 9. The placeholder generation 1 returned with an error is never memoized (#11421). */
	$reset_markers();
	$no_memo = $request( $healthy );
	$broken  = $faulty();
	$broken->broken = true;
	$inject_wrapper_client( $no_memo, $broken );
	$object_generation = $cache_class->getMethod( 'get_object_generation' );
	$group_generation  = $cache_class->getMethod( 'get_group_generation' );
	$assert( null === $object_generation->invoke( $no_memo ), 'A failed object generation read was not reported as unavailable.' );
	$assert( null === $cache_class->getProperty( 'object_generation' )->getValue( $no_memo ), 'The placeholder object generation was memoized after an error.' );
	$assert( null === $group_generation->invoke( $no_memo, 'posts' ), 'A failed group generation read was not reported as unavailable.' );
	$assert( ! array_key_exists( 'posts', $cache_class->getProperty( 'group_generations' )->getValue( $no_memo ) ), 'The placeholder group generation was memoized after an error.' );

	/* 10. An error reply proves Redis answered: it fails that request only and never trips the breaker. */
	$request( $healthy )->set( 'elsewhere', 'in-redis', 'posts' );
	$replies = array(
		'set'   => array(
			"OOM command not allowed when used memory > 'maxmemory'.",
			static fn ( WP_Object_Cache $cache ) => $cache->set( 'large', str_repeat( 'x', 64 ), 'posts' ),
		),
		'eval'  => array(
			"READONLY You can't write against a read only replica.",
			static fn ( WP_Object_Cache $cache ) => $cache->flush_group( 'posts' ),
		),
		'watch' => array(
			'ERR WATCH inside MULTI is not allowed',
			static fn ( WP_Object_Cache $cache ) => $cache->incr( 'elsewhere-count', 1, 'posts' ),
		),
	);
	foreach ( $replies as $method => [ $reply, $operation ] ) {
		$reset_markers();
		$answered = $request( $healthy );
		$client   = $faulty();
		$inject_wrapper_client( $answered, $client );
		$answered->get( 'warm-up', 'posts' );
		$client->replies[ $method ] = $reply;
		$operation( $answered );
		$assert( array() !== $answered->errors, 'The ' . $method . ' error reply was not recorded.' );
		$assert( ! $exists( $breaker ), 'The ' . $method . ' error reply tripped the breaker.' );
		$assert( $exists( $marker ), 'A change refused with the ' . $method . ' error reply left no dirty marker.' );
		unset( $client->replies[ $method ] );
		$assert( false === $answered->get( 'elsewhere', 'posts' ), 'The request kept using Redis after the ' . $method . ' error reply.' );
	}
	$expire_breaker();
	$request( $healthy )->get( 'anything', 'posts' );

	/* 11. A request connecting during another one's invalidation runs uncached (R1). */
	$reset_markers();
	$request( $healthy )->set( 'opt', 'old', 'options' );
	$request( $down )->set( 'opt', 'new', 'options' );
	$expire_breaker();

	$winner     = $request( $healthy );
	$hook       = $faulty();
	$loser      = null;
	$loser_saw  = 'never ran';
	$hook->before_eval = static function () use ( &$loser, &$loser_saw, $request, $healthy ): void {
		// The winner holds the claim and its bump is not sent yet.
		$loser     = $request( $healthy );
		$loser_saw = $loser->get( 'opt', 'options' );
		$loser->set( 'during-claim', 'v', 'posts' );
	};
	$inject_wrapper_client( $winner, $hook );
	$assert( false === $winner->get( 'opt', 'options' ), 'The winner read the old namespace.' );
	$assert( false === $loser_saw, 'A request connecting during another request\'s invalidation read the old namespace: ' . var_export( $loser_saw, true ) );
	$assert( $loser instanceof WP_Object_Cache && in_array( $busy, $loser->errors, true ), 'The request that ran uncached during the invalidation did not record why.' );
	$assert( ! $breaker_open(), 'Running uncached during another request\'s invalidation tripped the breaker.' );
	$assert( $exists( $marker ), 'A write made while another request invalidated left no dirty marker.' );
	$assert( ! $exists( $claim ), 'The winner did not release its claim.' );
	$request( $healthy )->get( 'anything', 'posts' );
	$assert( ! $exists( $marker ) && array() === $leftovers(), 'The marker written during the claim was not cleared afterwards.' );

	/* 12. A fresh claim makes requests wait it out uncached; an abandoned one is taken over. */
	$reset_markers();
	$request( $healthy )->set( 'claimed', 'in-redis', 'posts' );
	file_put_contents( $claim, 'another-request' );
	$generation_before = (int) $probe->get( $object_generation_key );
	$waiting           = $request( $healthy );
	$assert( false === $waiting->get( 'claimed', 'posts' ), 'A fresh claim did not make the request run uncached.' );
	$assert( in_array( $busy, $waiting->errors, true ), 'The request that ran uncached because of a fresh claim did not record why.' );
	$assert( 'another-request' === file_get_contents( $claim ), 'A request changed another request\'s fresh claim.' );
	$assert( $generation_before === (int) $probe->get( $object_generation_key ), 'A request bumped the generation behind another request\'s fresh claim.' );
	$assert( ! $breaker_open() && ! $exists( $marker ), 'A request that waited out a fresh claim tripped the breaker or marked the cache dirty.' );

	touch( $claim, time() - 60 );
	clearstatcache( true, $claim );
	$taker = $request( $healthy );
	$assert( false === $taker->get( 'claimed', 'posts' ), 'An abandoned claim was not taken over: the old namespace stayed readable.' );
	$assert( (int) $probe->get( $object_generation_key ) > $generation_before, 'Taking an abandoned claim over did not bump the generation.' );
	$assert( ! in_array( $busy, $taker->errors, true ) && array() === $leftovers(), 'Taking an abandoned claim over left it behind: ' . implode( ', ', $leftovers() ) );

	// A claimer whose claim was taken over must leave the new claim alone.
	$request( $down )->delete( 'claimed', 'posts' );
	$expire_breaker();
	$overtaken = $request( $healthy );
	$hook      = $faulty();
	$hook->before_eval = static function () use ( $claim ): void {
		file_put_contents( $claim, 'taken-over' );
	};
	$inject_wrapper_client( $overtaken, $hook );
	$overtaken->get( 'anything', 'posts' );
	$assert( null === $hook->before_eval, 'The overtaken request never invalidated.' );
	$assert( 'taken-over' === @file_get_contents( $claim ), 'A request whose claim was taken over released the new claim.' );
	$reset_markers();

	/* 13. A value that can never be serialized does not leave the previous Redis copy readable. */
	$request( $healthy )->set( 'callback', 'old', 'default' );
	$closure = $request( $healthy );
	$assert( true === $closure->set( 'callback', array( 'fn' => static function (): void {} ), 'default' ), 'set() of an unserializable value stopped failing open.' );
	$assert( is_array( $closure->get( 'callback', 'default' ) ), 'The request lost its own unserializable value from L1.' );
	$assert( false === $request( $healthy )->get( 'callback', 'default' ), 'After set() of an unserializable value, a later request read the previous Redis copy.' );
	$assert( ! $exists( $marker ), 'Removing the previous Redis copy marked the cache dirty.' );
	$request( $down )->set( 'callback', array( 'fn' => static function (): void {} ), 'default' );
	$assert( $exists( $marker ), 'An unserializable value whose previous copy could not be removed left no dirty marker.' );
	$reset_markers();

	/* 15. A marker this request cannot claim invalidates once, then Redis is used again. */
	$request( $healthy )->set( 'stuck', 'before', 'posts' );
	file_put_contents( $marker, 'left-by-another-user' );
	chmod( WP_CONTENT_DIR, 0500 );
	try {
		$generation_before = (int) $probe->get( $object_generation_key );
		$first             = $request( $healthy );
		$assert( false === $first->get( 'stuck', 'posts' ), 'A marker that could not be claimed left the old namespace readable.' );
		$assert( (int) $probe->get( $object_generation_key ) > $generation_before, 'A marker that could not be claimed did not invalidate the namespace.' );
		$first->set( 'stuck', 'after', 'posts' );
		$generation_after = (int) $probe->get( $object_generation_key );
		$second           = $request( $healthy );
		$assert( 'after' === $second->get( 'stuck', 'posts' ), 'After an unclaimable marker was handled once, later requests still ran without Redis.' );
		$assert( $generation_after === (int) $probe->get( $object_generation_key ), 'An unclaimable marker invalidated the namespace again on every request.' );
		$assert( array() === array_intersect( array( $busy ), $second->errors ), 'A request after an unclaimable marker reported a busy claim.' );
	} finally {
		chmod( WP_CONTENT_DIR, 0700 );
	}
	$reset_markers();

	// A claim released while a request waits for it does not send that request uncached.
	$request( $healthy )->set( 'released', 'in-redis', 'posts' );
	file_put_contents( $claim, 'another-request' );
	// The releaser signals once it runs, then drops the claim 20 ms later: well
	// inside the request's 50 ms wait, whatever PHP's start-up time is.
	$ready    = WP_CONTENT_DIR . '/releaser-ready';
	$releaser = proc_open(
		array( PHP_BINARY, '-r', 'touch( ' . var_export( $ready, true ) . ' ); usleep( 20000 ); @unlink( ' . var_export( $claim, true ) . ' );' ),
		array(),
		$releaser_pipes
	);
	for ( $spin = 0; $spin < 400 && ! $exists( $ready ); $spin++ ) {
		usleep( 5000 );
	}
	@unlink( $ready );
	$patient = $request( $healthy );
	$assert( 'in-redis' === $patient->get( 'released', 'posts' ), 'A request ran uncached although the claim it found was released within the wait.' );
	$assert( ! in_array( $busy, $patient->errors, true ) && ! $exists( $marker ), 'A request whose claim was released in time still ran uncached or marked the cache dirty.' );
	if ( is_resource( $releaser ) ) {
		proc_close( $releaser );
	}
	$reset_markers();

	/* 14. A damaged generated config falls back to core's runtime cache instead of a fatal error. */
	$load_dropin = static function ( string $config_source ): array {
		$directory = sys_get_temp_dir() . '/simple-redis-cache-loader-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700 );
		file_put_contents( $directory . '/simple-redis-cache-config.php', $config_source );
		$code    = 'define( "ABSPATH", ' . var_export( ABSPATH, true ) . ' );'
			. 'define( "WP_CONTENT_DIR", ' . var_export( $directory, true ) . ' );'
			. 'require ' . var_export( dirname( __DIR__ ) . '/dropins/object-cache-loader.php', true ) . ';'
			. 'echo function_exists( "wp_cache_init" ) ? "external" : "core";';
		$process = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		$output = is_resource( $process ) ? (string) stream_get_contents( $pipes[1] ) : '';
		$errors = is_resource( $process ) ? (string) stream_get_contents( $pipes[2] ) : 'proc_open failed';
		$status = is_resource( $process ) ? proc_close( $process ) : -1;
		unlink( $directory . '/simple-redis-cache-config.php' );
		rmdir( $directory );
		return array( $status, trim( $output ), trim( $errors ) );
	};
	[ $status, $output, $errors ] = $load_dropin( "<?php\nreturn array( 'object' => array( 'enabled' => true ) );\n" );
	$assert( 0 === $status && 'external' === $output, 'The object-cache drop-in did not load with a valid config: ' . $output . ' ' . $errors );
	[ $status, $output, $errors ] = $load_dropin( "<?php\nreturn array( 'object' => array( 'enabled' => true ), 'site' => array( 'identity' => new stdClass() ) );\n" );
	$assert( 0 === $status && 'core' === $output, 'A damaged generated config broke the object-cache drop-in: ' . $output . ' ' . $errors );
} finally {
	// Only this run's keys: everything lives under the random WP_CACHE_KEY_SALT.
	$prefix   = Simple_Redis_Cache_Early_Config::prefix( $healthy );
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

echo "OK: lost persistent changes mark the cache dirty, only connection failures trip the breaker, no request reads a namespace being invalidated, and a damaged config falls back to core.\n";
