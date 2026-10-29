=== Simple Redis Cache ===
Contributors: vitaliikaplia
Tags: redis, object cache, page cache, performance
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused Redis-only object cache and full-page HTML cache for WordPress.

== Description ==

Simple Redis Cache supports single-site WordPress installations only; activation is refused on multisite. It provides two independent cache layers backed by one standalone Redis server through the PhpRedis extension:

* A persistent WordPress object cache via `wp-content/object-cache.php`.
* An early full-page HTML cache via `wp-content/advanced-cache.php`.

There is no file-based cache, minification, or scheduled/background preload. CDN support is limited to two things: an optional Cache-Control lifetime added to page-cache hits, and optional Cloudflare cache purging through a scoped API token. No other CDN provider is supported, and the Cloudflare client only reads the configured zone and purges it. Independent targeted invalidation settings can expire the updated post, page, or public custom post type, its WPML/Polylang translations, the public taxonomy term archives related to those posts, and the listing pages — home, posts page, generic archives, search and 404 — without clearing unrelated page-cache entries. Site-wide changes such as menus, widgets, the theme or updates can clear the whole page cache, and `wp simple-redis-cache purge [all|object|page]` clears a layer from the command line.

Connection settings are configured in the WordPress admin. A non-empty `WP_CACHE_KEY_SALT`, when defined in `wp-config.php`, is preserved byte-for-byte as the base prefix for every Redis key created by this plugin. A missing or empty value uses the generated per-site fallback prefix.

The settings screen is divided into Redis Connection, Object Cache, HTML Page Cache, Cloudflare Cache, Cache Warming, and Status tabs. Manual cache warming discovers enabled WordPress system pages, frontend-viewable public post types, and non-empty terms of frontend-viewable public taxonomies. It respects the current Home, Singular, and Archive cache settings and verifies every anonymous URL through a Redis cache HIT while reporting live progress in the open browser tab. Attachment pages are included only when WordPress enables them. The administration interface is available in English and Ukrainian and follows the current WordPress admin language.

Updates are discovered from the `Version` header in `simple-redis-cache.php` on the public repository's `master` branch and installed through the standard WordPress plugin updater. Each check first resolves `master` to one commit through the GitHub API and reads both the version and the package from that commit. WordPress native automatic updates are supported when enabled for this plugin.

== Installation ==

1. Install and enable the PhpRedis PHP extension.
2. Activate Simple Redis Cache.
3. Open Settings > Redis Cache.
4. Enter the Redis connection settings on the Redis Connection tab.
5. Enable Object Cache and/or HTML Page Cache on their respective tabs.
6. Review the invalidation options on the HTML Page Cache tab: listing-page invalidation and clearing the page cache on site changes are on by default; singular-page and related taxonomy-archive invalidation are optional.
7. Optionally set a CDN cache lifetime on the same tab so a shared cache or reverse proxy may store page-cache hits; leave it at zero unless you will purge that cache yourself when content changes.
8. Optionally enter a Cloudflare zone ID and API token on the Cloudflare Cache tab, pick the automatic purge triggers, and use Test Cloudflare connection.
9. Optionally use Cache Warming to select public content and keep the tab open until its live progress completes.
10. Use the Status tab to test the saved connection and inspect diagnostics.

For a Unix socket, select Unix socket and enter the raw absolute filesystem path, such as `/home/account/.system/redis.sock`, in the Unix socket path field. Do not add `unix://`; Host and Port are ignored for this connection type.

When HTML Page Cache is enabled, the plugin attempts to add `define( 'WP_CACHE', true );` to `wp-config.php`. If automatic enabling fails, set `WP_CACHE` to `true` manually and verify the Status tab.

Test Saved Connection performs PING plus an isolated `SET -> GET -> DELETE` round trip with a 30-second TTL. Status also reports the Redis version and `maxmemory_policy`, drop-in paths and file modes, `WP_CACHE`, generated-config ownership/synchronization/mode, and the effective key prefix without exposing Redis credentials.

Sites using plain/query-string permalinks must enable query-string page caching before URLs such as `?p=`, `?page_id=`, `?cat=`, or `?paged=` can be warmed. Unsafe query parameters still always bypass the page cache. Pagination is estimated from WordPress database counts and the global `posts_per_page`; custom query rules can produce individual failed URLs. Discovery stops after 20000 URLs and reports a warning rather than truncating silently. Counters cover every outcome, while the detailed issue list shows at most the first 100 discovery warnings, cache bypasses, and failures; any remaining issue count is shown separately. When enabled, search results and 404 pages may be cached on demand but have no finite discoverable URL list, so the warmer does not include them.

