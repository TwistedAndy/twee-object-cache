# Twee Cache

Twee Cache is a persistent object cache for WordPress and WooCommerce. It keeps the values of the WordPress object cache in **Redis**, **Memcached** or **APCu**, so the next requests read them from memory instead of the database.

The plugin installs and configures the `object-cache.php` drop-in. Each backend also has a handler that works as a drop-in without the plugin. The changes of each release are in [CHANGELOG.md](CHANGELOG.md).

## Requirements

- PHP 8.0 or a newer version.
- One of these backends, with its PHP extension:
  - Redis and the phpredis extension. Redis 7.4 or later deletes expired hash fields itself.
  - Memcached and the memcached extension. The **Statistics** tab needs Memcached 1.4.31 or later.
  - The APCu extension, enabled.
- Optional: igbinary for a smaller and faster serializer, and zstd, LZ4 or LZF support in phpredis for compression.

## Installation

### Plugin

1. Download `twee-cache-{version}.zip` from the [latest release](https://github.com/TwistedAndy/twee-cache/releases/latest).
2. Upload it in **Plugins → Add New → Upload Plugin**, and activate it.
3. In the Admin Bar, open the **Object Cache** menu.
4. Select **Enable Redis**, **Enable Memcached** or **Enable APCu**. The menu shows only the backends with a loaded PHP extension.

The plugin then makes sure that the backend is available, saves the settings, writes `wp-content/object-cache.php`, and flushes the cache.

- To change the backend, select **Disable Cache** first, then enable the other backend.
- To change the connection, open **Settings → Cache Settings**. On multisite, the page is in **Network Admin → Settings**. See [Cache Settings](#cache-settings).
- When you deactivate the plugin, it removes the drop-in and `wp-content/cache-flush`. The saved settings stay for the next activation.
- If another plugin installed `wp-content/object-cache.php`, the menu shows **Another object cache is installed**. Twee Cache does not change or remove that file.

### Drop-in without the plugin

Use a handler without the plugin on a site that does not need the **Cache Settings** page.

1. Copy `object-cache.php` from `plugin/handlers/redis/`, `plugin/handlers/memcached/` or `plugin/handlers/apcu/` to `wp-content/`.
2. If the defaults do not suit the server, define the [constants](#configuration-constants) in `wp-config.php`.

WordPress then loads the drop-in and starts to use the cache. To update the drop-in, copy the new version, and create `wp-content/cache-flush` to flush the cache before its first read.

If you activate the plugin later, it uses the copy as its own drop-in. **Save Changes** and **Enable Cache** replace the copy with the drop-in of the plugin.

### Updates

New releases show in **Dashboard → Updates**. The drop-in of the plugin loads the handler from the plugin folder, so an update of the plugin also updates the cache code.

While an update replaces the plugin folder, the drop-in finds no handler. Then WordPress uses its own cache, and the drop-in creates `wp-content/cache-flush`. The next request that finds the handler flushes the cache, so the backend does not keep values that changed in the database.

## Admin Bar menu

The **Object Cache** menu shows to administrators. On multisite, only users with the `manage_network_options` capability see it, because all sites of the network use the same drop-in.

- **Clean Cache** flushes the cache of the site.
- The engine, and if the drop-in is connected to the backend.
- The hit ratio with the hits and misses, and the backend reads of the page, such as **Redis reads**.
- With the plugin: **Settings**, and **Disable Cache**.

These values need no request to the backend. In wp-admin, the Admin Bar shows before most of the page, so the values of the page are not complete.

## Cache Settings

The **Cache Settings** page saves the settings in the `toc_settings` site option. A key is the name of its constant in lower case and without `WP_`, such as `redis_host` for `WP_REDIS_HOST`.

When the cache is on, the plugin writes the settings into `wp-content/object-cache.php` as the `TWEE_CACHE_CONFIG` constant. The drop-in then loads the handler from `handlers/<engine>/object-cache.php` in the plugin folder. OPcache keeps the file in memory, so the settings need no database query.

- **Test Connection** connects to the backend with the values of the form, then writes, reads and deletes a test key. It does not save the values. When the test fails, the message gives the reason, such as a missing Redis password or a Memcached timeout.
- **Save Changes** tests the new values when the cache is on, and saves them only when the test passes. Then the plugin writes the drop-in again.
- **Enable Cache** tests the saved values and installs the drop-in. **Disable Cache** removes the drop-in.
- **Reset Connection** fills the connection fields of Redis or Memcached with the found values or the defaults. It does not save the values.
- After **Save Changes** and **Enable Cache**, the drop-in flushes the cache before its first read. Thus, values in an old format or from another backend do not stay in the cache.
- A constant in `wp-config.php` wins over the value on the page, and the page shows its field as read-only.
- If the PHP extensions do not support igbinary, compression or Relay, the page shows the switch as unavailable.

On a site with more than one web server, the page changes the files only on the server that handles the request. Use constants on these sites.

### Detection

Each time the page opens, it finds the settings of the host and of other object cache plugins. When no settings are saved, the form shows the found settings, and **Enable Cache** saves them. When a found value is different from the value of the form, the page shows it under the field. The page does not show a found password.

The page reads these sources in this order. A value from an earlier source wins. A Twee Cache constant in `wp-config.php` wins over all of them.

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

On the default ports, the page sends `PING` to Redis and `version` to Memcached, and uses a port only when the server answers. When Redis answers `NOAUTH`, the page shows "Redis needs a password". Then enter the password in the **Password** field, or define `WP_REDIS_PASSWORD`. Only the page and **Enable Cache** do this check.

### Statistics

The **Statistics** tab shows the backend that the site uses now. It needs the cache to be on and connected. The tab reads the backend only when it opens, so it does not make other pages slower.

- The key salt and the server details, such as the version, the memory and the number of keys.
- The latency: the average, minimum, 95th percentile and maximum time of 100 writes and 100 reads of a 1 KB value, with the serializer and the compression of the handler.
- The cache groups of the site, with the number of keys and the size of each group. The largest group shows first.
- The keys of a group, with the stored size, the expiration time and the first 10,000 characters of the value.
- **Clean Selected** deletes the selected groups with `wp_cache_flush_group()`, or the selected keys with `wp_cache_delete_multiple()`.

The values can contain private data, such as the options of plugins. Only a user who can change the settings can open the tab.

The backends give the data in different ways:

- **Redis** keeps each group in a hash. The size of a group is an estimate from 5 of its fields (`MEMORY USAGE`), so the tab does not block Redis on a large group.
- **APCu** gives the memory of each key.
- **Memcached** cannot list its keys through the PHP extension, so the tab sends `lru_crawler metadump all` to each server. A flush changes the version of the site or the group. The keys of old versions stay until they expire or Memcached evicts them, and the tab shows them as stale keys.

## Query Monitor

When [Query Monitor](https://wordpress.org/plugins/query-monitor/) is active, the plugin replaces its **Object Cache** panel. The panel uses the data that the drop-in collects on each request, so it does not make the cache slower:

- The engine, and if the backend is connected.
- The hits, misses, hit rate, sets and backend reads.
- The key salt, the server details and the latency of one request.
- The errors of the request.
- The groups in the runtime cache, with their type and number of entries.

The panel shows below **Database Queries** in the Query Monitor menus. It needs the plugin. Without the plugin, Query Monitor shows its own panel.

## Configuration constants

All values are optional. Define the constants in `wp-config.php`, before WordPress loads the drop-in. A drop-in without the plugin reads only the constants.

### All backends

- `WP_CACHE_KEY_SALT` - The namespace of the cache keys. Default: `DB_NAME` and `$table_prefix`, so the web, Cron and WP-CLI requests of a site use the same keys. Characters other than letters, digits and underscores change to underscores. Memcached and APCu change a salt of more than 20 characters to its first 12 characters and 8 characters of its MD5 hash.

### Redis

- `WP_REDIS_HOST` - Redis host or Unix socket. Default: `127.0.0.1`.
- `WP_REDIS_PORT` - Redis port. Default: `6379`.
- `WP_REDIS_TIMEOUT` - Connection and read timeout in seconds. Default: `1`.
- `WP_REDIS_RETRY_INTERVAL` - Retry interval in milliseconds. Default: `100`.
- `WP_REDIS_USERNAME` - Redis 6 ACL user. All keys start with the key salt and a colon, so an ACL can allow only these keys, such as `~mysite:*` for the salt `mysite`.
- `WP_REDIS_PASSWORD` - Redis password.
- `WP_REDIS_DATABASE` - Redis database number. Default: `0`.
- `WP_REDIS_PERSISTENT_CONNECT` - Use persistent connections. Default: enabled. Each database and password uses a separate connection.
- `WP_REDIS_PERSISTENT_GROUPS` - A comma-separated string or an array of [protected groups](#groups). Default: `plugins`, `themes` and `counts`.
- `WP_REDIS_IGBINARY` - Use igbinary when it is available. Default: enabled.
- `WP_REDIS_COMPRESSION` - Compress the values with zstd, LZ4 or LZF, in this order, when phpredis supports it. Default: enabled.
- `WP_REDIS_USE_RELAY` - Use `Relay\Relay` when Relay is available. Default: disabled. The drop-in also needs the phpredis extension.

### Memcached

- `$memcached_servers` - The WordPress global with the server list. Default: `[['127.0.0.1', 11211]]`. The drop-in also accepts `host:port` and `unix://path` strings, and a list in a `default` bucket, as on WP Engine.
- `WP_MEMCACHED_CONNECT_TIMEOUT` - Connection timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_SEND_TIMEOUT` - Send timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_RECV_TIMEOUT` - Receive timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_PERSISTENT_GROUPS` - A comma-separated string or an array of [protected groups](#groups). Default: `plugins`, `themes` and `counts`.
- `WP_MEMCACHED_IGBINARY` - Use igbinary when it is available. Default: enabled. The extension marks the serializer of each value, so it also reads the old values of the PHP serializer.

### APCu

- `WP_APCU_PERSISTENT_GROUPS` - A comma-separated string or an array of [protected groups](#groups). Default: `plugins`, `themes` and `counts`.

## How it works

### Runtime cache

Each request keeps the values that it reads and writes in memory, so it reads each key from the backend only once. A group keeps at most 10,000 entries with Redis and Memcached, and 100,000 entries with APCu. Then the oldest entries go first.

Bulk reads use one request for each group with Redis (`hMGet`), and one request for all keys with Memcached (`getMulti`).

### Groups

- On multisite, the name of a group starts with the blog ID, such as `2:options`. Global groups have no blog ID, so all sites share them. `wp_cache_switch_to_blog()` keeps the global entries in the runtime cache.
- Groups from `wp_cache_add_non_persistent_groups()` stay in the runtime cache only.
- Protected groups always go to the backend, also when a plugin makes them non-persistent.

### Redis

- Each group is a Redis hash, with a key such as `salt:iz:options`. The middle part is the format: `i` for igbinary or `p` for the PHP serializer, then `z` for zstd, `l` for LZ4 or `f` for LZF. Thus, a change of the format does not read the values of the old format.
- On Redis 7.4 or later, `HEXPIREAT` deletes the expired fields. On older versions, the drop-in deletes an expired field when a request reads it.
- `wp_cache_flush()` deletes only the keys with the key salt, in all formats. Other sites in the same database keep their cache.

### Memcached

- The keys contain the key salt, the blog ID, the group and a version of the site and of the group. `wp_cache_flush()` and `wp_cache_flush_group()` change the version, so other sites on the same servers keep their cache.
- The values are compressed with FastLZ.
- When Memcached rejects a value because it is too large, the drop-in saves it in chunks of 500,000 bytes.

### Failures

When the backend fails, the request continues with the runtime cache and WordPress reads the database:

- If a Redis command fails and Redis does not answer `PING`, the drop-in stops using Redis until the end of the request.
- If a Redis write or delete fails, the old value stays in Redis. The drop-in stops using Redis and creates `wp-content/cache-flush`.
- Known Memcached errors, and APCu errors that show that APCu is disabled also stop the use of the backend until the end of the request.
- If `apcu_store()` fails, the drop-in deletes the key, so a later request loads a new value.

Errors at the connection show as admin notices. Errors in a request cause PHP notices or warnings. The error settings of the site tell if PHP shows or logs them.

The `wp-content/cache-flush` file tells the next request to flush the cache. Redis then deletes the keys with the key salt, Memcached changes the version of the site, and APCu deletes its keys. Then the drop-in deletes the file. This recovery needs write access to `wp-content/`.

## Development

```
├── plugin/                    The plugin, as it is installed in wp-content/plugins/twee-cache/
│   ├── twee-cache.php         Loads the includes, adds the hooks, writes the drop-in on activation and removes it on deactivation
│   ├── handlers/              The handlers that the drop-in loads: apcu/, memcached/, redis/, each with object-cache.php
│   ├── assets/                Styles of the Cache Settings page: scss/ sources, css/ built with gulp or sass
│   └── includes/
│       ├── admin.php          Admin Bar menu and its actions
│       ├── dropin.php         Drop-in file, engine detection, Query Monitor connection
│       ├── groups.php         Latency test and cache groups of the Statistics tab
│       ├── monitor.php        Query Monitor panel
│       ├── settings.php       Cache Settings page and the toc_settings option
│       └── update.php         Updates from GitHub
├── tests/                     PHPUnit integration tests
├── bin/release.php            Builds and publishes releases
└── CHANGELOG.md               Release notes
```

### Tests

The tests load each handler with WordPress function stubs, and run it against a live backend. They do not need a WordPress installation. When the PHP extension or the backend is not available, the tests are skipped.

1. Run `composer install`.
2. Run `composer test:redis`, `composer test:memcached` or `composer test:apcu`.

These scripts use the Windows `set` command. On other systems, run `TW_CACHE_ENGINE=redis composer test`.

### Releases

Sites update from the ZIP of the latest GitHub release. The release script needs the [GitHub CLI](https://cli.github.com), signed in with `gh auth login`.

1. Change the `Version` header in `plugin/twee-cache.php`.
2. Describe the release in `CHANGELOG.md` under a `## {version}` heading.
3. Commit and push.
4. Run `composer release`. The script builds the ZIP and publishes the release `v{version}`, with the section of `CHANGELOG.md` as the notes.

The script stops when the `Version` header is missing, `CHANGELOG.md` has no section for the version, the tracked files have uncommitted changes, `HEAD` is not pushed, or the release exists.

To build only `build/twee-cache-{version}.zip`, run `composer zip`. This ZIP also contains the uncommitted changes of the tracked files, so you can install the current code on a test site.

## About

Author: Andrii Toniievych

Contact: [toniyevych@gmail.com](mailto:toniyevych@gmail.com)

Feel free to contact me if you have any questions.

## Contribution

Fork the repository, push your changes to a branch, and open a pull request.

## License

Twee Cache is released under the MIT License. See [LICENSE.md](LICENSE.md).

Note: The "About" section in `README.md` and the author (`@author`) notice in the file-headers shall not be edited or deleted without permission. Thank you!
