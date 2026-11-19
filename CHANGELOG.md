# Changelog

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

