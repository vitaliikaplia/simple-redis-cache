=== Simple Redis Cache ===
Contributors: vitaliikaplia
Tags: redis, object cache, page cache, performance
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused Redis-only object cache and full-page HTML cache for WordPress.

== Description ==

Simple Redis Cache supports single-site WordPress installations only; activation is refused on multisite. It provides two independent cache layers backed by one standalone Redis server through the PhpRedis extension:

* A persistent WordPress object cache via `wp-content/object-cache.php`.
* An early full-page HTML cache via `wp-content/advanced-cache.php`.

There is no file-based cache, CDN integration, minification, or scheduled/background preload. The one CDN-related feature is an optional Cache-Control lifetime added to page-cache hits; the plugin never calls a CDN API and never purges one. Independent targeted invalidation settings can expire the updated post, page, or public custom post type, its WPML/Polylang translations, and the public taxonomy term archives related to those posts without clearing unrelated page-cache entries.

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
7. Optionally set a CDN cache lifetime on the same tab so a shared cache or reverse proxy may store page-cache hits; leave it at zero unless you will purge that cache yourself when content changes.
8. Optionally use Cache Warming to select public content and keep the tab open until its live progress completes.
9. Use the Status tab to test the saved connection and inspect diagnostics.

For a Unix socket, select Unix socket and enter the raw absolute filesystem path, such as `/home/account/.system/redis.sock`, in the Unix socket path field. Do not add `unix://`; Host and Port are ignored for this connection type.

When HTML Page Cache is enabled, the plugin attempts to add `define( 'WP_CACHE', true );` to `wp-config.php`. If automatic enabling fails, set `WP_CACHE` to `true` manually and verify the Status tab.

Test Saved Connection performs PING plus an isolated `SET -> GET -> DELETE` round trip with a 30-second TTL. Status also reports the Redis version and `maxmemory_policy`, drop-in paths and file modes, `WP_CACHE`, generated-config ownership/synchronization/mode, and the effective key prefix without exposing Redis credentials.

Sites using plain/query-string permalinks must enable query-string page caching before URLs such as `?p=`, `?page_id=`, `?cat=`, or `?paged=` can be warmed. Unsafe query parameters still always bypass the page cache. Pagination is estimated from WordPress database counts and the global `posts_per_page`; custom query rules can produce individual failed URLs. Discovery stops after 20000 URLs and reports a warning rather than truncating silently. Counters cover every outcome, while the detailed issue list shows at most the first 100 discovery warnings, cache bypasses, and failures; any remaining issue count is shown separately. When enabled, search results and 404 pages may be cached on demand but have no finite discoverable URL list, so the warmer does not include them.

The plugin never overwrites a drop-in owned by another plugin.

To publish an update, synchronize the `Version` plugin header,
`SIMPLE_REDIS_CACHE_VERSION`, `Stable tag`, `readme.txt` changelog, the current
version's changelog text in the standard WordPress plugin-details modal, and the
bundled POT/PO/MO files, then commit and push that coherent tree to the
repository's `master` branch. A GitHub tag or GitHub Release is not required.
The branch package is mutable and has no independent checksum or cryptographic
signature; transport relies on GitHub HTTPS and the integrity of the public
repository.

Deactivation removes the generated early configuration and both plugin-owned
drop-ins while retaining admin settings for a later reactivation. Deleting the
plugin also removes settings, notices, updater metadata, generated config, and
plugin-owned drop-ins. Its updater site transient is deleted, but remaining
database transients are not bulk-deleted. Redis payload/meta, `WP_CACHE`, and
`WP_CACHE_KEY_SALT` remain. `WP_CACHE` is intentionally left alone
because another WordPress cache may use it.

== Safety ==

