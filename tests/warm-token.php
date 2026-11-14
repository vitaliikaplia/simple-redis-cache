<?php
/**
 * The cache-state header is reported to the warmer only, not to anyone who sends
 * X-Simple-Redis-Cache-Warm: 1. A request needs a short-lived token signed with
 * the site's page secret; the warming screen and the server-side fallback send one.
 *
 * Run directly with: php tests/warm-token.php
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$test_content = sys_get_temp_dir() . '/src-warm-token-' . bin2hex( random_bytes( 6 ) );
mkdir( $test_content );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'SIMPLE_REDIS_CACHE_VERSION', '0.0.0-test' );

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

// The loader runs itself on include; with no generated config it does nothing.
require_once dirname( __DIR__ ) . '/dropins/advanced-cache-loader.php';

/* -------------------------------------------------------------------------
 * 1. Minting and checking a token.
 * ---------------------------------------------------------------------- */

$secret   = bin2hex( random_bytes( 32 ) );
$now      = time();
$lifetime = Simple_Redis_Cache_Early_Config::WARM_TOKEN_LIFETIME;
$token    = Simple_Redis_Cache_Early_Config::warm_token( $secret, $now + $lifetime );
$valid    = static fn( string $value, string $key = '', ?int $at = null ): bool => Simple_Redis_Cache_Early_Config::is_valid_warm_token( $value, '' === $key ? $secret : $key, $at ?? $now );

$assert( 1 === preg_match( '/^\d+\.[a-f0-9]{64}$/D', $token ), 'A warming token does not have the form {expires}.{hmac}.' );
$assert( $valid( $token ), 'A fresh warming token was refused.' );
$assert( $valid( " {$token} " ), 'Surrounding whitespace made a valid token fail.' );
$assert( $valid( $token, '', $now + $lifetime ), 'A token was refused in its last valid second.' );
$assert( ! $valid( $token, '', $now + $lifetime + 1 ), 'An expired warming token was accepted.' );
$assert( ! $valid( '1' ), 'The legacy header value 1 was accepted as a warming token.' );
$assert( ! $valid( $token, bin2hex( random_bytes( 32 ) ) ), 'A token signed with another site\'s secret was accepted.' );
$assert( ! Simple_Redis_Cache_Early_Config::is_valid_warm_token( $token, '', $now ), 'A site without a page secret accepted a warming token.' );
$assert( '' === Simple_Redis_Cache_Early_Config::warm_token( '', $now + 60 ), 'A site without a page secret minted a warming token.' );

// Without a secret anyone could sign with the empty key; such a token must not count.
$context        = ( new ReflectionClassConstant( Simple_Redis_Cache_Early_Config::class, 'WARM_TOKEN_CONTEXT' ) )->getValue();
$empty_key_mac  = static fn( int $at ): string => $at . '.' . hash_hmac( 'sha256', $context . "\0" . $at, '' );
$assert( ! Simple_Redis_Cache_Early_Config::is_valid_warm_token( $empty_key_mac( $now + 600 ), '', $now ), 'A site without a page secret accepted a token signed with the empty key.' );

// Nobody holding the secret mints a token that lives longer than the lifetime plus a small skew.
$far = Simple_Redis_Cache_Early_Config::warm_token( $secret, $now + $lifetime + 3600 );
$assert( ! $valid( $far ), 'A token claiming to live far longer than its lifetime was accepted.' );
$assert( $valid( Simple_Redis_Cache_Early_Config::warm_token( $secret, $now + $lifetime + 30 ) ), 'A token minted by a server whose clock runs slightly ahead was refused.' );

[ $expires, $mac ] = explode( '.', $token );
$assert( ! $valid( ( (int) $expires + 1 ) . '.' . $mac ), 'A token whose expiry was changed was accepted.' );
$assert( ! $valid( $expires . '.' . strtoupper( $mac ) ), 'An upper-case MAC was accepted.' );
$assert( ! $valid( '0' . $token ), 'A zero-padded expiry was accepted.' );
$assert( ! $valid( $token . 'x' ), 'Trailing garbage after a token was accepted.' );
$assert( ! $valid( $expires . '.' ), 'A token without a MAC was accepted.' );
// A page payload MAC made with the same secret is not a token for the same number.
$assert( ! $valid( $expires . '.' . Simple_Redis_Cache_Early_Config::page_payload_mac( '', $expires, $secret ) ), 'A page payload MAC was accepted as a warming token.' );

/* -------------------------------------------------------------------------
 * 2. The early runtime reports the cache state only with a valid token.
 * ---------------------------------------------------------------------- */

$loader  = new ReflectionClass( Simple_Redis_Cache_Advanced_Cache_Loader::class );
$is_warm = $loader->getMethod( 'is_warm_request' );
$warm    = static function ( mixed $header, string $key ) use ( $is_warm ): bool {
	$_SERVER = array( 'REQUEST_METHOD' => 'GET' );
	if ( null !== $header ) {
		$_SERVER['HTTP_X_SIMPLE_REDIS_CACHE_WARM'] = $header;
	}
	return (bool) $is_warm->invoke( null, $key );
};

