# Twee Cache

**Twee Cache** is a suite of high-performance, lightweight object caches built for large-scale WordPress and WooCommerce projects. It provides drop-in replacements for WordPress's in-memory runtime cache, adding backend storage that persists between requests.

This repository contains caching plugins for three different backends: **APCu**, **Memcached**, and **Redis**. The changes of each release are in [CHANGELOG.md](CHANGELOG.md).

## Installation

Twee Cache can be installed as a standard WordPress plugin or as a manual drop-in.

### 1. WordPress Plugin Installation (Recommended)

- Use PHP 8.0 or a newer version.
- Make sure that Redis or Memcached is reachable, or APCu is enabled, and install the PHP extension for it.
- Download `twee-cache-{version}.zip` from the [latest release](https://github.com/TwistedAndy/twee-cache/releases/latest), upload it in **Plugins → Add New → Upload Plugin**, and activate it. The plugin is installed in the `wp-content/plugins/twee-cache/` folder.
- As an administrator, open the **Object Cache** menu in the WordPress Admin Bar. When no drop-in is installed, it lists the backends whose PHP extensions are loaded.
- Select **Enable Redis**, **Enable Memcached**, or **Enable APCu**. The plugin examines if the backend is available, copies the selected drop-in to `wp-content/object-cache.php`, and flushes the cache. Disable the current drop-in before you select another backend.
- Select **Disable Cache** to remove the drop-in. When you deactivate the plugin, it also removes the drop-in.
- Select **Settings** to open **Settings → Cache Settings**. On multisite, the page is in **Network Admin → Settings**. See [Configuration](#configuration).
- When a drop-in is installed, the menu also shows the engine, the hit ratio with the hits and misses, and the backend reads of the page. It also shows the memory and the number of keys of the backend, and the latency of one request to it. The menu sends one or two requests to the backend to get these values. In wp-admin, the Admin Bar shows before most of the page, so the page values are not complete.
- When another plugin installed `wp-content/object-cache.php`, the menu shows **Another object cache is installed**, and the plugin does not change or remove that file.
- On multisite, only users with the `manage_network_options` capability see the menu, because the drop-in is shared by all sites of the network.

New releases show up in **Dashboard → Updates**. After an update, the plugin replaces the drop-in with the new version, and the new drop-in flushes the cache before its first read. When you replace the plugin files manually, select **Disable Cache** and then enable the backend again to replace the drop-in.

### 2. Manual Drop-in Installation

- Make sure that Redis or Memcached is reachable, or APCu is enabled, and install the PHP extension for it.
- Copy `plugin/handlers/apcu.php`, `plugin/handlers/memcached.php`, or `plugin/handlers/redis.php` to `wp-content/object-cache.php`.
- WordPress detects the drop-in and starts the persistent cache.

## Features

### 1. High-Performance Integration

- Uses the corresponding PHP extension for process-host-local shared memory with APCu or external Redis and Memcached services.
- Can reduce repeated database reads for WordPress data routed through the object-cache API, including options, transients, and metadata.
- Uses Redis hash bulk operations (`hMGet` and `hMSet`) and Memcached `getMulti`; Memcached bulk writes process each item individually so oversized values can use chunking.
- Memcached compresses values with FastLZ.
- If `apcu_store()` fails while APCu remains enabled, the drop-in deletes the backend key so a later request loads a fresh value.
- When Memcached rejects a `set`, `add`, or `replace` value as too large, the drop-in retries by serializing it into 500,000-byte chunks.

### 2. Dual-Layer Caching Engine

- Implements an internal runtime cache array to prevent redundant backend round-trips during a single page load.
- Caps each group's runtime cache at 10,000 entries for Redis and Memcached or 100,000 entries for APCu, evicting the earliest entries when the limit is exceeded.
- Provides WordPress's procedural `wp_cache_*` API through a `WP_Object_Cache` implementation.

### 3. Advanced Group Management

- Supports global cache groups shared across sites in a multisite installation.
- Keeps groups registered through `wp_cache_add_non_persistent_groups()` in runtime memory only, except protected groups. The `plugins`, `themes`, and `counts` groups are protected as persistent by default.
- The **Redis** implementation stores groups in Redis hashes and uses hash operations such as `hSet`, `hGet`, and `hDel`.
- On Redis 7.4 or later, Redis deletes expired hash fields with `HEXPIREAT`. On older versions, the drop-in deletes an expired field when a request reads it.
- Redis keys contain the key salt, the format and the group, such as `salt:iz:options` for igbinary with zstd. The format is `i` for igbinary or `p` for the PHP serializer, then `z` for zstd, `l` for LZ4 or `f` for LZF. A request that started before a format change writes to the keys of the old format, so the requests with the new format do not read its values.
- Redis `wp_cache_flush()` deletes only the keys with the key salt, in all formats, so other sites in the same database keep their cache.
- The **Memcached** implementation namespaces keys by installation, blog, and group, and uses per-group version counters for logical group flushing.
- Memcached keys include a site version. `wp_cache_flush()` changes this version, so other sites on the same servers keep their cache.
- Memcached reads a TTL of more than 30 days as a Unix time, so the drop-in converts such a TTL to a Unix time.

### 4. Multisite & Context Compatibility

- Prefixes non-global groups with the current blog ID in multisite installations; registered global groups omit the blog prefix.
- Supports `wp_cache_switch_to_blog()` while preserving global runtime entries and isolating blog-scoped entries.
- Uses `WP_CACHE_KEY_SALT` to keep web, Cron, and WP-CLI requests in the same namespace when their detected host values differ. The default salt uses the database name and the table prefix, so all requests to one site use the same namespace.

### 5. Reliability

- Recognized Memcached operation errors disable backend access for the remainder of the request while leaving the runtime cache available. Selected APCu failures do the same when they indicate APCu has become disabled.
- When a Redis command fails and Redis does not reply to `PING`, the drop-in stops using Redis for the remainder of the request.
- When a Redis write or delete fails, the old value stays in Redis. The drop-in then stops using Redis for the remainder of the request and creates the `oc-flush` marker.
- Backend initialization failures create WordPress admin notices. Runtime Redis errors use `E_USER_NOTICE`; Memcached and selected APCu errors use `E_USER_WARNING`. Display and logging follow the site's PHP and WordPress error configuration.
- On detected Redis or Memcached failures, and when the Cache Settings page saves the settings or turns on the cache, the plugin creates an internal `oc-flush` marker. On a later successful initialization, Redis deletes all keys with the key salt, Memcached changes the site version, and APCu flushes its keys before the marker is removed. This best-effort recovery requires the deployed drop-in directory to be writable.

## Query Monitor

When [Query Monitor](https://wordpress.org/plugins/query-monitor/) is active, the plugin replaces its **Object Cache** panel. The panel shows data that the drop-in collects on each request, so it does not make the cache slower:

- The engine, and if the backend is connected.
- The hits, misses, hit rate, sets and backend reads of the runtime cache.
- The key salt and the server details, such as the version, the memory, the number of keys, and the latency of one request.
- The errors of the request.
- The groups in the runtime cache, with their type and number of entries.

Turn on **Tracing** on the **Cache Settings** page to log each backend call. Then the panel also shows the number of backend calls, their total time, and their share of the request time. The **Commands** panel under it lists each call with its key, caller, component and time. You can filter the list by command and by component.

- When tracing is off, the panels show a message about it.
- The log stops at `plugins_loaded` when Query Monitor is not active.
- Without the log, each backend call adds two boolean checks. Runtime cache hits do not change.
- The log records a backtrace for each backend call, so use it only on development sites.
- The panels need the plugin. A drop-in that you installed manually shows the panel of Query Monitor.
- Query Monitor shows the panels of plugins after its own panels. A small script moves **Object Cache** below **Database Queries** in both Query Monitor menus.

## Configuration

Configuration values are optional when the built-in local defaults suit the environment. Set them on the **Cache Settings** page, or define constants in `wp-config.php` before WordPress loads the object-cache drop-in.

- The page writes the settings to `wp-content/oc-config.php`, a PHP file that returns an array. The drop-in reads it once on each request. OPcache keeps it in memory.
- A constant in `wp-config.php` wins over the value on the page. The page shows the field as read-only.
- **Test** connects to the backend with the values of the form, then writes, reads and deletes a test key. It does not save the values.
- When the cache is on, **Save Changes** tests the new values first and saves them only when the test passes. When the test fails, the form keeps the values that you typed. When you select another handler, it replaces the drop-in.
- **Turn On** tests the saved values and installs the drop-in of the selected handler. **Turn Off** removes the drop-in.
- After **Save Changes** and **Turn On**, the drop-in flushes the cache before its first read. Thus, values in an old format or from another backend do not stay in the cache.
- **Detect** fills the form with the settings of the host and of other object cache plugins. It does not save the values. When `oc-config.php` does not exist, the page shows the detected settings, and **Turn On** saves them. See [Detection](#detection).
- **Tracing** logs the backend calls for the Query Monitor panel. See [Query Monitor](#query-monitor).
- On a site with more than one web server, the page changes the files only on the server that handles the request. Use constants on these sites.

### All backends

- `WP_CACHE_KEY_SALT` - Cache namespace. When omitted, the drop-in uses `DB_NAME` and `$table_prefix`, and replaces characters other than letters, digits and underscores with underscores. Redis does not truncate the salt. Memcached and APCu replace a salt of more than 20 characters with its first 12 characters and 8 characters of its MD5 hash.

### Redis

- `WP_REDIS_HOST` - Redis host or Unix socket. Default: `127.0.0.1`.
- `WP_REDIS_PORT` - Redis port. Default: `6379`.
- `WP_REDIS_TIMEOUT` - Connection and read timeout in seconds. Default: `1`.
- `WP_REDIS_RETRY_INTERVAL` - Retry interval in milliseconds. Default: `100`.
- `WP_REDIS_USERNAME` - Redis 6 ACL user. The drop-in sends it with `WP_REDIS_PASSWORD`.
- `WP_REDIS_PASSWORD` - Redis authentication password.
- `WP_REDIS_DATABASE` - Redis database number.
- `WP_REDIS_PERSISTENT_CONNECT` - Enable persistent connections. Default: enabled. Each database and password uses a separate persistent connection. The drop-in selects the database on each request, because phpredis can give a pooled connection that another site used.
- `WP_REDIS_PERSISTENT_GROUPS` - Comma-separated string or array replacing the protected-group list. Protected groups ignore requests to make them non-persistent. Default: `plugins`, `themes`, and `counts`.
- `WP_REDIS_IGBINARY` - Enable igbinary serialization when available. Default: enabled.
- `WP_REDIS_COMPRESSION` - Enable Redis compression when available. The drop-in uses zstd, then LZ4, then LZF. Default: enabled.
- `WP_REDIS_USE_RELAY` - Use `Relay\Relay` when Relay is available. Default: disabled. The current drop-in still requires the phpredis `Redis` class.

### Memcached

- `$memcached_servers` - WordPress global containing the Memcached server list. Default: `[['127.0.0.1', 11211]]`. The drop-in also accepts `host:port` and `unix://path` strings, and a list in a `default` bucket, as on WP Engine.
- `WP_MEMCACHED_PERSISTENT_GROUPS` - Comma-separated string or array replacing the protected-group list. Protected groups ignore requests to make them non-persistent. Default: `plugins`, `themes`, and `counts`.
- `WP_MEMCACHED_CONNECT_TIMEOUT` - Connection timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_SEND_TIMEOUT` - Send timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_RECV_TIMEOUT` - Receive timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_IGBINARY` - Enable igbinary serialization when available. Default: enabled. The memcached extension marks the serializer of each value, so it also reads the values that it saved before with the PHP serializer.

### APCu

- `WP_APCU_PERSISTENT_GROUPS` - Comma-separated string or array replacing the protected-group list. Protected groups ignore requests to make them non-persistent. Default: `plugins`, `themes`, and `counts`.

### Detection

The page reads these sources in this order. A value from an earlier source wins. A constant of Twee Cache in `wp-config.php` wins over all of them.

| Source | Hosts and plugins | Values |
| --- | --- | --- |
| `WP_REDIS_HOST` | Nexcess, Redis Object Cache | Selects Redis |
| `WP_REDIS_CONFIG` | Object Cache Pro, Nexcess, Cloudways | Host, port, database, user, password, timeout, retry interval, prefix, compression, serializer |
| `$redis_server` | WP Redis, Pantheon | Host, port, password, database |
| `WP_REDIS_SERVERS`, `WP_REDIS_PATH`, `WP_REDIS_PREFIX`, `WP_REDIS_SERIALIZER` | Redis Object Cache | First server, socket, prefix, serializer |
| `REDIS_URL`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `CACHE_HOST`, `CACHE_PORT`, `CACHE_PASSWORD` | Heroku, Pantheon | Redis connection |
| Redis sockets | Nexcess (`/var/run/redis-multi-{account}.redis/redis.sock`), `/var/run/redis/redis.sock` and others | Redis socket |
| `$memcached_servers`, `WPE_CACHE_HOST` | WP Engine, Memcached plugins | Memcached servers |
| Memcached sockets | `/tmp/memcached.sock`, `/var/run/memcached/memcached.sock` | Memcached socket |
| `127.0.0.1:6379`, `127.0.0.1:11211` | SiteGround and other hosts with default ports | Redis, then Memcached |

The page selects Redis when it finds a Redis host, and Memcached when it finds only Memcached servers. The prefix of another plugin becomes the key salt.

## Development

```
├── plugin/                    The plugin, as it is installed in wp-content/plugins/twee-cache/
│   ├── twee-cache.php         Loads the includes, adds the hooks, replaces an older drop-in on activation, removes it on deactivation
│   ├── handlers/              The drop-ins: apcu.php, memcached.php, redis.php
│   ├── assets/                Styles of the Cache Settings page: scss/ sources, css/ built with gulp or sass
│   └── includes/
│       ├── admin.php          Admin Bar menu and its actions
│       ├── dropin.php         Drop-in install, engine detection, Query Monitor connection
│       ├── monitor.php        Query Monitor panels
│       ├── settings.php       Cache Settings page, oc-config.php
│       └── update.php         Updates from GitHub, drop-in replacement after an update
├── tests/                     PHPUnit integration tests
├── bin/release.php            Builds and publishes releases
└── CHANGELOG.md               Release notes
```

### Tests

- Run `composer install`.
- Run a backend suite with `composer test:redis`, `composer test:memcached`, or `composer test:apcu`. These scripts use the Windows `set` command. On other systems, run `TW_CACHE_ENGINE=redis composer test`.
- The suite loads each drop-in directly with lightweight WordPress function stubs and exercises it against the selected live backend. It does not boot a complete WordPress installation.
- Shared coverage includes core `wp_cache_*` operations, runtime and backend persistence, bulk operations, expiration, global and non-persistent groups, group flushing, multisite isolation, and blog switching. Memcached-specific cases also cover oversized payload chunking.
- Tests skip when the selected PHP extension or backend is unavailable.

### Releases

Sites update from the ZIP attached to the latest GitHub release.

- Change the `Version` header in `plugin/twee-cache.php` and in the three drop-ins in `plugin/handlers/` to the same value.
- Describe the release in `CHANGELOG.md` under a `## {version}` heading.
- Commit and push.
- Run `composer release` to build the ZIP and publish release `v{version}`. The section of the version in `CHANGELOG.md` becomes the release notes.
- Run `composer zip` to build `build/twee-cache-{version}.zip` only.

The release script needs the [GitHub CLI](https://cli.github.com), signed in with `gh auth login`. It stops when the versions are different, `CHANGELOG.md` has no section for the version, there are uncommitted changes, `HEAD` is not pushed, or the release exists.

### Working on a Site

Link the `plugin/` folder into a site instead of copying it. On Windows, run this in an admin prompt or with Developer Mode on:

```bash
cmd /c mklink /D "D:\Server\domains\work\site\wp-content\plugins\twee-cache" "D:\Work\wp-content\plugins\twee-cache\plugin"
```

WordPress never offers an update for a linked plugin folder, so an update cannot replace the working copy. The drop-in in `wp-content` is a copy, so select **Disable Cache** and enable the backend again after you change a drop-in.

## About

Author: Andrii Toniievych

Contact: [toniyevych@gmail.com](mailto:toniyevych@gmail.com)

Feel free to contact me if you have any questions.

## Contribution

* Fork this repository
* Commit your changes
* Push it to the branch
* Create the new pull request

## License

**Twee** is released under the MIT Public License.

Note: The "About" section in `README.md` and the author (`@author`) notice in the file-headers shall not be edited or deleted without permission. Thank you!
