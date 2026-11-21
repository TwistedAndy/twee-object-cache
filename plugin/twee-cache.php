<?php
/**
 * Plugin Name: Twee Cache
 * Plugin URI: https://github.com/TwistedAndy/twee-cache
 * Description: A suite of high-performance, lightweight object caches built for large-scale WordPress projects.
 * Author: Andrii Toniievych
 * Author URI: https://www.linkedin.com/in/toniievych/
 * Version: 1.4.0
 * Requires PHP: 8.0
 * License: MIT
 * Update URI: https://github.com/TwistedAndy/twee-cache
 */

if (!defined('ABSPATH')) {
	exit;
}

require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/dropin.php';

// The Cache Settings page is in wp-admin only
if (is_admin()) {
	require __DIR__ . '/includes/settings.php';
}

add_action('plugins_loaded', 'toc_dropin_monitor');
add_action('admin_menu', 'toc_settings_menu');
add_action('network_admin_menu', 'toc_settings_menu');
add_action('init', 'toc_admin_action');
add_action('admin_bar_menu', 'toc_admin_bar', 100);

/**
 * Offer the latest GitHub release as an update. The update code loads only when WordPress examines the updates.
 */
add_filter('update_plugins_github.com', function($update, array $plugin_data, string $plugin_file) {
	require_once __DIR__ . '/includes/update.php';

	return toc_update_check($update, $plugin_data, $plugin_file);
}, 10, 3);

/**
 * Show the release notes in the "View version details" window
 */
add_filter('plugins_api', function($result, string $action, $args) {
	require_once __DIR__ . '/includes/update.php';

	return toc_update_details($result, $action, $args);
}, 10, 3);

/**
 * Add the Settings link in the Plugins list. On multisite, the page is in the Network Admin.
 */
add_filter((is_multisite() ? 'network_admin_' : '') . 'plugin_action_links_' . plugin_basename(__FILE__), function(array $links): array {
	array_unshift($links, '<a href="' . esc_url(toc_settings_url()) . '">Settings</a>');

	return $links;
});

/**
 * Write the drop-in again on activation, for example after the plugin folder was renamed.
 * The drop-in that found no handler already asked for a flush.
 */
register_activation_hook(__FILE__, function(): void {
	if (toc_dropin_engine() !== '') {
		toc_dropin_install();
	}
});

/**
 * Remove the drop-in and the flush marker on deactivation. A drop-in of another plugin stays.
 *
 * The settings stay in the toc_settings site option for the next activation.
 */
register_deactivation_hook(__FILE__, function(): void {
	if (toc_dropin_engine() !== '') {
		wp_cache_flush();
		@unlink(WP_CONTENT_DIR . '/object-cache.php');
	}

	@unlink(WP_CONTENT_DIR . '/cache-flush');
});
