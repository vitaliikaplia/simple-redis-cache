<?php
/**
 * A copied or moved site must not run, invalidate or keep the original site's
 * cache identity: it regenerates its own on the next administrator request.
 *
 * Usage: php tests/copy-resync.php
 */

declare(strict_types=1);

$test_content = sys_get_temp_dir() . '/src-copy-' . bin2hex( random_bytes( 6 ) );
mkdir( $test_content );
// Even an aborted run must not leave a read-only directory behind.
register_shutdown_function(
	static function () use ( $test_content ): void {
		@chmod( $test_content, 0755 );
		foreach ( glob( $test_content . '/*' ) ?: array() as $leftover ) {
			@unlink( $leftover );
		}
		@rmdir( $test_content );
	}
);

define( 'ABSPATH', $test_content . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SIMPLE_REDIS_CACHE_BASENAME', 'simple-redis-cache/simple-redis-cache.php' );

$GLOBALS['src_option']  = null;
$GLOBALS['src_applied'] = 0;

final class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}

	/** @return string[] */
	public function get_error_messages(): array {
		return array( $this->message );
	}
}

final class Src_Test_Wpdb {
	public string $options = 'wp_options';

	public function prepare( string $query, mixed ...$args ): string {
		return $query;
	}

	public function get_var( string $query ): ?string {
		return null === $GLOBALS['src_option'] ? null : serialize( $GLOBALS['src_option'] );
	}
}
$GLOBALS['wpdb'] = new Src_Test_Wpdb();

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function load_plugin_textdomain( string $domain, bool $deprecated = false, string $path = '' ): bool {
	return true;
}

function maybe_unserialize( mixed $value ): mixed {
	return is_string( $value ) ? unserialize( $value ) : $value;
}

function get_option( string $name, mixed $default_value = false ): mixed {
	return $GLOBALS['src_option'] ?? $default_value;
}

/** Mirrors core: the update_option_{$option} hook fires after a changed value is stored. */
function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	$old                   = $GLOBALS['src_option'];
	$GLOBALS['src_option'] = $value;
	Simple_Redis_Cache_Plugin::settings_updated( $old, $value, $name );
	return true;
}

function sanitize_text_field( string $value ): string {
	return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function home_url( string $path = '' ): string {
	return 'https://copy.example.test' . $path;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}

function is_user_logged_in(): bool {
	return true;
}

function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability;
}

function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length );
}

final class Simple_Redis_Cache_Admin {
	/** @var array<int, array{message:string,type:string}> */
	public static array $notices = array();

	public static function queue_notice( string $message, string $type = 'info' ): void {
		self::$notices[] = array( 'message' => $message, 'type' => $type );
	}
}

final class Simple_Redis_Cache_Dropins {
	public static int $synced = 0;
	public static bool $fail  = false;

	public static function sync( ?array $config = null ): bool|WP_Error {
		++self::$synced;
		return self::$fail ? new WP_Error( 'src_dropin_install_failed', 'Could not install the drop-in.' ) : true;
	}

	public static function remove_all(): bool {
		return true;
	}
}

/** Records which namespace every bump would have touched. */
final class Simple_Redis_Cache_Redis {
	public static int $constructed = 0;
	/** @var string[] */
	public static array $prefixes = array();

	public function __construct( ?array $config = null ) {
		++self::$constructed;
		self::$prefixes[] = Simple_Redis_Cache_Early_Config::prefix( $config );
	}