* Redis failures are fail-open and must not take the site offline.
* Saving cache-semantic settings automatically bumps only the affected active Redis object/page generation. A Redis datastore, credentials, or generated site-identity change invalidates active layers on both old and new targets; timeout, retry, persistence, debug-header, and CDN cache-lifetime changes do not purge data.
* Redis object-generation invalidation does not silently delete database-mirror transients, which may rewarm Redis. The plugin warns about retained rows; use the separately confirmed Clear Object Cache action when those database values must also be removed.
* Changing `WP_CACHE_KEY_SALT` outside the admin immediately selects another namespace, but the plugin cannot recover the previous constant value. Clear the required layers while the old salt is still active, or do not restore it until the old payload TTLs have elapsed.
* If invalidation cannot reach Redis, new policy is not applied over the old generation: the latest early runtime is re-read and both managed cache layers and owned drop-ins are disabled fail-open. This also prevents a concurrent settings save from being partially rolled back. The plugin retries on the next authenticated `manage_options` administrator request, revalidates the latest saved config, and restores the required layers after invalidation succeeds.
* Redis payload purge uses versioned namespaces and never calls `FLUSHDB` or `FLUSHALL`. Clear Object Cache and Clear All require explicit confirmation and also physically delete every standard WordPress transient row from the current site's `wp_options`, including transients created by other plugins. Database rows are deleted only after object-generation invalidation succeeds; Clear Page Cache does not touch them.
* Comment-moderation links carrying `unapproved` and `moderation-hash` always bypass the page cache, so a pending comment is never stored and replayed. Unsafe parameter names are matched against the name PHP produces, so dot, space and unterminated-bracket variants such as `rest.route` or `rest[route` cannot evade the list. Excluded paths are matched case-insensitively.
* WordPress auth cookies and the bundled WordPress, WooCommerce, EDD, and PHP session-cookie patterns bypass page cache by default. Custom personalization cookies must be excluded or safely varied explicitly.
* Cookie exclusions are name-based in the early drop-in. A stale or duplicate auth/session cookie can continue to bypass HTML cache after logout until the browser removes it.
* Cache warming is manual and anonymous. The open tab performs same-origin browser requests with a 30-second per-request timeout and uses a protected server-side loopback fallback for a different origin, timeout, or network failure; no cron runs and existing entries are not cleared first. Only the anonymous no-cookie variant is warmed.
* Targeted post-update invalidation is disabled by default. When enabled, the core `post_updated` hook invalidates every cached HTML variant of that frontend-viewable post/page/CPT and all translations discovered through the documented WPML or Polylang APIs. Stale payloads are removed on their next read or expire by TTL.
* Related taxonomy-archive invalidation is a separate disabled-by-default option. It invalidates all cached variants and pagination of assigned terms in frontend-viewable standard and custom taxonomies. Both old and new relationships are covered when terms change, hierarchical ancestors are included, and current terms of translated WPML/Polylang posts are collected.
* Unrelated term archives, generic post-type/author/date archives, search, 404, menu, comment, arbitrary post-meta-only, and WooCommerce-derived pages are intentionally not invalidated. A static front page or posts page is still invalidated when singular-page invalidation is enabled and that page itself is the updated post. Assigning terms through the WordPress API also invalidates the post's singular cache, but only when related taxonomy-archive invalidation is enabled as well: both hooks are always registered, and that option is what stops the term-relationship handler from returning immediately. Use manual Clear Page Cache when broader related views must also be refreshed.
* Only post updates and term assignments are observed, and only for taxonomies actually registered for that post type. Permanently deleting a post, renaming, merging, or deleting a term itself, removing a relationship through `wp_remove_object_terms()`, and scheduled publication through `wp_publish_post()` do not fire the observed hooks, so the affected HTML stays cached until TTL or a manual page purge.
* A queued invalidation warning reaches an administrator even when the change came from an editor, the front end, a webhook, or cron: those notices go to a shared queue that the next administrator screen displays.
* A Redis failure during post-update invalidation does not fail the WordPress save. The request remains fail-open, an administrator warning is queued, and the previous HTML may remain until TTL or manual page purge.
* The optional CDN cache lifetime only adds `Cache-Control: public, max-age=0, s-maxage=N` to a page-cache hit. The plugin never contacts a shared cache, so neither targeted post-update invalidation nor Clear Page Cache reaches it: an edge may keep serving the previous HTML, including a cached 404, until that lifetime expires and must be purged separately. Changing the value never invalidates already stored pages. A stored response that already carries `Cache-Control` is replayed unchanged, and a validated logged-in hit never receives the header at all, so a session-specific response is not offered to a shared cache even on a site that removes the standard WordPress no-cache headers.
* With the database transient mirror enabled, a plain `wp_cache_flush()` or `wp_cache_flush_group()` — for example WP-CLI `wp cache flush` — also deletes every standard WordPress transient row from `wp_options`, without the confirmation the admin buttons require. Those rows are retained and the call reports failure whenever a required generation bump did not succeed. `flush_group()` also invalidates the cached options group first, so a group configured as non-persistent still reports failure and keeps its rows when Redis is unreachable.
* Object-cache `expire=0` means the configured Maximum TTL, not infinite storage.
* Redis is a trusted serialization boundary and must be private and protected from untrusted writes. Missing generation metadata is recreated from a random safe integer rather than `1`, but `noeviction` or a volatile policy is still preferred for a stable hit rate.
* Redis credentials are stored in the WordPress database and generated early config. File mode `0640` is best-effort; protect database and filesystem access at the hosting layer.
* GitHub update metadata is cached for 12 hours. A failed check is cached for 1 hour, after which a later WordPress update check may retry.

