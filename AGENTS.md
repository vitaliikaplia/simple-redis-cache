# AGENTS.md — технічний довідник Simple Redis Cache

Цей файл описує поточну архітектуру плагіна та правила безпечного внесення змін. Він застосовується до всього каталогу плагіна. Джерелом істини завжди є код; якщо поведінку змінено, цей файл, `README.md`, `readme.txt` і changelog потрібно синхронізувати.

## 1. Контракт продукту

Simple Redis Cache — навмисно вузький WordPress-плагін із двома незалежними Redis-шарами:

1. persistent WordPress Object Cache API через `wp-content/object-cache.php`;
2. full-page HTML cache через `wp-content/advanced-cache.php`.

Поточний узгоджений release baseline: версія плагіна, `Stable tag`, gettext metadata та текст changelog у стандартному WordPress plugin-details modal — `0.5.0`; schema конфігурації — `3`; page payload — `3`.

Незмінні межі поточного продукту, якщо задача прямо не вимагає змінити scope:

- тільки single-site WordPress;
- WordPress 6.5+ і PHP 8.1+;
- тільки розширення PhpRedis;
- один standalone Redis server;
- Redis TCP, TLS або Unix socket;
- усі Redis connection settings задаються в адмінці;
- єдина користувацька константа для namespace кешу — `WP_CACHE_KEY_SALT`;
- `WP_CACHE` використовується лише як стандартний прапорець WordPress для `advanced-cache.php`;
- жодного filesystem cache, Predis, Redis Cluster, Sentinel або replica routing;
- жодної інтеграції з CDN: немає API-клієнтів, purge-викликів чи знання про конкретного провайдера. Єдиний виняток — опційний `page.shared_max_age`, який лише додає стандартний `Cache-Control` на попадання; що з ним робить проксі, плагіну невідомо, і очищення CDN лишається на операторові;
- жодної мініфікації, scheduled/background preload або cron warmup;
- автоматична content invalidation обмежена двома незалежними opt-in механізмами: оновлений post/page/CPT із перекладами та публічні term archives, пов'язані з цими posts; непов'язані й generic archives автоматично не очищуються;
- дозволений лише ручний browser-led прогрів HTML-кешу з відкритої admin-вкладки;
- глобальне очищення користувачем доступне тільки вручну, але generation bump-иться автоматично на lifecycle-подіях: activation/deactivation, schema migration і зміна semantic policy-налаштувань відповідного шару; автоматична інвалідація після оновлення контенту завжди точкова через content tokens;
- ніколи не застосовувати `FLUSHDB`, `FLUSHALL` або Redis `KEYS`;
- будь-який Redis/cache failure має бути fail-open для звичайного HTTP-запиту.

Плагін не має Composer/autoloader, JavaScript/CSS build, REST API, WP-CLI команд або власних cron-задач. Є лише два privileged `wp_ajax_*` actions для discovery та cross-origin fallback ручного прогріву.

## 2. Карта файлів

| Файл | Відповідальність |
| --- | --- |
| `simple-redis-cache.php` | Metadata, глобальні константи, `require_once`, activation/deactivation hooks, запуск на `plugins_loaded`. |
| `includes/class-simple-redis-cache-plugin.php` | Оркестрація lifecycle, single-site guard, синхронізація конфігурації та drop-in. |
| `includes/class-simple-redis-cache-early-config.php` | Dependency-free defaults, читання раннього config, побудова Redis prefix/meta keys. |
| `includes/class-simple-redis-cache-config.php` | Читання option напряму з БД, sanitization, атомарний generated config. |
| `includes/class-simple-redis-cache-redis.php` | Мінімальний PhpRedis connection wrapper, generation counters і hash post/term content versions. |
| `includes/class-simple-redis-cache-dropins.php` | Встановлення, видалення, ownership-check drop-in та безпечне ввімкнення `WP_CACHE`. |
| `includes/class-simple-redis-cache-admin.php` | Settings API, діагностика, connection test, confirmed purge actions, notices, admin bar і privileged warmup AJAX. |
| `includes/class-simple-redis-cache-purger.php` | Ручна інвалідація object/page namespaces та очищення DB transients. |
| `includes/class-simple-redis-cache-page-invalidator.php` | Відкладена точкова інвалідація оновлених post/page/CPT, груп перекладів і пов'язаних public term archives. |
| `includes/class-simple-redis-cache-warmer.php` | Allowlisted discovery публічних frontend URL, URL signatures і server-side warm fallback. |
| `includes/class-simple-redis-cache-page-capture.php` | Пізня валідація WordPress-відповіді, output capture, знімок post/term content version і атомарний запис HTML із звільненням lock. |
| `includes/class-simple-redis-cache-github-updater.php` | Перевірка версії у GitHub-гілці `master` та інтеграція зі стандартним WordPress updater. |
| `dropins/object-cache.php` | Мінімальний шаблон, що копіюється в `wp-content/object-cache.php`. |
| `dropins/object-cache-loader.php` | Ранній bootstrap і процедурний WordPress Object Cache API. |
| `dropins/class-simple-redis-cache-object-cache.php` | Реалізація глобального `WP_Object_Cache`. |
| `dropins/advanced-cache.php` | Мінімальний шаблон, що копіюється в `wp-content/advanced-cache.php`. |
| `dropins/advanced-cache-loader.php` | Ранній HIT/MISS runtime, logged-in validation, stampede lock і опційний `Cache-Control` для shared caches на HIT. |
| `dropins/class-simple-redis-cache-page-request.php` | Dependency-free request validation та page-key canonicalization. |
| `assets/js/admin-warm-cache.js` | Послідовний HEAD/GET/HEAD warmup, live progress, stop і verified result counters. |
| `assets/css/admin-warm-cache.css` | Layout вкладки ручного прогріву без build-кроку. |
| `uninstall.php` | Видалення plugin-owned файлів і WordPress state. |
| `readme.txt` | WordPress-format metadata/changelog. |
| `README.md` | Коротка документація для користувача. |
| `languages/simple-redis-cache.pot` | Канонічний шаблон gettext-рядків. |
| `languages/simple-redis-cache-uk.po` | Український переклад для WordPress locale `uk`. |
| `languages/simple-redis-cache-uk.mo` | Скомпільований runtime-переклад, який має постачатися разом із плагіном. |
| `tests/*.php` | Dependency-light regression та optional Redis integration checks без PHPUnit/Composer. |

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

`Plugin::init()` реєструє завантаження text domain на `init` priority `0`, відмовляється запускати підсистеми на multisite, виконує одноразову schema migration/self-heal за потреби, створює GitHub updater, ініціалізує admin hooks і `Simple_Redis_Cache_Page_Invalidator`, а також слухає `update_option_simple_redis_cache_settings`. Activation та фактично потрібна migration окремо викликають `load_textdomain()`, оскільки їхні notices можуть виникнути до звичайного `init` load; той самий виклик робить `maybe_recover_runtime()` перед retry, хоча на `admin_init` text domain уже завантажено.

### 3.2. Generated early config

Обидва drop-in не можуть покладатися на нормальний bootstrap плагіна або Settings API. Тому `Config::write_early_config()` створює:

```text
wp-content/simple-redis-cache-config.php
```

Файл повертає повний config-масив, включно з Redis password. Штатні activation/settings callers передають sanitized config, але `write_early_config()` сам не викликає sanitization і лише серіалізує переданий/current config. Запис виконується як temp file + `LOCK_EX` + atomic `rename()`, права — best-effort `0640`. Ownership перевіряється двічі: перед створенням temp file і ще раз безпосередньо перед `rename()` — програний race видаляє temp file і повертає `src_config_collision`. Після заміни інвалідується OPcache і скидається static cache `Early_Config`. Маркер ownership: `Generated by Simple Redis Cache`.

Якщо target існує без маркера, його не перезаписують і не видаляють. Якщо early config не вдалося оновити, `Plugin::synchronize()` прибирає обидва власні drop-in: ранньому runtime не дозволено працювати зі старими connection/privacy settings.

`Early_Config::load()` перехоплює будь-який `Throwable`, зливає файл із defaults і кешує результат у static property до `reset()`.

## 4. Persistent state та побічні файли

| State | Значення/призначення |
| --- | --- |
| Option `simple_redis_cache_settings` | Усі налаштування; `autoload=false`. |
| User meta `_simple_redis_cache_notices` | Черга notices конкретного адміністратора. |
| Option `_simple_redis_cache_notices` | Спільна черга для повідомлень, які нікому персонально не адресуєш: `queue_notice()` пише сюди, коли автор запиту не має `manage_options` або user ID дорівнює `0` (cron, frontend, webhook); `autoload=false`. `admin_notices()` вичитує її разом із власною чергою адміністратора. |
| Site transient `simple_redis_cache_github_update_data` | Кеш GitHub version check. |
| `wp-content/simple-redis-cache-config.php` | Ранній generated config із credentials; у штатному flow записується sanitized config. |
| `wp-content/object-cache.php` | Керований object-cache drop-in. |
| `wp-content/advanced-cache.php` | Керований page-cache drop-in. |
| `wp-config.php` | Може бути змінений лише для стандартного `WP_CACHE=true`. |

