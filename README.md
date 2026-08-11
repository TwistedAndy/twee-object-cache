# Twee Object Cache

**Twee Object Cache** is a suite of high-performance, lightweight object caches built for large-scale WordPress and WooCommerce projects. It provides drop-in replacements for WordPress's in-memory runtime cache, adding backend storage that persists between requests.

This repository contains caching plugins for three different backends: **APCu**, **Memcached**, and **Redis**.

## Features

### 1. High-Performance Integration

- Uses the corresponding PHP extension for process-host-local shared memory with APCu or external Redis and Memcached services.
- Can reduce repeated database reads for WordPress data routed through the object-cache API, including options, transients, and metadata.
- Uses Redis hash bulk operations (`hMGet` and `hMSet`) and Memcached `getMulti`; Memcached bulk writes process each item individually so oversized values can use chunking.
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
- The **Memcached** implementation namespaces keys by installation, blog, and group, and uses per-group version counters for logical group flushing.

### 4. Multisite & Context Compatibility

- Prefixes non-global groups with the current blog ID in multisite installations; registered global groups omit the blog prefix.
- Supports `wp_cache_switch_to_blog()` while preserving global runtime entries and isolating blog-scoped entries.
- Uses `WP_CACHE_KEY_SALT` to keep web, Cron, and WP-CLI requests in the same namespace when their detected host values differ.

## Reliability & Test Coverage

- Redis exceptions and recognized Memcached operation errors disable backend access for the remainder of the request while leaving the runtime cache available. Selected APCu failures do the same when they indicate APCu has become disabled.
- Backend initialization failures create WordPress admin notices. Runtime Redis errors use `E_USER_NOTICE`; Memcached and selected APCu errors use `E_USER_WARNING`. Display and logging follow the site's PHP and WordPress error configuration.
- On detected Redis or Memcached failures, the drop-in attempts to create an internal `.ocflush` marker. On a later successful initialization, Redis flushes the selected database and Memcached flushes its configured pool before the marker is removed. This best-effort recovery requires the deployed drop-in directory to be writable.
- The PHPUnit integration suite loads each drop-in directly with lightweight WordPress function stubs and exercises it against the selected live backend.
- Shared coverage includes core `wp_cache_*` operations, runtime and backend persistence, bulk operations, expiration, global and non-persistent groups, group flushing, multisite isolation, and blog switching. Memcached-specific cases also cover oversized payload chunking.
- Tests skip when the selected PHP extension or backend is unavailable and do not boot a complete WordPress installation.
- Run a backend suite with `composer test:redis`, `composer test:memcached`, or `composer test:apcu`.

## Installation

Twee Object Cache can be installed as a standard WordPress plugin or as a manual drop-in.

### 1. WordPress Plugin Installation (Recommended)

- Switch to PHP 8.0 or a newer version.
- Ensure Redis or Memcached is reachable, or APCu is enabled, and install the corresponding PHP extension.
- Copy the contents of this repository's `plugin/` directory into `wp-content/plugins/twee-object-cache/`, so `twee-object-cache.php` is directly inside that directory, then activate the plugin.
- As an administrator, open the **Object Cache** menu in the WordPress Admin Bar. When no drop-in is installed, it lists the backends whose PHP extensions are loaded.
- Select **Enable Redis**, **Enable Memcached**, or **Enable APCu**. The plugin performs a basic availability check and copies the selected drop-in to `wp-content/object-cache.php`. Disable the current drop-in before switching backends.

### 2. Manual Drop-in Installation

- Ensure Redis or Memcached is reachable, or APCu is enabled, and install the corresponding PHP extension.
- Copy `plugin/apcu/object-cache.php`, `plugin/memcached/object-cache.php`, or `plugin/redis/object-cache.php` to `wp-content/object-cache.php`.
- WordPress will automatically detect the drop-in and initialize the persistent cache.

## Configuration

Configuration values are optional when the built-in local defaults suit the environment. Define constants in `wp-config.php` before WordPress loads the object-cache drop-in.

### All backends

- `WP_CACHE_KEY_SALT` - Cache namespace. When omitted, the drop-in derives it from `HTTP_HOST`, then `SERVER_NAME`, then `localhost`, and replaces non-alphanumeric characters with underscores. Redis truncates the salt to 10 characters; Memcached and APCu truncate it to 20.

### Redis

- `WP_REDIS_HOST` - Redis host or Unix socket. Default: `127.0.0.1`.
- `WP_REDIS_PORT` - Redis port. Default: `6379`.
- `WP_REDIS_TIMEOUT` - Connection timeout in seconds. Default: `1`.
- `WP_REDIS_RETRY_INTERVAL` - Retry interval in milliseconds. Default: `100`.
- `WP_REDIS_PASSWORD` - Redis authentication password.
- `WP_REDIS_DATABASE` - Redis database number.
- `WP_REDIS_PERSISTENT_CONNECT` - Enable persistent connections. Default: enabled.
- `WP_REDIS_PERSISTENT_GROUPS` - Comma-separated string or array replacing the protected-group list. Protected groups ignore requests to make them non-persistent. Default: `plugins`, `themes`, and `counts`.
- `WP_REDIS_IGBINARY` - Enable igbinary serialization when available. Default: enabled.
- `WP_REDIS_COMPRESSION` - Enable Redis compression when available. Default: enabled.
- `WP_REDIS_USE_RELAY` - Use `Relay\Relay` when Relay is available. Default: disabled. The current drop-in still requires the phpredis `Redis` class.

### Memcached

- `$memcached_servers` - WordPress global containing the Memcached server list. Default: `[['127.0.0.1', 11211]]`.
- `WP_MEMCACHED_PERSISTENT_GROUPS` - Comma-separated string or array replacing the protected-group list. Protected groups ignore requests to make them non-persistent. Default: `plugins`, `themes`, and `counts`.
- `WP_MEMCACHED_CONNECT_TIMEOUT` - Connection timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_SEND_TIMEOUT` - Send timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_RECV_TIMEOUT` - Receive timeout in milliseconds. Default: `250`.
- `WP_MEMCACHED_IGBINARY` - Enable igbinary serialization when available. Default: disabled.

### APCu

- `WP_APCU_PERSISTENT_GROUPS` - Comma-separated string or array replacing the protected-group list. Protected groups ignore requests to make them non-persistent. Default: `plugins`, `themes`, and `counts`.

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
