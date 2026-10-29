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
$method = $reflection->getMethod( 'queried_content_resources' );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$GLOBALS['src_test_queried_object'] = new WP_Post( 17 );
$GLOBALS['src_test_post_type']      = 'page';
$assert( array( array( 'type' => 'post', 'id' => 17 ) ) === $method->invoke( $capture ), 'A frontend WP_Post was not associated with its cached payload.' );

// Author archives expose a WP_User-like object with an ID. It must never be
// mistaken for a post that happens to have the same numeric ID.
$GLOBALS['src_test_queried_object'] = (object) array( 'ID' => 17 );
$assert( array() === $method->invoke( $capture ), 'A non-post queried object was associated with a post content token.' );

$GLOBALS['src_test_queried_object'] = new WP_Post( 17 );
$GLOBALS['src_test_post_type']      = 'private_type';
$assert( array() === $method->invoke( $capture ), 'A non-viewable post type was associated with public page cache.' );

$GLOBALS['src_test_queried_object'] = new WP_Term( 31, 'category' );
$assert( array( array( 'type' => 'term', 'id' => 31 ) ) === $method->invoke( $capture ), 'A public taxonomy archive was not associated with its term-taxonomy token.' );

$GLOBALS['src_test_queried_object'] = new WP_Term( 32, 'private_taxonomy' );
$assert( array() === $method->invoke( $capture ), 'A non-viewable taxonomy was associated with public page cache.' );

$page_config->setValue(
	$capture,
	array(
		'invalidate_on_post_update'               => false,
		'invalidate_term_archives_on_post_update' => true,
	)
);
$GLOBALS['src_test_queried_object'] = new WP_Post( 17 );
$GLOBALS['src_test_post_type']      = 'page';
$assert( array() === $method->invoke( $capture ), 'The independent singular-page invalidation option was ignored.' );

/*
 * Listing pages share one token: the front page and posts page, generic
 * archives, search results and 404s. A taxonomy archive keeps its own term token
 * when that option is on, and falls back to the listing token when it is off.
 */
function is_404(): bool { return '404' === ( $GLOBALS['src_test_page'] ?? '' ); }
function is_search(): bool { return 'search' === ( $GLOBALS['src_test_page'] ?? '' ); }
function is_front_page(): bool { return 'home' === ( $GLOBALS['src_test_page'] ?? '' ); }
function is_home(): bool { return false; }
function is_singular(): bool { return 'singular' === ( $GLOBALS['src_test_page'] ?? '' ); }
function is_archive(): bool { return 'archive' === ( $GLOBALS['src_test_page'] ?? '' ); }

$listing = array( 'type' => 'list', 'id' => Simple_Redis_Cache_Page_Capture::LISTING_RESOURCE_ID );
$page_types = array(
	'cache_home'     => true,
	'cache_singular' => true,
	'cache_archives' => true,
	'cache_search'   => true,
	'cache_404'      => true,
);
$page_config->setValue(
	$capture,
	$page_types + array(
		'invalidate_on_post_update'               => true,
		'invalidate_term_archives_on_post_update' => true,
		'invalidate_listings_on_post_update'      => true,
	)
);

$GLOBALS['src_test_page']           = 'home';
$GLOBALS['src_test_queried_object'] = new WP_Post( 5 );
$GLOBALS['src_test_post_type']      = 'page';
$assert( array( array( 'type' => 'post', 'id' => 5 ), $listing ) === $method->invoke( $capture ), 'A static front page was not bound to both its own post and the listing token.' );

$GLOBALS['src_test_page']           = 'singular';
$GLOBALS['src_test_queried_object'] = new WP_Post( 17 );
$assert( array( array( 'type' => 'post', 'id' => 17 ) ) === $method->invoke( $capture ), 'A singular page was bound to the listing token, so every publish would clear it.' );

$GLOBALS['src_test_page']           = 'archive';
$GLOBALS['src_test_queried_object'] = new WP_Term( 31, 'category' );
$assert( array( array( 'type' => 'term', 'id' => 31 ) ) === $method->invoke( $capture ), 'A taxonomy archive with term invalidation on was also bound to the listing token.' );

$GLOBALS['src_test_queried_object'] = (object) array( 'ID' => 3 );
$assert( array( $listing ) === $method->invoke( $capture ), 'An author archive was not bound to the listing token.' );

foreach ( array( 'search', '404' ) as $listing_page ) {
	$GLOBALS['src_test_page']           = $listing_page;
	$GLOBALS['src_test_queried_object'] = null;
	$assert( array( $listing ) === $method->invoke( $capture ), "A {$listing_page} page was not bound to the listing token." );
}

$page_config->setValue(
	$capture,
	$page_types + array(
		'invalidate_on_post_update'               => false,
		'invalidate_term_archives_on_post_update' => false,
		'invalidate_listings_on_post_update'      => true,
	)
);
$GLOBALS['src_test_page']           = 'archive';
$GLOBALS['src_test_queried_object'] = new WP_Term( 31, 'category' );
$assert( array( $listing ) === $method->invoke( $capture ), 'A taxonomy archive without term invalidation did not fall back to the listing token.' );

$page_config->setValue( $capture, $page_types + array( 'invalidate_listings_on_post_update' => false ) );
$GLOBALS['src_test_page'] = '404';
$assert( array() === $method->invoke( $capture ), 'The listing token was bound while its option was off.' );

echo "OK: page payload tracking binds enabled posts, taxonomy terms and listing pages only.\n";
