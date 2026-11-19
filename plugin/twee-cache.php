<?php
/**
 * Plugin Name: Twee Cache
 * Plugin URI: https://github.com/TwistedAndy/twee-cache
 * Description: A suite of high-performance, lightweight object caches built for large-scale WordPress projects.
 * Author: Andrii Toniievych
 * Author URI: https://www.linkedin.com/in/toniievych/
 * Version: 1.1.0
 * Requires PHP: 8.0
 * License: MIT
 * Update URI: https://github.com/TwistedAndy/twee-cache
 */

if (!defined('ABSPATH')) {
	exit;
}

require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/dropin.php';
require __DIR__ . '/includes/settings.php';
require __DIR__ . '/includes/update.php';

add_action('plugins_loaded', 'toc_dropin_monitor');
add_action('admin_menu', 'toc_settings_menu');
add_action('network_admin_menu', 'toc_settings_menu');
add_action('init', 'toc_admin_action');
add_action('admin_bar_menu', 'toc_admin_bar', 100);
add_action('upgrader_process_complete', 'toc_update_dropin', 10, 2);
add_filter('update_plugins_github.com', 'toc_update_check', 10, 3);
add_filter('plugins_api', 'toc_update_details', 10, 3);

/**
 * Replace an older Twee drop-in on activation. An update from the twee-object-cache folder deactivates the plugin and keeps the old drop-in.
 */
register_activation_hook(__FILE__, function(): void {
	toc_update_dropin(null, ['type' => 'plugin']);
});

/**
 * Remove the drop-in on deactivation. A drop-in of another plugin stays.
 */
register_deactivation_hook(__FILE__, function(): void {
	if (toc_dropin_engine() !== '') {
		wp_cache_flush();
		@unlink(WP_CONTENT_DIR . '/object-cache.php');
	}
});
