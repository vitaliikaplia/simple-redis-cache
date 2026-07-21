# AGENTS.md — технічний довідник Simple Redis Cache

Цей файл описує поточну архітектуру плагіна та правила безпечного внесення змін. Він застосовується до всього каталогу плагіна. Джерелом істини завжди є код; якщо поведінку змінено, цей файл, `README.md`, `readme.txt` і changelog потрібно синхронізувати.

## 1. Контракт продукту

Simple Redis Cache — навмисно вузький WordPress-плагін із двома незалежними Redis-шарами:

1. persistent WordPress Object Cache API через `wp-content/object-cache.php`;
2. full-page HTML cache через `wp-content/advanced-cache.php`.

Незмінні межі поточного продукту, якщо задача прямо не вимагає змінити scope:

- тільки single-site WordPress;
- WordPress 6.5+ і PHP 8.1+;
- тільки розширення PhpRedis;
- один standalone Redis server;
- Redis TCP, TLS або Unix socket;
- усі Redis connection settings задаються в адмінці;
- єдина користувацька константа для namespace кешу — `WP_CACHE_KEY_SALT`;
- `WP_CACHE` використовується лише як стандартний прапорець WordPress для `advanced-cache.php`;
- жодного filesystem cache, Predis, Redis Cluster, Sentinel, replica routing або CDN;
- жодної мініфікації, preload, cron warmup чи автоматичної інвалідації за подіями контенту;
- очищення тільки вручну;
- ніколи не застосовувати `FLUSHDB`, `FLUSHALL` або Redis `KEYS`;
- будь-який Redis/cache failure має бути fail-open для звичайного HTTP-запиту.

Плагін не має Composer/autoloader, JavaScript/CSS build, REST API, AJAX API, WP-CLI команд або власних cron-задач.

## 2. Карта файлів

| Файл | Відповідальність |
| --- | --- |
| `simple-redis-cache.php` | Metadata, глобальні константи, `require_once`, activation/deactivation hooks, запуск на `plugins_loaded`. |
| `includes/class-simple-redis-cache-plugin.php` | Оркестрація lifecycle, single-site guard, синхронізація конфігурації та drop-in. |
| `includes/class-simple-redis-cache-early-config.php` | Dependency-free defaults, читання раннього config, побудова Redis prefix/meta keys. |
| `includes/class-simple-redis-cache-config.php` | Читання option напряму з БД, sanitization, атомарний generated config. |
| `includes/class-simple-redis-cache-redis.php` | Мінімальний PhpRedis connection wrapper і generation counters. |
| `includes/class-simple-redis-cache-dropins.php` | Встановлення, видалення, ownership-check drop-in та безпечне ввімкнення `WP_CACHE`. |
| `includes/class-simple-redis-cache-admin.php` | Settings API, діагностика, connection test, notices, admin-bar purge. |
| `includes/class-simple-redis-cache-purger.php` | Ручна інвалідація object/page namespaces та очищення DB transients. |
| `includes/class-simple-redis-cache-page-capture.php` | Пізня валідація WordPress-відповіді, output capture і атомарний запис HTML. |
| `includes/class-simple-redis-cache-github-updater.php` | Інтеграція GitHub-гілки зі стандартним WordPress updater. |
| `dropins/object-cache.php` | Мінімальний шаблон, що копіюється в `wp-content/object-cache.php`. |
| `dropins/object-cache-loader.php` | Ранній bootstrap і процедурний WordPress Object Cache API. |
| `dropins/class-simple-redis-cache-object-cache.php` | Реалізація глобального `WP_Object_Cache`. |
| `dropins/advanced-cache.php` | Мінімальний шаблон, що копіюється в `wp-content/advanced-cache.php`. |
| `dropins/advanced-cache-loader.php` | Ранній HIT/MISS runtime, logged-in validation і stampede lock. |
| `dropins/class-simple-redis-cache-page-request.php` | Dependency-free request validation та page-key canonicalization. |
| `uninstall.php` | Видалення plugin-owned файлів і WordPress state. |
| `readme.txt` | WordPress-format metadata/changelog. |
| `README.md` | Коротка документація для користувача. |

Drop-in шаблони мають залишатися маленькими. Під час встановлення placeholder замінюється абсолютним шляхом до loader усередині поточної директорії плагіна. Основна логіка залишається в plugin directory, тому звичайне оновлення коду змінює runtime без копіювання великих файлів у `wp-content`.

## 3. Модель завантаження

Є три різні фази, які не можна змішувати:

