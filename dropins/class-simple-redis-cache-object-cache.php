<?php
/**
 * Redis-backed implementation of the WordPress Object Cache API.
 *
 * This file is loaded by the object-cache drop-in before normal plugins load.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[AllowDynamicProperties]
class WP_Object_Cache {
	/** @var array<string, array<int|string, mixed>> Runtime (L1) cache. */
	public $cache = array();

	/** @var int */
	public $cache_hits = 0;

	/** @var int */
	public $cache_misses = 0;

	/** @var array<string, true> */
	public $global_groups = array();

	/** @var array<string, true> */
	public $non_persistent_groups = array();

	/** Core-compatible single-site properties exposed for cache diagnostics. */
	public $blog_prefix = '';
	public $multisite = false;

	/** @var string[] Runtime Redis errors, kept for diagnostics (distinct, capped). */
	public $errors = array();

	/** Bound on $errors, so a long-running WP-CLI process cannot grow it forever. */
	private const MAX_ERRORS = 20;

	/**
	 * Option holding the Redis password and the Cloudflare API token. Mirrors
	 * Simple_Redis_Cache_Config::OPTION, which is not loaded this early.
	 */
	private const SETTINGS_OPTION = 'simple_redis_cache_settings';

	/** @var array<string, array<int|string, int>> Unix expiry times for L1 entries. */
	private array $expirations = array();

	/** @var array<string, mixed> */
	private array $config;

	/** @var array<string, mixed> */
	private array $object_config;

	private Simple_Redis_Cache_Redis $redis;

	/** @var Redis|null */
	private $redis_client = null;

	private bool $redis_attempted = false;
	private bool $redis_failed = false;
	private bool $object_enabled;
	private bool $persistent_reads_enabled;
	private bool $transient_db_fallback;
	private int $max_ttl;
	private string $prefix;
	private ?int $object_generation = null;

	/** @var array<string, int> */
	private array $group_generations = array();

	/**
	 * Marker file left when persistent data changed while Redis could not take
	 * the change. The next request that reaches Redis invalidates the object
	 * namespace and removes it. An empty path disables the marker.
	 */
	private string $dirty_marker = '';
	private bool $dirty_write_failed = false;

	/**
	 * Seconds after which the claim of a request invalidating the namespace is
	 * considered abandoned: twice the read timeout its single bump may take, plus
	 * a margin for the one-second resolution of file modification times.
	 */
	private int $claim_seconds;

	public function __construct() {
		$this->config                   = Simple_Redis_Cache_Early_Config::load();
		$this->object_config            = (array) ( $this->config['object'] ?? array() );
		$this->object_enabled           = ! empty( $this->object_config['enabled'] );
		$this->max_ttl                  = max( 1, (int) ( $this->object_config['max_ttl'] ?? 86400 ) );
		$this->transient_db_fallback    = ! empty( $this->object_config['transient_db_fallback'] );
		$this->persistent_reads_enabled = $this->object_enabled && (
			! defined( 'WP_ADMIN' ) || ! WP_ADMIN || ! empty( $this->object_config['cache_wp_admin'] )
		);
		$this->prefix                   = Simple_Redis_Cache_Early_Config::prefix( $this->config );
		$this->redis                    = new Simple_Redis_Cache_Redis( $this->config, true );
		$this->dirty_marker             = WP_CONTENT_DIR . '/simple-redis-cache-object-dirty';
		$this->claim_seconds            = (int) ceil( 2 * max( 0.1, (float) ( $this->config['redis']['read_timeout'] ?? 1.0 ) ) ) + 2;

		$this->add_non_persistent_groups(
			(array) ( $this->object_config['non_persistent_groups'] ?? array() )
		);
	}

	/**
	 * Add a value only when it does not already exist.
	 *
	 * @param int|string $key
	 * @param mixed      $data
	 */
	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}

		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$group = $this->normalize_group( $group );
		$key   = (string) $key;
		$ttl   = $this->normalize_ttl( $expire );

		if ( $this->runtime_exists( $key, $group ) ) {
			return false;
		}

		// Redis may have missed while a mirrored transient still exists in the DB.
		if ( $this->uses_db_fallback( $group ) ) {
			$db = $this->read_db_transient( $key, $group );
			if ( $db['found'] ) {
				$value = $db['value'];
				$ttl   = $db['ttl'];
				if ( $this->is_persistent_key( $key, $group ) ) {
					$rewarmed = $this->store_redis( $key, $group, $value, $ttl, true );
					if ( false === $rewarmed ) {
						$winner = $this->read_redis( $key, $group );
						if ( $winner['found'] ) {
							$value = $winner['value'];
							$ttl   = $winner['ttl'];
						}
					}
				}
				$this->put_runtime( $key, $group, $value, $ttl );
				return false;
			}
		}

		// A lost add() needs no dirty marker: it can leave Redis without the value,
		// never with a stale one.
		if ( $this->is_persistent_key( $key, $group ) ) {
			$stored = $this->store_redis( $key, $group, $data, $ttl, true );

			/*
			 * NX refused because Redis already holds the key, so add() fails: callers
			 * that use it as a lock must see the refusal. A request that may not read
			 * persistent groups (wp-admin with cache_wp_admin off) cannot see that
			 * value, so it keeps the caller's copy in L1 all the same; otherwise every
			 * get_option() there would miss and reload alloptions from the database.
			 * Neither the Redis copy nor the database mirror is touched, and an
			 * unserializable value is not kept at all.
			 */
			if ( false === $stored ) {
				if ( ! $this->can_read_persistent_key( $key, $group ) && false !== $this->encode( $data ) ) {
					$this->put_runtime( $key, $group, $data, $ttl );
				}
				return false;
			}
		}

		$this->put_runtime( $key, $group, $data, $ttl );
		if ( $this->uses_db_fallback( $group ) ) {
			$this->write_db_transient( $key, $group, $data, $ttl );
		}

		return true;
	}

	/** @return bool[] */
	public function add_multiple( array $data, $group = '', $expire = 0 ) {
		$results = array();
		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $results;
	}

	/**
	 * Replace an existing value.
	 *
	 * @param int|string $key
	 * @param mixed      $data
	 */
	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$group         = $this->normalize_group( $group );
		$key           = (string) $key;
		$ttl           = $this->normalize_ttl( $expire );
		$runtime_found = $this->runtime_exists( $key, $group );

		if ( ! $this->is_persistent_key( $key, $group ) ) {
			// A mirrored transient group can still prove existence from the database,
			// and must receive the replacement there too. Otherwise replace() would
			// contradict a get() in the same request and leave a stale database row.
			if ( ! $runtime_found && $this->uses_db_fallback( $group ) ) {
				$runtime_found = ! empty( $this->read_db_transient( $key, $group )['found'] );
			}

			if ( ! $runtime_found ) {
				return false;
			}

			$this->put_runtime( $key, $group, $data, $ttl );
			if ( $this->uses_db_fallback( $group ) ) {
				$this->write_db_transient( $key, $group, $data, $ttl );
			}
			return true;
		}

		// Redis XX keeps replace atomic across requests. A GET followed by SET could
		// otherwise recreate an entry deleted by another request between the calls.
		$stored   = $this->store_redis( $key, $group, $data, $ttl, false, true );
		$db_value = null;

		if ( false === $stored && $this->uses_db_fallback( $group ) ) {
			$db_value = $this->read_db_transient( $key, $group );
			if ( $db_value['found'] ) {
				$rewarmed = $this->store_redis( $key, $group, $db_value['value'], $db_value['ttl'], true );
				$stored   = null === $rewarmed
					? null
					: $this->store_redis( $key, $group, $data, $ttl, false, true );
			}
		}

		if ( true === $stored ) {
			$this->put_runtime( $key, $group, $data, $ttl );
			if ( $this->uses_db_fallback( $group ) ) {
				$this->write_db_transient( $key, $group, $data, $ttl );
			}
			return true;
		}

		if ( null === $stored ) {
			// Whatever this call returns, Redis may still hold the value it replaces.
			$this->mark_dirty();

			if ( ! $runtime_found && $this->uses_db_fallback( $group ) ) {
				$db_value = is_array( $db_value ) ? $db_value : $this->read_db_transient( $key, $group );
				$runtime_found = ! empty( $db_value['found'] );
			}

			// Fail open during an outage, but only when one of the request-local or
			// mirrored layers proves that there was an entry to replace.
			if ( $runtime_found ) {
				$this->put_runtime( $key, $group, $data, $ttl );
				if ( $this->uses_db_fallback( $group ) ) {
					$this->write_db_transient( $key, $group, $data, $ttl );
				}
				return true;
			}
		}

		$this->remove_runtime( $key, $group );
		return false;
	}

	/**
	 * Store a value in L1 and, when enabled, Redis.
	 *
	 * Redis failures intentionally do not turn a valid runtime cache write into a
	 * WordPress failure. This keeps requests operational during Redis outages.
	 *
	 * @param int|string $key
	 * @param mixed      $data
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$group = $this->normalize_group( $group );
		$key   = (string) $key;
		$ttl   = $this->normalize_ttl( $expire );

		$this->put_runtime( $key, $group, $data, $ttl );

		if ( $this->is_persistent_key( $key, $group ) ) {
			$stored = $this->store_redis( $key, $group, $data, $ttl );

			/*
			 * A plain SET is refused only for a value that cannot be serialized. It
			 * never reaches Redis, so the previous copy must go, or later requests
			 * would keep reading it; a DEL that cannot be done marks the cache dirty.
			 */
			if ( false === $stored ) {
				$stored = $this->delete_redis( $key, $group );
			}
			if ( null === $stored ) {
				$this->mark_dirty();
			}
		}

		// The mirror refuses the same unserializable value; drop its old row too.
		if ( $this->uses_db_fallback( $group ) && ! $this->write_db_transient( $key, $group, $data, $ttl ) ) {
			$this->delete_db_transient( $key, $group );
		}

		return true;
	}

	/** @return bool[] */
	public function set_multiple( array $data, $group = '', $expire = 0 ) {
		$results = array();
		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->set( $key, $value, $group, $expire );
		}
		return $results;
	}

	/**
	 * Retrieve a cached value.
	 *
	 * @param int|string $key
	 * @param bool|null  $found Passed by reference.
	 * @return mixed|false
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$found = false;
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$group = $this->normalize_group( $group );
		$key   = (string) $key;

		$runtime_found = $this->runtime_exists( $key, $group );
		$can_refresh   = $this->can_read_persistent_key( $key, $group ) || $this->uses_db_fallback( $group );

		if ( ( ! $force || ! $can_refresh ) && $runtime_found ) {
			$found = true;
			++$this->cache_hits;
			return $this->clone_value( $this->cache[ $group ][ $key ] );
		}

		$runtime_fallback = null;
		$runtime_ttl      = $this->max_ttl;
		if ( $force && $can_refresh && $runtime_found ) {
			$runtime_fallback = $this->clone_value( $this->cache[ $group ][ $key ] );
			$runtime_ttl      = $this->remaining_runtime_ttl( $key, $group );
			$this->remove_runtime( $key, $group );
		}

		if ( $this->can_read_persistent_key( $key, $group ) ) {
			$redis_value = $this->read_redis( $key, $group );
			if ( $redis_value['found'] ) {
				$this->put_runtime( $key, $group, $redis_value['value'], $redis_value['ttl'] );
				$found = true;
				++$this->cache_hits;
				return $this->clone_value( $redis_value['value'] );
			}
		}

		if ( $this->uses_db_fallback( $group ) ) {
			$db_value = $this->read_db_transient( $key, $group );
			if ( $db_value['found'] ) {
				$value = $db_value['value'];
				$ttl   = $db_value['ttl'];
				if ( $this->is_persistent_key( $key, $group ) ) {
					// Rewarm without overwriting a value created after our Redis miss.
					$rewarmed = $this->store_redis( $key, $group, $value, $ttl, true );
					if ( false === $rewarmed ) {
						$winner = $this->read_redis( $key, $group );
						if ( $winner['found'] ) {
							$value = $winner['value'];
							$ttl   = $winner['ttl'];
						}
					}
				}
				$this->put_runtime( $key, $group, $value, $ttl );
				$found = true;
				++$this->cache_hits;
				return $this->clone_value( $value );
			}
		}

		// A forced refresh must not turn a usable request-local value into a miss
		// merely because Redis became unavailable during this request.
		if ( $force && $runtime_found && $this->redis_failed ) {
			$this->put_runtime( $key, $group, $runtime_fallback, $runtime_ttl );
			$found = true;
			++$this->cache_hits;
			return $this->clone_value( $runtime_fallback );
		}

		++$this->cache_misses;
		return false;
	}

	/** @return array<int|string, mixed> */
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$values = array();
		foreach ( (array) $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group, $force );
		}
		return $values;
	}

	/**
	 * Delete a value from every configured storage layer.
	 *
	 * @param int|string $key
	 */
	public function delete( $key, $group = 'default', $deprecated = false ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$group   = $this->normalize_group( $group );
		$key     = (string) $key;
		$deleted = $this->remove_runtime( $key, $group );

		if ( $this->is_persistent_key( $key, $group ) ) {
			$redis_deleted = $this->delete_redis( $key, $group );
			if ( null === $redis_deleted ) {
				$this->mark_dirty();
			}
			$deleted = true === $redis_deleted || $deleted;
		}

		if ( $this->uses_db_fallback( $group ) ) {
			$deleted = $this->delete_db_transient( $key, $group ) || $deleted;
		}

		return $deleted;
	}

	/** @return bool[] */
	public function delete_multiple( array $keys, $group = '' ) {
		$results = array();
		foreach ( $keys as $key ) {
			$results[ $key ] = $this->delete( $key, $group );
		}
		return $results;
	}

	/** @return int|false */
	public function incr( $key, $offset = 1, $group = 'default' ) {
		return $this->change_numeric_value( $key, (int) $offset, $group );
	}

	/** @return int|false */
	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->change_numeric_value( $key, -1 * (int) $offset, $group );
	}

	/** Alias used by some older object-cache integrations. */
	public function increment( $key, $offset = 1, $group = 'default' ) {
		return $this->incr( $key, $offset, $group );
	}

	/** Alias used by some older object-cache integrations. */
	public function decrement( $key, $offset = 1, $group = 'default' ) {
		return $this->decr( $key, $offset, $group );
	}

	/**
	 * Invalidate the full object-cache namespace without touching unrelated Redis keys.
	 */
	public function flush() {
		$this->flush_runtime();
		$redis_ok = true;

		if ( $this->object_enabled ) {
			$redis_ok = $this->bump_object_generation();
			if ( ! $redis_ok ) {
				/*
				 * The next request that reaches Redis invalidates the Redis copies.
				 * Nothing deletes the database mirror later, so its rows (current,
				 * since every change reaches them) outlive this flush, which fails.
				 */
				$this->mark_dirty();
			}
		}

		/*
		 * Deleting the database mirror while the Redis generation still points at the
		 * old values is a partial purge: the rows are gone, but stale Redis entries
		 * keep answering. Retain the rows instead and report failure, which is the
		 * same rule Purger::purge() applies to the confirmed object purge.
		 */
		$db_ok = true;
		if ( $redis_ok && $this->transient_db_fallback ) {
			$db_ok = $this->clear_db_transient_group( 'transient', false );
			$db_ok = $this->clear_db_transient_group( 'site-transient', false ) && $db_ok;
		}

		return $redis_ok && $db_ok;
	}

	/** Clear only the request-local L1 cache. */
	public function flush_runtime() {
		$this->cache       = array();
		$this->expirations = array();
		return true;
	}

	/**
	 * Invalidate one cache group using its generation key.
	 */
	public function flush_group( $group ) {
		$group = $this->normalize_group( $group );
		unset( $this->cache[ $group ], $this->expirations[ $group ] );

		$redis_ok = true;
		if ( $this->is_persistent_group( $group ) ) {
			$redis_ok = $this->bump_group_generation( $group );
			if ( ! $redis_ok ) {
				// As in flush(), the deferred invalidation covers Redis only.
				$this->mark_dirty();
			}
		}

		// Same rule as flush(): no database deletion behind a failed generation bump.
		$db_ok = true;
		if ( $redis_ok && $this->uses_db_fallback( $group ) ) {
			$db_ok = $this->clear_db_transient_group( $group, true );
		}

		return $redis_ok && $db_ok;
	}

	/** @param string|string[] $groups */
	public function add_global_groups( $groups ) {
		foreach ( (array) $groups as $group ) {
			if ( is_scalar( $group ) && '' !== (string) $group ) {
				$this->global_groups[ (string) $group ] = true;
			}
		}
	}

	/** @param string|string[] $groups */
	public function add_non_persistent_groups( $groups ) {
		foreach ( (array) $groups as $group ) {
			if ( is_scalar( $group ) && '' !== (string) $group ) {
				$this->non_persistent_groups[ (string) $group ] = true;
			}
		}
	}

	/**
	 * Multisite is intentionally unsupported. Clear L1 if another component still
	 * asks WordPress to switch blogs, preventing request-local cross-site reuse.
	 */
	public function switch_to_blog( $blog_id ) {
		$this->flush_runtime();
	}

	public function reset() {
		$this->flush_runtime();
	}

	public function close() {
		return true;
	}

	/** Basic compatibility output used by a few diagnostics plugins. */
	public function stats() {
		$hits   = (int) $this->cache_hits;
		$misses = (int) $this->cache_misses;
		echo '<p><strong>Cache Hits:</strong> ' . $hits . '<br />';
		echo '<strong>Cache Misses:</strong> ' . $misses . '</p>';
	}

	/** @param int|string $key */
	private function is_valid_key( $key ): bool {
		if ( is_int( $key ) || ( is_string( $key ) && '' !== trim( $key ) ) ) {
			return true;
		}

		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong(
				__METHOD__,
				'Cache key must be an integer or a non-empty string.',
				'6.1.0'
			);
		}

		return false;
	}

	private function normalize_group( $group ): string {
		if ( empty( $group ) || ! is_scalar( $group ) ) {
			return 'default';
		}
		return (string) $group;
	}

	private function normalize_ttl( $expire ): int {
		$expire = (int) $expire;
		if ( $expire <= 0 || $expire > $this->max_ttl ) {
			return $this->max_ttl;
		}
		return $expire;
	}

	private function clone_value( $value ) {
		if ( ! is_object( $value ) ) {
			return $value;
		}

		try {
			return clone $value;
		} catch ( Throwable $throwable ) {
			return $value;
		}
	}

	private function runtime_exists( string $key, string $group ): bool {
		if ( ! isset( $this->cache[ $group ] ) || ! array_key_exists( $key, $this->cache[ $group ] ) ) {
			return false;
		}

		$expires_at = (int) ( $this->expirations[ $group ][ $key ] ?? 0 );
		if ( $expires_at > 0 && $expires_at <= time() ) {
			$this->remove_runtime( $key, $group );
			return false;
		}

		return true;
	}

	private function put_runtime( string $key, string $group, $value, int $ttl ): void {
		$this->cache[ $group ][ $key ]       = $this->clone_value( $value );
		$this->expirations[ $group ][ $key ] = time() + max( 1, $ttl );
	}

	private function remove_runtime( string $key, string $group ): bool {
		if ( ! isset( $this->cache[ $group ] ) || ! array_key_exists( $key, $this->cache[ $group ] ) ) {
			return false;
		}

		unset( $this->cache[ $group ][ $key ], $this->expirations[ $group ][ $key ] );
		if ( empty( $this->cache[ $group ] ) ) {
			unset( $this->cache[ $group ], $this->expirations[ $group ] );
		}
		return true;
	}

	private function remaining_runtime_ttl( string $key, string $group ): int {
		$expires_at = (int) ( $this->expirations[ $group ][ $key ] ?? 0 );
		return $expires_at > time() ? min( $this->max_ttl, $expires_at - time() ) : $this->max_ttl;
	}

	private function is_persistent_group( string $group ): bool {
		return $this->object_enabled && ! isset( $this->non_persistent_groups[ $group ] );
	}

	/**
	 * Core caches the plugin's own settings as options:simple_redis_cache_settings,
	 * and they hold the Redis password and the Cloudflare API token. That one entry
	 * stays in L1 and is read from the options table, so the secrets never reach Redis.
	 */
	private function is_persistent_key( string $key, string $group ): bool {
		return $this->is_persistent_group( $group ) && ! ( 'options' === $group && self::SETTINGS_OPTION === $key );
	}

	private function can_read_persistent_key( string $key, string $group ): bool {
		if ( ! $this->is_persistent_key( $key, $group ) ) {
			return false;
		}

		if ( $this->persistent_reads_enabled ) {
			return true;
		}

		// With an external object cache, WordPress never falls back to the options
		// table for transients. Keep them readable unless our explicit DB mirror can
		// provide the authoritative admin-side fallback.
		return ! $this->transient_db_fallback && in_array( $group, array( 'transient', 'site-transient' ), true );
	}

	private function uses_db_fallback( string $group ): bool {
		return $this->transient_db_fallback && in_array( $group, array( 'transient', 'site-transient' ), true );
	}

	/** @return Redis|null */
	private function client() {
		if (
			! $this->object_enabled ||
			$this->redis_failed
		) {
			return null;
		}

		if ( $this->redis_attempted ) {
			return $this->redis_client;
		}

		$this->redis_attempted = true;
		$client                = $this->redis->client();
		if ( null === $client ) {
			// connect() has already tripped the breaker for a real failure, and a
			// connection the open breaker skipped must not extend it.
			$this->redis_failed = true;
			$this->remember_error( $this->redis->error() ?? 'Unable to connect to Redis.' );
			return null;
		}

		try {
			if ( defined( 'Redis::OPT_SERIALIZER' ) && defined( 'Redis::SERIALIZER_NONE' ) ) {
				$client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE );
			}
			if ( defined( 'Redis::OPT_COMPRESSION' ) && defined( 'Redis::COMPRESSION_NONE' ) ) {
				$client->setOption( Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE );
			}
		} catch ( Throwable $throwable ) {
			$this->disable_redis( $throwable );
			return null;
		}

		$this->redis_client = $client;

		// A pending or failed invalidation leaves the namespace stale: run uncached instead.
		return $this->clear_dirty_marker() && ! $this->redis_failed ? $this->redis_client : null;
	}

	private function storage_key( string $key, string $group ): ?string {
		if ( null === $this->client() ) {
			return null;
		}

		$object_generation = $this->get_object_generation();
		$group_generation  = $this->get_group_generation( $group );
		if ( null === $object_generation || null === $group_generation ) {
			return null;
		}

		$digest = hash( 'sha256', $group . "\0" . $key );
		return $this->prefix . 'object:' . $object_generation . ':' . $group_generation . ':' . $digest;
	}

	private function get_object_generation(): ?int {
		if ( null !== $this->object_generation ) {
			return $this->object_generation;
		}

		// Never memoize the placeholder generation() returns alongside an error.
		$generation = $this->redis->generation( 'object' );
		if ( null !== $this->redis->error() ) {
			$this->record_redis_error( $this->redis->error() );
			return null;
		}

		$this->object_generation = $generation;
		return $this->object_generation;
	}

	private function get_group_generation( string $group ): ?int {
		if ( isset( $this->group_generations[ $group ] ) ) {
			return $this->group_generations[ $group ];
		}

		$generation = $this->redis->generation( $this->group_generation_namespace( $group ) );
		if ( null !== $this->redis->error() ) {
			$this->record_redis_error( $this->redis->error() );
			return null;
		}

		$this->group_generations[ $group ] = $generation;
		return $generation;
	}

	private function group_generation_namespace( string $group ): string {
		return 'object-group-' . hash( 'sha256', $group );
	}

	private function bump_object_generation(): bool {
		if ( null === $this->client() ) {
			return false;
		}

		$generation = $this->redis->bump_generation( 'object' );
		if ( false === $generation || null !== $this->redis->error() ) {
			$this->record_redis_error( $this->redis->error() ?? 'Unable to invalidate the Redis object cache.' );
			return false;
		}

		$this->object_generation = $generation;
		$this->group_generations = array();
		return true;
	}

	private function bump_group_generation( string $group ): bool {
		if ( null === $this->client() ) {
			return false;
		}

		$generation = $this->redis->bump_generation( $this->group_generation_namespace( $group ) );
		if ( false === $generation || null !== $this->redis->error() ) {
			$this->record_redis_error( $this->redis->error() ?? 'Unable to invalidate a Redis object-cache group.' );
			return false;
		}

		$this->group_generations[ $group ] = $generation;
		return true;
	}

	/** @return string|false */
	private function encode( $value ) {
		try {
			return "SRC1\0" . serialize( $value );
		} catch ( Throwable $throwable ) {
			$this->remember_error( 'Object-cache serialization failed: ' . $throwable->getMessage() );
			return false;
		}
	}

	/** @return array{valid: bool, value: mixed} */
	private function decode( string $payload ): array {
		if ( ! str_starts_with( $payload, "SRC1\0" ) ) {
			return array( 'valid' => false, 'value' => null );
		}

		$serialized = substr( $payload, 5 );
		try {
			$value = @unserialize( $serialized );
		} catch ( Throwable $throwable ) {
			return array( 'valid' => false, 'value' => null );
		}

		if ( false === $value && 'b:0;' !== $serialized ) {
			return array( 'valid' => false, 'value' => null );
		}

		return array( 'valid' => true, 'value' => $value );
	}

	/**
	 * @return bool|null True when stored, false when NX/XX rejected, null when unavailable.
	 */
	private function store_redis(
		string $key,
		string $group,
		$value,
		int $ttl,
		bool $only_if_absent = false,
		bool $only_if_present = false
	): ?bool {
		/*
		 * A value that cannot be serialized will never reach Redis on any attempt, so
		 * it must not be reported as an outage: null makes callers fail open and tell
		 * WordPress the write succeeded. Answer the NX/XX "rejected" value instead.
		 */
		$payload = $this->encode( $value );
		if ( false === $payload ) {
			return false;
		}

		$client      = $this->client();
		$storage_key = $this->storage_key( $key, $group );
		if ( null === $client || null === $storage_key ) {
			return null;
		}

		$options = array( 'ex' => max( 1, $ttl ) );
		if ( $only_if_absent ) {
			$options[] = 'nx';
		} elseif ( $only_if_present ) {
			$options[] = 'xx';
		}

		try {
			$stored = $client->set( $storage_key, $payload, $options );
			if ( true === $stored ) {
				return true;
			}

			if ( $only_if_absent ) {
				// A normal SET NX rejection means the key exists. If it vanished
				// between SET and EXISTS, retry once so an outage is not mistaken
				// for an ordinary add() collision.
				if ( (int) $client->exists( $storage_key ) > 0 ) {
					return false;
				}

				$stored = $client->set( $storage_key, $payload, $options );
				if ( true === $stored ) {
					return true;
				}
				if ( (int) $client->exists( $storage_key ) > 0 ) {
					return false;
				}
			}

			if ( $only_if_present ) {
				return false;
			}

			$this->disable_redis( new RuntimeException( 'Redis SET returned an unsuccessful response.' ) );
			return null;
		} catch ( Throwable $throwable ) {
			$this->disable_redis( $throwable );
			return null;
		}
	}

	/** @return array{found: bool, value: mixed, ttl: int} */
	private function read_redis( string $key, string $group ): array {
		$client      = $this->client();
		$storage_key = $this->storage_key( $key, $group );
		if ( null === $client || null === $storage_key ) {
			return array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
		}

		try {
			$payload = $client->get( $storage_key );
			if ( false === $payload || ! is_string( $payload ) ) {
				return array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
			}

			$decoded = $this->decode( $payload );
			if ( ! $decoded['valid'] ) {
				$client->del( $storage_key );
				return array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
			}

			$ttl = (int) $client->ttl( $storage_key );
			if ( $ttl < 1 ) {
				$ttl = 1;
			}

			return array(
				'found' => true,
				'value' => $decoded['value'],
				'ttl'   => min( $this->max_ttl, $ttl ),
			);
		} catch ( Throwable $throwable ) {
			$this->disable_redis( $throwable );
			return array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
		}
	}

	private function delete_redis( string $key, string $group ): ?bool {
		$client      = $this->client();
		$storage_key = $this->storage_key( $key, $group );
		if ( null === $client || null === $storage_key ) {
			return null;
		}

		try {
			return (int) $client->del( $storage_key ) > 0;
		} catch ( Throwable $throwable ) {
			$this->disable_redis( $throwable );
			return null;
		}
	}

	/** @return int|false */
	private function change_numeric_value( $key, int $change, $group ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$group = $this->normalize_group( $group );
		$key   = (string) $key;

		if ( $this->is_persistent_key( $key, $group ) ) {
			$redis_result = $this->change_numeric_value_in_redis( $key, $group, $change );

			if ( 'missing' === $redis_result['status'] && $this->uses_db_fallback( $group ) ) {
				$db_value = $this->read_db_transient( $key, $group );
				if ( $db_value['found'] ) {
					// Restore a missing Redis transient without overwriting a value
					// another request may have created in the meantime, then retry the
					// atomic operation against whichever value won.
					$rewarmed = $this->store_redis(
						$key,
						$group,
						$db_value['value'],
						$db_value['ttl'],
						true
					);

					if ( null === $rewarmed ) {
						$this->put_runtime( $key, $group, $db_value['value'], $db_value['ttl'] );
						$redis_result['status'] = 'unavailable';
					} else {
						$redis_result = $this->change_numeric_value_in_redis( $key, $group, $change );
					}
				}
			}

			if ( 'unavailable' === $redis_result['status'] ) {
				// The change may or may not have reached Redis before it failed.
				$this->mark_dirty();
			}

			if ( 'success' === $redis_result['status'] ) {
				$value = (int) $redis_result['value'];
				$ttl   = (int) $redis_result['ttl'];
				$this->put_runtime( $key, $group, $value, $ttl );
				if ( $this->uses_db_fallback( $group ) ) {
					$this->write_db_transient( $key, $group, $value, $ttl );
				}
				return $value;
			}

			if ( in_array( $redis_result['status'], array( 'missing', 'failed' ), true ) ) {
				$this->remove_runtime( $key, $group );
				return false;
			}
			// On an actual Redis outage, continue with L1/DB below. This is the
			// same fail-open policy used by set() and add().
		}

		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}

		$value = is_numeric( $value ) ? (int) $value : 0;
		$value = max( 0, $value + $change );
		$ttl   = $this->remaining_runtime_ttl( $key, $group );

		$this->set( $key, $value, $group, $ttl );
		return $value;
	}

	/**
	 * Atomically update a serialized numeric Redis value using WATCH/MULTI.
	 * The existing millisecond TTL is retained across the transaction.
	 *
	 * @return array{status: 'success'|'missing'|'unavailable'|'failed', value: int, ttl: int}
	 */
	private function change_numeric_value_in_redis( string $key, string $group, int $change ): array {
		$unavailable = array(
			'status' => 'unavailable',
			'value'  => 0,
			'ttl'    => $this->max_ttl,
		);
		$client      = $this->client();
		$storage_key = $this->storage_key( $key, $group );
		if ( null === $client || null === $storage_key ) {
			return $unavailable;
		}

		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			try {
				if ( ! $client->watch( $storage_key ) ) {
					throw new RuntimeException( 'Redis WATCH returned an unsuccessful response.' );
				}

				$payload = $client->get( $storage_key );
				if ( false === $payload || ! is_string( $payload ) ) {
					$client->unwatch();
					return array( 'status' => 'missing', 'value' => 0, 'ttl' => $this->max_ttl );
				}

				$decoded = $this->decode( $payload );
				if ( ! $decoded['valid'] ) {
					$client->unwatch();
					$client->del( $storage_key );
					return array( 'status' => 'missing', 'value' => 0, 'ttl' => $this->max_ttl );
				}

				$pttl = (int) $client->pttl( $storage_key );
				if ( -2 === $pttl ) {
					$client->unwatch();
					return array( 'status' => 'missing', 'value' => 0, 'ttl' => $this->max_ttl );
				}
				if ( $pttl < 0 ) {
					$pttl = $this->max_ttl * 1000;
				} else {
					$pttl = min( $this->max_ttl * 1000, max( 1, $pttl ) );
				}

				$value   = is_numeric( $decoded['value'] ) ? (int) $decoded['value'] : 0;
				$value   = max( 0, $value + $change );
				$encoded = $this->encode( $value );
				if ( false === $encoded ) {
					$client->unwatch();
					return array( 'status' => 'failed', 'value' => 0, 'ttl' => $this->max_ttl );
				}

				$client->multi();
				$client->set( $storage_key, $encoded, array( 'px' => $pttl ) );
				$transaction = $client->exec();
				if ( false === $transaction ) {
					continue;
				}

				if ( ! is_array( $transaction ) || true !== ( $transaction[0] ?? false ) ) {
					$this->disable_redis( new RuntimeException( 'Redis numeric transaction did not commit.' ) );
					return $unavailable;
				}

				return array(
					'status' => 'success',
					'value'  => $value,
					'ttl'    => max( 1, (int) ceil( $pttl / 1000 ) ),
				);
			} catch ( Throwable $throwable ) {
				// WATCH/MULTI state can survive on a persistent socket. Always try
				// to clear it before marking the connection unavailable.
				try {
					$client->discard();
				} catch ( Throwable $ignored ) {
					// The connection may not currently be inside MULTI.
				}
				try {
					$client->unwatch();
				} catch ( Throwable $ignored ) {
					// The connection may already be closed.
				}
				$this->disable_redis( $throwable );
				return $unavailable;
			}
		}

		try {
			$client->unwatch();
		} catch ( Throwable $ignored ) {
			// EXEC already clears WATCH in the normal contention path.
		}

		return array( 'status' => 'failed', 'value' => 0, 'ttl' => $this->max_ttl );
	}

	/** @return array{found: bool, value: mixed, ttl: int} */
	private function read_db_transient( string $key, string $group ): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
		}

		$names    = $this->transient_option_names( $key, $group );
		$sentinel = new stdClass();
		$timeout  = get_option( $names['timeout'], $sentinel );
		$ttl      = $this->max_ttl;

		if ( $sentinel !== $timeout ) {
			$expires_at = (int) $timeout;
			if ( $expires_at <= time() ) {
				$this->delete_db_transient( $key, $group );
				return array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
			}
			$ttl = min( $this->max_ttl, max( 1, $expires_at - time() ) );
		}

		$value = get_option( $names['value'], $sentinel );
		if ( $sentinel === $value ) {
			if ( $sentinel !== $timeout && function_exists( 'delete_option' ) ) {
				delete_option( $names['timeout'] );
			}
			return array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
		}

		return array( 'found' => true, 'value' => $value, 'ttl' => $ttl );
	}

	private function write_db_transient( string $key, string $group, $value, int $ttl ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		/*
		 * update_option() runs maybe_serialize(), which throws on a closure or other
		 * unserializable value. Refuse the mirror write instead of turning a cache
		 * miss into a fatal request.
		 */
		if ( false === $this->encode( $value ) ) {
			return false;
		}

		$names = $this->transient_option_names( $key, $group );
		update_option( $names['timeout'], time() + max( 1, $ttl ), false );
		update_option( $names['value'], $value, false );
		return true;
	}

	private function delete_db_transient( string $key, string $group ): bool {
		if ( ! function_exists( 'delete_option' ) ) {
			return false;
		}

		$names           = $this->transient_option_names( $key, $group );
		$value_deleted   = delete_option( $names['value'] );
		$timeout_deleted = delete_option( $names['timeout'] );
		return $value_deleted || $timeout_deleted;
	}

	/** @return array{value: string, timeout: string} */
	private function transient_option_names( string $key, string $group ): array {
		$prefix = 'site-transient' === $group ? '_site_transient_' : '_transient_';
		return array(
			'value'   => $prefix . $key,
			'timeout' => $prefix . 'timeout_' . $key,
		);
	}

	/**
	 * Delete mirrored DB values for a group. This intentionally operates on the
	 * WordPress options table only; multisite is outside this plugin's scope.
	 */
	private function clear_db_transient_group( string $group, bool $invalidate_options ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return false;
		}

		/*
		 * Invalidate the cached options group before deleting anything. A stale Redis
		 * copy of that group would let get_option() resurrect exactly the transients
		 * this call is about to remove, so a failed bump must abort the delete rather
		 * than leave a half-cleared state that still reports success.
		 */
		if ( $invalidate_options ) {
			unset( $this->cache['options'], $this->expirations['options'] );
			if ( $this->object_enabled && ! $this->bump_group_generation( 'options' ) ) {
				return false;
			}
		}

		$prefix = 'site-transient' === $group ? '_site_transient_' : '_transient_';
		$like   = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $prefix ) . '%' : $prefix . '%';
		$sql    = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like );
		$result = $wpdb->query( $sql );

		return false !== $result;
	}

	private function disable_redis( Throwable $throwable ): void {
		$this->record_redis_error( $throwable->getMessage() );
		$this->redis_client = null;
	}

	/**
	 * A runtime Redis failure: stop using Redis for the rest of this request. When
	 * the connection itself failed, the next requests skip Redis briefly instead
	 * of each waiting on a timeout; an error reply (OOM, READONLY) fails this
	 * request only. The Redis wrapper tells the two apart.
	 */
	private function record_redis_error( string $message ): void {
		$this->redis_failed = true;
		$this->remember_error( $message );
		$this->redis->trip_breaker();
	}

	private function remember_error( string $message ): void {
		if ( '' === $message || in_array( $message, $this->errors, true ) ) {
			return;
		}

		$this->errors[] = $message;
		if ( count( $this->errors ) > self::MAX_ERRORS ) {
			array_shift( $this->errors );
		}
	}

	/**
	 * Record that a persistent change never reached Redis, so its old copy cannot
	 * outlive the outage until max_ttl. The marker is written only when missing,
	 * which costs one stat per lost change and also covers a marker that another
	 * request cleared after this one wrote it. Write failures are ignored.
	 */
	private function mark_dirty(): void {
		if ( '' === $this->dirty_marker || $this->dirty_write_failed ) {
			return;
		}

		try {
			clearstatcache( true, $this->dirty_marker );
			if ( is_file( $this->dirty_marker ) ) {
				return;
			}

			$temporary = $this->dirty_marker . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
			if ( false === @file_put_contents( $temporary, (string) time() ) || ! @rename( $temporary, $this->dirty_marker ) ) {
				@unlink( $temporary );
				$this->dirty_write_failed = true;
			}
		} catch ( Throwable $throwable ) {
			$this->dirty_write_failed = true;
		}
	}

	/**
	 * Called on the first successful connection of a request: finish what an
	 * earlier request could not by invalidating the object namespace before this
	 * one reads anything. Returns false when this request must run uncached.
	 * Without a marker or a claim this costs two stats.
	 *
	 * The invalidating request first creates the claim file, exclusively, and
	 * only then removes the marker. Every other request checks the marker before
	 * the claim, so it cannot find neither while an invalidation is still
	 * pending; while the claim is fresh, it runs uncached (L1 and the database
	 * mirror only) rather than read the namespace about to be replaced. A marker
	 * written meanwhile by a request that is still failing survives the claim.
	 */
	private function clear_dirty_marker(): bool {
		if ( '' === $this->dirty_marker ) {
			return true;
		}

		$claim = $this->dirty_marker . '.claimed';
		$token = '';
		$busy  = 'Redis was skipped while another request invalidates the object cache.';

		try {
			clearstatcache( true, $this->dirty_marker );
			$dirty = is_file( $this->dirty_marker );
			clearstatcache( true, $claim );
			$claimed_at = is_file( $claim ) ? @filemtime( $claim ) : false;

			if ( ! $dirty && false === $claimed_at ) {
				return true;
			}

			// A fresh claim: another request is invalidating right now. It takes about
			// one EVAL, so wait briefly rather than run uncached and mark the cache
			// dirty all over again. One older than any invalidation can take was
			// abandoned, and is taken over below.
			if ( false !== $claimed_at && $this->claim_is_fresh( $claimed_at ) ) {
				if ( ! $this->wait_for_claim( $claim ) ) {
					return $this->skip_redis( $busy );
				}
				clearstatcache( true, $this->dirty_marker );
				$dirty = is_file( $this->dirty_marker );
				if ( ! $dirty ) {
					return true;
				}
				$claimed_at = false;
			}

			$token = bin2hex( random_bytes( 8 ) );
			if ( ! $this->claim_dirty_marker( $claim, $token, false !== $claimed_at ) ) {
				clearstatcache( true, $claim );
				$current = is_file( $claim ) ? @filemtime( $claim ) : false;
				if ( false !== $current && $this->claim_is_fresh( $current ) ) {
					return $this->skip_redis( $busy );
				}

				// wp-content refuses the claim — say, a marker WP-CLI left as another
				// user. Running uncached until someone fixes the permissions would be
				// silent and permanent, so invalidate without a claim instead.
				return $this->invalidate_unclaimable( $dirty ? $this->dirty_marker : $claim );
			}

			if ( $dirty ) {
				@unlink( $this->dirty_marker );
			}
		} catch ( Throwable $throwable ) {
			// A claim this left behind is abandoned once it is no longer fresh.
			return $this->skip_redis( $busy );
		}

		$invalidated = $this->bump_object_generation();

		try {
			// A request that took the claim over finishes the invalidation instead.
			if ( $token === @file_get_contents( $claim ) ) {
				if ( $invalidated ) {
					@unlink( $claim );
				} else {
					// Hand the claim back: backdated, it reads as abandoned to the next
					// request. If even that fails, it expires on its own.
					@touch( $claim, time() - $this->claim_seconds - 1 );
				}
			}
		} catch ( Throwable $throwable ) {
			// A leftover claim is abandoned once it is no longer fresh.
		}

		// A failed bump has already disabled Redis for this request.
		return $invalidated;
	}

	/**
	 * Create the claim file, or replace an abandoned one so that the request that
	 * abandoned it can no longer release it. Either way the claim exists without
	 * a gap, and it holds this request's token.
	 */
	private function claim_dirty_marker( string $claim, string $token, bool $take_over ): bool {
		if ( ! $take_over ) {
			$handle = @fopen( $claim, 'x' );
			if ( false === $handle ) {
				return false;
			}

			$written = @fwrite( $handle, $token );
			@fclose( $handle );
			if ( strlen( $token ) !== $written ) {
				@unlink( $claim );
				return false;
			}

			return true;
		}

		$temporary = $claim . '.' . $token . '.tmp';
		if ( false === @file_put_contents( $temporary, $token ) || ! @rename( $temporary, $claim ) ) {
			@unlink( $temporary );
			return false;
		}

		return true;
	}

	/** Wait up to 50 ms for another request's claim to be released. */
	private function wait_for_claim( string $claim ): bool {
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			usleep( 5000 );
			clearstatcache( true, $claim );
			if ( ! is_file( $claim ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Invalidate for a marker or claim this request cannot take. Redis records
	 * which file, by modification time and content, it was invalidated for, so
	 * the next requests see that and use Redis again instead of bumping the
	 * namespace on every request. A changed or rewritten file is a new one.
	 */
	private function invalidate_unclaimable( string $path ): bool {
		try {
			clearstatcache( true, $path );
			$modified = @filemtime( $path );
			if ( false === $modified ) {
				return true;
			}

			$stamp  = hash( 'sha256', $modified . "\0" . (string) @file_get_contents( $path, false, null, 0, 64 ) );
			$key    = $this->prefix . 'meta:object-dirty-acknowledged';
			$client = $this->redis->client();
			if ( null === $client ) {
				return false;
			}
			if ( $stamp === $client->get( $key ) ) {
				return true;
			}
		} catch ( Throwable $throwable ) {
			$this->disable_redis( $throwable );
			return false;
		}

		if ( ! $this->bump_object_generation() ) {
			return false;
		}

		try {
			$client->set( $key, $stamp );
		} catch ( Throwable $throwable ) {
			// The next request invalidates once more; nothing stale is served.
		}

		return true;
	}

	/** Whether a claim is recent enough that its request may still be invalidating. */
	private function claim_is_fresh( int $claimed_at ): bool {
		// A claim from the future (clock change) must not hold Redis off forever.
		$age = time() - $claimed_at;
		return $age < $this->claim_seconds && $age > -$this->claim_seconds;
	}

	/**
	 * Run the rest of this request without Redis although it is reachable. This
	 * is no Redis failure, so the breaker stays as it is; lost changes still mark
	 * the cache dirty.
	 */
	private function skip_redis( string $message ): bool {
		$this->redis_failed = true;
		$this->remember_error( $message );
		return false;
	}
}
