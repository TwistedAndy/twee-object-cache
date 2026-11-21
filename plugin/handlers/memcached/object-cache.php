<?php

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
class WP_Object_Cache
{
	/**
	 * The engine of this drop-in. The Twee Cache plugin reads it to find its drop-in.
	 */
	const TWEE_CACHE = 'memcached';

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
	 * The key salt without the site version
	 */
	private string $site_salt = '';

	/**
	 * False when the backend is not available. The Admin Bar of the plugin reads it.
	 */
	public bool $active = false;

	/**
	 * The error messages of the request
	 */
	private array $errors = [];

	private Memcached $memcached;

	private string $serializer = 'serialize';

	private string $unserializer = 'unserialize';

	public function __construct()
	{
		// The Object Cache menu in the Admin Bar. The Twee Cache plugin adds its own menu and calls admin_bar() for the items of the handler.
		add_action('admin_bar_menu', function(WP_Admin_Bar $admin_bar): void {
			if (!function_exists('toc_admin_bar')) {
				$this->admin_bar($admin_bar);
			}
		}, 100);

		// The Clean Cache link of the menu. The action runs before the page output starts, so the redirect can send its headers.
		add_action('init', function(): void {
			if (($_GET['toc_action'] ?? '') !== 'flush' or !current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
				return;
			}

			check_admin_referer('toc_action');

			$this->flush();

			wp_safe_redirect(remove_query_arg(['toc_action', '_wpnonce']));
			exit;
		});

		// The settings of the Cache Settings page, from the object-cache.php that the plugin writes. A constant in wp-config.php wins over a setting. The constant of a key is WP_ and the key in upper case.
		$config = defined('TWEE_CACHE_CONFIG') ? TWEE_CACHE_CONFIG : [];

		foreach (['memcached_persistent_groups', 'cache_key_salt', 'memcached_connect_timeout', 'memcached_send_timeout', 'memcached_recv_timeout', 'memcached_igbinary'] as $key) {
			$constant = 'WP_' . strtoupper($key);

			if (defined($constant)) {
				$config[$key] = constant($constant);
			}
		}

		$groups = $config['memcached_persistent_groups'] ?? null;

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

		// Keys have a length limit. A hash keeps two long salts with the same start apart.
		if (strlen($this->key_salt) > 20) {
			$this->key_salt = substr($this->key_salt, 0, 12) . substr(md5($this->key_salt), 0, 8);
		}

		global $memcached_servers;

		// The $memcached_servers global of wp-config.php wins over the setting
		if (!empty($memcached_servers) and is_array($memcached_servers)) {
			$servers = $memcached_servers;
		} elseif (!empty($config['memcached_servers']) and is_array($config['memcached_servers'])) {
			$servers = $config['memcached_servers'];
		} else {
			$servers = [
				['127.0.0.1', 11211],
			];
		}

		// Other plugins and hosts such as WP Engine put the servers in buckets, as "host:port" or "unix://path" strings
		if ($servers !== array_values($servers)) {
			$servers = (array) ($servers['default'] ?? reset($servers));
		}

		foreach ($servers as $index => $server) {
			if (is_string($server)) {
				// A Unix socket has no port
				$server = preg_replace('#^unix://#', '', $server);
				$servers[$index] = str_starts_with($server, '/') ? [$server, 0] : [strtok($server, ':'), (int) (strtok(':') ?: 11211)];
			}
		}

		$connect_timeout = (int) ($config['memcached_connect_timeout'] ?? 250);
		$send_timeout = (int) ($config['memcached_send_timeout'] ?? 250);
		$recv_timeout = (int) ($config['memcached_recv_timeout'] ?? 250);

		if ($connect_timeout <= 0) {
			$connect_timeout = 250;
		}

		if ($send_timeout < 0) {
			$send_timeout = 250;
		}

		if ($recv_timeout < 0) {
			$recv_timeout = 250;
		}

		if (($config['memcached_igbinary'] ?? true) and defined('Memcached::HAVE_IGBINARY') and Memcached::HAVE_IGBINARY and function_exists('igbinary_serialize')) {
			$this->serializer = 'igbinary_serialize';
			$this->unserializer = 'igbinary_unserialize';
			$serializer = Memcached::SERIALIZER_IGBINARY;
		} else {
			$serializer = Memcached::SERIALIZER_PHP;
		}

		$pool_signature = hash('sha256', serialize($servers) . ':' . $serializer . ':' . $connect_timeout . ':' . $send_timeout . ':' . $recv_timeout);

		$this->memcached = new Memcached('wp_object_cache_pool_' . substr($pool_signature, 0, 16));

		if (empty($this->memcached->getServerList())) {
			$this->memcached->setOption(Memcached::OPT_NO_BLOCK, false);
			$this->memcached->setOption(Memcached::OPT_NOREPLY, false);
			$this->memcached->setOption(Memcached::OPT_BUFFER_WRITES, false);
			$this->memcached->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
			$this->memcached->setOption(Memcached::OPT_CONNECT_TIMEOUT, $connect_timeout);
			$this->memcached->setOption(Memcached::OPT_SEND_TIMEOUT, $send_timeout * 1000);
			$this->memcached->setOption(Memcached::OPT_RECV_TIMEOUT, $recv_timeout * 1000);

			if (defined('Memcached::OPT_DEAD_TIMEOUT')) {
				$this->memcached->setOption(Memcached::OPT_DEAD_TIMEOUT, 1);
			}

			$this->memcached->setOption(Memcached::OPT_COMPRESSION, true);
			$this->memcached->setOption(Memcached::OPT_COMPRESSION_TYPE, Memcached::COMPRESSION_FASTLZ);

			if ($serializer === Memcached::SERIALIZER_IGBINARY) {
				$this->memcached->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_IGBINARY);
			} else {
				$this->memcached->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_PHP);
			}

