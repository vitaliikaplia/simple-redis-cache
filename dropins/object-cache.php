<?php
/**
 * Simple Redis Cache Drop-In
 * Simple Redis Cache Object Cache Drop-In
 *
 * Installed as wp-content/object-cache.php by the plugin. Keep this file tiny:
 * all implementation code stays inside the plugin so updates remain atomic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SIMPLE_REDIS_CACHE_OBJECT_CACHE_DROPIN' ) ) {
	define( 'SIMPLE_REDIS_CACHE_OBJECT_CACHE_DROPIN', true );
}

$simple_redis_cache_object_loader = '__SIMPLE_REDIS_CACHE_LOADER__';

if ( is_readable( $simple_redis_cache_object_loader ) ) {
	require_once $simple_redis_cache_object_loader;
}

unset( $simple_redis_cache_object_loader );
