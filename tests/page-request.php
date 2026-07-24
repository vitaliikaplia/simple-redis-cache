<?php
/**
 * Dependency-light regression checks for the early page-request validator.
 *
 * Run directly with: php tests/page-request.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/dropins/class-simple-redis-cache-page-request.php';

$config = array(
	'page' => array(
		'cache_logged_in'          => false,
		'cache_query_strings'       => true,
		'cache_search'              => false,
		'ignored_query_parameters' => array(),
		'excluded_paths'            => array(),
		'excluded_cookies'          => array(),
		'excluded_user_agents'      => array(),
		'vary_cookies'              => array(),
	),
	'site' => array(
		'host' => 'example.test',
		'port' => 80,
	),
);

/** @param array<string, mixed> $config */
$request = static function ( string $uri, array $config ): ?Simple_Redis_Cache_Page_Request {
	$_COOKIE = array();
	$_SERVER = array(
		'REQUEST_METHOD'  => 'GET',
		'HTTP_HOST'       => 'example.test',
		'SERVER_PORT'     => '80',
		'REQUEST_URI'     => $uri,
		'HTTP_USER_AGENT' => 'Simple Redis Cache test',
	);

	return Simple_Redis_Cache_Page_Request::from_globals( $config );
};

/** @param mixed $actual */
$assert = static function ( bool $expected, mixed $actual, string $message ): void {
	if ( $expected !== (bool) $actual ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$assert( true, $request( '/ordinary-page/', $config ) instanceof Simple_Redis_Cache_Page_Request, 'ordinary paths remain cacheable' );
$assert( true, $request( '/caf%C3%A9/', $config ) instanceof Simple_Redis_Cache_Page_Request, 'valid percent-encoded paths remain cacheable' );
$assert( false, $request( '/%00/', $config ) instanceof Simple_Redis_Cache_Page_Request, 'encoded NUL bypasses page cache' );
$assert( false, $request( '/prefix%1Fsuffix/', $config ) instanceof Simple_Redis_Cache_Page_Request, 'encoded C0 controls bypass page cache' );
$assert( false, $request( '/%7F/', $config ) instanceof Simple_Redis_Cache_Page_Request, 'encoded DEL bypasses page cache' );

fwrite( STDOUT, "Page-request checks passed.\n" );
