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

	/** @var string[] Runtime Redis errors, kept for diagnostics. */
	public $errors = array();

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
		$this->redis                    = new Simple_Redis_Cache_Redis( $this->config );

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
				if ( $this->is_persistent_group( $group ) ) {
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

		if ( $this->is_persistent_group( $group ) ) {
			$stored = $this->store_redis( $key, $group, $data, $ttl, true );
			if ( false === $stored ) {
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

		if ( ! $this->is_persistent_group( $group ) ) {
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

		if ( $this->is_persistent_group( $group ) ) {
			$this->store_redis( $key, $group, $data, $ttl );
		}

		if ( $this->uses_db_fallback( $group ) ) {
			$this->write_db_transient( $key, $group, $data, $ttl );
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
		$can_refresh   = $this->can_read_persistent_group( $group ) || $this->uses_db_fallback( $group );

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

		if ( $this->can_read_persistent_group( $group ) ) {
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
				if ( $this->is_persistent_group( $group ) ) {
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

		if ( $this->is_persistent_group( $group ) ) {
			$redis_deleted = $this->delete_redis( $key, $group );
			$deleted       = true === $redis_deleted || $deleted;
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

	private function can_read_persistent_group( string $group ): bool {
		if ( ! $this->is_persistent_group( $group ) ) {
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
			$this->record_redis_error( $this->redis->error() ?? 'Unable to connect to Redis.' );
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
		return $this->redis_client;
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

		$this->object_generation = $this->redis->generation( 'object' );
		if ( null !== $this->redis->error() ) {
			$this->record_redis_error( $this->redis->error() );
			return null;
		}

		return $this->object_generation;
	}

	private function get_group_generation( string $group ): ?int {
		if ( isset( $this->group_generations[ $group ] ) ) {
			return $this->group_generations[ $group ];
		}

		$namespace                         = $this->group_generation_namespace( $group );
		$this->group_generations[ $group ] = $this->redis->generation( $namespace );
		if ( null !== $this->redis->error() ) {
			$this->record_redis_error( $this->redis->error() );
			return null;
		}

		return $this->group_generations[ $group ];
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
			$this->errors[] = 'Object-cache serialization failed: ' . $throwable->getMessage();
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

		if ( $this->is_persistent_group( $group ) ) {
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
		$this->redis_failed = true;
		$this->redis_client = null;
	}

	private function record_redis_error( string $message ): void {
		$this->redis_failed = true;
		if ( '' !== $message && ! in_array( $message, $this->errors, true ) ) {
			$this->errors[] = $message;
		}
	}
}