	public function bump_generation( string $namespace ): int|false {
		return 1;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-plugin.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

// The original site's saved settings and generated config, copied verbatim.
$original = Simple_Redis_Cache_Early_Config::defaults();
$original['object']['enabled']         = true;
$original['page']['enabled']           = true;
$original['redis']['password']         = 'shared-secret';
$original['site']['host']              = 'example.test';
$original['site']['port']              = 443;
$original['site']['fallback_prefix']   = 'original-prefix';
$original['site']['identity']          = str_repeat( 'e', 32 );
$original['site']['secret']            = str_repeat( 'a', 64 );
$original['cloudflare']['zone_id']     = str_repeat( 'c', 32 );
$original['cloudflare']['api_token']   = 'production-token';
$original['cloudflare']['purge_on_clear_page'] = true;
$GLOBALS['src_option']                 = $original;
Simple_Redis_Cache_Config::write_early_config( $original );

$runtime = Simple_Redis_Cache_Config::runtime( true );
$assert( is_array( $runtime ) && ! empty( $runtime['site']['copy_detected'] ), 'The copied runtime was not recognized as another installation\'s.' );
$assert( is_array( $runtime ) && false === $runtime['object']['enabled'] && false === $runtime['page']['enabled'], 'The copied runtime would still run both cache layers.' );
$assert( Simple_Redis_Cache_Config::is_foreign(), 'The copied saved settings were not recognized as foreign.' );

$resync = new ReflectionMethod( Simple_Redis_Cache_Plugin::class, 'maybe_resync_copy' );
$resync->invoke( null );

$saved = (array) $GLOBALS['src_option'];
$assert( Simple_Redis_Cache_Early_Config::install_identity() === ( $saved['site']['identity'] ?? '' ), 'The copy did not record its own installation identity.' );
$assert( 'original-prefix' !== ( $saved['site']['fallback_prefix'] ?? '' ), 'The copy kept the original site\'s key prefix.' );
$assert( 'copy.example.test' === ( $saved['site']['host'] ?? '' ), 'The copy kept the original site\'s host.' );
$assert( str_repeat( 'a', 64 ) !== ( $saved['site']['secret'] ?? '' ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $saved['site']['secret'] ?? '' ) ), 'The copy kept the original site\'s page secret.' );
$assert( 'shared-secret' === ( $saved['redis']['password'] ?? '' ) && true === ( $saved['object']['enabled'] ?? null ), 'The resync lost the copied connection or cache settings.' );
$assert( '' === ( $saved['cloudflare']['zone_id'] ?? null ) && '' === ( $saved['cloudflare']['api_token'] ?? null ) && empty( $saved['cloudflare']['purge_on_clear_page'] ), 'The copy kept the original site\'s Cloudflare zone and token, so it could purge production\'s zone.' );
$assert( ! Simple_Redis_Cache_Config::is_foreign(), 'The regenerated settings still look foreign.' );
$assert( 0 === Simple_Redis_Cache_Redis::$constructed, 'The resync touched Redis, which would invalidate the original site\'s namespace.' );
$assert( 1 === Simple_Redis_Cache_Dropins::$synced, 'The resync did not reinstall the drop-ins for this installation.' );

$after = Simple_Redis_Cache_Config::runtime( true );
$assert( is_array( $after ) && empty( $after['site']['copy_detected'] ) && true === $after['page']['enabled'], 'The regenerated early config is still inert.' );
$assert( is_array( $after ) && ! array_key_exists( 'copy_detected', (array) $after['site'] ), 'The runtime-only copy flag leaked into the generated config.' );
$assert( 2 === count( Simple_Redis_Cache_Admin::$notices ) && array( 'warning', 'warning' ) === array_column( Simple_Redis_Cache_Admin::$notices, 'type' ), 'The administrator was not told once about the copy and once about the removed Cloudflare details.' );

// A second request on the same installation has nothing to resync.
Simple_Redis_Cache_Admin::$notices = array();
$resync->invoke( null );
$assert( array() === Simple_Redis_Cache_Admin::$notices && 1 === Simple_Redis_Cache_Dropins::$synced, 'An already regenerated site was resynced again.' );

$original_prefix = Simple_Redis_Cache_Early_Config::prefix( $original );
$reset_world     = static function () use ( $original ): void {
	$GLOBALS['src_option'] = $original;
	Simple_Redis_Cache_Config::write_early_config( $original );
	Simple_Redis_Cache_Early_Config::reset();
	Simple_Redis_Cache_Admin::$notices   = array();
	Simple_Redis_Cache_Redis::$prefixes  = array();
	Simple_Redis_Cache_Dropins::$synced  = 0;
	Simple_Redis_Cache_Dropins::$fail    = false;
	( new ReflectionProperty( Simple_Redis_Cache_Plugin::class, 'last_config_hash' ) )->setValue( null, null );
	( new ReflectionProperty( Simple_Redis_Cache_Plugin::class, 'settings_attempts' ) )->setValue( null, array() );
};

// A. The copy cannot install its new config: nothing may then fall back to
// recovering from the copied runtime, which would bump the original's namespace.
$reset_world();
Simple_Redis_Cache_Dropins::$fail = true;
for ( $request = 1; $request <= 3; $request++ ) {
	Simple_Redis_Cache_Plugin::admin_maintenance();
}
$assert( ! in_array( $original_prefix, Simple_Redis_Cache_Redis::$prefixes, true ), 'A copy that could not install its config invalidated the original site\'s namespace.' );
$assert( 'error' === ( Simple_Redis_Cache_Admin::$notices[0]['type'] ?? '' ), 'A failed copy resync was reported as a success.' );
$assert( ! in_array( 'warning', array_column( Simple_Redis_Cache_Admin::$notices, 'type' ), true ), 'A failed copy resync still claimed the site got its own namespace.' );

// A2. wp-content cannot be written at all, so the copied runtime stays in place.
$reset_world();
chmod( $test_content, 0555 );
for ( $request = 1; $request <= 3; $request++ ) {
	Simple_Redis_Cache_Plugin::admin_maintenance();
}
chmod( $test_content, 0755 );
$assert( ! empty( Simple_Redis_Cache_Config::runtime( true )['site']['copy_detected'] ), 'The read-only fixture did not keep the copied runtime in place.' );
$assert( ! in_array( $original_prefix, Simple_Redis_Cache_Redis::$prefixes, true ), 'A copy with an unwritable wp-content invalidated the original site\'s namespace on every admin request.' );
$assert( 'error' === ( Simple_Redis_Cache_Admin::$notices[0]['type'] ?? '' ), 'A copy that could not write its config was not reported as an error.' );

// Recovery on its own must refuse a copied runtime, whatever ran before it.
$reset_world();
$GLOBALS['src_option'] = Simple_Redis_Cache_Config::sanitize( $original );
( new ReflectionMethod( Simple_Redis_Cache_Plugin::class, 'maybe_recover_runtime' ) )->invoke( null );
$assert( ! in_array( $original_prefix, Simple_Redis_Cache_Redis::$prefixes, true ), 'Recovery from a copied runtime invalidated the original site\'s namespace.' );

// B. Only the saved settings were imported; this site's own config is current.
$reset_world();
$own = Simple_Redis_Cache_Config::sanitize( $original );
Simple_Redis_Cache_Config::write_early_config( $own );
$GLOBALS['src_option'] = $original;
Simple_Redis_Cache_Early_Config::reset();
Simple_Redis_Cache_Plugin::admin_maintenance();
$assert( ! in_array( $original_prefix, Simple_Redis_Cache_Redis::$prefixes, true ), 'Imported settings were recovered into the original site\'s namespace.' );
$assert( ! Simple_Redis_Cache_Config::is_foreign(), 'Imported foreign settings were not regenerated for this site.' );

// C. No generated config at all, and the first save happens through Settings.
$reset_world();
@unlink( Simple_Redis_Cache_Early_Config::path() );
Simple_Redis_Cache_Early_Config::reset();
$saved = Simple_Redis_Cache_Config::sanitize( $original );
update_option( Simple_Redis_Cache_Config::OPTION, $saved );
$assert( ! in_array( $original_prefix, Simple_Redis_Cache_Redis::$prefixes, true ), 'Saving settings on a copy without a generated config invalidated the original site\'s namespace.' );

// Deactivating the plugin on a copy that never resynced must not clear the original.
$reset_world();
( new ReflectionMethod( Simple_Redis_Cache_Plugin::class, 'invalidate_lifecycle_namespaces' ) )->invoke( null, $original );
$assert( array() === Simple_Redis_Cache_Redis::$prefixes, 'A lifecycle change on a copy invalidated the original site\'s namespace.' );

// A shared WP_CACHE_KEY_SALT keeps the copy inside the original's namespace, so
// both layers stay off until an administrator deals with it. Defined last: it
// changes the prefix of everything after it.
$reset_world();
define( 'WP_CACHE_KEY_SALT', 'shared-salt' );
Simple_Redis_Cache_Plugin::admin_maintenance();
$salted = (array) $GLOBALS['src_option'];
$assert( false === ( $salted['object']['enabled'] ?? null ) && false === ( $salted['page']['enabled'] ?? null ), 'A copy sharing WP_CACHE_KEY_SALT was re-enabled inside the original site\'s namespace.' );
$assert( ! Simple_Redis_Cache_Config::is_foreign(), 'A copy sharing WP_CACHE_KEY_SALT was not given its own identity.' );
$assert( str_contains( Simple_Redis_Cache_Admin::$notices[0]['message'] ?? '', 'WP_CACHE_KEY_SALT' ), 'The administrator was not told why the layers were turned off.' );

// A deploy script must see a failed edge purge: the CLI command fails then.
final class WP_CLI {
	/** @var array<int, array{string, string}> */
	public static array $calls = array();