			$this->memcached->addServers($servers);
		}

		/*
		 * All keys of the site include the site version, so flush() only changes the version.
		 * Other sites on the same servers keep their keys. The read also shows if the server is available.
		 */
		$this->site_salt = $this->key_salt;
		$flush_key = $this->site_salt . ':flush';
		$version = $this->memcached->get($flush_key);

		if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
			// Use add() so concurrent requests agree on a single version
			$version = bin2hex(random_bytes(4));

			if (!$this->memcached->add($flush_key, $version) and in_array($this->memcached->getResultCode(), [Memcached::RES_NOTSTORED, Memcached::RES_DATA_EXISTS], true)) {
				$version = $this->memcached->get($flush_key);
			}
		}

		if ($this->memcached->getResultCode() === Memcached::RES_SUCCESS and is_string($version)) {
			$this->active = true;
			$this->key_salt = $this->site_salt . '_' . $version;
		}

		// Flush stale cache if the server was previously offline
		$flag_file = WP_CONTENT_DIR . '/cache-flush';

		if ($this->active) {
			if (file_exists($flag_file) and $this->flush()) {
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

		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		$this->cache_loads++;

		// Check Memcached
		$cache_key = $this->build_key($key, $group);
		$value = $this->memcached->get($cache_key);
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
		if ($group === '') {
			$group = 'default';
		}

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
		$code = $this->memcached->getResultCode();

		if ($code !== Memcached::RES_SUCCESS and $code !== Memcached::RES_NOTFOUND) {
			$this->handle_error($this->memcached->getResultMessage());
		}

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

		// Skip Memcached if it's non-persistent data
		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			return true;
		}

		// Memcached reads a TTL of more than 30 days as a Unix time
		if ($expire > 2592000) {
			$expire += time();
		}

		$cache_key = $this->build_key($key, $group);
		$result = $this->memcached->set($cache_key, $data, $expire);

		if (!$result) {
			if ($this->memcached->getResultCode() === Memcached::RES_E2BIG) {
				$result = $this->set_chunked_value($key, $data, $group, $expire);
			} else {
				$this->handle_error($this->memcached->getResultMessage());
			}

			if (!$result) {
				unset($this->cache[$group][$key]);
			}
		}

		return $result;
	}

	public function set_multiple(array $data, string $group = 'default', int $expire = 0): array
	{
		if ($group === '') {
			$group = 'default';
		}

		$values = [];

		// Use set() per key; setMulti() may silently skip oversized values.
		foreach ($data as $key => $value) {
			$values[$key] = $this->set($key, $value, $group, $expire);
		}

		return $values;
	}

	public function add(int|string $key, mixed $data, string $group = 'default', int $expire = 0): bool
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

		if (isset($this->non_persistent_groups[$group]) or !$this->active) {
			$this->cache[$group][$key] = $data;
			$this->cache_sets++;

			return true;
		}

		// Memcached reads a TTL of more than 30 days as a Unix time
		if ($expire > 2592000) {
			$expire += time();
		}

		$cache_key = $this->build_key($key, $group);
		$added = $this->memcached->add($cache_key, $data, $expire);

		if (!$added) {
			$code = $this->memcached->getResultCode();

			if ($code === Memcached::RES_E2BIG) {
				$added = $this->set_chunked_value($key, $data, $group, $expire, 'add');
			} elseif ($code !== Memcached::RES_NOTSTORED and $code !== Memcached::RES_DATA_EXISTS) {
				$this->handle_error($this->memcached->getResultMessage());
			}
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
		if ($group === '') {
			$group = 'default';
		}

		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		if (!array_key_exists($key, $this->cache[$group]) and (isset($this->non_persistent_groups[$group]) or !$this->active)) {
			return false;
		}

		if (!isset($this->non_persistent_groups[$group]) and $this->active) {
			// Memcached reads a TTL of more than 30 days as a Unix time
			if ($expire > 2592000) {
				$expire += time();
			}

			$cache_key = $this->build_key($key, $group);
			$replaced = $this->memcached->replace($cache_key, $data, $expire);

			if (!$replaced and $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND) {
				if ($this->memcached->getResultCode() === Memcached::RES_E2BIG) {
					$replaced = $this->set_chunked_value($key, $data, $group, $expire, 'replace');
				} else {
					$this->handle_error($this->memcached->getResultMessage());
				}
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

		// Chunks of a chunked value stay until Memcached evicts them. No request reads them again.
		$cache_key = $this->build_key($key, $group);
		$deleted = $this->memcached->delete($cache_key);
		$code = $this->memcached->getResultCode();

		if (!$deleted and $code !== Memcached::RES_NOTFOUND) {
			$this->handle_error($this->memcached->getResultMessage());

			return false;
		}

		return ($deleted or $deleted_runtime);
	}

	public function delete_multiple(array $keys, string $group = 'default'): array
	{
		if ($group === '') {
			$group = 'default';
		}

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

		// Chunks of a chunked value stay until Memcached evicts them. No request reads them again.
		$results = $this->memcached->deleteMulti(array_values($memcached_keys));

		if (is_array($results)) {
			$failed = false;

			foreach ($memcached_keys as $key => $memcached_key) {
				// deleteMulti() returns true or a result code for each key
				$result = $results[$memcached_key] ?? Memcached::RES_NOTFOUND;

				if ($result === true) {
					$values[$key] = true;
				} elseif ($result !== Memcached::RES_NOTFOUND) {
					$values[$key] = false;
					$failed = true;
				}
			}

			if ($failed) {
				$this->handle_error($this->memcached->getResultMessage());
			}
		} else {
			if ($this->memcached->getResultCode() !== Memcached::RES_NOTFOUND) {
				$this->handle_error($this->memcached->getResultMessage());
				foreach ($values as $key => $value) {
					$values[$key] = false;
				}
			}
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

			if ($value === false) {
				return false;
			}

			$value = (int) $value + $offset;

			$this->cache[$group][$key] = $value;

			return $value;
		}

		$cache_key = $this->build_key($key, $group);
		$value = $this->memcached->increment($cache_key, $offset);
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
		if ($group === '') {
			$group = 'default';
		}

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

		if (!$this->active) {
			return true;
		}

		$version = bin2hex(random_bytes(4));
		$stored = $this->memcached->set($this->site_salt . ':flush', $version, 0);

		if (!$stored) {
			// Old values stay available, so stop using the cache until the next request flushes it
			$this->handle_error($this->memcached->getResultMessage(), true);

			return false;
		}

		$this->key_salt = $this->site_salt . '_' . $version;

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
		if ($group === '') {
			$group = 'default';
		}

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
			$code = $this->memcached->getResultCode();

			// A harvested key may have already expired or been evicted, so ignore NOT FOUND
			if ($code !== Memcached::RES_SUCCESS and $code !== Memcached::RES_NOTFOUND) {
				$this->handle_error($this->memcached->getResultMessage());
			}
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

		$version = bin2hex(random_bytes(8));
		$version_key = $this->key_salt . ':group:' . $namespaced_group;
		$version_stored = $this->memcached->set($version_key, $version, 0);

		if (!$version_stored or $this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
			$this->handle_error($this->memcached->getResultMessage());
			unset($this->group_mapping[$group]);

			return false;
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

	/**
	 * Add the Object Cache menu to the Admin Bar with the Clean Cache link, the engine, the hit ratio and the backend reads of the page
	 *
	 * The values need no backend request. In wp-admin, the Admin Bar shows before most of the page, so the values are not complete.
	 * The Twee Cache plugin adds its own links to the menu and calls this method.
	 *
	 * @param WP_Admin_Bar $admin_bar
	 */
	public function admin_bar(WP_Admin_Bar $admin_bar): void
	{
		if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
			return;
		}

		$hits = $this->cache_hits;
		$misses = $this->cache_misses;
		$ratio = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 1) : 0;

		// Each row has a label and a value
		$rows = [
			'engine' => ['<strong>Memcached</strong>', $this->active ? 'Connected' : 'Not connected'],
			'ratio'  => ['Hit ratio', $ratio . '% (' . number_format_i18n($hits) . ' / ' . number_format_i18n($misses) . ')'],
			'reads'  => ['Memcached reads', number_format_i18n($this->cache_loads)],
		];

		$admin_bar->add_node([
			'id'    => 'twee-cache',
			'title' => 'Object Cache',
			'href'  => '#',
		]);

		$admin_bar->add_node([
			'id'     => 'twee-cache-flush',
			'parent' => 'twee-cache',
			'title'  => 'Clean Cache',
			'href'   => wp_nonce_url(add_query_arg('toc_action', 'flush'), 'toc_action'),
		]);

		$admin_bar->add_group([
			'id'     => 'twee-cache-info',
			'parent' => 'twee-cache',
			'meta'   => ['class' => 'ab-sub-secondary'],
		]);

		// The first row adds the styles of the rows: the label at the left and the value at the right.
		// The Admin Bar gives each element a line height of 32px, so the text in a row of 20px moves down without the inherit rule.
		$meta = ['html' => '<style>#wpadminbar #wp-admin-bar-twee-cache-info{padding:9px 0}#wpadminbar #wp-admin-bar-twee-cache-info .ab-item{display:flex;justify-content:space-between;gap:24px;height:20px;line-height:20px}#wpadminbar #wp-admin-bar-twee-cache-info .ab-item *{line-height:inherit}#wpadminbar #wp-admin-bar-twee-cache-info .ab-item span+span{color:#f0f0f1;font-variant-numeric:tabular-nums}</style>'];

		foreach ($rows as $id => $row) {
			$admin_bar->add_node([
				'id'     => 'twee-cache-' . $id,
				'parent' => 'twee-cache-info',
				'title'  => '<span>' . $row[0] . '</span><span>' . $row[1] . '</span>',
				'meta'   => $meta,
			]);

			$meta = [];
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
	 * Get the details of the cache for the Query Monitor panel
	 *
	 * @return array The engine, the status, the details, the runtime groups and the errors
	 */
	public function debug_info(): array
	{
		$details = [
			'Key salt' => $this->key_salt,
		];

		if ($this->active) {
			$start = hrtime(true);
			$this->memcached->getVersion();
			$latency = hrtime(true) - $start;

			$versions = [];
			$bytes = 0;
			$limit = 0;
			$keys = 0;

			// The totals of all servers
			foreach ((array) $this->memcached->getStats() as $data) {
				if (is_array($data) and !empty($data['pid'])) {
					$versions[] = $data['version'] ?? '';
					$bytes += (int) ($data['bytes'] ?? 0);
					$limit += (int) ($data['limit_maxbytes'] ?? 0);
					$keys += (int) ($data['curr_items'] ?? 0);
				}
			}

			$details['Servers'] = count($versions);
			$details['Version'] = implode(', ', array_unique($versions));
			$details['Memory'] = size_format($bytes) . ' / ' . size_format($limit);
			$details['Keys'] = $keys;
			$details['Latency'] = number_format($latency / 1e3) . ' µs';
		}

		$groups = [];

		foreach ($this->cache as $group => $values) {
			$groups[$group] = [
				'entries' => count($values),
				'type'    => isset($this->non_persistent_groups[$group]) ? 'non-persistent' : (isset($this->global_groups[$group]) ? 'global' : ''),
			];
		}

		return [
			'engine'  => 'Memcached',
			'active'  => $this->active,
			'details' => $details,
			'groups'  => $groups,
			'errors'  => $this->errors,
		];
	}

	/**
	 * Deactivate the cache and flag a full flush on a real outage or when forced
	 */
	private function handle_error(string $message, bool $disable = false): void
	{
		$this->errors[] = $message;

		$active = false;
		$stats = $disable ? false : $this->memcached->getStats();

		if (is_array($stats)) {
			foreach ($stats as $data) {
				if (is_array($data) and !empty($data['pid'])) {
					$active = true;
					break;
				}
			}
		}

		if (!$active) {
			$this->active = false;

			if (!file_exists(WP_CONTENT_DIR . '/cache-flush')) {
				@touch(WP_CONTENT_DIR . '/cache-flush');
			}
		}

		trigger_error('Twee Memcached Cache: ' . $message, E_USER_WARNING);
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
	 * Resolves a chunked payload back into its original value
	 */
	private function get_chunked_value(array $value, string $group): mixed
	{
		if (empty($value['__chunk_list']) or !is_array($value['__chunk_list'])) {
			return false;
		}

		$cache_keys = [];

		foreach ($value['__chunk_list'] as $chunk_key) {
			$cache_keys[] = $this->build_key((string) $chunk_key, $group);
		}

		$chunks = $this->memcached->getMulti($cache_keys);
		$code = $this->memcached->getResultCode();

		if ($code !== Memcached::RES_SUCCESS and $code !== Memcached::RES_NOTFOUND) {
			$this->handle_error($this->memcached->getResultMessage());
		}

		if (is_array($chunks) and count($chunks) === count($cache_keys)) {
			$assembled = '';

			foreach ($cache_keys as $cache_key) {
				if (!array_key_exists($cache_key, $chunks) or !is_string($chunks[$cache_key])) {
					return false;
				}

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
	private function set_chunked_value(int|string $key, mixed $data, string $group, int $expire, string $operation = 'set'): bool
	{
		$cache_key = $this->build_key($key, $group);
		$previous = $this->memcached->get($cache_key);
		$previous_chunk_keys = [];

		if (is_array($previous) and !empty($previous['__chunk_list']) and is_array($previous['__chunk_list'])) {
			foreach ($previous['__chunk_list'] as $chunk_key) {
				$previous_chunk_keys[] = $this->build_key((string) $chunk_key, $group);
			}
		}

		$serializer = $this->serializer;
		$serialized = $serializer($data);

		$chunks = str_split($serialized, 500000);
		$chunk_keys = [];

		$multi_set = [];
		$generation = bin2hex(random_bytes(8));

		foreach ($chunks as $index => $chunk) {
			$chunk_key = $key . '_chunk_' . $generation . '_' . $index;
			$chunk_keys[] = $chunk_key;
			$multi_set[$this->build_key($chunk_key, $group)] = $chunk;
		}

		$chunks_stored = $this->memcached->setMulti($multi_set, $expire);

		if (!$chunks_stored) {
			$message = $this->memcached->getResultMessage();
			$this->memcached->deleteMulti(array_keys($multi_set));
			$this->handle_error($message);

			return false;
		}

		if ($operation === 'add') {
			$list_stored = $this->memcached->add($cache_key, ['__chunk_list' => $chunk_keys], $expire);
		} elseif ($operation === 'replace') {
			$list_stored = $this->memcached->replace($cache_key, ['__chunk_list' => $chunk_keys], $expire);
		} else {
			$list_stored = $this->memcached->set($cache_key, ['__chunk_list' => $chunk_keys], $expire);
		}

		if (!$list_stored) {
			$message = $this->memcached->getResultMessage();
			$code = $this->memcached->getResultCode();
			$this->memcached->deleteMulti(array_keys($multi_set));

			if (($operation === 'add' and $code !== Memcached::RES_NOTSTORED and $code !== Memcached::RES_DATA_EXISTS) or ($operation === 'replace' and $code !== Memcached::RES_NOTFOUND) or ($operation === 'set')) {
				$this->handle_error($message);
			}

			return false;
		}

		if (!empty($previous_chunk_keys)) {
			$this->memcached->deleteMulti($previous_chunk_keys);
		}

		return true;
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

		if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
			// Use add() so concurrent requests agree on a single version
			$version_number = bin2hex(random_bytes(8));

			// Re-read only when another request won the race to keep the original error otherwise
			if (!$this->memcached->add($version_key, $version_number) and in_array($this->memcached->getResultCode(), [Memcached::RES_NOTSTORED, Memcached::RES_DATA_EXISTS], true)) {
				$version_number = $this->memcached->get($version_key);
			}
		}

		if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS or !is_string($version_number)) {
			// Without the shared version, writes and deletes would miss the real namespace
			$this->handle_error($this->memcached->getResultMessage(), true);

			return $this->key_salt . ':' . $namespaced_group . ':' . bin2hex(random_bytes(8));
		}

		$cache_group = $this->key_salt . ':' . $namespaced_group . ':' . $version_number;

		$this->group_mapping[$group] = $cache_group;

		return $cache_group;
	}

}
