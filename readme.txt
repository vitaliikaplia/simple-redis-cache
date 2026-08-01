=== Simple Redis Cache ===
Contributors: vitaliikaplia
Tags: redis, object cache, page cache, performance
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused Redis-only object cache and full-page HTML cache for WordPress.

== Description ==

Simple Redis Cache provides two independent cache layers backed by one standalone Redis server through the PhpRedis extension:

* A persistent WordPress object cache via `wp-content/object-cache.php`.
* An early full-page HTML cache via `wp-content/advanced-cache.php`.

There is no file-based cache, CDN, minification, or scheduled/background preload. Independent targeted invalidation settings can expire the updated post, page, or public custom post type, its WPML/Polylang translations, and the public taxonomy term archives related to those posts without clearing unrelated page-cache entries.

Connection settings are configured in the WordPress admin. A non-empty `WP_CACHE_KEY_SALT`, when defined in `wp-config.php`, is preserved byte-for-byte as the base prefix for every Redis key created by this plugin. A missing or empty value uses the generated per-site fallback prefix.

The settings screen is divided into Redis Connection, Object Cache, HTML Page Cache, Cache Warming, and Status tabs. Manual cache warming discovers enabled WordPress system pages, frontend-viewable public post types, and non-empty terms of frontend-viewable public taxonomies. It respects the current Home, Singular, and Archive cache settings and verifies every anonymous URL through a Redis cache HIT while reporting live progress in the open browser tab. Attachment pages are included only when WordPress enables them. The administration interface is available in English and Ukrainian and follows the current WordPress admin language.

Updates are discovered from the `Version` header in `simple-redis-cache.php` on the public repository's `master` branch and installed through the standard WordPress plugin updater. WordPress native automatic updates are supported when enabled for this plugin.

== Installation ==

1. Install and enable the PhpRedis PHP extension.
2. Activate Simple Redis Cache.
3. Open Settings > Redis Cache.
4. Enter the Redis connection settings on the Redis Connection tab.
5. Enable Object Cache and/or HTML Page Cache on their respective tabs.
6. Optionally enable singular-page invalidation and/or related taxonomy-archive invalidation on the HTML Page Cache tab.
7. Optionally use Cache Warming to select public content and keep the tab open until its live progress completes.
8. Use the Status tab to test the saved connection and inspect diagnostics.

For a Unix socket, select Unix socket and enter the raw absolute filesystem path, such as `/home/account/.system/redis.sock`, in the Unix socket path field. Do not add `unix://`; Host and Port are ignored for this connection type.

When HTML Page Cache is enabled, the plugin attempts to add `define( 'WP_CACHE', true );` to `wp-config.php`. If automatic enabling fails, set `WP_CACHE` to `true` manually and verify the Status tab.

Test Saved Connection performs PING plus an isolated `SET -> GET -> DELETE` round trip with a 30-second TTL. Status also reports the Redis version and `maxmemory_policy`, drop-in paths and file modes, `WP_CACHE`, generated-config ownership/synchronization/mode, and the effective key prefix without exposing Redis credentials.

Sites using plain/query-string permalinks must enable query-string page caching before URLs such as `?p=`, `?page_id=`, `?cat=`, or `?paged=` can be warmed. Unsafe query parameters still always bypass the page cache. Pagination is estimated from WordPress database counts and the global `posts_per_page`; custom query rules can produce individual failed URLs. Counters cover every outcome, while the detailed issue list shows at most the first 100 discovery warnings, cache bypasses, and failures; any remaining issue count is shown separately. When enabled, search results and 404 pages may be cached on demand but have no finite discoverable URL list, so the warmer does not include them.

The plugin never overwrites a drop-in owned by another plugin.

To publish an update, synchronize the `Version` plugin header,
`SIMPLE_REDIS_CACHE_VERSION`, `Stable tag`, changelog, and bundled translation
files, then commit and push that coherent tree to the repository's `master`
branch. A GitHub tag or GitHub Release is not required. The branch package is
mutable and has no independent checksum or cryptographic signature; transport
relies on GitHub HTTPS and the integrity of the public repository.

Deactivation removes the generated early configuration and both plugin-owned
drop-ins while retaining admin settings for a later reactivation. Deleting the
plugin also removes settings, notices, updater metadata, generated config, and
plugin-owned drop-ins. Its updater site transient is deleted, but remaining
database transients are not bulk-deleted. Redis payload/meta, `WP_CACHE`, and
`WP_CACHE_KEY_SALT` remain. `WP_CACHE` is intentionally left alone
because another WordPress cache may use it.

== Safety ==

