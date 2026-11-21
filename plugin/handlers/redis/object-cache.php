<?php

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
class WP_Object_Cache
{

	/**
	 * The engine of this drop-in. The Twee Cache plugin reads it to find its drop-in.
	 */
	const TWEE_CACHE = 'redis';

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

	/**
	 * The serializer and the compression in the keys, such as "iz" for igbinary with zstd.
	 * A request that started before a format change cannot write values that the requests with the new format read.
	 */
	private string $key_format = '';

	/**
	 * False when the backend is not available. The Admin Bar of the plugin reads it.
	 */
	public bool $active = true;

	/**
	 * The error messages of the request
	 */
	private array $errors = [];

	/**
	 * Set hash field TTLs with HEXPIREAT. The first command that fails sets it to false.
	 */
	private bool $field_expiry = false;

	private Redis|Relay\Relay $redis;

	public function __construct()
	{
		// The settings of the Cache Settings page, from the object-cache.php that the plugin writes. A constant in wp-config.php wins over a setting. The constant of a key is WP_ and the key in upper case.
		$config = defined('TWEE_CACHE_CONFIG') ? TWEE_CACHE_CONFIG : [];

		foreach (['redis_persistent_groups', 'cache_key_salt', 'redis_use_relay', 'redis_host', 'redis_port', 'redis_timeout', 'redis_retry_interval', 'redis_database', 'redis_password', 'redis_username', 'redis_persistent_connect', 'redis_igbinary', 'redis_compression'] as $key) {
			$constant = 'WP_' . strtoupper($key);

			if (defined($constant)) {
				$config[$key] = constant($constant);
			}
		}

		$groups = $config['redis_persistent_groups'] ?? null;

		if ($groups !== null and $groups !== '') {
			$groups = is_string($groups) ? explode(',', $groups) : $groups;

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

		// The host name changes between web, WP-CLI and multisite requests, so the default salt uses the database
		$this->key_salt = (string) ($config['cache_key_salt'] ?? '');

		if ($this->key_salt === '') {
			$this->key_salt = preg_replace('/[^a-zA-Z0-9_]/', '_', (defined('DB_NAME') ? DB_NAME : '') . '_' . ($GLOBALS['table_prefix'] ?? 'wp_'));
		}

		if (($config['redis_use_relay'] ?? false) and class_exists('Relay\Relay')) {
			$this->redis = new Relay\Relay();
		} else {
			$this->redis = new Redis();
		}

		$this->field_expiry = method_exists($this->redis, 'hExpireAt');

		$host = (string) ($config['redis_host'] ?? '127.0.0.1');
		$port = (int) ($config['redis_port'] ?? 6379);

		$timeout = (float) ($config['redis_timeout'] ?? 1);
		$retry_interval = (int) ($config['redis_retry_interval'] ?? 100);

		$database = (int) ($config['redis_database'] ?? 0);
		$password = $config['redis_password'] ?? '';
		$username = (string) ($config['redis_username'] ?? '');

		// A Redis 6 ACL user logs in with the name and the password
		if ($username !== '' and is_string($password) and $password !== '') {
			$password = [$username, $password];
		}

		try {

			if (strpos($host, 'unix://') === 0 or strpos($host, '/') === 0) {
				$port = 0;
			}

			if ($config['redis_persistent_connect'] ?? true) {
				/*
				 * A persistent connection keeps its database and its login between requests.
				 * Each database and password gets a separate connection, so that a site cannot use the state of other sites.
				 */
				$persistent_id = 'twee_' . $database;

				if ($password !== '') {
					$persistent_id .= '_' . md5(serialize($password));
				}

				$this->redis->pconnect($host, $port, $timeout, $persistent_id, $retry_interval, $timeout);
			} else {
				$this->redis->connect($host, $port, $timeout, null, $retry_interval, $timeout);
			}

			if ($password !== '' and !$this->redis->auth($password)) {
				throw new Exception('Authentication failed: ' . $this->redis->getLastError());
			}

			// A pooled persistent connection keeps the database that another site selected, so database 0 needs the command too
			if (!$this->redis->select($database)) {
				throw new Exception('Cannot select database ' . $database . ': ' . $this->redis->getLastError());
			}

			if (($config['redis_igbinary'] ?? true) and defined('Redis::SERIALIZER_IGBINARY') and extension_loaded('igbinary')) {
				$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
				$this->key_format = 'i';
			} else {
				$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
				$this->key_format = 'p';
			}

			if ($config['redis_compression'] ?? true) {
				if (defined('Redis::COMPRESSION_ZSTD')) {
					$this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
					$this->key_format .= 'z';
				} elseif (defined('Redis::COMPRESSION_LZ4')) {
					$this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
					$this->key_format .= 'l';
				} elseif (defined('Redis::COMPRESSION_LZF')) {
					$this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);
					$this->key_format .= 'f';
				}
			}

			// Flush stale cache if the server was previously offline
			$flag_file = WP_CONTENT_DIR . '/cache-flush';

			if (file_exists($flag_file) and $this->flush()) {
				@unlink($flag_file);
			}
		} catch (Exception $e) {
			$this->active = false;
			$error = $e->getMessage();

			// Mark the cache as offline to trigger a flush on next reconnect
			$flag_file = WP_CONTENT_DIR . '/cache-flush';

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

	public function get(int|string|null $key, ?string $group = 'default', bool|null $force = false, &$found = null): mixed
	{
		$found = false;

		if ($key === null) {
			return false;
		}

		if ($group === null or $group === '') {
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
			$this->cache_misses++;

			return false;
		}

		// Force a fresh read from Redis: drop any stale runtime entry
		if ($force and isset($this->cache[$group])) {
			unset($this->cache[$group][$key]);
		}

		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		$this->cache_loads++;

		try {
			$group_key = $this->build_group($group);
			$value = $this->redis->hGet($group_key, $key);

			if ($value !== false) {
				// Values with TTL and boolean falses are stored as an array
				if (is_array($value) and array_key_exists('_d', $value)) {
					if ($this->is_expired($value, time())) {
						// Redis before 7.4 cannot expire a hash field, so delete it here
						$this->redis->hDel($group_key, $key);
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

		$this->cache_misses++;

		return false;
	}

	public function get_multiple(array $keys, ?string $group = 'default', bool $force = false): array
	{
		if ($group === null or $group === '') {
			$group = 'default';
		}

		$values = [];
		$key_map = [];

		$is_runtime_group = (!$this->active or isset($this->non_persistent_groups[$group]));

		$runtime_cache = $this->cache[$group] ?? [];

		// Resolve runtime cache and identify keys missing from memory
		foreach ($keys as $key) {
			if ($key === null) {
				continue;
			}

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
			$expired = [];

			foreach ($key_map as $key) {
				$cached_value = $cached_values[$key] ?? false;

				if ($cached_value === false) {
					$this->cache_misses++;
					$values[$key] = false;
					continue;
				}

				if (is_array($cached_value) and array_key_exists('_d', $cached_value)) {
					if ($this->is_expired($cached_value, $time)) {
						$expired[] = $key;
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

			// Redis before 7.4 cannot expire a hash field, so delete it here
			if ($expired) {
				try {
					$this->redis->hDel($group_key, ...$expired);
				} catch (Exception $e) {
					$this->handle_error($e);
				}
			}
		} else {
			foreach ($key_map as $key) {
				$this->cache_misses++;
				$values[$key] = false;
			}
		}

		return $values;
	}

	public function set(int|string|null $key, mixed $data, ?string $group = 'default', int $expire = 0): bool
	{
		if ($key === null) {
			return false;
		}

		if ($group === null or $group === '') {
			$group = 'default';
		}

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

		$expire_at = $expire > 0 ? time() + $expire : 0;

		return $this->write($this->build_group($group), [$key => $this->pack($data, $expire_at)], $expire_at);
	}

	public function set_multiple(array $data, ?string $group = 'default', int $expire = 0): array
	{
		if ($group === null or $group === '') {
			$group = 'default';
		}

		if ($data === []) {
			return [];
		}

		$values = [];

		$is_cached_group = ($this->active and !isset($this->non_persistent_groups[$group]));

		foreach ($data as $key => $value) {
			$this->cache[$group][$key] = $value;
			$this->cache_sets++;
			$values[$key] = true;
		}

		while (count($this->cache[$group]) > $this->runtime_cache_limit) {
			reset($this->cache[$group]);
			unset($this->cache[$group][key($this->cache[$group])]);
		}

		if (!$is_cached_group) {
			return $values;
		}

		$expire_at = $expire > 0 ? time() + $expire : 0;
		$cache_values = [];

		foreach ($data as $key => $value) {
			$cache_values[$key] = $this->pack($value, $expire_at);
		}

		if (!$this->write($this->build_group($group), $cache_values, $expire_at)) {
			foreach ($values as $key => $_) {
				$values[$key] = false;
			}
		}

		return $values;
	}

	public function add(int|string|null $key, mixed $data, ?string $group = 'default', int $expire = 0): bool
	{
		if ($key === null or wp_suspend_cache_addition()) {
			return false;
		}

		if ($group === null or $group === '') {
			$group = 'default';
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

		$expire_at = $expire > 0 ? time() + $expire : 0;

		$data_to_store = $this->pack($data, $expire_at);
		$group_key = $this->build_group($group);

		try {
			// Use atomic hSetNx for the primary insertion to prevent race conditions
			$added = (bool) $this->redis->hSetNx($group_key, $key, $data_to_store);

			if (!$added) {
				// Key already exists, check if it's expired
				$existing = $this->redis->hGet($group_key, $key);

				if ($this->is_expired($existing, time())) {
					// It's expired, so we are allowed to overwrite it
					$added = ($this->redis->hSet($group_key, $key, $data_to_store) !== false);
				}
			}

			if ($added and $expire_at > 0 and $this->field_expiry) {
				$this->field_expiry = ($this->redis->hExpireAt($group_key, $expire_at, [$key]) !== false);
			}
		} catch (Exception $e) {
			$this->handle_error($e);
			$added = false;
		}

		if ($added) {
			$this->cache[$group][$key] = $data;
			$this->cache_sets++;
		}

		return $added;
	}

	public function add_multiple(array $data, ?string $group = 'default', int $expire = 0): array
	{
		$values = [];

		foreach ($data as $key => $value) {
			$values[$key] = $this->add($key, $value, $group, $expire);
		}

		return $values;
	}

	public function replace(int|string|null $key, mixed $data, ?string $group = 'default', int $expire = 0): bool
	{
		if ($key === null) {
			return false;
		}

		if ($group === null or $group === '') {
			$group = 'default';
		}

		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		$is_cached_group = ($this->active and !isset($this->non_persistent_groups[$group]));

		if (!array_key_exists($key, $this->cache[$group]) and !$is_cached_group) {
			return false;
		}

		if (!$is_cached_group) {
			return $this->set($key, $data, $group, $expire);
		}

		$group_key = $this->build_group($group);

		try {
			$existing = $this->redis->hGet($group_key, $key);
		} catch (Exception $e) {
			$this->handle_error($e);

			return false;
		}

		// Booleans false are stored as an array, so false means that the field does not exist
		if ($existing === false or $this->is_expired($existing, time())) {
			return false;
		}

		return $this->set($key, $data, $group, $expire);
	}

	public function delete(int|string|null $key, ?string $group = 'default'): bool
	{
		if ($key === null) {
			return false;
		}

		if ($group === null or $group === '') {
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

		$error = null;

		try {
			$deleted = $this->redis->hDel($this->build_group($group), $key);
		} catch (Exception $error) {
			$deleted = false;
		}

		if ($deleted === false) {
			// The old value stays in Redis
			$this->handle_error($error, true);

			return false;
		}

		return ($deleted > 0 or $deleted_runtime);
	}

	public function delete_multiple(array $keys, ?string $group = 'default'): array
	{
		$values = [];

		foreach ($keys as $key) {
			$values[$key] = $this->delete($key, $group);
		}

		return $values;
	}

	public function incr(int|string|null $key, int $offset = 1, ?string $group = 'default'): int|bool
	{
		if ($key === null) {
			return false;
		}

		if ($group === null or $group === '') {
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

		$group_key = $this->build_group($group);

		try {
			$value = $this->redis->hGet($group_key, $key);
		} catch (Exception $e) {
			$this->handle_error($e);

			return false;
		}

		$expire_at = 0;

		if (is_array($value) and array_key_exists('_d', $value)) {
			if ($this->is_expired($value, time())) {
				return false;
			}

			$expire_at = (int) ($value['_t'] ?? 0);
			$value = $value['_d'];
		}

		if ($value === false or !is_numeric($value)) {
			return false;
		}

		$value = (int) $value + $offset;

		if (!$this->write($group_key, [$key => $this->pack($value, $expire_at)], $expire_at)) {
			return false;
		}

		$this->cache[$group][$key] = $value;

		return $value;
	}

	public function decr(int|string|null $key, int $offset = 1, ?string $group = 'default'): int|bool
	{
		return $this->incr($key, -$offset, $group);
	}

	public function flush(): bool
	{
		$this->flush_runtime();

		if (!$this->active) {
			return true;
		}

		// Delete only the keys with the key salt, in all formats. Other sites in the same database keep their keys.
		$pattern = addcslashes($this->key_salt, '*?[]\\') . ':*';
		$iterator = null;
		$flushed = true;
		$error = null;

		try {
			do {
				$keys = $this->redis->scan($iterator, $pattern, 1000);

				// SCAN returns false when no keys are left or on an error
				if ($keys === false) {
					$flushed = ($iterator === 0);
				} elseif ($keys) {
					$flushed = ($this->redis->unlink($keys) !== false);
				}
			} while ($flushed and $iterator > 0);
		} catch (Exception $error) {
			$flushed = false;
		}

		if (!$flushed) {
			// Old values stay in Redis, so stop using the cache until the next request flushes it
			$this->handle_error($error, true);
		}

		return $flushed;
	}

	public function flush_runtime(): bool
	{
		$this->cache = [];
		$this->group_mapping = [];

		return true;
	}

	public function flush_group(?string $group): bool
	{
		if ($group === null or $group === '') {
			$group = 'default';
		}

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

			return false;
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
	 * Get the details of the cache for the Query Monitor panel
	 *
	 * @return array The engine, the status, the details, the runtime groups and the errors
	 */
	public function debug_info(): array
	{
		$details = [
			'Key salt'       => $this->key_salt,
			'Database'       => $this->active ? $this->redis->getDbNum() : '',
			'Hash field TTL' => $this->field_expiry ? 'HEXPIREAT' : 'Deleted on read',
		];

		if ($this->active) {
			try {
				$start = hrtime(true);
				$this->redis->ping();
				$latency = hrtime(true) - $start;

				$server = $this->redis->info();
				$details['Version'] = $server['redis_version'] ?? '';
				$details['Memory'] = ($server['used_memory_human'] ?? '') . (!empty($server['maxmemory']) ? ' / ' . $server['maxmemory_human'] : '');
				$details['Keys'] = $this->redis->dbSize();
				$details['Latency'] = number_format($latency / 1e3) . ' µs';
			} catch (Exception $e) {
				$details['Server'] = $e->getMessage();
			}
		}

		$groups = [];

		foreach ($this->cache as $group => $values) {
			$groups[$group] = [
				'entries' => count($values),
				'type'    => isset($this->non_persistent_groups[$group]) ? 'non-persistent' : (isset($this->global_groups[$group]) ? 'global' : ''),
			];
		}

		return [
			'engine'  => 'Redis',
			'active'  => $this->active,
			'details' => $details,
			'groups'  => $groups,
			'errors'  => $this->errors,
		];
	}

	/**
	 * Log an error. Deactivate the cache and flag a flush if $flush is true or Redis does not reply to PING.
	 *
	 * @param Exception|null $e     The exception, or null to log the last error reply of Redis
	 * @param bool           $flush True after a failed write or delete, because the old value stays in Redis
	 */
	private function handle_error(?Exception $e = null, bool $flush = false): void
	{
		$message = $e ? $e->getMessage() : ($this->redis->getLastError() ? : 'Unknown error');

		$this->redis->clearLastError();
		$this->errors[] = $message;

		if (!$flush) {
			try {
				$flush = !$this->redis->ping();
			} catch (Exception $ignored) {
				$flush = true;
			}
		}

		if ($flush) {
			$this->active = false;

			if (!file_exists(WP_CONTENT_DIR . '/cache-flush')) {
				@touch(WP_CONTENT_DIR . '/cache-flush');
			}
		}

		trigger_error('Twee Redis Cache: ' . $message, E_USER_NOTICE);
	}

	/**
	 * Store packed values in a group hash. Send the field TTLs in the same round trip.
	 * If the write fails, the old values stay in Redis, so stop using the cache and flag a flush.
	 *
	 * @param string $group_key The group hash key
	 * @param array  $values    Packed values. The array keys are the field names.
	 * @param int    $expire_at The Unix time of expiration, or 0 for no expiration
	 */
	private function write(string $group_key, array $values, int $expire_at): bool
	{
		$error = null;

		try {
			if ($expire_at > 0 and $this->field_expiry) {
				$result = $this->redis->pipeline()->hMSet($group_key, $values)->hExpireAt($group_key, $expire_at, array_keys($values))->exec();
				$stored = (is_array($result) and $result[0] !== false);
				$this->field_expiry = ($stored and $result[1] !== false);
			} else {
				$stored = ($this->redis->hMSet($group_key, $values) !== false);
			}
		} catch (Exception $error) {
			$stored = false;
		}

		if (!$stored) {
			$this->handle_error($error, true);
		}

		return $stored;
	}

	/**
	 * Values with a TTL and booleans false are stored as an array with the "_t" and "_d" keys
	 */
	private function pack(mixed $data, int $expire_at): mixed
	{
		if ($expire_at > 0) {
			return [
				'_t' => $expire_at,
				'_d' => $data,
			];
		}

		if ($data === false) {
			return ['_d' => false];
		}

		return $data;
	}

	private function is_expired(mixed $value, int $time): bool
	{
		return (is_array($value) and array_key_exists('_d', $value) and isset($value['_t']) and $time > (int) $value['_t']);
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

		$cache_group = $this->key_salt . ':' . $this->key_format . ':' . $prefix . $group;

		$this->group_mapping[$group] = $cache_group;

		return $cache_group;
	}

}