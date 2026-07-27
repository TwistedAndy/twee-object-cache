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

	private array $cache = [];

	private array $global_groups = [];

	private array $group_mapping = [];

	private array $non_persistent_groups = [];

	private int $runtime_cache_limit = 10000;

	private bool $multisite = false;

	private string $blog_prefix = '';

	private string $key_salt = '';

	private bool $active = true;

	private $redis;

	public function __construct()
	{
		$this->multisite = is_multisite();
		$this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';

		if (defined('WP_CACHE_KEY_SALT')) {
			$this->key_salt = WP_CACHE_KEY_SALT;
		} else {
			$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
			$this->key_salt = preg_replace('/[^a-zA-Z0-9]/', '_', $host);
		}

		if (strlen($this->key_salt) > 20) {
			$this->key_salt = substr($this->key_salt, 0, 20);
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
				$this->redis->connect($host, 0, $timeout, null, $retry_interval);
			} else {
				$this->redis->connect($host, (int) $port, $timeout, null, $retry_interval);
			}

			if (defined('WP_REDIS_PASSWORD')) {
				$this->redis->auth(WP_REDIS_PASSWORD);
			}

			if (defined('WP_REDIS_DATABASE')) {
				$this->redis->select((int) WP_REDIS_DATABASE);
			}

			if (defined('Redis::SERIALIZER_IGBINARY') and extension_loaded('igbinary')) {
				$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
			} else {
				$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
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
		if (!$force and isset($this->cache[$group]) and array_key_exists($key, $this->cache[$group])) {
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

		// Check Redis
		$value = $this->redis->get($this->build_key($key, $group));
		if ($value !== false) {
			$found = true;
			$this->cache_hits++;
			$this->cache[$group][$key] = $value; // Store in runtime for subsequent calls

			return $value;
		}

		$found = false;
		$this->cache_misses++;

		return false;
	}

	public function get_multiple(array $keys, string $group = 'default', bool $force = false): array
	{
		$values = [];
		$cache_keys = [];
		$key_map = [];

		// Resolve runtime cache and identify keys missing from memory
		foreach ($keys as $key) {
			if (!$force and isset($this->cache[$group]) and array_key_exists($key, $this->cache[$group])) {
				$this->cache_hits++;
				$value = $this->cache[$group][$key];
				$values[$key] = $value;
			} elseif (isset($this->non_persistent_groups[$group]) or !$this->active) {
				$this->cache_misses++;
				$values[$key] = false;
			} else {
				$cache_key = $this->build_key($key, $group);
				$cache_keys[] = $cache_key;
				$key_map[] = $key;
			}
		}

		// Bulk fetch missing keys from Redis
		if (!empty($cache_keys)) {
			$cached_values = $this->redis->mGet($cache_keys);

			if (is_array($cached_values)) {
				foreach ($cache_keys as $index => $cache_key) {
					$key = $key_map[$index];

					if ($cached_values[$index] !== false) {
						$this->cache_hits++;
						$this->cache[$group][$key] = $cached_values[$index];
						$values[$key] = $cached_values[$index];
					} else {
						$this->cache_misses++;
						$values[$key] = false;
					}
				}
			} else {
				foreach ($key_map as $key) {
					$this->cache_misses++;
					$values[$key] = false;
				}
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
			return $this->redis->setEx($this->build_key($key, $group), $expire, $data);
		}

		return $this->redis->set($this->build_key($key, $group), $data);
	}

	public function set_multiple(array $data, string $group = 'default', int $expire = 0): array
	{
		$values = [];
		$cache_values = [];

		foreach ($data as $key => $value) {
			$this->cache[$group][$key] = $value;
			$this->cache_sets++;
			$values[$key] = true;

			// Prepare data for bulk Redis storage if persistent
			if (!isset($this->non_persistent_groups[$group]) and $this->active) {
				$cache_values[$this->build_key($key, $group)] = $value;
			}
		}

		while (count($this->cache[$group]) > $this->runtime_cache_limit) {
			reset($this->cache[$group]);
			unset($this->cache[$group][key($this->cache[$group])]);
		}

		if (!empty($cache_values)) {
			$pipe = $this->redis->multi(Redis::PIPELINE);
			foreach ($cache_values as $cache_key => $value) {
				if ($expire > 0) {
					$pipe->setEx($cache_key, $expire, $value);
				} else {
					$pipe->set($cache_key, $value);
				}
			}
			$pipe->exec();
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

		$options = ['nx'];
		if ($expire > 0) {
			$options['ex'] = $expire;
		}

		$added = $this->redis->set($this->build_key($key, $group), $data, $options);

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

		if (!array_key_exists($key, $this->cache[$group]) and (isset($this->non_persistent_groups[$group]) or !$this->active)) {
			return false;
		}

		if (!isset($this->non_persistent_groups[$group]) and $this->active) {
			$options = ['xx'];
			if ($expire > 0) {
				$options['ex'] = $expire;
			} else {
				$options[] = 'keepttl';
			}

			$cache_key = $this->build_key($key, $group);
			$replaced = $this->redis->set($cache_key, $data, $options);

			if ($replaced === false and $expire === 0 and $this->redis->getLastError()) {
				$this->redis->clearLastError();
				$replaced = $this->redis->set($cache_key, $data, ['xx']);
			}

			if ($replaced) {
				$this->cache[$group][$key] = $data;
				$this->cache_sets++;
			}

			return (bool) $replaced;
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

		$cache_key = $this->build_key($key, $group);

		if (method_exists($this->redis, 'unlink')) {
			$deleted = (bool) $this->redis->unlink($cache_key);
		} else {
			$deleted = (bool) $this->redis->del($cache_key);
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

		$cache_key = $this->build_key($key, $group);
		$value = $this->redis->get($cache_key);

		if ($value === false) {
			return false;
		}

		$value = max(0, (int) $value + $offset);
		$result = $this->redis->set($cache_key, $value, ['keepttl']);

		if ($result === false and $this->redis->getLastError()) {
			$this->redis->clearLastError();
			$result = $this->redis->set($cache_key, $value);
		}

		if ($result) {
			$this->cache[$group][$key] = $value;

			return $value;
		}

		return false;
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

		$cache_key = $this->build_key($key, $group);
		$value = $this->redis->get($cache_key);

		if ($value === false) {
			return false;
		}

		$value = max(0, (int) $value - $offset);
		$result = $this->redis->set($cache_key, $value, ['keepttl']);

		if ($result === false and $this->redis->getLastError()) {
			$this->redis->clearLastError();
			$result = $this->redis->set($cache_key, $value);
		}

		if ($result) {
			$this->cache[$group][$key] = $value;

			return $value;
		}

		return false;
	}

	public function flush(): bool
	{
		$this->flush_runtime();

		if ($this->active) {
			try {
				$this->redis->flushDb(true); // Async flush
			} catch (Exception $e) {
				$this->redis->flushDb();
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

		if (isset($this->global_groups[$group])) {
			$prefix = '';
		} else {
			$prefix = $this->blog_prefix;
		}

		$version_key = $this->key_salt . ':group:' . $prefix . $group;
		$version = $this->redis->get($version_key);

		if ($version === false or !is_numeric($version)) {
			$version = 1;
		} else {
			$version = (int) $version;
			$version++;
		}

		$this->redis->set($version_key, $version);

		// Map the new version in the runtime property
		$this->group_mapping[$group] = $this->key_salt . ':' . $prefix . $group . ':' . $version;

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
				$this->non_persistent_groups[(string) $group] = true;
			}
		} else {
			$this->non_persistent_groups[$groups] = true;
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
		echo '<strong>Cache Misses:</strong> ' . esc_html($this->cache_misses) . '<br />';
		echo '<strong>Cache Sets:</strong> ' . esc_html($this->cache_sets) . '<br />';
		echo '</p>';
	}

	/**
	 * Build the fully qualified cache key
	 */
	private function build_key(int|string $key, string $group): string
	{
		return ($this->group_mapping[$group] ?? $this->build_group($group)) . ':' . $key;
	}

	/**
	 * Build the group cache key
	 */
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

		$version_key = $this->key_salt . ':group:' . $prefix . $group;
		$version_number = $this->redis->get($version_key);

		if ($version_number === false or !is_numeric($version_number)) {
			$version_number = 0;
			$this->redis->set($version_key, $version_number);
		} else {
			$version_number = (int) $version_number;
		}

		$cache_group = $this->key_salt . ':' . $prefix . $group . ':' . $version_number;

		$this->group_mapping[$group] = $cache_group;

		return $cache_group;
	}

}
