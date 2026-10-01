<?php
/**
 * Dependency-light regression test for the Cloudflare cache-purge client.
 *
 * Run directly with: php tests/cloudflare.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

/** @var array<int, array<string, mixed>> Every request the client attempted. */
$GLOBALS['src_test_requests'] = array();
/** @var array<int, array{code:int,body:string}> Queued responses, consumed in order. */
$GLOBALS['src_test_responses'] = array();

final class WP_Error {
	/** @var string[] */
	private array $messages;

	public function __construct( public string $code = '', string $message = '' ) {
		$this->messages = '' === $message ? array() : array( $message );
	}

	/** @return string[] */
	public function get_error_messages(): array {
		return $this->messages;
	}

	public function get_error_message(): string {
		return $this->messages[0] ?? '';
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function esc_url_raw( string $url, array $protocols = array() ): string {
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, $protocols, true ) ? $url : '';
}

function wp_json_encode( mixed $value ): string {
	return (string) json_encode( $value );
}

function wp_remote_request( string $url, array $arguments = array() ): array|WP_Error {
	$GLOBALS['src_test_requests'][] = array( 'url' => $url ) + $arguments;
	$next = array_shift( $GLOBALS['src_test_responses'] );
	if ( $next instanceof WP_Error ) {
		return $next;
	}

	return array( 'code' => $next['code'] ?? 200, 'body' => $next['body'] ?? '{"success":true}' );
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

final class Simple_Redis_Cache_Config {
	public static function get(): array {
		return $GLOBALS['src_test_config'];
	}
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-cloudflare.php';

$token  = 'cf-token-do-not-leak';
$config = array(
	'cloudflare' => array(
		'zone_id'              => str_repeat( 'a', 32 ),
		'api_token'            => $token,
		'purge_on_clear_all'   => true,
		'purge_on_clear_page'  => false,
		'purge_on_post_update' => false,
	),
);
$GLOBALS['src_test_config'] = $config;

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$reset = static function ( array $responses = array() ): void {
	$GLOBALS['src_test_requests']  = array();
	$GLOBALS['src_test_responses'] = $responses;
};
$ok = array( 'code' => 200, 'body' => '{"success":true,"errors":[],"result":{}}' );

/* ---- configuration and trigger gating ---- */

$assert( true === Simple_Redis_Cache_Cloudflare::is_configured( $config ), 'A complete configuration was not recognised.' );
$assert( false === Simple_Redis_Cache_Cloudflare::is_configured( array( 'cloudflare' => array( 'zone_id' => 'x' ) ) ), 'A configuration without a token was accepted.' );
$assert( false === Simple_Redis_Cache_Cloudflare::is_configured( array() ), 'An empty configuration was accepted.' );

$assert( true === Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_clear_all', $config ), 'An enabled trigger was reported as disabled.' );
$assert( false === Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_clear_page', $config ), 'A disabled trigger was reported as enabled.' );

$unconfigured = $config;
$unconfigured['cloudflare']['api_token'] = '';
$assert( false === Simple_Redis_Cache_Cloudflare::should_purge_for( 'purge_on_clear_all', $unconfigured ), 'A trigger fired without credentials.' );

/* ---- purge everything ---- */

$reset( array( $ok ) );
$assert( true === Simple_Redis_Cache_Cloudflare::purge_everything( $config ), 'purge_everything() did not report success.' );
$request = $GLOBALS['src_test_requests'][0] ?? array();
$assert( 'https://api.cloudflare.com/client/v4/zones/' . str_repeat( 'a', 32 ) . '/purge_cache' === ( $request['url'] ?? '' ), 'purge_everything() used the wrong endpoint.' );
$assert( 'POST' === ( $request['method'] ?? '' ), 'purge_everything() used the wrong HTTP method.' );
$assert( 'Bearer ' . $token === ( $request['headers']['Authorization'] ?? '' ), 'The API token was not sent as a bearer token.' );
$assert( '{"purge_everything":true}' === ( $request['body'] ?? '' ), 'purge_everything() sent the wrong body.' );

/* ---- URL purging: same-site filtering, dedup, batching ---- */

$reset( array( $ok ) );
$result = Simple_Redis_Cache_Cloudflare::purge_urls(
	array(
		'https://example.test/a/',
		'https://example.test/a/',        // duplicate
		'https://elsewhere.test/b/',      // foreign host
		'ftp://example.test/c/',          // unsupported scheme
		'',                               // empty
	),
	$config
);
$assert( ! is_wp_error( $result ) && 1 === $result['purged'], 'purge_urls() did not reduce the input to one valid URL.' );
$body = json_decode( (string) ( $GLOBALS['src_test_requests'][0]['body'] ?? '' ), true );
$assert( array( 'https://example.test/a/' ) === ( $body['files'] ?? null ), 'purge_urls() sent the wrong file list.' );

$reset( array() );
$assert( array( 'purged' => 0, 'skipped' => 0 ) === Simple_Redis_Cache_Cloudflare::purge_urls( array(), $config ), 'An empty URL list did not short-circuit.' );
$assert( array() === $GLOBALS['src_test_requests'], 'An empty URL list still called the API.' );

// 70 URLs must arrive as three calls of at most 30 each.
$many = array();
for ( $index = 0; $index < 70; $index++ ) {
	$many[] = 'https://example.test/page-' . $index . '/';
}
$reset( array( $ok, $ok, $ok ) );
$result = Simple_Redis_Cache_Cloudflare::purge_urls( $many, $config );
$assert( ! is_wp_error( $result ) && 70 === $result['purged'], 'purge_urls() lost URLs while batching.' );
$assert( 3 === count( $GLOBALS['src_test_requests'] ), 'purge_urls() did not split 70 URLs into three requests.' );
foreach ( $GLOBALS['src_test_requests'] as $index => $sent ) {
	$decoded = json_decode( (string) $sent['body'], true );
	$assert( count( $decoded['files'] ) <= 30, 'Batch ' . $index . ' exceeded the 30-URL Cloudflare limit.' );
}

// Beyond the per-flush ceiling the surplus is reported, never silently dropped.
$huge = array();
for ( $index = 0; $index < 400; $index++ ) {
	$huge[] = 'https://example.test/bulk-' . $index . '/';
}
$reset( array_fill( 0, 20, $ok ) );
$result = Simple_Redis_Cache_Cloudflare::purge_urls( $huge, $config );
$assert( ! is_wp_error( $result ) && 300 === $result['purged'] && 100 === $result['skipped'], 'The per-flush ceiling did not report the skipped remainder.' );

/* ---- error handling ---- */

$reset( array( array( 'code' => 403, 'body' => '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}' ) ) );
$error = Simple_Redis_Cache_Cloudflare::purge_everything( $config );
$assert( is_wp_error( $error ), 'A 403 response was not reported as an error.' );
$message = implode( ' ', $error->get_error_messages() );
$assert( str_contains( $message, 'Authentication error' ), 'The Cloudflare error text was dropped.' );
$assert( str_contains( $message, 'Cache Purge' ), 'A 403 did not explain the required permission.' );

$reset( array( array( 'code' => 404, 'body' => '{"success":false,"errors":[]}' ) ) );
$error = Simple_Redis_Cache_Cloudflare::purge_everything( $config );
$assert( is_wp_error( $error ) && str_contains( implode( ' ', $error->get_error_messages() ), 'zone ID' ), 'A 404 did not point at the zone ID.' );

$reset( array( array( 'code' => 200, 'body' => 'not json' ) ) );
$error = Simple_Redis_Cache_Cloudflare::purge_everything( $config );
$assert( is_wp_error( $error ), 'An unreadable body was accepted as success.' );

$reset( array( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) ) );
$error = Simple_Redis_Cache_Cloudflare::purge_everything( $config );
$assert( is_wp_error( $error ) && str_contains( implode( ' ', $error->get_error_messages() ), 'timeout' ), 'A transport failure lost its cause.' );

$error = Simple_Redis_Cache_Cloudflare::purge_everything( array() );
$assert( is_wp_error( $error ), 'A purge without credentials was attempted.' );

/* ---- verification proves the purge permission, not just validity ---- */

$reset(
	array(
		array( 'code' => 200, 'body' => '{"success":true,"errors":[],"result":{"name":"example.test","status":"active"}}' ),
		$ok,
	)
);
$verified = Simple_Redis_Cache_Cloudflare::verify( $config );
$assert( ! is_wp_error( $verified ) && 'example.test' === $verified['zone'] && 'active' === $verified['status'], 'verify() did not report the zone details.' );
$assert( 2 === count( $GLOBALS['src_test_requests'] ), 'verify() did not both read the zone and prove the purge permission.' );
$assert( 'GET' === ( $GLOBALS['src_test_requests'][0]['method'] ?? '' ), 'verify() did not read the zone first.' );
$purge_body = json_decode( (string) ( $GLOBALS['src_test_requests'][1]['body'] ?? '' ), true );
$assert( array( 'https://example.test/' ) === ( $purge_body['files'] ?? null ), 'verify() did not purge exactly the home page URL.' );

// A read-only token passes the zone read and must still fail verification.
$reset(
	array(
		array( 'code' => 200, 'body' => '{"success":true,"errors":[],"result":{"name":"example.test","status":"active"}}' ),
		array( 'code' => 403, 'body' => '{"success":false,"errors":[{"code":10000,"message":"Actor is not authorized"}]}' ),
	)
);
$assert( is_wp_error( Simple_Redis_Cache_Cloudflare::verify( $config ) ), 'A token without the purge permission passed verification.' );

$assert( is_wp_error( Simple_Redis_Cache_Cloudflare::verify( array() ) ), 'verify() ran without credentials.' );

/* ---- the token must never reach an administrator-visible string ---- */

$reset( array( array( 'code' => 403, 'body' => '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}' ) ) );
$error = Simple_Redis_Cache_Cloudflare::purge_everything( $config );
$assert( ! str_contains( implode( ' ', $error->get_error_messages() ), $token ), 'The API token leaked into an error message.' );

$reset( array( new WP_Error( 'http_request_failed', 'failed for https://api.cloudflare.com with ' . $token ) ) );
$error = Simple_Redis_Cache_Cloudflare::purge_everything( $config );
$assert( str_contains( implode( ' ', $error->get_error_messages() ), 'Could not reach the Cloudflare API' ), 'A transport error was not wrapped.' );

/* ---- the HTML Cache Rule recipe shown on the Cloudflare tab ---- */

$paths_of = static function ( string $expression ): array {
	preg_match_all( '/not \(http\.request\.uri\.path eq "([^"]*)" or starts_with\(http\.request\.uri\.path, "([^"]*)"\)\)/', $expression, $matches, PREG_SET_ORDER );
	$paths = array();
	foreach ( $matches as $match ) {
		// Both halves must describe the same segment: the path itself and everything below it.
		$paths[] = $match[2] === $match[1] . '/' ? $match[1] : 'MISMATCH:' . $match[1] . '|' . $match[2];
	}
	return $paths;
};
$cookies_of = static function ( string $expression ): array {
	preg_match_all( '/not http\.cookie contains "((?:[^"\\\\]|\\\\.)*)"/', $expression, $matches );
	return $matches[1];
};

$rule = Simple_Redis_Cache_Cloudflare::html_cache_rule(
	'Example.TEST',
	array( '/wp-admin/', '/wp-login.php', '/wp-json/', '/wp-cron.php', '/wp-admin/' ),
	array( 'comment_author_', 'wordpress_logged_in_', 'my_cart_*' ),
	'/'
);
$expression = $rule['expression'];
$assert( str_starts_with( $expression, '(http.host eq "example.test" and ' ) && str_ends_with( $expression, ')' ), 'The rule is not a single parenthesised expression scoped to the lowercased host.' );
$assert( array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php' ) === $paths_of( $expression ), 'The rule did not exclude each service path exactly once, without its trailing slash.' );
$assert( ! str_contains( $expression, "\n" ), 'The rule spans several lines; a single line is the only form guaranteed to paste cleanly.' );

$cookies = $cookies_of( $expression );
$assert( in_array( 'wordpress_logged_in_', $cookies, true ) && in_array( 'wp-postpass_', $cookies, true ), 'The auth and post-password cookies are not always excluded.' );
$assert( 1 === count( array_keys( $cookies, 'wordpress_logged_in_', true ) ), 'A cookie listed twice produced two clauses.' );
$assert( in_array( 'comment_author_', $cookies, true ), 'A configured excluded cookie did not reach the rule.' );
$assert( in_array( 'my_cart_', $cookies, true ), 'A trailing-wildcard pattern was not reduced to its literal prefix.' );

// The required cookies stay even when the site's own list is empty.
$bare = Simple_Redis_Cache_Cloudflare::html_cache_rule( 'example.test', array(), array(), '/' );
$assert( array( 'wordpress_logged_in_', 'wp-postpass_' ) === $cookies_of( $bare['expression'] ), 'Clearing Excluded request cookies also dropped the auth cookie from the edge rule.' );

// Patterns too vague for a free-plan rule are reported, never silently dropped.
$vague = Simple_Redis_Cache_Cloudflare::html_cache_rule( 'example.test', array(), array( '*session*', 'x?', 'ab*', 'ok_cookie' ), '/' );
$assert( array( '*session*', 'x?', 'ab*' ) === $vague['skipped'], 'Vague cookie patterns were not reported back.' );
$assert( in_array( 'ok_cookie', $cookies_of( $vague['expression'] ), true ), 'A valid pattern was lost next to vague ones.' );

// A path that covers the home URL would switch the rule off for the whole site.
// Plain permalinks put REST at home_url('?rest_route=/'), whose path is the home.
$root_plain = Simple_Redis_Cache_Cloudflare::html_cache_rule( 'example.test', array( '/wp-admin/', '/', '/wp-cron.php' ), array(), '/' );
$assert( array( '/wp-admin', '/wp-cron.php' ) === $paths_of( $root_plain['expression'] ), 'A root REST path excluded the whole site from the rule.' );

$sub_plain = Simple_Redis_Cache_Cloudflare::html_cache_rule( 'example.test', array( '/blog/wp-admin/', '/blog/', '/blog/wp-cron.php' ), array(), '/blog/' );
$assert( array( '/blog/wp-admin', '/blog/wp-cron.php' ) === $paths_of( $sub_plain['expression'] ), 'A subdirectory home path excluded the whole site from the rule.' );

$ancestor = Simple_Redis_Cache_Cloudflare::html_cache_rule( 'example.test', array( '/', '/sites', '/sites/blog' ), array(), '/sites/blog' );
$assert( array() === $paths_of( $ancestor['expression'] ), 'A path above the home URL was kept although it covers every page.' );

$sibling = Simple_Redis_Cache_Cloudflare::html_cache_rule( 'example.test', array( '/blogger/admin' ), array(), '/blog/' );
$assert( array( '/blogger/admin' ) === $paths_of( $sibling['expression'] ), 'A sibling path sharing a text prefix with the home was mistaken for it.' );

// A path is excluded as a whole segment, so a short custom login slug such as
// /go does not drag /golf/ or /google-ads/ out of the edge cache with it.
$short = Simple_Redis_Cache_Cloudflare::html_cache_rule( 'example.test', array( '/go/' ), array(), '/' );
$assert( ! preg_match( '/starts_with\(http\.request\.uri\.path, "\/go"\)/', $short['expression'] ), 'A path was excluded as a bare prefix, which also excludes unrelated pages that merely start with it.' );
$assert( array( '/go' ) === $paths_of( $short['expression'] ), 'The segment exclusion for a short path is malformed.' );

// Values are quoted as rule-language strings, so a stray quote cannot break out.
$quoted = Simple_Redis_Cache_Cloudflare::html_cache_rule( 'example.test', array(), array( 'bad"name', 'back\\slash' ), '/' );
$assert( str_contains( $quoted['expression'], 'contains "bad\\"name"' ), 'A double quote in a cookie pattern was not escaped.' );
$assert( str_contains( $quoted['expression'], 'contains "back\\\\slash"' ), 'A backslash in a cookie pattern was not escaped.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: the Cloudflare client batches, filters, verifies purge permission, and keeps the token out of messages.\n";
