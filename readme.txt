=== Simple Redis Cache ===
Contributors: vitaliikaplia
Tags: redis, object cache, page cache, performance
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused Redis-only object cache and full-page HTML cache for WordPress.

== Description ==

Simple Redis Cache provides two independent cache layers backed by one standalone Redis server through the PhpRedis extension:

* A persistent WordPress object cache via `wp-content/object-cache.php`.
* An early full-page HTML cache via `wp-content/advanced-cache.php`.

There is no file-based cache, CDN, minification, preload, or automatic content purge. Redis TTL and explicit manual purge control cache lifetime.

Connection settings are configured in the WordPress admin. `WP_CACHE_KEY_SALT`, when defined in `wp-config.php`, is always used as the base prefix for every Redis key.

Updates are discovered from the public `vitaliikaplia/simple-redis-cache` GitHub repository and installed through the standard WordPress plugin updater. WordPress native automatic updates are supported when enabled for this plugin.

== Installation ==

1. Install and enable the PhpRedis PHP extension.
2. Activate Simple Redis Cache.
3. Open Settings > Simple Redis Cache.
4. Enter the Redis connection settings and test the connection.
5. Enable Object Cache and/or HTML Page Cache.

The plugin never overwrites a drop-in owned by another plugin.

GitHub releases are branch-based. To publish an update, change both the `Version`
plugin header and `SIMPLE_REDIS_CACHE_VERSION`, then push the plugin files to the
repository's `master` branch. A GitHub tag or Release is not required.

Deactivation removes the generated early configuration and both plugin-owned
drop-ins while retaining admin settings for a later reactivation. Deleting the
plugin also removes its saved settings. `WP_CACHE` is intentionally left alone
because another WordPress cache may use it.

== Safety ==

* Redis failures are fail-open and must not take the site offline.
* Purge uses versioned namespaces and never calls `FLUSHDB` or `FLUSHALL`.
* Logged-in users, private responses, sessions, and sensitive cookies are bypassed by default.
* Automatic purge on post, comment, term, menu, or WooCommerce changes is intentionally not implemented.
* GitHub update metadata is cached for 12 hours; failed checks are retried after 1 hour.

== Changelog ==

= 0.1.0 =
* Initial single-site Redis object cache and HTML page cache implementation.
* Added updates through the public GitHub repository and the native WordPress updater.
