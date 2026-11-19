<?php
/**
 * Install, detection and Query Monitor connection of the drop-in
 */

if (!defined('ABSPATH')) {
	exit;
}


/**
 * Get the engine of the installed drop-in
 *
 * @return string The engine (redis, memcached or apcu), or an empty string when there is no drop-in or another plugin installed it
 */
function toc_dropin_engine(): string
{
	$dropin_file = WP_CONTENT_DIR . '/object-cache.php';

	if (!is_file($dropin_file)) {
		return '';
	}

	return match (get_file_data($dropin_file, ['name' => 'Plugin Name'])['name']) {
		'Twee Redis Cache', 'Twee Redis Object Cache' => 'redis',
		'Twee Memcached Cache', 'Twee Memcached Object Cache' => 'memcached',
		'Twee APCu Cache', 'Twee APCu Object Cache' => 'apcu',
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
function toc_dropin_install(string $engine): bool
{
	$source_file = dirname(__DIR__) . '/handlers/' . $engine . '.php';
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
 * Check if the selected cache engine is actually working
 *
 * @param string $ext
 *
 * @return bool
 */
function toc_dropin_is_reachable(string $ext): bool
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
 * Add the Object Cache panels to Query Monitor
 *
 * Without Query Monitor, the drop-in stops the log of the backend calls that WP_DEBUG_LOG started.
 */
function toc_dropin_monitor(): void
{
	global $wp_object_cache;

	// Only the Twee drop-ins have debug_info()
	if (!method_exists($wp_object_cache, 'debug_info')) {
		return;
	}

	if (!class_exists('QM_Collectors')) {
		$wp_object_cache->debug = false;
		$wp_object_cache->commands = [];

		return;
	}

	require_once __DIR__ . '/monitor.php';

	$collector = new Twee_Cache_QM_Collector();

	QM_Collectors::add($collector);

	// Replace the Object Cache panel of Query Monitor (priority 9). The key of each output must be its panel id.
	add_filter('qm/outputter/html', function(array $output) use ($collector): array {
		unset($output['cache']);

		$output['twee-cache'] = new Twee_Cache_QM_Output($collector);
		$output['twee-cache-commands'] = new Twee_Cache_QM_Commands_Output($collector);

		return $output;
	}, 20);

	// Query Monitor shows the panels of plugins after its own panels. The script moves Object Cache below Database Queries in both menus.
	add_action('qm/output/enqueued-assets', function(): void {
		if (!QM_Dispatcher::user_can_view()) {
			return;
		}

		$script = <<<'JS'
(function() {
	var target = %s;

	// The text of a menu item without its badge
	function label(item) {
		var node = item.querySelector('button, a');

		return (node && node.firstChild) ? node.firstChild.textContent.trim() : '';
	}

	function place(after, item) {
		if (after && item && after.nextElementSibling !== item) {
			after.after(item);
		}
	}

	function sort() {
		var bar = document.querySelector('#wp-admin-bar-query-monitor .ab-submenu');
		var root = document.getElementById('query-monitor-container');
		var menu = (root && root.shadowRoot) ? root.shadowRoot.querySelector('#qm-panel-menu > ul') : null;

		if (bar) {
			place(bar.querySelector(':scope > .qm-item-db_queries'), bar.querySelector(':scope > .qm-item-twee-cache'));
		}

		if (menu) {
			var items = Array.from(menu.children);

			place(items.find(function(item) { return label(item) === target; }), items.find(function(item) { return label(item) === 'Object Cache'; }));
		}
	}

	// Query Monitor draws its menus on DOMContentLoaded and draws them again on changes
	window.addEventListener('load', function() {
		var root = document.getElementById('query-monitor-container');
		var bar = document.getElementById('wp-admin-bar-query-monitor');

		sort();

		if (root && root.shadowRoot) {
			new MutationObserver(sort).observe(root.shadowRoot, {childList: true, subtree: true});
		}

		if (bar) {
			new MutationObserver(sort).observe(bar, {childList: true, subtree: true});
		}
	});
})();
JS;

		wp_enqueue_script('query-monitor');
		wp_add_inline_script('query-monitor', sprintf($script, wp_json_encode(__('Database Queries', 'query-monitor'))));
	});
}
