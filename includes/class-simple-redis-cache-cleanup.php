<?php
/**
 * Background removal of keys left behind by superseded cache generations.
 *
 * Clearing a cache layer only bumps its generation counter, so the payloads of
 * the previous generation stay in Redis until their TTL — up to 30 days for
 * pages. On a shared Redis that memory is taken from every other site. This
 * sweeper walks the keyspace with SCAN (never KEYS), in bounded WP-Cron runs,
 * and unlinks only keys of this site's prefix whose page or object generation
 * is no longer current.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Redis_Cache_Cleanup {
	public const HOOK = 'simple_redis_cache_cleanup';

	/** Keys asked for per SCAN call: a hint to Redis, kept small so each call stays short. */
	private const SCAN_COUNT = 1000;

	/** Wall-clock seconds one cron run may spend before it saves its cursor and stops. */
	private const TIME_BUDGET = 5.0;

	/** Seconds the run lock lives; far longer than one budgeted run. */
	private const LOCK_TTL = 60;

	/** Delay of the follow-up run after a purge or an unfinished pass. */
	private const FOLLOW_UP_DELAY = 60;

	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		// Activation does not run on updates, so existing sites get the event here.
		add_action( 'admin_init', array( self::class, 'ensure_scheduled' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'simple-redis-cache cleanup', array( self::class, 'cli_cleanup' ) );
		}
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::FOLLOW_UP_DELAY, 'hourly', self::HOOK );
		}
	}

	/** Ask for a run soon, after a purge left a whole generation behind. */
	public static function schedule_soon(): void {
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			// WordPress refuses a duplicate within ten minutes, which is what we want.
			wp_schedule_single_event( time() + self::FOLLOW_UP_DELAY, self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** WP-Cron entry point: one budgeted step of the current pass. */
	public static function run(): void {
		$result = self::run_once( self::TIME_BUDGET );
		if ( 'partial' === ( $result['status'] ?? '' ) ) {
			self::schedule_soon();
		}
	}

	/**
	 * Remove every key of superseded generations now, however long it takes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp simple-redis-cache cleanup
	 */
	public static function cli_cleanup(): void {
		$deleted = 0;
		do {
			$result = self::run_once( self::TIME_BUDGET );
			$deleted += (int) ( $result['deleted'] ?? 0 );
		} while ( 'partial' === ( $result['status'] ?? '' ) );

		if ( 'error' === ( $result['status'] ?? '' ) || 'locked' === ( $result['status'] ?? '' ) ) {
			WP_CLI::error( (string) ( $result['message'] ?? $result['status'] ) );
		}

		WP_CLI::success(
			sprintf(
				/* translators: %d: number of Redis keys. */
				_n( 'Removed %d key of superseded cache generations.', 'Removed %d keys of superseded cache generations.', $deleted, 'simple-redis-cache' ),
				$deleted
			)
		);
	}

	/**
	 * One budgeted step against the configured Redis.
	 *
	 * @return array{status:string,deleted?:int,message?:string}
	 */
	public static function run_once( float $budget ): array {
		if ( is_multisite() ) {
			return array( 'status' => 'skipped' );
		}

		$config = Simple_Redis_Cache_Config::get();
		// A copied site shares the original's prefix until it is re-keyed: never sweep it.
		if ( Simple_Redis_Cache_Config::is_foreign( $config ) ) {
			return array( 'status' => 'skipped' );
		}
		// With both layers off nothing new is written, and Redis may not even exist.
		if ( empty( $config['object']['enabled'] ) && empty( $config['page']['enabled'] ) ) {
			return array( 'status' => 'skipped' );
		}

		$redis  = new Simple_Redis_Cache_Redis( $config );
		$client = $redis->client();
		if ( null === $client ) {
			return array( 'status' => 'error', 'message' => (string) ( $redis->error() ?? 'Redis is unavailable.' ) );
		}

		return self::sweep( $client, $config, $budget );
	}

	/**
	 * Last recorded sweep state, for the Status tab and the status ability.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, string>
	 */
	public static function state( Redis $client, array $config ): array {
		try {
			$state = $client->hGetAll( Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-state', $config ) );
		} catch ( Throwable ) {
			return array();
		}

		return is_array( $state ) ? array_map( 'strval', $state ) : array();
	}

	/**
	 * Advance the sweep by at most $budget seconds.
	 *
	 * The pass remembers the generations current when it started. Once a pass has
	 * walked the whole keyspace, those generations are recorded as clean, and later
	 * runs do nothing until either generation changes again.
	 *
	 * @param array<string, mixed> $config
	 * @param int                  $scan_count COUNT hint per SCAN call.
	 * @return array{status:string,deleted?:int,message?:string}
	 */
	public static function sweep( Redis $client, array $config, float $budget, int $scan_count = self::SCAN_COUNT ): array {
		$prefix    = Simple_Redis_Cache_Early_Config::prefix( $config );
		$state_key = Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-state', $config );
		$lock_key  = Simple_Redis_Cache_Early_Config::meta_key( 'cleanup-lock', $config );
		$gen_keys  = array(
			'page'   => Simple_Redis_Cache_Early_Config::meta_key( 'page-generation', $config ),
			'object' => Simple_Redis_Cache_Early_Config::meta_key( 'object-generation', $config ),
		);
		$token     = bin2hex( random_bytes( 16 ) );
		$started   = microtime( true );

		try {
			if ( true !== $client->set( $lock_key, $token, array( 'nx', 'ex' => self::LOCK_TTL ) ) ) {
				return array( 'status' => 'locked', 'message' => 'Another cleanup run is in progress.' );
			}
		} catch ( Throwable $throwable ) {
			return array( 'status' => 'error', 'message' => $throwable->getMessage() );
		}

		$deleted = 0;
		try {
			$state       = self::state( $client, $config );
			$generations = self::generations( $client, $gen_keys );
			$cursor      = (string) ( $state['cursor'] ?? '' );
			$in_pass     = '' !== $cursor && '0' !== $cursor;

			if ( ! $in_pass ) {
				if ( null === $generations['page'] && null === $generations['object'] ) {
					return array( 'status' => 'idle', 'deleted' => 0 );
				}
				if ( (string) $generations['page'] === (string) ( $state['clean_page'] ?? '' )
					&& (string) $generations['object'] === (string) ( $state['clean_object'] ?? '' ) ) {
					return array( 'status' => 'idle', 'deleted' => 0 );
				}

				$state = array_merge(
					$state,
					array(
						'pass_page'    => (string) $generations['page'],
						'pass_object'  => (string) $generations['object'],
						'pass_deleted' => '0',
						'pass_started' => (string) time(),
						'error'        => '',
					)
				);
				$iterator = null;
			} else {
				$iterator = $cursor;
			}

			$match = self::glob_escape( $prefix ) . '*';
			$done  = false;
			do {
				$keys = $client->scan( $iterator, $match, max( 1, $scan_count ) );
				// Only a cursor of 0 from Redis ends the pass. PhpRedis also answers false
				// to an error reply (an ACL without SCAN, LOADING, BUSY) and then leaves the
				// cursor as it was; that must not be recorded as a finished, clean pass.
				if ( null !== $iterator && '0' === (string) $iterator ) {
					$done = true;
				} elseif ( false === $keys ) {
					$error = $client->getLastError();
					throw new RuntimeException( is_string( $error ) && '' !== $error ? $error : 'Redis did not complete a SCAN step.' );
				}

				if ( is_array( $keys ) && ! empty( $keys ) ) {
					/*
					 * Read the generations after SCAN returned the batch. A key that SCAN
					 * saw already existed, so its generation existed too: if that is not
					 * the generation current now, it has been superseded. Reading them
					 * before SCAN would let a purge in between make fresh keys look old.
					 */
					$generations = self::generations( $client, $gen_keys );
					$stale       = self::stale_keys( $keys, $prefix, $generations );
					if ( ! empty( $stale ) ) {
						$deleted += self::unlink( $client, $stale );
					}
				}
			} while ( ! $done && ( microtime( true ) - $started ) < $budget );

			$state['pass_deleted'] = (string) ( (int) ( $state['pass_deleted'] ?? 0 ) + $deleted );
			$state['last_run']     = (string) time();
			if ( $done ) {
				$state['cursor']         = '';
				$state['clean_page']     = (string) ( $state['pass_page'] ?? '' );
				$state['clean_object']   = (string) ( $state['pass_object'] ?? '' );
				$state['last_completed'] = (string) time();
				$state['last_deleted']   = $state['pass_deleted'];
			} else {
				$state['cursor'] = (string) $iterator;
			}
			$client->hMSet( $state_key, $state );

			return array( 'status' => $done ? 'complete' : 'partial', 'deleted' => $deleted );
		} catch ( Throwable $throwable ) {
			try {
				$client->hMSet( $state_key, array( 'error' => $throwable->getMessage(), 'last_run' => (string) time() ) );
			} catch ( Throwable ) {
				// Nothing more to record when Redis refuses even this.
			}

			return array( 'status' => 'error', 'deleted' => $deleted, 'message' => $throwable->getMessage() );
		} finally {
			try {
				$client->eval(
					"if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
					array( $lock_key, $token ),
					1
				);
			} catch ( Throwable ) {
				// The lock expires on its own.
			}
		}
	}

	/**
	 * Keys of this prefix whose page or object generation differs from the current
	 * one. A namespace whose current generation is unknown is never touched.
	 *
	 * @param string[]                                $keys
	 * @param array{page:?string,object:?string}      $generations
	 * @return string[]
	 */
	public static function stale_keys( array $keys, string $prefix, array $generations ): array {
		$stale  = array();
		$length = strlen( $prefix );
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( 0 !== strncmp( $key, $prefix, $length ) ) {
				continue;
			}

			/*
			 * The whole key must have this plugin's shape. Matching only the segment
			 * after "page:" would let a salt such as "shop:src:page:1" — another site's
			 * prefix that starts with this one — be read as a page generation here.
			 */
			$rest = substr( $key, $length );
			if ( 1 === preg_match( '/^page:([1-9][0-9]*):(?:variants:[0-9a-f]{64}|[0-9a-f]{64}(?::access:[0-9a-f]{64})?(?::lock)?)$/D', $rest, $match ) ) {
				$namespace = 'page';
			} elseif ( 1 === preg_match( '/^object:([1-9][0-9]*):[1-9][0-9]*:[0-9a-f]{64}$/D', $rest, $match ) ) {
				$namespace = 'object';
			} else {
				continue;
			}

			$current = $generations[ $namespace ];
			if ( null !== $current && $match[1] !== $current ) {
				$stale[] = $key;
			}
		}

		return $stale;
	}

	/** Escape Redis glob metacharacters so a salt cannot widen the MATCH pattern. */
	public static function glob_escape( string $value ): string {
		return (string) preg_replace( '/([\\\\*?\[\]^])/', '\\\\$1', $value );
	}

	/**
	 * Current generations as Redis stores them, without creating missing ones.
	 *
	 * @param array{page:string,object:string} $gen_keys
	 * @return array{page:?string,object:?string}
	 */
	private static function generations( Redis $client, array $gen_keys ): array {
		$values = $client->mGet( array( $gen_keys['page'], $gen_keys['object'] ) );
		$result = array( 'page' => null, 'object' => null );
		if ( ! is_array( $values ) ) {
			return $result;
		}

		foreach ( array( 'page' => 0, 'object' => 1 ) as $namespace => $index ) {
			$value = $values[ $index ] ?? false;
			if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
				$result[ $namespace ] = $value;
			}
		}

		return $result;
	}

	/** @param string[] $keys */
	private static function unlink( Redis $client, array $keys ): int {
		try {
			$removed = $client->unlink( $keys );
		} catch ( Throwable ) {
			$removed = false;
		}

		// UNLINK needs Redis 4; DEL frees the same keys synchronously.
		if ( false === $removed ) {
			$removed = $client->del( $keys );
		}

		return is_int( $removed ) ? $removed : 0;
	}
}
