<?php
/**
 * Plugin Name: Twee Redis Object Cache
 * Plugin URI: https://github.com/TwistedAndy/twee-object-cache
 * Description: A high-performance, lightweight Redis object cache for WordPress and WooCommerce.
 * Version: 1.0.0
 * Author: Andrii Toniievych
 * Author URI: https://www.linkedin.com/in/toniievych/
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * If Redis isn't available WordPress will automatically
 * fall back to its native in-memory runtime cache.
 */
if (!class_exists('Redis')) {
	return;
}

/**
 * Initialize the cache.
 */
function wp_cache_init()
{
	global $wp_object_cache;

	if (!$wp_object_cache instanceof WP_Object_Cache) {
		$wp_object_cache = new WP_Object_Cache();
		$GLOBALS['wp_object_cache'] = $wp_object_cache;
	}
}

function wp_cache_add($key, $data, $group = 'default', $expire = 0)
{
	global $wp_object_cache;

	return $wp_object_cache->add($key, $data, $group, (int) $expire);
}

function wp_cache_add_multiple(array $data, $group = 'default', $expire = 0)
{
	global $wp_object_cache;

	return $wp_object_cache->add_multiple($data, $group, (int) $expire);
}

function wp_cache_set($key, $data, $group = 'default', $expire = 0)
{
	global $wp_object_cache;

	return $wp_object_cache->set($key, $data, $group, (int) $expire);
}

function wp_cache_set_multiple(array $data, $group = 'default', $expire = 0)
{
	global $wp_object_cache;

	return $wp_object_cache->set_multiple($data, $group, (int) $expire);
}

function wp_cache_get($key, $group = 'default', $force = false, &$found = null)
{
	global $wp_object_cache;

	return $wp_object_cache->get($key, $group, $force, $found);
}

function wp_cache_get_multiple($keys, $group = 'default', $force = false)
{
	global $wp_object_cache;

	return $wp_object_cache->get_multiple($keys, $group, $force);
}

function wp_cache_replace($key, $data, $group = 'default', $expire = 0)
{
	global $wp_object_cache;

	return $wp_object_cache->replace($key, $data, $group, (int) $expire);
}

function wp_cache_delete($key, $group = 'default')
{
	global $wp_object_cache;

	return $wp_object_cache->delete($key, $group);
}

function wp_cache_delete_multiple(array $keys, $group = 'default')
{
	global $wp_object_cache;

	return $wp_object_cache->delete_multiple($keys, $group);
}

function wp_cache_incr($key, $offset = 1, $group = 'default')
{
	global $wp_object_cache;

	return $wp_object_cache->incr($key, (int) $offset, $group);
}

function wp_cache_decr($key, $offset = 1, $group = 'default')
{
	global $wp_object_cache;

	return $wp_object_cache->decr($key, (int) $offset, $group);
}

