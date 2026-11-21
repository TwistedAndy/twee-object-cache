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
 * Do the action of an Object Cache menu link (enable, disable, flush)
 *
 * The action runs before the page output starts, so the redirect can send its headers.
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

	if ($action === 'flush') {
		wp_cache_flush();
	} elseif ($action === 'disable') {
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

	$engines = [
		'memcached' => 'Memcached',
		'redis'     => 'Redis',
		'apcu'      => 'APCu'
	];

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

	$engine = toc_dropin_engine();

	if ($engine !== '') {
		$admin_bar->add_node([
			'id'     => 'twee-cache-flush',
			'parent' => 'twee-cache',
			'title'  => 'Clean Cache',
			'href'   => wp_nonce_url(add_query_arg('toc_action', 'flush'), 'toc_action'),
		]);

		$admin_bar->add_node([
			'id'     => 'twee-cache-disable',
			'parent' => 'twee-cache',
			'title'  => 'Disable Cache',
			'href'   => wp_nonce_url(add_query_arg('toc_action', 'disable'), 'toc_action'),
		]);

		$admin_bar->add_group([
			'id'     => 'twee-cache-info',
			'parent' => 'twee-cache',
			'meta'   => ['class' => 'ab-sub-secondary'],
		]);

		// Each row has a label and a value
		$rows = ['engine' => ['<strong>' . $engines[$engine] . '</strong>', '']];

		global $wp_object_cache;

		// The values of the page until the Admin Bar shows. In wp-admin, the Admin Bar shows before most of the page.
		// They need no backend request. Query Monitor shows the memory, the keys and the latency of the backend.
		if (method_exists($wp_object_cache, 'debug_info')) {
			$hits = $wp_object_cache->cache_hits;
			$misses = $wp_object_cache->cache_misses;
			$ratio = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 1) : 0;

			$rows['engine'][1] = $wp_object_cache->active ? 'Connected' : 'Not connected';
			$rows['ratio'] = ['Hit ratio', $ratio . '% (' . number_format_i18n($hits) . ' / ' . number_format_i18n($misses) . ')'];
			$rows['reads'] = [$engines[$engine] . ' reads', number_format_i18n($wp_object_cache->cache_loads)];
		}

		// The first row adds the styles of the rows: the label at the left and the value at the right.
		// The Admin Bar gives each element a line height of 32px, so the text in a row of 20px moves down without the inherit rule.
		$meta = ['html' => '<style>#wpadminbar #wp-admin-bar-twee-cache-info{padding:9px 0}#wpadminbar #wp-admin-bar-twee-cache-info .ab-item{display:flex;justify-content:space-between;gap:24px;height:20px;line-height:20px}#wpadminbar #wp-admin-bar-twee-cache-info .ab-item *{line-height:inherit}#wpadminbar #wp-admin-bar-twee-cache-info .ab-item span+span{color:#f0f0f1;font-variant-numeric:tabular-nums}</style>'];

		foreach ($rows as $id => $row) {
			$admin_bar->add_node([
				'id'     => 'twee-cache-' . $id,
				'parent' => 'twee-cache-info',
				'title'  => '<span>' . $row[0] . '</span><span>' . $row[1] . '</span>',
				'meta'   => $meta,
			]);

			$meta = [];
		}

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

	foreach ($engines as $ext => $name) {
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