If you also cache HTML at the Cloudflare edge, add a cookie condition to that cache rule. The Cloudflare Cache tab shows the exact expression for your site, generated locally — with no Cloudflare API call — from its host, its admin, login, REST and cron paths, and the Excluded request cookies on the HTML Page Cache tab; copy it from there. Each path is excluded as a whole segment, auth and post-password cookies are always included, paths covering the home URL are never added, vague cookie patterns are listed rather than dropped, and the length is checked against Cloudflare's 4000-character limit. Cloudflare's cache key is method + host + URL and does not include cookies, so a page stored from an anonymous request is served to logged-in users too — the request never reaches WordPress, and no origin setting, including Cache logged-in users, can change that. Keep the exclusion inside the existing rule rather than adding a separate bypass rule, because several cache rules can match one request.

Leave Edge TTL on "use cache-control header if present, bypass cache if not". That lets the plugin decide what the edge may store: it sends `s-maxage` only on an anonymous hit, never on a miss, a bypass, or a logged-in request. Purge the zone once after saving the rule so the previously stored anonymous copy goes away.

Cloudflare purging needs a scoped API token with the Zone / Cache Purge permission for that zone; the account-wide Global API Key is not used. Test Cloudflare connection tries to read the zone, to report its name and status, and then performs a real single-URL purge of the home page. The zone read needs its own Zone Read permission, so a token scoped to Cache Purge alone simply reports no zone name; only the purge decides whether the test passes. Clear Cloudflare cache purges the whole zone and leaves Redis untouched.

The plugin never overwrites a drop-in owned by another plugin.

