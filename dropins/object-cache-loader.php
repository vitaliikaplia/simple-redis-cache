<?php
/**
 * Bootstrap and procedural WordPress Object Cache API for Simple Redis Cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_redis_cache_root = dirname( __DIR__ );
$simple_redis_cache_dependencies = array(
	$simple_redis_cache_root . '/includes/class-simple-redis-cache-early-config.php',
	$simple_redis_cache_root . '/includes/class-simple-redis-cache-redis.php',
);

foreach ( $simple_redis_cache_dependencies as $simple_redis_cache_dependency ) {
	if ( ! is_readable( $simple_redis_cache_dependency ) ) {
		return;
	}
	require_once $simple_redis_cache_dependency;
}

unset( $simple_redis_cache_dependencies, $simple_redis_cache_dependency );

// This drop-in intentionally supports single-site WordPress only. If a site is
// converted to multisite while the plugin is active, let core load its runtime
// cache rather than sharing keys between blogs.
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	unset( $simple_redis_cache_root );
	return;
}

// If disabling/removing the managed drop-in ever fails, do not leave WordPress
// believing that an external cache is active. Core will load its runtime cache.
$simple_redis_cache_early_config = Simple_Redis_Cache_Early_Config::load();
if ( empty( $simple_redis_cache_early_config['object']['enabled'] ) ) {
	unset( $simple_redis_cache_root, $simple_redis_cache_early_config );
	return;
}
unset( $simple_redis_cache_early_config );

if ( ! class_exists( 'WP_Object_Cache', false ) ) {
	require_once __DIR__ . '/class-simple-redis-cache-object-cache.php';
}

if ( ! function_exists( 'wp_cache_init' ) ) {
	function wp_cache_init() {
		global $wp_object_cache;
		$wp_object_cache = new WP_Object_Cache();
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->add( $key, $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_add_multiple' ) ) {
	function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->add_multiple( $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_replace' ) ) {
	function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->set( $key, $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_set_multiple' ) ) {
	function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->set_multiple( $data, $group, (int) $expire );
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		global $wp_object_cache;
		return $wp_object_cache->get( $key, $group, $force, $found );
	}
}

if ( ! function_exists( 'wp_cache_get_multiple' ) ) {
	function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
		global $wp_object_cache;
		return $wp_object_cache->get_multiple( $keys, $group, $force );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->delete( $key, $group );
	}
}

if ( ! function_exists( 'wp_cache_delete_multiple' ) ) {
	function wp_cache_delete_multiple( array $keys, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->delete_multiple( $keys, $group );
	}
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->incr( $key, $offset, $group );
	}
}

if ( ! function_exists( 'wp_cache_decr' ) ) {
	function wp_cache_decr( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->decr( $key, $offset, $group );
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() {
		global $wp_object_cache;
		return $wp_object_cache->flush();
	}
}

if ( ! function_exists( 'wp_cache_flush_runtime' ) ) {
	function wp_cache_flush_runtime() {
		global $wp_object_cache;
		return $wp_object_cache->flush_runtime();
	}
}

if ( ! function_exists( 'wp_cache_flush_group' ) ) {
	function wp_cache_flush_group( $group ) {
		global $wp_object_cache;
		return $wp_object_cache->flush_group( $group );
	}
}

if ( ! function_exists( 'wp_cache_supports' ) ) {
	function wp_cache_supports( $feature ) {
		return in_array(
			$feature,
			array(
				'add_multiple',
				'set_multiple',
				'get_multiple',
				'delete_multiple',
				'flush_runtime',
				'flush_group',
			),
			true
		);
	}
}

if ( ! function_exists( 'wp_cache_close' ) ) {
	function wp_cache_close() {
		global $wp_object_cache;
		return is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'close' )
			? $wp_object_cache->close()
			: true;
	}
}

if ( ! function_exists( 'wp_cache_add_global_groups' ) ) {
	function wp_cache_add_global_groups( $groups ) {
		global $wp_object_cache;
		$wp_object_cache->add_global_groups( $groups );
	}
}

if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
	function wp_cache_add_non_persistent_groups( $groups ) {
		global $wp_object_cache;
		$wp_object_cache->add_non_persistent_groups( $groups );
	}
}

if ( ! function_exists( 'wp_cache_switch_to_blog' ) ) {
	function wp_cache_switch_to_blog( $blog_id ) {
		global $wp_object_cache;
		$wp_object_cache->switch_to_blog( $blog_id );
	}
}

if ( ! function_exists( 'wp_cache_reset' ) ) {
	function wp_cache_reset() {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' );
		}
		global $wp_object_cache;
		$wp_object_cache->reset();
	}
}

/* WordPress 6.9+ salted-cache helpers. Defining them here also keeps the API
 * complete on older supported WordPress versions whose cache-compat.php does
 * not yet provide them. */
if ( ! function_exists( 'wp_cache_get_salted' ) ) {
	function wp_cache_get_salted( $cache_key, $group, $salt ) {
		$salt  = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		$cache = wp_cache_get( $cache_key, $group );
		if ( ! is_array( $cache ) || ! isset( $cache['salt'], $cache['data'] ) ) {
			return false;
		}
		return $salt === $cache['salt'] ? $cache['data'] : false;
	}
}

if ( ! function_exists( 'wp_cache_set_salted' ) ) {
	function wp_cache_set_salted( $cache_key, $data, $group, $salt, $expire = 0 ) {
		$salt = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		return wp_cache_set( $cache_key, array( 'data' => $data, 'salt' => $salt ), $group, $expire );
	}
}

if ( ! function_exists( 'wp_cache_get_multiple_salted' ) ) {
	function wp_cache_get_multiple_salted( $cache_keys, $group, $salt ) {
		$salt   = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		$values = wp_cache_get_multiple( $cache_keys, $group );
		foreach ( $values as $key => $cache ) {
			if (
				! is_array( $cache ) ||
				! isset( $cache['salt'], $cache['data'] ) ||
				$salt !== $cache['salt']
			) {
				$values[ $key ] = false;
			} else {
				$values[ $key ] = $cache['data'];
			}
		}
		return $values;
	}
}

if ( ! function_exists( 'wp_cache_set_multiple_salted' ) ) {
	function wp_cache_set_multiple_salted( $data, $group, $salt, $expire = 0 ) {
		$salt   = is_array( $salt ) ? implode( ':', $salt ) : $salt;
		$values = array();
		foreach ( $data as $key => $value ) {
			$values[ $key ] = array( 'data' => $value, 'salt' => $salt );
		}
		return wp_cache_set_multiple( $values, $group, $expire );
	}
}

unset( $simple_redis_cache_root );