```text
дуже рання фаза: wp-config.php → advanced-cache.php → page HIT або normal bootstrap
рання фаза:      object-cache.php → WP_Object_Cache до звичайних plugin hooks
звичайна фаза:   active plugin → plugins_loaded → admin/updater/synchronization
```

### 3.1. Звичайний plugin bootstrap

`simple-redis-cache.php` визначає:

- `SIMPLE_REDIS_CACHE_VERSION`;
- `SIMPLE_REDIS_CACHE_FILE`;
- `SIMPLE_REDIS_CACHE_DIR`;
- `SIMPLE_REDIS_CACHE_BASENAME`.

Після прямих `require_once` він реєструє:

- activation → `Simple_Redis_Cache_Plugin::activate()`;
- deactivation → `Simple_Redis_Cache_Plugin::deactivate()`;
- `plugins_loaded` → `Simple_Redis_Cache_Plugin::init()`.

`Plugin::init()` завантажує text domain, відмовляється запускати підсистеми на multisite, створює GitHub updater, ініціалізує admin hooks і слухає `update_option_simple_redis_cache_settings`.

### 3.2. Generated early config

Обидва drop-in не можуть покладатися на нормальний bootstrap плагіна або Settings API. Тому `Config::write_early_config()` створює:

```text
wp-content/simple-redis-cache-config.php
```

Файл повертає повний sanitized масив, включно з Redis password. Запис виконується як temp file + `LOCK_EX` + atomic `rename()`, права — best-effort `0640`, після заміни інвалідується OPcache. Маркер ownership: `Generated by Simple Redis Cache`.

Якщо target існує без маркера, його не перезаписують і не видаляють. Якщо early config не вдалося оновити, `Plugin::synchronize()` прибирає обидва власні drop-in: ранньому runtime не дозволено працювати зі старими connection/privacy settings.

`Early_Config::load()` перехоплює будь-який `Throwable`, зливає файл із defaults і кешує результат у static property до `reset()`.

## 4. Persistent state та побічні файли

| State | Значення/призначення |
| --- | --- |
| Option `simple_redis_cache_settings` | Усі налаштування; `autoload=false`. |
| User meta `_simple_redis_cache_notices` | Черга notices конкретного адміністратора. |
| Option `_simple_redis_cache_notices` | Fallback notices без user ID; `autoload=false`. |
| Site transient `simple_redis_cache_github_update_data` | Кеш GitHub version check. |
| `wp-content/simple-redis-cache-config.php` | Ранній sanitized config із credentials. |
| `wp-content/object-cache.php` | Керований object-cache drop-in. |
| `wp-content/advanced-cache.php` | Керований page-cache drop-in. |
| `wp-config.php` | Може бути змінений лише для стандартного `WP_CACHE=true`. |

`Config::get()` читає головний option прямим SQL із `$wpdb->options`, оминаючи object cache. Це необхідно для ремонту connection settings після Redis outage або застарілого кешу. Notices також читаються напряму з БД; зберігається максимум 10 повідомлень.

Credentials дублюються у WordPress DB та generated PHP config. Не логувати їх, не показувати в diagnostics і не додавати до Redis keys, HTTP headers чи GitHub User-Agent.

## 5. Повна схема конфігурації

Кореневий `config_version` зараз дорівнює `1`. Автоматичної migration-системи немає.

### 5.1. Redis

| Key | Default | Sanitization/сенс |
| --- | --- | --- |
| `redis.scheme` | `tcp` | Тільки `tcp`, `tls`, `unix`. |
| `redis.host` | `127.0.0.1` | Ігнорується для Unix socket. |
| `redis.port` | `6379` | `1..65535`. |
| `redis.path` | порожньо | Unix socket path. |
| `redis.database` | `0` | `0..255`. |
| `redis.username` | порожньо | ACL username. |
| `redis.password` | порожньо | ACL/classic password. |
| `redis.timeout` | `1.0` | Connect timeout `0.1..30` секунд. |
| `redis.read_timeout` | `1.0` | Read timeout `0.1..30` секунд. |
| `redis.retry_interval` | `100` | `0..5000` мс. |
| `redis.persistent` | `false` | `connect()` або `pconnect()`. |

Password input ніколи не заповнюється назад у HTML. `keep_password` checked за замовчуванням: порожнє поле з checked зберігає попередній пароль; unchecked очищає його; непорожнє поле замінює.

### 5.2. Object cache

