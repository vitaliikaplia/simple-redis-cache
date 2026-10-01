<?php
/**
 * Minimal Cloudflare cache-purge client.
 *
 * The plugin talks to exactly one Cloudflare endpoint family — zone read and
 * zone cache purge — using a scoped API token. There is no account-wide access,
 * no zone discovery, and no provider abstraction: everything here exists so the
 * edge can be cleared when the origin cache is cleared. The class also builds,
 * locally and without any API call, the Cache Rule expression an administrator
 * pastes into Cloudflare when HTML is cached at the edge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Cloudflare {
	private const API_BASE = 'https://api.cloudflare.com/client/v4';

	/**
	 * Cloudflare accepts at most 30 URLs per purge call on Free, Pro and Business
	 * plans. Enterprise allows more, but assuming the lowest documented limit keeps
	 * one code path for every plan.
	 */
	private const URLS_PER_REQUEST = 30;

	/** Refuse to grow a single invalidation into an unbounded burst of API calls. */
	private const MAX_URLS_PER_FLUSH = 300;

	private const TIMEOUT = 15;

	/** @param array<string, mixed> $config */
	public static function is_configured( ?array $config = null ): bool {
		$cloudflare = self::section( $config );

		return '' !== (string) $cloudflare['zone_id'] && '' !== (string) $cloudflare['api_token'];
	}

	/**
	 * Whether a given automatic trigger should reach Cloudflare.
	 *
	 * @param 'purge_on_clear_all'|'purge_on_clear_page'|'purge_on_post_update' $trigger
	 * @param array<string, mixed>|null                                        $config
	 */
	public static function should_purge_for( string $trigger, ?array $config = null ): bool {
		$cloudflare = self::section( $config );

		return self::is_configured( $config ) && ! empty( $cloudflare[ $trigger ] );
	}

	/**
	 * Purge the entire zone.
	 *
	 * @param array<string, mixed>|null $config
	 */
	public static function purge_everything( ?array $config = null ): true|WP_Error {
		$response = self::request( 'POST', '/purge_cache', array( 'purge_everything' => true ), $config );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Purge specific URLs, in batches Cloudflare will accept.
	 *
	 * @param string[]                  $urls
	 * @param array<string, mixed>|null $config
	 * @return array{purged:int,skipped:int}|WP_Error
	 */
	public static function purge_urls( array $urls, ?array $config = null ): array|WP_Error {
		$urls = self::normalize_urls( $urls );
		if ( empty( $urls ) ) {
			return array( 'purged' => 0, 'skipped' => 0 );
		}

		$skipped = 0;
		if ( count( $urls ) > self::MAX_URLS_PER_FLUSH ) {
			$skipped = count( $urls ) - self::MAX_URLS_PER_FLUSH;
			$urls    = array_slice( $urls, 0, self::MAX_URLS_PER_FLUSH );
		}

		foreach ( array_chunk( $urls, self::URLS_PER_REQUEST ) as $batch ) {
			$response = self::request( 'POST', '/purge_cache', array( 'files' => array_values( $batch ) ), $config );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return array( 'purged' => count( $urls ), 'skipped' => $skipped );
	}

	/**
	 * Confirm the saved credentials can actually purge this zone.
	 *
	 * Reading the zone proves the ID exists and the token may see it, but says
	 * nothing about the Cache Purge permission — a token scoped to read-only
	 * passes that check and then fails the first real purge. So the verification
	 * finishes with a genuine single-URL purge. The only side effect is that the
	 * home page is fetched from the origin once more.
	 *
	 * @param array<string, mixed>|null $config
	 * @return array{zone:string,status:string}|WP_Error
	 */
	public static function verify( ?array $config = null ): array|WP_Error {
		if ( ! self::is_configured( $config ) ) {
			return new WP_Error(
				'src_cloudflare_not_configured',
				__( 'Enter the Cloudflare zone ID and API token before testing the connection.', 'simple-redis-cache' )
			);
		}

		/*
		 * Reading the zone is a nicety, not the test. Zone Details needs its own
		 * Zone Read permission, which a correctly scoped Cache Purge token does not
		 * have — treating that as failure would reject exactly the token the plugin
		 * tells administrators to create. The purge below is the real proof.
		 */
		$zone   = self::request( 'GET', '', null, $config );
		$result = ! is_wp_error( $zone ) && is_array( $zone['result'] ?? null ) ? $zone['result'] : array();

		$purge = self::purge_urls( array( home_url( '/' ) ), $config );
		if ( is_wp_error( $purge ) ) {
			return $purge;
		}

		return array(
			'zone'   => (string) ( $result['name'] ?? '' ),
			'status' => (string) ( $result['status'] ?? '' ),
		);
	}

	/**
	 * Cookies that must always keep a visitor off the edge copy, whatever the
	 * Excluded request cookies list says. The edge cannot vary by session, so even
	 * with logged-in caching enabled in Redis an authenticated visitor has to go
	 * to the origin.
	 */
	private const RULE_REQUIRED_COOKIES = array( 'wordpress_logged_in_', 'wp-postpass_' );

	/** Shorter literals would match nearly any Cookie header and switch the edge off. */
	private const RULE_MIN_COOKIE_LITERAL = 3;

	/**
	 * Build the Cache Rule expression a site needs if Cloudflare caches its HTML.
	 *
	 * Purely local: no API call is made. Cloudflare's cache key is method + host +
	 * URL with no cookies, so a page stored for an anonymous visitor is served to
	 * everyone holding a session or personalization cookie — and that request never
	 * reaches WordPress, so nothing at the origin can correct it. The expression
	 * keeps such visitors out of the rule entirely.
	 *
	 * Cookie patterns come from the plugin's own Excluded request cookies, so the
	 * edge refuses exactly what the origin already refuses. The free plan has no
	 * regex for cookies, so each pattern becomes a `contains` on its literal part
	 * before the first wildcard; a pattern too vague to express that way is
	 * reported back rather than silently dropped.
	 *
	 * @param string   $host            Host the rule applies to.
	 * @param string[] $paths           Raw paths to keep out of the rule (admin, login, REST, cron).
	 * @param string[] $cookie_patterns Excluded request cookies from the HTML Page Cache tab.
	 * @param string   $home_path       Path of the home URL; "" or "/" for a site at the domain root.
	 * @return array{expression:string,skipped:string[]}
	 */
	public static function html_cache_rule( string $host, array $paths, array $cookie_patterns, string $home_path = '' ): array {
		$clauses   = array( 'http.host eq ' . self::rule_string( strtolower( trim( $host ) ) ) );
		$home_root = rtrim( trim( $home_path ), '/' ) . '/';

		$seen_paths = array();
		foreach ( $paths as $path ) {
			$path = rtrim( trim( (string) $path ), '/' );
			/*
			 * A path that covers the home URL itself would exclude every page from
			 * the rule. That is not hypothetical: with plain permalinks REST lives at
			 * home_url('?rest_route=/'), whose path is "/" on a root install and
			 * "/blog/" on a subdirectory one. Those requests carry ?rest_route=,
			 * which the origin already refuses to cache, so nothing is lost.
			 */
			if ( '' === $path || str_starts_with( $home_root, $path . '/' ) || isset( $seen_paths[ $path ] ) ) {
				continue;
			}
			$seen_paths[ $path ] = true;
			/*
			 * Match the path as a whole segment: the path itself or anything below it.
			 * A bare starts_with("/go") would also pull /golf/ and /google-ads/ out of
			 * the edge cache, which matters once a hide-login plugin moves the login
			 * page to a short slug.
			 */
			$quoted    = self::rule_string( $path );
			$clauses[] = 'not (http.request.uri.path eq ' . $quoted . ' or starts_with(http.request.uri.path, ' . self::rule_string( $path . '/' ) . '))';
		}

		$cookies = array();
		$skipped = array();
		foreach ( array_merge( self::RULE_REQUIRED_COOKIES, $cookie_patterns ) as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}

			$literal = trim( (string) preg_split( '/[*?]/', $pattern, 2 )[0] );
			if ( strlen( $literal ) < self::RULE_MIN_COOKIE_LITERAL ) {
				$skipped[] = $pattern;
				continue;
			}

			$cookies[ $literal ] = true;
		}

		foreach ( array_keys( $cookies ) as $literal ) {
			$clauses[] = 'not http.cookie contains ' . self::rule_string( $literal );
		}

		return array(
			'expression' => '(' . implode( ' and ', $clauses ) . ')',
			'skipped'    => array_values( array_unique( $skipped ) ),
		);
	}

	/** Quote a value as a Cloudflare rules-language string literal. */
	private static function rule_string( string $value ): string {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	/**
	 * Reduce candidates to absolute same-site URLs Cloudflare will accept.
	 *
	 * @param string[] $urls
	 * @return string[]
	 */
	private static function normalize_urls( array $urls ): array {
		$home   = wp_parse_url( home_url( '/' ) );
		$host   = strtolower( (string) ( $home['host'] ?? '' ) );
		$unique = array();

		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}

			$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
			if ( '' === $url ) {
				continue;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || strtolower( (string) ( $parts['host'] ?? '' ) ) !== $host ) {
				continue;
			}

			$unique[ $url ] = true;
		}

		return array_keys( $unique );
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @param array<string, mixed>|null $config
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( string $method, string $path, ?array $body, ?array $config ): array|WP_Error {
		$cloudflare = self::section( $config );
		$zone_id    = (string) $cloudflare['zone_id'];
		$token      = (string) $cloudflare['api_token'];

		if ( '' === $zone_id || '' === $token ) {
			return new WP_Error(
				'src_cloudflare_not_configured',
				__( 'Cloudflare is not configured: a zone ID and API token are required.', 'simple-redis-cache' )
			);
		}

		$arguments = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$arguments['headers']['Content-Type'] = 'application/json';
			$arguments['body']                    = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . '/zones/' . rawurlencode( $zone_id ) . $path, $arguments );
		if ( is_wp_error( $response ) ) {
			/*
			 * Transport errors name the URL, never the headers, so the bearer token is
			 * not in this text. Wrap it anyway to say which service failed.
			 */
			return new WP_Error(
				'src_cloudflare_transport_failed',
				sprintf(
					/* translators: %s: HTTP transport error. */
					__( 'Could not reach the Cloudflare API: %s', 'simple-redis-cache' ),
					$response->get_error_message()
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'src_cloudflare_invalid_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The Cloudflare API returned an unreadable response (HTTP %d).', 'simple-redis-cache' ),
					$status
				)
			);
		}

		if ( 200 !== $status || empty( $decoded['success'] ) ) {
			return new WP_Error( 'src_cloudflare_api_error', self::error_message( $status, $decoded ) );
		}

		return $decoded;
	}

	/**
	 * Turn a Cloudflare error envelope into one administrator-readable sentence.
	 *
	 * @param array<string, mixed> $decoded
	 */
	private static function error_message( int $status, array $decoded ): string {
		$messages = array();
		foreach ( (array) ( $decoded['errors'] ?? array() ) as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$code = isset( $error['code'] ) ? (int) $error['code'] : 0;
			$text = trim( (string) ( $error['message'] ?? '' ) );
			if ( '' !== $text ) {
				$messages[] = $code > 0 ? $text . ' (' . $code . ')' : $text;
			}
		}

		if ( 401 === $status || 403 === $status ) {
			$messages[] = __( 'Check that the API token is valid and has the Zone / Cache Purge permission for this zone.', 'simple-redis-cache' );
		} elseif ( 404 === $status ) {
			$messages[] = __( 'Check that the zone ID is correct and that the token may access this zone.', 'simple-redis-cache' );
		} elseif ( 429 === $status ) {
			$messages[] = __( 'Cloudflare is rate limiting purge requests. Try again shortly.', 'simple-redis-cache' );
		}

		if ( empty( $messages ) ) {
			$messages[] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The Cloudflare API rejected the request (HTTP %d).', 'simple-redis-cache' ),
				$status
			);
		}

		return implode( ' ', $messages );
	}

	/**
	 * @param array<string, mixed>|null $config
	 * @return array<string, mixed>
	 */
	private static function section( ?array $config ): array {
		$config     = $config ?? Simple_Redis_Cache_Config::get();
		$cloudflare = (array) ( $config['cloudflare'] ?? array() );
		$defaults   = (array) ( Simple_Redis_Cache_Early_Config::defaults()['cloudflare'] ?? array() );

		return array_merge( $defaults, $cloudflare );
	}
}
