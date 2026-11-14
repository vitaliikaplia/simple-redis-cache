<?php
/**
 * A cached page that prints a guest nonce lives no longer than that nonce.
 *
 * Guest nonces are the same for every visitor and verify for nonce_life / 2 at
 * the very least (12 hours by default), while a page may be cached for days.
 * A page printing one is kept in Redis and offered to the edge only until then;
 * a nonce that was created but never printed (core's wp_rest one) changes nothing.
 *
 * Usage: php tests/guest-nonce-ttl.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$test_content = sys_get_temp_dir() . '/src-guest-nonce-' . bin2hex( random_bytes( 6 ) );
mkdir( $test_content );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

/* -------------------------------------------------------------------------
 * A small hook system and core's nonce functions, as WordPress implements them.
 * ---------------------------------------------------------------------- */

$GLOBALS['src_test_hooks']       = array();
$GLOBALS['src_test_nonce_calls'] = 0;

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['src_test_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	return true;
}

function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
	foreach ( $GLOBALS['src_test_hooks'][ $hook ][ $priority ] ?? array() as $index => $entry ) {
		if ( $entry[0] == $callback ) { // phpcs:ignore Universal.Operators.StrictComparisons -- callables compare by value.
			unset( $GLOBALS['src_test_hooks'][ $hook ][ $priority ][ $index ] );
		}
	}
	if ( empty( $GLOBALS['src_test_hooks'][ $hook ][ $priority ] ) ) {
		unset( $GLOBALS['src_test_hooks'][ $hook ][ $priority ] );
	}
	if ( empty( $GLOBALS['src_test_hooks'][ $hook ] ) ) {
		unset( $GLOBALS['src_test_hooks'][ $hook ] );
	}
	return true;
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$callbacks = $GLOBALS['src_test_hooks'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $list ) {
		foreach ( $list as [ $callback, $accepted_args ] ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function wp_nonce_tick( mixed $action = -1 ): float {
	$nonce_life = apply_filters( 'nonce_life', DAY_IN_SECONDS, $action );
	return ceil( time() / ( $nonce_life / 2 ) );
}

// Logged in as user 7 when set; a guest otherwise.
$GLOBALS['src_test_user'] = 0;

function wp_create_nonce( mixed $action = -1 ): string {
	++$GLOBALS['src_test_nonce_calls'];
	$uid   = (int) $GLOBALS['src_test_user'];
	$token = $uid > 0 ? 'session-token-' . $uid : '';
	if ( 0 === $uid ) {
		$uid = apply_filters( 'nonce_user_logged_out', 0, $action );
	}
	$i = wp_nonce_tick( $action );
	return substr( hash_hmac( 'md5', $i . '|' . $action . '|' . $uid . '|' . $token, 'src-test-nonce-salt' ), -12, 10 );
}

function is_user_logged_in(): bool {
	return $GLOBALS['src_test_user'] > 0;
}

function get_current_user_id(): int {
	return (int) $GLOBALS['src_test_user'];
}

function is_front_page(): bool {
	return true;
}

// The loader runs itself on include; with no generated config it does nothing.
require_once dirname( __DIR__ ) . '/dropins/advanced-cache-loader.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-page-capture.php';

$loader = new ReflectionClass( Simple_Redis_Cache_Advanced_Cache_Loader::class );
$decode = $loader->getMethod( 'decode_payload' );
$edge   = $loader->getMethod( 'shared_max_age' );
$secret = bin2hex( random_bytes( 32 ) );

/* -------------------------------------------------------------------------
 * 1. The runtime honours valid_until, and payloads without it still work.
 * ---------------------------------------------------------------------- */

$page = Simple_Redis_Cache_Early_Config::defaults()['page'];
$page['invalidate_listings_on_post_update'] = false;
$page['shared_max_age']                     = 7 * DAY_IN_SECONDS;
$html = '<!doctype html><html><body>page</body></html>';
$sign = static function ( array $payload, string $cache_key ) use ( $secret ): string {
	$serialized = serialize( $payload );
	return Simple_Redis_Cache_Early_Config::PAGE_PAYLOAD_PREFIX . Simple_Redis_Cache_Early_Config::page_payload_mac( $cache_key, $serialized, $secret ) . $serialized;
};
$base = array(
	'version'   => 4,
	'status'    => 200,
	'type'      => 'home',
	'headers'   => array(),
	'body'      => $html,
	'encoding'  => '',
	'stored_at' => time(),
	'resources' => array(),
);

$legacy = $decode->invoke( null, $sign( $base, 'k' ), $page, 'k', $secret );
$assert( is_array( $legacy ) && 0 === $legacy['valid_until'], 'A payload without valid_until no longer decodes as unbounded.' );
$assert( is_array( $legacy ) && 7 * DAY_IN_SECONDS === $edge->invoke( null, $legacy, $page, array() ), 'A payload without valid_until lost its CDN lifetime.' );

$bounded = $decode->invoke( null, $sign( $base + array( 'valid_until' => time() + 500 ), 'k' ), $page, 'k', $secret );
$edge_ttl = is_array( $bounded ) ? $edge->invoke( null, $bounded, $page, array() ) : -1;
$assert( $edge_ttl > 490 && $edge_ttl <= 500, "A page whose guest nonce expires in 500 s was offered to the edge for {$edge_ttl} s." );

$expired = $decode->invoke( null, $sign( $base + array( 'valid_until' => time() - 1 ), 'k' ), $page, 'k', $secret );
$assert( null === $expired, 'A page whose guest nonce may no longer verify was still served.' );

/* -------------------------------------------------------------------------
 * 2. The capture records guest nonces and limits what it stores.
 * ---------------------------------------------------------------------- */

$cleanup = array();
$finish  = static function () use ( &$failures, &$cleanup, $test_content ): void {
	if ( ! empty( $cleanup['redis'] ) && ! empty( $cleanup['keys'] ) ) {
		try {
			$cleanup['redis']->del( array_keys( $cleanup['keys'] ) );
		} catch ( Throwable ) {
			// Every key carries the random prefix and a TTL.
		}
	}
	foreach ( (array) glob( $test_content . '/*' ) as $file ) {
		@unlink( (string) $file );
	}
	@rmdir( $test_content );

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
		exit( 1 );
	}
};

// A logged-in render has its own, per-session nonces; the guest filter stays off.
$capture_class = new ReflectionClass( Simple_Redis_Cache_Page_Capture::class );
ob_start();
Simple_Redis_Cache_Page_Capture::start(
	array(
		'config'             => array( 'page' => $page ),
		'redis'              => new Simple_Redis_Cache_Redis( array( 'redis' => array( 'host' => '127.0.0.1', 'port' => 1 ) ) ),
		'cache_key'          => 'unused',
		'lock_key'           => 'unused:lock',
		'lock_token'         => 'token',
		'deferred_logged_in' => true,
	)
);
ob_end_clean();
ob_end_clean();
$assert( empty( $GLOBALS['src_test_hooks']['nonce_user_logged_out'] ), 'A logged-in capture listens for guest nonces.' );
$assert( ! empty( $GLOBALS['src_test_hooks']['nonce_life'][ PHP_INT_MAX ] ), 'A logged-in capture does not listen for the nonces of its session.' );
unset( $GLOBALS['src_test_hooks']['nonce_life'] );

if ( ! extension_loaded( 'redis' ) ) {
	$finish();
	fwrite( STDERR, "SKIP: PhpRedis is not installed; payload checks passed.\n" );
	exit( 0 );
}

$config = Simple_Redis_Cache_Early_Config::defaults();
$config['page']                              = $page;
$config['page']['ttl']                       = 7 * DAY_IN_SECONDS;
$config['site']['fallback_prefix']           = 'src-guest-nonce-' . bin2hex( random_bytes( 8 ) );
$config['site']['secret']                    = $secret;
$config['redis']['host']                     = getenv( 'SRC_TEST_REDIS_HOST' ) ?: '127.0.0.1';
$config['redis']['port']                     = (int) ( getenv( 'SRC_TEST_REDIS_PORT' ) ?: 6379 );
$config['redis']['timeout']                  = 0.5;
$config['redis']['read_timeout']             = 0.5;

$redis  = new Simple_Redis_Cache_Redis( $config );
$client = $redis->client();
if ( null === $client ) {
	$finish();
	fwrite( STDERR, 'SKIP: Redis is unavailable: ' . (string) $redis->error() . "; payload checks passed.\n" );
	exit( 0 );
}
$cleanup['redis'] = $client;
$prefix           = Simple_Redis_Cache_Early_Config::prefix( $config );

// Keep clear of a default nonce tick boundary (00:00 and 12:00 UTC): there every
// nonce counts as printed on purpose, which the unprinted case below would see.
$half_day = DAY_IN_SECONDS / 2;
while ( ceil( time() / $half_day ) !== ceil( ( time() + 10 ) / $half_day ) ) {
	sleep( 1 );
}

/**
 * Render one page through a real capture buffer and return what was stored.
 *
 * @param callable():string $render Produces the page; may create nonces.
 * @return array{0: string|false, 1: int, 2: array<string, mixed>|null}
 */
$render = static function ( string $name, callable $render, array $extra = array() ) use ( $config, $redis, $client, $prefix, $decode, $secret, &$cleanup ): array {
	$cache_key = $prefix . 'page:1:' . hash( 'sha256', 'guest-nonce-' . $name );
	$lock_key  = $cache_key . ':lock';
	$token     = bin2hex( random_bytes( 16 ) );
	$cleanup['keys'][ $cache_key ] = true;
	$cleanup['keys'][ $lock_key ]  = true;
	$client->set( $lock_key, $token, array( 'ex' => 30 ) );

	ob_start();
	Simple_Redis_Cache_Page_Capture::start(
		array(
			'config'     => $config,
			'redis'      => $redis,
			'cache_key'  => $cache_key,
			'lock_key'   => $lock_key,
			'lock_token' => $token,
		) + $extra
	);
	echo $render();
	ob_end_flush();
	ob_end_clean();
	unset( $GLOBALS['src_test_hooks']['nonce_user_logged_out'] );
	if ( ! empty( $extra['deferred_logged_in'] ) ) {
		unset( $GLOBALS['src_test_hooks']['nonce_life'] );
	}

	$stored = $client->get( $cache_key );
	return array(
		$stored,
		(int) $client->ttl( $cache_key ),
		is_string( $stored ) ? $decode->invoke( null, $stored, $config['page'], $cache_key, $secret ) : null,
	);
};
$page_with = static fn( string ...$printed ): string => '<!doctype html><html><head><script>var nonces = ' . json_encode( $printed ) . ';</script></head><body><form><input type="hidden" name="_wpnonce" value="' . ( $printed[0] ?? '' ) . '"></form></body></html>';
$guaranteed = (int) floor( DAY_IN_SECONDS / 2 ) - 60;

// A printed guest nonce caps the page; an unprinted one (core's wp_rest) does not.
// A plugin that hands guests another uid is respected: the copy uses the same uid.
add_filter( 'nonce_user_logged_out', static fn( mixed $uid ): mixed => 0 === $uid ? 'guest-session' : $uid, 10, 1 );
$GLOBALS['src_test_nonce_calls'] = 0;
$before = time();
[ $stored, $ttl, $payload ] = $render(
	'printed',
	static function () use ( $page_with ): string {
		wp_create_nonce( 'wp_rest' );
		return $page_with( wp_create_nonce( 'contact_form' ) );
	}
);
$assert( is_string( $stored ), 'A page printing a guest nonce was not stored at all.' );
$assert( $ttl > $guaranteed - 10 && $ttl <= $guaranteed, "A page printing a guest nonce was stored for {$ttl} s instead of at most {$guaranteed} s." );
$assert( is_array( $payload ) && $payload['valid_until'] >= $before + $guaranteed && $payload['valid_until'] <= time() + $guaranteed, 'The payload does not record when its guest nonce may stop verifying.' );
$edge_ttl = is_array( $payload ) ? $edge->invoke( null, $payload, $config['page'], array() ) : -1;
$assert( $edge_ttl > 0 && $edge_ttl <= $guaranteed, "A page printing a guest nonce was offered to the edge for {$edge_ttl} s." );
$assert( 4 === $GLOBALS['src_test_nonce_calls'], 'Recording guest nonces created ' . $GLOBALS['src_test_nonce_calls'] . ' nonces for two in the page; the capture\'s own filter is not guarded.' );

[ $stored, $ttl, $payload ] = $render(
	'unprinted',
	static function () use ( $page_with ): string {
		wp_create_nonce( 'wp_rest' );
		return $page_with();
	}
);
$assert( is_string( $stored ) && $ttl > DAY_IN_SECONDS, "A page that prints no guest nonce was stored for {$ttl} s instead of its configured TTL." );
$assert( is_array( $payload ) && 0 === $payload['valid_until'], 'A page that prints no guest nonce got a valid_until.' );
unset( $GLOBALS['src_test_hooks']['nonce_user_logged_out'] );

// The shortest nonce_life among the printed nonces wins; the filter gets the action.
add_filter( 'nonce_life', static fn( int $life, mixed $action = -1 ): int => 'short_form' === $action ? 2 * HOUR_IN_SECONDS : ( 'tiny_form' === $action ? 100 : $life ), 10, 2 );
[ $stored, $ttl ] = $render(
	'shortest',
	static fn(): string => $page_with( wp_create_nonce( 'contact_form' ), wp_create_nonce( 'short_form' ) )
);
$assert( is_string( $stored ) && $ttl > HOUR_IN_SECONDS - 70 && $ttl <= HOUR_IN_SECONDS - 60, "A page printing a nonce with a two-hour life was stored for {$ttl} s." );

// A nonce too short-lived to cache around leaves the page uncached.
[ $stored ] = $render( 'tiny', static fn(): string => $page_with( wp_create_nonce( 'tiny_form' ) ) );
$assert( false === $stored, 'A page printing a nonce that verifies for under a minute was stored.' );
unset( $GLOBALS['src_test_hooks']['nonce_life'] );

// Past the tracking cap, every nonce counts as printed.
[ $stored, $ttl ] = $render(
	'many',
	static function () use ( $page_with ): string {
		for ( $i = 0; $i < 300; $i++ ) {
			wp_create_nonce( 'row_' . $i );
		}
		return $page_with();
	}
);
$assert( is_string( $stored ) && $ttl <= $guaranteed, "A page creating more guest nonces than can be checked was stored for {$ttl} s." );

/* -------------------------------------------------------------------------
 * 3. A logged-in page carries nonces of its session, the toolbar's log-out
 *    link among them: they cap it the same way.
 * ---------------------------------------------------------------------- */

$GLOBALS['src_test_user']        = 7;
$GLOBALS['src_test_nonce_calls'] = 0;
$logged_in                       = array( 'deferred_logged_in' => true, 'validated_user_id' => 7 );
[ $stored, $ttl, $payload ] = $render(
	'session-printed',
	static function () use ( $page_with ): string {
		wp_create_nonce( 'wp_rest' );
		return $page_with( wp_create_nonce( 'log-out' ) );
	},
	$logged_in
);
$assert( is_string( $stored ), 'A logged-in page printing a session nonce was not stored at all.' );
$assert( $ttl > $guaranteed - 10 && $ttl <= $guaranteed, "A logged-in page printing a session nonce was stored for {$ttl} s instead of at most {$guaranteed} s." );
$assert( is_array( $payload ) && $payload['valid_until'] > time() && $payload['valid_until'] <= time() + $guaranteed, 'The logged-in payload does not record when its session nonce may stop verifying.' );
$assert( 4 === $GLOBALS['src_test_nonce_calls'], 'Recording session nonces created ' . $GLOBALS['src_test_nonce_calls'] . ' nonces for two in the page; the capture\'s own filter is not guarded.' );

[ $stored, $ttl ] = $render(
	'session-unprinted',
	static function () use ( $page_with ): string {
		wp_create_nonce( 'wp_rest' );
		return $page_with();
	},
	$logged_in
);
$assert( is_string( $stored ) && $ttl > DAY_IN_SECONDS, "A logged-in page that prints no nonce was stored for {$ttl} s instead of its configured TTL." );

// The capture of a logged-in page starts at template_include; core creates the
// printed wp_rest nonce at init. The loader notes it from wp_cache_postload on.
$GLOBALS['src_test_hooks'] = array_diff_key( $GLOBALS['src_test_hooks'], array( 'nonce_life' => true ) );
Simple_Redis_Cache_Advanced_Cache_Loader::defer_logged_in( array( 'config' => $config ) + $logged_in );
$early_rest = wp_create_nonce( 'wp_rest' );
[ $stored, $ttl, $payload ] = $render( 'session-early', static fn(): string => $page_with( $early_rest ), $logged_in );
$assert( is_string( $stored ) && $ttl > $guaranteed - 10 && $ttl <= $guaranteed, "A logged-in page printing a nonce created before the capture started was stored for {$ttl} s instead of at most {$guaranteed} s." );
$assert( empty( $GLOBALS['src_test_hooks']['nonce_life'] ), 'The early nonce listener stayed hooked after the capture took over.' );

// More early nonces than can be tracked: the page is capped without looking.
Simple_Redis_Cache_Advanced_Cache_Loader::defer_logged_in( array( 'config' => $config ) + $logged_in );
for ( $i = 0; $i < 300; $i++ ) {
	wp_create_nonce( 'early_row_' . $i );
}
[ $stored, $ttl ] = $render( 'session-early-many', static fn(): string => $page_with(), $logged_in );
$assert( is_string( $stored ) && $ttl <= $guaranteed, "A logged-in page that created more early nonces than can be checked was stored for {$ttl} s." );
unset( $GLOBALS['simple_redis_cache_logged_in_context'], $GLOBALS['src_test_hooks']['template_redirect'] );
$GLOBALS['src_test_user'] = 0;

$finish();
echo "OK: pages printing guest or session nonces expire with them, in Redis and at the edge.\n";
