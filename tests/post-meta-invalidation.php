<?php
/**
 * Dependency-light test: post meta changes invalidate like a post update, but
 * only for published front-end posts, non-internal keys and editing contexts.
 *
 * Request-type constants cannot be undefined, so the contexts that need them
 * (XML-RPC, REST, WP-CLI) run last, in the order the invalidator checks them.
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['src_test_actions'] = array();
$GLOBALS['src_test_config']  = array();
$GLOBALS['src_test_posts']   = array();
$GLOBALS['src_test_env']     = array( 'cron' => false, 'ajax' => false, 'admin' => false, 'can_edit' => array() );
/** @var array<int, array{0:bool,1:int,2:string,3:string}> */
$GLOBALS['src_test_filter_calls'] = array();
/** @var callable|null */
$GLOBALS['src_test_filter'] = null;

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['src_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function wp_doing_cron(): bool {
	return $GLOBALS['src_test_env']['cron'];
}

function wp_doing_ajax(): bool {
	return $GLOBALS['src_test_env']['ajax'];
}

function is_admin(): bool {
	return $GLOBALS['src_test_env']['admin'];
}

function wp_get_current_user(): object {
	return (object) array( 'ID' => 5 );
}

function current_user_can( string $capability, mixed ...$args ): bool {
	return 'edit_post' === $capability && in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['src_test_env']['can_edit'], true );
}

function wp_is_post_revision( int $post_id ): bool {
	return 900 === $post_id;
}

function wp_is_post_autosave( int $post_id ): bool {
	return 901 === $post_id;
}

function get_post_type_object( string $post_type ): object {
	return (object) array( 'name' => $post_type, 'publicly_queryable' => ! in_array( $post_type, array( 'private_type', 'nav_menu_item' ), true ) );
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
	return '' === $status ? false : (object) array( 'name' => $status, 'viewable' => 'publish' === $status );
}

function is_post_status_viewable( object $status ): bool {
	return ! empty( $status->viewable );
}

function get_taxonomy( string $taxonomy ): object|false {
	return '' === $taxonomy ? false : (object) array( 'name' => $taxonomy, 'publicly_queryable' => true, 'object_type' => array( 'post', 'page' ) );
}

function is_taxonomy_viewable( object $taxonomy ): bool {
	return ! empty( $taxonomy->publicly_queryable );
}

function get_object_taxonomies( string $post_type, string $output = 'names' ): array {
	unset( $post_type );

	return 'objects' === $output ? array( 'category' => get_taxonomy( 'category' ) ) : array( 'category' );
}

function wp_get_object_terms( int $post_id, string|array $taxonomy, array $args = array() ): array {
	unset( $taxonomy, $args );
	$map = array(
		1 => array( (object) array( 'term_id' => 11, 'taxonomy' => 'category', 'term_taxonomy_id' => 111 ) ),
		2 => array( (object) array( 'term_id' => 12, 'taxonomy' => 'category', 'term_taxonomy_id' => 121 ) ),
	);

	return $map[ $post_id ] ?? array();
}

function get_term_by( string $field, int|string $value, string $taxonomy = '' ): object|false {
	unset( $field );

	return (object) array( 'term_id' => (int) $value, 'taxonomy' => '' !== $taxonomy ? $taxonomy : 'category', 'term_taxonomy_id' => (int) $value );
}

function get_term( int $term_id, string $taxonomy = '' ): object|false {
	return (object) array( 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'term_taxonomy_id' => $term_id );
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
	return 'post' === $post_type ? false : 'https://example.test/' . $post_type . '/';
}

/** Polylang: posts 1 and 2 are translations of each other. */
function pll_get_post_translations( int $post_id ): array {
	return in_array( $post_id, array( 1, 2 ), true ) ? array( 'uk' => 1, 'en' => 2 ) : array();
}

