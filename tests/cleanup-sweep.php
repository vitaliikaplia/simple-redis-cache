<?php
/**
 * Integration test for the background sweep of superseded cache generations.
 *
 * A purge only bumps a generation, so the old payloads would stay in Redis until
 * their TTL. The sweep must remove exactly those keys: never a key of the current
 * generation, never a meta key, never a key of another site's prefix, and never
 * anything while a namespace's current generation is unknown.
 *
 * Usage: php tests/cleanup-sweep.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) );
// Glob metacharacters in the salt: an unescaped MATCH would never see these keys.
define( 'WP_CACHE_KEY_SALT', 'src-cleanup-test-' . bin2hex( random_bytes( 6 ) ) . '-[ab]*?' );

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-cleanup.php';

if ( ! extension_loaded( 'redis' ) ) {
	fwrite( STDERR, "SKIP: PhpRedis is not installed.\n" );
	exit( 0 );
}

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$client = new Redis();
try {
	$client->connect( getenv( 'SRC_TEST_REDIS_HOST' ) ?: '127.0.0.1', (int) ( getenv( 'SRC_TEST_REDIS_PORT' ) ?: 6379 ), 0.5 );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'SKIP: Redis is not reachable: ' . $throwable->getMessage() . "\n" );
	exit( 0 );
}

$config  = Simple_Redis_Cache_Early_Config::defaults();
$prefix  = Simple_Redis_Cache_Early_Config::prefix( $config );
$other   = 'src-cleanup-test-other-' . bin2hex( random_bytes( 6 ) ) . ':src:';
$lookalike = substr( WP_CACHE_KEY_SALT, 0, -6 ) . 'aZ:src:';
$created = array();

$put = static function ( string $key ) use ( $client, &$created ): void {
	$client->set( $key, 'x', array( 'ex' => 300 ) );
	$created[] = $key;
};
$exists = static function ( string $key ) use ( $client ): bool {
	return 1 === (int) $client->exists( $key );
};

try {
	$client->set( $prefix . 'meta:page-generation', '700' );
	$client->set( $prefix . 'meta:object-generation', '500' );
	$created[] = $prefix . 'meta:page-generation';
	$created[] = $prefix . 'meta:object-generation';

	$stale_keys = array(
		$prefix . 'page:699:' . hash( 'sha256', 'a' ),
		$prefix . 'page:699:' . hash( 'sha256', 'a' ) . ':lock',
		$prefix . 'page:12:variants:' . hash( 'sha256', 'b' ),
		$prefix . 'object:499:7:' . hash( 'sha256', 'c' ),
	);
	$current_keys = array(
		$prefix . 'page:700:' . hash( 'sha256', 'a' ),
		$prefix . 'page:700:' . hash( 'sha256', 'a' ) . ':access:' . hash( 'sha256', 'd' ),
		$prefix . 'object:500:7:' . hash( 'sha256', 'c' ),
		$prefix . 'meta:page-content-versions',
		$prefix . 'meta:object-group-' . hash( 'sha256', 'g' ) . '-generation',
		$prefix . 'page:not-a-generation:x',
		$other . 'page:699:' . hash( 'sha256', 'a' ),
		$other . 'object:1:1:' . hash( 'sha256', 'a' ),
		$lookalike . 'page:699:' . hash( 'sha256', 'a' ),
	);
	foreach ( array_merge( $stale_keys, $current_keys ) as $key ) {
		$put( $key );
	}

	// Pure key classification.
	$classified = Simple_Redis_Cache_Cleanup::stale_keys( array_merge( $stale_keys, $current_keys ), $prefix, array( 'page' => '700', 'object' => '500' ) );
	sort( $classified );
	$expected = $stale_keys;
	sort( $expected );
	$assert( $expected === $classified, 'stale_keys() picked the wrong keys: ' . implode( ', ', $classified ) );
	$assert( array() === Simple_Redis_Cache_Cleanup::stale_keys( $stale_keys, $prefix, array( 'page' => null, 'object' => null ) ), 'Keys were treated as stale although the current generation is unknown.' );

	// Run the sweep to completion in small budgeted steps, the way WP-Cron does.
	$runs   = 0;
	$status = '';
	do {
		$result = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 0.0, 1 );
		$status = (string) ( $result['status'] ?? '' );
		++$runs;
	} while ( 'partial' === $status && $runs < 100000 );

	$assert( 'complete' === $status, 'The sweep did not complete (last status: ' . $status . ').' );
	foreach ( $stale_keys as $key ) {
		$assert( ! $exists( $key ), 'A key of a superseded generation survived the sweep: ' . $key );
	}
	foreach ( $current_keys as $key ) {
		$assert( $exists( $key ), 'The sweep removed a key it must keep: ' . $key );
	}

	$state = Simple_Redis_Cache_Cleanup::state( $client, $config );
	$assert( '700' === ( $state['clean_page'] ?? '' ) && '500' === ( $state['clean_object'] ?? '' ), 'The completed pass did not record the generations it cleaned.' );
	$assert( (string) count( $stale_keys ) === ( $state['last_deleted'] ?? '' ), 'The completed pass recorded a wrong number of removed keys: ' . ( $state['last_deleted'] ?? 'none' ) );
	$assert( '' === ( $state['cursor'] ?? 'x' ), 'A completed pass left a cursor behind.' );

	// Nothing changed since: the next run must not walk the keyspace again.
	$again = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 5.0 );
	$assert( 'idle' === ( $again['status'] ?? '' ), 'A run after a clean pass did not stay idle: ' . ( $again['status'] ?? '' ) );

	// A new purge makes the previous current generation stale on the next pass.
	$client->set( $prefix . 'meta:page-generation', '701' );
	$fresh = $prefix . 'page:701:' . hash( 'sha256', 'fresh' );
	$put( $fresh );
	do {
		$result = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 0.0, 1 );
	} while ( 'partial' === ( $result['status'] ?? '' ) );
	$assert( ! $exists( $prefix . 'page:700:' . hash( 'sha256', 'a' ) ), 'The pass after a purge kept the superseded generation.' );
	$assert( $exists( $fresh ), 'The pass after a purge removed a key of the new generation.' );
	$assert( $exists( $prefix . 'object:500:7:' . hash( 'sha256', 'c' ) ), 'A page purge made the sweep remove current object keys.' );

	// A purge in the middle of a pass: keys of the new generation must survive.
	$client->hDel( Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-state', $config ), 'clean_page' );
	$first = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 0.0, 1 );
	$client->set( $prefix . 'meta:page-generation', '702' );
	$newest = $prefix . 'page:702:' . hash( 'sha256', 'newest' );
	$put( $newest );
	$assert( 'partial' === ( $first['status'] ?? '' ), 'A one-key SCAN step finished the whole pass, so the mid-pass purge was not exercised.' );
	if ( 'partial' === ( $first['status'] ?? '' ) ) {
		do {
			$result = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 0.0, 1 );
		} while ( 'partial' === ( $result['status'] ?? '' ) );
	}
	$assert( $exists( $newest ), 'A purge during a pass let the sweep remove a key of the new generation.' );
	$state = Simple_Redis_Cache_Cleanup::state( $client, $config );
	$assert( '701' === ( $state['clean_page'] ?? '' ), 'A pass that started before a purge recorded a generation other than its own as clean: ' . ( $state['clean_page'] ?? 'none' ) );
	// So the next pass still runs and catches keys of 701 the first one saw while they were current.
	do {
		$result = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 0.0, 1 );
	} while ( 'partial' === ( $result['status'] ?? '' ) );
	$assert( ! $exists( $fresh ) && $exists( $newest ), 'The pass after a mid-pass purge did not finish the superseded generation.' );
	$state = Simple_Redis_Cache_Cleanup::state( $client, $config );
	$assert( '702' === ( $state['clean_page'] ?? '' ), 'The follow-up pass did not record the current generation as clean.' );

	/*
	 * Generations are compared after each SCAN batch, not once per run: a purge
	 * that lands while a run is under way must not turn the new generation's keys
	 * into "stale" ones. The client below purges right after its first SCAN call.
	 */
	$racing = new class() extends Redis {
		public ?Closure $after_first_scan = null;

		public function scan( null|int|string &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null ): array|false {
			$keys = parent::scan( $iterator, $pattern, $count, $type );
			if ( null !== $this->after_first_scan ) {
				( $this->after_first_scan )();
				$this->after_first_scan = null;
			}

			return $keys;
		}
	};
	$racing->connect( getenv( 'SRC_TEST_REDIS_HOST' ) ?: '127.0.0.1', (int) ( getenv( 'SRC_TEST_REDIS_PORT' ) ?: 6379 ), 0.5 );
	$raced = $prefix . 'page:703:' . hash( 'sha256', 'raced' );
	$put( $raced );
	$client->hDel( Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-state', $config ), 'clean_page' );
	$racing->after_first_scan = static function () use ( $client, $prefix ): void {
		$client->set( $prefix . 'meta:page-generation', '703' );
	};
	Simple_Redis_Cache_Cleanup::sweep( $racing, $config, 5.0, 1 );
	$assert( $exists( $raced ), 'A purge during a run made the sweep remove keys of the new generation.' );
	$racing->close();

	// An unknown generation (evicted meta key) must never mean "everything is stale".
	$client->del( $prefix . 'meta:object-generation' );
	$kept = $prefix . 'object:500:7:' . hash( 'sha256', 'kept' );
	$put( $kept );
	$client->hDel( Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-state', $config ), 'clean_page' );
	do {
		$result = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 0.0, 1 );
	} while ( 'partial' === ( $result['status'] ?? '' ) );
	$assert( $exists( $kept ), 'Object keys were removed while the object generation was unknown.' );

	// A second runner backs off while the first holds the lock.
	$lock = Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-lock', $config );
	$client->set( $lock, 'someone-else', array( 'ex' => 30 ) );
	$created[] = $lock;
	$locked = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 5.0 );
	$assert( 'locked' === ( $locked['status'] ?? '' ), 'The sweep ran although another run held the lock.' );
	$assert( 'someone-else' === $client->get( $lock ), 'The sweep released a lock it did not own.' );

	// A SCAN refused with an error reply must not count as a finished, clean pass.
	$failing = new class() extends Redis {
		public int $calls = 0;

		public function scan( null|int|string &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null ): array|false {
			if ( ++$this->calls >= 2 ) {
				return false; // PhpRedis on an error reply: false, cursor untouched.
			}
			return parent::scan( $iterator, $pattern, $count, $type );
		}
	};
	$failing->connect( getenv( 'SRC_TEST_REDIS_HOST' ) ?: '127.0.0.1', (int) ( getenv( 'SRC_TEST_REDIS_PORT' ) ?: 6379 ), 0.5 );
	$client->set( $prefix . 'meta:object-generation', '500' );
	$client->set( $prefix . 'meta:page-generation', '704' );
	$client->del( Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-state', $config ), $lock );
	$failed = Simple_Redis_Cache_Cleanup::sweep( $failing, $config, 5.0, 1 );
	$state  = Simple_Redis_Cache_Cleanup::state( $client, $config );
	$assert( 'error' === ( $failed['status'] ?? '' ), 'A failed SCAN step was reported as ' . ( $failed['status'] ?? '' ) . ' instead of an error.' );
	$assert( '704' !== ( $state['clean_page'] ?? '' ) && '' !== ( $state['error'] ?? '' ), 'A failed SCAN step recorded the pass as clean, or recorded no error.' );
	$failing->close();
	$again = Simple_Redis_Cache_Cleanup::sweep( $client, $config, 5.0 );
	$assert( 'idle' !== ( $again['status'] ?? '' ), 'After a failed SCAN the next run stayed idle instead of finishing the pass.' );

	// Another site whose salt extends this prefix with a page-like segment is not ours.
	$extended = array( $prefix . 'page:1:src:page:5:' . hash( 'sha256', 'x' ), $prefix . 'page:1:src:meta:page-generation', $prefix . 'object:2:src:object:3:4:' . hash( 'sha256', 'y' ) );
	$assert( array() === Simple_Redis_Cache_Cleanup::stale_keys( $extended, $prefix, array( 'page' => '9', 'object' => '9' ) ), 'Keys of a site whose prefix extends this one were taken for stale keys of this site.' );

	// Escaping keeps glob metacharacters literal.
	$assert( 'a\\[b\\]\\*\\?\\\\c' === Simple_Redis_Cache_Cleanup::glob_escape( 'a[b]*?\\c' ), 'Glob metacharacters were not escaped.' );
} finally {
	$created[] = Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-state', $config );
	$created[] = Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-lock', $config );
	$client->del( array_values( array_unique( $created ) ) );
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: the sweep removes only keys of superseded generations of this site, resumably and under a lock.\n";