`Config::get()` читає головний option прямим SQL із `$wpdb->options`, оминаючи object cache. Це необхідно для ремонту connection settings після Redis outage або застарілого кешу. Notices також читаються напряму з БД; зберігається максимум 10 повідомлень.

Credentials дублюються у WordPress DB та generated PHP config. Не логувати їх, не показувати в diagnostics і не додавати до Redis keys, HTTP headers чи GitHub User-Agent.

## 5. Повна схема конфігурації

Кореневий `config_version` зараз дорівнює `3`. `Config::stored()` читає raw option без defaults, `needs_migration()` порівнює з `Early_Config::CONFIG_VERSION`, а `migrate()` пропускає старий state через поточний sanitizer. Новіший stored schema старішою копією плагіна не downgrade-иться через `migrate()`: він виходить, щойно stored version не менша за поточну. Це не поширюється на Save: `sanitize()` перебудовує масив із `defaults()` і штампує `CONFIG_VERSION` запущеного коду, тож збереження налаштувань зі старішої копії плагіна опустить schema й відкине ключі, яких вона не знає.

Під час першого звичайного bootstrap після оновлення `Plugin::maybe_upgrade()`:

1. sanitize-ить і зберігає поточну schema з `autoload=false`;
2. перевіряє запис прямим читанням із БД;
3. best-effort інвалідує обидва enabled namespaces, бо зміна schema може змінити cache semantics;
4. атомарно переписує early config і синхронізує drop-in.

Якщо DB migration не збереглася, ранній runtime не змінюється і адміністратор отримує error notice. Якщо Redis invalidation не вдалася, plugin-owned early config перечитується без static cache, обидва cache flags тимчасово стають `false`, обидва owned drop-in видаляються, а saved DB config лишається бажаним станом. Консервативне вимкнення обох шарів не дозволяє застосувати нову schema поверх старого покоління або частково відкотити паралельний Save. На наступному authenticated `admin_init` для користувача з `manage_options` `maybe_recover_runtime()` порівнює generated runtime із saved config, повторює інвалідацію і після успіху відновлює потрібні config/drop-in. Публічні `admin-ajax.php`/`admin-post.php` recovery не запускають; frontend у цей час лишається fail-open без Redis retry на кожному запиті.

### 5.1. Redis

| Key | Default | Sanitization/сенс |
| --- | --- | --- |
| `redis.scheme` | `tcp` | Тільки `tcp`, `tls`, `unix`. |
| `redis.host` | `127.0.0.1` | Ігнорується для Unix socket. |
| `redis.port` | `6379` | `1..65535`; ігнорується для Unix socket. |
| `redis.path` | порожньо | Raw absolute Unix socket path, наприклад `/home/account/.system/redis.sock`, без `unix://`. |
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
| `object.non_persistent_groups` | `[]` | Точна назва групи на рядок. Redis L2 вимкнений; група лишається в L1, а `transient`/`site-transient` можуть також використовувати optional DB mirror. |

### 5.3. HTML page cache

| Key | Default | Sanitization/сенс |
| --- | --- | --- |
| `page.enabled` | `false` | Керує `advanced-cache.php`; потребує `WP_CACHE=true`. |
| `page.ttl` | `3600` | `60..MONTH_IN_SECONDS`. |
| `page.shared_max_age` | `0` | `0..MONTH_IN_SECONDS`. Нуль — не надсилати заголовок узагалі (поточна поведінка). Ненульове значення додає на HIT `Cache-Control: public, max-age=0, s-maxage=N`: shared cache може зберігати відповідь, браузер щоразу перевіряє. Заголовок додається лише на anonymous HIT. Validated logged-in шлях передає в `serve()` жорсткий `0` і прапорець private: session-specific відповідь не можна пропонувати shared cache, який ключується за URL, а якщо у збереженому payload немає власного `Cache-Control`, runtime надсилає `Cache-Control: private, no-store`. Заголовок формується в `serve()` і **не** входить у збережений payload, тож зміна цього значення не інвалідує кеш: ключа немає у списку `$page_keys` в `invalidate_changed_settings()` — так само, як `debug_header` і `lock_ttl`; решта page-ключів інвалідацію запускають. Наявний `Cache-Control` у збереженій відповіді ніколи не перезаписується. |
| `page.invalidate_on_post_update` | `false` | На `post_updated` точково змінює content token поточного frontend-viewable post/page/CPT та всіх перекладів, знайдених через documented WPML/Polylang APIs. |
| `page.invalidate_term_archives_on_post_update` | `false` | На `post_updated`/`set_object_terms` точково змінює content tokens assigned public term archives, old/new relationships і hierarchical ancestors для поста та його перекладів. |
| `page.cache_logged_in` | `false` | Session/access-specific logged-in variants. |
| `page.cache_home` | `true` | Front page і posts home. |
| `page.cache_singular` | `true` | Posts, pages, CPT. |
| `page.cache_archives` | `true` | WordPress archives. |
| `page.cache_search` | `false` | Search results. |
| `page.cache_404` | `false` | Тільки status 404. |
| `page.cache_query_strings` | `false` | Невідомі non-ignored query інакше дають bypass. |
| `page.ignored_query_parameters` | marketing list | Видаляються з cache key. |
| `page.excluded_paths` | cart/checkout/account | `*` і `?` patterns; матчинг case-insensitive, щоб `/My-Account/*` не переставав працювати мовчки. |
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

`Simple_Redis_Cache_Redis` підтримує TCP, `tls://`, Unix socket, classic AUTH, ACL AUTH, SELECT database і persistent sockets. Persistent ID — `simple-redis-cache-` плюс SHA-256 від scheme, уже нормалізованих host/socket (`tls://` префікс для TLS, шлях сокета і port `0` для unix), port, database, username і password, щоб різні connections не ділили сокет. `timeout`, `read_timeout` і `retry_interval` в identity не входять.

Одразу після успішного `connect()`/`pconnect()` і перед AUTH, SELECT та будь-якою data-командою wrapper примусово встановлює (кожну опцію — лише якщо відповідні константи PhpRedis визначені):

- `Redis::OPT_SERIALIZER = Redis::SERIALIZER_NONE`;
- `Redis::OPT_COMPRESSION = Redis::COMPRESSION_NONE`;
- `Redis::OPT_PREFIX = ''`.

Невдалий `setOption` фатальний: wrapper кидає власний RuntimeException, тож `connect()` повертає `false`, а `client()` — `null`. Краще залишитися без кешу, ніж працювати з чужим serializer або prefix.

Payload кодує сам плагін, а generation values мають залишатися plain integers. Один wrapper робить лише одну connection attempt. Усі винятки ловляться. `error()` описує саме останній виклик: кожна публічна операція скидає попередню помилку на початку. Виняток — невдале підключення: воно робиться один раз, тому зберігається й повертається кожним наступним викликом.

`generation($namespace)` читає `{prefix}meta:{namespace}-generation`. Якщо ключ відсутній, він створюється через `SET NX` із випадковим позитивним integer: 40–52 bits на 64-bit PHP або 20–30 bits на 32-bit із понад мільярдом значень запасу для `INCR`. Після race значення перечитується й строго перевіряється як canonical decimal integer.

`bump_generation()` виконує одним Lua-викликом `missing → random SET → INCR`, тому eviction між окремими командами не може повернути namespace до `1`. Page-generation bump тим самим script видаляє hash page content versions: payload старого global generation уже недосяжний, а нові post/term tokens ініціалізуються заново. При помилці read повертає defensive `1` разом із error; споживачі повинні перевірити error і зробити bypass. Purge повертає `false`/`WP_Error`, якщо generation не можна bump-нути.

`page_content_version($type, $id)` читає field `post:{id}` або `term:{term_taxonomy_id}` зі спільного Redis hash і за відсутності атомарно створює випадковий 128-bit hex token. `bump_page_content_versions()` одним Lua-викликом змінює tokens усіх переданих unique positive resources і повертає їх кількість; порожній список — це `0` без звернення до Redis. Обидва методи при помилці повертають `false` разом із error: reader тоді робить bypass замість того, щоб віддати payload, а invalidation вважається невдалою. Якщо весь hash був evicted, новий random token не збігається з token у старому payload, тому старий HTML не оживає.

## 7. Redis namespace і ключі

`Early_Config::prefix()` вибирає:

1. непорожній `WP_CACHE_KEY_SALT` byte-for-byte;
2. `site.fallback_prefix`;
3. перші 16 символів SHA-256 від `ABSPATH`.

Потім завжди додає `:src:`. Не обрізати й не нормалізувати salt: `site` і `site:` навмисно створюють різні prefixes.

