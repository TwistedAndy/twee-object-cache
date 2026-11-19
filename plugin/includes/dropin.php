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

	// Version 1.1 and older name the flush marker .ocflush
	if (is_file(WP_CONTENT_DIR . '/.ocflush')) {
		@rename(WP_CONTENT_DIR . '/.ocflush', WP_CONTENT_DIR . '/oc-flush');
	}

	// OPcache can keep the old drop-in when it does not examine the file time
	if (function_exists('opcache_invalidate')) {
		opcache_invalidate($dropin_file, true);
	}

	return true;
}


/**
 * Test the backend of an engine with the given settings
 *
 * The test connects, then writes, reads and deletes a test key. It does not change the installed drop-in.
 *
 * @param string $engine   redis, memcached or apcu
 * @param array  $settings The settings from toc_settings_get()
 *
 * @return array The result (ok) and a message for the user (message)
 */
function toc_dropin_test(string $engine, array $settings): array
{
	$key = 'toc_test_' . uniqid();

	try {
		if ($engine === 'redis') {
			if (!class_exists('Redis')) {
				return ['ok' => false, 'message' => 'The redis PHP extension is not loaded.'];
			}

			$host = (string) $settings['WP_REDIS_HOST'];
			$port = (str_starts_with($host, 'unix://') or str_starts_with($host, '/')) ? 0 : (int) $settings['WP_REDIS_PORT'];
			$password = $settings['WP_REDIS_PASSWORD'];
			$database = (int) $settings['WP_REDIS_DATABASE'];
			$redis = new Redis();

			if (!$redis->connect($host, $port, (float) $settings['WP_REDIS_TIMEOUT'])) {
				return ['ok' => false, 'message' => 'Cannot connect to Redis at ' . $host . ($port ? ':' . $port : '') . '.'];
			}

			if ($password !== '' and !$redis->auth($password)) {
				return ['ok' => false, 'message' => 'Redis does not accept the password: ' . $redis->getLastError()];
			}

			if ($database > 0 and !$redis->select($database)) {
				return ['ok' => false, 'message' => 'Redis cannot select database ' . $database . ': ' . $redis->getLastError()];
			}

			$start = hrtime(true);
			$redis->ping();
			$latency = hrtime(true) - $start;

			$works = ($redis->set($key, 'ok', 60) and $redis->get($key) === 'ok' and $redis->del($key) === 1);
			$version = $redis->info('server')['redis_version'] ?? '';

			$redis->close();
		} elseif ($engine === 'memcached') {
			if (!class_exists('Memcached')) {
				return ['ok' => false, 'message' => 'The memcached PHP extension is not loaded.'];
			}

			$memcached = new Memcached();

			$memcached->setOption(Memcached::OPT_NO_BLOCK, false);
			$memcached->setOption(Memcached::OPT_NOREPLY, false);
			$memcached->setOption(Memcached::OPT_CONNECT_TIMEOUT, max(1, (int) $settings['WP_MEMCACHED_CONNECT_TIMEOUT']));
			$memcached->setOption(Memcached::OPT_SEND_TIMEOUT, max(0, (int) $settings['WP_MEMCACHED_SEND_TIMEOUT']) * 1000);
			$memcached->setOption(Memcached::OPT_RECV_TIMEOUT, max(0, (int) $settings['WP_MEMCACHED_RECV_TIMEOUT']) * 1000);
			$memcached->addServers($settings['memcached_servers']);

			$start = hrtime(true);
			$versions = array_filter((array) $memcached->getVersion(), fn($version) => $version and $version !== '255.255.255');
			$latency = hrtime(true) - $start;

			if (!$versions) {
				return ['ok' => false, 'message' => 'Cannot connect to Memcached: ' . $memcached->getResultMessage()];
			}

			$works = ($memcached->set($key, 'ok', 60) and $memcached->get($key) === 'ok' and $memcached->delete($key));
			$version = implode(', ', array_unique($versions));

			if (!$works) {
				return ['ok' => false, 'message' => 'Memcached does not save the test value: ' . $memcached->getResultMessage()];
			}
		} elseif ($engine === 'apcu') {
			if (!function_exists('apcu_enabled') or !apcu_enabled()) {
				return ['ok' => false, 'message' => 'APCu is not enabled for the web server. Set apc.enabled to 1.'];
			}

			$start = hrtime(true);
			$works = (apcu_store($key, 'ok', 60) and apcu_fetch($key) === 'ok' and apcu_delete($key));
			$latency = hrtime(true) - $start;
			$version = phpversion('apcu');
		} else {
			return ['ok' => false, 'message' => 'Select Redis, Memcached or APCu.'];
		}
	} catch (Throwable $e) {
		return ['ok' => false, 'message' => $e->getMessage()];
	}

	if (!$works) {
		return ['ok' => false, 'message' => 'The backend does not save the test value.'];
	}

	return ['ok' => true, 'message' => 'The test value was written, read and deleted. Version: ' . $version . '. Latency: ' . number_format($latency / 1e3) . ' µs.'];
}


/**
 * Add the Object Cache panels to Query Monitor
 *
 * Without Query Monitor, the drop-in stops the log of the backend calls that the Tracing setting started.
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
