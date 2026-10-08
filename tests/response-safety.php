<?php
/**
 * Dependency-light regression checks for three response-safety rules:
 *
 * 1. a session-specific logged-in HIT never advertises itself to shared caches;
 * 2. a discarded output buffer is never stored as a cached page;
 * 3. the transient database mirror is never deleted behind a failed Redis bump.
 *
 * Run directly with: php tests/response-safety.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) );

$plugin_dir = dirname( __DIR__ );
$failures   = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

/* -------------------------------------------------------------------------
 * 1. Logged-in HIT must not carry s-maxage.
 *
 * serve() exits, so the guard is verified at its call sites: the anonymous path
 * forwards the configured value, the validated logged-in path forwards zero.
 * ---------------------------------------------------------------------- */

$loader = (string) file_get_contents( $plugin_dir . '/dropins/advanced-cache-loader.php' );

preg_match_all( '/self::serve\(\s*(.+?)\);/s', $loader, $serve_calls );
$assert( 2 === count( $serve_calls[1] ), 'Expected exactly two serve() call sites in the advanced-cache loader.' );

$anonymous_call = $serve_calls[1][0] ?? '';
$logged_in_call = $serve_calls[1][1] ?? '';

$assert(
	str_contains( $anonymous_call, "shared_max_age" ),
	'The anonymous HIT no longer forwards page.shared_max_age to serve().'
);
$assert(
	! str_contains( $logged_in_call, 'shared_max_age' ) && (bool) preg_match( '/,\s*0\s*,\s*true\s*$/', trim( $logged_in_call ) ),
	'The validated logged-in HIT must pass 0 and the private flag to serve(), so a session-specific response is never offered to shared caches.'
);

// The private branch must state a policy of its own when the stored payload has
// none, rather than putting personalized HTML on the wire with no directive.
$assert(
	(bool) preg_match( "/\\\$private_response[\\s\\S]{0,400}Cache-Control: private, no-store/", $loader ),
	'A private HIT no longer falls back to an explicit Cache-Control: private, no-store.'
);
$assert(
	(bool) preg_match( "/\\\$private_response[\\s\\S]{0,400}seen_headers\\['cache-control'\\]/", $loader ),
	'The private fallback no longer respects a Cache-Control already stored in the payload.'
);

/* -------------------------------------------------------------------------
 * 2. A discarded buffer must not be stored.
 * ---------------------------------------------------------------------- */

/**
 * Stand-in for the early loader. Page_Capture talks to it for lock ownership, and
 * the real one executes a full request at file scope.
 */
final class Simple_Redis_Cache_Advanced_Cache_Loader {
	/** @var array<string, true> */
	public static array $held = array();
	/** @var string[] */
	public static array $released = array();
	public static bool $decline = false;

	public static function hold_lock_for_capture( string $lock_key ): void {
		if ( '' !== $lock_key ) {
			self::$held[ $lock_key ] = true;
		}
	}

	public static function finish_capture_lock( string $lock_key ): void {
		unset( self::$held[ $lock_key ] );
	}

	/** @param array<string, mixed> $context */
	public static function release_lock( array $context ): bool {
		$key = (string) ( $context['lock_key'] ?? '' );
		if ( '' !== $key && isset( self::$held[ $key ] ) ) {
			return false;
		}

		self::$released[] = $key;
		return true;
	}
}

require_once $plugin_dir . '/includes/class-simple-redis-cache-page-capture.php';

$capture_class = new ReflectionClass( Simple_Redis_Cache_Page_Capture::class );
$finalized     = $capture_class->getProperty( 'finalized' );

/** Build a capture whose maybe_store() would throw if it were ever reached. */
$new_capture = static function ( bool $released = true ) use ( $capture_class ): Simple_Redis_Cache_Page_Capture {
	$capture = $capture_class->newInstanceWithoutConstructor();
	// No redis/cache_key in the context: reaching maybe_store() would still be a
	// no-op, so the flags below are what actually prove the early return.
	$capture_class->getProperty( 'context' )->setValue( $capture, array( 'lock_key' => 'lock:test' ) );
	$capture_class->getProperty( 'page_config' )->setValue( $capture, array() );
	$capture_class->getProperty( 'debug_header' )->setValue( $capture, false );
	$capture_class->getProperty( 'released' )->setValue( $capture, $released );
	return $capture;
};

$body = '<!doctype html><html><body>page</body></html>';

// Normal termination: ob_end_flush() reports FINAL|START (9).
$flushed = $new_capture();
$assert( $body === $flushed->handle_output( $body, PHP_OUTPUT_HANDLER_FINAL | PHP_OUTPUT_HANDLER_START ), 'handle_output() altered the response body on the flush path.' );
$assert( true === $finalized->getValue( $flushed ), 'A flushed final buffer was not treated as the storable response.' );

