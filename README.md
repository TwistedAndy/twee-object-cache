# Twee Cache

**Twee Cache** is a suite of high-performance, lightweight object caches built for large-scale WordPress and WooCommerce projects. It provides drop-in replacements for WordPress's in-memory runtime cache, adding backend storage that persists between requests.

This repository contains the Twee Cache plugin with handlers for three backends: **APCu**, **Memcached**, and **Redis**. A handler also works as a drop-in without the plugin. The changes of each release are in [CHANGELOG.md](CHANGELOG.md).

## Installation

Twee Cache can be installed as a standard WordPress plugin or as a manual drop-in.

### 1. WordPress Plugin Installation (Recommended)

- Use PHP 8.0 or a newer version.
- Make sure that Redis or Memcached is reachable, or APCu is enabled, and install the PHP extension for it.
- Download `twee-cache-{version}.zip` from the [latest release](https://github.com/TwistedAndy/twee-cache/releases/latest), upload it in **Plugins → Add New → Upload Plugin**, and activate it. The plugin is installed in the `wp-content/plugins/twee-cache/` folder.
- As an administrator, open the **Object Cache** menu in the WordPress Admin Bar. When no drop-in is installed, it lists the backends whose PHP extensions are loaded.
- Select **Enable Redis**, **Enable Memcached**, or **Enable APCu**. The plugin examines if the backend is available, saves the settings, writes `wp-content/object-cache.php`, and flushes the cache. Disable the current drop-in before you select another backend.
- Select **Disable Cache** to remove the drop-in. When you deactivate the plugin, it removes the drop-in and `wp-content/cache-flush`. The saved settings stay for the next activation.
- Select **Settings** in the menu or in the Plugins list to open **Settings → Cache Settings**. On multisite, the page is in **Network Admin → Settings**. See [Configuration](#configuration).
- When a drop-in is installed, the menu also shows the engine, the hit ratio with the hits and misses, and the backend reads of the page. It also shows if the drop-in is connected. These values need no request to the backend. The [Query Monitor](#query-monitor) panel shows the memory, the keys and the latency of the backend. In wp-admin, the Admin Bar shows before most of the page, so the page values are not complete.
- When another plugin installed `wp-content/object-cache.php`, the menu shows **Another object cache is installed**, and the plugin does not change or remove that file.
- On multisite, only users with the `manage_network_options` capability see the menu, because the drop-in is shared by all sites of the network.

New releases show up in **Dashboard → Updates**. The drop-in loads the handler from the plugin folder, so an update also updates the cache code. When a request finds no handler, for example while an update replaces the plugin folder, WordPress uses its own cache, and the drop-in creates `wp-content/cache-flush`. The next request that finds the handler flushes the cache. Thus, the backend does not keep values that changed in the database in the meantime. A release that changes how the values are stored must also change the keys.

### 2. Manual Drop-in Installation

Use a handler without the plugin on a production site that does not need the **Cache Settings** page.

- Make sure that Redis or Memcached is reachable, or APCu is enabled, and install the PHP extension for it.
- Copy `object-cache.php` from `plugin/handlers/apcu/`, `plugin/handlers/memcached/`, or `plugin/handlers/redis/` to `wp-content/`.
- Set the values with constants in `wp-config.php`, such as `WP_REDIS_HOST` and `WP_REDIS_PASSWORD`. See [Configuration](#configuration). Without constants, the drop-in uses the defaults.
- WordPress detects the drop-in and starts the persistent cache.
- To update the drop-in, copy the new version. To flush the cache before the first read of the new version, create `wp-content/cache-flush`.
- When you activate the plugin later, it manages the copy as its own drop-in. **Save Changes** and **Enable Cache** replace it with the drop-in of the plugin.

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
- When a Redis write or delete fails, the old value stays in Redis. The drop-in then stops using Redis for the remainder of the request and creates the `cache-flush` marker.
- Backend initialization failures create WordPress admin notices. Runtime Redis errors use `E_USER_NOTICE`; Memcached and selected APCu errors use `E_USER_WARNING`. Display and logging follow the site's PHP and WordPress error configuration.
- On detected Redis or Memcached failures, the drop-in creates the `wp-content/cache-flush` marker. The Cache Settings page also creates it when it saves the settings or turns on the cache, and the drop-in of the plugin creates it when it finds no handler. On a later successful initialization, Redis deletes all keys with the key salt, Memcached changes the site version, and APCu flushes its keys before the marker is removed. This best-effort recovery requires the deployed drop-in directory to be writable.

## Statistics

The **Statistics** tab of the **Cache Settings** page shows the backend that the site uses now. It needs the cache to be on and connected. The tab reads the backend each time it opens, so it does not change the speed of other pages.

- The key salt and the server details, such as the version, the memory and the number of keys.
- The latency: the average, minimum, 95th percentile and maximum time of 100 writes and 100 reads of a 1 KB value. The writes and reads use the serializer and the compression of the handler.
- The cache groups of the site, with the number of keys and the size of each group. A page shows 100 groups, and the largest group shows first.
- Select a group to see its keys, with the stored size, the expiration time and the value. A page shows 100 keys, and a value shows its first 10,000 characters.
- To delete groups or keys, select their checkboxes, then select **Clean Selected**. A group is cleaned with `wp_cache_flush_group()`, and keys with `wp_cache_delete_multiple()`. WordPress and the plugins make a deleted value again when they need it.

The values can contain private data, such as the options of plugins. Only a user who can change the settings can open the tab.

The backends give the data in different ways:

- **Redis** keeps each group in a hash. The size of a group is the memory of the hash, which Redis estimates from 5 of its fields (`MEMORY USAGE`). The estimate does not block Redis on a large group.
- **APCu** gives the memory of each key.
- **Memcached** cannot list its keys through the PHP extension, so the tab sends `lru_crawler metadump all` to each server. This command needs Memcached 1.4.31 or later. A flush of the site or of a group changes its version, and the keys of old versions stay until they expire or Memcached evicts them. The tab shows them as stale keys.

## Query Monitor

When [Query Monitor](https://wordpress.org/plugins/query-monitor/) is active, the plugin replaces its **Object Cache** panel. The panel shows data that the drop-in collects on each request, so it does not make the cache slower:

- The engine, and if the backend is connected.
- The hits, misses, hit rate, sets and backend reads of the runtime cache.
- The key salt and the server details, such as the version, the memory, the number of keys, and the latency of one request.
- The errors of the request.
- The groups in the runtime cache, with their type and number of entries.

The panel needs the plugin. With a handler copied by hand and no plugin, Query Monitor shows its own panel. Query Monitor shows the panels of plugins after its own panels, so a small script moves **Object Cache** below **Database Queries** in both Query Monitor menus.

## Configuration

Configuration values are optional when the built-in local defaults suit the environment. Set them on the **Cache Settings** page, or define constants in `wp-config.php` before WordPress loads the object-cache drop-in. A handler copied by hand reads the constants only.

- The page saves the settings in the `toc_settings` site option. A key is the name of its constant in lower case and without `WP_`, such as `redis_host` for `WP_REDIS_HOST`.
- When the cache is on, the plugin writes `wp-content/object-cache.php`. This file holds a copy of the settings in the `TWEE_CACHE_CONFIG` constant and loads the handler from `handlers/<engine>/object-cache.php` in the plugin folder. OPcache keeps it in memory. When the plugin folder does not exist, WordPress uses its own cache.
- A constant in `wp-config.php` wins over the value on the page. The page shows the field as read-only.
- **Test Connection** in the section of the handler connects to the backend with the values of the form, then writes, reads and deletes a test key. It does not save the values. When the test fails, the message gives the reason. For example, when Redis needs a password and the **Password** field is empty, the message tells where to find the password. A failed Memcached test names the servers and the reason, such as a timeout.
- When the cache is on, **Save Changes** tests the new values first and saves them only when the test passes. When the test fails, the form keeps the values that you typed. After a save, the plugin writes the drop-in again with the new values.
- **Enable Cache** tests the saved values and installs the drop-in of the selected handler. **Disable Cache** removes the drop-in.
- After **Save Changes** and **Enable Cache**, the drop-in flushes the cache before its first read. Thus, values in an old format or from another backend do not stay in the cache.
- The page finds the settings of the host and of other object cache plugins each time it opens. When no settings are saved, the form shows the found settings, and **Enable Cache** saves them. When a found value is different from the value of the form, it shows under the field. The page does not show a found password. See [Detection](#detection).
- When the PHP extensions of the server do not support igbinary, compression or Relay, the page turns off the switch, shows it as unavailable, and does not suggest a found value for it.
- **Reset Connection** in the Redis and Memcached sections fills the connection fields with the found values or the defaults: the host, the port, the user, the password, the database, the timeouts, the retry interval and the Memcached servers. The other fields keep their values. It does not save the values.
- The time fields show their unit after the field: `s` for the Redis timeout and `ms` for the other timeouts and the retry interval.
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

On the default ports, the page sends `PING` to Redis and `version` to Memcached. It uses a port only when the server answers. When Redis answers `NOAUTH`, the sources show "Redis needs a password". Then enter the password in the **Password** field, or define `WP_REDIS_PASSWORD` in `wp-config.php`. The page and **Enable Cache** do this check. Other requests do not.

## Development

```
├── plugin/                    The plugin, as it is installed in wp-content/plugins/twee-cache/
│   ├── twee-cache.php         Loads the includes and the settings page in wp-admin, adds the hooks, writes the drop-in again on activation, removes it on deactivation
│   ├── handlers/              The handlers that the drop-in loads: apcu/, memcached/, redis/, each with object-cache.php
│   ├── assets/                Styles of the Cache Settings page: scss/ sources, css/ built with gulp or sass
│   └── includes/
│       ├── admin.php          Admin Bar menu and its actions
│       ├── dropin.php         Drop-in file, engine detection, Query Monitor connection
│       ├── groups.php         Latency test and cache groups of the Statistics tab, loaded only on this tab
│       ├── monitor.php        Query Monitor panel
│       ├── settings.php       Cache Settings page, the toc_settings option
│       └── update.php         Updates from GitHub, loaded only when WordPress examines the updates
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

- Change the `Version` header in `plugin/twee-cache.php`.
- Describe the release in `CHANGELOG.md` under a `## {version}` heading.
- Commit and push.
- Run `composer release` to build the ZIP and publish release `v{version}`. The section of the version in `CHANGELOG.md` becomes the release notes.
- Run `composer zip` to build `build/twee-cache-{version}.zip` only. This ZIP also has the uncommitted changes of the tracked files, so you can install the current code on a local site.

The release script needs the [GitHub CLI](https://cli.github.com), signed in with `gh auth login`. It stops when the `Version` header is missing, `CHANGELOG.md` has no section for the version, there are uncommitted changes, `HEAD` is not pushed, or the release exists.

### Working on a Site

Link the `plugin/` folder into a site instead of copying it. On Windows, run this in an admin prompt or with Developer Mode on:

```bash
cmd /c mklink /D "D:\Server\domains\work\site\wp-content\plugins\twee-cache" "D:\Work\wp-content\plugins\twee-cache\plugin"
```

WordPress never offers an update for a linked plugin folder, so an update cannot replace the working copy. The drop-in loads the handler from the linked folder, so a change in a handler starts with the next request.

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
