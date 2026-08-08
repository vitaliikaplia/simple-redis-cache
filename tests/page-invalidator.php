<?php
/** Dependency-light WordPress/WPML/Polylang invalidator regression test. */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['src_test_actions'] = array();
$GLOBALS['src_test_config']  = array(
	'page' => array(
		'enabled'                                  => true,
		'invalidate_on_post_update'                => true,
		'invalidate_term_archives_on_post_update' => true,
	),
);

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['src_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function wp_is_post_revision( int $post_id ): bool {
	return false;
}

function wp_is_post_autosave( int $post_id ): bool {
	return false;
}

function get_post_type_object( string $post_type ): object {
	return (object) array( 'name' => $post_type, 'publicly_queryable' => 'private_type' !== $post_type );
}

function is_post_type_viewable( object $post_type ): bool {
	return ! empty( $post_type->publicly_queryable );
}

function get_post_type( int $post_id ): string|false {
	return $post_id > 0 ? 'post' : false;
}

function get_taxonomy( string $taxonomy ): object|false {
	if ( '' === $taxonomy ) {
		return false;
	}

	// "profile" stands for a public taxonomy registered against something other than
	// a post type, which set_object_terms also fires for.
	return (object) array(
		'name'               => $taxonomy,
		'publicly_queryable' => 'internal' !== $taxonomy,
		'object_type'        => 'profile' === $taxonomy ? array( 'user' ) : array( 'post', 'page' ),
	);
}

function is_taxonomy_viewable( object $taxonomy ): bool {
	return ! empty( $taxonomy->publicly_queryable );
}

function get_object_taxonomies( string $post_type, string $output = 'names' ): array {
	unset( $post_type );
	$objects = array(
		'category' => get_taxonomy( 'category' ),
		'topic'    => get_taxonomy( 'topic' ),
		'internal' => get_taxonomy( 'internal' ),
	);
	return 'objects' === $output ? $objects : array_keys( $objects );
}

function wp_get_object_terms( int $post_id, string|array $taxonomy, array $args = array() ): array {
	unset( $args );
	$taxonomy = is_array( $taxonomy ) ? (string) reset( $taxonomy ) : $taxonomy;
	$map      = array(
		1 => array(
			'category' => array( (object) array( 'term_id' => 11, 'taxonomy' => 'category', 'term_taxonomy_id' => 111 ) ),
			'topic'    => array( (object) array( 'term_id' => 21, 'taxonomy' => 'topic', 'term_taxonomy_id' => 211 ) ),
			'internal' => array( (object) array( 'term_id' => 91, 'taxonomy' => 'internal', 'term_taxonomy_id' => 911 ) ),
		),
		2 => array(
			'category' => array( (object) array( 'term_id' => 12, 'taxonomy' => 'category', 'term_taxonomy_id' => 121 ) ),
			'topic'    => array(),
			'internal' => array(),
		),
		3 => array(
			'category' => array( (object) array( 'term_id' => 13, 'taxonomy' => 'category', 'term_taxonomy_id' => 131 ) ),
			'topic'    => array(),
			'internal' => array(),
		),
	);
	return $map[ $post_id ][ $taxonomy ] ?? array();
}

function is_wp_error( mixed $value ): bool {
	return false;
}

function get_ancestors( int $object_id, string $object_type = '', string $resource_type = '' ): array {
	unset( $object_type, $resource_type );
	return in_array( $object_id, array( 11, 12, 13, 14 ), true ) ? array( 10 ) : array();
}

function get_term( int $term_id, string $taxonomy = '' ): object|false {
	if ( $term_id < 1 || '' === $taxonomy ) {
		return false;
	}
	return (object) array( 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'term_taxonomy_id' => 100 );
}

function get_term_by( string $field, int|string $value, string $taxonomy = '' ): object|false {
	if ( 'term_taxonomy_id' !== $field || (int) $value < 1 || '' === $taxonomy ) {
		return false;
	}
	$term_ids = array( 111 => 11, 112 => 14 );
	return (object) array(
		'term_id'          => $term_ids[ (int) $value ] ?? (int) $value,
		'taxonomy'         => $taxonomy,
		'term_taxonomy_id' => (int) $value,
	);
}

function pll_get_post_translations( int $post_id ): array {
	return array( 'uk' => 1, 'en' => 2 );
}

