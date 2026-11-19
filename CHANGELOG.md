# Changelog

## 1.1.0

The plugin is now **Twee Cache**, and it is installed in the `wp-content/plugins/twee-cache` folder.

- After this update, WordPress deactivates the plugin one time. Activate **Twee Cache** again on the Plugins screen. The cache continues to work while the plugin is inactive. The activation replaces the drop-in with the new version.
- The **Object Cache** menu in the Admin Bar shows the hit ratio and the backend reads of the page. It also shows the memory, the number of keys and the latency of the backend.
- When Query Monitor is active, the plugin adds an **Object Cache** panel below **Database Queries**. Set `WP_DEBUG_LOG` to `true` to see each backend call with its caller and time in the **Commands** panel.

## 1.0.0

