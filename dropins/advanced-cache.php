<?php
/**
 * Simple Redis Cache Drop-In
 *
 * This small bootstrap file is copied to wp-content/advanced-cache.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$simple_redis_cache_loader = '__SIMPLE_REDIS_CACHE_LOADER__';

if ( is_readable( $simple_redis_cache_loader ) ) {
	require $simple_redis_cache_loader;
}

unset( $simple_redis_cache_loader );
