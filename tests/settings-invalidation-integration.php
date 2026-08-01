<?php
/** Focused policy-to-namespace invalidation regression test. */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) );

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-redis.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-plugin.php';

if ( ! extension_loaded( 'redis' ) ) {
	fwrite( STDERR, "SKIP: PhpRedis is not installed.\n" );
	exit( 0 );
}

$host   = getenv( 'SRC_TEST_REDIS_HOST' ) ?: '127.0.0.1';
$port   = (int) ( getenv( 'SRC_TEST_REDIS_PORT' ) ?: 6379 );
$config = Simple_Redis_Cache_Early_Config::defaults();
$config['redis']['host'] = $host;
$config['redis']['port'] = $port;
$config['redis']['timeout'] = 0.5;
$config['redis']['read_timeout'] = 0.5;
$config['object']['enabled'] = true;
$config['page']['enabled'] = true;
$config['site'] = array(
	'host'            => 'example.test',
	'port'            => 443,
	'fallback_prefix' => 'src-settings-test-' . bin2hex( random_bytes( 8 ) ),
);

$probe = new Redis();
try {
	if ( ! $probe->connect( $host, $port, 0.5, null, 0, 0.5 ) ) {
		throw new RuntimeException( 'connect returned false' );
	}
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'SKIP: Redis is unavailable: ' . $throwable->getMessage() . "\n" );
	exit( 0 );
}

$object_key = Simple_Redis_Cache_Early_Config::meta_key( 'object-generation', $config );
$page_key   = Simple_Redis_Cache_Early_Config::meta_key( 'page-generation', $config );
$page_versions_key = Simple_Redis_Cache_Early_Config::page_content_versions_key( $config );
$keys       = array( $object_key, $page_key, $page_versions_key );
$assert     = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$method = new ReflectionMethod( Simple_Redis_Cache_Plugin::class, 'invalidate_changed_settings' );
$method->setAccessible( true );

try {
	$probe->del( $keys );
	$redis         = new Simple_Redis_Cache_Redis( $config );
	$object_before = $redis->generation( 'object' );
	$page_before   = $redis->generation( 'page' );

	$object_change = $config;
	$object_change['object']['max_ttl'] = 3600;
	$method->invoke( null, $config, $object_change );
	$assert( $object_before + 1 === (int) $probe->get( $object_key ), 'Object policy did not invalidate object generation.' );
	$assert( $page_before === (int) $probe->get( $page_key ), 'Object policy unexpectedly invalidated page generation.' );

	$object_after = (int) $probe->get( $object_key );
	$page_change  = $object_change;
	$page_change['page']['ttl'] = 7200;
	$probe->hSet( $page_versions_key, 'post:123', str_repeat( 'a', 32 ) );
	$method->invoke( null, $object_change, $page_change );
	$assert( $object_after === (int) $probe->get( $object_key ), 'Page policy unexpectedly invalidated object generation.' );
	$assert( $page_before + 1 === (int) $probe->get( $page_key ), 'Page policy did not invalidate page generation.' );
	$assert( ! $probe->exists( $page_versions_key ), 'Page generation bump did not reset per-resource content versions.' );

	$page_after = (int) $probe->get( $page_key );
	$auto_purge = $page_change;
	$auto_purge['page']['invalidate_on_post_update'] = true;
	$method->invoke( null, $page_change, $auto_purge );
	$assert( $page_after + 1 === (int) $probe->get( $page_key ), 'Post-update invalidation policy did not invalidate page generation.' );

	$page_after = (int) $probe->get( $page_key );
	$term_auto_purge = $auto_purge;
	$term_auto_purge['page']['invalidate_term_archives_on_post_update'] = true;
	$method->invoke( null, $auto_purge, $term_auto_purge );
	$assert( $page_after + 1 === (int) $probe->get( $page_key ), 'Taxonomy-archive invalidation policy did not invalidate page generation.' );

	$page_after  = (int) $probe->get( $page_key );
	$debug_only  = $term_auto_purge;
	$debug_only['page']['debug_header'] = true;
	$method->invoke( null, $term_auto_purge, $debug_only );
	$assert( $object_after === (int) $probe->get( $object_key ), 'Debug-header change invalidated object generation.' );
	$assert( $page_after === (int) $probe->get( $page_key ), 'Debug-header change invalidated page generation.' );

	$transport_only = $debug_only;
	$transport_only['redis']['timeout'] = 2.0;
	$method->invoke( null, $debug_only, $transport_only );
	$assert( $object_after === (int) $probe->get( $object_key ), 'Timeout tuning invalidated object generation.' );
	$assert( $page_after === (int) $probe->get( $page_key ), 'Timeout tuning invalidated page generation.' );

	$new_target = $transport_only;
	$new_target['site']['fallback_prefix'] = 'src-settings-test-' . bin2hex( random_bytes( 8 ) );
	$new_object_key = Simple_Redis_Cache_Early_Config::meta_key( 'object-generation', $new_target );
	$new_page_key   = Simple_Redis_Cache_Early_Config::meta_key( 'page-generation', $new_target );
	$keys[]         = $new_object_key;
	$keys[]         = $new_page_key;
	$new_redis      = new Simple_Redis_Cache_Redis( $new_target );
	$new_object_before = $new_redis->generation( 'object' );
	$new_page_before   = $new_redis->generation( 'page' );
	$old_object_before = (int) $probe->get( $object_key );
	$old_page_before   = (int) $probe->get( $page_key );

	$method->invoke( null, $transport_only, $new_target );
	$assert( $old_object_before + 1 === (int) $probe->get( $object_key ), 'Storage change did not invalidate the old object namespace.' );
	$assert( $old_page_before + 1 === (int) $probe->get( $page_key ), 'Storage change did not invalidate the old page namespace.' );
	$assert( $new_object_before + 1 === (int) $probe->get( $new_object_key ), 'Storage change did not invalidate the new object namespace.' );
	$assert( $new_page_before + 1 === (int) $probe->get( $new_page_key ), 'Storage change did not invalidate the new page namespace.' );

	echo "OK: settings changes invalidate only relevant namespaces and both storage targets.\n";
} finally {
	$probe->del( $keys );
	$probe->close();
}
