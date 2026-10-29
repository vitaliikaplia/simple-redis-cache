<?php
/**
 * Cross-request circuit breaker of Simple_Redis_Cache_Redis, plus the 'list'
 * page-content resource type. Only a failed connection trips the breaker,
 * never an error reply; WP-CLI ignores it; at expiry a single probe runs while
 * the breaker stays re-armed.
 *
 * A failing Redis is simulated with a second config aimed at a closed local
 * port, a hung one with a local listener that never answers, and a read
 * timeout with a blocking command on a key nobody writes; the shared Redis
 * server is never stopped or reconfigured. Markers are written to a private
 * temporary WP_CONTENT_DIR that is removed at the end. WP-CLI and concurrent
 * probes run in child processes.
 *
 * Usage: php tests/redis-breaker.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

$test_content = sys_get_temp_dir() . '/simple-redis-cache-breaker-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $test_content, 0700 ) && ! is_dir( $test_content ) ) {
	throw new RuntimeException( 'Could not create the breaker test directory.' );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'WP_CACHE_KEY_SALT', 'src-breaker-test-' . bin2hex( random_bytes( 8 ) ) );

/** Translation stub that shows whether a message went through __(). */
function __( string $text, string $domain = 'default' ): string {
	return '[' . $domain . '] ' . $text;
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-redis.php';

$remove_test_content = static function () use ( $test_content ): void {
	if ( ! is_dir( $test_content ) ) {
		return;
	}
	@chmod( $test_content, 0700 );
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

// A port that was free a moment ago refuses connections at once.
$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
if ( false === $listener ) {
	$remove_test_content();
	fwrite( STDERR, 'SKIP: could not reserve a closed port: ' . $errstr . "\n" );
	exit( 0 );
}
$closed_port = (int) substr( (string) strrchr( (string) stream_socket_get_name( $listener, false ), ':' ), 1 );
fclose( $listener );

/** A connection whose GET can be made to hit the read timeout for real. */
final class SRC_Test_Slow_Redis extends Redis {
	public bool $slow = false;

	public function get( string $key ): mixed {
		return $this->slow ? $this->rawCommand( 'BLPOP', $key . ':never', '1' ) : parent::get( $key );
	}
}

$healthy                          = Simple_Redis_Cache_Early_Config::defaults();
$healthy['redis']['host']         = $host;
$healthy['redis']['port']         = $port;
$healthy['redis']['timeout']      = 0.5;
$healthy['redis']['read_timeout'] = 0.5;
$broken                           = $healthy;
$broken['redis']['host']          = '127.0.0.1';
$broken['redis']['port']          = $closed_port;

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$breaker = Simple_Redis_Cache_Redis::breaker_path();
$skipped = 'Redis was skipped after a recent connection failure.';
$mtime   = static function () use ( $breaker ): ?int {
	clearstatcache( true, $breaker );
	return is_file( $breaker ) ? (int) filemtime( $breaker ) : null;
};
/** Backdate the breaker marker and return the modification time it now has. */
$set_age = static function ( int $seconds ) use ( $breaker ): int {
	$stamp = time() - $seconds;
	touch( $breaker, $stamp );
	clearstatcache( true, $breaker );
	return $stamp;
};
$fresh = static function () use ( $mtime ): bool {
	$stamp = $mtime();
	return null !== $stamp && abs( time() - $stamp ) <= 2;
};

/** Start PHP code in a child process that shares this run's WP_CONTENT_DIR. */
$spawn = static function ( string $code, bool $wp_cli = false ): array {
	$bootstrap = 'define( "ABSPATH", ' . var_export( ABSPATH, true ) . ' );'
		. 'define( "WP_CONTENT_DIR", ' . var_export( WP_CONTENT_DIR, true ) . ' );'
		. ( $wp_cli ? 'define( "WP_CLI", true );' : '' )
		. 'require ' . var_export( dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php', true ) . ';'
		. 'require ' . var_export( dirname( __DIR__ ) . '/includes/class-simple-redis-cache-redis.php', true ) . ';';
	$process   = proc_open(
		array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $bootstrap . $code ),
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not start a child process.' );
	}
	return array( $process, $pipes );
};
/** @return array{0: int, 1: string, 2: string} Exit status, output, and errors. */
$finish = static function ( $process, array $pipes ): array {
	$output = (string) stream_get_contents( $pipes[1] );
	$errors = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array( proc_close( $process ), trim( $output ), trim( $errors ) );
};

$versions_key = Simple_Redis_Cache_Early_Config::page_content_versions_key( $healthy );

try {
	$assert( WP_CONTENT_DIR . '/simple-redis-cache-breaker' === $breaker, 'breaker_path() is not the documented marker under WP_CONTENT_DIR.' );

	/* 1. Existing callers (admin, diagnostics) never touch the breaker. */
	$plain = new Simple_Redis_Cache_Redis( $broken );
	$assert( false === $plain->connect(), 'A closed port reported a successful connection.' );
	$plain->trip_breaker();
	$assert( null === $mtime(), 'A Redis wrapper without the breaker wrote the breaker marker.' );

	/* 2. A breaker-aware connection failure trips the breaker. */
	$first = new Simple_Redis_Cache_Redis( $broken, true );
	$assert( false === $first->connect(), 'A closed port reported a successful connection.' );
	$assert( $skipped !== $first->error(), 'A real connection failure was reported as a breaker skip.' );
	$tripped_at = $mtime();
	$assert( null !== $tripped_at && abs( time() - $tripped_at ) <= 2, 'A breaker-aware connection failure did not trip the breaker.' );
	$assert( array() === ( glob( WP_CONTENT_DIR . '/*.tmp' ) ?: array() ), 'Tripping the breaker left a temporary file behind.' );

	/* 3. While the breaker is open, breaker-aware callers skip even a healthy Redis. */
	$skip_stamp = $set_age( 5 );
	$skipping   = new Simple_Redis_Cache_Redis( $healthy, true );
	$assert( false === $skipping->connect(), 'An open breaker did not skip the connection.' );
	$assert( $skipped === $skipping->error(), 'A skipped connection did not report the breaker message.' );
	$assert( null === $skipping->client(), 'A skipped connection still returned a client.' );
	$assert( 1 === $skipping->generation( 'object' ) && $skipped === $skipping->error(), 'A later call on a skipped instance stopped reporting the skip.' );
	$assert( '[simple-redis-cache] ' . $skipped === $skipping->display_error(), 'display_error() does not translate the breaker message.' );

	// A skipped request must never extend the breaker, or Redis could stay locked out.
	$skipping->trip_breaker();
	$assert( $skip_stamp === $mtime(), 'trip_breaker() on a skipped instance extended the breaker.' );

	/* 4. Admin and diagnostics wrappers connect while the breaker is open. */
	$admin = new Simple_Redis_Cache_Redis( $healthy );
	$assert( true === $admin->ping(), 'A wrapper without the breaker was blocked by it.' );

	/* 5. The breaker expires after 15 seconds; a clock jump cannot hold it forever. */
	$set_age( 16 );
	$recovered = new Simple_Redis_Cache_Redis( $healthy, true );
	$assert( true === $recovered->ping(), 'An expired breaker still skipped Redis.' );

	touch( $breaker, time() + 3600 );
	clearstatcache( true, $breaker );
	$future = new Simple_Redis_Cache_Redis( $healthy, true );
	$assert( true === $future->ping(), 'A breaker marker from the far future locked Redis out.' );

	$set_age( 14 );
	$edge = new Simple_Redis_Cache_Redis( $healthy, true );
	$assert( false === $edge->connect(), 'A breaker tripped 14 seconds ago did not skip Redis.' );

	/* 6. Callers report runtime failures; each instance writes at most once. */
	$set_age( 100 );
	$runtime = new Simple_Redis_Cache_Redis( $healthy, true );
	$assert( true === $runtime->ping(), 'An old breaker marker blocked the connection.' );
	$runtime->trip_breaker();
	$assert( abs( time() - (int) $mtime() ) <= 2, 'trip_breaker() did not refresh the breaker.' );
	$old_stamp = $set_age( 100 );
	$runtime->trip_breaker();
	$assert( $old_stamp === $mtime(), 'trip_breaker() rewrote the marker on every call.' );

	/* 7. An unwritable directory or a throwing error handler never escapes. */
	unlink( $breaker );
	chmod( $test_content, 0500 );
	if ( ! is_writable( $test_content ) ) {
		set_error_handler(
			static function ( int $severity, string $message ): bool {
				throw new ErrorException( $message, 0, $severity );
			}
		);
		try {
			$readonly = new Simple_Redis_Cache_Redis( $broken, true );
			$assert( false === $readonly->connect(), 'A closed port reported a successful connection.' );
			$connected = new Simple_Redis_Cache_Redis( $healthy, true );
			$connected->ping();
			$connected->trip_breaker();
		} catch ( Throwable $throwable ) {
			$assert( false, 'The breaker let an exception escape: ' . $throwable->getMessage() );
		} finally {
			restore_error_handler();
			chmod( $test_content, 0700 );
		}
		$assert( null === $mtime(), 'The breaker marker appeared in an unwritable directory.' );
	} else {
		chmod( $test_content, 0700 );
	}

	/* 8. 'list' is a page-content resource type next to 'post' and 'term'. */
	$probe->del( $versions_key );
	$pages = new Simple_Redis_Cache_Redis( $healthy );
	$list  = $pages->page_content_version( 'list', 1 );
	$assert( is_string( $list ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $list ), 'page_content_version() rejected the list resource type.' );
	$assert( $list === ( new Simple_Redis_Cache_Redis( $healthy ) )->page_content_version( 'list', 1 ), 'The list content version was not stable.' );
	$post = $pages->page_content_version( 'post', 1 );
	$assert( 1 === $pages->bump_page_content_versions( array( 'list' => array( 1 ) ) ), 'bump_page_content_versions() did not count the list field.' );
	$assert( $list !== $pages->page_content_version( 'list', 1 ), 'Bumping list:1 kept the old content version.' );
	$assert( $post === $pages->page_content_version( 'post', 1 ), 'Bumping list:1 changed post:1 as well.' );
	$assert( false === $pages->page_content_version( 'list', 0 ), 'A non-positive list ID was accepted.' );
	$assert( false === $pages->page_content_version( 'lists', 1 ), 'An unknown resource type was accepted.' );

	/* 9. Only a failed connection trips the breaker: an error reply proves Redis answered. */
	$redis_class = new ReflectionClass( Simple_Redis_Cache_Redis::class );
	$never_key   = WP_CACHE_KEY_SALT . ':never';
	$slow        = $healthy;
	$slow['redis']['read_timeout'] = 0.2;

	@unlink( $breaker );
	$answered = new Simple_Redis_Cache_Redis( $healthy, true );
	$assert( true === $answered->ping(), 'The error-reply check could not connect.' );
	try {
		$answered->client()->eval( 'return redis.error_reply(ARGV[1])', array( "READONLY You can't write against a read only replica." ), 0 );
	} catch ( RedisException ) {
		// PhpRedis throws for READONLY, as it does for OOM.
	}
	$answered->trip_breaker();
	$assert( null === $mtime(), 'An error reply tripped the breaker.' );

	@unlink( $breaker );
	$timed_out = new Simple_Redis_Cache_Redis( $slow, true );
	$assert( true === $timed_out->ping(), 'The read-timeout check could not connect.' );
	try {
		$timed_out->client()?->rawCommand( 'BLPOP', $never_key, '1' );
		$assert( false, 'BLPOP outlived a 0.2 second read timeout.' );
	} catch ( RedisException ) {
		// The read timeout this check needs.
	}
	$timed_out->trip_breaker();
	$assert( $fresh(), 'A read timeout did not trip the breaker.' );

	// An earlier error reply must not make a later read timeout look answered.
	unlink( $breaker );
	$stale_client = new SRC_Test_Slow_Redis();
	$stale_client->connect( $host, $port, 0.5, null, 0, 0.2 );
	$stale = new Simple_Redis_Cache_Redis( $slow, true );
	$redis_class->getProperty( 'client' )->setValue( $stale, $stale_client );
	$redis_class->getProperty( 'attempted' )->setValue( $stale, true );
	$stale_client->eval( 'return redis.error_reply(ARGV[1])', array( 'ERR an earlier error reply' ), 0 );
	$stale_client->slow = true;
	$stale->generation( 'object' );
	$assert( null !== $stale->error(), 'The slow generation read did not fail.' );
	$stale->trip_breaker();
	$assert( $fresh(), 'An earlier error reply made a later read timeout look like an answer.' );

	/* 10. WP-CLI neither honours nor trips the breaker. */
	$both_ways = '$open = ( new Simple_Redis_Cache_Redis( ' . var_export( $healthy, true ) . ', true ) )->ping();'
		. '$down = new Simple_Redis_Cache_Redis( ' . var_export( $broken, true ) . ', true );'
		. '$refused = ! $down->connect();'
		. '$down->trip_breaker();'
		. 'echo json_encode( array( $open, $refused ) );';
	$tripped_at = $set_age( 5 );
	[ $status, $output, $errors ] = $finish( ...$spawn( $both_ways ) );
	$assert( 0 === $status && '[false,true]' === $output, 'Outside WP-CLI, a child request did not honour the open breaker: ' . $output . ' ' . $errors );
	[ $status, $output, $errors ] = $finish( ...$spawn( $both_ways, true ) );
	$assert( 0 === $status && '[true,true]' === $output, 'WP-CLI waited out the open breaker: ' . $output . ' ' . $errors );
	$assert( $tripped_at === $mtime(), 'WP-CLI tripped, extended, or re-armed the breaker.' );
	unlink( $breaker );
	[ $status, $output, $errors ] = $finish( ...$spawn( $both_ways, true ) );
	$assert( 0 === $status && null === $mtime(), 'A connection failure under WP-CLI tripped the breaker: ' . $errors );

	/* 11. At expiry one request probes: the breaker stays re-armed meanwhile and closes once Redis answers. */
	$silent = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	$assert( false !== $silent, 'Could not open a listener that never answers: ' . $errstr );
	if ( false !== $silent ) {
		$hung                          = $healthy;
		$hung['redis']['host']         = '127.0.0.1';
		$hung['redis']['port']         = (int) substr( (string) strrchr( (string) stream_socket_get_name( $silent, false ), ':' ), 1 );
		$hung['redis']['read_timeout'] = 2.0;

		$expired             = $set_age( 16 );
		[ $process, $pipes ] = $spawn( '( new Simple_Redis_Cache_Redis( ' . var_export( $hung, true ) . ', true ) )->connect();' );
		$deadline            = microtime( true ) + 1.5;
		while ( $expired === $mtime() && microtime( true ) < $deadline ) {
			usleep( 20000 );
		}
		// Re-armed for the probe's budget, not tripped afresh, while the probe still waits.
		$rearmed = $mtime();
		$assert(
			null !== $rearmed && time() - $rearmed >= 5 && time() - $rearmed < 15 && proc_get_status( $process )['running'],
			'The probing request did not re-arm the expired breaker before connecting.'
		);

		$started    = microtime( true );
		$concurrent = new Simple_Redis_Cache_Redis( $healthy, true );
		$assert( false === $concurrent->connect() && $skipped === $concurrent->error(), 'A request arriving during the probe did not keep skipping Redis.' );
		$assert( microtime( true ) - $started < 0.5, 'A request arriving during the probe waited on Redis.' );

		[ $status, , $errors ] = $finish( $process, $pipes );
		$assert( 0 === $status, 'The probing child failed: ' . $errors );
		$assert( $fresh(), 'A probe that got no answer did not trip the breaker again.' );
		fclose( $silent );
	}

	$set_age( 16 );
	$prober = new Simple_Redis_Cache_Redis( $healthy, true );
	$assert( true === $prober->ping(), 'A probe of a healthy Redis failed.' );
	$assert( null === $mtime(), 'A successful probe left the breaker in place.' );
	$assert( true === ( new Simple_Redis_Cache_Redis( $healthy, true ) )->ping(), 'The breaker still blocked after a successful probe.' );

	// A marker that can no longer be replaced or removed must not re-arm at every expiry.
	$locked_stamp = $set_age( 16 );
	chmod( $test_content, 0500 );
	if ( ! is_writable( $test_content ) ) {
		$first  = ( new Simple_Redis_Cache_Redis( $healthy, true ) )->ping();
		$second = ( new Simple_Redis_Cache_Redis( $healthy, true ) )->ping();
		$assert( $first && $second, 'An expired breaker in a read-only directory kept blocking Redis.' );
		$assert( $locked_stamp === $mtime(), 'An expired breaker in a read-only directory was re-armed.' );
	}
	chmod( $test_content, 0700 );
} finally {
	$probe->del( $versions_key );
	$probe->close();
	$remove_test_content();
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: the breaker trips on connection failures only, skips, expires behind a single probe, ignores admin wrappers, WP-CLI and write failures, and 'list' content versions work.\n";
