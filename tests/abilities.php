<?php
/**
 * Dependency-light test of the WordPress Abilities this plugin gives AI agents
 * (Simple MCP 2.7+, the WordPress MCP Adapter): registered only when the
 * Abilities API exists, category first, marked for MCP, with object schemas
 * that reject unknown input, native capability checks and agent-readable
 * results.
 *
 * Usage: php tests/abilities.php
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/src-abilities-' . bin2hex( random_bytes( 6 ) ) );
define( 'SIMPLE_REDIS_CACHE_VERSION', '9.9.9-test' );

$GLOBALS['src_test_actions'] = array();
$GLOBALS['src_test_caps']    = array();
$GLOBALS['src_test_purge']   = array();

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['src_test_actions'][ $hook ][] = $callback;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function current_user_can( string $capability, mixed ...$args ): bool {
	$key = $capability . ( isset( $args[0] ) ? ':' . (int) $args[0] : '' );
	return in_array( $key, $GLOBALS['src_test_caps'], true );
}

function wp_next_scheduled( string $hook ): int|false {
	return 'simple_redis_cache_cleanup' === $hook ? 1700000000 : false;
}

final class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	/** @return string[] */
	public function get_error_messages(): array {
		return array( $this->message );
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

final class Simple_Redis_Cache_Config {
	public static bool $foreign = false;

	public static function get(): array {
		return array(
			'redis'      => array( 'database' => 3 ),
			'object'     => array( 'enabled' => true, 'max_ttl' => 86400 ),
			'page'       => array( 'enabled' => true, 'ttl' => 3600, 'invalidate_on_post_update' => true, 'invalidate_listings_on_post_update' => true, 'purge_on_content_change' => 'off' ),
			'cloudflare' => array( 'zone_id' => 'zone', 'api_token' => 'secret-token-value', 'purge_on_clear_page' => true ),
		);
	}

	public static function is_foreign( ?array $config = null ): bool {
		return self::$foreign;
	}
}

final class Simple_Redis_Cache_Early_Config {
	public static function prefix( ?array $config = null ): string {
		return 'test:src:';
	}
}

final class Simple_Redis_Cache_Redis {
	public static bool $up = true;

	public function __construct( ?array $config = null ) {}

	public function ping(): bool {
		return self::$up;
	}

	public function client(): ?Redis {
		return null;
	}

	public function display_error(): ?string {
		return self::$up ? null : 'Unable to connect to Redis.';
	}

	public static function breaker_path(): string {
		return WP_CONTENT_DIR . '/simple-redis-cache-breaker';
	}
}

final class Simple_Redis_Cache_Dropins {
	public static function status( string $type ): string {
		return 'object' === $type ? 'owned' : 'missing';
	}
}

final class Simple_Redis_Cache_Cloudflare {
	public static function is_configured( ?array $config = null ): bool {
		return true;
	}
}

final class Simple_Redis_Cache_Cleanup {
	public const HOOK = 'simple_redis_cache_cleanup';

	public static function state( Redis $client, array $config ): array {
		return array();
	}
}

final class Simple_Redis_Cache_Page_Invalidator {
	/** @var array<int, array{int, bool}> */
	public static array $calls = array();

	public static function content_purge_scope( array $config ): string {
		return 'off';
	}

	public static function purge_post_now( int $post_id, bool $related = false ): array|WP_Error {
		self::$calls[] = array( $post_id, $related );
		return array( 'post_ids' => array( $post_id ), 'page_cache' => 'cleared' );
	}
}

final class Simple_Redis_Cache_Purger {
	public static function purge( string $scope = 'all' ): array|WP_Error {
		$GLOBALS['src_test_purge'][] = $scope;
		return match ( $scope ) {
			'page'   => array( 'page_generation' => 7, 'cloudflare' => true ),
			'object' => array( 'object_generation' => 8, 'db_rows_deleted' => 12 ),
			'all'    => array( 'object_generation' => 8, 'db_rows_deleted' => 3, 'page_generation' => 9, 'cloudflare' => false, 'cloudflare_error' => 'Actor is not authorized.' ),
			default  => new WP_Error( 'src_invalid_purge_scope', 'Invalid cache purge scope.' ),
		};
	}
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-abilities.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

// Before WordPress 6.9 there is no Abilities API: nothing may be hooked.
Simple_Redis_Cache_Abilities::init();
$assert( array() === $GLOBALS['src_test_actions'], 'Abilities were hooked although the Abilities API does not exist.' );

// From here on the Abilities API exists.
$GLOBALS['src_test_registry'] = array( 'categories' => array(), 'abilities' => array() );
if ( ! function_exists( 'wp_register_ability' ) ) {
	// Like core, each registration only works inside its own action.
	function wp_register_ability_category( string $slug, array $args ): ?object {
		if ( 'wp_abilities_api_categories_init' !== ( $GLOBALS['src_test_doing'] ?? '' ) ) {
			return null;
		}
		$GLOBALS['src_test_registry']['categories'][ $slug ] = $args;
		return (object) $args;
	}

	function wp_register_ability( string $name, array $args ): ?object {
		if ( 'wp_abilities_api_init' !== ( $GLOBALS['src_test_doing'] ?? '' ) ) {
			return null;
		}
		// Core silently refuses an ability whose category does not exist yet.
		if ( ! isset( $GLOBALS['src_test_registry']['categories'][ $args['category'] ?? '' ] ) ) {
			return null;
		}
		$GLOBALS['src_test_registry']['abilities'][ $name ] = $args;
		return (object) $args;
	}
}

Simple_Redis_Cache_Abilities::init();
foreach ( array( 'wp_abilities_api_categories_init', 'wp_abilities_api_init' ) as $action ) {
	$GLOBALS['src_test_doing'] = $action;
	foreach ( $GLOBALS['src_test_actions'][ $action ] ?? array() as $callback ) {
		$callback();
	}
}
$GLOBALS['src_test_doing'] = '';

$abilities = $GLOBALS['src_test_registry']['abilities'];
$assert( isset( $GLOBALS['src_test_registry']['categories']['simple-redis-cache'] ), 'The ability category was not registered.' );
$assert( Simple_Redis_Cache_Abilities::NAMES === array_keys( $abilities ), 'The registered abilities are not exactly: ' . implode( ', ', Simple_Redis_Cache_Abilities::NAMES ) . ' (got ' . implode( ', ', array_keys( $abilities ) ) . ').' );

if ( Simple_Redis_Cache_Abilities::NAMES !== array_keys( $abilities ) ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}

foreach ( $abilities as $name => $ability ) {
	$assert( 1 === preg_match( '#^simple-redis-cache/[a-z0-9-]+$#', $name ), "{$name} is not namespaced as simple-redis-cache/…." );
	$assert( 'simple-redis-cache' === ( $ability['category'] ?? '' ), "{$name} is not in the plugin's category." );
	$assert( true === ( $ability['meta']['mcp']['public'] ?? null ), "{$name} is not marked for MCP, so no agent would see it." );
	$assert( '' !== trim( (string) ( $ability['label'] ?? '' ) ), "{$name} has no label." );
	$description = (string) ( $ability['description'] ?? '' );
	$assert( strlen( $description ) >= 80 && mb_strlen( $description ) <= 1900, "{$name} has no description an agent could act on, or one too long for MCP." );
	$assert( is_callable( $ability['execute_callback'] ?? null ) && is_callable( $ability['permission_callback'] ?? null ), "{$name} lacks a callable execute or permission callback." );
	foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $hint ) {
		$assert( is_bool( $ability['meta']['annotations'][ $hint ] ?? null ), "{$name} does not declare the {$hint} hint." );
	}
	if ( isset( $ability['input_schema'] ) ) {
		$schema = $ability['input_schema'];
		$assert( 'object' === ( $schema['type'] ?? '' ) && false === ( $schema['additionalProperties'] ?? null ), "{$name} accepts input other than its declared object properties." );
		foreach ( (array) ( $schema['properties'] ?? array() ) as $property => $definition ) {
			$assert( '' !== (string) ( $definition['description'] ?? '' ), "{$name}.{$property} has no description." );
		}
	}
}

$status     = $abilities['simple-redis-cache/status'];
$flush      = $abilities['simple-redis-cache/flush'];
$flush_post = $abilities['simple-redis-cache/flush-post'];

$assert( true === $status['meta']['annotations']['readonly'] && ! isset( $status['input_schema'] ), 'The status ability is not a read-only call without input.' );
$assert( true === $flush['meta']['annotations']['destructive'], 'Clearing a whole layer, which deletes every transient, is not flagged destructive.' );
$assert( array( 'page', 'object', 'all' ) === $flush['input_schema']['properties']['scope']['enum'] && array( 'scope' ) === $flush['input_schema']['required'], 'The flush scope is not a required page/object/all choice.' );
$assert( array( 'post_id' ) === $flush_post['input_schema']['required'] && 'integer' === $flush_post['input_schema']['properties']['post_id']['type'], 'flush-post does not require an integer post_id.' );

// Native capabilities: manage_options for the site, edit_post for one post.
$GLOBALS['src_test_caps'] = array();
$assert( false === ( $status['permission_callback'] )() && false === ( $flush['permission_callback'] )( array( 'scope' => 'page' ) ), 'A user without manage_options may read the status or clear a layer.' );
$GLOBALS['src_test_caps'] = array( 'manage_options' );
$assert( true === ( $status['permission_callback'] )() && true === ( $flush['permission_callback'] )( array( 'scope' => 'page' ) ), 'An administrator may not read the status or clear a layer.' );
$GLOBALS['src_test_caps'] = array( 'edit_post:5' );
$assert( true === ( $flush_post['permission_callback'] )( array( 'post_id' => 5 ) ), 'Someone who can edit the post may not clear its cached page.' );
$assert( false === ( $flush_post['permission_callback'] )( array( 'post_id' => 6 ) ), 'Someone who cannot edit a post may clear its cached page.' );
$assert( false === ( $flush_post['permission_callback'] )( array() ) && false === ( $flush_post['permission_callback'] )( null ), 'flush-post allowed a call without a post.' );

// Status: the state of every layer, and never a credential.
$GLOBALS['src_test_caps'] = array( 'manage_options' );
$state = ( $status['execute_callback'] )();
$assert( '9.9.9-test' === $state['plugin_version'] && true === $state['redis']['connected'] && 3 === $state['redis']['database'], 'The status does not report the version and the Redis connection.' );
$assert( true === $state['object_cache']['enabled'] && 'owned' === $state['object_cache']['drop_in'] && 'missing' === $state['page_cache']['drop_in'], 'The status does not report both layers and their drop-ins.' );
$assert( true === $state['page_cache']['invalidation']['post_on_update'] && 'off' === $state['page_cache']['invalidation']['whole_cache_on_content_change'], 'The status does not report which changes clear cached pages.' );
$assert( true === $state['cloudflare']['configured'] && true === $state['cloudflare']['purge_on_clear_page'], 'The status does not report the Cloudflare purge settings.' );
$assert( ! str_contains( (string) json_encode( $state ), 'secret-token-value' ), 'The status leaks the Cloudflare API token.' );
$assert( isset( $state['cleanup']['next_run'] ) && ! isset( $state['copied_site'] ), 'The status lacks the background cleanup state, or claims a copied site.' );
Simple_Redis_Cache_Redis::$up       = false;
Simple_Redis_Cache_Config::$foreign = true;
$state = ( $status['execute_callback'] )();
$assert( false === $state['redis']['connected'] && 'Unable to connect to Redis.' === $state['redis']['error'] && null === $state['redis']['server'], 'An unreachable Redis is not reported with its error.' );
$assert( isset( $state['copied_site'] ), 'Settings copied from another site are not pointed out.' );
Simple_Redis_Cache_Redis::$up       = true;
Simple_Redis_Cache_Config::$foreign = false;

// Flush: the purger's outcome in words an agent can relay.
$page = ( $flush['execute_callback'] )( array( 'scope' => 'page' ) );
$assert( array( 'page' => true, 'object' => false ) === $page['cleared'] && 'zone purged' === $page['cloudflare'], 'Clearing the page cache was not reported as such.' );
$object = ( $flush['execute_callback'] )( array( 'scope' => 'object' ) );
$assert( 12 === $object['database_transient_rows_deleted'] && str_starts_with( $object['cloudflare'], 'not purged' ), 'Clearing the object cache did not report the deleted transient rows.' );
$all = ( $flush['execute_callback'] )( array( 'scope' => 'all' ) );
$assert( array( 'page' => true, 'object' => true ) === $all['cleared'] && 'purge failed: Actor is not authorized.' === $all['cloudflare'], 'A failed Cloudflare purge was hidden behind a successful local clear.' );
$bad = ( $flush['execute_callback'] )( array( 'scope' => 'everything' ) );
$assert( is_wp_error( $bad ), 'An invalid scope did not come back as an error.' );
$assert( array( 'page', 'object', 'all', 'everything' ) === $GLOBALS['src_test_purge'], 'Flush did not hand the requested scope to the purger.' );

// flush-post goes to the invalidator with the post and the related flag.
( $flush_post['execute_callback'] )( array( 'post_id' => 5 ) );
( $flush_post['execute_callback'] )( array( 'post_id' => 6, 'related' => true ) );
$assert( array( array( 5, false ), array( 6, true ) ) === Simple_Redis_Cache_Page_Invalidator::$calls, 'flush-post did not clear the requested post with the requested related pages.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: the cache abilities are registered for MCP with strict schemas, native capabilities and readable results.\n";
