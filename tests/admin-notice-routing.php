<?php
/**
 * Dependency-light regression test for administrator notice routing.
 *
 * A notice is only useful if somebody with manage_options will read it. Requests
 * made by an editor, by cron, or by a webhook must therefore leave their warning
 * in the shared fallback rather than in a per-user queue nobody drains.
 *
 * Run directly with: php tests/admin-notice-routing.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'SIMPLE_REDIS_CACHE_BASENAME', 'simple-redis-cache/simple-redis-cache.php' );

$GLOBALS['src_test_user_id']   = 0;
$GLOBALS['src_test_can_manage'] = false;
$GLOBALS['src_test_usermeta']  = array();
$GLOBALS['src_test_options']   = array();

function get_current_user_id(): int {
	return (int) $GLOBALS['src_test_user_id'];
}

function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability && (bool) $GLOBALS['src_test_can_manage'];
}

function update_user_meta( int $user_id, string $key, mixed $value ): bool {
	$GLOBALS['src_test_usermeta'][ $user_id ][ $key ] = $value;
	return true;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['src_test_options'][ $name ] = $value;
	return true;
}

function maybe_unserialize( mixed $value ): mixed {
	return $value;
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

/** Minimal $wpdb whose direct reads mirror the stubbed stores above. */
final class Simple_Redis_Cache_Test_Notice_Wpdb {
	public string $usermeta = 'wp_usermeta';
	public string $options  = 'wp_options';

	public function prepare( string $query, mixed ...$args ): string {
		return $query . '|' . implode( '|', array_map( 'strval', $args ) );
	}

	public function get_var( string $query ): mixed {
		if ( str_contains( $query, 'wp_usermeta' ) ) {
			$parts   = explode( '|', $query );
			$user_id = (int) ( $parts[1] ?? 0 );
			return $GLOBALS['src_test_usermeta'][ $user_id ]['_simple_redis_cache_notices'] ?? null;
		}

		return $GLOBALS['src_test_options']['_simple_redis_cache_notices'] ?? null;
	}
}

$GLOBALS['wpdb'] = new Simple_Redis_Cache_Test_Notice_Wpdb();

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-admin.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

/* An administrator keeps their own queue. */
$GLOBALS['src_test_user_id']    = 7;
$GLOBALS['src_test_can_manage'] = true;
Simple_Redis_Cache_Admin::queue_notice( 'admin message', 'error' );
$assert(
	'admin message' === ( $GLOBALS['src_test_usermeta'][7]['_simple_redis_cache_notices'][0]['message'] ?? null ),
	'An administrator notice was not stored against that administrator.'
);
$assert( empty( $GLOBALS['src_test_options'] ), 'An administrator notice also went to the shared fallback.' );

/* An editor cannot read the admin notices screen, so their warning must be shared. */
$GLOBALS['src_test_usermeta']   = array();
$GLOBALS['src_test_options']    = array();
$GLOBALS['src_test_user_id']    = 42;
$GLOBALS['src_test_can_manage'] = false;
Simple_Redis_Cache_Admin::queue_notice( 'editor save failed', 'warning' );
$assert( empty( $GLOBALS['src_test_usermeta'] ), 'A notice was queued for a user who can never display it.' );
$assert(
	'editor save failed' === ( $GLOBALS['src_test_options']['_simple_redis_cache_notices'][0]['message'] ?? null ),
	'An editor-triggered warning did not reach the shared administrator fallback.'
);

/* cron and webhooks run without a user at all. */
$GLOBALS['src_test_usermeta']   = array();
$GLOBALS['src_test_options']    = array();
$GLOBALS['src_test_user_id']    = 0;
$GLOBALS['src_test_can_manage'] = false;
Simple_Redis_Cache_Admin::queue_notice( 'cron failure', 'warning' );
$assert(
	'cron failure' === ( $GLOBALS['src_test_options']['_simple_redis_cache_notices'][0]['message'] ?? null ),
	'A notice raised without a current user did not reach the shared fallback.'
);

/* The queue stays bounded. */
$GLOBALS['src_test_options'] = array();
for ( $index = 0; $index < 14; $index++ ) {
	Simple_Redis_Cache_Admin::queue_notice( 'message ' . $index, 'info' );
}
$queued = $GLOBALS['src_test_options']['_simple_redis_cache_notices'] ?? array();
$assert( 10 === count( $queued ), 'The shared notice queue is no longer capped at ten entries.' );
$assert( 'message 13' === ( $queued[9]['message'] ?? null ), 'The shared notice queue dropped the newest entry instead of the oldest.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: notices reach an administrator regardless of who triggered them.\n";