To publish an update, synchronize the `Version` plugin header,
`SIMPLE_REDIS_CACHE_VERSION`, `Stable tag`, `readme.txt` changelog, the current
version's changelog text in the standard WordPress plugin-details modal, and the
bundled POT/PO/MO files, then commit and push that coherent tree to the
repository's `master` branch. A GitHub tag or GitHub Release is not required.
The offered package is pinned to the commit whose version was read, but it has
no independent checksum or cryptographic signature; transport relies on GitHub
HTTPS and the integrity of the public repository.

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
* Comment-moderation links carrying `unapproved` and `moderation-hash` always bypass the page cache, so a pending comment is never stored and replayed. Unsafe parameter names are matched against the name PHP produces, so dot, space and unterminated-bracket variants such as `rest.route` or `rest[route` cannot evade the list. Excluded paths are matched case-insensitively. Upper- and lower-case percent-encoding and raw UTF-8 spellings of one path share a cache entry. A path that differs from its lower-case form, or that percent-encodes a letter, digit or `-._~` (which WordPress treats as a different, usually missing, slug), may read the cache but is never stored, so such variants can neither multiply entries nor overwrite the real page. A request carrying ignored parameters such as `utm_*` reads the canonical page but never stores its own rendering.
* WordPress auth cookies and the bundled WordPress, WooCommerce, EDD, and PHP session-cookie patterns bypass page cache by default. Custom personalization cookies must be excluded or safely varied explicitly.
* Cookie exclusions are name-based in the early drop-in. A stale or duplicate auth/session cookie can continue to bypass HTML cache after logout until the browser removes it.
* Cache warming is manual and anonymous. The open tab performs same-origin browser requests with a 30-second per-request timeout and uses a protected server-side loopback fallback for a different origin, timeout, or network failure; no cron runs and existing entries are not cleared first. Only the anonymous no-cookie variant is warmed.
* Targeted post-update invalidation is disabled by default. When enabled, `post_updated`, `transition_post_status` (which also covers scheduled publishing) and `before_delete_post` invalidate every cached HTML variant of that frontend-viewable post/page/CPT and all translations discovered through the documented WPML or Polylang APIs. Stale payloads are removed on their next read or expire by TTL.
* Listing-page invalidation is enabled by default. Whenever public content is published, changed, unpublished or deleted, one shared token invalidates the front page, the posts page, post type, author and date archives, search results and cached 404 pages with all their pagination; taxonomy archives use their own term token when that option is enabled.
* Related taxonomy-archive invalidation is a separate disabled-by-default option. It invalidates all cached variants and pagination of assigned terms in frontend-viewable standard and custom taxonomies. Both old and new relationships are covered when terms change, hierarchical ancestors are included, and current terms of translated WPML/Polylang posts are collected.
* Unrelated term archives, comment, arbitrary post-meta-only, and WooCommerce-derived pages are intentionally not invalidated, and a singular page that lists other posts in a sidebar keeps that list until TTL. A static front page or posts page is also invalidated when singular-page invalidation is enabled and that page itself is the updated post. Assigning terms through the WordPress API also invalidates the post's singular cache, but only when related taxonomy-archive invalidation is enabled as well: both hooks are always registered, and that option is what stops the term-relationship handler from returning immediately. Use manual Clear Page Cache when broader related views must also be refreshed.
* Term relationships are observed only for taxonomies actually registered for that post type. Renaming or deleting a term invalidates its archive, its parent and the listing pages. Removing a relationship through `wp_remove_object_terms()` alone does not fire an observed hook, so that HTML stays cached until TTL or a manual page purge.
* Clearing the page cache on site changes is enabled by default: saving or deleting menus and menu items, Site Editor navigation, templates, template parts, global styles or synced patterns, widgets, Customizer settings, core site settings such as the title or permalinks, an ACF options page, switching the theme, activating or deactivating a plugin, or a plugin, theme, core or translation update (not a new install) clears the whole HTML page cache once at the end of the request, together with the whole Cloudflare zone when Clear page cache is set to purge it. Deploys that change files without WordPress can run `wp simple-redis-cache purge page`; it exits with an error when the local cache was cleared but the Cloudflare purge failed.
* Cached 404 pages and search results live for at most 10 minutes in Redis and at a CDN. Page bodies are stored compressed and a page larger than 2 MB is not stored. A vary-cookie value must look like a language code and, when Polylang, WPML or WP-LOC is active, all of a request's vary-cookie values must name one and the same of its languages, so a mix no real visitor carries is never stored. The `simple_redis_cache_vary_cookie_values` filter receives and returns the list of languages, each an array of the spellings a cookie may hold, for other plugins. Languages are read before the page renders, never inside the output buffer. Each URL holds at most 16 vary-cookie variants per page generation.
* A queued invalidation warning reaches an administrator even when the change came from an editor, the front end, a webhook, or cron: those notices go to a shared queue that the next administrator screen displays.
* A Redis failure during post-update invalidation does not fail the WordPress save. The request remains fail-open, an administrator warning is queued, and the previous HTML may remain until TTL or manual page purge.
* The optional CDN cache lifetime only adds `Cache-Control: public, max-age=0, s-maxage=N` to a page-cache hit. Without a configured Cloudflare trigger the plugin does not contact the shared cache at all, so an edge may keep serving the previous HTML, including a cached 404, until that lifetime expires and must be purged separately. With Cloudflare configured, the matching trigger clears it. Changing the value never invalidates already stored pages. A stored response that already carries `Cache-Control` is replayed unchanged, a page varied by a vary cookie never receives the header, because a CDN keys by URL alone, and a validated logged-in hit never receives it either, so a session-specific response is not offered to a shared cache even on a site that removes the standard WordPress no-cache headers.
* With the database transient mirror enabled, a plain `wp_cache_flush()` or `wp_cache_flush_group()` — for example WP-CLI `wp cache flush` — also deletes every standard WordPress transient row from `wp_options`, without the confirmation the admin buttons require. Those rows are retained and the call reports failure whenever a required generation bump did not succeed. `flush_group()` also invalidates the cached options group first, so a group configured as non-persistent still reports failure and keeps its rows when Redis is unreachable.
* Cloudflare purging is opt-in per trigger. Clear all cache and Clear page cache purge the whole zone; the content-update trigger purges only the exact URLs this plugin already invalidates, so it depends on the HTML Page Cache invalidation options being enabled. Cloudflare purges exact URLs, so deeper archive pagination and generic views are not covered — use a full purge for those. At most 300 URLs are sent per flush, in batches of 30, and any remainder is reported to the administrator so a manual zone purge can finish the job. The purge only runs once the local invalidation succeeded, and the address is captured before the post changes, so unpublishing, trashing or renaming clears the address the CDN actually holds.
* A Cloudflare failure never blocks the local purge or the WordPress save: the Redis side still completes and an administrator warning is queued.
* Caching HTML at a CDN is a separate decision from caching it in Redis, and the plugin cannot police it. A shared cache that keys on the URL alone serves a stored anonymous page to logged-in visitors, because that request never reaches WordPress. Exclude the WordPress auth cookie in the CDN's own rule; Cache logged-in users only governs Redis.
* The Cloudflare API token is stored in the WordPress database and the generated early configuration, exactly like the Redis credentials. Use a token scoped to one zone and the Cache Purge permission so a leak cannot do more than clear that cache.
* A change the object cache could not write to Redis during an outage leaves a marker file, `wp-content/simple-redis-cache-object-dirty`; the first request that reaches Redis again invalidates the whole object namespace once, so a cold object cache follows such an outage. While one request performs that invalidation, others that connect meanwhile wait up to 50 ms for it and otherwise run without Redis for that request. A marker PHP cannot remove — for example one left by WP-CLI running as another user — still invalidates the namespace exactly once, recorded in Redis, instead of keeping the object cache off. The Status tab shows any marker, a running invalidation, an open breaker, and a `wp-content` PHP cannot write. After a connection failure or a timeout — not an error reply such as out-of-memory — the drop-ins skip Redis for 15 seconds, tracked by `wp-content/simple-redis-cache-breaker`, and one request then probes it; WP-CLI ignores the breaker. These files need a `wp-content` writable by PHP and are removed on uninstall.
* Object-cache `expire=0` means the configured Maximum TTL, not infinite storage.
* Stored HTML pages are signed with a per-site secret kept in the generated early configuration and bound to their cache key, so another writer on a shared Redis cannot plant or move a page. Object-cache values are not signed: Redis is a trusted serialization boundary for them and must be private and protected from untrusted writes. Missing generation metadata is recreated from a random safe integer rather than `1`, but `noeviction` or a volatile policy is still preferred for a stable hit rate.
* Redis credentials are stored in the WordPress database and generated early config. File mode `0640` is best-effort; protect database and filesystem access at the hosting layer.
* GitHub update metadata is cached for 12 hours. A failed check is cached for 1 hour, after which a later WordPress update check may retry. Resolving the commit uses the unauthenticated GitHub API, limited to 60 requests per hour per IP address; when that fails, no update is offered until a later check succeeds.
* A copied or moved site recognizes a generated configuration or saved settings written by another installation (the real path of `wp-content`, the database name and the table prefix — the same for the web and WP-CLI), keeps both drop-ins inert and leaves the original's Redis namespace untouched, then derives its own key prefix and page secret on the next administrator request. The copied Cloudflare zone ID and token are removed, so the copy cannot purge the original's zone. With `WP_CACHE_KEY_SALT` set the prefix stays shared, so both cache layers are turned off on the copy until an administrator changes the salt or confirms by enabling them again. Moving `wp-content` to another directory counts as a move, so caching resumes only after the next administrator visit.

