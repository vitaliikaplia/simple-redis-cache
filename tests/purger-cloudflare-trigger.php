<?php
/** Dependency-light test: which purges also clear the Cloudflare zone. */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['src_test_config'] = array();

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

function is_multisite(): bool {
	return false;
}

final class WP_Error {
	/** @var string[] */
	private array $messages = array();

	public function __construct( string $code = '', string $message = '' ) {
		unset( $code );
		if ( '' !== $message ) {
			$this->messages[] = $message;
		}
	}

	public function add( string $code, string $message ): void {
		unset( $code );
		$this->messages[] = $message;
	}

	public function has_errors(): bool {
		return array() !== $this->messages;
	}

	/** @return string[] */
	public function get_error_messages(): array {
		return $this->messages;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

final class Simple_Redis_Cache_Config {
	public static function get(): array {
		return $GLOBALS['src_test_config'];
	}

	public static function is_foreign( ?array $config = null ): bool {
		unset( $config );

		return false;
	}
}

final class Simple_Redis_Cache_Redis {
	public static bool $fail_page = false;

	public function __construct( array $config ) {
		unset( $config );
	}

	public function bump_generation( string $namespace ): int|false {
		return 'page' === $namespace && self::$fail_page ? false : 2;
	}

	public function display_error(): ?string {
		return null;
	}
}

final class Simple_Redis_Cache_Admin {
	public static function queue_notice( string $message, string $type = 'info' ): void {
		unset( $message, $type );
	}
}

final class Simple_Redis_Cache_Cloudflare {
	public static int $zone_purges = 0;

	public static function should_purge_for( string $trigger, ?array $config = null ): bool {
		return ! empty( $config['cloudflare'][ $trigger ] );
	}

	public static function purge_everything( ?array $config = null ): bool|WP_Error {
		unset( $config );
		++self::$zone_purges;

		return true;
	}
}

final class Simple_Redis_Cache_Test_DB {
	public string $options = 'wp_options';

	public function esc_like( string $text ): string {
		return $text;
	}

	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );

		return $query;
	}

	public function query( string $query ): int {
		unset( $query );

		return 0;
	}
}

$GLOBALS['wpdb'] = new Simple_Redis_Cache_Test_DB();

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-purger.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$purges = static function ( array $cloudflare, string $scope, string $trigger = '' ): int {
	$GLOBALS['src_test_config']             = array( 'cloudflare' => $cloudflare );
	Simple_Redis_Cache_Cloudflare::$zone_purges = 0;
	Simple_Redis_Cache_Purger::purge( $scope, $trigger );

	return Simple_Redis_Cache_Cloudflare::$zone_purges;
};

$assert( 0 === $purges( array(), 'page', 'purge_on_content_change' ), 'The zone was purged with every Cloudflare option off.' );
$assert( 1 === $purges( array( 'purge_on_clear_page' => true ), 'page' ), 'Clear page cache stopped purging the zone.' );
$assert( 1 === $purges( array( 'purge_on_clear_all' => true ), 'all' ), 'Clear all cache stopped purging the zone.' );
$assert( 0 === $purges( array( 'purge_on_content_change' => true ), 'page' ), 'A manual purge took the content-change option for its own.' );
$assert( 1 === $purges( array( 'purge_on_content_change' => true ), 'page', 'purge_on_content_change' ), 'A content change did not purge the zone with the page cache.' );
$assert( 1 === $purges( array( 'purge_on_content_change' => true ), 'all', 'purge_on_content_change' ), 'A content change did not purge the zone with the whole cache.' );
$assert( 1 === $purges( array( 'purge_on_content_change' => true, 'purge_on_clear_all' => true ), 'all', 'purge_on_content_change' ), 'Two matching options purged the zone twice.' );
$assert( 0 === $purges( array( 'purge_on_content_change' => true ), 'object', 'purge_on_content_change' ), 'An object-cache purge touched the zone.' );

Simple_Redis_Cache_Redis::$fail_page = true;
$assert( 0 === $purges( array( 'purge_on_content_change' => true ), 'page', 'purge_on_content_change' ), 'The zone was purged while Redis still served the old pages.' );

echo "Purger Cloudflare trigger test passed.\n";
