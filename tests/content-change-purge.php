<?php
/** Dependency-light test of the whole-cache purge after content and site changes. */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['src_test_actions'] = array();
$GLOBALS['src_test_config']  = array();
$GLOBALS['src_test_posts']   = array();

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['src_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function wp_is_post_revision( int $post_id ): bool {
	return 900 === $post_id;
}

function wp_is_post_autosave( int $post_id ): bool {
	return 901 === $post_id;
}

function get_post_type_object( string $post_type ): object {
	return (object) array( 'name' => $post_type, 'publicly_queryable' => 'private_type' !== $post_type );
}

function is_post_type_viewable( object $post_type ): bool {
	return ! empty( $post_type->publicly_queryable );
}

function get_post( mixed $post ): object|false {
	if ( is_object( $post ) ) {
		return $post;
	}

	return $GLOBALS['src_test_posts'][ (int) $post ] ?? false;
}

function get_post_type( int $post_id ): string|false {
	$post = get_post( $post_id );

	return is_object( $post ) ? (string) $post->post_type : false;
}

function get_post_status_object( string $status ): object|false {
	return '' === $status ? false : (object) array( 'name' => $status, 'viewable' => in_array( $status, array( 'publish', 'private' ), true ) );
}

function is_post_status_viewable( object $status ): bool {
	return ! empty( $status->viewable );
}

function get_taxonomy( string $taxonomy ): object|false {
	return '' === $taxonomy ? false : (object) array(
		'name'               => $taxonomy,
		'publicly_queryable' => 'internal' !== $taxonomy,
		'object_type'        => 'profile' === $taxonomy ? array( 'user' ) : array( 'post', 'page' ),
	);
}

function is_taxonomy_viewable( object $taxonomy ): bool {
	return ! empty( $taxonomy->publicly_queryable );
}

function get_object_taxonomies( string $post_type, string $output = 'names' ): array {
	unset( $post_type, $output );

	return array();
}

function get_term_by( string $field, int|string $value, string $taxonomy = '' ): object|false {
	unset( $field );

	return (object) array( 'term_id' => (int) $value, 'taxonomy' => $taxonomy, 'term_taxonomy_id' => (int) $value, 'parent' => 0 );
}

function get_term( int $term_id, string $taxonomy = '' ): object|false {
	return (object) array( 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'term_taxonomy_id' => $term_id, 'parent' => 0 );
}

function get_ancestors( int $object_id, string $object_type = '', string $resource_type = '' ): array {
	unset( $object_id, $object_type, $resource_type );

	return array();
}

function get_permalink( mixed $post ): string {
	$post = get_post( $post );

	return is_object( $post ) ? 'https://example.test/post-' . (int) $post->ID . '/' : '';
}