// Discard: ob_end_clean() reports FINAL|CLEAN|START (11).
$discarded = $new_capture();
$assert( $body === $discarded->handle_output( $body, PHP_OUTPUT_HANDLER_FINAL | PHP_OUTPUT_HANDLER_CLEAN | PHP_OUTPUT_HANDLER_START ), 'handle_output() altered the response body on the discard path.' );
$assert( false === $finalized->getValue( $discarded ), 'A discarded output buffer was accepted as a cacheable response.' );

// Intermediate chunks must keep passing through untouched.
$streaming = $new_capture();
$assert( $body === $streaming->handle_output( $body, PHP_OUTPUT_HANDLER_WRITE ), 'handle_output() altered a non-final chunk.' );
$assert( false === $finalized->getValue( $streaming ), 'A non-final chunk was treated as the final response.' );

/* -------------------------------------------------------------------------
 * 2b. A shutdown release that runs before the buffer is finalized must not
 *     drop the lock the request still needs in order to store its payload.
 * ---------------------------------------------------------------------- */

Simple_Redis_Cache_Advanced_Cache_Loader::$held     = array();
Simple_Redis_Cache_Advanced_Cache_Loader::$released = array();

$pending  = $new_capture( false );
$released = $capture_class->getProperty( 'released' );
Simple_Redis_Cache_Advanced_Cache_Loader::hold_lock_for_capture( 'lock:test' );

// This is the shutdown callback firing early, before the output handler ran.
$pending->release_lock();
$assert( array() === Simple_Redis_Cache_Advanced_Cache_Loader::$released, 'A shutdown release dropped the lock while the capture buffer was still open.' );
$assert( false === $released->getValue( $pending ), 'A declined release was recorded as done, which would block the real release.' );

// Now the buffer finalizes: the claim is dropped and the release goes through.
$pending->handle_output( $body, PHP_OUTPUT_HANDLER_FINAL | PHP_OUTPUT_HANDLER_START );
$assert( ! isset( Simple_Redis_Cache_Advanced_Cache_Loader::$held['lock:test'] ), 'Finalizing the buffer did not drop the capture lock claim.' );
$assert( array( 'lock:test' ) === Simple_Redis_Cache_Advanced_Cache_Loader::$released, 'The lock was not released once the payload had been written.' );

// A discarded buffer must also give the lock back rather than leaving it claimed.
Simple_Redis_Cache_Advanced_Cache_Loader::$held     = array();
Simple_Redis_Cache_Advanced_Cache_Loader::$released = array();
$dropped = $new_capture( false );
Simple_Redis_Cache_Advanced_Cache_Loader::hold_lock_for_capture( 'lock:test' );
$dropped->handle_output( $body, PHP_OUTPUT_HANDLER_FINAL | PHP_OUTPUT_HANDLER_CLEAN | PHP_OUTPUT_HANDLER_START );
$assert( ! isset( Simple_Redis_Cache_Advanced_Cache_Loader::$held['lock:test'] ), 'A discarded buffer kept its lock claim.' );
$assert( array( 'lock:test' ) === Simple_Redis_Cache_Advanced_Cache_Loader::$released, 'A discarded buffer did not release its lock.' );

/* -------------------------------------------------------------------------
 * 3. No database deletion behind a failed Redis generation bump.
 * ---------------------------------------------------------------------- */

final class Simple_Redis_Cache_Test_Wpdb {
	public string $options = 'wp_options';
	/** @var string[] */
	public array $queries = array();

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		return 1;
	}
}

require_once $plugin_dir . '/includes/class-simple-redis-cache-early-config.php';
require_once $plugin_dir . '/includes/class-simple-redis-cache-redis.php';
require_once $plugin_dir . '/dropins/class-simple-redis-cache-object-cache.php';

$cache_class = new ReflectionClass( WP_Object_Cache::class );

/**
 * @param bool $redis_down Whether the Redis generation bump should fail.
 */
$new_cache = static function ( bool $redis_down, bool $mirror = true ) use ( $cache_class ): WP_Object_Cache {
	$cache  = $cache_class->newInstanceWithoutConstructor();
	$config = Simple_Redis_Cache_Early_Config::defaults();

	foreach (
		array(
			'config'                  => $config,
			'object_config'           => $config['object'],
			'object_enabled'          => true,
			'persistent_reads_enabled' => true,
			'transient_db_fallback'   => $mirror,
			'max_ttl'                 => 3600,
			'prefix'                  => 'test:src:',
			'redis'                   => new Simple_Redis_Cache_Redis( $config ),
			// A latched failure makes client() return null, so bump_object_generation()
			// and bump_group_generation() fail without touching a real server.
			'redis_failed'            => $redis_down,
			'redis_attempted'         => true,
			'group_generations'       => $redis_down ? array() : array(),
		) as $property => $value
	) {
		$cache_class->getProperty( $property )->setValue( $cache, $value );
	}

	return $cache;
};