$assert( ! $warm( '1', $secret ), 'X-Simple-Redis-Cache-Warm: 1 still unlocks the cache-state header for anyone.' );
$assert( ! $warm( null, $secret ), 'A request without the header counts as a warming request.' );
$assert( ! $warm( '', $secret ), 'An empty header counts as a warming request.' );
$assert( ! $warm( array( $token ), $secret ), 'A non-string header value counts as a warming request.' );
$assert( $warm( Simple_Redis_Cache_Early_Config::warm_token( $secret, time() + 600 ), $secret ), 'A request with a valid token is not recognised as warming.' );
$assert( ! $warm( Simple_Redis_Cache_Early_Config::warm_token( $secret, time() - 1 ), $secret ), 'A request with an expired token is recognised as warming.' );
$assert( ! $warm( Simple_Redis_Cache_Early_Config::warm_token( $secret, time() + 600 ), '' ), 'A runtime without a page secret honoured a warming token.' );
$assert( ! $warm( $empty_key_mac( time() + 600 ), '' ), 'A runtime without a page secret honoured a token anyone can sign with the empty key.' );

$loader_source = (string) file_get_contents( dirname( __DIR__ ) . '/dropins/advanced-cache-loader.php' );
$assert( (bool) preg_match( '/\$warm\s*=\s*self::is_warm_request\(\s*\$secret\s*\)/', $loader_source ), 'The runtime no longer checks the warming token against the page secret.' );
$assert( (bool) preg_match( "/if\\s*\\(\\s*\\\$warm_request\\s*\\)\\s*\\{[\\s\\S]{0,600}header\\(\\s*'Cache-Control: private, no-store',\\s*true\\s*\\)/", $loader_source ), 'A warming HIT no longer replaces its Cache-Control with private, no-store.' );

/* -------------------------------------------------------------------------
 * 3. The server-side fallback sends a fresh valid token.
 * ---------------------------------------------------------------------- */

/** Stand-in: the warmer reads the page secret through the plugin config. */
final class Simple_Redis_Cache_Config {
	/** @var array<string, mixed> */
	public static array $config = array();

	/** @return array<string, mixed>|null */
	public static function runtime(): ?array {
		return self::$config;
	}

	/** @return array<string, mixed> */
	public static function get(): array {
		return self::$config;
	}
}

final class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}

	public function get_error_message(): string {
		return $this->message;
	}
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function esc_url_raw( string $url, ?array $protocols = null ): string {
	unset( $protocols );
	return $url;
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_remote_request( string $url, array $args ): array {
	$GLOBALS['src_test_requests'][] = array( 'url' => $url, 'args' => $args );
	$token = (string) ( $args['headers']['X-Simple-Redis-Cache-Warm'] ?? '' );
	$state = Simple_Redis_Cache_Early_Config::is_valid_warm_token( $token, (string) ( Simple_Redis_Cache_Config::$config['site']['secret'] ?? '' ) ) ? 'HIT' : '';
	return array( 'code' => 200, 'state' => $state );
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) $response['code'];
}

function wp_remote_retrieve_header( array $response, string $name ): string {
	return 'x-simple-redis-cache' === $name ? (string) $response['state'] : '';
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-warmer.php';

Simple_Redis_Cache_Config::$config = array( 'site' => array( 'secret' => $secret ) );
$GLOBALS['src_test_requests']      = array();
$result = Simple_Redis_Cache_Warmer::warm_remote_url( 'https://example.test/news/' );
$sent   = (string) ( $GLOBALS['src_test_requests'][0]['args']['headers']['X-Simple-Redis-Cache-Warm'] ?? '' );
$assert( 'cached' === ( $result['result'] ?? '' ), 'The server-side fallback did not see the cache state with its own token.' );
$assert( Simple_Redis_Cache_Early_Config::is_valid_warm_token( $sent, $secret ), 'The server-side fallback did not send a valid warming token.' );
$assert( '1' !== $sent, 'The server-side fallback still sends the legacy value 1.' );

Simple_Redis_Cache_Config::$config = array( 'site' => array( 'secret' => '' ) );
$GLOBALS['src_test_requests']      = array();
Simple_Redis_Cache_Warmer::warm_remote_url( 'https://example.test/news/' );
$assert( ! isset( $GLOBALS['src_test_requests'][0]['args']['headers']['X-Simple-Redis-Cache-Warm'] ), 'Without a page secret the fallback still sent a warming header.' );

/* -------------------------------------------------------------------------
 * 4. The warming screen receives a token and sends it.
 * ---------------------------------------------------------------------- */

$admin_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-simple-redis-cache-admin.php' );
$js_source    = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-warm-cache.js' );
$assert( (bool) preg_match( "/'SimpleRedisCacheWarm',\\s*self::warm_token_data\\(\\)/", $admin_source ), 'The warming screen no longer receives a token with the page.' );
$assert( (bool) preg_match( '/function ajax_prepare_warm\(\)[\s\S]{0,2000}self::warm_token_data\(\)/', $admin_source ), 'Preparing a warming run no longer returns a fresh token.' );
$assert( (bool) preg_match( '/function ajax_warm_token\(\)[\s\S]{0,200}self::authorize_ajax_warm\(\)/', $admin_source ), 'Token renewal is not limited to authorised warming requests.' );
$assert( (bool) preg_match( "/headers\\[\\s*'X-Simple-Redis-Cache-Warm'\\s*\\]\\s*=\\s*warmToken/", $js_source ), 'The browser no longer sends the warming token.' );
$assert( ! str_contains( $js_source, "'X-Simple-Redis-Cache-Warm': '1'" ), 'The browser still sends the legacy header value 1.' );
$assert( (bool) preg_match( '/acceptToken\(\s*prepared\s*\)/', $js_source ) && (bool) preg_match( '/await renewTokenIfDue\(/', $js_source ), 'The browser no longer refreshes its warming token for a run.' );

@rmdir( $test_content );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: only a valid, short-lived warming token unlocks the cache-state header.\n";
