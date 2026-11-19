<?php
/**
 * Plugin Name: Twee Object Cache
 * Plugin URI: https://github.com/TwistedAndy/twee-object-cache
 * Description: A suite of high-performance, lightweight object caches built for large-scale WordPress projects.
 * Author: Andrii Toniievych
 * Author URI: https://www.linkedin.com/in/toniievych/
 * Version: 1.0.0
 * Requires PHP: 8.0
 * License: MIT
 * Update URI: https://github.com/TwistedAndy/twee-object-cache
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('init', 'twee_cache_action');
add_action('admin_bar_menu', 'twee_cache_admin_bar', 100);
add_action('upgrader_process_complete', 'twee_cache_upgraded', 10, 2);
add_filter('update_plugins_github.com', 'twee_cache_update_check', 10, 3);
add_filter('plugins_api', 'twee_cache_update_details', 10, 3);

/**
 * Remove the drop-in on deactivation. A drop-in of another plugin stays.
 */
register_deactivation_hook(__FILE__, function(): void {
	if (twee_cache_engine() !== '') {
		wp_cache_flush();
		@unlink(WP_CONTENT_DIR . '/object-cache.php');
	}
});


/**
 * Do the action of an Object Cache menu link (enable, disable, flush)
 *
 * The action runs before the page output starts, so the redirect can send its headers.
 */
function twee_cache_action(): void
{
	$action = $_GET['twee_cache_action'] ?? '';

	if (!$action or !current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
		return;
	}

	check_admin_referer('twee_cache_action');

	$dropin_file = WP_CONTENT_DIR . '/object-cache.php';
	$redirect_url = remove_query_arg(['twee_cache_action', '_wpnonce']);

	if ($action === 'flush') {
		wp_cache_flush();
	} elseif ($action === 'disable') {
		if (twee_cache_engine() !== '' and !@unlink($dropin_file)) {
			wp_die('Cannot delete ' . esc_html($dropin_file) . '. Make sure that the web server can write to this file.', 'Object Cache', ['back_link' => true]);
		}
	} elseif (str_starts_with($action, 'enable_')) {
		$engine = substr($action, 7);

		if (file_exists($dropin_file)) {
			wp_die('An object cache is already installed. Disable it first.', 'Object Cache', ['back_link' => true]);
		}

		if (!twee_cache_is_reachable($engine)) {
			wp_die('The selected cache engine is not reachable. Check the configuration.', 'Object Cache', ['back_link' => true]);
		}

		if (!twee_cache_install($engine)) {
			wp_die('Cannot write ' . esc_html($dropin_file) . '. Make sure that the web server can write to the wp-content folder.', 'Object Cache', ['back_link' => true]);
		}

		// The drop-in loads on the next request, so that request removes the values that were saved before
		$redirect_url = add_query_arg([
			'twee_cache_action' => 'flush',
			'_wpnonce'          => wp_create_nonce('twee_cache_action')
		], $redirect_url);
	}

	wp_safe_redirect($redirect_url);
	exit;
}


/**
 * Add the Object Cache menu in the WordPress Admin Bar
 *
 * @param WP_Admin_Bar $admin_bar
 */
function twee_cache_admin_bar(WP_Admin_Bar $admin_bar): void
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
		'id'    => 'twee-object-cache',
		'title' => 'Object Cache',
		'href'  => '#',
	]);

	$engine = twee_cache_engine();

	if ($engine !== '') {
		$admin_bar->add_node([
			'id'     => 'twee-cache-stats',
			'parent' => 'twee-object-cache',
			'title'  => '<strong>Engine:</strong> ' . $engines[$engine],
		]);

		$admin_bar->add_node([
			'id'     => 'twee-cache-flush',
			'parent' => 'twee-object-cache',
			'title'  => 'Clean Cache',
			'href'   => wp_nonce_url(add_query_arg('twee_cache_action', 'flush'), 'twee_cache_action'),
		]);

		$admin_bar->add_node([
			'id'     => 'twee-cache-disable',
			'parent' => 'twee-object-cache',
			'title'  => 'Disable Cache',
			'href'   => wp_nonce_url(add_query_arg('twee_cache_action', 'disable'), 'twee_cache_action'),
		]);

		return;
	}

	if (file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
		$admin_bar->add_node([
			'id'     => 'twee-cache-other',
			'parent' => 'twee-object-cache',
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
				'parent' => 'twee-object-cache',
				'title'  => 'Enable ' . $name,
				'href'   => wp_nonce_url(add_query_arg('twee_cache_action', 'enable_' . $ext), 'twee_cache_action'),
			]);
		}
	}

	if (!$available) {
		$admin_bar->add_node([
			'id'     => 'twee-cache-no-ext',
			'parent' => 'twee-object-cache',
			'title'  => 'No extensions available',
		]);
	}
}