// Redis unreachable: rows must survive and the call must report failure.
$GLOBALS['wpdb'] = new Simple_Redis_Cache_Test_Wpdb();
$down            = $new_cache( true );
$assert( false === $down->flush(), 'flush() reported success while the Redis generation bump had failed.' );
$assert( array() === $GLOBALS['wpdb']->queries, 'flush() deleted database transients behind a failed Redis generation bump.' );

$GLOBALS['wpdb'] = new Simple_Redis_Cache_Test_Wpdb();
$down_group      = $new_cache( true );
$assert( false === $down_group->flush_group( 'transient' ), 'flush_group() reported success while the Redis generation bump had failed.' );
$assert( array() === $GLOBALS['wpdb']->queries, 'flush_group() deleted database transients behind a failed Redis generation bump.' );

// Object cache disabled: nothing to bump, so the mirror is still cleared.
$GLOBALS['wpdb'] = new Simple_Redis_Cache_Test_Wpdb();
$disabled        = $new_cache( true );
$cache_class->getProperty( 'object_enabled' )->setValue( $disabled, false );
$assert( true === $disabled->flush(), 'flush() failed while the object cache was disabled.' );
$assert( 2 === count( $GLOBALS['wpdb']->queries ), 'flush() skipped the database mirror even though no Redis bump was required.' );
// esc_like() escapes the underscores, so match on the distinguishing token.
$first  = $GLOBALS['wpdb']->queries[0] ?? '';
$second = $GLOBALS['wpdb']->queries[1] ?? '';
$assert(
	str_contains( $first, 'transient' ) && ! str_contains( $first, 'site' )
	&& str_contains( $second, 'transient' ) && str_contains( $second, 'site' ),
	'flush() did not clear both mirrored transient groups.'
);
$assert(
	str_starts_with( $first, 'DELETE FROM wp_options' ) && str_starts_with( $second, 'DELETE FROM wp_options' ),
	'flush() issued something other than a scoped options DELETE.'
);

// flush_group() invalidates the cached options group before deleting rows, and a
// failed bump must abort the delete instead of reporting a half-done purge: a
// stale Redis copy of the options group would resurrect the deleted transients.
$GLOBALS['wpdb'] = new Simple_Redis_Cache_Test_Wpdb();
$options_group   = $new_cache( true );
$options_group->non_persistent_groups = array( 'transient' => true );
$assert( false === $options_group->flush_group( 'transient' ), 'flush_group() reported success while the options-group bump had failed.' );
$assert( array() === $GLOBALS['wpdb']->queries, 'flush_group() deleted transient rows before confirming the options group was invalidated.' );

// Without the mirror there is never a database write, failure or not.
$GLOBALS['wpdb'] = new Simple_Redis_Cache_Test_Wpdb();
$no_mirror       = $new_cache( true, false );
$no_mirror->flush();
$assert( array() === $GLOBALS['wpdb']->queries, 'flush() touched the database while the transient mirror was disabled.' );

/* -------------------------------------------------------------------------
 * 4. An unserializable value is a definite failure, not a Redis outage, and it
 *    must never be handed to update_option().
 * ---------------------------------------------------------------------- */

$GLOBALS['src_test_options'] = array();

function get_option( string $name, mixed $default_value = false ): mixed {
	return array_key_exists( $name, $GLOBALS['src_test_options'] ) ? $GLOBALS['src_test_options'][ $name ] : $default_value;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['src_test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	if ( ! array_key_exists( $name, $GLOBALS['src_test_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['src_test_options'][ $name ] );
	return true;
}

$GLOBALS['wpdb'] = new Simple_Redis_Cache_Test_Wpdb();
$closure_cache   = $new_cache( true );
$unserializable  = static function (): void {};

$assert( false === $closure_cache->add( 'closure', $unserializable ), 'add() reported success for a value that can never be serialized.' );

$GLOBALS['src_test_options'] = array();
$mirror_cache = $new_cache( true );
$mirror_cache->set( 'closure', $unserializable, 'transient' );
$assert( array() === $GLOBALS['src_test_options'], 'An unserializable value was passed to update_option(), which maybe_serialize() would throw on.' );

/* -------------------------------------------------------------------------
 * 5. replace() must consult and update the database mirror for a mirrored
 *    group that was configured as non-persistent.
 * ---------------------------------------------------------------------- */