| Key | Default | Sanitization/сенс |
| --- | --- | --- |
| `object.enabled` | `false` | Керує `object-cache.php`. |
| `object.max_ttl` | `86400` | `60..YEAR_IN_SECONDS`. |
| `object.cache_wp_admin` | `true` | Дозволяє Redis reads у нормальному `wp-admin`. |
| `object.transient_db_fallback` | `false` | DB mirror лише для `transient` і `site-transient`. |
| `object.non_persistent_groups` | `[]` | Точна назва групи на рядок; ці групи працюють лише в L1. |

### 5.3. HTML page cache

| Key | Default | Sanitization/сенс |
| --- | --- | --- |
| `page.enabled` | `false` | Керує `advanced-cache.php`; потребує `WP_CACHE=true`. |
| `page.ttl` | `3600` | `60..MONTH_IN_SECONDS`. |
| `page.cache_logged_in` | `false` | Session/access-specific logged-in variants. |
| `page.cache_home` | `true` | Front page і posts home. |
| `page.cache_singular` | `true` | Posts, pages, CPT. |
| `page.cache_archives` | `true` | WordPress archives. |
| `page.cache_search` | `false` | Search results. |
| `page.cache_404` | `false` | Тільки status 404. |
| `page.cache_query_strings` | `false` | Невідомі non-ignored query інакше дають bypass. |
| `page.ignored_query_parameters` | marketing list | Видаляються з cache key. |
| `page.excluded_paths` | cart/checkout/account | `*` і `?` patterns. |
| `page.excluded_cookies` | auth/cart/session list | Exact, wildcard або conventional `_` prefix. |
| `page.excluded_user_agents` | `[]` | Case-insensitive substring або wildcard. |
| `page.vary_cookies` | language-cookie list | Хеш cookie value входить до key. |
| `page.allowed_set_cookies` | language-cookie list | Дозволяють storage; сам `Set-Cookie` не replay-иться. |
| `page.debug_header` | `false` | `X-Simple-Redis-Cache`. |
| `page.lock_ttl` | `10` | Внутрішнє fixed sanitized value, UI не має поля. |

Default ignored query parameters:

```text
utm_*, gclid, fbclid, msclkid, yclid, ysclid, srsltid, _ga, mc_cid, mc_eid
```

Default excluded paths:

```text
/cart/*, /checkout/*, /my-account/*
```

Default excluded cookies:

```text
wordpress_logged_in_, wordpress_sec_, wp-postpass_, comment_author_,
woocommerce_items_in_cart, woocommerce_cart_hash, wp_woocommerce_session_,
edd_items_in_cart, PHPSESSID
```

Default vary/allowed response cookies:

```text
wp_loc_current_language, wp_loc_current_locale, _icl_current_language,
wp-wpml_current_language, pll_language
```

Textarea-списки проходять `sanitize_text_field`, trim, видалення порожніх рядків і duplicates.

### 5.4. Internal site metadata

`site.host`, `site.port` і `site.fallback_prefix` генеруються із `home_url('/')` та `ABSPATH`, а не беруться з input. `fallback_prefix` — перші 16 символів SHA-256 від `home_url('/') . '|' . ABSPATH`.

При зміні schema потрібно одночасно перевірити:

1. `Early_Config::defaults()`;
2. `Config::sanitize()`;
3. admin field registration/rendering;
4. generated config compatibility;
5. обидва ранні loader;
6. документацію та migration потребу.

## 6. Redis connection wrapper

`Simple_Redis_Cache_Redis` підтримує TCP, `tls://`, Unix socket, classic AUTH, ACL AUTH, SELECT database і persistent sockets. Persistent ID — SHA-256 від scheme, host/socket, port, database, username і password, щоб різні connections не ділили сокет.

Перед будь-якими операціями wrapper примусово встановлює:

- `Redis::OPT_SERIALIZER = Redis::SERIALIZER_NONE`;
- `Redis::OPT_COMPRESSION = Redis::COMPRESSION_NONE`;
- `Redis::OPT_PREFIX = ''`.

Payload кодує сам плагін, а generation values мають залишатися plain integers. Один wrapper робить лише одну connection attempt. Усі винятки ловляться; `error()` зберігає текст для diagnostics/privileged actions.

`generation($namespace)` читає `{prefix}meta:{namespace}-generation`, створюючи `1` через `SET NX`. `bump_generation()` робить `INCR`. При помилці read повертає defensive `1` разом із error; споживачі повинні перевірити error і зробити bypass. Purge повертає `false`/`WP_Error`, якщо generation не можна bump-нути.

## 7. Redis namespace і ключі

`Early_Config::prefix()` вибирає:

1. непорожній `WP_CACHE_KEY_SALT` byte-for-byte;
2. `site.fallback_prefix`;
3. перші 16 символів SHA-256 від `ABSPATH`.