/**
 * Get the engine of the installed drop-in
 *
 * @return string The engine (redis, memcached or apcu), or an empty string when there is no drop-in or another plugin installed it
 */
function twee_cache_engine(): string
{
	$dropin_file = WP_CONTENT_DIR . '/object-cache.php';

	if (!is_file($dropin_file)) {
		return '';
	}

	return match (get_file_data($dropin_file, ['name' => 'Plugin Name'])['name']) {
		'Twee Redis Object Cache' => 'redis',
		'Twee Memcached Object Cache' => 'memcached',
		'Twee APCu Object Cache' => 'apcu',
		default => '',
	};
}


/**
 * Copy the drop-in of an engine to the wp-content folder
 *
 * The file is replaced with a rename, so a request never loads a partly written drop-in.
 *
 * @param string $engine redis, memcached or apcu
 *
 * @return bool
 */
function twee_cache_install(string $engine): bool
{
	$source_file = __DIR__ . '/' . $engine . '/object-cache.php';
	$dropin_file = WP_CONTENT_DIR . '/object-cache.php';
	$temp_file = $dropin_file . '.' . uniqid() . '.tmp';

	if (!@copy($source_file, $temp_file)) {
		return false;
	}

	if (!@rename($temp_file, $dropin_file)) {
		@unlink($temp_file);
		return false;
	}

	// OPcache can keep the old drop-in when it does not examine the file time
	if (function_exists('opcache_invalidate')) {
		opcache_invalidate($dropin_file, true);
	}

	return true;
}


/**
 * Replace the drop-in with the version of the updated plugin
 *
 * @param WP_Upgrader $upgrader
 * @param array       $options
 */
function twee_cache_upgraded($upgrader, array $options): void
{
	if (($options['type'] ?? '') !== 'plugin') {
		return;
	}

	$engine = twee_cache_engine();

	if ($engine === '') {
		return;
	}

	$installed = get_file_data(WP_CONTENT_DIR . '/object-cache.php', ['version' => 'Version'])['version'];
	$source_file = __DIR__ . '/' . $engine . '/object-cache.php';

	if (is_file($source_file) and $installed !== get_file_data($source_file, ['version' => 'Version'])['version']) {
		twee_cache_install($engine);
	}
}


/**
 * Check if the selected cache engine is actually working
 *
 * @param string $ext
 *
 * @return bool
 */
