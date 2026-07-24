<?php
/**
 * Redis invalidation failure -> disabled runtime -> recovery regression test.
 *
 * Usage: php tests/settings-recovery-integration.php
 * Optional environment: SRC_TEST_REDIS_HOST, SRC_TEST_REDIS_PORT.
 */

declare(strict_types=1);

$test_content = sys_get_temp_dir() . '/simple-redis-cache-recovery-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $test_content, 0700 ) && ! is_dir( $test_content ) ) {
	throw new RuntimeException( 'Could not create the settings-recovery test directory.' );
}

define( 'ABSPATH', $test_content . '/' );
define( 'WP_CONTENT_DIR', $test_content );
define( 'SIMPLE_REDIS_CACHE_DIR', dirname( __DIR__ ) . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		/** @var array<string, string[]> */
		private array $errors = array();
		/** @var array<string, mixed> */
		private array $data = array();

		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( string $code, string $message, mixed $data = null ): void {
			$this->errors[ $code ][] = $message;
			if ( null !== $data ) {
				$this->data[ $code ] = $data;
			}
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}

		/** @return string[] */
		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		/** @return string[] */
		public function get_error_messages( string $code = '' ): array {
			if ( '' !== $code ) {
				return $this->errors[ $code ] ?? array();
			}

			return array_merge( ...array_values( $this->errors ?: array( array() ) ) );
		}

		public function get_error_data( string $code = '' ): mixed {
			return $this->data[ $code ] ?? null;
		}
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
	return substr( bin2hex( random_bytes( max( 1, $length ) ) ), 0, $length );
}

function get_option( string $name, mixed $default = false ): mixed {
	if ( 'simple_redis_cache_settings' !== $name ) {
		return $default;
	}
	if ( is_callable( $GLOBALS['src_test_option_reader'] ?? null ) ) {
		return $GLOBALS['src_test_option_reader']( $default );
	}

	return $GLOBALS['src_test_saved_config'] ?? $default;
}

final class Simple_Redis_Cache_Admin {
	/** @var array<int, array{message:string,type:string}> */
	public static array $notices = array();

	public static function queue_notice( string $message, string $type = 'info' ): void {
		self::$notices[] = array( 'message' => $message, 'type' => $type );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-early-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-redis.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-config.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-dropins.php';
require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-plugin.php';

$remove_tree = static function ( string $path ) use ( &$remove_tree ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) ?: array() as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			$remove_tree( $path . DIRECTORY_SEPARATOR . $item );
		}
	}
	@rmdir( $path );
};

if ( ! extension_loaded( 'redis' ) ) {
	$remove_tree( $test_content );
	fwrite( STDERR, "SKIP: PhpRedis is not installed.\n" );
	exit( 0 );
}

$host = getenv( 'SRC_TEST_REDIS_HOST' ) ?: '127.0.0.1';
$port = (int) ( getenv( 'SRC_TEST_REDIS_PORT' ) ?: 6379 );
$probe = new Redis();
try {
	if ( ! $probe->connect( $host, $port, 0.5, null, 0, 0.5 ) ) {
		throw new RuntimeException( 'connect returned false' );
	}
} catch ( Throwable $throwable ) {
	$remove_tree( $test_content );
	fwrite( STDERR, 'SKIP: Redis is unavailable: ' . $throwable->getMessage() . "\n" );
	exit( 0 );
}

$config = Simple_Redis_Cache_Early_Config::defaults();
$config['redis']['host'] = $host;
$config['redis']['port'] = $port;
$config['redis']['timeout'] = 0.5;
$config['redis']['read_timeout'] = 0.5;
$config['object']['enabled'] = true;
$config['page']['enabled'] = false;
$config['site'] = array(
	'host'            => 'recovery.example.test',
	'port'            => 443,
	'fallback_prefix' => 'src-recovery-test-' . bin2hex( random_bytes( 8 ) ),
);

$generation_key = Simple_Redis_Cache_Early_Config::meta_key( 'object-generation', $config );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

