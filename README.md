# Twee Object Cache

**Twee Object Cache** is a suite of high-performance, lightweight object caches built for large-scale WordPress and WooCommerce projects. It provides highly optimized drop-in replacements for the native WordPress in-memory runtime cache, minimizing database load and maximizing execution speed through persistent backend storage. 

This repository contains caching plugins for three different backends: **APCu**, **Memcached**, and **Redis**.

## Features

### 1. High-Performance Integration

- Natively leverages the respective PHP extensions (`apcu`, `memcached`, or `redis`) for ultra-fast, persistent object caching.
- Reduces heavy database queries by reliably maintaining options, transients, and object metadata in memory.
- Safely handles bulk operations (utilizing native methods like `getMulti` and `setMulti` for Memcached and Redis) to minimize network latency.
- For APCu, safely handles memory exhaustion by gracefully deleting keys when storage fails, forcing fresh database reads instead of serving stale data.
- The **Memcached** implementation automatically splits huge cache payloads into smaller chunks to bypass the server's hard item size limits, completely preventing "Payload too large" errors on massive datasets.

### 2. Dual-Layer Caching Engine

- Implements an internal runtime cache array to prevent redundant backend round-trips during a single page load.
- Automatically trims the runtime cache limit using an efficient, key-preserving slice mechanism to prevent memory exhaustion and array key corruption on massive bulk operations.
- Strictly adheres to functional and procedural paradigms native to WordPress core.

### 3. Advanced Group Management

- Fully supports global cache groups for shared data across networks.
- Natively handles non-persistent groups (such as `counts` and `plugins`) by enforcing that they remain exclusively in the runtime cache and never hit the persistent backend.
- The **Redis** implementation natively utilizes Redis Hashes (`hSet`, `hGet`, `hDel`) to cleanly group cache data, dramatically organizing the global keyspace.
- The **Memcached** implementation manages group isolation using an efficient versioning (namespace) architecture to securely separate keys by their respective cache group.

### 4. Multisite & Context Compatibility

- Provides full WordPress Multisite support.
- Automatically isolates cache keys using the blog ID prefix to prevent data bleeding across the network.
- Context-aware key prefixing ensures CLI, Cron, and web requests all target the identical caching environment.

### 5. Safe Cache Flushing & Iteration

- Utilizes O(1) group versioning (namespaces) for instant, non-blocking cache invalidation in Memcached.
- The **Redis** implementation achieves true instant cache group invalidation by dropping entire Redis Hashes directly (via non-blocking `UNLINK`), completely bypassing the need for fallback key versioning.
- Actively prevents memory leaks and stale bloat ("ghost keys") by automatically cleaning up orphaned chunk payloads and harvested keys during individual deletions and group flushes.
- Flushes only the keys belonging to the specific WordPress installation using dynamic key salts, preventing conflicts with other applications or sites running on the same server.

## Installation

Twee Object Cache can be installed as a standard WordPress plugin or as a manual drop-in.

### 1. WordPress Plugin Installation (Recommended)

- Switch to PHP 8.0 or a newer version.
- Ensure your preferred caching service (APCu, Memcached, or Redis) is running on your web server and install the corresponding PHP extension.
- Upload the `twee-object-cache` folder to your `wp-content/plugins/` directory and activate the plugin via the WordPress dashboard.
- Hover over the **"Object Cache"** menu in the WordPress Admin Bar. 
- Select **"Enable Redis"**, **"Enable Memcached"**, or **"Enable APCu"** based on your environment. The plugin will automatically deploy the correct drop-in, verify connectivity, and start caching.

### 2. Manual Drop-in Installation

- Ensure your preferred caching service and PHP extension are installed.
- Manually copy the `object-cache.php` file from the respective folder (`apcu/`, `memcached/`, or `redis/`) directly into your `wp-content/` directory.
- WordPress will automatically detect the drop-in and initialize the persistent cache.

## Configuration

All configuration is automatic, but you can explicitly define your cache salt in `wp-config.php` to guarantee prefix consistency across different execution environments (like WP-CLI).

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