function has_filter( string $hook ): bool {
	unset( $hook );

	return false;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'simple_redis_cache_post_meta_invalidates' !== $hook ) {
		return $value;
	}

	$GLOBALS['src_test_filter_calls'][] = array( $value, ...$args );

	return null === $GLOBALS['src_test_filter'] ? $value : ( $GLOBALS['src_test_filter'] )( $value, ...$args );
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
	public static int $calls = 0;

	public function __construct( array $config ) {
		unset( $config );
	}

	public function bump_page_content_versions( array $resources ): int|false {
		++self::$calls;
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

	public static function purge( string $scope = 'all', string $cloudflare_trigger = '' ): array|WP_Error {
		self::$calls[] = array( $scope, $cloudflare_trigger );

		return array( 'page_generation' => 2 );
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
	/** @var array<int, string[]> Every exact-URL purge, in order. */
	public static array $url_purges = array();

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
		self::$url_purges[] = $urls;

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

$targeted = array(
	'page'       => array(
		'enabled'                                 => true,
		'invalidate_on_post_update'               => true,
		'invalidate_term_archives_on_post_update' => true,
		'invalidate_listings_on_post_update'      => true,
		'purge_on_site_change'                    => true,
		'purge_on_content_change'                 => 'off',
	),
	'cloudflare' => array(),
);

/** A fresh request: no pending work, nothing scheduled, the given settings and context. */
$request = static function ( array $config, array $env = array() ) use ( $targeted ): void {
	$GLOBALS['src_test_config']       = array() === $config ? $targeted : $config;
	$GLOBALS['src_test_env']          = array_replace( array( 'cron' => false, 'ajax' => false, 'admin' => false, 'can_edit' => array() ), $env );
	$GLOBALS['src_test_actions']      = array();
	$GLOBALS['src_test_foreign']      = false;
	$GLOBALS['src_test_filter']       = null;
	$GLOBALS['src_test_filter_calls'] = array();
	Simple_Redis_Cache_Purger::$calls            = array();
	Simple_Redis_Cache_Cloudflare::$zone_purges  = 0;
	Simple_Redis_Cache_Cloudflare::$url_purges   = array();
	Simple_Redis_Cache_Redis::$bumped            = array();
	Simple_Redis_Cache_Redis::$calls             = 0;
	Simple_Redis_Cache_Admin::$notices           = array();

	$class = new ReflectionClass( Simple_Redis_Cache_Page_Invalidator::class );
	foreach ( array( 'shutdown_scheduled', 'site_purge_scheduled', 'content_purge_scheduled', 'content_purged', 'pending_listing' ) as $flag ) {
		$class->getProperty( $flag )->setValue( null, false );
	}
	foreach ( array( 'pending_post_ids', 'pending_term_taxonomy_ids', 'pending_cloudflare_urls', 'pending_post_types', 'pending_cloudflare_term_urls', 'meta_changed_post_ids' ) as $list ) {
		$class->getProperty( $list )->setValue( null, array() );
	}
};

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

/** Whether the request queued anything at all. */
$queued = static function () use ( $scheduled ): bool {
	return $scheduled( 'flush' ) > 0 || $scheduled( 'flush_content_change' ) > 0;
};

$sorted = static function ( string $type ): array {
	$ids = (array) ( Simple_Redis_Cache_Redis::$bumped[ $type ] ?? array() );
	sort( $ids, SORT_NUMERIC );

	return $ids;
};

$meta = static function ( int $post_id, string $key, string $hook = 'updated_post_meta' ): void {
	$meta_ids = 'deleted_post_meta' === $hook ? array( 77 ) : 77;
	Simple_Redis_Cache_Page_Invalidator::post_meta_changed( $meta_ids, $post_id, $key );
};

$published = $post( 1, 'publish' );
$post( 2, 'publish' );
$post( 3, 'draft' );
$post( 4, 'trash' );
$post( 5, 'publish', 'private_type' );
$post( 6, 'inherit', 'attachment' );
$post( 7, 'publish', 'attachment' );
$post( 8, 'publish', 'nav_menu_item' );
$post( 900, 'publish' );
$post( 901, 'publish' );
$editor = array( 'admin' => true, 'can_edit' => array( 1, 2, 3, 4, 5, 6, 7, 8, 900, 901 ) );

/* ---- Hooks ----------------------------------------------------------------- */

Simple_Redis_Cache_Page_Invalidator::init();
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
	$registered = $GLOBALS['src_test_actions'][ $hook ][0] ?? null;
	$assert( is_array( $registered ) && array( Simple_Redis_Cache_Page_Invalidator::class, 'post_meta_changed' ) === $registered[0] && $registered[2] >= 3, "The {$hook} hook was not registered with the post ID and meta key." );
}

/* ---- What an editing-context meta change invalidates ----------------------- */

foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
	$request( array(), $editor );
	$meta( 1, 'price', $hook );
	$assert( 1 === $scheduled( 'flush' ), "A {$hook} change by an editor in the admin did not schedule exactly one invalidation." );
	$shutdown();
	$assert( array( 1, 2 ) === $sorted( 'post' ), "A {$hook} change did not invalidate the post and its translations." );
	$assert( array( 111, 121 ) === $sorted( 'term' ), "A {$hook} change did not invalidate the term archives of the post and its translations." );
	$assert( array( 1 ) === ( Simple_Redis_Cache_Redis::$bumped['list'] ?? null ), "A {$hook} change did not invalidate the listing pages, as a post update does." );
}