Потім завжди додає `:src:`. Не обрізати й не нормалізувати salt: `site` і `site:` навмисно створюють різні prefixes.

```text
{prefix}meta:object-generation
{prefix}meta:page-generation
{prefix}meta:object-group-{sha256(group)}-generation

{prefix}object:{objectGeneration}:{groupGeneration}:{sha256(group + NUL + key)}
{prefix}page:{pageGeneration}:{sha256(canonicalRequest)}
{pageKey}:access:{sha256(user/roles/capabilities)}
{finalPageKey}:lock
```

Meta generation keys не мають TTL. Object/page payloads мають TTL. Старі generation payloads після purge залишаються фізично в Redis і звільняються природно за TTL.

Важливий Redis eviction edge case: якщо allkeys policy видалить generation key, але залишить старі payloads generation `1`, повторне створення metadata як `1` може тимчасово зробити їх доступними. Для цієї архітектури бажані `noeviction` або volatile-policy, яка не витісняє persistent meta keys. Плагін не конфігурує Redis server policy сам.

## 8. Object cache

### 8.1. Bootstrap та API

`Dropins::install('object')` атомарно встановлює template як `wp-content/object-cache.php`. Loader підключає лише early config і Redis wrapper, відступає на multisite або при `object.enabled=false`, потім оголошує `WP_Object_Cache` і процедурні функції.

Підтримано:

- add/set/get/delete і multiple variants;
- replace;
- incr/decr;
- flush, flush_runtime, flush_group;
- global/non-persistent groups;
- switch/reset/close/stats;
- WordPress 6.9+ salted helper functions.

`wp_cache_supports()` декларує `add_multiple`, `set_multiple`, `get_multiple`, `delete_multiple`, `flush_runtime`, `flush_group`.

### 8.2. Storage layers

```text
WordPress Object Cache API
    → L1: PHP array поточного request
    → L2: Redis для persistent groups
    → optional L3: wp_options mirror лише для transient/site-transient
```

L1 має власні expiry timestamps, що важливо для long-running requests. Об'єкти клонуються під час запису й читання, коли PHP дозволяє clone. Hits/misses рахуються у public properties.

Group persistent, якщо object cache увімкнено і group не входить до exact `non_persistent_groups`. Global groups підтримуються API-сумісно, але не мають окремого blog namespace, бо multisite заборонено. `switch_to_blog()` лише очищає L1.

### 8.3. TTL та payload

```text
expire <= 0            → max_ttl
expire > max_ttl       → max_ttl
1 <= expire <= max_ttl → expire
```

Тобто WordPress `expire=0` у цьому плагіні не означає infinity. Усі object values отримують обмежений TTL. Redis payload:

```text
"SRC1\0" + serialize(value)
```

Payload без marker або з невалідною serialization видаляється та стає miss.

### 8.4. Семантика методів

- `set()` пише L1, best-effort Redis і optional transient mirror; Redis outage не робить runtime write неуспішним.
- `add()` поважає `wp_suspend_cache_addition()`, L1 і DB mirror; Redis використовує atomic `SET NX EX`. NX collision повертає `false`. Якщо Redis недоступний (`null`, а не NX `false`), поточна реалізація fail-open записує L1 і повертає `true`, тому cross-request uniqueness під час outage не гарантована.
- `replace()` для Redis використовує `SET XX EX`; DB-only transient спочатку rewarm-иться через NX. Під час outage успіх можливий лише коли наявність доведена L1/DB.
- `get()` іде L1 → Redis → DB mirror. DB hit rewarm-ить Redis через NX; Redis winner має пріоритет при race. Forced read може повернути попередній L1, якщо Redis упав у цьому request.
- `delete()` прибирає L1, Redis і відповідний DB mirror.
- `incr()`/`decr()` використовують `WATCH → GET → PTTL → MULTI → SET PX → EXEC`, до п'яти retry, зберігають remaining TTL і не опускають результат нижче нуля.
- `flush()` bump-ить object generation, чистить L1 і, тільки коли DB mirror enabled, обидві transient-групи у БД.
- `flush_group()` bump-ить generation групи; для mirrored transient також видаляє DB rows та інвалідує options-group generation.

Після першої runtime Redis-помилки instance встановлює `redis_failed`; наступних Redis attempts у цьому request немає. L1 і DB mirror продовжують працювати.

### 8.5. Transient DB mirror

Mirror працює тільки для groups `transient` і `site-transient` та використовує стандартні option names:

```text
_transient_{key}
_transient_timeout_{key}
_site_transient_{key}
_site_transient_timeout_{key}
```

