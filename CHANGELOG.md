# Changelog

## 1.5.0

The settings moved from `wp-content/cache-config.php` to the database. After this update, open **Settings → Cache Settings**, examine the values and select **Save Changes**. Then you can delete `cache-config.php`.

- The new **Statistics** tab of the **Cache Settings** page shows the server details and the latency of 100 writes and 100 reads. It also shows the cache groups of the site, from the largest, with the number of keys and the size of each group.
- Select a group on the **Statistics** tab to see its keys, their size, their expiration time and their values. To delete groups or keys, select their checkboxes, then select **Clean Selected**.
- The page saves the settings in the `toc_settings` site option. The settings stay when you deactivate the plugin.
- The plugin writes `wp-content/object-cache.php` with a copy of the settings. This file loads the handler from the plugin folder, so a plugin update also updates the cache code. When the plugin folder does not exist, WordPress uses its own cache.
- You can copy a handler to `wp-content/` and use it without the plugin. The handlers are now in `plugin/handlers/{engine}/object-cache.php`, and a handler without the plugin reads the constants in `wp-config.php`.
- **Tracing** and the **Commands** panel of Query Monitor are removed.
- The Admin Bar menu sends no request to the backend. It shows if the drop-in is connected. The reads row has the name of the backend, such as **Redis reads**. The memory, the keys and the latency show in Query Monitor and on the **Statistics** tab.
- When **Test Connection** fails, the message gives the reason. When Redis needs a password and the **Password** field is empty, the message tells where to find the password. A failed Memcached test names the servers and the reason, such as a timeout.
- On the default ports, the page sends `PING` to Redis and `version` to Memcached. It uses a port only when the server answers.
- The page no longer shows the notice "Some settings found on this server are different". The found values still show under the fields.

Fixes:

- The Object Cache menu in the Admin Bar had too much space under the last row.

## 1.4.0

The settings file and its keys have new names. After this update, open **Settings → Cache Settings**, examine the values and select **Save Changes**.

- The page writes the settings to `wp-content/cache-config.php`. A key is the name of its constant in lower case and without `WP_`, such as `redis_host` for `WP_REDIS_HOST`. The plugin does not read `oc-config.php`. Until you save the settings again, the drop-in uses the constants in `wp-config.php` and the default values. Then you can delete `oc-config.php`.
- The flush marker is now `wp-content/cache-flush`. You can delete an old `oc-flush` file.
- The **Detect** button is removed. The page finds the settings of the server each time it opens. When a found value is different from the value of the form, it shows under the field. The page does not show a found password.
- **Reset Connection** fills the connection fields with the found values or the defaults. It does not save the values.
- **Test** is now **Test Connection**. **Test Connection** and **Reset Connection** are in the section of the handler.
- **Turn On** and **Turn Off** are now **Enable Cache** and **Disable Cache**, as in the Admin Bar.
- When the PHP extensions of the server do not support igbinary, compression or Relay, the page turns off the switch and shows it as unavailable.
- The time fields show their unit, `s` or `ms`, after the field.
- The Plugins list has a **Settings** link for the plugin.
- The boxes at the top of the **Object Cache** panel in Query Monitor have the same width.

Fixes:

- After the upload of the plugin ZIP, the **Activate** link pointed to a drop-in, and WordPress showed "The plugin does not have a valid header". The drop-ins no longer have a plugin header.
- The fields on the page had empty cells between them.

## 1.3.0

The **Cache Settings** page now finds the settings of the host. After this update, the cache starts empty one time, because the Redis keys have a new format.

- **Detect** fills the form with the settings of the host and of other object cache plugins: Object Cache Pro, Redis Object Cache, WP Redis, the environment variables, the sockets of the host and the default ports. It finds the settings on Nexcess, Cloudways, WP Engine and SiteGround. It does not save the values.
- When `wp-content/oc-config.php` does not exist, the page shows the detected settings, and **Turn On** saves them.
- The new **Username** field connects to Redis 6 and later with an ACL user.
- The **igbinary** and **Compression** switches are on by default when the server supports them. In the Memcached drop-in, igbinary is now on by default too. Memcached reads the values that it saved before with the PHP serializer.
- **Save Changes** and **Turn On** flush the cache before the next request reads it. Thus, values in an old format or from another backend do not stay in the cache. APCu now also flushes when it finds the `oc-flush` marker.
- The Redis keys now contain the serializer and the compression. A request that started before a change of these switches cannot write values that the new requests read.
- `$memcached_servers` also accepts `host:port` and `unix://path` strings, and the server buckets of WP Engine.

Fixes:

- With persistent connections, Redis can give a connection that another site used. The drop-in now selects the database on each request, also for database 0. Before, a site with database 0 could use the database of another site, and logins failed randomly.
- After **Save Changes**, the page did not show "Settings saved.". When the test fails, the form now keeps the values that you typed.
- When a request came between the two steps of a handler change, the new drop-in did not flush the cache and showed old values.

## 1.2.0

The new **Settings → Cache Settings** page configures the object cache. On multisite, the page is in **Network Admin → Settings**.

- Select the handler: Redis, Memcached or APCu. Then set the connection and the other values of that handler.
- **Test** connects to the backend with the values of the form, then writes, reads and deletes a test key. It does not save the values.
- When the cache is on, **Save Changes** saves the values only when the test passes. When you select another handler, it replaces the drop-in and flushes the cache.
- **Turn On** and **Turn Off** install and remove the drop-in. Before Turn On installs the drop-in, it tests the saved values. The **Enable** links in the Admin Bar also do this test now.
- The page writes the values to `wp-content/oc-config.php`. The constants in `wp-config.php` continue to work and win over the page. The page shows these fields as read-only.
- The **Tracing** switch on the page replaces `WP_DEBUG_LOG`. If you used `WP_DEBUG_LOG` to see the backend calls in Query Monitor, turn on Tracing.
- The **Object Cache** menu in the Admin Bar has a **Settings** link.
- The flush marker is now `wp-content/oc-flush`. The update renames the old `.ocflush` marker.

## 1.1.0

The plugin is now **Twee Cache**, and it is installed in the `wp-content/plugins/twee-cache` folder.

- After this update, WordPress deactivates the plugin one time. Activate **Twee Cache** again on the Plugins screen. The cache continues to work while the plugin is inactive. The activation replaces the drop-in with the new version.
- The **Object Cache** menu in the Admin Bar shows the hit ratio and the backend reads of the page. It also shows the memory, the number of keys and the latency of the backend.
- When Query Monitor is active, the plugin adds an **Object Cache** panel below **Database Queries**. Set `WP_DEBUG_LOG` to `true` to see each backend call with its caller and time in the **Commands** panel.

## 1.0.0