function wp_cache_flush()
{
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_flush_runtime()
{
	global $wp_object_cache;

	return $wp_object_cache->flush_runtime();
}

function wp_cache_flush_group($group)
{
	global $wp_object_cache;

	return $wp_object_cache->flush_group($group);
}

function wp_cache_supports($feature)
{
	static $features = [
		'add_multiple'    => true,
		'set_multiple'    => true,
		'get_multiple'    => true,
		'delete_multiple' => true,
		'flush_runtime'   => true,
		'flush_group'     => true,
	];

	return isset($features[$feature]);
}

function wp_cache_add_global_groups($groups)
{
	global $wp_object_cache;
	$wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups($groups)
{
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups($groups);
}

function wp_cache_switch_to_blog($blog_id)
{
	global $wp_object_cache;
	$wp_object_cache->switch_to_blog($blog_id);
}

function wp_cache_close()
{
	return true;
}

function wp_cache_reset()
{
	_deprecated_function(__FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()');

	return false;
}

/**
 * Core WP_Object_Cache class
 */
#[AllowDynamicProperties]
class WP_Object_Cache {

	public int $cache_hits = 0;

	public int $cache_misses = 0;

	public int $cache_sets = 0;

	public int $cache_loads = 0;

	private array $cache = [];

	private array $global_groups = [];

	private array $group_mapping = [];

	private array $persistent_groups = [
		'plugins' => true,
		'themes'  => true,
		'counts'  => true,
	];

	private array $non_persistent_groups = [];

	private int $runtime_cache_limit = 10000;

	private bool $multisite = false;

	private string $blog_prefix = '';

	private string $key_salt = '';

	private bool $active = true;

	private Redis|Relay\Relay $redis;

	public function __construct()
	{
		if (defined('WP_REDIS_PERSISTENT_GROUPS')) {
			$groups = is_string(WP_REDIS_PERSISTENT_GROUPS) ? explode(',', WP_REDIS_PERSISTENT_GROUPS) : WP_REDIS_PERSISTENT_GROUPS;

			if (is_array($groups)) {
				$this->persistent_groups = [];

				foreach ($groups as $group) {
					$this->persistent_groups[trim($group)] = true;
				}
			}
		}

		if (is_multisite()) {
			$this->multisite = true;
			$this->blog_prefix = get_current_blog_id() . ':';
		}

		if (defined('WP_CACHE_KEY_SALT')) {
			$this->key_salt = WP_CACHE_KEY_SALT;
		} else {
			$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
			$this->key_salt = preg_replace('/[^a-zA-Z0-9]/', '_', $host);
		}

		if (strlen($this->key_salt) > 10) {
			$this->key_salt = substr($this->key_salt, 0, 10);
		}

		if (defined('WP_REDIS_USE_RELAY') and WP_REDIS_USE_RELAY and class_exists('Relay\Relay')) {
			$this->redis = new Relay\Relay();
		} else {
			$this->redis = new Redis();
		}

		$host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
		$port = defined('WP_REDIS_PORT') ? WP_REDIS_PORT : 6379;

		$timeout = defined('WP_REDIS_TIMEOUT') ? WP_REDIS_TIMEOUT : 1;
		$retry_interval = defined('WP_REDIS_RETRY_INTERVAL') ? WP_REDIS_RETRY_INTERVAL : 100;

		try {

			if (strpos($host, 'unix://') === 0 or strpos($host, '/') === 0) {
				$port = 0;
			}

			if (!defined('WP_REDIS_PERSISTENT_CONNECT') or WP_REDIS_PERSISTENT_CONNECT) {
				$this->redis->pconnect($host, $port, $timeout, null, $retry_interval);
			} else {
				$this->redis->connect($host, $port, $timeout, null, $retry_interval);
			}

			if (defined('WP_REDIS_PASSWORD')) {
				$this->redis->auth(WP_REDIS_PASSWORD);
			}

			if (defined('WP_REDIS_DATABASE')) {
				$this->redis->select((int) WP_REDIS_DATABASE);
			}

			if ((!defined('WP_REDIS_IGBINARY') or WP_REDIS_IGBINARY) and defined('Redis::SERIALIZER_IGBINARY') and extension_loaded('igbinary')) {
				$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
			} else {
				$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
			}

			if (!defined('WP_REDIS_COMPRESSION') or WP_REDIS_COMPRESSION) {
				if (defined('Redis::COMPRESSION_ZSTD')) {
					$this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
				} elseif (defined('Redis::COMPRESSION_LZ4')) {
					$this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
				} elseif (defined('Redis::COMPRESSION_LZF')) {
					$this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);
				}
			}

			// Flush stale cache if the server was previously offline
			$flag_file = __DIR__ . '/.ocflush';

			if (file_exists($flag_file)) {
				$this->redis->flushDb();
				@unlink($flag_file);
			}
		} catch (Exception $e) {
			$this->active = false;
			$error = $e->getMessage();

			// Mark the cache as offline to trigger a flush on next reconnect
			$flag_file = __DIR__ . '/.ocflush';

			if (!file_exists($flag_file)) {
				@touch($flag_file);
			}

			if (function_exists('add_action')) {
				add_action('admin_notices', function() use ($error) {
					$message = '<strong>Redis Object Cache Error:</strong> ' . esc_html($error);

					if (function_exists('wp_admin_notice')) {
						wp_admin_notice($message, ['type' => 'error']);
					} else {
						echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
					}
				});
			}
		}
	}

	public function get(int|string $key, string $group = 'default', bool|null $force = false, &$found = null): mixed
	{
		// Check runtime cache first
		if ($force) {
			// Force a fresh read from Redis: drop any stale runtime entry
			if (isset($this->cache[$group])) {
				unset($this->cache[$group][$key]);
			}
		} elseif (isset($this->cache[$group]) and array_key_exists($key, $this->cache[$group])) {
			$found = true;
			$this->cache_hits++;

			return $this->cache[$group][$key];
		}

		// If it's a non-persistent group and wasn't in runtime, it's a miss
		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			$found = false;
			$this->cache_misses++;

			return false;
		}

		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		$this->cache_loads++;

		try {
			$value = $this->redis->hGet($this->build_group($group), $key);

			if ($value !== false) {
				// Values with TTL and boolean falses are stored as an array
				if (is_array($value) and array_key_exists('_d', $value)) {
					if (isset($value['_t']) and time() > (int) $value['_t']) {
						$found = false;
						$this->cache_misses++;

						return false;
					}

					$value = $value['_d'];
				}

				$found = true;
				$this->cache_hits++;
				$this->cache[$group][$key] = $value;

				return $value;
			}
		} catch (Exception $e) {
			$this->handle_error($e);
		}

		$found = false;
		$this->cache_misses++;

		return false;
	}

	public function get_multiple(array $keys, string $group = 'default', bool $force = false): array
	{
		$values = [];
		$key_map = [];

		$is_runtime_group = (!$this->active or isset($this->non_persistent_groups[$group]));

		$runtime_cache = $this->cache[$group] ?? [];

		// Resolve runtime cache and identify keys missing from memory
		foreach ($keys as $key) {
			if (!$force and array_key_exists($key, $runtime_cache)) {
				$this->cache_hits++;
				$values[$key] = $this->cache[$group][$key];
			} elseif ($is_runtime_group) {
				$this->cache_misses++;
				$values[$key] = false;
			} else {
				$key_map[] = $key;
			}
		}

		if (empty($key_map)) {
			return $values;
		}

		$time = time();
		$group_key = $this->build_group($group);

		try {
			$cached_values = $this->redis->hMGet($group_key, $key_map);
		} catch (Exception $e) {
			$this->handle_error($e);
			$cached_values = false;
		}

		$this->cache_loads++;

		if (is_array($cached_values)) {
			foreach ($key_map as $key) {
				$cached_value = $cached_values[$key] ?? false;

				if ($cached_value === false) {
					$this->cache_misses++;
					$values[$key] = false;
					continue;
				}

				if (is_array($cached_value) and array_key_exists('_d', $cached_value)) {
					if (isset($cached_value['_t']) and $time > (int) $cached_value['_t']) {
						$this->cache_misses++;
						$values[$key] = false;
						continue;
					}

					$cached_value = $cached_value['_d'];
				}

				$this->cache_hits++;
				$this->cache[$group][$key] = $cached_value;
				$values[$key] = $cached_value;
			}
		} else {
			foreach ($key_map as $key) {
				$this->cache_misses++;
				$values[$key] = false;
			}
		}

		return $values;
	}

	public function set(int|string $key, mixed $data, string $group = 'default', int $expire = 0): bool
	{
		// Always store in runtime cache
		$this->cache[$group][$key] = $data;
		$this->cache_sets++;

		if (count($this->cache[$group]) > $this->runtime_cache_limit) {
			reset($this->cache[$group]);
			unset($this->cache[$group][key($this->cache[$group])]);
		}

		// Skip Redis if it's non-persistent data
		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			return true;
		}

		if ($expire > 0) {
			$data = [
				'_t' => time() + $expire,
				'_d' => $data,
			];
		} elseif ($data === false) {
			$data = [
				'_d' => false
			];
		}

		try {
			$result = $this->redis->hSet($this->build_group($group), $key, $data);
		} catch (Exception $e) {
			$this->handle_error($e);

			return true;
		}

		return $result !== false;
	}

	public function set_multiple(array $data, string $group = 'default', int $expire = 0): array
	{
		$values = [];
		$cache_values = [];

		$is_cached_group = ($this->active and !isset($this->non_persistent_groups[$group]));

		foreach ($data as $key => $value) {
			$this->cache[$group][$key] = $value;
			$this->cache_sets++;
			$values[$key] = true;

			if ($value === false) {
				$cache_values[$key] = ['_d' => false];
			} else {
				$cache_values[$key] = $value;
			}
		}

		while (count($this->cache[$group]) > $this->runtime_cache_limit) {
			reset($this->cache[$group]);
			unset($this->cache[$group][key($this->cache[$group])]);
		}

		if ($is_cached_group and !empty($cache_values)) {
			$group_key = $this->build_group($group);

			if ($expire > 0) {
				$time = time() + $expire;
				foreach ($cache_values as $key => $value) {
					$cache_values[$key] = [
						'_t' => $time,
						'_d' => $value,
					];
				}
			}

			try {
				$this->redis->hMSet($group_key, $cache_values);
			} catch (Exception $e) {
				$this->handle_error($e);
			}
		}

		return $values;
	}

	public function add(int|string $key, mixed $data, string $group = 'default', int $expire = 0): bool
	{
		if (wp_suspend_cache_addition()) {
			return false;
		}

		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		if (array_key_exists($key, $this->cache[$group])) {
			return false;
		}

		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			$this->cache[$group][$key] = $data;
			$this->cache_sets++;

			return true;
		}

		if ($expire > 0) {
			$data_to_store = [
				'_t' => time() + $expire,
				'_d' => $data,
			];
		} elseif ($data === false) {
			$data_to_store = ['_d' => false];
		} else {
			$data_to_store = $data;
		}

		$group_key = $this->build_group($group);

		$added = true;

		try {
			// Use atomic hSetNx for the primary insertion to prevent race conditions
			if (!$this->redis->hSetNx($group_key, $key, $data_to_store)) {
				// Key already exists, check if it's expired
				$existing = $this->redis->hGet($group_key, $key);

				if (is_array($existing) and array_key_exists('_d', $existing)
					and isset($existing['_t']) and time() > (int) $existing['_t']) {
					// It's expired, so we are allowed to overwrite it
					$added = $this->redis->hSet($group_key, $key, $data_to_store) !== false;
				} else {
					$added = false;
				}
			}
		} catch (Exception $e) {
			$this->handle_error($e);
		}

		if ($added) {
			$this->cache[$group][$key] = $data;
			$this->cache_sets++;
		}

		return $added;
	}

	public function add_multiple(array $data, string $group = 'default', int $expire = 0): array
	{
		$values = [];

		foreach ($data as $key => $value) {
			$values[$key] = $this->add($key, $value, $group, $expire);
		}

		return $values;
	}

	public function replace(int|string $key, mixed $data, string $group = 'default', int $expire = 0): bool
	{
		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		$is_cached_group = ($this->active and !isset($this->non_persistent_groups[$group]));

		if (!array_key_exists($key, $this->cache[$group]) and !$is_cached_group) {
			return false;
		}

		if ($is_cached_group) {
			$group_key = $this->build_group($group);
			$replaced = false;

			try {
				if ($this->redis->hExists($group_key, $key)) {
					$existing = $this->redis->hGet($group_key, $key);

					if (!(is_array($existing) and array_key_exists('_d', $existing)
						and isset($existing['_t']) and time() > (int) $existing['_t'])) {
						if ($expire > 0) {
							$data_to_store = [
								'_t' => time() + $expire,
								'_d' => $data,
							];
						} elseif ($data === false) {
							$data_to_store = ['_d' => false];
						} else {
							$data_to_store = $data;
						}
						$this->redis->hSet($group_key, $key, $data_to_store);
						$replaced = true;
					}
				}
			} catch (Exception $e) {
				$this->handle_error($e);
				$replaced = true;
			}

			if ($replaced) {
				$this->cache[$group][$key] = $data;
				$this->cache_sets++;
			}

			return $replaced;
		}

		return $this->set($key, $data, $group, $expire);
	}

	public function delete(int|string $key, string $group = 'default'): bool
	{
		$deleted_runtime = false;

		if (isset($this->cache[$group]) and array_key_exists($key, $this->cache[$group])) {
			unset($this->cache[$group][$key]);
			$deleted_runtime = true;
		}

		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			return $deleted_runtime;
		}

		try {
			$deleted = (bool) $this->redis->hDel($this->build_group($group), $key);
		} catch (Exception $e) {
			$this->handle_error($e);
			return $deleted_runtime;
		}

		return ($deleted or $deleted_runtime);
	}

	public function delete_multiple(array $keys, string $group = 'default'): array
	{
		$values = [];

		foreach ($keys as $key) {
			$values[$key] = $this->delete($key, $group);
		}

		return $values;
	}

	public function incr(int|string $key, int $offset = 1, string $group = 'default'): int|bool
	{
		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			$value = $this->get($key, $group);

			if ($value === false) {
				return false;
			}

			$value = max(0, (int) $value + $offset);

			$this->cache[$group][$key] = $value;

			return $value;
		}

		$group_key = $this->build_group($group);

		try {
			$value = $this->redis->hGet($group_key, $key);

			if ($value === false) {
				return false;
			}

			if (is_array($value) and array_key_exists('_d', $value)) {
				if (isset($value['_t']) and time() > (int) $value['_t']) {
					return false;
				}

				$new_value = max(0, (int) $value['_d'] + $offset);
				$value['_d'] = $new_value;
				$this->redis->hSet($group_key, $key, $value);
				$value = $new_value;
			} else {
				$value = max(0, (int) $value + $offset);
				$this->redis->hSet($group_key, $key, $value);
			}
		} catch (Exception $e) {
			$this->handle_error($e);
			return false;
		}

		$this->cache[$group][$key] = $value;

		return $value;
	}

	public function decr(int|string $key, int $offset = 1, string $group = 'default'): int|bool
	{
		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			$value = $this->get($key, $group);

			if ($value === false) {
				return false;
			}

			$value = max(0, (int) $value - $offset);
			$this->cache[$group][$key] = $value;

			return $value;
		}

		$group_key = $this->build_group($group);

		try {
			$value = $this->redis->hGet($group_key, $key);

			if ($value === false) {
				return false;
			}

			if (is_array($value) and array_key_exists('_d', $value)) {
				if (isset($value['_t']) and time() > (int) $value['_t']) {
					return false;
				}

				$new_value = max(0, (int) $value['_d'] - $offset);
				$value['_d'] = $new_value;
				$this->redis->hSet($group_key, $key, $value);
				$value = $new_value;
			} else {
				$value = max(0, (int) $value - $offset);
				$this->redis->hSet($group_key, $key, $value);
			}
		} catch (Exception $e) {
			$this->handle_error($e);
			return false;
		}

		$this->cache[$group][$key] = $value;

		return $value;
	}

	public function flush(): bool
	{
		$this->flush_runtime();

		if ($this->active) {
			try {
				$this->redis->flushDb(true); // Async flush
			} catch (Exception $e) {
				$this->handle_error($e);
			}
		}

		return true;
	}

	public function flush_runtime(): bool
	{
		$this->cache = [];
		$this->group_mapping = [];

		return true;
	}

	public function flush_group(string $group): bool
	{
		unset($this->cache[$group]);

		if (!$this->active or isset($this->non_persistent_groups[$group])) {
			return true;
		}

		$group_key = $this->build_group($group);

		try {
			if (method_exists($this->redis, 'unlink')) {
				$this->redis->unlink($group_key);
			} else {
				$this->redis->del($group_key);
			}
		} catch (Exception $e) {
			$this->handle_error($e);
		}

		return true;
	}

	public function add_global_groups(string|array $groups): void
	{
		if (is_array($groups)) {
			foreach ($groups as $group) {
				$this->global_groups[(string) $group] = true;
			}
		} else {
			$this->global_groups[$groups] = true;
		}
	}

	public function add_non_persistent_groups(string|array $groups): void
	{
		if (is_array($groups)) {
			foreach ($groups as $group) {
				if (!isset($this->persistent_groups[(string) $group])) {
					$this->non_persistent_groups[(string) $group] = true;
				}
			}
		} else {
			if (!isset($this->persistent_groups[$groups])) {
				$this->non_persistent_groups[$groups] = true;
			}
		}
	}

	public function switch_to_blog(int $blog_id): void
	{
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
		$this->group_mapping = [];

		foreach (array_keys($this->cache) as $group) {
			if (!isset($this->global_groups[$group])) {
				unset($this->cache[$group]);
			}
		}
	}

	public function stats(): void
	{
		echo '<p>';
		echo '<strong>Engine:</strong> Redis<br />';
		echo '<strong>Cache Hits:</strong> ' . esc_html($this->cache_hits) . '<br />';
		echo '<strong>Cache Loads:</strong> ' . esc_html($this->cache_loads) . '<br />';
		echo '<strong>Cache Misses:</strong> ' . esc_html($this->cache_misses) . '<br />';
		echo '<strong>Cache Sets:</strong> ' . esc_html($this->cache_sets) . '<br />';
		echo '</p>';
	}

	/**
	 * Deactivate the cache for the rest of a request and trigger
	 * a failure via trigger_error() with WP_DEBUG support
	 */
	private function handle_error(Exception $e): void
	{
		$this->active = false;

		trigger_error('Twee Redis Object Cache: ' . $e->getMessage(), E_USER_NOTICE);
	}

	private function build_group(string $group = 'default'): string
	{
		if (isset($this->group_mapping[$group])) {
			return $this->group_mapping[$group];
		}

		if (isset($this->global_groups[$group])) {
			$prefix = '';
		} else {
			$prefix = $this->blog_prefix;
		}

		$cache_group = $this->key_salt . ':' . $prefix . $group;

		$this->group_mapping[$group] = $cache_group;

		return $cache_group;
	}

}