== Changelog ==

= 0.8.0 =
* Object cache: a change that could not reach Redis — because Redis was down, timed out or ran out of memory mid-request — is no longer lost silently. The request leaves a marker outside Redis, and the first request that reaches Redis again invalidates the whole object namespace, so a value deleted or replaced during the outage can no longer come back for up to the maximum TTL.
* Object and page cache: after a Redis connection failure or timeout, the drop-ins skip Redis for 15 seconds instead of making every request wait for its own timeout, and then let a single request probe it. An error reply such as out-of-memory or a read-only replica affects only that request. Administrator actions, the connection test and WP-CLI always try Redis directly.
* Object cache: with "Read object cache in wp-admin" disabled, values WordPress adds while Redis already holds them are kept for the rest of the request, so wp-admin no longer re-reads every option from the database. The plugin's own settings, which hold the Redis password and the Cloudflare token, are never written to Redis.
* HTML page cache: home, posts page, post type, author and date archives, search results and cached 404 pages are now invalidated when public content is published, changed, unpublished, published on schedule or deleted (new option, on by default). Scheduled publishing, permanent deletion and renamed or deleted terms now also invalidate the post and its term archives. With Cloudflare purging on, the home page of each language, the posts page and the post type archive are purged too.
* HTML page cache: menus, widgets, Customizer settings, the active theme, core site settings, ACF options pages and plugin, theme or core updates now clear the whole page cache (new option, on by default). `wp simple-redis-cache purge [all|object|page]` clears a layer from the command line, for deploys that bypass WordPress.
* HTML page cache: cached 404 pages and search results live for at most 10 minutes, in Redis and at a CDN, so a URL that becomes a real post is no longer stuck as a 404 and random 404 or search URLs cannot fill Redis for weeks. Page bodies are stored compressed, and a page larger than 2 MB is not stored.
* HTML page cache: a request with ignored parameters such as `utm_*` or `gclid` still gets the cached page but never stores its own rendering, which echoed those parameters into pagination and language links. Paths that differ only in letter case or percent-encoding no longer create separate cache entries, `?S=` is no longer treated as a search, and a request's vary-cookie values must together name one of the site's languages when Polylang, WPML or WP-LOC is active, with at most 16 vary-cookie variants per URL.
* HTML page cache: a page varied by a cookie no longer gets the CDN lifetime, and the Cloudflare Cache Rule now also keeps requests with vary cookies away from the edge copy, which could otherwise serve one language to everyone.
* HTML page cache: stored pages are signed with a per-site key, so another site sharing the same Redis cannot plant HTML for this one, and a valid page cannot be replayed under a different URL. Existing cached pages are rebuilt once after the update.
* HTML page cache: a page whose render outlasted the 10-second stampede lock is now stored instead of thrown away, so a slow site under load no longer keeps its cache permanently cold.
* A copied or moved site (staging, a restored backup) no longer shares the original's cache: it recognizes that its generated configuration or settings belong to another installation, keeps both drop-ins inert, and gets its own cache namespace on the next administrator request without touching the original's. The copied Cloudflare credentials are removed, and if `WP_CACHE_KEY_SALT` is shared both layers stay off until it is changed.
* Updates: the version and the package are now read from one and the same commit of the `master` branch, resolved through the GitHub API, so the downloaded package always matches the version that was offered. If the commit cannot be resolved, no update is offered for an hour.
* The configuration schema moves to version 4. The first request after the update migrates the settings and invalidates both cache layers once.