function has_filter( string $hook ): bool {
	return in_array( $hook, array( 'wpml_element_trid', 'wpml_get_element_translations' ), true );
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return match ( $hook ) {
		'wpml_element_type' => 'post_post',
		'wpml_element_trid' => 42,
		'wpml_get_element_translations' => array(
			'uk' => (object) array( 'element_id' => 1 ),
			'en' => (object) array( 'element_id' => 3 ),
		),
		default => $value,
	};
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

final class Simple_Redis_Cache_Config {
	public static function get(): array {
		return $GLOBALS['src_test_config'];
	}
}

final class Simple_Redis_Cache_Redis {
	/** @var array{post?:int[],term?:int[]} */
	public static array $invalidated = array();
	public static bool $fail = false;

	public function __construct( array $config ) {
		unset( $config );
	}

	public function bump_page_content_versions( array $resources ): int|false {
		if ( self::$fail ) {
			return false;
		}
		self::$invalidated = $resources;
		return count( (array) ( $resources['post'] ?? array() ) ) + count( (array) ( $resources['term'] ?? array() ) );
	}

	public function display_error(): ?string {
		return self::$fail ? 'connection failed' : null;
	}
}

final class Simple_Redis_Cache_Admin {
	/** @var array<int, array{message:string,type:string}> */
	public static array $notices = array();

	public static function queue_notice( string $message, string $type = 'info' ): void {
		self::$notices[] = array( 'message' => $message, 'type' => $type );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-page-invalidator.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

Simple_Redis_Cache_Page_Invalidator::init();
$assert( isset( $GLOBALS['src_test_actions']['post_updated'] ), 'The post_updated hook was not registered.' );
$assert( isset( $GLOBALS['src_test_actions']['set_object_terms'] ), 'The set_object_terms hook was not registered.' );
$assert( 6 === $GLOBALS['src_test_actions']['set_object_terms'][0][2], 'The complete set_object_terms argument list was not requested.' );

$post = (object) array( 'post_type' => 'post' );
Simple_Redis_Cache_Page_Invalidator::set_object_terms( 1, array(), array( 112 ), 'category', false, array( 111 ) );
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $post, $post );
$assert( isset( $GLOBALS['src_test_actions']['shutdown'] ), 'The deferred invalidation hook was not scheduled.' );

Simple_Redis_Cache_Page_Invalidator::flush();
$posts = Simple_Redis_Cache_Redis::$invalidated['post'] ?? array();
$terms = Simple_Redis_Cache_Redis::$invalidated['term'] ?? array();
sort( $posts, SORT_NUMERIC );
sort( $terms, SORT_NUMERIC );
$assert( array( 1, 2, 3 ) === $posts, 'The updated post and all WPML/Polylang translations were not invalidated together.' );
$assert( array( 100, 111, 112, 121, 131, 211 ) === $terms, 'Old, new, translated, custom-taxonomy, and hierarchical parent archives were not invalidated together.' );
$assert( ! in_array( 911, $terms, true ), 'A non-viewable taxonomy archive was invalidated.' );

// set_object_terms fires for every object type and its IDs are unique only within
// a taxonomy, so a public taxonomy attached to users must never be mistaken for a
// post relationship just because the object ID matches an existing post.
Simple_Redis_Cache_Redis::$invalidated = array();
Simple_Redis_Cache_Page_Invalidator::set_object_terms( 1, array(), array( 501 ), 'profile', false, array( 500 ) );
Simple_Redis_Cache_Page_Invalidator::flush();
$assert(
	empty( Simple_Redis_Cache_Redis::$invalidated['term'] ) && empty( Simple_Redis_Cache_Redis::$invalidated['post'] ),
	'A taxonomy that does not apply to the post type invalidated the post and its terms.'
);

$GLOBALS['src_test_config']['page']['invalidate_on_post_update'] = false;
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $post, $post );
Simple_Redis_Cache_Page_Invalidator::flush();
$assert( ! isset( Simple_Redis_Cache_Redis::$invalidated['post'] ), 'The disabled singular-page invalidation option still invalidated posts.' );
$assert( ! empty( Simple_Redis_Cache_Redis::$invalidated['term'] ), 'The independent taxonomy-archive option stopped working with singular invalidation disabled.' );

$GLOBALS['src_test_config']['page']['invalidate_on_post_update']                = true;
$GLOBALS['src_test_config']['page']['invalidate_term_archives_on_post_update'] = false;
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $post, $post );
Simple_Redis_Cache_Page_Invalidator::flush();
$assert( ! empty( Simple_Redis_Cache_Redis::$invalidated['post'] ), 'The singular-page option stopped working with taxonomy invalidation disabled.' );
$assert( ! isset( Simple_Redis_Cache_Redis::$invalidated['term'] ), 'The disabled taxonomy-archive option still invalidated terms.' );

$GLOBALS['src_test_config']['page']['invalidate_term_archives_on_post_update'] = true;
Simple_Redis_Cache_Redis::$fail = true;
Simple_Redis_Cache_Page_Invalidator::post_updated( 1, $post, $post );
Simple_Redis_Cache_Page_Invalidator::flush();
$assert( 1 === count( Simple_Redis_Cache_Admin::$notices ), 'A Redis invalidation failure did not queue one administrator warning.' );
$assert( 'warning' === Simple_Redis_Cache_Admin::$notices[0]['type'], 'A Redis invalidation failure queued the wrong notice type.' );

echo "OK: post updates invalidate translated singular pages and related public taxonomy archives while failing open.\n";