// The edge copies of the same pages.
$request( array_replace_recursive( $targeted, array( 'cloudflare' => array( 'purge_on_post_update' => true ) ) ), $editor );
$meta( 1, 'price' );
$shutdown();
$assert( 1 === count( Simple_Redis_Cache_Cloudflare::$url_purges ), 'A meta change did not purge Cloudflare exactly once.' );
$urls = Simple_Redis_Cache_Cloudflare::$url_purges[0];
foreach ( array( 'https://example.test/post-1/', 'https://example.test/post-2/', 'https://example.test/term-111/', 'https://example.test/' ) as $url ) {
	$assert( in_array( $url, $urls, true ), "A meta change did not purge {$url} from Cloudflare." );
}

// The listing token follows its option, exactly like a post update.
$listings_off = $targeted;
$listings_off['page']['invalidate_listings_on_post_update'] = false;
$request( $listings_off, $editor );
$meta( 1, 'price' );
$shutdown();
$assert( array( 1, 2 ) === $sorted( 'post' ) && ! isset( Simple_Redis_Cache_Redis::$bumped['list'] ), 'A meta change ignored the listing option.' );

/* ---- Saving a post writes meta too: one bump, one purge -------------------- */

$request( array_replace_recursive( $targeted, array( 'cloudflare' => array( 'purge_on_post_update' => true ) ) ), $editor );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
$shutdown();
$post_only      = Simple_Redis_Cache_Redis::$bumped;
$post_only_urls = Simple_Redis_Cache_Cloudflare::$url_purges;

$request( array_replace_recursive( $targeted, array( 'cloudflare' => array( 'purge_on_post_update' => true ) ) ), $editor );
$meta( 1, '_edit_last' );
$meta( 1, '_thumbnail_id' );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
$meta( 1, 'price', 'added_post_meta' );
$meta( 2, 'price' );
$meta( 1, 'subtitle', 'deleted_post_meta' );
$meta( 1, '_edit_lock' );
$assert( 1 === $scheduled( 'flush' ), 'Saving a post with its meta scheduled more than one flush.' );
$shutdown();
$assert( 1 === Simple_Redis_Cache_Redis::$calls, 'Saving a post with its meta bumped the versions more than once.' );
foreach ( array( 'post', 'term', 'list' ) as $type ) {
	sort( $post_only[ $type ], SORT_NUMERIC );
	$assert( $post_only[ $type ] === $sorted( $type ), "Saving a post with its meta changed which {$type} resources were invalidated." );
}
$assert( 1 === count( Simple_Redis_Cache_Cloudflare::$url_purges ), 'Saving a post with its meta purged Cloudflare more than once.' );
$with_meta_urls = Simple_Redis_Cache_Cloudflare::$url_purges[0];
$expected_urls  = $post_only_urls[0];
sort( $with_meta_urls );
sort( $expected_urls );
$assert( array_values( array_unique( $expected_urls ) ) === array_values( array_unique( $with_meta_urls ) ), 'Saving a post with its meta purged a different set of Cloudflare URLs.' );