= 0.7.1 =
* The plugin now works on PHP 8.1, its declared minimum. Two return types were declared as `true|WP_Error`. PHP 8.1 has no `true` type in any position; only PHP 8.2 and newer accept it, so every version since 0.2.0 failed with a fatal error on PHP 8.1 as soon as WordPress loaded it and could not be activated there. Both are now declared as `bool|WP_Error`; behaviour on PHP 8.2 and newer is unchanged.
* The early page-cache loader no longer overwrites and removes a global `$config` variable. WordPress includes `advanced-cache.php` at global scope after `wp-config.php` has run, so a site that defined its own `$config` there lost it on every request while HTML page cache was enabled. All of the loader's variables are now prefixed, as the object-cache loader's already were.
* A new dependency-light test, `tests/php-compat.php`, scans the plugin's own source for the PHP 8.2/8.3 type and constant syntax of the kind that broke 0.2.0–0.7.0 and for a curated list of newer builtins, and checks that the plugin header, this readme and the update responses all still declare PHP 8.1.

= 0.7.0 =
* The Cloudflare Cache tab now shows the Cache Rule expression this site needs when Cloudflare caches HTML, ready to paste. It is generated from the site's own host, its admin, login, REST and cron paths as WordPress reports them, and the Excluded request cookies from the HTML Page Cache tab, so the edge refuses the same visitors the origin already refuses, apart from cookie patterns too vague to express, which the tab lists. Logged-in and post-password cookies are always included.
* Each service path is excluded as a whole segment, so a short custom login slug such as `/go` cannot pull `/golf/` out of the edge cache. Paths that would cover the home URL itself are never added, so plain permalinks on a root or subdirectory install cannot switch the rule off for the whole site. Cookie patterns too vague for a free-plan rule are listed instead of silently dropped, the expression length is checked against Cloudflare's limit, and a notice explains when HTML page cache is disabled or the CDN cache lifetime is 0, since Cloudflare then stores no HTML under the recommended Edge TTL.

= 0.6.0 =
* Added a Cloudflare Cache tab with a zone ID, a scoped API token, a manual Clear Cloudflare cache button, and a connection test that proves the token really holds the Cache Purge permission by performing a real single-URL purge.
* Added three independent automatic purge triggers: with Clear all cache, with Clear page cache, and when content is updated. The content trigger purges the exact URLs this plugin already invalidates and therefore follows the HTML Page Cache invalidation options.
* The purge address is captured before the post changes, so unpublishing, trashing or renaming clears the address the CDN is actually holding rather than the plain `?p=ID` form WordPress falls back to afterwards.
* Cloudflare is only purged after the local invalidation succeeded, so a failed Redis bump cannot make the edge refetch and re-hold the same stale page.
* Cloudflare failures are fail-open: the local purge and the WordPress save still complete and an administrator warning is queued, without turning a successful local purge into an error.

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
