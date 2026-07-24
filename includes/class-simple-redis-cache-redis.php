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
	private bool $attempted = false;

	/** @param array<string, mixed>|null $config */
	public function __construct( ?array $config = null ) {
		$this->all_config = $config ?? Simple_Redis_Cache_Early_Config::load();
		$this->config     = (array) ( $this->all_config['redis'] ?? array() );
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
			$this->error = 'The PhpRedis extension is not installed.';
			return false;
		}

		try {
			$redis       = new Redis();
			$scheme      = (string) ( $this->config['scheme'] ?? 'tcp' );
			$timeout     = (float) ( $this->config['timeout'] ?? 1.0 );
			$read_timeout = (float) ( $this->config['read_timeout'] ?? 1.0 );
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

			$this->client = $redis;
			return true;
		} catch ( Throwable $throwable ) {
			$this->error  = $throwable->getMessage();
			$this->client = null;
			return false;
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
			default => $this->error,
		};
	}

	public function ping(): bool {
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
		$client = $this->client();
		if ( null === $client ) {
			return false;
		}

		$key = Simple_Redis_Cache_Early_Config::meta_key( $namespace . '-generation', $this->all_config );

		try {
			/*
			 * Initializing and incrementing must be one Redis operation. Otherwise an
			 * eviction between GET/SET and INCR would recreate the key as 1, which can
			 * make an older generation reachable again. Every missing object, page, or
			 * group metadata key instead starts from a fresh random safe integer.
			 */
			$generation = $client->eval(
				"if redis.call('exists', KEYS[1]) == 0 then redis.call('set', KEYS[1], ARGV[1]) end return redis.call('incr', KEYS[1])",
				array( $key, (string) self::new_generation() ),
				1
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