// The same save with the full purge: one purge of everything, nothing targeted.
$request( array_replace_recursive( $targeted, array( 'page' => array( 'purge_on_content_change' => 'all' ) ) ), $editor );
$meta( 1, '_thumbnail_id' );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $published, $published );
$meta( 1, 'price' );
$meta( 2, 'price' );
$assert( 1 === $scheduled( 'flush_content_change' ), 'Saving a post with its meta scheduled more than one whole-cache purge.' );
$shutdown();
$assert( array( array( 'all', 'purge_on_content_change' ) ) === Simple_Redis_Cache_Purger::$calls, 'Saving a post with its meta did not clear everything exactly once.' );
$assert( 0 === Simple_Redis_Cache_Redis::$calls, 'The targeted invalidation ran on top of the whole-cache purge.' );

// A meta-only change in the full-purge mode clears everything too; an edge-only site
// purges just the zone.
$request( array_replace_recursive( $targeted, array( 'page' => array( 'purge_on_content_change' => 'page' ) ) ), $editor );
$meta( 1, 'price' );
$shutdown();
$assert( array( array( 'page', 'purge_on_content_change' ) ) === Simple_Redis_Cache_Purger::$calls, 'A meta change did not clear the page cache in the full-purge mode.' );
$request( array( 'page' => array( 'enabled' => false ), 'cloudflare' => array( 'purge_on_content_change' => true ) ), $editor );
$meta( 1, 'price' );
$shutdown();
$assert( 1 === Simple_Redis_Cache_Cloudflare::$zone_purges && array() === Simple_Redis_Cache_Purger::$calls, 'A meta change on an edge-only site did not purge just the zone.' );

/* ---- Which posts count ----------------------------------------------------- */

foreach (
	array(
		3   => 'a draft',
		4   => 'a trashed post',
		5   => 'a type visitors cannot see',
		6   => 'an attachment',
		7   => 'an attachment with a public status',
		8   => 'a menu item',
		900 => 'a revision',
		901 => 'an autosave',
		999 => 'a missing post',
		0   => 'delete_post_meta_by_key() (no post ID)',
	) as $post_id => $label
) {
	$request( array_replace_recursive( $targeted, array( 'page' => array( 'purge_on_content_change' => 'all' ) ) ), $editor );
	$GLOBALS['src_test_filter'] = static fn(): bool => true;
	$meta( $post_id, 'price' );
	$meta( $post_id, 'price', 'deleted_post_meta' );
	$assert( ! $queued(), "A meta change of {$label} invalidated something." );
	$assert( array() === $GLOBALS['src_test_filter_calls'], "The filter was asked about {$label}, which can never invalidate." );
}

// Nothing to do: no page cache, no full purge, or settings copied from another site.
$request( array( 'page' => array( 'enabled' => false ) ), $editor );
$meta( 1, 'price' );
$assert( ! $queued() && array() === $GLOBALS['src_test_filter_calls'], 'A meta change did work with every invalidation off.' );
$request( array(), $editor );
$GLOBALS['src_test_foreign'] = true;
$meta( 1, 'price' );
$assert( ! $queued(), 'Settings copied from another site invalidated after a meta change.' );

/* ---- Internal keys ---------------------------------------------------------- */

