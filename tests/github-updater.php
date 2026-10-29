<?php
/**
 * Dependency-light regression checks for the master-branch GitHub updater,
 * which pins the version it reads and the package it offers to one commit.
 *
 * Run directly with: php tests/github-updater.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SIMPLE_REDIS_CACHE_VERSION', '0.3.0' );
define( 'SIMPLE_REDIS_CACHE_BASENAME', 'custom-installed/simple-redis-cache.php' );

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function trailingslashit( string $value ): string {
	return untrailingslashit( $value ) . '/';
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}

function get_site_transient( string $key ): mixed {
	return $GLOBALS['src_test_update_metadata'] ?? false;
}

function set_site_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['src_test_transient_writes'][] = array(
		'key'        => $key,
		'value'      => $value,
		'expiration' => $expiration,
	);
	return true;
}

/** Records every request and answers from the current scenario's responder. */
function wp_remote_get( string $url, array $args = array() ): mixed {
	$GLOBALS['src_test_http_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	$responder = $GLOBALS['src_test_http_responder'] ?? null;

	return is_callable( $responder )
		? $responder( $url )
		: new WP_Error( 'http_request_failed', 'No HTTP fixture for ' . $url );
}

function wp_remote_retrieve_response_code( mixed $response ): int|string {
	return is_array( $response ) ? ( $response['response']['code'] ?? '' ) : '';
}

function wp_remote_retrieve_body( mixed $response ): string {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}

function is_admin(): bool {
	return false;
}

require_once dirname( __DIR__ ) . '/includes/class-simple-redis-cache-github-updater.php';

$reflection = new ReflectionClass( Simple_Redis_Cache_GitHub_Updater::class );
$updater    = $reflection->newInstanceWithoutConstructor();
$checks     = 0;

$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

/** @return mixed */
$invoke = static function ( object $instance, string $method, mixed ...$arguments ) use ( $reflection ): mixed {
	return $reflection->getMethod( $method )->invoke( $instance, ...$arguments );
};

$assert(
	'2.4.0' === $invoke( $updater, 'parse_plugin_version', "<?php\n/**\n * Version: 2.4.0\n */\n" ),
	'A valid plugin version header was not parsed.'
);
$assert(
	'2.4.0-beta.1+build.7' === $invoke( $updater, 'parse_plugin_version', "/**\n * Version:\t2.4.0-beta.1+build.7\n */" ),
	'A valid prerelease/build version was not parsed.'
);
$assert(
	null === $invoke( $updater, 'parse_plugin_version', "<?php\n/* Plugin Name: Simple Redis Cache */\n" ),
	'Contents without a Version header were accepted.'
);
$assert(
	null === $invoke( $updater, 'parse_plugin_version', "/* Version: v2.4.0 */" ),
	'A version that does not begin with a digit was accepted.'
);
$assert(
	null === $invoke( $updater, 'parse_plugin_version', "/* Version: 2.4.0 <script> */" ),
	'A version containing unsafe characters was accepted.'
);
$assert(
	null === $invoke( $updater, 'parse_plugin_version', '/* Version: 1' . str_repeat( '0', 64 ) . ' */' ),
	'A version longer than the updater limit was accepted.'
);

$cached_sha = '8751a8035d8e9c57a44ba258d30e05ea60384087';
$remote_sha = '0123456789abcdef0123456789abcdef01234567';
$commit_url = 'https://api.github.com/repos/vitaliikaplia/simple-redis-cache/commits/master';
$raw_url    = static fn ( string $ref ): string => 'https://raw.githubusercontent.com/vitaliikaplia/simple-redis-cache/' . $ref . '/simple-redis-cache.php';
$zip_url    = static fn ( string $sha ): string => 'https://github.com/vitaliikaplia/simple-redis-cache/archive/' . $sha . '.zip';

$assert(
	$raw_url( $cached_sha ) === $invoke( $updater, 'get_remote_plugin_file_url', $cached_sha ),
	'The updater does not read the version header from the resolved master commit.'
);
$assert(
	$zip_url( $cached_sha ) === $invoke( $updater, 'get_package_url', $cached_sha ),
	'The updater package URL is not the archive of the resolved master commit.'
);
$assert(
	$commit_url === $invoke( $updater, 'get_branch_commit_url' ),
	'The updater does not resolve the master branch through the GitHub commits API.'
);
$assert(
	'https://github.com/vitaliikaplia/simple-redis-cache' === $invoke( $updater, 'get_repository_url' ),
	'The updater repository URL changed unexpectedly.'
);

$GLOBALS['src_test_update_metadata'] = array(
	'version'      => '0.4.0',
	'sha'          => $cached_sha,
	'package'      => $zip_url( $cached_sha ),
	'url'          => 'https://github.com/vitaliikaplia/simple-redis-cache',
	'branch'       => 'master',
	'last_checked' => time(),
);

/*
 * A missing update transient must stay missing. Returning a fresh stdClass makes
 * core's `false` truthy, and delete_plugins() then takes its "there is update
 * data" branch and writes back a transient that never had a last_checked.
 */
$assert( false === $updater->filter_update_plugins_transient( false ), 'A missing update transient was materialized into an object.' );
$assert( null === $updater->filter_update_plugins_transient( null ), 'A null update transient was materialized into an object.' );
$empty_transient = (object) array();
$assert( $empty_transient === $updater->filter_update_plugins_transient( $empty_transient ), 'An unchecked transient was modified.' );

$newer_transient = (object) array(
	'checked'   => array( SIMPLE_REDIS_CACHE_BASENAME => '0.3.0' ),
	'no_update' => array(
		SIMPLE_REDIS_CACHE_BASENAME => (object) array( 'new_version' => '0.3.0' ),
	),
);
$newer_result    = $updater->filter_update_plugins_transient( $newer_transient );
$assert( isset( $newer_result->response[ SIMPLE_REDIS_CACHE_BASENAME ] ), 'A newer master-branch version was not offered as an update.' );
$assert(
	'0.4.0' === $newer_result->response[ SIMPLE_REDIS_CACHE_BASENAME ]->new_version,
	'The offered update has the wrong remote version.'
);
$assert(
	$GLOBALS['src_test_update_metadata']['package'] === $newer_result->response[ SIMPLE_REDIS_CACHE_BASENAME ]->package,
	'The offered update does not use the cached commit archive.'
);
$assert(
	! isset( $newer_result->no_update[ SIMPLE_REDIS_CACHE_BASENAME ] ),
	'A stale no-update response survived after a newer version was found.'
);

$GLOBALS['src_test_update_metadata']['version'] = '0.3.0';
$current_updater   = $reflection->newInstanceWithoutConstructor();
$current_transient = (object) array(
	'checked'  => array( SIMPLE_REDIS_CACHE_BASENAME => '0.3.0' ),
	'response' => array(
		SIMPLE_REDIS_CACHE_BASENAME => (object) array( 'new_version' => '9.9.9' ),
	),
);
$current_result    = $current_updater->filter_update_plugins_transient( $current_transient );
$assert( isset( $current_result->no_update[ SIMPLE_REDIS_CACHE_BASENAME ] ), 'The current version was not recorded as up to date.' );
$assert(
	'0.3.0' === $current_result->no_update[ SIMPLE_REDIS_CACHE_BASENAME ]->new_version,
	'The no-update response has the wrong remote version.'
);
$assert(
	! isset( $current_result->response[ SIMPLE_REDIS_CACHE_BASENAME ] ),
	'A stale update response survived when the installed version was current.'
);

$http_response = static fn ( int $code, string $body ): array => array(
	'response' => array( 'code' => $code ),
	'body'     => $body,
);

/*
 * The commit lookup answers with the scenario's response. Raw files answer for
 * any commit, so only the updater's own SHA resolution and validation decide
 * whether a version is read; master itself has already moved on to 0.6.0.
 */
$github = static function ( mixed $commit_response, mixed $plugin_file_response = null ) use ( $commit_url, $raw_url, $http_response ): Closure {
	return static function ( string $url ) use ( $commit_response, $plugin_file_response, $commit_url, $raw_url, $http_response ): mixed {
		if ( $commit_url === $url ) {
			return $commit_response;
		}
		if ( $raw_url( 'master' ) === $url ) {
			return $http_response( 200, "<?php\n/**\n * Version: 0.6.0\n */\n" );
		}
		if ( str_starts_with( $url, 'https://raw.githubusercontent.com/vitaliikaplia/simple-redis-cache/' ) ) {
			return $plugin_file_response ?? $http_response( 200, "<?php\n/**\n * Plugin Name: Simple Redis Cache\n * Version: 0.5.0\n */\n" );
		}

		return new WP_Error( 'http_request_failed', 'Unexpected request to ' . $url );
	};
};

/** @return array{0: object, 1: object, 2: array<int, array<string, mixed>>, 3: array<int, array<string, mixed>>} */
$run_check = static function ( Closure $responder, mixed $cached = false ) use ( $reflection ): array {
	$GLOBALS['src_test_update_metadata']  = $cached;
	$GLOBALS['src_test_http_responder']   = $responder;
	$GLOBALS['src_test_http_requests']    = array();
	$GLOBALS['src_test_transient_writes'] = array();

	$instance = $reflection->newInstanceWithoutConstructor();
	$result   = $instance->filter_update_plugins_transient(
		(object) array( 'checked' => array( SIMPLE_REDIS_CACHE_BASENAME => '0.3.0' ) )
	);

	return array( $instance, $result, $GLOBALS['src_test_http_requests'], $GLOBALS['src_test_transient_writes'] );
};

$failure_cached = static function ( array $writes ): bool {
	return 1 === count( $writes )
		&& 'simple_redis_cache_github_update_data' === $writes[0]['key']
		&& HOUR_IN_SECONDS === $writes[0]['expiration']
		&& is_array( $writes[0]['value'] )
		&& '' === ( $writes[0]['value']['version'] ?? null );
};

/*
 * A fresh check resolves master to one commit, then reads the version from that
 * commit and offers that commit's archive, so a later push to master cannot
 * change what gets installed.
 */
[ $pinned_updater, $pinned_result, $requests, $writes ] = $run_check( $github( $http_response( 200, $remote_sha ) ) );
$commit_request = $requests[0] ?? array();
$offered        = $pinned_result->response[ SIMPLE_REDIS_CACHE_BASENAME ] ?? null;
$assert(
	2 === count( $requests ) && $commit_url === ( $commit_request['url'] ?? null ),
	'The update check did not resolve master to a commit before reading the version.'
);
$assert(
	'application/vnd.github.sha' === ( $commit_request['args']['headers']['Accept'] ?? null ),
	'The commit lookup does not ask GitHub for the bare commit SHA.'
);
$assert(
	10 === ( $commit_request['args']['timeout'] ?? null )
		&& 3 === ( $commit_request['args']['redirection'] ?? null )
		&& ( $commit_request['args']['limit_response_size'] ?? 0 ) > 0
		&& ( $commit_request['args']['limit_response_size'] ?? PHP_INT_MAX ) <= 65536
		&& str_starts_with( (string) ( $commit_request['args']['headers']['User-Agent'] ?? '' ), 'Simple-Redis-Cache/' ),
	'The commit lookup does not use the updater HTTP limits.'
);
$assert(
	$raw_url( $remote_sha ) === ( $requests[1]['url'] ?? null ),
	'The version header was not read from the resolved commit.'
);
$assert(
	is_object( $offered ) && '0.5.0' === $offered->new_version,
	'The offered version does not come from the resolved commit.'
);
$assert(
	is_object( $offered ) && $zip_url( $remote_sha ) === $offered->package,
	'The offered package is not the archive of the resolved commit.'
);
preg_match( '~/simple-redis-cache/([0-9a-f]{40})/simple-redis-cache\.php$~D', (string) ( $requests[1]['url'] ?? '' ), $read_ref );
preg_match( '~/archive/([0-9a-f]{40})\.zip$~D', is_object( $offered ) ? (string) $offered->package : '', $package_ref );
$assert(
	$remote_sha === ( $read_ref[1] ?? null ) && $remote_sha === ( $package_ref[1] ?? null ),
	'The version source and the offered package are not pinned to the same commit.'
);
$assert(
	1 === count( $writes )
		&& 'simple_redis_cache_github_update_data' === $writes[0]['key']
		&& 12 * HOUR_IN_SECONDS === $writes[0]['expiration']
		&& $remote_sha === ( $writes[0]['value']['sha'] ?? null )
		&& $zip_url( $remote_sha ) === ( $writes[0]['value']['package'] ?? null )
		&& '0.5.0' === ( $writes[0]['value']['version'] ?? null ),
	'The successful check did not cache the version with its commit and pinned package for 12 hours.'
);
$details = $pinned_updater->filter_plugins_api( false, 'plugin_information', (object) array( 'slug' => 'simple-redis-cache' ) );
$assert(
	is_object( $details ) && $zip_url( $remote_sha ) === $details->download_link && 2 === count( $GLOBALS['src_test_http_requests'] ),
	'The plugin-details modal does not reuse the pinned package of this request.'
);

/*
 * Fail closed: anything but a bare lowercase 40-hex SHA from a 2xx response is a
 * failed check. Nothing else is fetched, nothing is offered, and the failure is
 * cached for an hour like any other failed check.
 */
$invalid_commit_responses = array(
	'an empty body'         => $http_response( 200, '' ),
	'a branch name'         => $http_response( 200, 'master' ),
	'a short SHA'           => $http_response( 200, substr( $remote_sha, 0, 39 ) ),
	'an abbreviated SHA'    => $http_response( 200, substr( $remote_sha, 0, 7 ) ),
	'a long SHA'            => $http_response( 200, $remote_sha . 'a' ),
	'an uppercase SHA'      => $http_response( 200, strtoupper( $remote_sha ) ),
	'a SHA with a newline'  => $http_response( 200, $remote_sha . "\n" ),
	'a non-hex SHA'         => $http_response( 200, str_repeat( 'g', 40 ) ),
	'a JSON commit'         => $http_response( 200, (string) json_encode( array( 'sha' => $remote_sha ) ) ),
	'HTTP 403 (rate limit)' => $http_response( 403, $remote_sha ),
	'HTTP 500'              => $http_response( 500, $remote_sha ),
	'a transport error'     => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
);
foreach ( $invalid_commit_responses as $label => $commit_response ) {
	[ $failed_updater, $failed_result, $requests, $writes ] = $run_check( $github( $commit_response ) );
	$assert(
		! isset( $failed_result->response[ SIMPLE_REDIS_CACHE_BASENAME ] ) && ! isset( $failed_result->no_update[ SIMPLE_REDIS_CACHE_BASENAME ] ),
		sprintf( 'An update result was produced although the commit lookup returned %s.', $label )
	);
	$assert(
		1 === count( $requests ),
		sprintf( 'A version was read although the commit lookup returned %s.', $label )
	);
	$assert(
		$failure_cached( $writes ),
		sprintf( 'The commit lookup that returned %s was not cached as a failed check for one hour.', $label )
	);
}

$details = $failed_updater->filter_plugins_api( false, 'plugin_information', (object) array( 'slug' => 'simple-redis-cache' ) );
$assert(
	is_object( $details ) && '' === $details->download_link && SIMPLE_REDIS_CACHE_VERSION === $details->version,
	'The plugin-details modal fell back to an unpinned package after a failed check.'
);

[ , $failed_result, $requests, $writes ] = $run_check( $github( $http_response( 200, $remote_sha ), $http_response( 404, 'Not Found' ) ) );
$assert(
	! isset( $failed_result->response[ SIMPLE_REDIS_CACHE_BASENAME ] ) && $failure_cached( $writes ) && 2 === count( $requests ),
	'A missing plugin file at the resolved commit was not treated as a failed check.'
);

[ , $failed_result, $requests ] = $run_check(
	$github( $http_response( 200, $remote_sha ) ),
	array(
		'version'      => '',
		'last_checked' => time(),
	)
);
$assert(
	array() === $requests && ! isset( $failed_result->response[ SIMPLE_REDIS_CACHE_BASENAME ] ),
	'A cached failed check did not suppress the remote check for its lifetime.'
);

/*
 * Data cached by 0.7.1 points at the moving branch archive, and damaged data may
 * name a package that is not its commit's archive: neither is offered, a fresh
 * pinned check runs instead.
 */
$unpinned_caches = array(
	'0.7.1 branch-archive cache'                    => array(
		'version'      => '0.4.0',
		'package'      => 'https://github.com/vitaliikaplia/simple-redis-cache/archive/refs/heads/master.zip',
		'url'          => 'https://github.com/vitaliikaplia/simple-redis-cache',
		'branch'       => 'master',
		'last_checked' => time(),
	),
	'cache whose package is not its commit archive' => array(
		'version'      => '0.4.0',
		'sha'          => $cached_sha,
		'package'      => 'https://github.com/vitaliikaplia/simple-redis-cache/archive/refs/heads/master.zip',
		'url'          => 'https://github.com/vitaliikaplia/simple-redis-cache',
		'branch'       => 'master',
		'last_checked' => time(),
	),
);
foreach ( $unpinned_caches as $label => $unpinned_cache ) {
	[ , $refreshed_result, $requests ] = $run_check( $github( $http_response( 200, $remote_sha ) ), $unpinned_cache );
	$assert(
		$commit_url === ( $requests[0]['url'] ?? null )
			&& $zip_url( $remote_sha ) === ( $refreshed_result->response[ SIMPLE_REDIS_CACHE_BASENAME ]->package ?? null ),
		sprintf( 'A %s was offered instead of running a fresh pinned check.', $label )
	);
}
unset( $GLOBALS['src_test_http_responder'], $GLOBALS['src_test_http_requests'], $GLOBALS['src_test_transient_writes'] );

$source_selection_root = sys_get_temp_dir() . '/simple-redis-cache-source-selection-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $source_selection_root, 0700 ) && ! is_dir( $source_selection_root ) ) {
	throw new RuntimeException( 'Could not create the updater source-selection directory.' );
}