* Redis failures are fail-open and must not take the site offline.
* Saving cache-semantic settings automatically bumps only the affected active Redis object/page generation. A Redis datastore, credentials, or generated site-identity change invalidates active layers on both old and new targets; timeout, retry, persistence, and debug-header changes do not purge data.
* Redis object-generation invalidation does not silently delete database-mirror transients, which may rewarm Redis. The plugin warns about retained rows; use the separately confirmed Clear Object Cache action when those database values must also be removed.
* Changing `WP_CACHE_KEY_SALT` outside the admin immediately selects another namespace, but the plugin cannot recover the previous constant value. Clear the required layers while the old salt is still active, or do not restore it until the old payload TTLs have elapsed.
* If invalidation cannot reach Redis, new policy is not applied over the old generation: the latest early runtime is re-read and both managed cache layers and owned drop-ins are disabled fail-open. This also prevents a concurrent settings save from being partially rolled back. The plugin retries on the next authenticated `manage_options` administrator request, revalidates the latest saved config, and restores the required layers after invalidation succeeds.
* Redis payload purge uses versioned namespaces and never calls `FLUSHDB` or `FLUSHALL`. Clear Object Cache and Clear All require explicit confirmation and also physically delete every standard WordPress transient row from the current site's `wp_options`, including transients created by other plugins. Database rows are deleted only after object-generation invalidation succeeds; Clear Page Cache does not touch them.
* WordPress auth cookies and the bundled WordPress, WooCommerce, EDD, and PHP session-cookie patterns bypass page cache by default. Custom personalization cookies must be excluded or safely varied explicitly.
* Cookie exclusions are name-based in the early drop-in. A stale or duplicate auth/session cookie can continue to bypass HTML cache after logout until the browser removes it.
* Cache warming is manual and anonymous. The open tab performs same-origin browser requests with a 30-second per-request timeout and uses a protected server-side loopback fallback for a different origin, timeout, or network failure; no cron runs and existing entries are not cleared first. Only the anonymous no-cookie variant is warmed.
* Targeted post-update invalidation is disabled by default. When enabled, the core `post_updated` hook invalidates every cached HTML variant of that frontend-viewable post/page/CPT and all translations discovered through the documented WPML or Polylang APIs. Stale payloads are removed on their next read or expire by TTL.
* Related taxonomy-archive invalidation is a separate disabled-by-default option. It invalidates all cached variants and pagination of assigned terms in frontend-viewable standard and custom taxonomies. Both old and new relationships are covered when terms change, hierarchical ancestors are included, and current terms of translated WPML/Polylang posts are collected.
* Unrelated term archives, generic post-type/author/date archives, search, 404, menu, comment, arbitrary post-meta-only, and WooCommerce-derived pages are intentionally not invalidated. A static front page or posts page is still invalidated when singular-page invalidation is enabled and that page itself is the updated post. Use manual Clear Page Cache when broader related views must also be refreshed.
* A Redis failure during post-update invalidation does not fail the WordPress save. The request remains fail-open, an administrator warning is queued, and the previous HTML may remain until TTL or manual page purge.
* Object-cache `expire=0` means the configured Maximum TTL, not infinite storage.
* Redis is a trusted serialization boundary and must be private and protected from untrusted writes. Missing generation metadata is recreated from a random safe integer rather than `1`, but `noeviction` or a volatile policy is still preferred for a stable hit rate.
* Redis credentials are stored in the WordPress database and generated early config. File mode `0640` is best-effort; protect database and filesystem access at the hosting layer.
* GitHub update metadata is cached for 12 hours. A failed check is cached for 1 hour, after which a later WordPress update check may retry.

== Changelog ==

= 0.3.0 =
* Added independent targeted HTML-cache invalidation for updated posts/pages/public CPTs, their WPML/Polylang translations, and assigned public taxonomy archives with old/new relationship and hierarchical-parent coverage, without scanning Redis or clearing unrelated pages.

= 0.2.0 =
* Added versioned settings migration and targeted object/page namespace invalidation when cache semantics or Redis storage identity changes.
* Deferred new runtime policy and conservatively disabled both managed drop-ins after an invalidation failure, then retried safely on a later administrator request; retained database mirrors now produce an explicit cleanup warning.
* Recreated missing generation metadata from random safe values and made generation bumps atomic to prevent stale namespace reuse after metadata eviction.
* Added explicit confirmation before object/all purges delete WordPress database transients, while preserving rows if object invalidation fails.
* Expanded Redis round-trip, server-policy, generated-config, drop-in ownership, path, and permission diagnostics without exposing credentials.
* Hardened page-request validation and manual warmup timeouts, URL checks, pagination estimates, retry handling, and bounded issue reporting.
* Added dependency-light regression and optional Redis integration checks for migrations, page requests, updater behavior, generation recovery, settings invalidation, and failure recovery.

= 0.1.1 =
* Added English and Ukrainian localization based on the WordPress admin language.
* Split the settings screen into Redis, object cache, HTML page cache, cache warming, and status tabs.
* Kept Redis page-cache hits available during normal and hard browser reloads.
* Added manual verified HTML-cache warming for public WordPress content and an updated Caching admin-bar menu.

= 0.1.0 =
* Initial single-site Redis object cache and HTML page cache implementation.
* Added updates through the public GitHub repository and the native WordPress updater.
