<?php
/** Dependency-light queried-content association regression test. */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

final class WP_Post {
	public function __construct( public int $ID ) {}
}

final class WP_Term {
	public function __construct(
		public int $term_taxonomy_id,
		public string $taxonomy
	) {}
}

function get_queried_object(): mixed {
	return $GLOBALS['src_test_queried_object'] ?? null;
}

function get_post_type( int $post_id ): string|false {
	return $post_id > 0 ? (string) ( $GLOBALS['src_test_post_type'] ?? 'page' ) : false;
}

function get_post_type_object( string $post_type ): object {
	return (object) array(
		'name'               => $post_type,
		'publicly_queryable' => 'private_type' !== $post_type,
	);
}

function is_post_type_viewable( object $post_type ): bool {
	return ! empty( $post_type->publicly_queryable );
}

function get_taxonomy( string $taxonomy ): object|false {
	return '' !== $taxonomy
		? (object) array( 'name' => $taxonomy, 'publicly_queryable' => 'private_taxonomy' !== $taxonomy )
		: false;
}

function is_taxonomy_viewable( object $taxonomy ): bool {
	return ! empty( $taxonomy->publicly_queryable );
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-page-capture.php';

$reflection = new ReflectionClass( Simple_Redis_Cache_Page_Capture::class );
$capture    = $reflection->newInstanceWithoutConstructor();
$page_config = $reflection->getProperty( 'page_config' );
$page_config->setValue(
	$capture,
	array(
		'invalidate_on_post_update'               => true,
		'invalidate_term_archives_on_post_update' => true,
	)
);
$method = $reflection->getMethod( 'queried_content_resource' );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$GLOBALS['src_test_queried_object'] = new WP_Post( 17 );
$GLOBALS['src_test_post_type']      = 'page';
$assert( array( 'type' => 'post', 'id' => 17 ) === $method->invoke( $capture ), 'A frontend WP_Post was not associated with its cached payload.' );

// Author archives expose a WP_User-like object with an ID. It must never be
// mistaken for a post that happens to have the same numeric ID.
$GLOBALS['src_test_queried_object'] = (object) array( 'ID' => 17 );
$assert( null === $method->invoke( $capture ), 'A non-post queried object was associated with a post content token.' );

$GLOBALS['src_test_queried_object'] = new WP_Post( 17 );
$GLOBALS['src_test_post_type']      = 'private_type';
$assert( null === $method->invoke( $capture ), 'A non-viewable post type was associated with public page cache.' );

$GLOBALS['src_test_queried_object'] = new WP_Term( 31, 'category' );
$assert( array( 'type' => 'term', 'id' => 31 ) === $method->invoke( $capture ), 'A public taxonomy archive was not associated with its term-taxonomy token.' );

$GLOBALS['src_test_queried_object'] = new WP_Term( 32, 'private_taxonomy' );
$assert( null === $method->invoke( $capture ), 'A non-viewable taxonomy was associated with public page cache.' );

$page_config->setValue(
	$capture,
	array(
		'invalidate_on_post_update'               => false,
		'invalidate_term_archives_on_post_update' => true,
	)
);
$GLOBALS['src_test_queried_object'] = new WP_Post( 17 );
$GLOBALS['src_test_post_type']      = 'page';
$assert( null === $method->invoke( $capture ), 'The independent singular-page invalidation option was ignored.' );

echo "OK: page payload tracking accepts only enabled frontend-viewable posts and taxonomy terms.\n";