function get_term_link( object $term ): string {
	return 'https://example.test/term-' . $term->term_taxonomy_id . '/';
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function get_option( string $name, mixed $default = false ): mixed {
	unset( $name );

	return $default;
}

function get_post_type_archive_link( string $post_type ): string|false {
	unset( $post_type );

	return false;
}

function has_filter( string $hook ): bool {
	unset( $hook );

	return false;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	unset( $hook, $args );

	return $value;
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

final class WP_Error {
	/** @var string[] */
	private array $messages;

	public function __construct( string $code = '', string $message = '' ) {
		unset( $code );
		$this->messages = '' === $message ? array() : array( $message );
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

		return ! empty( $GLOBALS['src_test_foreign'] );
	}
}

final class Simple_Redis_Cache_Page_Capture {
	public const LISTING_RESOURCE_ID = 1;
}

final class Simple_Redis_Cache_Redis {
	/** @var array<string, int[]> */
	public static array $bumped = array();

	public function __construct( array $config ) {
		unset( $config );
	}

	public function bump_page_content_versions( array $resources ): int|false {
		self::$bumped = $resources;

		return 1;
	}

	public function display_error(): ?string {
		return null;
	}
}

final class Simple_Redis_Cache_Purger {
	/** @var array<int, array{0:string,1:string}> */
	public static array $calls = array();
	public static bool $fail = false;

	public static function purge( string $scope = 'all', string $cloudflare_trigger = '' ): array|WP_Error {
		self::$calls[] = array( $scope, $cloudflare_trigger );

		return self::$fail ? new WP_Error( 'src_page_purge_failed', 'Redis is unavailable.' ) : array( 'page_generation' => 2 );
	}
}

final class Simple_Redis_Cache_Admin {
	/** @var string[] */
	public static array $notices = array();

	public static function queue_notice( string $message, string $type = 'info' ): void {
		unset( $type );
		self::$notices[] = $message;
	}
}

final class Simple_Redis_Cache_Cloudflare {
	public static int $zone_purges = 0;
	/** @var string[]|null */
	public static ?array $urls = null;

	public static function should_purge_for( string $trigger, ?array $config = null ): bool {
		$config = $config ?? Simple_Redis_Cache_Config::get();

		return ! empty( $config['cloudflare'][ $trigger ] );
	}

	public static function purge_everything( ?array $config = null ): bool|WP_Error {
		unset( $config );
		++self::$zone_purges;

		return true;
	}

	/** @param string[] $urls */
	public static function purge_urls( array $urls, ?array $config = null ): array|WP_Error {
		unset( $config );
		self::$urls = $urls;

		return array( 'purged' => count( $urls ), 'skipped' => 0 );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-page-invalidator.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$post = static function ( int $id, string $status, string $type = 'post' ): object {
	$object = (object) array( 'ID' => $id, 'post_status' => $status, 'post_type' => $type );
	$GLOBALS['src_test_posts'][ $id ] = $object;

	return $object;
};

/** A fresh request: no pending work, no scheduled callbacks, the given settings. */
$request = static function ( array $config ): void {
	$GLOBALS['src_test_config']  = $config;
	$GLOBALS['src_test_actions'] = array();
	$GLOBALS['src_test_foreign'] = false;
	Simple_Redis_Cache_Purger::$calls         = array();
	Simple_Redis_Cache_Purger::$fail          = false;
	Simple_Redis_Cache_Cloudflare::$zone_purges = 0;
	Simple_Redis_Cache_Cloudflare::$urls      = null;
	Simple_Redis_Cache_Redis::$bumped         = array();
	Simple_Redis_Cache_Admin::$notices        = array();

	$class = new ReflectionClass( Simple_Redis_Cache_Page_Invalidator::class );
	foreach ( array( 'shutdown_scheduled', 'site_purge_scheduled', 'content_purge_scheduled', 'content_purged' ) as $flag ) {
		$class->getProperty( $flag )->setValue( null, false );
	}
	foreach ( array( 'pending_post_ids', 'pending_term_taxonomy_ids', 'pending_cloudflare_urls', 'pending_post_types', 'pending_cloudflare_term_urls' ) as $list ) {
		$class->getProperty( $list )->setValue( null, array() );
	}
	$class->getProperty( 'pending_listing' )->setValue( null, false );
};

/** Run what the request scheduled for shutdown, in the order it was scheduled. */
$shutdown = static function (): void {
	foreach ( $GLOBALS['src_test_actions']['shutdown'] ?? array() as $action ) {
		call_user_func( $action[0] );
	}
};

$scheduled = static function ( string $method ): int {
	$count = 0;
	foreach ( $GLOBALS['src_test_actions']['shutdown'] ?? array() as $action ) {
		if ( is_array( $action[0] ) && $method === $action[0][1] ) {
			++$count;
		}
	}

	return $count;
};

$whole = static function ( string $scope, array $extra = array() ): array {
	return array_replace_recursive(
		array(
			'page'       => array(
				'enabled'                            => true,
				'invalidate_on_post_update'          => true,
				'invalidate_listings_on_post_update' => true,
				'purge_on_site_change'               => true,
				'purge_on_content_change'            => $scope,
			),
			'cloudflare' => array(),
		),
		$extra
	);
};

$published = $post( 1, 'publish' );
$draft     = $post( 2, 'draft' );

// Off by default: an update of published content stays a targeted invalidation.
$request( $whole( 'off' ) );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
$assert( 0 === $scheduled( 'flush_content_change' ), 'The whole cache was scheduled with the option off.' );
$shutdown();
$assert( array() === Simple_Redis_Cache_Purger::$calls && array( 1 ) === ( Simple_Redis_Cache_Redis::$bumped['post'] ?? null ), 'The targeted invalidation changed with the option off.' );

// Drafts, revisions and autosaves change nothing visitors see.
$request( $whole( 'all' ) );
Simple_Redis_Cache_Page_Invalidator::post_updated( 2, $draft, $draft );
Simple_Redis_Cache_Page_Invalidator::post_updated( 900, $published, $published );
Simple_Redis_Cache_Page_Invalidator::post_updated( 901, $published, $published );
Simple_Redis_Cache_Page_Invalidator::transition_post_status( 'draft', 'auto-draft', $draft );
Simple_Redis_Cache_Page_Invalidator::before_delete_post( 2, $draft );
$assert( 0 === $scheduled( 'flush_content_change' ), 'A draft, a revision or an autosave cleared the whole cache.' );

// Any number of changes in one request clear everything once, at its end.
$request( $whole( 'all' ) );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
Simple_Redis_Cache_Page_Invalidator::transition_post_status( 'publish', 'publish', $published );
$assert( 1 === $scheduled( 'flush_content_change' ), 'One request scheduled more than one whole-cache purge.' );
$shutdown();
$assert( array( array( 'all', 'purge_on_content_change' ) ) === Simple_Redis_Cache_Purger::$calls, 'The page and object cache were not cleared together, once.' );
$assert( array() === Simple_Redis_Cache_Redis::$bumped, 'The targeted invalidation ran on top of the whole-cache purge.' );

// Whichever shutdown callback runs first, the targeted flush leaves it to the purge.
$request( $whole( 'all' ) );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
Simple_Redis_Cache_Page_Invalidator::flush();
Simple_Redis_Cache_Page_Invalidator::flush_content_change();
$assert( array() === Simple_Redis_Cache_Redis::$bumped && 1 === count( Simple_Redis_Cache_Purger::$calls ), 'The targeted flush ran before the whole-cache purge.' );

// Publishing, unpublishing and deleting count, and so does a draft that goes public.
foreach (
	array(
		'publishing a new post' => static fn() => Simple_Redis_Cache_Page_Invalidator::transition_post_status( 'publish', 'auto-draft', $post( 3, 'publish' ) ),
		'unpublishing'          => static fn() => Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $draft, $published ),
		'deleting'              => static fn() => Simple_Redis_Cache_Page_Invalidator::before_delete_post( 1, $published ),
		'publishing a draft'    => static fn() => Simple_Redis_Cache_Page_Invalidator::post_updated( 2, $published, $draft ),
	) as $label => $change
) {
	$request( $whole( 'page' ) );
	$change();
	$shutdown();
	$assert( array( array( 'page', 'purge_on_content_change' ) ) === Simple_Redis_Cache_Purger::$calls, 'The page cache was not cleared after ' . $label . '.' );
}

// Content of a type visitors cannot see changes nothing.
$request( $whole( 'all' ) );
$hidden = $post( 4, 'publish', 'private_type' );
Simple_Redis_Cache_Page_Invalidator::post_updated( 4, $hidden, $hidden );
Simple_Redis_Cache_Page_Invalidator::transition_post_status( 'publish', 'draft', $hidden );
Simple_Redis_Cache_Page_Invalidator::before_delete_post( 4, $hidden );
$assert( 0 === $scheduled( 'flush_content_change' ), 'A post type visitors cannot see cleared the cache.' );

// Categories and tags of a published post count; of a draft, a hidden taxonomy or
// another object type they do not.
$request( $whole( 'all' ) );
Simple_Redis_Cache_Page_Invalidator::set_object_terms( 2, array(), array( 7 ), 'category', false, array() );
Simple_Redis_Cache_Page_Invalidator::set_object_terms( 1, array(), array( 7 ), 'internal', false, array() );
Simple_Redis_Cache_Page_Invalidator::set_object_terms( 1, array(), array( 7 ), 'profile', false, array() );
$assert( 0 === $scheduled( 'flush_content_change' ), 'Terms of a draft, a hidden taxonomy or another object type cleared the cache.' );
Simple_Redis_Cache_Page_Invalidator::set_object_terms( 1, array(), array( 7 ), 'category', false, array() );
$assert( 1 === $scheduled( 'flush_content_change' ), 'New categories of a published post did not clear the cache.' );

// A renamed or deleted public term counts; an internal one does not.
$request( $whole( 'all' ) );
Simple_Redis_Cache_Page_Invalidator::term_changed( 7, 7, 'internal' );
$assert( 0 === $scheduled( 'flush_content_change' ), 'An internal term cleared the cache.' );
Simple_Redis_Cache_Page_Invalidator::term_changed( 7, 7, 'category' );
Simple_Redis_Cache_Page_Invalidator::term_deleted( 8, 8, 'category', get_term( 8, 'category' ) );
$assert( 1 === $scheduled( 'flush_content_change' ), 'A renamed or deleted category did not clear the cache once.' );

// Site changes (menus, widgets, the theme) go the same way while the option is on.
$request( $whole( 'all' ) );
Simple_Redis_Cache_Page_Invalidator::site_changed();
$assert( 1 === $scheduled( 'flush_content_change' ) && 0 === $scheduled( 'flush_site_change' ), 'A site change did not use the whole-cache purge.' );
$shutdown();
$assert( array( array( 'all', 'purge_on_content_change' ) ) === Simple_Redis_Cache_Purger::$calls, 'A site change did not clear the page and object cache.' );

// With the option off, a site change keeps clearing the page cache, and now also asks
// for the Cloudflare zone when the new Cloudflare option is on.
$request( $whole( 'off' ) );
Simple_Redis_Cache_Page_Invalidator::site_changed();
$shutdown();
$assert( array( array( 'page', 'purge_on_content_change' ) ) === Simple_Redis_Cache_Purger::$calls, 'A site change stopped clearing the page cache.' );

// The Cloudflare option with targeted invalidation: the whole zone once Redis is done,
// instead of the exact URLs.
$request( $whole( 'off', array( 'cloudflare' => array( 'purge_on_content_change' => true, 'purge_on_post_update' => true ) ) ) );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
$assert( 0 === $scheduled( 'flush_content_change' ), 'The Cloudflare option bypassed the targeted Redis invalidation.' );
$shutdown();
$assert( 1 === Simple_Redis_Cache_Cloudflare::$zone_purges && null === Simple_Redis_Cache_Cloudflare::$urls, 'The zone was not purged instead of the exact URLs.' );
$assert( array( 1 ) === ( Simple_Redis_Cache_Redis::$bumped['post'] ?? null ), 'The Redis side was not invalidated before the edge.' );

// Without a page cache the edge is the only HTML cache: it is purged on its own.
$request(
	array(
		'page'       => array( 'enabled' => false ),
		'cloudflare' => array( 'purge_on_content_change' => true ),
	)
);
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
Simple_Redis_Cache_Page_Invalidator::site_changed();
$assert( 1 === $scheduled( 'flush_content_change' ), 'An edge-only site did not schedule one zone purge.' );
$shutdown();
$assert( 1 === Simple_Redis_Cache_Cloudflare::$zone_purges && array() === Simple_Redis_Cache_Purger::$calls, 'An edge-only site did not purge just the zone.' );

// A page cache nothing invalidates would hand the old page straight back to the edge,
// so the zone waits for Redis then.
$request(
	array(
		'page'       => array( 'enabled' => true ),
		'cloudflare' => array( 'purge_on_content_change' => true ),
	)
);
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
$shutdown();
$assert( 0 === Simple_Redis_Cache_Cloudflare::$zone_purges, 'The zone was purged while the page cache kept the old page.' );

// Settings copied from another site never clear its namespace.
$request( $whole( 'all' ) );
$GLOBALS['src_test_foreign'] = true;
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
$assert( 0 === $scheduled( 'flush_content_change' ), 'Settings copied from another site cleared the cache.' );

// A failed purge is reported without breaking the save.
$request( $whole( 'page' ) );
Simple_Redis_Cache_Purger::$fail = true;
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
$shutdown();
$assert( 1 === count( Simple_Redis_Cache_Admin::$notices ) && str_contains( Simple_Redis_Cache_Admin::$notices[0], 'Redis is unavailable.' ), 'A failed purge was not reported.' );

// An unknown value is the safe default.
$assert( 'off' === Simple_Redis_Cache_Page_Invalidator::content_purge_scope( array( 'page' => array( 'purge_on_content_change' => 'everything' ) ) ), 'An unknown scope was not treated as off.' );

echo "Content-change purge test passed.\n";
