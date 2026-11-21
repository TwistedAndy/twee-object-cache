<?php
/**
 * Object Cache menu in the WordPress Admin Bar and its actions
 */

if (!defined('ABSPATH')) {
	exit;
}


/**
 * Get the URL of the Cache Settings page. On multisite, the page is in the Network Admin.
 *
 * The Admin Bar shows the link on the front end too, where settings.php does not load.
 */
function toc_settings_url(): string
{
	return is_multisite() ? network_admin_url('settings.php?page=twee-cache') : admin_url('options-general.php?page=twee-cache');
}


/**
 * Do the action of an Object Cache menu link (enable, disable)
 *
 * The action runs before the page output starts, so the redirect can send its headers. The handler does the Clean Cache link (flush).
 */
function toc_admin_action(): void
{
	$action = $_GET['toc_action'] ?? '';

	if (!$action or !current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
		return;
	}

	check_admin_referer('toc_action');

	$dropin_file = WP_CONTENT_DIR . '/object-cache.php';
	$redirect_url = remove_query_arg(['toc_action', '_wpnonce']);

	if ($action === 'disable') {
		if (toc_dropin_engine() !== '' and !@unlink($dropin_file)) {
			wp_die('Cannot delete ' . esc_html($dropin_file) . '. Make sure that the web server can write to this file.', 'Object Cache', ['back_link' => true]);
		}
	} elseif (str_starts_with($action, 'enable_')) {
		$engine = substr($action, 7);

		if (file_exists($dropin_file)) {
			wp_die('An object cache is already installed. Disable it first.', 'Object Cache', ['back_link' => true]);
		}

		// The menu link works on the front end too, where settings.php does not load
		require_once __DIR__ . '/settings.php';

		$result = toc_settings_save(['handler' => $engine] + toc_settings_get(), true);

		if (!$result['ok']) {
			wp_die(esc_html($result['message']), 'Object Cache', ['back_link' => true]);
		}
	}

	wp_safe_redirect($redirect_url);
	exit;
}


/**
 * Add the Object Cache menu in the WordPress Admin Bar
 *
 * @param WP_Admin_Bar $admin_bar
 */
function toc_admin_bar(WP_Admin_Bar $admin_bar): void
{
	if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
		return;
	}

	$admin_bar->add_node([
		'id'    => 'twee-cache',
		'title' => 'Object Cache',
		'href'  => '#',
	]);

	$admin_bar->add_node([
		'id'     => 'twee-cache-settings',
		'parent' => 'twee-cache',
		'title'  => 'Settings',
		'href'   => toc_settings_url(),
	]);

	if (toc_dropin_engine() !== '') {
		global $wp_object_cache;

		// The handler adds Clean Cache, the engine, the hit ratio and the backend reads. When the drop-in cannot find the handler, WordPress uses its own cache.
		if (method_exists($wp_object_cache, 'admin_bar')) {
			$wp_object_cache->admin_bar($admin_bar);
		}

		$admin_bar->add_node([
			'id'     => 'twee-cache-disable',
			'parent' => 'twee-cache',
			'title'  => 'Disable Cache',
			'href'   => wp_nonce_url(add_query_arg('toc_action', 'disable'), 'toc_action'),
		]);

		return;
	}

	if (file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
		$admin_bar->add_node([
			'id'     => 'twee-cache-other',
			'parent' => 'twee-cache',
			'title'  => 'Another object cache is installed',
		]);

		return;
	}

	$available = false;

	foreach (['memcached' => 'Memcached', 'redis' => 'Redis', 'apcu' => 'APCu'] as $ext => $name) {
		if (extension_loaded($ext)) {
			$available = true;

			$admin_bar->add_node([
				'id'     => 'twee-cache-enable-' . $ext,
				'parent' => 'twee-cache',
				'title'  => 'Enable ' . $name,
				'href'   => wp_nonce_url(add_query_arg('toc_action', 'enable_' . $ext), 'toc_action'),
			]);
		}
	}

	if (!$available) {
		$admin_bar->add_node([
			'id'     => 'twee-cache-no-ext',
			'parent' => 'twee-cache',
			'title'  => 'No extensions available',
		]);
	}
}