```text
{prefix}meta:object-generation
{prefix}meta:page-generation
{prefix}meta:page-content-versions         # Redis hash: post:{ID}/term:{TTID} → 128-bit token
{prefix}meta:object-group-{sha256(group)}-generation
{prefix}meta:diagnostic:{sha256(uuid4)}    # тимчасовий ключ connection test: SET NX EX 30, видаляється одразу

{prefix}object:{objectGeneration}:{groupGeneration}:{sha256(group + NUL + key)}
{prefix}page:{pageGeneration}:{sha256(canonicalRequest)}
{pageKey}:access:{sha256(user/roles/capabilities)}
{finalPageKey}:lock
```

Meta generation keys і `page-content-versions` не мають TTL. Hash очищується під час global page-generation bump; окремі fields змінюються, але не видаляються, під час точкової content invalidation. Object/page payloads мають TTL. Старі generation або content-version payloads після purge залишаються фізично в Redis і звільняються природно за TTL або видаляються best-effort під час наступного stale read.

Якщо allkeys policy видалить generation metadata, випадкова 40–52-bit ініціалізація (20–30-bit на 32-bit PHP) створює практично новий namespace і не повертає старе покоління `1`. Водночас eviction metadata погіршує hit rate, залишає orphaned payload до TTL і руйнує послідовність manual purge. Тому для передбачуваної роботи все одно бажані `noeviction` або volatile-policy, яка не витісняє persistent meta keys. Плагін лише показує policy у diagnostics і не конфігурує Redis server сам.

## 8. Object cache

### 8.1. Bootstrap та API

`Dropins::install('object')` атомарно встановлює template як `wp-content/object-cache.php`. Loader підключає лише early config і Redis wrapper і мовчки виходить, якщо хоча б один із цих файлів не readable. Далі він відступає на multisite або при `object.enabled=false`, а вже потім підключає `WP_Object_Cache` (лише коли клас ще не оголошено) і процедурні функції, кожна з яких захищена `function_exists()`. У всіх трьох випадках виходу drop-in не оголошує `wp_cache_init()`, тому WordPress вантажить власний runtime cache.

Підтримано:

- add/set/get/delete і multiple variants;
- replace;
- incr/decr;
- flush, flush_runtime, flush_group;
- global/non-persistent groups;
- switch/reset/close/stats;
- WordPress 6.9+ salted helper functions;
- аліаси `increment()`/`decrement()`, які просто делегують в `incr()`/`decr()` для старих інтеграцій.

Публічними також лишаються `$cache`, `$cache_hits`, `$cache_misses`, `$global_groups`, `$non_persistent_groups`, `$blog_prefix`, `$multisite` і `$errors` — їх читають сторонні diagnostics-плагіни. `$errors` збирає не лише Redis-помилки: `encode()` дописує туди й помилки серіалізації.

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

Object payload декодується native `unserialize()` з дозволеними PHP objects, що потрібно для семантики WordPress Object Cache API. Тому Redis є trusted boundary: server має бути private/access-controlled, без можливості стороннього запису в namespace плагіна. Page payload, на відміну, читається з `allowed_classes=false`.

### 8.4. Семантика методів

- `set()` пише L1, best-effort Redis і optional transient mirror; Redis outage не робить runtime write неуспішним.
- Значення, яке не серіалізується (наприклад closure), — це певна відмова, а не outage: `store_redis()` повертає `false` ще до звернення до Redis, `add()` віддає `false`, а DB mirror такий запис не пише, щоб `maybe_serialize()` усередині `update_option()` не завалив запит.
- `add()` поважає `wp_suspend_cache_addition()`, L1 і DB mirror; Redis використовує atomic `SET NX EX`. NX rejection не приймається на віру: `store_redis()` перевіряє ключ через `EXISTS`, і лише підтверджене існування повертає `false`. Якщо ключ зник між `SET` і `EXISTS`, `SET NX EX` повторюється один раз; друге відхилення без підтвердженого `EXISTS` трактується як outage — instance ставить `redis_failed` і повертає `null`. Якщо Redis недоступний (`null`, а не NX `false`), поточна реалізація fail-open записує L1 і повертає `true`, тому cross-request uniqueness під час outage не гарантована.
- `replace()` для Redis використовує `SET XX EX`; DB-only transient спочатку rewarm-иться через NX. Під час outage успіх можливий лише коли наявність доведена L1/DB. Для mirrored групи, оголошеної non-persistent, наявність теж підтверджується з DB mirror, і туди ж іде заміна.
- `get()` іде L1 → Redis → DB mirror. DB hit rewarm-ить Redis через NX; Redis winner має пріоритет при race. Forced read може повернути попередній L1, якщо Redis упав у цьому request.
- `delete()` прибирає L1, Redis і відповідний DB mirror.
- Для persistent Redis groups `incr()`/`decr()` використовують `WATCH → GET → PTTL → MULTI → SET PX → EXEC`, щонайбільше п'ять спроб (retry лише тоді, коли `EXEC` повертає `false` через WATCH-конфлікт), зберігають remaining TTL і не опускають результат нижче нуля. Вичерпані спроби дають статус `failed`: запис прибирається з L1, метод повертає `false` без fallback. Якщо Redis повідомляє `missing`, а DB mirror увімкнений, значення спочатку rewarm-иться з БД через NX і атомарна операція повторюється; недоступний rewarm пише L1 і переводить статус у `unavailable`. До L1/DB fallback без cross-request atomicity доходять лише non-persistent групи та статус `unavailable`.
- `flush()` bump-ить object generation, чистить L1 і, тільки коли DB mirror enabled, видаляє з `wp_options` усі рядки `_transient_*` та `_site_transient_*` — тобто всі стандартні WordPress transients, включно з чужими. Це робить будь-який виклик `wp_cache_flush()` (наприклад, WP-CLI `wp cache flush`), а не лише confirmed purge. Видалення гейтиться успіхом bump так само, як у `Purger::purge()`: якщо потрібний bump не вдався, рядки зберігаються, а метод повертає `false`. Коли object cache вимкнено, bump не потрібен і mirror очищується як звичайно.
- `flush_group()` bump-ить generation лише persistent групи. Звичайна non-persistent group очищається лише з L1; mirrored `transient`/`site-transient` додатково видаляють з `wp_options` усі рядки відповідного префікса (не лише власний mirror). Спершу інвалідується options-group generation, і невдалий bump скасовує сам DELETE: інакше stale Redis-копія групи `options` воскресила б щойно видалені рядки через `get_option()`. Для групи, оголошеної non-persistent, власний group bump не потрібен, але options-bump усе одно виконується, тож за недоступного Redis `flush_group()` теж поверне `false` і рядки збереже.

Після першої runtime Redis-помилки instance встановлює `redis_failed`; наступних Redis attempts у цьому request немає. L1 і DB mirror продовжують працювати.

### 8.5. Transient DB mirror

Mirror працює тільки для groups `transient` і `site-transient` та використовує стандартні option names:

```text
_transient_{key}
_transient_timeout_{key}
_site_transient_{key}
_site_transient_timeout_{key}
```

Запис best-effort викликає `update_option(..., false)` для timeout і value. Зазвичай це дає `autoload=false`, але якщо в WordPress вже існує row з тим самим value, `update_option()` може short-circuit-нути й зберегти попередній autoload state. Результат individual mirror writes не змінює return основної cache operation. Expired rows і orphan timeout очищуються на read. Redis miss може rewarm-итися з БД без overwrite нового concurrent value.

Settings-driven bump object generation навмисно не видаляє ці стандартні rows без confirmation: плагін не може відрізнити власний mirror від транзієнтів інших компонентів. Тому retained DB transient може rewarm-ити вже нове Redis generation (наприклад, після зменшення `max_ttl` — повторними capped TTL до початкового DB timeout). Коли object policy змінюється за ввімкненого mirror до або після Save, admin отримує warning із пропозицією окремо виконати confirmed **Clear object cache**, якщо потрібна повна інвалідація DB copy.

Якщо `cache_wp_admin=false`, звичайні admin reads не йдуть у Redis, але writes/deletes/invalidations продовжують. Mirrored transients читаються з БД; без mirror transient groups усе одно читаються з Redis, бо external object cache не дає core автоматично повернутися до `wp_options`.

## 9. HTML page cache

### 9.1. Ранній request validator

`advanced-cache.php` завантажується до БД та active plugins. Loader читає generated config, перевіряє запит, підключається до Redis і або видає HIT, або продовжує normal WordPress bootstrap.

Розглядаються тільки `GET` і `HEAD`; вони ділять key. Безумовний bypass:

- multisite;
- `DONOTCACHEPAGE`, admin, AJAX, cron, REST, XML-RPC, WP-CLI;
- Authorization/Basic Auth;
- Range, conditional headers і explicit request `Cache-Control: no-store`;
- host/port, що не збігаються з generated `home_url()` metadata;
- malformed percent encoding, raw control characters у `REQUEST_URI` та decoded control characters у path/query names/query values;
- `/wp-admin`, `/wp-json`, `/wp-content`, `/wp-includes` та core service endpoints;
- configured path/cookie/User-Agent exclusions.

Scheme визначається через `HTTPS`/`SERVER_PORT`, а не forwarded headers. Reverse proxy має коректно передавати server environment. Raw path не canonicalize-иться за slash/dot/encoding, тому еквівалентні URL можуть мати різні keys.