Запис — `update_option(..., false)`, тобто без autoload. Expired rows і orphan timeout очищуються на read. Redis miss може rewarm-итися з БД без overwrite нового concurrent value.

Якщо `cache_wp_admin=false`, звичайні admin reads не йдуть у Redis, але writes/deletes/invalidations продовжують. Mirrored transients читаються з БД; без mirror transient groups усе одно читаються з Redis, бо external object cache не дає core автоматично повернутися до `wp_options`.

## 9. HTML page cache

### 9.1. Ранній request validator

`advanced-cache.php` завантажується до БД та active plugins. Loader читає generated config, перевіряє запит, підключається до Redis і або видає HIT, або продовжує normal WordPress bootstrap.

Розглядаються тільки `GET` і `HEAD`; вони ділять key. Безумовний bypass:

- multisite;
- `DONOTCACHEPAGE`, admin, AJAX, cron, REST, XML-RPC, WP-CLI;
- Authorization/Basic Auth;
- Range, conditional headers і hard-refresh directives;
- host/port, що не збігаються з generated `home_url()` metadata;
- malformed URI/percent encoding/control characters;
- `/wp-admin`, `/wp-json`, `/wp-content`, `/wp-includes` та core service endpoints;
- configured path/cookie/User-Agent exclusions.

Scheme визначається через `HTTPS`/`SERVER_PORT`, а не forwarded headers. Reverse proxy має коректно передавати server environment. Raw path не canonicalize-иться за slash/dot/encoding, тому еквівалентні URL можуть мати різні keys.

Завжди unsafe query names перевіряються до ignore rules: nonces, preview/customizer, REST route, cart actions, cron і builder-preview parameters. Query pair order зберігається. Ignored names вилучаються з key. Якщо `cache_query_strings=false`, non-ignored query дає bypass, крім вузького search-набору `s`, `paged`, `post_type` за ввімкненого search cache.

Звичайні cookies не входять до key. Excluded cookie дає bypass. Vary-cookie додає SHA-256 свого значення; до variant також входить vary specification, тому зміна списку створює інший key space.

### 9.2. Canonical page key

Canonical request містить version marker, scheme, configured host, port, raw path, canonical query і hash cookie variant. Фінальний key:

```text
{prefix}page:{pageGeneration}:{sha256(canonicalRequest)}
```

Anonymous GET/HEAD HIT валідує payload, відтворює status і safe headers, друкує body лише для GET та робить `exit` до завантаження WordPress.

### 9.3. Anonymous MISS

1. HEAD MISS нічого не рендерить для кешу.
2. GET намагається отримати `SET lock NX EX 10`.
3. Конкурент без lock не чекає — рендерить uncached response.
4. Власник lock продовжує bootstrap.
5. `wp_cache_postload()` запускається після loading active plugin files, але до `plugins_loaded`.
6. Якщо рівень output buffers змінився порівняно з very-early фазою, anonymous storage навмисно робить BYPASS: ранній HIT не зміг би відтворити outer plugin transform.
7. Інакше `Page_Capture` відкриває buffer, а пізні inner transforms потрапляють у фінальний captured HTML.

Не прибирати перевірку output-buffer level без повного аналізу раннього HIT parity.

### 9.4. Logged-in path

Logged-in caching disabled за замовчуванням. Якщо enabled, core auth-cookie value хешується в session variant. Early runtime уже підключається до Redis і читає page-generation metadata, але не читає page payload і не бере lock до validation WordPress session.

На late `template_redirect`/`template_include` перевіряються `wp_validate_auth_cookie()`, current user ID, page type/status і вже встановлені security/cache headers. До key додається SHA-256 від user ID, sorted roles і resolved capabilities. Fake/revoked cookie дає bypass. Callback не видає HIT, якщо після нього на тому самому `PHP_INT_MAX` є пізніший template filter.

Logged-in HEAD завжди bypass. Key не включає довільні user meta або application state; персоналізація в межах тієї самої session може застаріти. Custom auth/personalization cookies потрібно exclude або безпечно vary.

### 9.5. Response validation і payload

Кешуються тільки:

- `home`, `singular`, `archive`, optional `search` зі status 200;
- optional `404` зі status 404.

Feed, preview, trackback, robots, favicon, embed, Customizer, password-protected content, redirect та інший status не кешуються.

HTML має бути повним документом: safe BOM/whitespace/comment/doctype/XML preamble, `<html>`, `<body>`, `</body>`, фінальний `</html>`. Після нього дозволені лише whitespace і повні HTML comments. NUL, warning/debug text перед документом або довільний trailing output дає bypass.