try {
	$probe->del( $generation_key );
	$GLOBALS['src_test_saved_config'] = $config;
	$assert( Simple_Redis_Cache_Plugin::synchronize( $config, false ), 'Initial runtime synchronization failed.' );
	$assert( is_file( WP_CONTENT_DIR . '/object-cache.php' ), 'Initial object-cache drop-in was not installed.' );

	// An invalid generation simulates a Redis state that cannot be safely bumped.
	$probe->set( $generation_key, 'invalid-generation' );
	$changed = $config;
	$changed['object']['max_ttl'] = 3600;
	$GLOBALS['src_test_saved_config'] = $changed;
	$assert( ! Simple_Redis_Cache_Plugin::apply_saved_config( $config, $changed ), 'Unsafe settings were applied after invalidation failure.' );

	$deferred = Simple_Redis_Cache_Config::runtime();
	$assert( is_array( $deferred ), 'Deferred runtime config was not retained for retry.' );
	$assert( empty( $deferred['object']['enabled'] ), 'Affected object-cache runtime was not disabled.' );
	$assert( ! is_file( WP_CONTENT_DIR . '/object-cache.php' ), 'Affected object-cache drop-in was not removed.' );
	$assert( 3600 !== (int) $deferred['object']['max_ttl'], 'Unapplied object-cache semantics leaked into the early runtime.' );

	// Repair Redis metadata and retry the saved settings from the disabled runtime.
	$probe->del( $generation_key );
	$assert( Simple_Redis_Cache_Plugin::apply_saved_config( $deferred, $changed ), 'Deferred settings did not recover after Redis was repaired.' );
	$recovered = Simple_Redis_Cache_Config::runtime();
	$assert( is_array( $recovered ) && ! empty( $recovered['object']['enabled'] ), 'Object-cache runtime was not re-enabled after recovery.' );
	$assert( 3600 === (int) $recovered['object']['max_ttl'], 'Recovered runtime did not apply the saved object-cache semantics.' );
	$assert( is_file( WP_CONTENT_DIR . '/object-cache.php' ), 'Object-cache drop-in was not reinstalled after recovery.' );

	// A recovery request must not apply an older snapshot over newer DB settings.
	$stale = $recovered;
	$stale['object']['max_ttl'] = 7200;
	$authoritative = $recovered;
	$authoritative['object']['max_ttl'] = 1800;
	$GLOBALS['src_test_saved_config'] = $authoritative;
	$assert( Simple_Redis_Cache_Plugin::apply_saved_config( $recovered, $stale ), 'Authoritative settings revalidation failed.' );
	$revalidated = Simple_Redis_Cache_Config::runtime();
	$assert( is_array( $revalidated ) && 1800 === (int) $revalidated['object']['max_ttl'], 'A stale recovery snapshot overwrote authoritative DB settings.' );

	/*
	 * Simulate another request saving and synchronizing page-cache settings while
	 * this request is blocked in an object-generation bump that ultimately fails.
	 * The post-failure option read performs the concurrent filesystem switch.
	 */
	$probe->set( $generation_key, 'invalid-generation' );
	$during_failure = $revalidated;
	$during_failure['object']['max_ttl'] = 1200;
	$concurrent = $during_failure;
	$concurrent['page']['enabled'] = true;
	$concurrent['page']['ttl'] = 900;
	$GLOBALS['src_test_saved_config'] = $during_failure;
	$option_reads = 0;
	$GLOBALS['src_test_option_reader'] = static function ( mixed $default ) use ( &$option_reads, $during_failure, $concurrent, $assert ): mixed {
		++$option_reads;
		if ( 1 === $option_reads ) {
			return $during_failure;
		}
		if ( 2 === $option_reads ) {
			$GLOBALS['src_test_saved_config'] = $concurrent;
			$written = Simple_Redis_Cache_Config::write_early_config( $concurrent );
			$assert( ! is_wp_error( $written ), 'Concurrent runtime config could not be simulated.' );
			$installed = Simple_Redis_Cache_Dropins::install( 'page' );
			$assert( ! is_wp_error( $installed ), 'Concurrent page drop-in could not be simulated.' );
			return $concurrent;
		}

		return $GLOBALS['src_test_saved_config'] ?? $default;
	};

	$assert( ! Simple_Redis_Cache_Plugin::apply_saved_config( $revalidated, $during_failure ), 'Failing invalidation unexpectedly applied settings.' );
	unset( $GLOBALS['src_test_option_reader'] );
	$deferred_race = Simple_Redis_Cache_Config::runtime();
	$assert( $option_reads >= 2, 'The authoritative config was not re-read after invalidation failed.' );
	$assert( is_array( $deferred_race ), 'Concurrent failure did not retain a safe runtime config.' );
	$assert( empty( $deferred_race['object']['enabled'] ) && empty( $deferred_race['page']['enabled'] ), 'Both managed layers were not disabled after the concurrent failure.' );
	$assert( 900 === (int) $deferred_race['page']['ttl'], 'The fresh concurrent runtime was replaced by a stale snapshot.' );
	$assert( ! is_file( WP_CONTENT_DIR . '/object-cache.php' ), 'Object drop-in remained installed after the concurrent failure.' );
	$assert( ! is_file( WP_CONTENT_DIR . '/advanced-cache.php' ), 'Concurrent page drop-in remained installed after the failure.' );

	fwrite( STDOUT, "OK: failed settings invalidation recovered safely and handled a concurrent runtime switch.\n" );
} finally {
	unset( $GLOBALS['src_test_option_reader'] );
	unset( $GLOBALS['src_test_saved_config'] );
	$probe->del( $generation_key );
	$probe->close();
	$remove_tree( $test_content );
}