Browser reload часто надсилає request `Cache-Control: no-cache`, `max-age=0` та/або `Pragma: no-cache`. Ці директиви навмисно не обходять server-side Redis page cache: звичайний і hard reload можуть отримати HIT. `Cache-Control: no-store`, Range та conditional headers залишаються BYPASS, оскільки cached 200 не реалізує byte ranges або HTTP preconditions.

Завжди unsafe query names перевіряються до ignore rules: nonces, preview/customizer, REST route, cart actions, cron, builder-preview parameters і посилання модерації коментарів (`unapproved`, `moderation-hash`), бо core обмежує показ pending-коментаря лише десятьма хвилинами під час рендеру. Порівняння відбувається з тим іменем, яке реально отримає WordPress. PHP переписує назву параметра перед `$_GET`: прибирає провідні пробіли, а кожен пробіл, крапку й **незакриту** `[` замінює на `_`; закрита `[…]` — це вже масив, і назва обрізається по ній. Тому `?rest.route=…` і `?rest[route=…` доходять як `rest_route`, `?.wpnonce=…` як `_wpnonce`, а `?rest[route]=…` — як безпечний `rest`. Матчинг сирої назви пропускав би більшу частину списку. Ту саму нормалізовану назву використовують ignore-патерни й search-allowlist; сирі назви лишаються в cache key. Query pair order зберігається. Ignored names вилучаються з key. Якщо `cache_query_strings=false`, non-ignored query дає bypass, крім вузького search-набору `s`, `paged`, `post_type` за ввімкненого search cache.

Звичайні cookies не входять до key. Core auth-cookie завжди дає early bypass, коли logged-in caching disabled, включно зі stale/revoked cookie. Коли logged-in caching enabled, два default auth exclusions замінюються hashed session variant і late WordPress validation. Інша excluded cookie дає bypass, крім випадку, коли ця сама cookie явно налаштована як vary-cookie. Vary-cookie додає SHA-256 свого значення; до variant також входить vary specification, тому зміна списку створює інший key space.

### 9.2. Canonical page key

Canonical request — це з'єднані через `\n` рядки: літеральний version marker `simple-redis-cache-page-v1` (не пов'язаний ні з `CONFIG_VERSION`, ні з версією payload; змінюється лише разом зі складом key), scheme, configured host, validated request port, raw path, canonical query і hash cookie variant. Фінальний key:

```text
{prefix}page:{pageGeneration}:{sha256(canonicalRequest)}
```

Anonymous GET/HEAD HIT валідує payload. Якщо відповідна opt-in invalidation активна і payload прив'язаний до post ID або term-taxonomy ID, ранній reader додатково порівнює payload token із field у `page-content-versions`; mismatch видаляє exact stale key і переходить у MISS, Redis failure дає BYPASS. Після успішної перевірки runtime відтворює status і safe headers, за ненульового `page.shared_max_age` і відсутнього збереженого `Cache-Control` додає `Cache-Control: public, max-age=0, s-maxage=N`, друкує body лише для GET та робить `exit` до завантаження WordPress.

### 9.3. Anonymous MISS

1. HEAD MISS нічого не рендерить для кешу.
2. GET намагається отримати `SET lock NX EX 10`.
3. Конкурент без lock не чекає — рендерить uncached response.
4. Власник lock продовжує bootstrap. Якщо `wp_cache_postload()` уже оголошено іншим drop-in чи компонентом, loader звільняє власний lock і робить BYPASS замість реєстрації свого callback.
5. `wp_cache_postload()` запускається після loading active plugin files, але до `plugins_loaded`.
6. Якщо рівень output buffers змінився порівняно з very-early фазою, anonymous storage навмисно робить BYPASS: ранній HIT не зміг би відтворити outer plugin transform.
7. Інакше `Page_Capture` відкриває buffer, а пізні inner transforms потрапляють у фінальний captured HTML.
8. Зберігається лише буфер, який дійсно віддано: `handle_output()` вимагає `PHP_OUTPUT_HANDLER_FINAL` без `PHP_OUTPUT_HANDLER_CLEAN`. Пізній `ob_end_clean()` — maintenance mode, заміна відповіді, `echo apply_filters( …, ob_get_clean() )` — теж рапортує FINAL, але його вміст ніхто не отримав, тому в кеш він не йде.

Не прибирати перевірку output-buffer level без повного аналізу раннього HIT parity.

### 9.4. Logged-in path

Logged-in caching disabled за замовчуванням. Якщо enabled, core auth-cookie value хешується в session variant. Early runtime уже підключається до Redis і читає page-generation metadata, але не читає page payload і не бере lock до validation WordPress session.