Page payload — PHP serialized array:

```php
array(
    'version'   => 1,
    'status'    => 200,
    'type'      => 'home',
    'headers'   => array(),
    'body'      => '<!doctype html>...',
    'stored_at' => time(),
)
```

Read використовує `unserialize(..., ['allowed_classes' => false])`. `stored_at` інформаційний; freshness визначає Redis TTL. Немає compression і size cap.

### 9.6. Header policy

- `Set-Cookie` забороняє storage, якщо кожне cookie name не allowlisted.
- Allowlisted `Set-Cookie` дозволяє storage, але сам header ніколи не зберігається й не replay-иться.
- Не replay-яться auth, redirect, hop-by-hop, server, powered-by, length, encoding і debug headers.
- `Content-Type`, якщо є, має бути `text/html` або `application/xhtml+xml`.
- `Content-Encoding` забороняє storage; Redis містить uncompressed body.
- `Vary` дозволяє тільки `Accept-Encoding`.
- `private`, `no-store`, `no-cache` зазвичай заборонені. Виняток — точний стандартний WordPress no-cache набір для validated logged-in variant або opted-in anonymous 404.
- `Pragma: no-cache` заборонений.

### 9.7. Stampede lock

```text
SET {finalPageKey}:lock {randomToken} NX EX {lockTTL}
```

Payload write і lock delete виконуються одним Lua script лише якщо token досі належить renderer. Повільний request після expiry не може overwrite новіший payload або видалити чужий lock. Звичайний release також token-checked Lua. Lock queue/stale serving немає.

Debug header:

```text
X-Simple-Redis-Cache: HIT | MISS | BYPASS
```

`MISS` не гарантує storage: late response validation ще може відмовити.

## 10. Ручне очищення

`Simple_Redis_Cache_Purger::purge()` приймає `all`, `object`, `page`.

- object → bump `object-generation`;
- page → bump `page-generation`;
- all → обидва;
- object/all додатково видаляє всі стандартні `_transient_*` та `_site_transient_*` rows із поточної `wp_options`.

Остання операція виконується незалежно від `transient_db_fallback` і не може відрізнити mirror цього плагіна від інших WordPress transients. Це поточна свідома семантика кнопки object purge.

Немає hooks на `save_post`, comments, terms, menus, WooCommerce тощо. Не додавати auto purge без прямої вимоги, окремого дизайну і документації.

## 11. Admin UI та security

Сторінка: **Settings → Redis Cache**, slug `simple-redis-cache`. Capability — `manage_options`.

Hooks:

- `admin_menu`, `admin_init`, `admin_notices`;
- `admin_post_simple_redis_cache_test_redis`;
- `admin_post_simple_redis_cache_purge`;
- `admin_bar_menu` priority 100;
- `plugin_action_links_{basename}`.

Connection test і purge перевіряють capability та nonce. Test працює із вже збереженими, а не unsaved полями. Diagnostics робить live PING і показує PhpRedis, connection, owned/foreign/missing drop-ins, `WP_CACHE`, generated config path і фактичний prefix.

Під час sanitization додається shutdown sync на `PHP_INT_MAX`, щоб повторний Save міг виправити permission/collision навіть коли option value не змінився. `Plugin::$last_config_hash` запобігає duplicate sync у тому самому request.

Admin bar має Clear all/page/object і Settings. Parent node теж очищує all. Усі URL scope-specific nonce protected.

## 12. Drop-in ownership і `WP_CACHE`

Drop-in ownership marker: `Simple Redis Cache Drop-In`, перевіряються перші 16 KiB. Foreign target ніколи не перезаписується. Install використовує temp file у target directory, mode `0644`, повторний ownership check, atomic rename і OPcache invalidation. Remove видаляє тільки owned file.

Marker — heuristic, не signature. Не послаблювати перевірку й не видаляти foreign/unreadable target.

Коли page drop-in успішно встановлено, `ensure_wp_cache()`:

- залишає standard `true/1`;
- замінює standalone `false/0` на `true`;
- відмовляється змінювати dynamic/non-standard expression;
- вставляє define перед WordPress stop marker, `wp-settings.php` require або після `<?php`;
- зберігає mode, перевіряє SHA-256 незмінності original і робить atomic rename.

Якщо `advanced-cache.php` foreign, `WP_CACHE` не вмикається. Плагін ніколи не видаляє й не вимикає `WP_CACHE` під час disable, deactivation або uninstall.

## 13. Lifecycle

### Activation

На multisite activation відхиляється через deactivate + `wp_die`. На single-site:

