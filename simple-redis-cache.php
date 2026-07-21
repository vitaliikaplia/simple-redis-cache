<?php
/**
 * Plugin Name: Simple Redis Cache
 * Description: Redis-only object cache and full-page HTML cache for WordPress.
 * Version: 0.1.1
 * Author: Vitalii Kaplia
 * Author URI: https://kaplia.pro/
 * Update URI: https://github.com/vitaliikaplia/simple-redis-cache
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: simple-redis-cache
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SIMPLE_REDIS_CACHE_VERSION', '0.1.1' );
define( 'SIMPLE_REDIS_CACHE_FILE', __FILE__ );
define( 'SIMPLE_REDIS_CACHE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIMPLE_REDIS_CACHE_BASENAME', plugin_basename( __FILE__ ) );

require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-early-config.php';
require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-redis.php';
require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-config.php';
require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-dropins.php';
require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-purger.php';
require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-warmer.php';
require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-admin.php';
require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-github-updater.php';
require_once SIMPLE_REDIS_CACHE_DIR . 'includes/class-simple-redis-cache-plugin.php';

register_activation_hook( __FILE__, array( 'Simple_Redis_Cache_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Simple_Redis_Cache_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Simple_Redis_Cache_Plugin', 'init' ) );
