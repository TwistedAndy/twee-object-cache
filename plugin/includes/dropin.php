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
 * The loaded handler of the request names its engine in the WP_Object_Cache::TWEE_CACHE constant.
 * When the drop-in of the plugin cannot find the handler file, for example after the plugin folder was renamed, the engine comes from TWEE_CACHE_CONFIG.
 * Then the page can disable the cache, and a save or the activation writes the drop-in with the correct path.
 *
 * @return string The engine (redis, memcached or apcu), or an empty string when there is no drop-in or another plugin installed it
 */
function toc_dropin_engine(): string
{
	// The class stays in memory when the drop-in is removed during the request
	if (!is_file(WP_CONTENT_DIR . '/object-cache.php')) {
		return '';
	}

	if (defined('WP_Object_Cache::TWEE_CACHE')) {
		return WP_Object_Cache::TWEE_CACHE;
	}

	return defined('TWEE_CACHE_CONFIG') ? (string) (TWEE_CACHE_CONFIG['handler'] ?? '') : '';
}


/**
 * Write wp-content/object-cache.php with the saved settings. It loads the handler of the saved engine from the plugin folder.
 *
 * Thus, a plugin update also updates the cache code. The file is replaced with a rename, so a request never loads a partly written drop-in.
 *
 * @return bool
 */
function toc_dropin_install(): bool
{
	$settings = (array) get_site_option('toc_settings', []);
	$engine = (string) ($settings['handler'] ?? '');
	$handler_file = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(dirname(__DIR__) . '/twee-cache.php')) . '/handlers/' . $engine . '.php';

	if ($engine === '' or !is_file($handler_file)) {
		return false;
	}

	// A path in wp-content stays correct when the site moves to another folder
	$handler_path = str_starts_with($handler_file, WP_CONTENT_DIR . '/') ? '__DIR__ . ' . var_export(substr($handler_file, strlen(WP_CONTENT_DIR)), true) : var_export($handler_file, true);

	$code = "<?php\n// Twee Cache writes this file. Change the settings on the Cache Settings page. A constant in wp-config.php wins over a setting.\n"
		. 'const TWEE_CACHE_CONFIG = ' . var_export($settings, true) . ";\n\n"
		. "// Without the handler, for example while an update replaces the plugin folder, WordPress uses its own cache.\n"
		. "// Then the backend can keep values that changed in the database, so the handler flushes it when it is back.\n"
		. "if (is_file(" . $handler_path . ")) {\n\trequire " . $handler_path . ";\n} elseif (!is_file(__DIR__ . '/cache-flush')) {\n\t@touch(__DIR__ . '/cache-flush');\n}\n";

	$dropin_file = WP_CONTENT_DIR . '/object-cache.php';
	$temp_file = $dropin_file . '.' . uniqid() . '.tmp';

	if (@file_put_contents($temp_file, $code) === false) {
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

			$host = (string) $settings['redis_host'];
			$port = (str_starts_with($host, 'unix://') or str_starts_with($host, '/')) ? 0 : (int) $settings['redis_port'];
			$password = $settings['redis_password'] ?: '';
			$username = (string) $settings['redis_username'];

			// A Redis 6 ACL user logs in with the name and the password
			if ($username !== '' and is_string($password) and $password !== '') {
				$password = [$username, $password];
			}

			$database = (int) $settings['redis_database'];
			$redis = new Redis();

			if (!$redis->connect($host, $port, (float) $settings['redis_timeout'])) {
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
			$memcached->setOption(Memcached::OPT_CONNECT_TIMEOUT, max(1, (int) $settings['memcached_connect_timeout']));
			$memcached->setOption(Memcached::OPT_SEND_TIMEOUT, max(0, (int) $settings['memcached_send_timeout']) * 1000);
			$memcached->setOption(Memcached::OPT_RECV_TIMEOUT, max(0, (int) $settings['memcached_recv_timeout']) * 1000);
			$memcached->addServers($settings['memcached_servers']);

			$start = hrtime(true);
			$response = (array) $memcached->getVersion();
			$latency = hrtime(true) - $start;
			$versions = [];

			// A server that does not answer has the version 255.255.255
			foreach ($response as $server => $version) {
				if ($version and $version !== '255.255.255') {
					$versions[$server] = $version;
				}
			}

			// The result message only says "SOME ERRORS WERE REPORTED". The last error of libmemcached has the reason, such as "(0x1f) A TIMEOUT OCCURRED,  host: 127.0.0.1:11211 -> version.cc:40".
			if (!$versions) {
				$reason = preg_match('/^(?:\(\w+\)\s*)?([^,]+)/', $memcached->getLastErrorMessage(), $match) ? $match[1] : $memcached->getResultMessage();

				return ['ok' => false, 'message' => 'Cannot connect to Memcached at ' . implode(', ', toc_settings_servers($settings['memcached_servers'])) . '. ' . ucfirst(strtolower(trim($reason))) . '.'];
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
		// Redis answers NOAUTH when its configuration has a password (requirepass or an ACL user) and the settings send none
		if ($engine === 'redis' and str_starts_with($e->getMessage(), 'NOAUTH')) {
			return ['ok' => false, 'message' => 'Redis needs a password, but the Password field is empty. Enter the password from the Redis configuration of the server (requirepass in redis.conf), or define WP_REDIS_PASSWORD in wp-config.php.'];
		}

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
