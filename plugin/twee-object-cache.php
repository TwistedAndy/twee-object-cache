<?php
/**
 * Plugin Name: Twee Object Cache
 * Plugin URI: https://github.com/TwistedAndy/twee-object-cache
 * Description: A suite of high-performance, lightweight object caches built for large-scale WordPress projects.
 * Author: Andrii Toniievych
 * Author URI: https://www.linkedin.com/in/toniievych/
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_bar_menu', 'twee_object_cache_admin_bar', 100);

/**
 * Clear object cache on activation
 */
register_activation_hook(__FILE__, function(): void {
	wp_cache_flush();
});

/**
 * Clear object cache on deactivation
 */
register_deactivation_hook(__FILE__, function(): void {
	wp_cache_flush();

	$dropin_file = WP_CONTENT_DIR . '/object-cache.php';

	if (file_exists($dropin_file)) {
		unlink($dropin_file);
	}
});

/**
 * Add a notice when it's not possible to activate the cache engine
 */
if (isset($_GET['twee_cache_error']) and $_GET['twee_cache_error'] === 'unreachable') {
	add_action('admin_notices', function() {
		$message = 'The selected cache engine is not reachable. Please check your configuration.';

		if (function_exists('wp_admin_notice')) {
			wp_admin_notice($message, ['type' => 'error', 'dismissible' => true,]);
		} else {
			echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
		}
	});
}

/**
 * Add the Object Cache menu in the WordPress Admin Bar
 */
function twee_object_cache_admin_bar($admin_bar): void
{
	if (!current_user_can('manage_options')) {
		return;
	}

	// Handle actions (enable, disable, flush)
	$action = $_GET['twee_cache_action'] ?? '';

	if ($action and wp_verify_nonce($_GET['_wpnonce'] ?? '', 'twee_cache_action')) {
		$dropin_file = WP_CONTENT_DIR . '/object-cache.php';
		$redirect_url = remove_query_arg(['twee_cache_action', '_wpnonce', 'twee_cache_error']);

		if ($action === 'flush') {
			wp_cache_flush();
		} elseif ($action === 'disable' and file_exists($dropin_file)) {
			unlink($dropin_file);
		} elseif (strpos($action, 'enable_') === 0) {
			$engine = str_replace('enable_', '', $action);

			if (twee_object_cache_is_reachable($engine)) {
				$source_file = plugin_dir_path(__FILE__) . $engine . '/object-cache.php';

				if (file_exists($source_file)) {
					copy($source_file, $dropin_file);
					wp_cache_flush();

					$redirect_url = add_query_arg([
						'twee_cache_action' => 'flush',
						'_wpnonce'          => wp_create_nonce('twee_cache_action')
					], $redirect_url);
				}
			} else {
				$redirect_url = add_query_arg('twee_cache_error', 'unreachable', $redirect_url);
			}
		}

		wp_redirect($redirect_url);
		exit;
	}

	$admin_bar->add_node([
		'id'    => 'twee-object-cache',
		'title' => 'Object Cache',
		'href'  => '#',
	]);

	if (file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
		global $wp_object_cache;

		if (is_object($wp_object_cache) and method_exists($wp_object_cache, 'stats')) {
			ob_start();
			$wp_object_cache->stats();
			$stats_html = ob_get_clean();
		} else {
			$stats_html = '';
		}

		if ($stats_html) {
			$stats_html = strip_tags($stats_html, '<strong><br>');
			$lines = preg_split('/<br\s*\/?>/i', $stats_html, -1, PREG_SPLIT_NO_EMPTY);

			foreach ($lines as $line) {
				if (strpos($line, 'Engine:') !== false) {
					$admin_bar->add_node([
						'id'     => 'twee-cache-stats',
						'parent' => 'twee-object-cache',
						'title'  => trim($line),
					]);
					break;
				}
			}
		}

		$flush_url = wp_nonce_url(add_query_arg('twee_cache_action', 'flush'), 'twee_cache_action');
		$admin_bar->add_node([
			'id'     => 'twee-cache-flush',
			'parent' => 'twee-object-cache',
			'title'  => 'Clean Cache',
			'href'   => $flush_url,
		]);

		$disable_url = wp_nonce_url(add_query_arg('twee_cache_action', 'disable'), 'twee_cache_action');
		$admin_bar->add_node([
			'id'     => 'twee-cache-disable',
			'parent' => 'twee-object-cache',
			'title'  => 'Disable Cache',
			'href'   => $disable_url,
		]);
	} else {

		// Check available extensions
		$engines = [
			'memcached' => 'Memcached',
			'redis'     => 'Redis',
			'apcu'      => 'APCu'
		];
		$available = [];

		foreach ($engines as $ext => $name) {
			if (extension_loaded($ext)) {
				$available[$ext] = $name;
			}
		}

		if (empty($available)) {
			$admin_bar->add_node([
				'id'     => 'twee-cache-no-ext',
				'parent' => 'twee-object-cache',
				'title'  => 'No extensions available',
			]);
		} else {
			foreach ($available as $ext => $name) {
				$enable_url = wp_nonce_url(add_query_arg('twee_cache_action', 'enable_' . $ext), 'twee_cache_action');
				$admin_bar->add_node([
					'id'     => 'twee-cache-enable-' . $ext,
					'parent' => 'twee-object-cache',
					'title'  => 'Enable ' . $name,
					'href'   => $enable_url,
				]);
			}
		}
	}
}


/**
 * Check if the selected cache engine is actually working
 */
function twee_object_cache_is_reachable($ext): bool
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

		foreach ($stats as $server => $data) {
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
