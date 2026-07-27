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

	private array $cache = [];

	private array $global_groups = [];

	private array $group_mapping = [];

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
		if (function_exists('igbinary_serialize')) {
			$this->serializer = 'igbinary_serialize';
			$this->unserializer = 'igbinary_unserialize';
		}

		if (is_multisite()) {
			$this->multisite = true;
			$this->blog_prefix = get_current_blog_id();
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
			$this->memcached->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
			$this->memcached->setOption(Memcached::OPT_TCP_NODELAY, true);
			$this->memcached->setOption(Memcached::OPT_NO_BLOCK, true);
			$this->memcached->setOption(Memcached::OPT_COMPRESSION, true);
			$this->memcached->setOption(Memcached::OPT_COMPRESSION_TYPE, Memcached::COMPRESSION_FASTLZ);

			if (Memcached::HAVE_IGBINARY) {
				$this->memcached->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_IGBINARY);
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

		// Check Memcached
		$value = $this->memcached->get($this->build_key($key, $group));

		if ($this->memcached->getResultCode() === Memcached::RES_SUCCESS) {
			if (is_array($value) and isset($value['__chunk_list'])) {
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
				$key_map[$cache_key] = $key;
			}
		}

		// Bulk fetch missing keys from Memcached
		if (!empty($cache_keys)) {
			$cached_values = $this->memcached->getMulti($cache_keys);

			if (is_array($cached_values)) {
				foreach ($cache_keys as $cache_key) {
					$key = $key_map[$cache_key];

					if (array_key_exists($cache_key, $cached_values)) {
						$value = $cached_values[$cache_key];

						if (is_array($value) and isset($value['__chunk_list'])) {
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

		if (!$result and $this->memcached->getResultCode() === Memcached::RES_E2BIG) {
			return $this->set_chunked_value($key, $data, $group, $expire);
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

		$added = $this->memcached->add($this->build_key($key, $group), $data, (int) $expire);

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

		return ($this->memcached->delete($this->build_key($key, $group)) or $deleted_runtime);
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

		$value = $this->memcached->increment($this->build_key($key, $group), $offset);

		if ($this->memcached->getResultCode() === Memcached::RES_SUCCESS) {
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

		// Memcached handles decrementing natively and never drops below 0
		$value = $this->memcached->decrement($this->build_key($key, $group), $offset);

		if ($this->memcached->getResultCode() === Memcached::RES_SUCCESS) {
			$this->cache[$group][$key] = $value;

			return $value;
		}

		return false;
	}

	public function flush(): bool
	{
		$this->flush_runtime();

		if ($this->active) {
			$this->memcached->flush();
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
		}

		if (isset($this->global_groups[$group])) {
			$prefix = '';
		} else {
			$prefix = $this->blog_prefix;
		}

		$version_key = $this->key_salt . ':group:' . $prefix . $group;
		$version = $this->memcached->increment($version_key);

		if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
			$version = 1;
			$this->memcached->set($version_key, $version);
		}

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
		echo '<strong>Engine:</strong> Memcached<br />';
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
	 * Resolves an array chunked payload back into a single array
	 */
	private function get_chunked_value(array $value, string $group): bool|array
	{
		if (!isset($value['__chunk_list'])) {
			return false;
		}

		$cache_keys = [];

		foreach ($value['__chunk_list'] as $chunk_key) {
			$cache_keys[] = $this->build_key($chunk_key, $group);
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

		$version_key = $this->key_salt . ':group:' . $prefix . $group;
		$version_number = $this->memcached->get($version_key);

		if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
			$version_number = 0;
			$this->memcached->set($version_key, $version_number);
		}

		$cache_group = $this->key_salt . ':' . $prefix . $group . ':' . $version_number;

		$this->group_mapping[$group] = $cache_group;

		return $cache_group;
	}

}