1. DB config зливається з defaults і sanitize-иться;
2. option створюється/оновлюється з `autoload=false`;
3. generations уже enabled layers bump-яться best-effort;
4. early config записується;
5. drop-ins синхронізуються;
6. за потреби встановлюється `WP_CACHE=true`.

На новій інсталяції шари disabled, але generated config створюється.

### Settings save

Спочатку атомарно пишеться early config, потім install/remove drop-ins. Якщо early write failed, обидва owned drop-ins видаляються. Settings change не робить automatic generation bump або purge. Після зміни cache semantics адміністратор має очистити потрібний шар вручну.

Disable/re-enable через settings може знову зробити доступними старі entries того самого generation до закінчення TTL. Це відрізняється від plugin deactivation/reactivation.

### Deactivation

1. Best-effort bump generations enabled layers.
2. Записати тимчасову early config copy з обома `enabled=false`.
3. Видалити owned drop-ins.
4. Видалити owned early config.

Збережені settings залишаються, тому reactivation відновлює flags. Redis payloads фізично не видаляються. `WP_CACHE` не змінюється. Помилка generation bump не блокує activation/deactivation; після Redis outage старі entries теоретично можуть знову стати доступними після recovery.

### Update

WordPress update не запускає activation hook. Немає migration/resync hook: update лише очищує updater metadata. Loaders у plugin directory оновлюються разом із кодом, але зміни tiny installed templates або необхідність regeneration config потребують окремої migration стратегії чи ручного Save/reactivation.

### Uninstall

`uninstall.php` видаляє marker-owned drop-ins/config, settings option, notices option, notices user meta для всіх users і updater transient. OPcache інвалідується.

Не видаляються:

- `WP_CACHE` і `WP_CACHE_KEY_SALT`;
- Redis payloads/meta;
- DB transient rows;
- foreign/unreadable файли;
- abandoned temp files.

Звичайний delete відбувається після deactivation, тому generation зазвичай уже bump-нуто.

## 14. Single-site defense in depth

- Activation відмовляється на multisite.
- `Plugin::init()` на multisite показує notices, але не запускає admin/updater/sync.
- Object loader повертається до core runtime cache.
- Advanced-cache loader і capture роблять bypass.
- Purger повертає `WP_Error`.
- `switch_to_blog()` лише очищає L1.

Не існує network option, per-blog prefix, правильних global-group semantics, network settings page або multi-table uninstall. Не «вмикати частково» multisite однією перевіркою; це окремий архітектурний проєкт.

## 15. GitHub updater

Repository: `vitaliikaplia/simple-redis-cache`, fixed branch: `master`.

Version URL:

```text
https://raw.githubusercontent.com/vitaliikaplia/simple-redis-cache/master/simple-redis-cache.php
```

Package URL:

```text
https://github.com/vitaliikaplia/simple-redis-cache/archive/refs/heads/master.zip
```

Hooks: `pre_set_site_transient_update_plugins`, `site_transient_update_plugins`, `plugins_api`, `upgrader_source_selection` priority 11, `delete_site_transient_update_plugins`, `upgrader_process_complete`.

Успіх кешується site transient на 12 годин, failure — на 1 годину. Authorized admin `?force-check=1` обходить persistent cache; один updater instance робить не більше однієї remote attempt у request. HTTP: 10-second timeout, 3 redirects, 64 KiB limit, 2xx only. Версія читається з `Version:` і порівнюється `version_compare()`.

`Update URI` у main header не дає WordPress.org підмінити same-slug plugin. `plugins_api` надає стандартну details modal. `upgrader_source_selection` перейменовує GitHub archive root у фактичну поточну installed directory, зберігаючи activation навіть для `simple-redis-cache-master` або custom folder.

Updater працює лише поки plugin active. Він не самовільно вмикає auto-update: автоматична інсталяція відбудеться лише через нативний WordPress opt-in адміністратора.

Модель branch-based і mutable: немає GitHub API/token, tags, checksum, signature або pin до commit. Push між check та install може змінити package contents. Репозиторій мусить бути публічним.

Release checklist:

1. однаково підняти header `Version` і `SIMPLE_REDIS_CACHE_VERSION`;
2. оновити `Stable tag` і changelog;
3. за зміни requirements синхронізувати main header, readmes та hard-coded updater fields;
4. перевірити ZIP root behavior;
5. запушити coherent tree у `master`.

Tag або GitHub Release поточному updater не потрібні.

## 16. Відомі компроміси й ризики

