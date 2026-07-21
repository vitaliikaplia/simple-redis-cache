=== Simple Redis Cache ===
Contributors: vitaliikaplia
Tags: redis, object cache, page cache, performance
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused Redis-only object cache and full-page HTML cache for WordPress.

== Description ==

Simple Redis Cache provides two independent cache layers backed by one standalone Redis server through the PhpRedis extension:

* A persistent WordPress object cache via `wp-content/object-cache.php`.
* An early full-page HTML cache via `wp-content/advanced-cache.php`.

There is no file-based cache, CDN, minification, scheduled/background preload, or automatic content purge. Redis TTL and explicit manual actions control cache lifetime.

Connection settings are configured in the WordPress admin. `WP_CACHE_KEY_SALT`, when defined in `wp-config.php`, is always used as the base prefix for every Redis key.

The settings screen is divided into Redis Connection, Object Cache, HTML Page Cache, Cache Warming, and Status tabs. Manual cache warming discovers WordPress system pages plus public standard/custom post types and taxonomies, then verifies every anonymous URL through a Redis cache HIT while reporting live progress in the open browser tab. The administration interface is available in English and Ukrainian and follows the current WordPress admin language.

Updates are discovered from the public `vitaliikaplia/simple-redis-cache` GitHub repository and installed through the standard WordPress plugin updater. WordPress native automatic updates are supported when enabled for this plugin.

== Installation ==

1. Install and enable the PhpRedis PHP extension.
2. Activate Simple Redis Cache.
3. Open Settings > Redis Cache.
4. Enter the Redis connection settings on the Redis Connection tab.
5. Enable Object Cache and/or HTML Page Cache on their respective tabs.
6. Optionally use Cache Warming to select public content and keep the tab open until its live progress completes.
7. Use the Status tab to test the saved connection and inspect diagnostics.

The plugin never overwrites a drop-in owned by another plugin.

GitHub releases are branch-based. To publish an update, synchronize the `Version`
plugin header, `SIMPLE_REDIS_CACHE_VERSION`, `Stable tag`, changelog, and bundled
translation files, then push the plugin files to the repository's `master` branch.
A GitHub tag or Release is not required.

Deactivation removes the generated early configuration and both plugin-owned
drop-ins while retaining admin settings for a later reactivation. Deleting the
plugin also removes its saved settings. `WP_CACHE` is intentionally left alone
because another WordPress cache may use it.

== Safety ==

* Redis failures are fail-open and must not take the site offline.
* Purge uses versioned namespaces and never calls `FLUSHDB` or `FLUSHALL`.
* Logged-in users, private responses, sessions, and sensitive cookies are bypassed by default.
* Cache warming is manual, anonymous, and browser-led; it does not run on cron and does not clear existing cache entries first.
* Automatic purge on post, comment, term, menu, or WooCommerce changes is intentionally not implemented.
* GitHub update metadata is cached for 12 hours; failed checks are retried after 1 hour.

== Changelog ==

= 0.1.1 =
* Added English and Ukrainian localization based on the WordPress admin language.
* Split the settings screen into Redis, object cache, HTML page cache, and status tabs.
* Kept Redis page-cache hits available during normal and hard browser reloads.
* Added manual verified HTML-cache warming for public WordPress content and an updated Caching admin-bar menu.

= 0.1.0 =
* Initial single-site Redis object cache and HTML page cache implementation.
* Added updates through the public GitHub repository and the native WordPress updater.
