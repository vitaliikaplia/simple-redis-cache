<?php
/**
 * Dependency-light regression test for cache-warming URL deduplication.
 *
 * WordPress hands back the same page in different shapes: home_url('/') keeps the
 * trailing slash while get_post_type_archive_link('post') does not. Comparing raw
 * strings therefore warms a posts-on-front home page twice and overstates the
 * discovered count.
 *
 * Run directly with: php tests/warmer-discovery.php
 */

declare(strict_types=1);

// A test script, not part of the plugin: never run it through a web server.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'SIMPLE_REDIS_CACHE_VERSION', '0.0.0-test' );

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function get_option( string $name, mixed $default = false ): mixed {
	unset( $name );
	return $default;
}

function add_query_arg( string $key, mixed $value, string $url ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . rawurlencode( (string) $value );
}

function get_post_stati( array $args, string $output ): array {
	unset( $args, $output );
	return array( 'publish' );
}

function get_author_posts_url( int $author_id ): string {
	++$GLOBALS['src_test_lookups'];
	return 'https://example.test/author/' . $author_id . '/';
}

function get_year_link( int $year ): string {
	++$GLOBALS['src_test_lookups'];
	return 'https://example.test/' . $year . '/';
}

function get_month_link( int $year, int $month ): string {
	++$GLOBALS['src_test_lookups'];
	return 'https://example.test/' . $year . '/' . $month . '/';
}

function get_day_link( int $year, int $month, int $day ): string {
	++$GLOBALS['src_test_lookups'];
	return 'https://example.test/' . $year . '/' . $month . '/' . $day . '/';
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-warmer.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$reflection = new ReflectionClass( Simple_Redis_Cache_Warmer::class );
$key        = $reflection->getMethod( 'deduplication_key' );

$same = static function ( string $left, string $right ) use ( $key ): bool {
	return $key->invoke( null, $left ) === $key->invoke( null, $right );
};

$assert( $same( 'https://example.test/', 'https://example.test' ), 'The home page with and without a trailing slash was treated as two pages.' );
$assert( $same( 'https://example.test/blog/', 'https://example.test/blog' ), 'An inner path with and without a trailing slash was treated as two pages.' );
$assert( $same( 'https://Example.Test/blog/', 'https://example.test/blog' ), 'Host casing produced two different pages.' );
$assert( $same( 'https://example.test:443/news/', 'https://example.test/news' ), 'The default HTTPS port produced a different page than an implicit one.' );

$assert( ! $same( 'https://example.test/a/', 'https://example.test/b/' ), 'Two different paths were collapsed into one.' );
$assert( ! $same( 'https://example.test/a/', 'https://example.test/a/?paged=2' ), 'Pagination was collapsed into the base URL.' );
$assert( ! $same( 'https://example.test/a/?p=1', 'https://example.test/a/?p=2' ), 'Different query strings were collapsed into one page.' );
$assert( ! $same( 'https://example.test/a/', 'http://example.test/a/' ), 'Two schemes were collapsed into one page.' );

// Paths stay case-sensitive: WordPress can serve /A/ and /a/ as different posts.
$assert( ! $same( 'https://example.test/Sale/', 'https://example.test/sale/' ), 'Path casing was collapsed, which can merge two distinct posts.' );

$bound = $reflection->getConstant( 'MAX_DISCOVERED_URLS' );
$assert( is_int( $bound ) && $bound > 0, 'Discovery no longer declares an upper bound on the URL set.' );

/*
 * Once discovery hits its bound every further URL is dropped, so the producers
 * must stop as well instead of spinning through huge archives for nothing.
 */
$added   = 0;
$add_url = static function ( string $url ) use ( &$added ): void {
	unset( $url );
	++$added;
};
$stop_after = static function ( int $limit ) use ( &$added ): callable {
	return static function () use ( &$added, $limit ): bool {
		return $added >= $limit;
	};
};

$paginate = $reflection->getMethod( 'add_archive_with_pagination' );
$added    = 0;
$paginate->invoke( null, 'https://example.test/blog/', 1000000, 1, $add_url, $stop_after( 3 ) );
$assert( 3 === $added, 'Archive pagination kept producing URLs after discovery stopped (' . $added . ' calls).' );

$added = 0;
$paginate->invoke( null, 'https://example.test/blog/', 5, 1, $add_url, null );
$assert( 5 === $added, 'Archive pagination without a stop signal no longer lists every page.' );

$GLOBALS['src_test_lookups'] = 0;
$GLOBALS['wpdb']             = new class() {
	public string $posts = 'wp_posts';

	public function prepare( string $sql, array $args ): string {
		unset( $args );
		return $sql;
	}

	public function get_results( string $sql ): array {
		if ( str_contains( $sql, 'post_author' ) ) {
			return array_map(
				static fn ( int $id ): object => (object) array( 'post_author' => $id, 'total' => 1 ),
				range( 1, 50 )
			);
		}

		return array_map(
			static fn ( int $day ): object => (object) array( 'year_number' => 2020 + intdiv( $day, 28 ), 'month_number' => 1 + intdiv( $day, 28 ) % 12, 'day_number' => 1 + $day % 28, 'total' => 1 ),
			range( 0, 99 )
		);
	}
};

$added = 0;
$reflection->getMethod( 'add_author_urls' )->invoke( null, $add_url, 10, $stop_after( 2 ) );
$assert( 2 === $added && $GLOBALS['src_test_lookups'] <= 2, 'Author discovery kept walking authors after discovery stopped (' . $GLOBALS['src_test_lookups'] . ' lookups).' );

$GLOBALS['src_test_lookups'] = 0;
$added                       = 0;
$reflection->getMethod( 'add_date_urls' )->invoke( null, $add_url, 10, $stop_after( 4 ) );
$assert( 4 === $added && $GLOBALS['src_test_lookups'] <= 4, 'Date discovery kept walking dates after discovery stopped (' . $GLOBALS['src_test_lookups'] . ' lookups).' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo "OK: warming deduplicates equivalent URLs, keeps discovery bounded and stops producing at the bound.\n";