$GLOBALS['src_test_options'] = array(
	'_transient_greeting'         => 'old',
	'_transient_timeout_greeting' => time() + 300,
);
$non_persistent = $new_cache( true );
$non_persistent->non_persistent_groups = array( 'transient' => true );

$assert( true === $non_persistent->replace( 'greeting', 'new', 'transient' ), 'replace() refused a value the database mirror proves exists.' );
$assert( 'new' === ( $GLOBALS['src_test_options']['_transient_greeting'] ?? null ), 'replace() left a stale value in the database mirror.' );

$GLOBALS['src_test_options'] = array();
$absent = $new_cache( true );
$absent->non_persistent_groups = array( 'transient' => true );
$assert( false === $absent->replace( 'missing', 'value', 'transient' ), 'replace() succeeded for a key that exists in no layer.' );

/* -------------------------------------------------------------------------
 * 6. error() must describe the call that just ran, not every call ever made.
 * ---------------------------------------------------------------------- */

$redis_class = new ReflectionClass( Simple_Redis_Cache_Redis::class );

/** Build a wrapper that reports a finished connection attempt without touching a server. */
$new_wrapper = static function ( ?string $connection_error, string $stale ) use ( $redis_class ): Simple_Redis_Cache_Redis {
	$wrapper = $redis_class->newInstanceWithoutConstructor();
	$redis_class->getProperty( 'all_config' )->setValue( $wrapper, Simple_Redis_Cache_Early_Config::defaults() );
	$redis_class->getProperty( 'config' )->setValue( $wrapper, array() );
	$redis_class->getProperty( 'attempted' )->setValue( $wrapper, true );
	$redis_class->getProperty( 'client' )->setValue( $wrapper, null );
	$redis_class->getProperty( 'connection_error' )->setValue( $wrapper, $connection_error );
	$redis_class->getProperty( 'error' )->setValue( $wrapper, $stale );
	return $wrapper;
};

// A stale error from an earlier call must not condemn the next one.
$fresh = $new_wrapper( null, 'Redis returned an invalid cache generation.' );
$fresh->generation( 'object' );
$assert( null === $fresh->error(), 'A stale error from a previous call survived into the next one.' );

// A failed connection is attempted once, so it must keep being reported.
$broken = $new_wrapper( 'Unable to connect to Redis.', 'Unable to connect to Redis.' );
$broken->generation( 'object' );
$assert( 'Unable to connect to Redis.' === $broken->error(), 'A failed connection stopped being reported on later calls.' );

/* -------------------------------------------------------------------------
 * 7. The early page-cache loader must leave the site's own globals alone.
 *
 * wp-settings.php includes advanced-cache.php at global scope, after
 * wp-config.php has run, so an unprefixed variable in the loader overwrites
 * and then unsets whatever the site defined under that name.
 *
 * This runs in a fresh PHP process: section 2 declared a stand-in loader class
 * here, which would make the real one skip its class body. The child runs at
 * global scope like wp-settings.php, loads the real class, and with no
 * generated config next to the plugin, run() returns at its disabled check.
 * ---------------------------------------------------------------------- */

$child = '
	define( "ABSPATH", ' . var_export( $plugin_dir . '/', true ) . ' );
	define( "WP_CONTENT_DIR", ' . var_export( $plugin_dir, true ) . ' );
	$config = "defined by wp-config.php";
	$before = array_keys( get_defined_vars() );
	require ' . var_export( $plugin_dir . '/dropins/advanced-cache-loader.php', true ) . ';
	echo json_encode(
		array(
			"config" => $config ?? null,
			"leaked" => array_values( array_diff( array_keys( get_defined_vars() ), $before, array( "before", "GLOBALS", "_SERVER", "_ENV", "_REQUEST" ) ) ),
			"real"   => method_exists( "Simple_Redis_Cache_Advanced_Cache_Loader", "run" ),
		)
	);
';
$output = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d error_reporting=-1 -r ' . escapeshellarg( $child ) . ' 2>&1' );
$result = json_decode( $output, true );
$assert( is_array( $result ), 'The global-scope loader check produced output other than its result (a warning?): ' . trim( $output ) );
$assert( true === ( $result['real'] ?? null ), 'The global-scope loader check did not load the real loader class.' );
$assert( 'defined by wp-config.php' === ( $result['config'] ?? null ), 'The advanced-cache loader overwrote or unset a global $config the site had defined.' );
$assert( array() === ( $result['leaked'] ?? null ), 'The advanced-cache loader left variables in the global scope: ' . implode( ', ', (array) ( $result['leaked'] ?? array() ) ) );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: logged-in hits stay private, discarded buffers are not cached, and failed bumps retain database transients.\n";