На late `template_redirect`/`template_include` перевіряються `wp_validate_auth_cookie()`, current user ID, page type/status і вже встановлені security/cache headers. До key додається SHA-256 від user ID, sorted roles і resolved capabilities. Fake/revoked cookie дає bypass. Якщо після нього на тому самому `PHP_INT_MAX` зареєстрований пізніший `template_include` filter, callback повністю відмовляється від кешування цього запиту: BYPASS без HIT і без MISS storage.

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
    'version'   => 3,
    'status'    => 200,
    'type'      => 'home',
    'headers'   => array(),
    'body'      => '<!doctype html>...',
    'stored_at' => time(),
    'resource_type'    => 'post', // post, term або порожній рядок
    'resource_id'      => 123,    // post ID, term_taxonomy_id або 0
    'resource_version' => '128-bit hex token або порожній рядок',
)
```

Read використовує `unserialize(..., ['allowed_classes' => false])`. Payload v1 і v2 читаються для backward compatibility лише коли їхня відсутня content-token семантика не потрібна; schema migration або перемикання будь-якої invalidation option bump-ить page-generation до застосування нових правил. V2 legacy `post_id`/`post_version` нормалізуються в post resource. `stored_at` інформаційний; freshness визначають Redis TTL, global page generation і optional post/term content token. Немає compression і size cap.

Крім response-валідації, `maybe_store()` має ще чотири PHP-side виходи, коли ввімкнена content invalidation: колбек `wp` не встиг відпрацювати; queried resource зник до shutdown, хоча в snapshot був; його тип або ID змінилися; для наявного resource не вдалося отримати token. У всіх цих випадках payload не пишеться, а debug header лишається `MISS`.

### 9.6. Header policy

- `Set-Cookie` забороняє storage, якщо кожне cookie name не allowlisted.
- Allowlisted `Set-Cookie` дозволяє storage, але сам header ніколи не зберігається й не replay-иться.
- Не replay-яться auth, redirect, hop-by-hop, server, powered-by, length, encoding і debug headers.
- `Content-Type`, якщо є, має бути `text/html` або `application/xhtml+xml`.
- `Content-Encoding` забороняє storage; Redis містить uncompressed body.
- `Vary` дозволяє тільки `Accept-Encoding`.
- `private`, `no-store`, `no-cache` зазвичай заборонені. Виняток — один із двох точних стандартних WordPress no-cache наборів (`max-age=0, must-revalidate, no-cache` або той самий набір із доданими `no-store, private`) для validated logged-in variant чи opted-in anonymous 404; будь-яка інша комбінація директив забороняє storage.
- `Pragma: no-cache` заборонений.
- На anonymous HIT `serve()` додатково надсилає `Cache-Control: public, max-age=0, s-maxage=N`, якщо `page.shared_max_age >= 1` і у збереженому payload немає власного `Cache-Control`. Validated logged-in HIT цього заголовка не отримує ніколи; замість нього за відсутнього збереженого `Cache-Control` надсилається `private, no-store`. Заголовок формується в runtime, не входить у payload, не впливає на eligibility й не bump-ить page generation.

### 9.7. Stampede lock

```text
SET {finalPageKey}:lock {randomToken} NX EX {lockTTL}
```

Payload write і lock delete виконуються одним Lua script лише якщо token досі належить renderer. Для payload, прив'язаного до post ID або term-taxonomy ID, той самий script також перевіряє, що content token не змінився від моменту `wp`/late logged-in snapshot; update під час render видаляє власний lock і забороняє stale write. Повільний request після expiry не може overwrite новіший payload або видалити чужий lock. Звичайний release також token-checked Lua. Lock queue/stale serving немає.

Поки capture buffer відкритий, loader тримає його lock: `release_lock()` відмовляє й повертає `false`. Інакше на сайті, який зняв core-ний `wp_ob_end_flush_all()` із shutdown, буфер фіналізується вже після shutdown-стека, lock устиг би зникнути, write script побачив би чужий token — і сторінка мовчки ніколи не кешувалася б. Після фіналізації або discard претензія знімається, і release відбувається штатно.

Debug header:

```text
X-Simple-Redis-Cache: HIT | MISS | BYPASS
```

`MISS` не гарантує storage: late response validation ще може відмовити.

## 10. Очищення HTML та object cache

`Simple_Redis_Cache_Purger::purge()` приймає `all`, `object`, `page`.

- object → bump `object-generation`;
- page → bump `page-generation`;
- all → обидва;
- object/all після успішного object bump додатково видаляє всі стандартні `_transient_*` та `_site_transient_*` rows із поточної `wp_options`.

Остання операція виконується незалежно від `transient_db_fallback` і не може відрізнити mirror цього плагіна від інших WordPress transients. Це поточна свідома семантика кнопки object purge.

Object/all потребують окремої confirmation-сторінки та POST із capability + scope-specific nonce + `confirmed=1`. Page-only purge лишається прямою nonce-protected дією, бо не видаляє DB rows. Якщо object-generation bump не вдався, DB transients навмисно зберігаються. Purge не транзакційний: `all` усе ще може успішно інвалідовувати один Redis layer і повернути `WP_Error` через інший. Жоден scope не досягає shared cache: за `page.shared_max_age > 0` копія HIT, яку CDN або reverse proxy зберіг за `s-maxage`, живе до закінчення власного часу — плагін не має CDN purge, тож очищення edge лишається на операторові.

### 10.1. Опційна інвалідація після оновлення контенту

`Simple_Redis_Cache_Page_Invalidator` завжди реєструє core hooks `post_updated` і `set_object_terms`, але виходить без роботи, доки HTML-кеш та хоча б одна з двох invalidation options не ввімкнені. На `post_updated` він ігнорує revisions/autosaves та працює лише для frontend-viewable post types. Current post ID і translation IDs збираються одразу після core update та повторно на `shutdown`, після пізніх save callbacks, щоб охопити translation relationships до і після таких callbacks у межах request. ID перекладів проходять ту саму перевірку frontend-viewable, що й сам пост, тому осиротілий multilingual-зв'язок не додає в hash поля, до якого не може прив'язатися жоден payload. Polylang інтегрується через `pll_get_post_translations()` лише після `function_exists`; WPML — через documented `wpml_element_type`, `wpml_element_trid` та `wpml_get_element_translations` filters лише коли hooks зареєстровані. Post content tokens фактично змінюються тільки коли `page.invalidate_on_post_update=true`.

Коли `page.invalidate_term_archives_on_post_update=true`, той самий invalidator додатково слухає `set_object_terms` із повними old/new `term_taxonomy_id`. Хук фаєриться для будь-якого типу об'єкта, а його `object_id` унікальний лише в межах таксономії, тому обробник додатково вимагає, щоб post type оновленого об'єкта був зареєстрований саме для цієї таксономії: інакше terms на users чи comments зі збіжним ID інвалідували б сторонній пост. Він обробляє лише taxonomies, для яких `is_taxonomy_viewable()` повертає true, збирає current terms оновленого поста та його перекладів до й після пізніх save callbacks і додає ancestors через `get_ancestors()`. Так зміна категорії інвалідує old і new archives, а hierarchical parent archives очищуються тому, що можуть включати child content. Зв'язки translated posts дають tokens відповідних translated term archives без залежності від внутрішніх API мультимовних плагінів.

На shutdown усі post і term-taxonomy resources одним Redis Lua call отримують нові random tokens. Це логічно інвалідує всі їхні HTML variants у Redis: pagination, query-string, language-cookie та optional logged-in/session/access variants. За ненульового `page.shared_max_age` копію, яку shared cache зберіг за `s-maxage`, token bump не змінює — CDN треба чистити окремо. Exact Redis payload фізично видаляється під час наступного stale read або природно за TTL. Static front/posts page також інвалідується, коли queried object є саме оновленим `WP_Post`. Опції singular і taxonomy invalidation незалежні. Непов'язані term archives та generic home/post-type/author/date/search/404/menu/comment/WooCommerce-derived pages не очищуються. Інвалідатор слухає лише `post_updated` і `set_object_terms`, тому низка звичайних core-подій не bump-ить жодного token і лишає HTML валідним до TTL чи ручного page purge:

- остаточне видалення поста;
- перейменування, злиття або видалення самого терміна: `wp_delete_term()` знімає зв'язки через `wp_remove_object_terms()`, який фаєрить `delete_term_relationships`/`deleted_term_relationships`, а не `set_object_terms`;
- будь-яке зняття зв'язку через `wp_remove_object_terms()` з тієї ж причини; хук ловить лише ту гілку видалення, що відбувається всередині `wp_set_object_terms()`;
- публікація за розкладом: `wp_publish_post()` міняє статус прямим `$wpdb->update()` і фаєрить `edit_post`/`save_post`/`wp_insert_post`, але не `post_updated`. Виняток — пост без категорії, якому default term призначається через `wp_set_post_terms()`, і то лише за ввімкненого `invalidate_term_archives_on_post_update`.

Пряма зміна post meta без `post_updated` не очищає singular payload. Присвоєння термів через `wp_set_object_terms()`/`wp_set_post_terms()` без `wp_insert_post()` потрапляє в `set_object_terms`, але handler виходить одразу, якщо вимкнено саме `invalidate_term_archives_on_post_update`; за одночасно ввімкненої singular invalidation той самий виклик змінює й token самого post.

Якщо Redis invalidation не вдалася, save WordPress лишається успішним у fail-open режимі, а в чергу ставиться warning notice. `queue_notice()` кладе його в user meta лише тоді, коли автор запиту має `manage_options`; save від Редактора, з frontend, webhook або cron іде у спільну option-чергу, яку наступний адміністратор вичитує разом із власною. На відміну від settings-policy transition, runtime/drop-in не вимикаються: старий HTML може жити до TTL або ручного page purge.

### 10.2. Ручний прогрів HTML-кешу

`Simple_Redis_Cache_Warmer::discover()` приймає тільки три списки machine names, які перетинає з актуальними `get_post_types( ['public' => true] )`, `get_taxonomies( ['public' => true] )` та allowlist системних джерел. Довільний URL від input не генерує кеш-запит. Post types/taxonomies додатково мають бути frontend-viewable, а URL має відповідати enabled `cache_home`/`cache_singular`/`cache_archives` flags. Discovery додає та deduplicate-ить лише URL з тим самим scheme/host/effective port, що й `home_url()`:

- головну; окремо posts page та її estimated pagination. Deduplication порівнює normalized key (scheme, host, effective port, path без кінцевого слеша, query), тому `home_url('/')` і `get_home_url()` за `show_on_front = posts` дають один запис. Регістр path зберігається: WordPress може віддавати `/A/` і `/a/` як різні записи;
- author і наявні year/month/day archives із estimated pagination;
- public non-password singular posts/pages/CPT і public post type archives;
- непорожні public taxonomy term archives та estimated pagination.

Search і 404 не мають скінченного discoverable набору. Feeds, REST, embeds, robots, favicon та service endpoints ранній runtime не кешує, тому warmer їх не додає. Attachment post type залишається видимим в UI, але disabled, коли `wp_attachment_pages_enabled=0`. Анонімний прогрів створює лише no-cookie variant; мовні `vary_cookies` прогріваються природно реальними запитами.

Pagination є оцінкою за DB counts і глобальним `posts_per_page`. Для кожного batch до 250 terms warmer виконує один grouped count-запит, який враховує лише frontend-viewable public/search-visible post types, public statuses і записи без пароля; при DB failure використовується core term count. Це зменшує зайві 404, але hierarchical parent archives можуть включати child terms, а custom main-query rules змінювати фактичну кількість сторінок, тому гарантії exact pagination немає. Counters охоплюють усі outcomes, але issue list у браузері показує щонайбільше перші 100 discovery warnings, BYPASS і failures разом та окремий лічильник прихованих проблем. На sites із plain/query-string permalinks generated URL містять `?p=`, `?page_id=`, `?cat=`, `?paged=` тощо і за default `cache_query_strings=false` отримають BYPASS. Для їх прогріву потрібно ввімкнути query-string page caching; unsafe query names все одно завжди bypass-яться.

Same-origin flow:

```text
POST wp_ajax_simple_redis_cache_prepare_warm + manage_options + nonce
  → signed allowlisted URL items
  → browser HEAD credentials=omit
  → HIT = already cached
  → MISS → до двох циклів:
      GET credentials=omit, повністю дочитати body
        → HIT = already cached, наприклад після concurrent writer
        → MISS → HEAD verification
  → тільки фінальний HIT = warmed