$internal = array(
	'_edit_lock',
	'_edit_last',
	'_wp_old_slug',
	'_wp_old_date',
	'_encloseme',
	'_pingme',
	'_trackbackme',
	'enclosure',
	'_wp_desired_post_slug',
	'_wp_attached_file',
	'_wp_suggested_privacy_policy_content',
	'_customize_draft_post_name',
	'_wp_trash_meta_status',
	'_wp_trash_meta_time',
	'_wp_trash_meta_comments_status',
	'_wp_attachment_metadata',
	'_wp_attachment_image_alt',
	'_wp_attachment_backup_sizes',
	'_oembed_0123456789abcdef0123456789abcdef',
	'_oembed_time_0123456789abcdef0123456789abcdef',
	'',
);
foreach ( $internal as $key ) {
	foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
		$request( array_replace_recursive( $targeted, array( 'page' => array( 'purge_on_content_change' => 'all' ) ) ), $editor );
		$meta( 1, $key, $hook );
		$assert( ! $queued(), "Core bookkeeping meta \"{$key}\" ({$hook}) invalidated the post." );
	}
}

// Keys that do change the page are not on the list.
foreach ( array( '_thumbnail_id', '_wp_page_template', 'footnotes', '_yoast_wpseo_title', 'price', '_edit_lock_custom', 'enclosures' ) as $key ) {
	$request( array(), $editor );
	$meta( 1, $key );
	$assert( 1 === $scheduled( 'flush' ), "Meta \"{$key}\" did not invalidate the post." );
}

/* ---- Contexts set by functions: front end, admin-ajax, admin, cron ---------- */

$decides = static function ( array $env, int $post_id = 1 ) use ( $request, $meta, $queued, $targeted ): bool {
	$request( $targeted, $env );
	$meta( $post_id, 'price' );

	return $queued();
};
$last_context = static function (): ?string {
	$calls = $GLOBALS['src_test_filter_calls'];
	$call  = end( $calls );

	return is_array( $call ) ? $call[3] : null;
};

// A view counter on the front end, even an editor's own visit, does not invalidate.
$assert( ! $decides( array() ), 'A front-end meta write (a view counter) invalidated the post.' );
$assert( 'frontend' === $last_context(), 'A front-end request was not reported as "frontend".' );
$assert( ! $decides( array( 'can_edit' => array( 1 ) ) ), 'An editor\'s front-end visit invalidated the post through a meta write.' );
// admin-ajax runs per visit too (counters, likes, the heartbeat).
$assert( ! $decides( array( 'ajax' => true, 'admin' => true, 'can_edit' => array( 1 ) ) ), 'An admin-ajax meta write invalidated the post.' );
$assert( 'ajax' === $last_context(), 'An admin-ajax request was not reported as "ajax".' );
// A non-AJAX admin request counts for someone who can edit the post.
$assert( $decides( array( 'admin' => true, 'can_edit' => array( 1 ) ) ), 'An editor\'s admin meta change did not invalidate the post.' );
$assert( 'admin' === $last_context(), 'An admin request was not reported as "admin".' );
$assert( ! $decides( array( 'admin' => true, 'can_edit' => array( 2 ) ) ), 'An admin meta write by someone who cannot edit the post invalidated it.' );
// Scheduled jobs sync statistics or stock: they stay out by default.
$assert( ! $decides( array( 'cron' => true ) ), 'A scheduled meta write invalidated the post by default.' );
$assert( 'cron' === $last_context(), 'A cron request was not reported as "cron".' );
// The classic editor saves the featured image and custom fields over admin-ajax
// before the post is updated; those actions count for someone who can edit it.
foreach ( array( 'set-post-thumbnail', 'add-meta', 'delete-meta' ) as $action ) {
	$_REQUEST['action'] = $action;
	$assert( $decides( array( 'ajax' => true, 'admin' => true, 'can_edit' => array( 1 ) ) ), "The classic editor's {$action} admin-ajax action did not invalidate the post." );
	$assert( ! $decides( array( 'ajax' => true, 'admin' => true, 'can_edit' => array( 2 ) ) ), "The {$action} admin-ajax action invalidated a post the user cannot edit." );
}
$_REQUEST['action'] = 'heartbeat';
$assert( ! $decides( array( 'ajax' => true, 'admin' => true, 'can_edit' => array( 1 ) ) ), 'Another admin-ajax action invalidated the post.' );
$_REQUEST['action'] = array( 'set-post-thumbnail' );
$assert( ! $decides( array( 'ajax' => true, 'admin' => true, 'can_edit' => array( 1 ) ) ), 'An admin-ajax action sent as an array invalidated the post.' );
unset( $_REQUEST['action'] );