$remove_tree = static function ( string $path ) use ( &$remove_tree ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = scandir( $path );
	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$remove_tree( $path . DIRECTORY_SEPARATOR . $item );
			}
		}
	}
	rmdir( $path );
};

try {
	$github_source = $source_selection_root . '/simple-redis-cache-master';
	if ( ! mkdir( $github_source, 0700 ) ) {
		throw new RuntimeException( 'Could not create the GitHub archive directory.' );
	}
	file_put_contents( $github_source . '/simple-redis-cache.php', "<?php\n/* Version: 0.3.0 */\n", LOCK_EX );

	$normalized = $updater->normalize_github_source_directory(
		trailingslashit( $github_source ),
		trailingslashit( $source_selection_root ),
		null,
		array( 'plugin' => SIMPLE_REDIS_CACHE_BASENAME )
	);
	$installed_directory = $source_selection_root . '/custom-installed';
	$assert(
		trailingslashit( $installed_directory ) === $normalized,
		'The simple-redis-cache-master archive was not normalized to the custom installed directory.'
	);
	$assert(
		is_file( $installed_directory . '/simple-redis-cache.php' ),
		'The normalized master archive lost its main plugin file.'
	);

	$already_normalized = $updater->normalize_github_source_directory(
		trailingslashit( $installed_directory ),
		trailingslashit( $source_selection_root ),
		null,
		array( 'plugin' => SIMPLE_REDIS_CACHE_BASENAME )
	);
	$assert(
		trailingslashit( $installed_directory ) === $already_normalized,
		'An already normalized custom installed directory was changed unexpectedly.'
	);

	$unrelated_source = $source_selection_root . '/another-plugin-master';
	if ( ! mkdir( $unrelated_source, 0700 ) ) {
		throw new RuntimeException( 'Could not create the unrelated archive directory.' );
	}
	$unchanged = $updater->normalize_github_source_directory(
		trailingslashit( $unrelated_source ),
		trailingslashit( $source_selection_root ),
		null,
		array( 'plugin' => SIMPLE_REDIS_CACHE_BASENAME )
	);
	$assert( trailingslashit( $unrelated_source ) === $unchanged, 'An unrelated archive directory was renamed.' );

	// A commit archive unpacks as simple-redis-cache-<sha> and replaces the installed copy.
	$commit_source = $source_selection_root . '/simple-redis-cache-' . $remote_sha;
	if ( ! mkdir( $commit_source, 0700 ) ) {
		throw new RuntimeException( 'Could not create the GitHub commit archive directory.' );
	}
	file_put_contents( $commit_source . '/simple-redis-cache.php', "<?php\n/* Version: 0.5.0 */\n", LOCK_EX );

	$normalized_commit = $updater->normalize_github_source_directory(
		trailingslashit( $commit_source ),
		trailingslashit( $source_selection_root ),
		null,
		array( 'plugin' => SIMPLE_REDIS_CACHE_BASENAME )
	);
	$assert(
		trailingslashit( $installed_directory ) === $normalized_commit && ! file_exists( $commit_source ),
		'The simple-redis-cache-<sha> commit archive was not normalized to the custom installed directory.'
	);
	$assert(
		str_contains( (string) file_get_contents( $installed_directory . '/simple-redis-cache.php' ), 'Version: 0.5.0' ),
		'The normalized commit archive did not replace the previously installed copy.'
	);
} finally {
	unset( $GLOBALS['src_test_update_metadata'] );
	$remove_tree( $source_selection_root );
}

fwrite( STDOUT, sprintf( "OK: commit-pinned GitHub updater passed %d regression checks.\n", $checks ) );