function twee_cache_is_reachable(string $ext): bool
{
	if ($ext === 'apcu') {
		return (function_exists('apcu_enabled') and apcu_enabled());
	}

	if ($ext === 'memcached') {
		if (!class_exists('Memcached')) {
			return false;
		}

		$memcached = new Memcached();
		$connect_timeout = defined('WP_MEMCACHED_CONNECT_TIMEOUT') ? (int) WP_MEMCACHED_CONNECT_TIMEOUT : 250;
		$send_timeout = defined('WP_MEMCACHED_SEND_TIMEOUT') ? (int) WP_MEMCACHED_SEND_TIMEOUT : 250;
		$recv_timeout = defined('WP_MEMCACHED_RECV_TIMEOUT') ? (int) WP_MEMCACHED_RECV_TIMEOUT : 250;

		$connect_timeout = $connect_timeout > 0 ? $connect_timeout : 250;
		$send_timeout = $send_timeout > 0 ? $send_timeout : 250;
		$recv_timeout = $recv_timeout > 0 ? $recv_timeout : 250;

		$memcached->setOption(Memcached::OPT_NO_BLOCK, false);
		$memcached->setOption(Memcached::OPT_NOREPLY, false);
		$memcached->setOption(Memcached::OPT_CONNECT_TIMEOUT, $connect_timeout);
		$memcached->setOption(Memcached::OPT_SEND_TIMEOUT, $send_timeout * 1000);
		$memcached->setOption(Memcached::OPT_RECV_TIMEOUT, $recv_timeout * 1000);

		global $memcached_servers;

		if (!empty($memcached_servers)) {
			$memcached->addServers($memcached_servers);
		} else {
			$memcached->addServer('127.0.0.1', 11211);
		}

		$stats = $memcached->getStats();

		if (empty($stats)) {
			return false;
		}

		foreach ($stats as $data) {
			if (isset($data['pid']) and $data['pid'] > 0) {
				return true;
			}
		}

		return false;
	}

	if ($ext === 'redis') {
		if (!class_exists('Redis')) {
			return false;
		}

		$redis = new Redis();
		$host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
		$port = defined('WP_REDIS_PORT') ? WP_REDIS_PORT : 6379;
		$timeout = defined('WP_REDIS_TIMEOUT') ? WP_REDIS_TIMEOUT : 1;

		try {
			if (strpos($host, 'unix://') === 0 or strpos($host, '/') === 0) {
				$connected = $redis->connect($host, 0, $timeout);
			} else {
				$connected = $redis->connect($host, (int) $port, $timeout);
			}

			if (!$connected) {
				return false;
			}

			if (defined('WP_REDIS_PASSWORD') and !$redis->auth(WP_REDIS_PASSWORD)) {
				return false;
			}

			return true;
		} catch (Exception $e) {
			return false;
		}
	}

	return false;
}


/**
 * Get the latest release with a plugin ZIP attached, kept for 12 hours
 *
 * "Check again" on the Updates screen asks GitHub again.
 *
 * @return array The version, package, url and notes, or an empty array when there is no release to install
 */
function twee_cache_release(): array
{
	$release = empty($_GET['force-check']) ? get_transient('twee_cache_release') : false;

	if (is_array($release)) {
		return $release;
	}

	$response = wp_remote_get('https://api.github.com/repos/TwistedAndy/twee-object-cache/releases/latest', [
		'timeout' => 10,
		'headers' => ['Accept' => 'application/vnd.github.html+json'],
	]);

	$data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);

	// The source archives that GitHub adds hold the whole repository, so only an attached ZIP can be installed
	$zip = current(array_filter($data['assets'] ?? [], fn($asset) => str_ends_with((string) ($asset['name'] ?? ''), '.zip')));

	$release = (!empty($data['tag_name']) and $zip) ? [
		'version' => ltrim($data['tag_name'], 'vV'),
		'package' => $zip['browser_download_url'],
		'url'     => $data['html_url'] ?? '',
		'notes'   => $data['body_html'] ?? '',
	] : [];

	// A failed request is kept too, so a GitHub outage or rate limit does not slow every admin page
	set_transient('twee_cache_release', $release, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);

	return $release;
}


/**
 * Offer the latest GitHub release as an update of this plugin
 *
 * WordPress compares the versions and shows the update only when the release is newer.
 *
 * @param array|false $update
 * @param array       $plugin_data
 * @param string      $plugin_file
 *
 * @return array|false
 */
function twee_cache_update_check($update, array $plugin_data, string $plugin_file)
{
	// An update replaces the plugin folder, so a folder linked to a working copy is never updated
	if ($plugin_file !== plugin_basename(__FILE__) or is_link(WP_PLUGIN_DIR . '/' . dirname($plugin_file))) {
		return $update;
	}

	$release = twee_cache_release();

	if (!$release) {
		return $update;
	}

	return [
		'slug'    => dirname($plugin_file),
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	];
}


/**
 * Show the release notes in the "View version details" window
 *
 * @param false|object|array $result
 * @param string             $action
 * @param object             $args
 *
 * @return false|object|array
 */
function twee_cache_update_details($result, string $action, $args)
{
	if ($action !== 'plugin_information' or ($args->slug ?? '') !== dirname(plugin_basename(__FILE__))) {
		return $result;
	}

	$release = twee_cache_release();

	if (!$release) {
		return $result;
	}

	return (object) [
		'name'          => 'Twee Object Cache',
		'slug'          => $args->slug,
		'version'       => $release['version'],
		'homepage'      => $release['url'],
		'download_link' => $release['package'],
		'sections'      => ['changelog' => $release['notes']],
	];
}