// The filter receives the default, the post, the key and the context, and has the
// last word either way.
$request( $targeted, array( 'admin' => true, 'can_edit' => array( 1 ) ) );
$meta( 1, 'price' );
$assert( array( array( true, 1, 'price', 'admin' ) ) === $GLOBALS['src_test_filter_calls'], 'The filter did not receive the default, post ID, meta key and context.' );
$request( $targeted );
$GLOBALS['src_test_filter'] = static fn( bool $default, int $post_id, string $key, string $context ): bool => 'frontend' === $context && 'price' === $key && 1 === $post_id && ! $default;
$meta( 1, 'views' );
$assert( ! $queued(), 'The filter could not keep a front-end key from invalidating.' );
$meta( 1, 'price' );
$assert( 1 === $scheduled( 'flush' ), 'The filter could not make a front-end meta change invalidate.' );
$request( $targeted, $editor );
$GLOBALS['src_test_filter'] = static fn(): bool => false;
$meta( 1, 'post_views_count' );
$assert( ! $queued(), 'The filter could not stop an editor\'s meta change from invalidating.' );

// Once a post is queued, its other keys add nothing and do not ask again; the next
// request starts fresh.
$request( $targeted, $editor );
$meta( 1, 'price' );
$meta( 1, 'stock' );
$meta( 1, 'colour' );
$assert( 1 === count( $GLOBALS['src_test_filter_calls'] ), 'The filter was asked again about a post already queued.' );
$shutdown();
$GLOBALS['src_test_actions']      = array();
$GLOBALS['src_test_filter_calls'] = array();
$meta( 1, 'price' );
$assert( 1 === count( $GLOBALS['src_test_filter_calls'] ) && 1 === $scheduled( 'flush' ), 'A post queued in an earlier flush was never queued again.' );

/* ---- Contexts set by constants, in the order they are checked --------------- */

// Core XML-RPC and REST edits of a post fire post_updated anyway; a meta-only
// write there is a view counter or a synchronisation job, even an editor's.
define( 'XMLRPC_REQUEST', true );
$assert( ! $decides( array( 'can_edit' => array( 1 ) ) ), 'An XML-RPC meta write invalidated the post by default.' );
$assert( 'xmlrpc' === $last_context(), 'An XML-RPC request was not reported as "xmlrpc".' );

define( 'REST_REQUEST', true );
$assert( ! $decides( array( 'can_edit' => array( 1 ) ) ), 'A REST meta write by an editor (a view counter) invalidated the post by default.' );
$assert( 'rest' === $last_context(), 'A REST request was not reported as "rest".' );
$assert( ! $decides( array() ), 'An anonymous REST meta write invalidated the post.' );
// The filter can opt REST in.
$request( $targeted, array( 'can_edit' => array( 1 ) ) );
$GLOBALS['src_test_filter'] = static fn( bool $default, int $post_id, string $key, string $context ): bool => 'rest' === $context;
$meta( 1, 'price' );
$assert( 1 === $scheduled( 'flush' ), 'The filter could not make a REST meta change invalidate.' );

define( 'WP_CLI', true );
$assert( $decides( array() ), 'A WP-CLI meta change did not invalidate the post.' );
$assert( 'cli' === $last_context(), 'A WP-CLI request was not reported as "cli".' );
// `wp cron event run` is WP-CLI and cron at once: scheduled jobs stay out there too.
$assert( ! $decides( array( 'cron' => true ) ), 'A cron job run through WP-CLI invalidated the post by default.' );
$assert( 'cron' === $last_context(), 'A cron job run through WP-CLI was not reported as "cron".' );
$assert( ! $decides( array(), 3 ), 'A WP-CLI meta change of a draft invalidated it.' );

echo "Post meta invalidation test passed.\n";