```

Discovery обмежене `MAX_DISCOVERED_URLS` (20000): pagination видається на кожну оцінену сторінку кожного автора, дати, архіву й терміна, тож набір може набагато перевищити кількість постів. Досягнення межі не мовчазне — воно додає warning. Сам виклик discovery має власний 120-секундний timeout, інакше вкладка нескінченно показувала б «preparing». Кожен browser HEAD/GET має власний `AbortController` і 30-секундний timeout. Timeout або network failure переходить до protected server fallback; global Stop abort не запускає fallback і зупиняє весь цикл. Browser path чекає `retryDelay` (default 250 мс) перед другим циклом; server fallback використовує 30-second WordPress HTTP timeout без явної затримки. Redirect не вважається успішним прогрівом: browser Fetch відхиляє `response.redirected`, а loopback має `redirection=0`.

Warm request header `X-Simple-Redis-Cache-Warm: 1` лише примусово показує `X-Simple-Redis-Cache` для цієї відповіді. Він не змінює eligibility, canonical key, cookies або cache semantics. Effective debug flag передається через early-loader context до `Page_Capture`. На `send_headers` некешовний WordPress page type може замінити ранній `MISS` на `BYPASS`; пізніша відмова через status, response headers, incomplete HTML, lock або Redis може залишити header `MISS`. Тому лише наступний `HIT` підтверджує storage.

Якщо frontend і admin мають різні origins або direct Fetch падає на network layer, JS викликає `wp_ajax_simple_redis_cache_warm_url`. URL має HMAC-підпис для поточного user ID, повторно проходить same-home-origin validation (scheme, host, effective port), а loopback HEAD/GET не отримує admin cookies чи Authorization і не follow-ить redirects. Endpoint доступний тільки через POST, `manage_options` та nonce; `nopriv` hook немає.

Warmup не очищає generation і не перезаписує valid existing HIT. Invalid/obsolete payload ранній reader може best-effort видалити, після чого warmer здатний записати новий. Для повної регенерації адміністратор окремо очищує page cache перед запуском. `MISS` ніколи не вважається успіхом: late header/body/status validation або lock race можуть не записати payload. Вкладку треба тримати відкритою; cron/background continuation немає.

## 11. Admin UI та security

Сторінка: **Settings → Redis Cache**, slug `simple-redis-cache`. Capability — `manage_options`. Інтерфейс має п'ять вкладок; JavaScript завантажується лише для ручного прогріву:

- `redis` — підключення до Redis;
- `object` — Object Cache;
- `page` — HTML Page Cache;
- `warm` — source selection, live progress і verified manual warmup;
- `status` — live diagnostics, connection test і ручне очищення.

Menu slug залишається спільним, а Settings API sections реєструються на внутрішніх page IDs `simple-redis-cache-{tab}`. `tab` читається тільки як string, проходить `wp_unslash()`/`sanitize_key()` та allowlist; невідоме значення повертає `redis`.

Форми перших трьох вкладок передають marker `simple_redis_cache_settings[_settings_tab]`. `Admin::sanitize_settings()` зливає на сервері тільки надіслану групу з поточним повним config, після чого передає результат у `Config::sanitize()`. Якщо marker присутній, але tab поза allowlist `FORM_TABS` або відповідної групи немає в payload, злиття не відбувається: додається settings error `src_invalid_settings_tab` і повертається поточний збережений config без змін. Так збереження однієї вкладки не скидає дві інші, unchecked checkbox активної вкладки все одно стає `false`, а Redis password не копіюється в hidden HTML. Без marker зберігається сумісна поведінка sanitization повного payload. Вкладки `warm` і `status` не мають Settings API form і не виконують Save.

Hooks:

- `admin_menu`, `admin_init`, `admin_notices`, `admin_enqueue_scripts`;
- `admin_post_simple_redis_cache_test_redis`;
- `admin_post_simple_redis_cache_purge`;
- `wp_ajax_simple_redis_cache_prepare_warm`;
- `wp_ajax_simple_redis_cache_warm_url`;
- `admin_bar_menu` priority 100;
- `plugin_action_links_{basename}`.

Connection test і purge перевіряють capability та nonce. Test працює із вже збереженими, а не unsaved полями: після PING він створює ізольований plugin-prefixed key через `SET NX EX 30`, перевіряє точний GET і DELETE, не показуючи credentials. Optional `INFO server/memory` додає Redis version та `maxmemory_policy`; заборонений ACL-командами INFO не робить сам test невдалим.

Status diagnostics показує PhpRedis/connection, scheme/database без credentials, Redis version/policy, owned/foreign/missing drop-ins з абсолютними шляхами й octal modes, `WP_CACHE`, generated config ownership/readability/mode/synchronization fingerprint і фактичний prefix. Group/other-writable drop-in та config permissions ширші за `0640` або stricter позначаються warning. `allkeys-*` також дає рекомендацію `noeviction`/`volatile-*`.

`Config::get()` навмисно читає option прямим SQL, тому `render_field()` мемоізує результат у `Admin::$render_config` на час запиту: інакше кожне поле форми давало б окремий uncached запит. Кешувати всередині `Config` не можна — саме свіже читання дозволяє виявити паралельний Save.

Під час sanitization додається shutdown sync на `PHP_INT_MAX`, щоб повторний Save міг виправити permission/collision навіть коли option value не змінився. `Plugin::$last_config_hash` запобігає duplicate sync у тому самому request.

Admin bar parent називається `Caching`, веде на settings і має Clear all/page/object, Warm cache та Settings. Object/all links ведуть на scope-specific confirmation у Status; confirm form є POST + nonce. Page link лишається прямим scope-specific nonce URL.

### 11.1. Локалізація

Канонічна мова PHP-рядків — англійська, text domain — `simple-redis-cache`, bundled domain path — `/languages`. Англійська працює як source fallback без окремого MO. Український WordPress locale — саме `uk`, тому runtime-файл називається `simple-redis-cache-uk.mo`.

Усі видимі UI-рядки, tabs, labels, descriptions, notices, admin-bar actions, localized JavaScript strings і plugin-details modal мають використовувати gettext із точним domain. Machine values (`redis`, `object`, `page`, `warm`, `status`, option/action keys, Redis schemes) не перекладаються. Після зміни рядків потрібно заново згенерувати POT, синхронізувати PO, скомпілювати MO і перевірити placeholders та fuzzy entries.

Redis wrapper використовується ранніми drop-in до нормального plugin bootstrap, тому `error()` залишається dependency-free. `display_error()` локалізує лише власні стабільні повідомлення під час звичайного WordPress UI; невідомі exception messages PhpRedis повертаються без змін.

## 12. Drop-in ownership і `WP_CACHE`

Drop-in ownership marker: `Simple Redis Cache Drop-In`, перевіряються перші 16 KiB. Foreign target ніколи не перезаписується. Install використовує temp file у target directory, mode `0644`, повторний ownership check, atomic rename і OPcache invalidation. Remove видаляє тільки owned file.

Marker — heuristic, не signature. Не послаблювати перевірку й не видаляти foreign/unreadable target.

Коли page drop-in успішно встановлено, `ensure_wp_cache()`:

- якщо `WP_CACHE` уже truthy у поточному request, залишає його без аналізу expression;
- інакше залишає standalone `true/1`;
- замінює standalone `false/0` на `true`;
- відмовляється змінювати falsey dynamic/non-standard expression;
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

`settings_updated()` бере old runtime із plugin-owned generated config (fallback — sanitized old option), sanitize-ить new config і порівнює лише semantic policy subsets. Зміна object policy bump-ить активний object namespace; зміна page policy — активний page namespace. Transport tuning (`timeout`, `read_timeout`, `retry_interval`, `persistent`), page `debug_header` і page `shared_max_age` payload semantics не змінюють та generation не bump-ять. `shared_max_age` навмисно не входить у `$page_keys`: заголовок `Cache-Control: public, max-age=0, s-maxage=N` формується у `serve()` раннього loader і не зберігається в payload, тому нове значення діє вже на наступному HIT без інвалідації.

Зміна Redis scheme/endpoint/database/credentials, generated fallback prefix або site host/port вважається зміною storage identity. Активні шари best-effort bump-яться і на старому, і на новому target, щоб повернення до попереднього target не оживило старий namespace. Password входить лише в hashed identity і ніколи не в notice/log/key.

`WP_CACHE_KEY_SALT` читається як runtime constant і не зберігається разом з option, тому плагін не може відновити його попереднє значення після зовнішньої зміни. До зміни salt адміністратор має очистити потрібні шари під старим prefix або не повертати старе значення до expiry payloads.

Після успішної інвалідації атомарно пишеться early config, потім install/remove drop-ins. Якщо early write failed, обидва owned drop-ins видаляються.

Якщо хоча б один потрібний generation bump не вдався, `apply_saved_config()` не пише new semantics у ранній runtime. `defer_failed_invalidation()` повторно читає authoritative DB state і через `Config::runtime(true)` — фактичний generated runtime після потенційно довгого PhpRedis-виклику. Потім `defer_runtime_config()` зберігає останню trustworthy connection/site identity, консервативно ставить обидва `enabled=false`, видаляє обидва owned drop-in як другу safety net і показує error notice. Так паралельний Save не можна частково відкотити зі старою, але все ще активною політикою іншого шару. Saved DB option не відкочується. `Admin::sanitize_settings()` планує `apply_saved_config()` на shutdown для кожного прийнятого payload, навіть коли `update_option()` short-circuit-нув unchanged value (відхилений `_settings_tab` marker виходить раніше й нічого не планує); окрім того, наступний authorized admin bootstrap автоматично викликає `maybe_recover_runtime()`. Після successful retry new config атомарно записується, а потрібні drop-in повертаються. Один fingerprint old/new виконується щонайбільше раз за request.

`apply_saved_config_attempt()` оптимістично перечитує authoritative DB config до інвалідації, безпосередньо перед filesystem sync і після нього. Якщо concurrent Save змінив target snapshot, операція повторюється з актуальним config; після трьох нестабільних спроб обидва потенційно active layers переходять у той самий fail-safe disabled state до наступного authorized admin retry. Так повільніший recovery request не може надовго повернути старішу early config поверх новішого DB option.

Object settings invalidation перемикає Redis namespace, але не видаляє standard DB transient mirror автоматично. Для повного очищення адміністратор має окремо підтвердити manual object/all purge; прихованої destructive дії під час Save немає.

### Deactivation

1. Best-effort bump generations enabled layers.
2. Записати тимчасову early config copy з обома `enabled=false`.
3. Видалити owned drop-ins.
4. Видалити owned early config.

Збережені settings залишаються, тому reactivation відновлює flags. Redis payloads фізично не видаляються. `WP_CACHE` не змінюється. Помилка generation bump не блокує activation/deactivation; після Redis outage старі entries теоретично можуть знову стати доступними після recovery.

### Update

WordPress update не запускає activation hook. Тому normal `plugins_loaded` виконує versioned `maybe_upgrade()` до ініціалізації admin/updater hooks. Для старішої schema він normalize-ить option, інвалідує enabled cache semantics, регенерує early config і resync-ить templates. Поточна schema `3` додає opt-in targeted content invalidation settings і payload v3; у 0.4.0 в межах тієї самої schema `3` з'явився page `shared_max_age` — суто additive ключ, default якого підмішується через `Config::merge()`/`Early_Config::load()`, тому bump не був потрібен. Loaders у plugin directory оновлюються разом із кодом. Кожне майбутнє schema change повинно підняти `Early_Config::CONFIG_VERSION`, додати transform за потреби й розширити migration tests; суто additive ключ із безпечним default може лишитися в поточній schema, але це має бути свідомим рішенням.

### Uninstall

`uninstall.php` видаляє marker-owned drop-ins/config, settings option, notices option, notices user meta для всіх users і updater site transient разом із його standard DB value/timeout rows. OPcache інвалідується.

Не видаляються:

- `WP_CACHE` і `WP_CACHE_KEY_SALT`;
- Redis payloads/meta;
- решта DB transient rows, включно з cache-mirror та unrelated transients;
- foreign/unreadable файли;
- abandoned temp files.

Звичайний delete відбувається після deactivation, тому generation зазвичай уже bump-нуто.

## 14. Single-site defense in depth

- Activation відмовляється на multisite.
- `Plugin::init()` на multisite показує notices, але не запускає admin, updater, page invalidator або settings synchronization.
- Object loader повертається до core runtime cache.
- Advanced-cache loader і capture роблять bypass.
- Purger повертає `WP_Error`.
- `switch_to_blog()` лише очищає L1.

Не існує network option, per-blog prefix, правильних global-group semantics, network settings page або multi-table uninstall. Не «вмикати частково» multisite однією перевіркою; це окремий архітектурний проєкт.

## 15. GitHub updater

Repository: `vitaliikaplia/simple-redis-cache`, branch `master`. Remote version читається без GitHub API або token безпосередньо з main plugin file:

```text
https://raw.githubusercontent.com/vitaliikaplia/simple-redis-cache/master/simple-redis-cache.php
```

Package URL:

```text
https://github.com/vitaliikaplia/simple-redis-cache/archive/refs/heads/master.zip
```

Hooks: `pre_set_site_transient_update_plugins`, `site_transient_update_plugins`, `plugins_api`, `upgrader_source_selection` priority 11, `delete_site_transient_update_plugins`, `upgrader_process_complete`.

Фільтр транзієнта повертає значення без змін, якщо це не об'єкт або в ньому немає `checked`. Створювати порожній `stdClass` не можна: тоді core-ний `false` став би truthy, і `delete_plugins()` пішов би гілкою «update data є» та матеріалізував транзієнт без `last_checked`.

Успіх кешується site transient на 12 годин, failure — на 1 годину. Успішні metadata містять `version`, branch package URL, repository URL, `branch=master` і timestamp. Authorized admin `?force-check=1` обходить persistent cache; один updater instance робить не більше однієї remote attempt у request. HTTP: 10-second timeout, 3 redirects, 64 KiB limit, 2xx only. Версія читається з `Version:` і порівнюється через `version_compare()`.

`Update URI` у main header не дає WordPress.org підмінити same-slug plugin. `plugins_api` надає стандартну details modal. `upgrader_source_selection` приймає canonical root або будь-який root із префіксом `simple-redis-cache-` (зокрема `simple-redis-cache-master`) і перейменовує його у фактичну поточну installed directory, зберігаючи activation для custom folder. Інші root names не змінюються.

Updater працює лише поки plugin active. Він не самовільно вмикає auto-update: автоматична інсталяція відбудеться лише через нативний WordPress opt-in адміністратора.

Модель branch-based і mutable: немає GitHub API/token, tags, checksum, signature або pin до commit. Push між check та install може змінити package contents. Репозиторій мусить бути публічним, а transport і repository trust покладаються на GitHub HTTPS.

Release checklist:

1. однаково підняти header `Version`, `SIMPLE_REDIS_CACHE_VERSION` і release baseline у розділі 1 цього файла;
2. оновити `Stable tag`, changelog у `readme.txt` і hard-coded changelog поточної версії у стандартному WordPress plugin-details modal;
3. оновити POT/PO `Project-Id-Version`, скомпілювати MO та перевірити обидві локалі;
4. за зміни requirements синхронізувати main header, readmes та hard-coded updater fields;
5. перевірити ZIP root behavior;
6. закомітити й запушити coherent tree у `master`;
7. перевірити raw main file, branch package URL і WordPress update response.

Tag або GitHub Release поточному updater не потрібні: нова версія публікується самим push узгодженого дерева у `master`.

## 16. Відомі компроміси й ризики

- Загального auto purge немає: автоматично змінюються лише content tokens оновлених singular resources і/або пов'язаних public term archives, коли відповідні opt-in options увімкнені. Object cache та всі інші HTML-представлення можуть бути stale до TTL або manual purge; при ненульовому `page.shared_max_age` manual purge теж не дістає до shared cache.
- Late `DONOTCACHEPAGE`, визначений active plugin, зупиняє MISS storage, але не готовий anonymous early HIT. Для гарантованого bypass умова має бути доступна до `advanced-cache.php` або виражена path/query/cookie exclusion.
- Anonymous HIT не завантажує WordPress, DB, plugins або theme hooks. Будь-яка personalization повинна бути врахована в exclusions/variants.
- Anonymous output-buffer guard порівнює лише `ob_get_level()`, а не identity handlers; заміна stack зі збереженням тієї самої глибини не буде виявлена.
- Logged-in key не містить довільні user meta; logged-in cache ризикований для session state і disabled за замовчуванням. `shared_max_age` на logged-in HIT не надсилається взагалі, а за відсутнього збереженого `Cache-Control` runtime сам додає `private, no-store`, тож per-session HTML не потрапляє в shared cache навіть на сайті, який прибрав `nocache_headers`.
- Early cookie exclusions є name-based: stale/duplicate auth cookie після logout, `PHPSESSID` або інша configured session cookie продовжує давати BYPASS, доки browser її не прибере.
- Vary-cookie/ignored-query misconfiguration може об'єднати різні відповіді в один key.
- Cookie- та UA-рішення видимі лише origin: payload зберігається щонайбільше з `Vary: Accept-Encoding`, тож при ненульовому `page.shared_max_age` shared cache, що ключується за URL, може злити variants vary-cookies (типово мовні cookie WPML/Polylang) і віддати public-копію запиту, який на origin дав би BYPASS через excluded cookie або UA.
- Request `no-cache`, `max-age=0` і `Pragma: no-cache` навмисно не змушують сервер повторно рендерити сторінку; для гарантованого request bypass використовувати `no-store` або configured exclusion.
- Allowed `Set-Cookie` не replay-иться на HIT.
- Page payload не має size cap і зберігається uncompressed.
- TTL reduction не скорочує вже створені keys, але settings save перемикає affected generation, тому старі записи стають недоступні й доживають до попереднього TTL.
- Generation purge не звільняє пам'ять негайно.
- Object `expire=0` стає `max_ttl`, а не infinity.
- Confirmed manual object/all purge видаляє всі WordPress transients у поточній options table після успішного object bump.
- Credentials існують і в DB, і в generated config.
- Object payload декодується native `unserialize()` з objects; Redis має бути trusted/private і не приймати сторонніх writes.
- Кожна нова persistent group створює group-generation meta key без TTL; dynamic/unbounded group names можуть накопичувати permanent metadata.
- Hash `page-content-versions` не має TTL і накопичує по одному field для кожного відстеженого post/term resource; global page purge видаляє весь hash, а звичайна точкова інвалідація лише змінює відповідні fields.
- Discovery прогріву обмежене 20000 URL: на дуже великому сайті частину розділів доведеться гріти окремими запусками.
- Ownership marker не є криптографічною ідентичністю.
- `WP_CACHE` лишається після uninstall.
- GitHub package — mutable archive поточної гілки `master` без pin до commit, незалежної checksum або signature.
- Redis meta eviction створює новий random namespace і не відроджує generation `1`, але погіршує hit rate та лишає orphaned payload до TTL.
- Зовнішня зміна `WP_CACHE_KEY_SALT` не може автоматично bump-нути невідомий old prefix; повернення старого salt до TTL може знову відкрити його namespace, якщо його не очистили до зміни.
- Ненульовий `page.shared_max_age` віддає HTML shared cache, який плагін не вміє інвалідувати: `Cache-Control: public, max-age=0, s-maxage=N` додається лише на anonymous HIT у `serve()`, тоді як content tokens, generation bump і manual purge торкаються самого Redis. CDN продовжує віддавати стару сторінку до кінця `s-maxage`, включно з cached 404; очищення edge лишається ручною операцією оператора.
- `shared_max_age` навмисно не входить у `$page_keys` `invalidate_changed_settings()`: зміна або обнулення значення нічого не інвалідує й не відкликає копії, які shared cache уже зберіг зі старим `s-maxage`.
- Інвалідатор реєструє лише `post_updated` і `set_object_terms`, тому поза покриттям лишаються остаточне видалення поста, перейменування/злиття/видалення терміна, будь-яке `wp_remove_object_terms()` і публікація за розкладом через `wp_publish_post()`; їхній HTML лишається валідним до TTL чи manual page purge.
- `wp_cache_flush()` і `wp_cache_flush_group()` за ввімкненого DB mirror видаляють усі стандартні transients із `wp_options`, включно з чужими, і робить це будь-який виклик — наприклад WP-CLI `wp cache flush` — без окремого підтвердження, якого вимагають кнопки в адмінці. Рядки зберігаються, лише коли generation bump не вдався.

## 17. Правила внесення змін

- Ранні файли не повинні викликати WordPress APIs, які ще не гарантовано завантажені. Перевіряйте `function_exists`/`defined` або залишайте логіку dependency-free.
- Не переносити secret/config читання в constants. Redis settings залишаються в admin option/generated config.
- Не міняти key schema або payload version без migration/namespace versioning та purge plan.
- Кожен новий `page.*`/`object.*` ключ свідомо класифікувати в `invalidate_changed_settings()`: або додати у відповідний список, або задокументувати виняток. Ключі, що змінюють лише response headers і не входять у payload (`shared_max_age`), навмисно лишаються поза `$page_keys`.
- Не змінювати byte-for-byte поведінку `WP_CACHE_KEY_SALT` випадково.
- Не перезаписувати й не видаляти foreign drop-ins/config.
- Файлові заміни мають залишатися atomic, із temp у тому самому filesystem та OPcache invalidation.
- Не допускати uncaught Redis/serialization/filesystem exception у frontend runtime.
- Admin mutation потребує capability, nonce, sanitization і escaped output.
- Зміни page cache перевіряти окремо для anonymous/logged-in, GET/HEAD, HIT/MISS/BYPASS, query, cookies, headers, output buffers і races.
- Зміни object cache перевіряти для L1, Redis, non-persistent groups, DB mirror, force read, NX/XX і Redis outage.
- Не розширювати opt-in invalidation за межі documented singular і assigned public term archives на generic archives, comments, menus, arbitrary meta або WooCommerce events без прямої вимоги, окремого дизайну й документації. Ніколи не додавати destructive Redis flush.
- Не вважати activation hook migration hook: він не запускається при update. Schema transitions мають іти через versioned `maybe_upgrade()` та tests.
- Якщо змінено drop-in template path або plugin directory behavior, перевірити GitHub updater normalization.

## 18. Перевірки перед завершенням задачі

Dependency-light checks запускаються напряму через PHP й не завантажуються production runtime:

- `tests/config-migration.php` — schema upgrade, preservation, no downgrade;
- `tests/page-request.php` — cacheable path і encoded-control bypass;
- `tests/response-safety.php` — відсутність `s-maxage` на logged-in HIT, ігнорування відкинутого output-буфера, утримання lock відкритим capture, збереження DB transients при невдалому bump, обробка несеріалізовних значень, `replace()` для mirrored non-persistent групи і per-call семантика `error()`;
- `tests/admin-notice-routing.php` — маршрутизація повідомлень адміністратору незалежно від автора запиту та обмеження черги;
- `tests/warmer-discovery.php` — normalized deduplication URL прогріву й наявність межі discovery;
- `tests/page-capture-post-tracking.php` — payload association лише з enabled frontend-viewable `WP_Post`/`WP_Term`, без помилкового author/user ID;
- `tests/page-invalidator.php` — post/term hooks, old/new/parent terms, WPML/Polylang translations і fail-open warning;
- `tests/page-content-invalidation-integration.php` — post/term token init/bump/eviction recovery і reset під час global page bump;
- `tests/github-updater.php` — newer/current/invalid branch version, update response, cached branch metadata та archive-root normalization;
- `tests/generation-integration.php` — object/page/group random initialization та atomic bump на Redis;
- `tests/settings-invalidation-integration.php` — точкова object/page invalidation, non-semantic changes і old/new storage targets.
- `tests/settings-recovery-integration.php` — failed generation bump, fail-safe disabled runtime/drop-in, successful recovery після ремонту Redis metadata та concurrent Save під час failure path.

Redis scripts використовують унікальний random salt або fallback prefix, видаляють лише власні exact meta keys у `finally` та друкують `SKIP`, якщо PhpRedis/Redis недоступні. Вони ніколи не виконують `FLUSH*` або `KEYS`.

Мінімум:

```bash
find . -name '*.php' -type f -print0 | xargs -0 -n1 php -l
node --check assets/js/admin-warm-cache.js
php tests/config-migration.php
php tests/page-request.php
php tests/response-safety.php
php tests/admin-notice-routing.php
php tests/warmer-discovery.php
php tests/page-capture-post-tracking.php
php tests/page-invalidator.php
php tests/github-updater.php
php tests/page-content-invalidation-integration.php
php tests/generation-integration.php
php tests/settings-invalidation-integration.php
php tests/settings-recovery-integration.php
git diff --check
wp core version
wp plugin status simple-redis-cache
# Перевірити Redis через Status → Test saved connection;
# redis-cli використовувати лише з тими самими socket/TLS/AUTH/DB parameters.
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
- normal/hard refresh із `no-cache`, `max-age=0` або `Pragma: no-cache` може дати HIT;
- conditional/range/request `no-store` = BYPASS;
- redirects/private headers/Set-Cookie/Vary/content encoding;
- 404/search flags;
- concurrent MISS lock і TTL;
- manual page generation purge;
- із warmed singular HIT оновлення post/page/CPT дає наступний `MISS`, потім `HIT`, і так само інвалідує знайдені WPML/Polylang translations;
- зміна term relationships інвалідує old/new public term archives та hierarchical parents, але не unrelated archive;
- Redis failure під час content invalidation не ламає save, ставить warning і залишає попередній HTML доступним до TTL/manual purge;
- із ненульовим `shared_max_age` anonymous HIT містить `Cache-Control: public, max-age=0, s-maxage=N`, а MISS, BYPASS і будь-який logged-in HIT — ні; наявний у збереженій відповіді `Cache-Control` не перезаписується, а нуль не надсилає заголовка взагалі;
- сторінка, чий буфер відкинуто пізнім `ob_end_clean()` (maintenance mode, заміна відповіді), не потрапляє в кеш;
- за ввімкненого DB mirror і недоступного Redis `wp_cache_flush()` не видаляє transient-рядки й повертає `false`.