== Changelog ==

= 0.5.0 =
* Stopped sending the optional CDN `Cache-Control` on validated logged-in cache hits, so a session-specific response is never offered to a shared cache even when the site removes the standard WordPress no-cache headers.
* Stopped storing a discarded output buffer as a cached page, so a response replaced late by another plugin is no longer served to later visitors.
* Kept the stampede lock alive while a capture buffer is still open, so a page is still stored on sites that remove core's `wp_ob_end_flush_all()` shutdown callback.
* Excluded comment-moderation links (`unapproved`, `moderation-hash`) from the page cache, so a pending comment can no longer be stored and replayed for the whole page TTL. Unsafe parameter names are compared using the name PHP will actually put in `$_GET`: it drops leading spaces and rewrites every space, dot and unterminated `[` to an underscore, so `?rest.route=`, `?rest[route=` and `?.wpnonce=` arrive as `rest_route`, `rest_route` and `_wpnonce`. Matching the raw name let most of the unsafe list through.
* Sent `Cache-Control: private, no-store` on a logged-in cache hit whose stored response carries no cache policy of its own.
* Invalidated the cached options group before deleting mirrored transient rows, and aborted the delete if that invalidation failed, so a stale Redis copy can no longer resurrect the deleted rows.
* Made excluded paths match case-insensitively, so a pattern such as `/My-Account/*` can no longer silently fail to exclude `/my-account/`.
* Stopped deleting database transient mirrors when the Redis generation bump failed, matching the existing Clear Object Cache behaviour instead of performing a partial purge.
* Reported an unserializable value as a failed write instead of a Redis outage, and stopped passing such a value to `update_option()`, which could end the request with a fatal error.
* Made `replace()` consult and update the database mirror for a mirrored group configured as non-persistent.
* Restricted the term-relationship hook to taxonomies actually registered for the updated post type, and skipped translation IDs that are not frontend-viewable.
* Routed invalidation warnings raised by an editor, cron, or a webhook to a shared queue that administrators actually see.
* Made `error()` describe the call that just ran instead of every call ever made on that connection.
* Stopped materializing a missing plugin-update transient into an empty object.
* Deduplicated warming URLs that differ only by trailing slash, host casing, or default port, bounded discovery, and gave URL discovery its own timeout.
* Reduced the settings screen from one uncached option query per field to a single read per request.

= 0.4.0 =
* Added an optional CDN cache lifetime for page-cache hits, sent as `Cache-Control: public, max-age=0, s-maxage=N`, so a shared cache or reverse proxy can store the response while browsers keep revalidating. Disabled by default; an existing `Cache-Control` on the stored response is never overwritten.

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
