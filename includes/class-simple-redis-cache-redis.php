<?php
/**
 * Minimal PhpRedis connection shared by the plugin and both drop-ins.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Redis {
	/** @var array<string, mixed> */
	private array $all_config;

	/** @var array<string, mixed> */
	private array $config;
	private ?Redis $client = null;
	private ?string $error = null;
	/** Connection failure, which stays true for every later call on this instance. */
	private ?string $connection_error = null;
	private bool $attempted = false;

	/**
	 * Whether this instance honours and trips the cross-request circuit breaker.
	 * Only the frontend drop-ins opt in; admin and diagnostics always connect.
	 */
	private bool $use_breaker = false;

	/** Set once this instance has tripped the breaker, or found it already open. */
	private bool $breaker_tripped = false;

	/** Modification time this instance gave an expired breaker before probing Redis. */
	private ?int $probe_stamp = null;

	/** Seconds after a failure during which breaker-aware requests skip Redis. */
	private const BREAKER_SECONDS = 15;

	/** @param array<string, mixed>|null $config */
	public function __construct( ?array $config = null, bool $use_breaker = false ) {
		$this->all_config  = $config ?? Simple_Redis_Cache_Early_Config::load();
		$this->config      = (array) ( $this->all_config['redis'] ?? array() );
		// WP-CLI runs one long process an operator is watching: it neither waits
		// out the breaker nor locks the site's requests out of Redis.
		$this->use_breaker = $use_breaker && ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	public function is_available(): bool {
		return class_exists( 'Redis', false ) || extension_loaded( 'redis' );
	}

	public function connect(): bool {
		if ( $this->attempted ) {
			return null !== $this->client;
		}

		$this->attempted = true;

		if ( ! $this->is_available() ) {
			$this->error            = 'The PhpRedis extension is not installed.';
			$this->connection_error = $this->error;
			return false;
		}

		try {
			$timeout      = (float) ( $this->config['timeout'] ?? 1.0 );
			$read_timeout = (float) ( $this->config['read_timeout'] ?? 1.0 );

			/*
			 * A hung or refused Redis costs every request up to the connect timeout.
			 * After a recent failure, breaker-aware callers fail fast instead and run
			 * uncached until the breaker expires; the next attempt then probes Redis.
			 */
			if ( $this->use_breaker ) {
				$breaker_age = self::breaker_age();
				if ( null !== $breaker_age && self::breaker_age_open( $breaker_age ) ) {
					$this->breaker_tripped  = true;
					$this->error            = 'Redis was skipped after a recent connection failure.';
					$this->connection_error = $this->error;
					return false;
				}

				/*
				 * An expired breaker makes this request the probe. Re-arm it for about
				 * as long as the probe may take, so the requests arriving meanwhile
				 * keep skipping instead of all waiting on the same timeout.
				 */
				if ( null !== $breaker_age ) {
					$this->probe_stamp = self::rearm_breaker( (int) ceil( $timeout + $read_timeout ) + 1 );
				}
			}

			$redis       = new Redis();
			$scheme      = (string) ( $this->config['scheme'] ?? 'tcp' );
			$retry       = max( 0, (int) ( $this->config['retry_interval'] ?? 100 ) );
			$persistent  = ! empty( $this->config['persistent'] );
			$host        = (string) ( $this->config['host'] ?? '127.0.0.1' );
			$port        = (int) ( $this->config['port'] ?? 6379 );

			if ( 'unix' === $scheme ) {
				$host = (string) ( $this->config['path'] ?? '' );
				$port = 0;
			} elseif ( 'tls' === $scheme && ! str_starts_with( $host, 'tls://' ) ) {
				$host = 'tls://' . $host;
			}

			if ( '' === $host ) {
				throw new RuntimeException( 'Redis host or socket path is empty.' );
			}

			if ( $persistent ) {
				$persistent_identity = implode(
					'|',
					array(
						$scheme,
						$host,
						(string) $port,
						(string) ( $this->config['database'] ?? 0 ),
						(string) ( $this->config['username'] ?? '' ),
						(string) ( $this->config['password'] ?? '' ),
					)
				);
				$connected = $redis->pconnect(
					$host,
					$port,
					$timeout,
					'simple-redis-cache-' . hash( 'sha256', $persistent_identity ),
					$retry,
					$read_timeout
				);
			} else {
				$connected = $redis->connect( $host, $port, $timeout, null, $retry, $read_timeout );
			}

			if ( ! $connected ) {
				throw new RuntimeException( 'Unable to connect to Redis.' );
			}

			/*
			 * Cache payloads are encoded explicitly and generation keys must remain
			 * plain Redis integers. Neutralize PhpRedis-wide serializer/compression
			 * defaults before any AUTH, SELECT, GET, SET, or INCR operation.
			 */
			if ( defined( 'Redis::OPT_SERIALIZER' ) && defined( 'Redis::SERIALIZER_NONE' ) ) {
				if ( ! $redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE ) ) {
					throw new RuntimeException( 'Unable to disable the PhpRedis serializer.' );
				}
			}
			if ( defined( 'Redis::OPT_COMPRESSION' ) && defined( 'Redis::COMPRESSION_NONE' ) ) {
				if ( ! $redis->setOption( Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE ) ) {
					throw new RuntimeException( 'Unable to disable PhpRedis compression.' );
				}
			}
			if ( defined( 'Redis::OPT_PREFIX' ) ) {
				if ( ! $redis->setOption( Redis::OPT_PREFIX, '' ) ) {
					throw new RuntimeException( 'Unable to clear the PhpRedis key prefix.' );
				}
			}

			$username = (string) ( $this->config['username'] ?? '' );
			$password = (string) ( $this->config['password'] ?? '' );

			if ( '' !== $password || '' !== $username ) {
				$authenticated = '' !== $username
					? $redis->auth( array( $username, $password ) )
					: $redis->auth( $password );

				if ( ! $authenticated ) {
					throw new RuntimeException( 'Redis authentication failed.' );
				}
			}

			$database = max( 0, (int) ( $this->config['database'] ?? 0 ) );
			if ( 0 !== $database && ! $redis->select( $database ) ) {
				throw new RuntimeException( 'Unable to select the Redis database.' );
			}

			/*
			 * A stopped server can still complete the TCP handshake, so a probe
			 * succeeds only after a full round trip. Then the breaker it re-armed
			 * stops blocking the requests that follow.
			 */
			if ( null !== $this->probe_stamp ) {
				$pong = $redis->ping();
				if ( true !== $pong && 'PONG' !== $pong && '+PONG' !== $pong ) {
					throw new RuntimeException( 'Unable to connect to Redis.' );
				}
				self::close_breaker( $this->probe_stamp );
			}

			$this->client = $redis;
			return true;
		} catch ( Throwable $throwable ) {
			$this->error            = $throwable->getMessage();
			$this->connection_error = $this->error;
			$this->client           = null;
			$this->open_breaker();
			return false;
		}
	}

	/**
	 * Report a runtime Redis failure. Only a failure of the connection itself (a
	 * read timeout, a lost connection) lets breaker-aware requests skip Redis
	 * for a short while: an error reply such as OOM or READONLY proves that
	 * Redis answered, so it fails the calling request only. A failed connect()
	 * trips the breaker by itself.
	 *
	 * A no-op without the breaker, and when this instance never reached Redis
	 * because the breaker was already open, so skipped requests can never keep
	 * a recovered server locked out.
	 */
	public function trip_breaker(): void {
		if ( ! $this->use_breaker || $this->breaker_tripped ) {
			return;
		}

		if ( null !== $this->client && $this->answered_with_error() ) {
			return;
		}

		$this->open_breaker();
	}

	/**
	 * PhpRedis keeps the last error reply on the client, while a socket timeout
	 * or a lost connection leaves none. begin_operation() clears it, so an
	 * earlier reply cannot make a later socket failure look answered.
	 */
	private function answered_with_error(): bool {
		try {
			$last_error = $this->client?->getLastError();
			return is_string( $last_error ) && '' !== $last_error;
		} catch ( Throwable ) {
			return false;
		}
	}

	private function open_breaker(): void {
		if ( ! $this->use_breaker || $this->breaker_tripped ) {
			return;
		}

		$this->breaker_tripped = true;
		self::write_marker( self::breaker_path() );
	}

	/**
	 * Marker whose modification time records the last tripped failure. It lives
	 * outside Redis on purpose and is read before WordPress, plugins, or the
	 * database are loaded.
	 */
	public static function breaker_path(): string {
		return WP_CONTENT_DIR . '/simple-redis-cache-breaker';
	}

	/**
	 * Seconds since the breaker was last tripped, from one stat of its marker.
	 * Null when there is no marker, or it cannot be read: the breaker is closed.
	 */
	private static function breaker_age(): ?int {
		try {
			$path = self::breaker_path();
			clearstatcache( true, $path );
			if ( ! is_file( $path ) ) {
				return null;
			}

			$tripped_at = @filemtime( $path );
			return false === $tripped_at ? null : time() - $tripped_at;
		} catch ( Throwable ) {
			return null;
		}
	}

	private static function breaker_age_open( int $age ): bool {
		// A marker from the future (clock change) must not hold Redis off forever.
		return $age < self::BREAKER_SECONDS && $age > -self::BREAKER_SECONDS;
	}

	/**
	 * Replace the breaker marker with one that stays open for the next $seconds
	 * only. Returns the modification time it now has, or null when it could not
	 * be written. Like a trip, this needs a writable directory: a marker that can
	 * be touched but never removed must not keep every expiry re-armed.
	 */
	private static function rearm_breaker( int $seconds ): ?int {
		$stamp = time() - self::BREAKER_SECONDS + max( 1, min( $seconds, self::BREAKER_SECONDS ) );
		return self::write_marker( self::breaker_path(), $stamp ) ? $stamp : null;
	}

	/**
	 * Remove the marker a successful probe re-armed. A marker that changed since
	 * then belongs to a newer failure and stays.
	 */
	private static function close_breaker( int $stamp ): void {
		try {
			$path = self::breaker_path();
			clearstatcache( true, $path );
			if ( @filemtime( $path ) === $stamp ) {
				@unlink( $path );
			}
		} catch ( Throwable ) {
			// A marker left behind simply expires.
		}
	}

	/**
	 * Replace a marker file atomically: write a unique sibling, optionally set
	 * its modification time, then rename it over the target. Failures only make
	 * this return false, and nothing here may throw, even under an error handler
	 * that turns warnings into exceptions.
	 */
	private static function write_marker( string $path, ?int $modified_at = null ): bool {
		try {
			$temporary = $path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
			if (
				false === @file_put_contents( $temporary, (string) time() ) ||
				( null !== $modified_at && ! @touch( $temporary, $modified_at ) ) ||
				! @rename( $temporary, $path )
			) {
				@unlink( $temporary );
				return false;
			}

			clearstatcache( true, $path );
			return true;
		} catch ( Throwable ) {
			// At worst a throwing error handler leaves a temporary sibling behind.
			return false;
		}
	}

	/**
	 * Clear the error left by a previous call so error() answers "did this call
	 * fail?" rather than "has anything ever failed on this instance?". A failed
	 * connection is deliberately preserved: connect() is attempted once, so a later
	 * call must not look successful merely because it stopped re-reporting it.
	 * The client's own last error reply is cleared for the same reason.
	 */
	private function begin_operation(): void {
		$this->error = $this->connection_error;

		if ( null !== $this->client ) {
			try {
				$this->client->clearLastError();
			} catch ( Throwable ) {
				// A broken connection reports itself on the next command.
			}
		}
	}

	public function client(): ?Redis {
		return $this->connect() ? $this->client : null;
	}

	public function error(): ?string {
		return $this->error;
	}

	/**
	 * Localize plugin-defined errors for normal WordPress UI. Raw PhpRedis
	 * exception messages remain unchanged, and early drop-ins continue to use
	 * error() without depending on the translation subsystem.
	 */
	public function display_error(): ?string {
		return match ( $this->error ) {
			'The PhpRedis extension is not installed.' => __( 'The PhpRedis extension is not installed.', 'simple-redis-cache' ),
			'Redis host or socket path is empty.' => __( 'Redis host or socket path is empty.', 'simple-redis-cache' ),
			'Unable to connect to Redis.' => __( 'Unable to connect to Redis.', 'simple-redis-cache' ),
			'Unable to disable the PhpRedis serializer.' => __( 'Unable to disable the PhpRedis serializer.', 'simple-redis-cache' ),
			'Unable to disable PhpRedis compression.' => __( 'Unable to disable PhpRedis compression.', 'simple-redis-cache' ),
			'Unable to clear the PhpRedis key prefix.' => __( 'Unable to clear the PhpRedis key prefix.', 'simple-redis-cache' ),
			'Redis authentication failed.' => __( 'Redis authentication failed.', 'simple-redis-cache' ),
			'Unable to select the Redis database.' => __( 'Unable to select the Redis database.', 'simple-redis-cache' ),
			'Redis returned an invalid cache generation.' => __( 'Redis returned an invalid cache generation.', 'simple-redis-cache' ),
			'Redis could not increment the cache generation.' => __( 'Redis could not increment the cache generation.', 'simple-redis-cache' ),
			'Redis returned an invalid page content version.' => __( 'Redis returned an invalid page content version.', 'simple-redis-cache' ),
			'Redis could not invalidate page content versions.' => __( 'Redis could not invalidate page content versions.', 'simple-redis-cache' ),
			'Redis was skipped after a recent connection failure.' => __( 'Redis was skipped after a recent connection failure.', 'simple-redis-cache' ),
			default => $this->error,
		};
	}

	public function ping(): bool {
		$this->begin_operation();

		try {
			$client = $this->client();
			if ( null === $client ) {
				return false;
			}

			$response = $client->ping();
			return true === $response || 'PONG' === $response || '+PONG' === $response;
		} catch ( Throwable $throwable ) {
			$this->error = $throwable->getMessage();
			return false;
		}
	}

	public function generation( string $namespace ): int {
		$this->begin_operation();
		$client = $this->client();
		if ( null === $client ) {
			return 1;
		}

		$key = Simple_Redis_Cache_Early_Config::meta_key( $namespace . '-generation', $this->all_config );

		try {
			$value = $client->get( $key );
			if ( false === $value ) {
				$client->set( $key, (string) self::new_generation(), array( 'nx' ) );
				$value = $client->get( $key );
			}

			$generation = self::parse_generation( $value );
			if ( null === $generation ) {
				throw new RuntimeException( 'Redis returned an invalid cache generation.' );
			}

			return $generation;
		} catch ( Throwable $throwable ) {
			$this->error = $throwable->getMessage();
			return 1;
		}
	}

	public function bump_generation( string $namespace ): int|false {
		$this->begin_operation();
		$client = $this->client();
		if ( null === $client ) {
			return false;
		}

		$key  = Simple_Redis_Cache_Early_Config::meta_key( $namespace . '-generation', $this->all_config );
		$keys = array( $key );
		$script = "if redis.call('exists', KEYS[1]) == 0 then redis.call('set', KEYS[1], ARGV[1]) end local generation = redis.call('incr', KEYS[1])";
		if ( 'page' === $namespace ) {
			$keys[]  = $this->page_content_versions_key();
			$script .= " redis.call('del', KEYS[2])";
		}
		$script .= ' return generation';

		try {
			/*
			 * Initializing and incrementing must be one Redis operation. Otherwise an
			 * eviction between GET/SET and INCR would recreate the key as 1, which can
			 * make an older generation reachable again. Every missing object, page, or
			 * group metadata key instead starts from a fresh random safe integer.
			 */
			$generation = $client->eval(
				$script,
				array_merge( $keys, array( (string) self::new_generation() ) ),
				count( $keys )
			);
			$generation = self::parse_generation( $generation );
			if ( null === $generation ) {
				throw new RuntimeException( 'Redis could not increment the cache generation.' );
			}

			return $generation;
		} catch ( Throwable $throwable ) {
			$this->error = $throwable->getMessage();
			return false;
		}
	}

	/**
	 * Return the current content version for a cached WordPress post, taxonomy
	 * term archive, or list resource. A missing hash field is initialized
	 * atomically with a random token, so eviction can never make an older payload
	 * current again.
	 */
	public function page_content_version( string $resource_type, int $resource_id ): string|false {
		$this->begin_operation();
		$client = $this->client();
		$field  = self::page_content_field( $resource_type, $resource_id );
		if ( null === $client || null === $field ) {
			return false;
		}

		try {
			$version = $client->eval(
				"local version = redis.call('hget', KEYS[1], ARGV[1]) if not version then version = ARGV[2] redis.call('hset', KEYS[1], ARGV[1], version) end return version",
				array( $this->page_content_versions_key(), $field, self::new_page_content_version() ),
				1
			);

			if ( ! is_string( $version ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $version ) ) {
				throw new RuntimeException( 'Redis returned an invalid page content version.' );
			}

			return $version;
		} catch ( Throwable $throwable ) {
			$this->error = $throwable->getMessage();
			return false;
		}
	}

	/**
	 * Atomically invalidate cached HTML associated with post IDs, term taxonomy
	 * IDs, and/or list IDs. Payloads retain the previous token and become stale
	 * on read.
	 *
	 * @param array{post?:int[],term?:int[],list?:int[]} $resources
	 */
	public function bump_page_content_versions( array $resources ): int|false {
		$this->begin_operation();
		$client = $this->client();
		if ( null === $client ) {
			return false;
		}

		$fields = array();
		foreach ( array( 'post', 'term', 'list' ) as $resource_type ) {
			foreach ( (array) ( $resources[ $resource_type ] ?? array() ) as $resource_id ) {
				$field = self::page_content_field( $resource_type, (int) $resource_id );
				if ( null !== $field ) {
					$fields[ $field ] = true;
				}
			}
		}
		$fields = array_keys( $fields );
		if ( empty( $fields ) ) {
			return 0;
		}

		try {
			$arguments = array( $this->page_content_versions_key() );
			foreach ( $fields as $field ) {
				$arguments[] = $field;
				$arguments[] = self::new_page_content_version();
			}

			$count = $client->eval(
				"for index = 1, #ARGV, 2 do redis.call('hset', KEYS[1], ARGV[index], ARGV[index + 1]) end return #ARGV / 2",
				$arguments,
				1
			);

			if ( ! is_int( $count ) && ! ( is_string( $count ) && preg_match( '/^[0-9]+$/D', $count ) ) ) {
				throw new RuntimeException( 'Redis could not invalidate page content versions.' );
			}

			return (int) $count;
		} catch ( Throwable $throwable ) {
			$this->error = $throwable->getMessage();
			return false;
		}
	}

	private function page_content_versions_key(): string {
		return Simple_Redis_Cache_Early_Config::page_content_versions_key( $this->all_config );
	}

	private static function new_page_content_version(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private static function page_content_field( string $resource_type, int $resource_id ): ?string {
		return $resource_id > 0 && in_array( $resource_type, array( 'post', 'term', 'list' ), true )
			? $resource_type . ':' . $resource_id
			: null;
	}

	/**
	 * Generate a positive Redis integer with ample INCR headroom. On 64-bit PHP,
	 * the 40–52 bit range also stays exactly representable by common tooling. A
	 * 32-bit runtime stays below 2^30, leaving more than a billion safe INCRs.
	 */
	private static function new_generation(): int {
		if ( PHP_INT_SIZE >= 8 ) {
			return random_int( 1099511627776, 4503599627370495 );
		}

		return random_int( 1048576, 1073741823 );
	}

	/** @param mixed $value */
	private static function parse_generation( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}

		$generation = (int) $value;
		if ( $generation < 1 || (string) $generation !== $value ) {
			return null;
		}

		return $generation;
	}
}