Для updater:

- raw `master` main file із newer/current/invalid version;
- перевірити `response` і `no_update`;
- plugin details modal data;
- success/failure cache;
- exact `archive/refs/heads/master.zip` package URL;
- normalization для canonical, `-master`, іншого slug-root і custom installed directory.

Для admin UI та локалізації:

- direct links, порядок `page → warm → status` і активний `aria-current` для всіх п'яти вкладок;
- `?tab=unknown` і `?tab[]=page` без TypeError повертають Redis-вкладку;
- Save кожної вкладки не змінює дві інші групи, включно з password retain/replace/clear;
- `warm` і `status` не містять Settings API form, а live PING не виконується на інших вкладках;
- public standard/custom post types і taxonomies видимі та checked by default, unavailable attachment source disabled;
- AJAX capability/nonce/malformed input, same-home-origin URL validation/signature та cross-origin fallback;
- warm probe `HEAD HIT`, `HEAD MISS → GET MISS → HEAD HIT`, second GET/HEAD retry, late BYPASS і repeated MISS;
- `simple-redis-cache-uk.mo` завантажується для admin locale `uk`, а `en_US` показує source English;
- у PO немає fuzzy/untranslated entries, а format placeholders збігаються з POT.

Після будь-якої зміни release metadata синхронізувати `simple-redis-cache.php`, `readme.txt`, `README.md`, `AGENTS.md`, gettext metadata та hard-coded updater requirements/details text.
