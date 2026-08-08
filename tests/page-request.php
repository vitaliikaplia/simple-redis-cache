<?php
/**
 * Dependency-light regression checks for the early page-request validator.
 *
 * Run directly with: php tests/page-request.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// cache_key() resolves the Redis prefix through the early config.
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
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

// Comment-moderation links must never be cached: core only limits that view for
// ten minutes at render time, so a stored copy would expose a pending comment.
$assert( false, $request( '/hello-world/?unapproved=12&moderation-hash=abc', $config ) instanceof Simple_Redis_Cache_Page_Request, 'comment moderation links bypass page cache' );
$assert( false, $request( '/hello-world/?unapproved=12', $config ) instanceof Simple_Redis_Cache_Page_Request, 'the unapproved parameter alone bypasses page cache' );

// PHP rewrites parameter names before they reach $_GET: leading spaces are
// dropped and every remaining space or dot becomes an underscore. Matching the
// raw name would let most of the unsafe list through — "rest.route" arrives as
// "rest_route", ".wpnonce" as "_wpnonce".
$assert( false, $request( '/hello-world/?rest.route=/wp/v2/users', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a dotted rest_route cannot slip past the allowlist' );
$assert( false, $request( '/hello-world/?.wpnonce=abc', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a dot-prefixed nonce cannot slip past the allowlist' );
$assert( false, $request( '/hello-world/?edd.action=add_to_cart', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a dotted edd_action cannot slip past the allowlist' );
$assert( false, $request( '/hello-world/?preview%20id=9', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a space-separated preview_id cannot slip past the allowlist' );
$assert( false, $request( '/hello-world/?doing.wp.cron=1', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a dotted doing_wp_cron cannot slip past the allowlist' );
$assert( false, $request( '/hello-world/?_wp.http_referer=/x', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a dotted referer parameter cannot slip past the allowlist' );

// An unterminated "[" is not array syntax: PHP turns it into an underscore, so
// "rest[route" arrives as "rest_route". A "[" that is actually closed does start
// an array, and then the name really is truncated at the bracket.
$assert( false, $request( '/hello-world/?rest%5Broute=x', $config ) instanceof Simple_Redis_Cache_Page_Request, 'an unterminated bracket cannot smuggle rest_route past the allowlist' );
$assert( false, $request( '/hello-world/?edd%5Baction=x', $config ) instanceof Simple_Redis_Cache_Page_Request, 'an unterminated bracket cannot smuggle edd_action past the allowlist' );
$assert( false, $request( '/hello-world/?doing%5Bwp_cron=1', $config ) instanceof Simple_Redis_Cache_Page_Request, 'an unterminated bracket cannot smuggle doing_wp_cron past the allowlist' );
$assert( false, $request( '/hello-world/?fl%5Bbuilder=1', $config ) instanceof Simple_Redis_Cache_Page_Request, 'an unterminated bracket cannot smuggle fl_builder past the allowlist' );

// Genuine array syntax must not be over-blocked: PHP reduces these to a base name
// that is not on the list, so the request stays cacheable.
$assert( true, $request( '/hello-world/?rest%5Broute%5D=x', $config ) instanceof Simple_Redis_Cache_Page_Request, 'closed bracket syntax reduces to a safe base name and stays cacheable' );
$assert( true, $request( '/hello-world/?filter%5Bcolor%5D=red', $config ) instanceof Simple_Redis_Cache_Page_Request, 'an ordinary array parameter stays cacheable' );
$assert( true, $request( '/hello-world/?items%5B%5D=1', $config ) instanceof Simple_Redis_Cache_Page_Request, 'an empty-index array parameter stays cacheable' );

// A trailing space becomes an underscore rather than being stripped, so the
// ignored-parameter match has to reason about the mangled name too.
$ignoring = $config;
$ignoring['page']['ignored_query_parameters'] = array( 'utm_*' );
$plain  = $request( '/hello-world/', $ignoring );
$padded = $request( '/hello-world/?utm_source%20=1', $ignoring );
$assert( true, $padded instanceof Simple_Redis_Cache_Page_Request, 'an ignored marketing parameter stays cacheable when padded' );
$assert( true, $plain->cache_key( 1, $ignoring ) === $padded->cache_key( 1, $ignoring ), 'a padded ignored parameter did not resolve to the same cache key' );

// "unapproved" reaches WordPress even when the raw name carries a leading space.
$assert( false, $request( '/hello-world/?%20unapproved=12', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a space-padded unsafe parameter cannot slip past the allowlist' );
$assert( false, $request( '/hello-world/?+unapproved=12', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a plus-encoded space cannot slip past the allowlist' );
$assert( false, $request( '/hello-world/?%20unapproved%5B%5D=12', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a space-padded array-style unsafe parameter cannot slip past the allowlist' );
// A trailing space is mangled to an underscore rather than dropped, so
// "unapproved " reaches WordPress as "unapproved_" — a parameter core ignores.
// Blocking it would be over-blocking, so this one stays cacheable.
$assert( true, $request( '/hello-world/?unapproved%20=12', $config ) instanceof Simple_Redis_Cache_Page_Request, 'a trailing-space name is a different parameter to WordPress and stays cacheable' );
$assert( false, $request( '/hello-world/?%20MODERATION-HASH=abc', $config ) instanceof Simple_Redis_Cache_Page_Request, 'casing plus padding cannot slip past the allowlist' );

// Path exclusions are matched case-insensitively, so a differently-cased pattern
// or request cannot silently defeat the exclusion.
$excluded = $config;
$excluded['page']['excluded_paths'] = array( '/My-Account/*', '/Secret-Page/' );
$assert( false, $request( '/my-account/orders/', $excluded ) instanceof Simple_Redis_Cache_Page_Request, 'a differently-cased wildcard exclusion still applies' );
$assert( false, $request( '/My-Account/', $excluded ) instanceof Simple_Redis_Cache_Page_Request, 'a wildcard exclusion also covers its own base path' );
$assert( false, $request( '/secret-page/', $excluded ) instanceof Simple_Redis_Cache_Page_Request, 'a differently-cased exact exclusion still applies' );
$assert( true, $request( '/my-accounts-payable/', $excluded ) instanceof Simple_Redis_Cache_Page_Request, 'an unrelated path sharing a prefix is not excluded' );

fwrite( STDOUT, "Page-request checks passed.\n" );
