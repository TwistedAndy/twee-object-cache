<?php
/**
 * Plugin Name: Twee APCu Object Cache
 * Plugin URI: https://github.com/TwistedAndy/twee-object-cache
 * Description: A high-performance, lightweight APCu object cache for WordPress and WooCommerce.
 * Version: 1.0.0
 * Author: Andrii Toniievych
 * Author URI: https://www.linkedin.com/in/toniievych/
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * If APCu isn't available WordPress will automatically
 * fall back to its native in-memory runtime cache.
 */
if (!function_exists('apcu_fetch')) {
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

	private array $persistent_groups = [
		'plugins' => true,
		'themes'  => true,
		'counts'  => true,
	];

	private array $global_groups = [];

	private array $non_persistent_groups = [];

	private int $runtime_cache_limit = 100000;

	private bool $multisite = false;

	private string $blog_prefix = '';

	private string $key_salt = '';

	private bool $active = true;

	public function __construct()
	{
		if (defined('WP_APCU_PERSISTENT_GROUPS')) {
			$groups = is_string(WP_APCU_PERSISTENT_GROUPS) ? explode(',', WP_APCU_PERSISTENT_GROUPS) : WP_APCU_PERSISTENT_GROUPS;

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

		$this->active = (function_exists('apcu_enabled') and apcu_enabled());

		if (!$this->active and function_exists('add_action')) {
			add_action('admin_notices', function() {
				$message = '<strong>APCu Object Cache Error:</strong> The APCu extension is not enabled.';

				if (function_exists('wp_admin_notice')) {
					wp_admin_notice($message, ['type' => 'error']);
				} else {
					echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
				}
			});
		}
	}

	public function get(int|string $key, string $group = 'default', bool|null $force = false, &$found = null): mixed
	{
		if ($group === '') {
			$group = 'default';
		}

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

		$cache_key = $this->build_key($key, $group);

		$this->cache_loads++;

		// Check APCu
		$value = apcu_fetch($cache_key, $found);
		if ($found) {
			$this->cache_hits++;
			$this->cache[$group][$key] = $value; // Store in runtime for subsequent calls

			return $value;
		}

		$this->cache_misses++;

		return false;
	}

	public function get_multiple(array $keys, string $group = 'default', $force = false): array
	{
		if ($group === '') {
			$group = 'default';
		}

		$values = [];

		foreach ($keys as $key) {
			$values[$key] = $this->get($key, $group, $force);
		}

		return $values;
	}

	public function set(int|string $key, mixed $data, string $group = 'default', int $expire = 0): bool
	{
		if ($group === '') {
			$group = 'default';
		}

		// Always store in runtime cache
		$this->cache[$group][$key] = $data;
		$this->cache_sets++;

		if (count($this->cache[$group]) > $this->runtime_cache_limit) {
			reset($this->cache[$group]);
			unset($this->cache[$group][key($this->cache[$group])]);
		}

		// Skip APCu if it's non-persistent data
		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			return true;
		}

		$cache_key = $this->build_key($key, $group);

		$stored = apcu_store($cache_key, $data, (int) $expire);

		if (!$stored) {
			if (!apcu_enabled()) {
				$this->handle_error('apcu_enabled() returned false after failed apcu_store()');
				return false;
			}
			apcu_delete($cache_key);
		}

		return $stored;
	}

	public function set_multiple(array $data, string $group = 'default', int $expire = 0): array
	{
		if ($group === '') {
			$group = 'default';
		}

		$values = [];

		foreach ($data as $key => $value) {
			$values[$key] = $this->set($key, $value, $group, $expire);
		}

		return $values;
	}

	public function add(int|string $key, mixed $data, string $group = 'default', int $expire = 0): mixed
	{
		if (wp_suspend_cache_addition()) {
			return false;
		}

		if ($group === '') {
			$group = 'default';
		}

		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		if (array_key_exists($key, $this->cache[$group])) {
			return false;
		}

		$cache_key = $this->build_key($key, $group);

		if (apcu_exists($cache_key)) {
			return false;
		}

		return $this->set($key, $data, $group, $expire);
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
		if ($group === '') {
			$group = 'default';
		}

		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		if (!array_key_exists($key, $this->cache[$group]) and (isset($this->non_persistent_groups[$group]) or !$this->active)) {
			return false;
		}

		$cache_key = $this->build_key($key, $group);

		// If it doesn't exist in APCu and it's a persistent group, fail the replace
		if (!isset($this->non_persistent_groups[$group]) and $this->active and !apcu_exists($cache_key)) {
			return false;
		}

		return $this->set($key, $data, $group, $expire);
	}

	public function delete(int|string $key, string $group = 'default'): bool
	{
		if ($group === '') {
			$group = 'default';
		}

		$deleted_runtime = false;

		if (isset($this->cache[$group]) and array_key_exists($key, $this->cache[$group])) {
			unset($this->cache[$group][$key]);
			$deleted_runtime = true;
		}

		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			return $deleted_runtime;
		}

		$cache_key = $this->build_key($key, $group);

		return (apcu_delete($cache_key) or $deleted_runtime);
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
		if ($group === '') {
			$group = 'default';
		}

		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			$value = $this->get($key, $group);

			if ($value === false or !is_numeric($value)) {
				return false;
			}

			$value = (int) $value + $offset;
			$this->cache[$group][$key] = $value;

			return $value;
		}

		$cache_key = $this->build_key($key, $group);

		if (!apcu_exists($cache_key)) {
			return false;
		}

		$success = false;
		$value = apcu_inc($cache_key, $offset, $success);

		if ($success) {
			$this->cache[$group][$key] = $value;

			return $value;
		}

		if (!apcu_enabled()) {
			$this->handle_error('apcu_enabled() returned false after failed apcu_inc()');
		}

		return false;
	}

	public function decr(int|string $key, int $offset = 1, string $group = 'default'): int|bool
	{
		if ($group === '') {
			$group = 'default';
		}

		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			$value = $this->get($key, $group);

			if ($value === false or !is_numeric($value)) {
				return false;
			}

			$value = (int) $value - $offset;
			$this->cache[$group][$key] = $value;

			return $value;
		}

		$cache_key = $this->build_key($key, $group);

		if (!apcu_exists($cache_key)) {
			return false;
		}

		$success = false;

		// APCu handles decrementing natively
		$value = apcu_dec($cache_key, $offset, $success);

		if ($success) {
			$this->cache[$group][$key] = $value;

			return $value;
		}

		if (!apcu_enabled()) {
			$this->handle_error('apcu_enabled() returned false after failed apcu_dec()');
		}

		return false;
	}

	public function flush(): bool
	{
		$this->flush_runtime();

		if ($this->active) {
			// Flush only keys belonging to this installation
			$pattern = '/^' . preg_quote($this->key_salt . ':', '/') . '/';
			$iterator = new APCUIterator($pattern, APC_ITER_KEY, 100);
			apcu_delete($iterator);
		}

		return true;
	}

	public function flush_runtime(): bool
	{
		$this->cache = [];

		return true;
	}

	public function flush_group(string $group): bool
	{
		if ($group === '') {
			$group = 'default';
		}

		unset($this->cache[$group]);

		if (!$this->active) {
			return true;
		}

		if ($this->multisite and !isset($this->global_groups[$group])) {
			$prefix = $this->blog_prefix;
		} else {
			$prefix = '';
		}

		$needle = $this->key_salt . ':' . $prefix . $group . ':';

		// Clear from APCu
		$pattern = '/^' . preg_quote($needle, '/') . '/';
		$iterator = new APCUIterator($pattern, APC_ITER_KEY, 100);
		apcu_delete($iterator);

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
		$this->blog_prefix = $this->multisite ? (int) $blog_id . ':' : '';

		foreach (array_keys($this->cache) as $group) {
			if (!isset($this->global_groups[$group])) {
				unset($this->cache[$group]);
			}
		}
	}

	public function stats(): void
	{
		echo '<p>';
		echo '<strong>Engine:</strong> APCu<br />';
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

		$flag_file = __DIR__ . '/.ocflush';

		if (!file_exists($flag_file)) {
			@touch($flag_file);
		}

		trigger_error('Twee APCu Object Cache: ' . $message, E_USER_WARNING);
	}

	/**
	 * Build the fully qualified cache key.
	 */
	private function build_key(int|string $key, string $group): string
	{
		if ($this->multisite and !isset($this->global_groups[$group])) {
			$prefix = $this->blog_prefix;
		} else {
			$prefix = '';
		}

		return $this->key_salt . ':' . $prefix . $group . ':' . $key;
	}

}
