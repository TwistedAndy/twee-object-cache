<?php
/**
 * Plugin Name: Twee Memcached Object Cache
 * Plugin URI: https://github.com/TwistedAndy/twee-object-cache
 * Description: A high-performance, lightweight Memcached object cache for WordPress and WooCommerce.
 * Version: 1.0.0
 * Author: Andrii Toniievych
 * Author URI: https://www.linkedin.com/in/toniievych/
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * If Memcached isn't available WordPress will automatically
 * fall back to its native in-memory runtime cache.
 */
if (!class_exists('Memcached')) {
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

	private bool $active = false;

	private Memcached $memcached;

	private string $serializer = 'serialize';

	private string $unserializer = 'unserialize';

	public function __construct()
	{
		if (defined('WP_MEMCACHED_PERSISTENT_GROUPS')) {
			$groups = is_string(WP_MEMCACHED_PERSISTENT_GROUPS) ? explode(',', WP_MEMCACHED_PERSISTENT_GROUPS) : WP_MEMCACHED_PERSISTENT_GROUPS;

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

		if (strlen($this->key_salt) > 20) {
			$this->key_salt = substr($this->key_salt, 0, 20);
		}

		$this->memcached = new Memcached('wp_object_cache_pool');

		if (empty($this->memcached->getServerList())) {
			$this->memcached->setOption(Memcached::OPT_NO_BLOCK, false);
			$this->memcached->setOption(Memcached::OPT_NOREPLY, false);
			$this->memcached->setOption(Memcached::OPT_BUFFER_WRITES, false);
			$this->memcached->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
			$this->memcached->setOption(Memcached::OPT_CONNECT_TIMEOUT, 100);
			$this->memcached->setOption(Memcached::OPT_SEND_TIMEOUT, 100000);
			$this->memcached->setOption(Memcached::OPT_RECV_TIMEOUT, 100000);

			$this->memcached->setOption(Memcached::OPT_COMPRESSION, true);
			$this->memcached->setOption(Memcached::OPT_COMPRESSION_TYPE, Memcached::COMPRESSION_FASTLZ);

			if (defined('WP_MEMCACHED_IGBINARY') and WP_MEMCACHED_IGBINARY and Memcached::HAVE_IGBINARY and function_exists('igbinary_serialize')) {
				$this->memcached->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_IGBINARY);
				$this->serializer = 'igbinary_serialize';
				$this->unserializer = 'igbinary_unserialize';
			} else {
				$this->memcached->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_PHP);
			}

			global $memcached_servers;

			if (!empty($memcached_servers)) {
				$this->memcached->addServers($memcached_servers);
			} else {
				$this->memcached->addServer('127.0.0.1', 11211);
			}
		}

		$stats = $this->memcached->getStats();

		if (!empty($stats)) {
			foreach ($stats as $data) {
				if (is_array($data) and isset($data['pid']) and $data['pid'] > 0) {
					$this->active = true;
					break;
				}
			}
		}

		// Flush stale cache if the server was previously offline
		$flag_file = __DIR__ . '/.ocflush';

		if ($this->active) {
			if (file_exists($flag_file)) {
				$this->memcached->flush();
				@unlink($flag_file);
			}
		} else {
			// Mark the cache as offline to trigger a flush on next reconnect
			if (!file_exists($flag_file)) {
				@touch($flag_file);
			}

			if (function_exists('add_action')) {
				add_action('admin_notices', function() {
					$message = '<strong>Memcached Object Cache Error:</strong> Could not connect to any Memcached servers.';

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

		$this->cache_loads++;

		// Check Memcached
		$value = $this->memcached->get($this->build_key($key, $group));
		$code = $this->memcached->getResultCode();

		if ($code === Memcached::RES_SUCCESS) {
			if (is_array($value) and !empty($value['__chunk_list']) and is_array($value['__chunk_list'])) {
				$value = $this->get_chunked_value($value, $group);

				if ($value === false) {
					$this->cache_misses++;
					$found = false;

					return false;
				}
			}

			$found = true;
			$this->cache_hits++;
			$this->cache[$group][$key] = $value; // Store in runtime for subsequent calls

			return $value;
		}

		if ($code !== Memcached::RES_NOTFOUND) {
			$this->handle_error($this->memcached->getResultMessage());
		}

		$this->cache_misses++;
		$found = false;

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
				$key_map[$cache_key] = $key;
			}
		}

		if (empty($cache_keys)) {
			return $values;
		}

		$this->cache_loads++;

		// Bulk fetch missing keys from Memcached
		$cached_values = $this->memcached->getMulti($cache_keys);

		if (is_array($cached_values)) {
			foreach ($cache_keys as $cache_key) {
				$key = $key_map[$cache_key];

				if (array_key_exists($cache_key, $cached_values)) {
					$value = $cached_values[$cache_key];

					if (is_array($value) and !empty($value['__chunk_list']) and is_array($value['__chunk_list'])) {
						$value = $this->get_chunked_value($value, $group);

						if ($value === false) {
							$this->cache_misses++;
							$values[$key] = false;
							continue;
						}
					}

					$this->cache_hits++;
					$this->cache[$group][$key] = $value;
					$values[$key] = $value;
				} else {
					$this->cache_misses++;
					$values[$key] = false;
				}
			}
		} else {
			foreach ($cache_keys as $cache_key) {
				$key = $key_map[$cache_key];
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

		// Skip Memcached if it's non-persistent data
		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			return true;
		}

		$result = $this->memcached->set($this->build_key($key, $group), $data, $expire);

		if (!$result) {
			if ($this->memcached->getResultCode() === Memcached::RES_E2BIG) {
				return $this->set_chunked_value($key, $data, $group, $expire);
			}

			$this->handle_error($this->memcached->getResultMessage());
		}

		return $result;
	}

	public function set_multiple(array $data, string $group = 'default', int $expire = 0): array
	{
		$values = [];
		$cache_values = [];

		foreach ($data as $key => $value) {
			$this->cache[$group][$key] = $value;
			$this->cache_sets++;
			$values[$key] = true;

			// Prepare data for bulk Memcached storage if persistent
			if (!isset($this->non_persistent_groups[$group]) and $this->active) {
				$cache_values[$this->build_key($key, $group)] = $value;
			}
		}

		while (count($this->cache[$group]) > $this->runtime_cache_limit) {
			reset($this->cache[$group]);
			unset($this->cache[$group][key($this->cache[$group])]);
		}

		if (!empty($cache_values)) {
			$this->memcached->setMulti($cache_values, (int) $expire);

			if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
				$this->handle_error($this->memcached->getResultMessage());
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

		$memcached_key = $this->build_key($key, $group);

		// Memcached::add() with OPT_NO_BLOCK may not reliably return false
		// when the key already exists, so verify backend presence first.
		$existing = $this->memcached->get($memcached_key);
		$code = $this->memcached->getResultCode();

		if ($code === Memcached::RES_SUCCESS) {
			return false;
		}

		if ($code !== Memcached::RES_NOTFOUND) {
			$this->handle_error($this->memcached->getResultMessage());

			return false;
		}

		$added = $this->memcached->set($memcached_key, $data, (int) $expire);

		if (!$added and $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND) {
			$this->handle_error($this->memcached->getResultMessage());
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

		if (!array_key_exists($key, $this->cache[$group]) and (isset($this->non_persistent_groups[$group]) or !$this->active)) {
			return false;
		}

		if (!isset($this->non_persistent_groups[$group]) and $this->active) {
			$replaced = $this->memcached->replace($this->build_key($key, $group), $data, (int) $expire);

			if (!$replaced and $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND) {
				$this->handle_error($this->memcached->getResultMessage());
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

		$memcached_key = $this->build_key($key, $group);

		// Check if the item is chunked before deleting
		$value = $this->memcached->get($memcached_key);
		$code = $this->memcached->getResultCode();

		if ($code !== Memcached::RES_SUCCESS and $code !== Memcached::RES_NOTFOUND) {
			$this->handle_error($this->memcached->getResultMessage());
		}

		if (is_array($value) and !empty($value['__chunk_list']) and is_array($value['__chunk_list'])) {
			$chunk_keys = [];

			foreach ($value['__chunk_list'] as $chunk_key) {
				$chunk_keys[] = $this->build_key((string) $chunk_key, $group);
			}

			if (!empty($chunk_keys)) {
				$this->memcached->deleteMulti($chunk_keys);
			}
		}

		$deleted = $this->memcached->delete($memcached_key);
		$code = $this->memcached->getResultCode();

		if (!$deleted and $code !== Memcached::RES_NOTFOUND) {
			$this->handle_error($this->memcached->getResultMessage());
		}

		return ($deleted or $deleted_runtime);
	}

	public function delete_multiple(array $keys, string $group = 'default'): array
	{
		if (empty($keys)) {
			return [];
		}

		$values = [];
		$runtime_group = $this->cache[$group] ?? [];

		foreach ($keys as $key) {
			if (array_key_exists($key, $runtime_group)) {
				unset($this->cache[$group][$key]);
				$values[$key] = true;
			} else {
				$values[$key] = false;
			}
		}

		if (!$this->active or isset($this->non_persistent_groups[$group])) {
			return $values;
		}

		$memcached_keys = [];

		foreach ($keys as $key) {
			$memcached_keys[$key] = $this->build_key($key, $group);
		}

		$delete_keys = array_values($memcached_keys);

		$raw_values = $this->memcached->getMulti($delete_keys);

		if (!is_array($raw_values)) {
			if ($this->memcached->getResultCode() !== Memcached::RES_NOTFOUND) {
				$this->handle_error($this->memcached->getResultMessage());
			}

			$raw_values = [];
		}

		foreach ($raw_values as $raw_value) {
			if (is_array($raw_value) and !empty($raw_value['__chunk_list']) and is_array($raw_value['__chunk_list'])) {
				foreach ($raw_value['__chunk_list'] as $chunk_key) {
					$delete_keys[] = $this->build_key((string) $chunk_key, $group);
				}
			}
		}

		$results = $this->memcached->deleteMulti($delete_keys);

		if (is_array($results)) {
			foreach ($memcached_keys as $key => $memcached_key) {
				if (!empty($results[$memcached_key]) and $values[$key] !== false) {
					$values[$key] = true;
				}
			}
		} else {
			if ($this->memcached->getResultCode() !== Memcached::RES_NOTFOUND) {
				$this->handle_error($this->memcached->getResultMessage());
			}
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

			$value = (int) $value + $offset;

			$this->cache[$group][$key] = $value;

			return $value;
		}

		$value = $this->memcached->increment($this->build_key($key, $group), $offset);
		$code = $this->memcached->getResultCode();

		if ($code === Memcached::RES_SUCCESS) {
			$this->cache[$group][$key] = $value;

			return $value;
		}

		if ($code !== Memcached::RES_NOTFOUND and $code !== Memcached::RES_UNKNOWN_READ_FAILURE) {
			$this->handle_error($this->memcached->getResultMessage());
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

			$value = (int) $value - $offset;
			$this->cache[$group][$key] = $value;

			return $value;
		}

		$cache_key = $this->build_key($key, $group);

		// Memcached's native decrement clamps to 0; use a get/modify/set cycle
		// so the counter can go negative (matching WP core behavior).
		$current = $this->memcached->get($cache_key);
		$code = $this->memcached->getResultCode();

		if ($code === Memcached::RES_NOTFOUND) {
			return false;
		}

		if ($code !== Memcached::RES_SUCCESS) {
			$this->handle_error($this->memcached->getResultMessage());

			return false;
		}

		if (!is_numeric($current)) {
			return false;
		}

		$value = (int) $current - $offset;
		$this->memcached->set($cache_key, (string) $value);
		$this->cache[$group][$key] = $value;

		return $value;
	}

	public function flush(): bool
	{
		$this->flush_runtime();

		if ($this->active) {
			$this->memcached->flush();

			if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
				$this->handle_error($this->memcached->getResultMessage());
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
		// Harvest old keys from runtime cache to perform a partial ghost-key cleanup
		if ($this->active and !isset($this->non_persistent_groups[$group])) {
			$keys_to_delete = [];

			if (isset($this->cache[$group]) and !empty($this->cache[$group])) {
				foreach ($this->cache[$group] as $key => $value) {
					$keys_to_delete[] = $this->build_key($key, $group);
				}
			}

			unset($this->cache[$group]);
		} else {
			unset($this->cache[$group]);

			return true;
		}

		// Clean up the harvested keys from Memcached to minimize stale memory bloat
		if (!empty($keys_to_delete)) {
			$this->memcached->deleteMulti($keys_to_delete);

			if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
				$this->handle_error($this->memcached->getResultMessage());
			}
		}

		if (isset($this->global_groups[$group])) {
			$prefix = '';
		} else {
			$prefix = $this->blog_prefix;
		}

		$namespaced_group = $prefix . $group;

		if (strlen($namespaced_group) > 100) {
			$namespaced_group = substr($namespaced_group, 0, 100);
		}

		$version_key = $this->key_salt . ':group:' . $namespaced_group;
		$version = $this->memcached->increment($version_key);

		if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
			$version = 1;
			$this->memcached->set($version_key, $version);

			if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
				$this->handle_error($this->memcached->getResultMessage());
			}
		}

		// Map the new version in the runtime property
		$this->group_mapping[$group] = $this->key_salt . ':' . $namespaced_group . ':' . $version;

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
		echo '<strong>Engine:</strong> Memcached<br />';
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
	private function handle_error(string $message): void
	{
		$this->active = false;

		trigger_error('Twee Memcached Object Cache: ' . $message, E_USER_WARNING);
	}

	/**
	 * Build the fully qualified cache key
	 */
	private function build_key(int|string $key, string $group): string
	{
		// Memcached rejects keys longer than 250 bytes, so
		// we limit groups to 80 and keys to 160 characters
		if (strlen($key) > 150) {
			$key = substr($key, 0, 150) . substr(hash('sha256', (string) $key), 0, 10);
		}

		return ($this->group_mapping[$group] ?? $this->build_group($group)) . ':' . $key;
	}

	/**
	 * Resolves an array chunked payload back into a single array
	 */
	private function get_chunked_value(array $value, string $group): bool|array
	{
		if (empty($value['__chunk_list']) or !is_array($value['__chunk_list'])) {
			return false;
		}

		$cache_keys = [];

		foreach ($value['__chunk_list'] as $chunk_key) {
			$cache_keys[] = $this->build_key((string) $chunk_key, $group);
		}

		$chunks = $this->memcached->getMulti($cache_keys);

		if (is_array($chunks) and count($chunks) === count($cache_keys)) {
			$assembled = '';

			foreach ($cache_keys as $cache_key) {
				$assembled .= $chunks[$cache_key];
			}

			$unserializer = $this->unserializer;

			return $unserializer($assembled);
		}

		return false;
	}

	/**
	 * Splits a massive payload into chunks and stores them in Memcached
	 */
	private function set_chunked_value(int|string $key, mixed $data, string $group, int $expire): bool
	{
		$serializer = $this->serializer;
		$serialized = $serializer($data);
		$chunks = str_split($serialized, 500000);
		$chunk_keys = [];
		$multi_set = [];

		foreach ($chunks as $index => $chunk) {
			$chunk_key = $key . '_chunk_' . $index;
			$chunk_keys[] = $chunk_key;
			$multi_set[$this->build_key($chunk_key, $group)] = $chunk;
		}

		$this->memcached->setMulti($multi_set, $expire);

		return $this->memcached->set($this->build_key($key, $group), ['__chunk_list' => $chunk_keys], $expire);
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

		$namespaced_group = $prefix . $group;

		if (strlen($namespaced_group) > 70) {
			$namespaced_group = substr($namespaced_group, 0, 70) . substr(hash('sha256', $group), 0, 10);
		}

		$version_key = $this->key_salt . ':group:' . $namespaced_group;
		$version_number = $this->memcached->get($version_key);
		$code = $this->memcached->getResultCode();

		if ($code !== Memcached::RES_SUCCESS) {
			if ($code !== Memcached::RES_NOTFOUND) {
				$this->handle_error($this->memcached->getResultMessage());
			}

			$version_number = 0;
			$this->memcached->set($version_key, $version_number);

			if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
				$this->handle_error($this->memcached->getResultMessage());
			}
		}

		$cache_group = $this->key_salt . ':' . $namespaced_group . ':' . $version_number;

		$this->group_mapping[$group] = $cache_group;

		return $cache_group;
	}

}