	public static function success( string $message ): void {
		self::$calls[] = array( 'success', $message );
	}

	public static function error( string $message ): void {
		self::$calls[] = array( 'error', $message );
		throw new RuntimeException( 'WP_CLI::error' );
	}
}

final class Simple_Redis_Cache_Purger {
	public static bool $cloudflare_fails = false;

	public static function purge( string $scope = 'all' ): array {
		return self::$cloudflare_fails
			? array( 'page_generation' => 2, 'cloudflare' => false, 'cloudflare_error' => 'Actor is not authorized.' )
			: array( 'page_generation' => 2, 'cloudflare' => true );
	}
}

Simple_Redis_Cache_Plugin::cli_purge( array( 'page' ) );
$assert( array( 'success' ) === array_column( WP_CLI::$calls, 0 ), 'A clean CLI purge did not report success.' );
WP_CLI::$calls                               = array();
Simple_Redis_Cache_Purger::$cloudflare_fails = true;
try {
	Simple_Redis_Cache_Plugin::cli_purge( array( 'page' ) );
} catch ( RuntimeException $exception ) {
	// WP_CLI::error() exits; the stub throws instead.
}
$assert( array( 'error' ) === array_column( WP_CLI::$calls, 0 ) && str_contains( WP_CLI::$calls[0][1], 'Actor is not authorized.' ), 'A CLI purge whose Cloudflare part failed still exited successfully or hid the error.' );

@unlink( Simple_Redis_Cache_Early_Config::path() );
@rmdir( $test_content );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: a copied site keeps the original's runtime inert and regenerates its own cache identity.\n";