- Немає auto purge: HTML і objects можуть бути stale до TTL/manual purge.
- Late `DONOTCACHEPAGE`, визначений active plugin, зупиняє MISS storage, але не готовий anonymous early HIT. Для гарантованого bypass умова має бути доступна до `advanced-cache.php` або виражена path/query/cookie exclusion.
- Anonymous HIT не завантажує WordPress, DB, plugins або theme hooks. Будь-яка personalization повинна бути врахована в exclusions/variants.
- Anonymous output-buffer guard порівнює лише `ob_get_level()`, а не identity handlers; заміна stack зі збереженням тієї самої глибини не буде виявлена.
- Logged-in key не містить довільні user meta; logged-in cache ризикований для session state і disabled за замовчуванням.
- Vary-cookie/ignored-query misconfiguration може об'єднати різні відповіді в один key.
- Allowed `Set-Cookie` не replay-иться на HIT.
- Page payload не має size cap і зберігається uncompressed.
- TTL reduction не скорочує вже створені keys.
- Generation purge не звільняє пам'ять негайно.
- Settings disable/re-enable може оживити старе generation до TTL.
- Object `expire=0` стає `max_ttl`, а не infinity.
- Manual object/all purge видаляє всі WordPress transients у поточній options table.
- Credentials існують і в DB, і в generated config.
- Ownership marker не є криптографічною ідентичністю.
- `WP_CACHE` лишається після uninstall.
- GitHub package — moving `master` без signature.
- Немає post-update migrations/config regeneration.
- Redis meta eviction може відродити старе generation `1`; враховувати server eviction policy.

## 17. Правила внесення змін

- Ранні файли не повинні викликати WordPress APIs, які ще не гарантовано завантажені. Перевіряйте `function_exists`/`defined` або залишайте логіку dependency-free.
- Не переносити secret/config читання в constants. Redis settings залишаються в admin option/generated config.
- Не міняти key schema або payload version без migration/namespace versioning та purge plan.
- Не змінювати byte-for-byte поведінку `WP_CACHE_KEY_SALT` випадково.
- Не перезаписувати й не видаляти foreign drop-ins/config.
- Файлові заміни мають залишатися atomic, із temp у тому самому filesystem та OPcache invalidation.
- Не допускати uncaught Redis/serialization/filesystem exception у frontend runtime.
- Admin mutation потребує capability, nonce, sanitization і escaped output.
- Зміни page cache перевіряти окремо для anonymous/logged-in, GET/HEAD, HIT/MISS/BYPASS, query, cookies, headers, output buffers і races.
- Зміни object cache перевіряти для L1, Redis, non-persistent groups, DB mirror, force read, NX/XX і Redis outage.
- Не додавати auto invalidation або destructive Redis flush як «зручне» виправлення.
- Не вважати activation hook migration hook: він не запускається при update.
- Якщо змінено drop-in template path або plugin directory behavior, перевірити GitHub updater normalization.

## 18. Перевірки перед завершенням задачі

Формального test suite зараз немає, тому потрібні пропорційні runtime-перевірки.

Мінімум:

```bash
find . -name '*.php' -type f -print0 | xargs -0 -n1 php -l
wp core version
wp plugin status simple-redis-cache
redis-cli PING
```

Для змін lifecycle/config:

- зафіксувати початкові plugin status, option, `wp-config.php`, drop-ins і Redis state;
- активувати/деактивувати плагін;
- перевірити ownership collisions;
- перевірити generated config permissions/marker;
- переконатися, що site повертає 200 після Redis outage;
- повернути локальне середовище до початкового стану.

Для object cache:

- set/get/add/replace/delete;
- false/null/object values;
- multiple operations;
- TTL cap та expiry;
- flush_runtime/flush_group/full flush;
- transient mirror on/off;
- Redis unavailable/recovery;
- `cache_wp_admin` behavior.

Для page cache з увімкненим debug header:

- перший anonymous GET = MISS, наступний = HIT;
- HEAD HIT без body;
- logged-in disabled/enabled і fake/revoked cookie;
- excluded path/cookie/UA;
- ignored, safe й unsafe query;
- hard refresh/conditional/range = BYPASS;
- redirects/private headers/Set-Cookie/Vary/content encoding;
- 404/search flags;
- concurrent MISS lock і TTL;
- manual page generation purge.

Для updater:

- mock `pre_http_request` із newer/current/invalid version;
- перевірити `response` і `no_update`;
- plugin details modal data;
- success/failure cache;
- package URL;
- normalization для canonical, `-master` і custom installed directory.

Після будь-якої зміни release metadata синхронізувати `simple-redis-cache.php`, `readme.txt`, `README.md`, `AGENTS.md` та updater requirements.
