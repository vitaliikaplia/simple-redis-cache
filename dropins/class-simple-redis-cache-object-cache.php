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
	 * What get_multiple() read from Redis in one round trip, for the get() calls
	 * it then makes: group => key => read_redis() result. Each entry is used once.
	 *
	 * @var array<string, array<int|string, array{found: bool, value: mixed, ttl: int}>>
	 */
	private array $prefetched = array();

	/**
	 * The notoptions list as this request last synchronized it with Redis (read
	 * or written); null for none. A write merges only the changes made since,
	 * so a list read early in a long request cannot bring back options another
	 * request created meanwhile.
	 *
	 * @var array<int|string, mixed>|null
	 */
	private ?array $notoptions_base = null;

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
		$this->forget_prefetch();
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
					$this->settle_notoptions( $key, $group, $data );
				}
				return false;
			}
		}

		$this->put_runtime( $key, $group, $data, $ttl );
		$this->settle_notoptions( $key, $group, $data );
		if ( $this->uses_db_fallback( $group ) ) {
			$this->write_db_transient( $key, $group, $data, $ttl );
		}

		return true;
	}

	/**
	 * add() for many keys, with their Redis writes sent as one pipeline: SET NX
	 * for all of them, then a single EXISTS round trip for any it refused. Each
	 * key gets exactly the answer, L1 entry and fail-open behaviour add() gives.
	 *
	 * @return bool[]
	 */
	public function add_multiple( array $data, $group = '', $expire = 0 ) {
		$this->forget_prefetch();
		$group = $this->normalize_group( $group );

		// The database mirror reads and rewarms key by key; nothing to batch elsewhere.
		if ( count( $data ) < 2 || ! $this->is_persistent_group( $group ) || $this->uses_db_fallback( $group ) ) {
			$results = array();
			foreach ( $data as $key => $value ) {
				$results[ $key ] = $this->add( $key, $value, $group, $expire );
			}
			return $results;
		}

		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return array_fill_keys( array_keys( $data ), false );
		}

		$ttl      = $this->normalize_ttl( $expire );
		$results  = array();
		$values   = array();
		$payloads = array();
		foreach ( $data as $key => $value ) {
			$results[ $key ] = false;
			if ( ! $this->is_valid_key( $key ) ) {
				continue;
			}

			$name = (string) $key;
			if ( $this->runtime_exists( $name, $group ) ) {
				continue;
			}

			if ( ! $this->is_persistent_key( $name, $group ) || $this->is_notoptions( $name, $group ) ) {
				$results[ $key ] = $this->add( $key, $value, $group, $expire );
				continue;
			}

			// A value that cannot be serialized is refused and never kept, as in add().
			$payload = $this->encode( $value );
			if ( false !== $payload ) {
				$values[ $name ]   = $value;
				$payloads[ $name ] = $payload;
			}
		}

		foreach ( $this->add_redis_many( $group, $payloads, $ttl ) as $name => $stored ) {
			$name = (string) $name;
			if ( false === $stored ) {
				// Refused by NX; see add() for why wp-admin may still keep its copy.
				if ( ! $this->can_read_persistent_key( $name, $group ) ) {
					$this->put_runtime( $name, $group, $values[ $name ], $ttl );
				}
				continue;
			}

			// Stored, or Redis is unavailable and add() fails open.
			$this->put_runtime( $name, $group, $values[ $name ], $ttl );
			$results[ $name ] = true;
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
		$this->forget_prefetch();
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
		$scrubbed = false;
		$stored   = $this->store_redis( $key, $group, $data, $ttl, false, true, $scrubbed );
		$db_value = null;

		if ( false === $stored && $this->uses_db_fallback( $group ) ) {
			$db_value = $this->read_db_transient( $key, $group );
			if ( $db_value['found'] ) {
				$rewarmed = $this->store_redis( $key, $group, $db_value['value'], $db_value['ttl'], true );
				$stored   = null === $rewarmed
					? null
					: $this->store_redis( $key, $group, $data, $ttl, false, true, $scrubbed );
			}
		}

		if ( true === $stored ) {
			$this->put_runtime( $key, $group, $data, $ttl );
			$this->settle_notoptions( $key, $group, $data );
			if ( $this->uses_db_fallback( $group ) ) {
				$this->write_db_transient( $key, $group, $data, $ttl );
			}
			return true;
		}

		if ( null === $stored ) {
			// Whatever this call returns, Redis may still hold the value it replaces,
			// unless the refused write could at least delete it.
			if ( ! $scrubbed ) {
				$this->mark_dirty();
			}

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
		$this->forget_prefetch();
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$group = $this->normalize_group( $group );
		$key   = (string) $key;
		$ttl   = $this->normalize_ttl( $expire );

		$this->put_runtime( $key, $group, $data, $ttl );

		if ( $this->is_persistent_key( $key, $group ) ) {
			$scrubbed = false;
			if ( $this->is_notoptions( $key, $group ) && is_array( $data ) ) {
				$stored = $this->store_notoptions( $data, $ttl, $scrubbed );
			} else {
				$stored = $this->store_redis( $key, $group, $data, $ttl, false, false, $scrubbed );
				$this->settle_notoptions( $key, $group, $data );
			}

			/*
			 * A plain SET is refused only for a value that cannot be serialized. It
			 * never reaches Redis, so the previous copy must go, or later requests
			 * would keep reading it; a DEL that cannot be done marks the cache dirty.
			 * So does a write Redis failed, unless it could still delete the old copy.
			 */
			if ( false === $stored ) {
				$stored = $this->delete_redis( $key, $group );
			}
			if ( null === $stored && ! $scrubbed ) {
				$this->mark_dirty();
			}
		}

		// The mirror refuses the same unserializable value; drop its old row too.
		if ( $this->uses_db_fallback( $group ) && ! $this->write_db_transient( $key, $group, $data, $ttl ) ) {
			$this->delete_db_transient( $key, $group );
		}

		return true;
	}

	/**
	 * set() for many keys, with their Redis writes sent as one pipeline. L1, the
	 * dirty marker and the database mirror end up exactly as set() leaves them.
	 *
	 * @return bool[]
	 */
	public function set_multiple( array $data, $group = '', $expire = 0 ) {
		$this->forget_prefetch();
		$group = $this->normalize_group( $group );

		if ( count( $data ) < 2 || ! $this->is_persistent_group( $group ) ) {
			$results = array();
			foreach ( $data as $key => $value ) {
				$results[ $key ] = $this->set( $key, $value, $group, $expire );
			}
			return $results;
		}

		$ttl      = $this->normalize_ttl( $expire );
		$results  = array();
		$payloads = array();
		$merged   = array();
		foreach ( $data as $key => $value ) {
			$results[ $key ] = false;
			if ( ! $this->is_valid_key( $key ) ) {
				continue;
			}

			$name = (string) $key;
			if ( $this->is_notoptions( $name, $group ) ) {
				// Merged with the current Redis copy by set(), after the batch.
				$merged[ $key ] = $value;
				continue;
			}

			$this->put_runtime( $name, $group, $value, $ttl );
			$results[ $key ] = true;
			if ( $this->is_persistent_key( $name, $group ) ) {
				// False queues a DEL instead: see set() for an unserializable value.
				$payloads[ $name ] = $this->encode( $value );
			}
		}

		if ( in_array( null, $this->write_redis_many( $group, $payloads, $ttl ), true ) ) {
			$this->mark_dirty();
		}

		if ( $this->uses_db_fallback( $group ) ) {
			foreach ( $data as $key => $value ) {
				if ( true === $results[ $key ] && ! $this->write_db_transient( (string) $key, $group, $value, $ttl ) ) {
					$this->delete_db_transient( (string) $key, $group );
				}
			}
		}

		foreach ( $merged as $key => $value ) {
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

	/**
	 * get() for many keys. What get() would read from Redis one key at a time is
	 * read first in one round trip; each get() then takes its key's reply from
	 * there, so the answer, L1 and database fallbacks are exactly get()'s.
	 *
	 * @return array<int|string, mixed>
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$keys = (array) $keys;

		// A nested call, from a hook that a get() below triggers, reads key by key.
		$owner = array() === $this->prefetched;
		if ( $owner ) {
			$this->prefetch( $keys, $this->normalize_group( $group ), (bool) $force );
		}

		$values = array();
		try {
			foreach ( $keys as $key ) {
				$values[ $key ] = $this->get( $key, $group, $force );
			}
		} finally {
			if ( $owner ) {
				$this->prefetched = array();
			}
		}

		return $values;
	}

	/**
	 * Drop the replies get_multiple() read ahead: a change this request makes to
	 * the cache, from a hook that runs inside one of its get() calls, must not be
	 * answered by a value read before that change.
	 */
	private function forget_prefetch(): void {
		$this->prefetched = array();
	}

	/**
	 * Delete a value from every configured storage layer.
	 *
	 * @param int|string $key
	 */
	public function delete( $key, $group = 'default', $deprecated = false ) {
		$this->forget_prefetch();
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$group   = $this->normalize_group( $group );
		$key     = (string) $key;
		$deleted = $this->remove_runtime( $key, $group );
		$this->settle_notoptions( $key, $group, null );

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

	/**
	 * delete() for many keys, with their Redis DELs sent as one pipeline. Each
	 * key, repeated ones included, gets the answer delete() would give it.
	 *
	 * @return bool[]
	 */
	public function delete_multiple( array $keys, $group = '' ) {
		$this->forget_prefetch();
		$group = $this->normalize_group( $group );

		if ( count( $keys ) < 2 || ! $this->is_persistent_group( $group ) ) {
			$results = array();
			foreach ( $keys as $key ) {
				$results[ $key ] = $this->delete( $key, $group );
			}
			return $results;
		}

		$results = array();
		$pending = array();
		$names   = array();
		foreach ( $keys as $key ) {
			if ( ! $this->is_valid_key( $key ) ) {
				$results[ $key ] = false;
				continue;
			}

			$name              = (string) $key;
			$index             = count( $pending );
			$results[ $key ]   = false;
			$pending[ $index ] = array( $key, $name, $this->remove_runtime( $name, $group ) );
			$this->settle_notoptions( $name, $group, null );
			if ( $this->is_persistent_key( $name, $group ) ) {
				$names[ $index ] = $name;
			}
		}

		$outcomes = $this->delete_redis_many( $group, $names );
		if ( in_array( null, $outcomes, true ) ) {
			$this->mark_dirty();
		}

		foreach ( $pending as $index => list( $key, $name, $deleted ) ) {
			if ( array_key_exists( $index, $outcomes ) ) {
				$deleted = true === $outcomes[ $index ] || $deleted;
			}
			if ( $this->uses_db_fallback( $group ) ) {
				$deleted = $this->delete_db_transient( $name, $group ) || $deleted;
			}
			$results[ $key ] = $deleted;
		}

		return $results;
	}

	/** @return int|false */
	public function incr( $key, $offset = 1, $group = 'default' ) {
		$this->forget_prefetch();
		return $this->change_numeric_value( $key, self::int_value( $offset ), $group );
	}

	/** @return int|false */
	public function decr( $key, $offset = 1, $group = 'default' ) {
		$this->forget_prefetch();
		$offset = self::int_value( $offset );
		// -PHP_INT_MIN is a float; adding PHP_INT_MAX saturates to the same result.
		return $this->change_numeric_value( $key, PHP_INT_MIN === $offset ? PHP_INT_MAX : -$offset, $group );
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
		$this->forget_prefetch();
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
		$this->forget_prefetch();
		$this->cache           = array();
		$this->expirations     = array();
		$this->notoptions_base = null;
		return true;
	}

	/**
	 * Invalidate one cache group using its generation key.
	 */
	public function flush_group( $group ) {
		$this->forget_prefetch();
		$group = $this->normalize_group( $group );
		unset( $this->cache[ $group ], $this->expirations[ $group ] );
		$this->settle_notoptions( 'notoptions', $group, null );

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
		$this->forget_prefetch();
		$this->flush_runtime();
	}

	public function reset() {
		$this->forget_prefetch();
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

	/** Whether $key can name a cache entry; is_valid_key() also reports misuse. */
	private function is_usable_key( $key ): bool {
		return is_int( $key ) || ( is_string( $key ) && '' !== trim( $key ) );
	}

	/** @param int|string $key */
	private function is_valid_key( $key ): bool {
		if ( $this->is_usable_key( $key ) ) {
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
		if ( null === $this->client() || ! $this->load_generations( array( $group ) ) ) {
			return null;
		}

		$digest = hash( 'sha256', $group . "\0" . $key );
		return $this->prefix . 'object:' . $this->object_generation . ':' . $this->group_generations[ $group ] . ':' . $digest;
	}

	private function get_object_generation(): ?int {
		return $this->load_generations( array() ) ? $this->object_generation : null;
	}

	private function get_group_generation( string $group ): ?int {
		return $this->load_generations( array( $group ) ) ? $this->group_generations[ $group ] : null;
	}

	/**
	 * Make sure the object generation and these groups' generations are known,
	 * reading every one this request has not read yet in a single round trip.
	 * Nothing from a failed read is memoized: no placeholder generation can ever
	 * name a namespace this request then writes to.
	 *
	 * @param string[] $groups
	 */
	private function load_generations( array $groups ): bool {
		if ( null === $this->client() ) {
			return false;
		}

		$namespaces = array();
		if ( null === $this->object_generation ) {
			$namespaces[] = 'object';
		}
		$pending = array();
		foreach ( $groups as $group ) {
			if ( ! isset( $this->group_generations[ $group ] ) ) {
				$namespace             = $this->group_generation_namespace( $group );
				$pending[ $namespace ] = $group;
				$namespaces[]          = $namespace;
			}
		}
		if ( array() === $namespaces ) {
			return true;
		}

		$generations = $this->redis->generations( $namespaces );
		if ( null === $generations || null !== $this->redis->error() ) {
			$this->record_redis_error( $this->redis->error() ?? 'Redis returned an invalid cache generation.' );
			return false;
		}

		if ( isset( $generations['object'] ) ) {
			$this->object_generation = $generations['object'];
		}
		foreach ( $pending as $namespace => $group ) {
			$this->group_generations[ $group ] = $generations[ $namespace ];
		}

		return true;
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
	 * @param bool $scrubbed Set when Redis refused a SET or SET XX with an error
	 *                       reply but then deleted the key: no stale copy is left.
	 * @return bool|null True when stored, false when NX/XX rejected, null when unavailable.
	 */
	private function store_redis(
		string $key,
		string $group,
		$value,
		int $ttl,
		bool $only_if_absent = false,
		bool $only_if_present = false,
		bool &$scrubbed = false
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
			// A reply error() reports from now on belongs to this write.
			$client->clearLastError();
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

				return $this->retry_add( $client, $storage_key, $payload, $options );
			}

			if ( $only_if_present ) {
				return false;
			}

			$this->disable_redis( new RuntimeException( 'Redis SET returned an unsuccessful response.' ) );
			$scrubbed = $this->scrub_after_error_reply( $client, array( $storage_key ) );
			return null;
		} catch ( Throwable $throwable ) {
			$this->disable_redis( $throwable );
			if ( ! $only_if_absent ) {
				$scrubbed = $this->scrub_after_error_reply( $client, array( $storage_key ) );
			}
			return null;
		}
	}

	/**
	 * Second and last SET NX attempt for a key that a first one found taken but
	 * EXISTS then found gone: true when stored, false when taken, null otherwise.
	 *
	 * @param array<int|string, mixed> $options
	 */
	private function retry_add( Redis $client, string $storage_key, string $payload, array $options ): ?bool {
		try {
			if ( true === $client->set( $storage_key, $payload, $options ) ) {
				return true;
			}
			if ( (int) $client->exists( $storage_key ) > 0 ) {
				return false;
			}

			$this->disable_redis( new RuntimeException( 'Redis SET returned an unsuccessful response.' ) );
			return null;
		} catch ( Throwable $throwable ) {
			$this->disable_redis( $throwable );
			return null;
		}
	}

	/**
	 * After Redis refused a write with an error reply, such as OOM, delete the
	 * keys it was for. Redis accepts DEL even when out of memory, and with the
	 * old copies gone nothing is left for the dirty marker to invalidate. False
	 * when the write left no reply (the connection failed) or the DEL failed too.
	 * Called once the write has already disabled Redis for this request.
	 *
	 * @param string[] $storage_keys
	 */
	private function scrub_after_error_reply( Redis $client, array $storage_keys ): bool {
		try {
			$reply = $client->getLastError();
			if ( ! is_string( $reply ) || '' === $reply || array() === $storage_keys ) {
				return false;
			}

			$client->clearLastError();
			return is_int( $client->del( $storage_keys ) );
		} catch ( Throwable $throwable ) {
			// The DEL failed on its own: an error reply leaves the breaker alone,
			// a lost connection trips it.
			$this->remember_error( $throwable->getMessage() );
			$this->redis->trip_breaker();
			return false;
		}
	}

	/** @return array{found: bool, value: mixed, ttl: int} */
	private function read_redis( string $key, string $group ): array {
		$miss = array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );

		// What get_multiple() read for this key; once Redis has failed, it reads nothing.
		if ( isset( $this->prefetched[ $group ] ) && array_key_exists( $key, $this->prefetched[ $group ] ) ) {
			$result = $this->prefetched[ $group ][ $key ];
			unset( $this->prefetched[ $group ][ $key ] );
			if ( array() === $this->prefetched[ $group ] ) {
				unset( $this->prefetched[ $group ] );
			}
			if ( ! $this->redis_failed ) {
				return $this->note_read( $key, $group, $result );
			}
		}

		$client      = $this->client();
		$storage_key = $this->storage_key( $key, $group );
		if ( null === $client || null === $storage_key ) {
			return $this->note_read( $key, $group, $miss );
		}

		// GET and TTL in one round trip: L1 keeps the entry no longer than Redis does.
		$replies = $this->pipeline(
			$client,
			static function ( Redis $pipe ) use ( $storage_key ): void {
				$pipe->get( $storage_key );
				$pipe->ttl( $storage_key );
			},
			2
		);
		if ( null === $replies ) {
			return $this->note_read( $key, $group, $miss );
		}

		$result = $this->redis_result( $replies[0], $replies[1] );
		if ( null === $result ) {
			try {
				$client->del( $storage_key );
			} catch ( Throwable $throwable ) {
				$this->disable_redis( $throwable );
			}
			$result = $miss;
		}

		return $this->note_read( $key, $group, $result );
	}

	/**
	 * A read_redis() result from a GET and a TTL reply, or null for a payload
	 * that is not a valid SRC1 entry, which the caller deletes.
	 *
	 * @param mixed $payload
	 * @param mixed $ttl
	 * @return array{found: bool, value: mixed, ttl: int}|null
	 */
	private function redis_result( $payload, $ttl ): ?array {
		if ( ! is_string( $payload ) ) {
			return array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
		}

		$decoded = $this->decode( $payload );
		if ( ! $decoded['valid'] ) {
			return null;
		}

		return array(
			'found' => true,
			'value' => $decoded['value'],
			'ttl'   => min( $this->max_ttl, max( 1, (int) $ttl ) ),
		);
	}

	/**
	 * Read in one round trip what get() would otherwise read from Redis key by
	 * key: only keys it would look up there, not those served from L1 or that
	 * this request may not read from Redis. Invalid payloads are deleted at once.
	 *
	 * @param array<int|string, mixed> $keys
	 */
	private function prefetch( array $keys, string $group, bool $force ): void {
		$names = array();
		foreach ( $keys as $key ) {
			if ( ! $this->is_usable_key( $key ) ) {
				continue;
			}
			$name = (string) $key;
			if ( ! $this->can_read_persistent_key( $name, $group ) || ( ! $force && $this->runtime_exists( $name, $group ) ) ) {
				continue;
			}
			$names[ $name ] = true;
		}

		// One key costs get() a single round trip already.
		$storage_keys = count( $names ) < 2 ? null : $this->storage_keys( $group, self::by_name( $names ) );
		$client       = null === $storage_keys ? null : $this->client();
		$replies      = null === $client ? null : $this->pipeline(
			$client,
			static function ( Redis $pipe ) use ( $storage_keys ): void {
				$pipe->mget( array_values( $storage_keys ) );
				foreach ( $storage_keys as $storage_key ) {
					$pipe->ttl( $storage_key );
				}
			},
			1 + count( $storage_keys )
		);
		if ( null === $client || null === $replies || ! is_array( $replies[0] ) || count( $replies[0] ) !== count( $storage_keys ) ) {
			return;
		}

		$payloads = array_values( $replies[0] );
		$invalid  = array();
		$position = 0;
		foreach ( $storage_keys as $name => $storage_key ) {
			$result = $this->redis_result( $payloads[ $position ], $replies[ $position + 1 ] );
			if ( null === $result ) {
				$invalid[] = $storage_key;
				$result    = array( 'found' => false, 'value' => null, 'ttl' => $this->max_ttl );
			}
			$this->prefetched[ $group ][ $name ] = $result;
			++$position;
		}

		if ( array() !== $invalid ) {
			try {
				$client->del( $invalid );
			} catch ( Throwable $throwable ) {
				$this->disable_redis( $throwable );
			}
		}
	}

	/**
	 * Storage keys for several keys of one group, by the same index, reading any
	 * generation they need first. Null when Redis is unavailable.
	 *
	 * @param array<int|string, int|string> $names
	 * @return array<int|string, string>|null
	 */
	private function storage_keys( string $group, array $names ): ?array {
		if ( null === $this->client() || ! $this->load_generations( array( $group ) ) ) {
			return null;
		}

		$storage_keys = array();
		foreach ( $names as $index => $name ) {
			$storage_keys[ $index ] = (string) $this->storage_key( (string) $name, $group );
		}
		return $storage_keys;
	}

	/**
	 * The keys of a name-keyed array, keyed by themselves, for storage_keys().
	 *
	 * @param array<int|string, mixed> $by_name
	 * @return array<int|string, int|string>
	 */
	private static function by_name( array $by_name ): array {
		$names = array_keys( $by_name );
		return array_combine( $names, $names );
	}

	/**
	 * Send what $queue adds as one pipeline: one round trip. The replies come
	 * back in order; null after a failure, which, like any failed command, stops
	 * this request from using Redis. An error reply such as OOM makes PhpRedis
	 * throw for the whole pipeline once every reply is read, and leaves it in
	 * getLastError(), so the breaker still tells it from a lost connection.
	 *
	 * @param callable(Redis): void $queue
	 * @return array<int, mixed>|null
	 */
	private function pipeline( Redis $client, callable $queue, int $expected ): ?array {
		try {
			$client->clearLastError();
			$client->multi( Redis::PIPELINE );
			$queue( $client );
			$replies = $client->exec();
			if ( ! is_array( $replies ) || count( $replies ) !== $expected ) {
				throw new RuntimeException( 'Redis returned an incomplete pipeline response.' );
			}
			return array_values( $replies );
		} catch ( Throwable $throwable ) {
			try {
				// Commands queued before the failure must not reach the next call.
				if ( Redis::PIPELINE === $client->getMode() ) {
					$client->discard();
				}
			} catch ( Throwable $ignored ) {
				// The connection reports its own failure on the next command.
			}
			$this->disable_redis( $throwable );
			return null;
		}
	}

	/**
	 * SET, or DEL for a value that cannot be serialized (false), several keys of
	 * one group in a single pipeline. Per key: true when stored, a DEL's answer,
	 * or null when Redis did not take it, which leaves the cache dirty unless the
	 * refused keys could at least be deleted (false).
	 *
	 * @param array<int|string, string|false> $payloads
	 * @return array<int|string, bool|null>
	 */
	private function write_redis_many( string $group, array $payloads, int $ttl ): array {
		$outcomes     = array_fill_keys( array_keys( $payloads ), null );
		$storage_keys = array() === $payloads ? null : $this->storage_keys( $group, self::by_name( $payloads ) );
		$client       = null === $storage_keys ? null : $this->client();
		if ( null === $client ) {
			return $outcomes;
		}

		$options = array( 'ex' => max( 1, $ttl ) );
		$replies = $this->pipeline(
			$client,
			static function ( Redis $pipe ) use ( $payloads, $storage_keys, $options ): void {
				foreach ( $payloads as $name => $payload ) {
					if ( false === $payload ) {
						$pipe->del( $storage_keys[ $name ] );
					} else {
						$pipe->set( $storage_keys[ $name ], $payload, $options );
					}
				}
			},
			count( $payloads )
		);
		if ( null === $replies ) {
			// Which writes took effect is unknown; deleting them all leaves nothing stale.
			$scrubbed = $this->scrub_after_error_reply( $client, array_values( $storage_keys ) );
			return array_fill_keys( array_keys( $payloads ), $scrubbed ? false : null );
		}

		$refused  = array();
		$position = 0;
		foreach ( $payloads as $name => $payload ) {
			$reply = $replies[ $position++ ];
			if ( false === $payload ) {
				$outcomes[ $name ] = (int) $reply > 0;
			} elseif ( true === $reply ) {
				$outcomes[ $name ] = true;
			} else {
				$refused[ $name ] = $storage_keys[ $name ];
			}
		}

		if ( array() !== $refused ) {
			// A plain SET fails only with an error reply; the other writes went through.
			$this->disable_redis( new RuntimeException( 'Redis SET returned an unsuccessful response.' ) );
			$scrubbed = $this->scrub_after_error_reply( $client, array_values( $refused ) );
			foreach ( array_keys( $refused ) as $name ) {
				$outcomes[ $name ] = $scrubbed ? false : null;
			}
		}

		return $outcomes;
	}

	/**
	 * SET NX for several keys of one group in one pipeline, then one EXISTS
	 * pipeline for those refused, then add()'s single retry for any that
	 * vanished meanwhile. Per key: true when stored, false when the key exists,
	 * null when Redis is unavailable (add() then fails open).
	 *
	 * @param array<int|string, string> $payloads
	 * @return array<int|string, bool|null>
	 */
	private function add_redis_many( string $group, array $payloads, int $ttl ): array {
		$outcomes     = array_fill_keys( array_keys( $payloads ), null );
		$storage_keys = array() === $payloads ? null : $this->storage_keys( $group, self::by_name( $payloads ) );
		$client       = null === $storage_keys ? null : $this->client();
		$options      = array( 'ex' => max( 1, $ttl ), 'nx' );
		$replies      = null === $client ? null : $this->pipeline(
			$client,
			static function ( Redis $pipe ) use ( $payloads, $storage_keys, $options ): void {
				foreach ( $payloads as $name => $payload ) {
					$pipe->set( $storage_keys[ $name ], $payload, $options );
				}
			},
			count( $payloads )
		);
		// A lost add() needs neither a dirty marker nor a scrub: see add().
		if ( null === $client || null === $replies ) {
			return $outcomes;
		}

		$refused  = array();
		$position = 0;
		foreach ( array_keys( $payloads ) as $name ) {
			if ( true === $replies[ $position++ ] ) {
				$outcomes[ $name ] = true;
			} else {
				$refused[ $name ] = $storage_keys[ $name ];
			}
		}
		if ( array() === $refused ) {
			return $outcomes;
		}

		$replies = $this->pipeline(
			$client,
			static function ( Redis $pipe ) use ( $refused ): void {
				foreach ( $refused as $storage_key ) {
					$pipe->exists( $storage_key );
				}
			},
			count( $refused )
		);
		if ( null === $replies ) {
			return $outcomes;
		}

		$position = 0;
		foreach ( $refused as $name => $storage_key ) {
			if ( (int) $replies[ $position++ ] > 0 ) {
				$outcomes[ $name ] = false;
			} elseif ( ! $this->redis_failed ) {
				$outcomes[ $name ] = $this->retry_add( $client, $storage_key, $payloads[ $name ], $options );
			}
		}

		return $outcomes;
	}

	/**
	 * DEL several keys of one group in one pipeline, one command per key so each
	 * gets its own answer: true when deleted, false when absent, null when Redis
	 * is unavailable.
	 *
	 * @param array<int, string> $names
	 * @return array<int, bool|null>
	 */
	private function delete_redis_many( string $group, array $names ): array {
		$outcomes     = array_fill_keys( array_keys( $names ), null );
		$storage_keys = array() === $names ? null : $this->storage_keys( $group, $names );
		$client       = null === $storage_keys ? null : $this->client();
		$replies      = null === $client ? null : $this->pipeline(
			$client,
			static function ( Redis $pipe ) use ( $storage_keys ): void {
				foreach ( $storage_keys as $storage_key ) {
					$pipe->del( $storage_key );
				}
			},
			count( $storage_keys )
		);
		if ( null === $replies ) {
			return $outcomes;
		}

		$position = 0;
		foreach ( array_keys( $storage_keys ) as $index ) {
			$outcomes[ $index ] = (int) $replies[ $position++ ] > 0;
		}

		return $outcomes;
	}

	/** Core's list of options known not to exist. */
	private function is_notoptions( string $key, string $group ): bool {
		return 'options' === $group && 'notoptions' === $key;
	}

	/**
	 * Record the notoptions list L1 now holds in step with Redis: one this
	 * request read, stored or deleted (null).
	 *
	 * @param mixed $value
	 */
	private function settle_notoptions( string $key, string $group, $value ): void {
		if ( $this->is_notoptions( $key, $group ) ) {
			$this->notoptions_base = is_array( $value ) ? $value : null;
		}
	}

	/**
	 * @param array{found: bool, value: mixed, ttl: int} $result
	 * @return array{found: bool, value: mixed, ttl: int}
	 */
	private function note_read( string $key, string $group, array $result ): array {
		$this->settle_notoptions( $key, $group, $result['found'] ? $result['value'] : null );
		return $result;
	}

	/**
	 * Write core's notoptions list as a three-way merge: the copy Redis holds now,
	 * plus the entries this request added since it last synchronized the list,
	 * minus those it removed. A plain write would put back whatever the request
	 * read early on, so an option another request created meanwhile would stay
	 * "missing" for every request until the entry expired (#11418).
	 *
	 * WATCH makes the read and the write one step, as incr() does. A request that
	 * may read Redis keeps the merged list in L1; one that may not (wp-admin with
	 * cache_wp_admin off) keeps its own and merges its next change from there.
	 *
	 * @param array<int|string, mixed> $ours
	 * @param bool                     $scrubbed See store_redis().
	 * @return bool|null As store_redis().
	 */
	private function store_notoptions( array $ours, int $ttl, bool &$scrubbed ): ?bool {
		if ( false === $this->encode( $ours ) ) {
			return false;
		}

		/*
		 * An empty list from a request that never read or wrote the list is a reset —
		 * what a plugin writes after inserting options behind the cache's back. A merge
		 * would keep every entry, since this request saw none of them; dropping the
		 * shared list instead only costs the queries that rebuild it. A request that
		 * emptied a list it had (core removing its last entry) merges as usual.
		 */
		if ( array() === $ours && null === $this->notoptions_base ) {
			$deleted = $this->delete_redis( 'notoptions', 'options' );
			if ( null === $deleted ) {
				return null;
			}
			$this->put_runtime( 'notoptions', 'options', array(), $ttl );
			$this->notoptions_base = array();
			return true;
		}

		$client      = $this->client();
		$storage_key = $this->storage_key( 'notoptions', 'options' );
		if ( null === $client || null === $storage_key ) {
			return null;
		}

		$base = $this->notoptions_base ?? array();
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			try {
				$client->clearLastError();
				if ( ! $client->watch( $storage_key ) ) {
					throw new RuntimeException( 'Redis WATCH returned an unsuccessful response.' );
				}

				$theirs  = array();
				$payload = $client->get( $storage_key );
				if ( is_string( $payload ) ) {
					$decoded = $this->decode( $payload );
					if ( $decoded['valid'] && is_array( $decoded['value'] ) ) {
						$theirs = $decoded['value'];
					}
				}

				$merged  = self::merge_notoptions( $base, $ours, $theirs );
				$encoded = $this->encode( $merged );
				if ( false === $encoded ) {
					$client->unwatch();
					return false;
				}

				$client->multi();
				$client->set( $storage_key, $encoded, array( 'ex' => max( 1, $ttl ) ) );
				$transaction = $client->exec();
				if ( false === $transaction ) {
					// EXECABORT carries an error reply; a WATCH conflict carries none.
					$reply = $client->getLastError();
					if ( is_string( $reply ) && '' !== $reply ) {
						throw new RuntimeException( $reply );
					}
					continue;
				}
				if ( ! is_array( $transaction ) || true !== ( $transaction[0] ?? false ) ) {
					throw new RuntimeException( 'Redis notoptions transaction did not commit.' );
				}

				$settled = $this->can_read_persistent_key( 'notoptions', 'options' ) ? $merged : $ours;
				$this->put_runtime( 'notoptions', 'options', $settled, $ttl );
				$this->notoptions_base = $settled;
				return true;
			} catch ( Throwable $throwable ) {
				try {
					if ( Redis::MULTI === $client->getMode() ) {
						$client->discard();
					}
					$client->unwatch();
				} catch ( Throwable $ignored ) {
					// The connection may already be closed.
				}
				$this->disable_redis( $throwable );
				$scrubbed = $this->scrub_after_error_reply( $client, array( $storage_key ) );
				return null;
			}
		}

		try {
			$client->unwatch();
		} catch ( Throwable $ignored ) {
			// EXEC already clears WATCH in the normal contention path.
		}

		// Still contended after five tries: drop the shared list rather than guess.
		// It is only a shortcut; the next request rebuilds it from the database.
		$this->notoptions_base = $ours;
		return null === $this->delete_redis( 'notoptions', 'options' ) ? null : true;
	}

	/**
	 * $theirs, plus every entry $ours added or changed relative to $base, minus
	 * every entry $ours dropped from it.
	 *
	 * @param array<int|string, mixed> $base
	 * @param array<int|string, mixed> $ours
	 * @param array<int|string, mixed> $theirs
	 * @return array<int|string, mixed>
	 */
	private static function merge_notoptions( array $base, array $ours, array $theirs ): array {
		foreach ( $ours as $name => $flag ) {
			if ( ! array_key_exists( $name, $base ) || $base[ $name ] !== $flag ) {
				$theirs[ $name ] = $flag;
			}
		}
		foreach ( array_keys( $base ) as $name ) {
			if ( ! array_key_exists( $name, $ours ) ) {
				unset( $theirs[ $name ] );
			}
		}
		return $theirs;
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
				$value = $redis_result['value'];
				$ttl   = $redis_result['ttl'];
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

		$value = self::changed_number( $value, $change );
		$ttl   = $this->remaining_runtime_ttl( $key, $group );

		$this->set( $key, $value, $group, $ttl );
		return $value;
	}

	/**
	 * The value incr()/decr() store and return: the old value as an integer (0
	 * when it is not numeric), changed, never below zero and never above
	 * PHP_INT_MAX. Core's runtime cache lets an overflowing counter turn into a
	 * float; a counter here stops at PHP_INT_MAX instead, so what Redis holds,
	 * what L1 holds and what the call returns are always the same integer, and
	 * no float is ever cast to int (PHP 8.5 warns when it does not fit).
	 *
	 * @param mixed $value
	 */
	private static function changed_number( $value, int $change ): int {
		$value = is_numeric( $value ) ? self::int_value( $value ) : 0;

		if ( $change > 0 && $value > PHP_INT_MAX - $change ) {
			return PHP_INT_MAX;
		}
		if ( $change < 0 && $value < PHP_INT_MIN - $change ) {
			return 0;
		}

		return max( 0, $value + $change );
	}

	/**
	 * (int) $number, except that a float outside the integer range saturates to
	 * PHP_INT_MAX or PHP_INT_MIN and NAN becomes 0: PHP 8.5 warns about those
	 * casts, and earlier versions return an arbitrary value. Numeric strings
	 * already saturate when cast.
	 *
	 * @param mixed $number
	 */
	private static function int_value( $number ): int {
		if ( ! is_float( $number ) ) {
			return (int) $number;
		}
		if ( is_nan( $number ) ) {
			return 0;
		}
		// (float) PHP_INT_MAX rounds up to 2^63, which is already out of range.
		if ( $number >= (float) PHP_INT_MAX ) {
			return PHP_INT_MAX;
		}
		if ( $number < (float) PHP_INT_MIN ) {
			return PHP_INT_MIN;
		}
		return (int) $number;
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

				$value   = self::changed_number( $decoded['value'], $change );
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
			$this->notoptions_base = null;
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
