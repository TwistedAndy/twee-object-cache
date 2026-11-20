<?php
/**
 * The Cache Settings page. It stores the settings in wp-content/oc-config.php, and the drop-in reads them from there.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The fields of each handler: the label, the type, the default value and the description
 *
 * A field has the name of the wp-config.php constant that wins over it. The memcached_servers field has the $memcached_servers global.
 * A switch with the default null is on when the server supports it.
 */
const TOC_SETTINGS = [
	'general'   => [
		'WP_CACHE_KEY_SALT' => ['Key salt', 'text', '', 'The namespace of the cache keys. When it is empty, the drop-in uses the database name and the table prefix.'],
	],
	'redis'     => [
		'WP_REDIS_HOST'               => ['Host', 'text', '127.0.0.1', 'A host name, an IP address or the path of a Unix socket.'],
		'WP_REDIS_PORT'               => ['Port', 'number', 6379, ''],
		'WP_REDIS_USERNAME'           => ['Username', 'text', '', 'The ACL user of Redis 6 and later. Keep it empty to log in with the password only.'],
		'WP_REDIS_PASSWORD'           => ['Password', 'password', '', 'Keep it empty when Redis has no password.'],
		'WP_REDIS_DATABASE'           => ['Database', 'number', 0, ''],
		'WP_REDIS_TIMEOUT'            => ['Timeout', 'number', 1, 'The time in seconds to connect and to read a reply.'],
		'WP_REDIS_RETRY_INTERVAL'     => ['Retry interval', 'number', 100, 'The time in milliseconds before the next connection attempt.'],
		'WP_REDIS_PERSISTENT_CONNECT' => ['Persistent connection', 'checkbox', true, 'Keep the connection open between requests.'],
		'WP_REDIS_IGBINARY'           => ['igbinary', 'checkbox', null, 'Serialize the values with igbinary. It is on by default when the igbinary extension is loaded.'],
		'WP_REDIS_COMPRESSION'        => ['Compression', 'checkbox', null, 'Compress the values with zstd, LZ4 or LZF. It is on by default when phpredis supports one of them.'],
		'WP_REDIS_USE_RELAY'          => ['Relay', 'checkbox', false, 'Connect through Relay when the extension is loaded.'],
		'WP_REDIS_PERSISTENT_GROUPS'  => ['Protected groups', 'text', 'plugins,themes,counts', 'Comma-separated groups that stay persistent when a plugin makes them non-persistent.'],
	],
	'memcached' => [
		'memcached_servers'              => ['Servers', 'servers', [['127.0.0.1', 11211]], 'One host:port or Unix socket path on each line.'],
		'WP_MEMCACHED_CONNECT_TIMEOUT'   => ['Connect timeout', 'number', 250, 'In milliseconds.'],
		'WP_MEMCACHED_SEND_TIMEOUT'      => ['Send timeout', 'number', 250, 'In milliseconds.'],
		'WP_MEMCACHED_RECV_TIMEOUT'      => ['Receive timeout', 'number', 250, 'In milliseconds.'],
		'WP_MEMCACHED_IGBINARY'          => ['igbinary', 'checkbox', null, 'Serialize the values with igbinary. It is on by default when the memcached extension supports igbinary.'],
		'WP_MEMCACHED_PERSISTENT_GROUPS' => ['Protected groups', 'text', 'plugins,themes,counts', 'Comma-separated groups that stay persistent when a plugin makes them non-persistent.'],
	],
	'apcu'      => [
		'WP_APCU_PERSISTENT_GROUPS' => ['Protected groups', 'text', 'plugins,themes,counts', 'Comma-separated groups that stay persistent when a plugin makes them non-persistent.'],
	],
];


/**
 * Add the Cache Settings page under Settings. On multisite, the page is in the Network Admin, because the drop-in serves all sites.
 */
function toc_settings_menu(): void
{
	if (is_multisite() !== is_network_admin()) {
		return;
	}

	$page = add_submenu_page(
		is_network_admin() ? 'settings.php' : 'options-general.php',
		'Cache Settings',
		'Cache Settings',
		is_multisite() ? 'manage_network_options' : 'manage_options',
		'twee-cache',
		'toc_settings_page'
	);

	add_action('load-' . $page, 'toc_settings_action');
}


/**
 * Load the styles of the page and save the settings of the form
 *
 * When the cache is on, the new settings must pass the test, because the next request uses them. A new handler replaces the drop-in.
 */
function toc_settings_action(): void
{
	wp_enqueue_style('toc_cache', plugins_url('assets/css/cache.css', __DIR__), [], (string) filemtime(dirname(__DIR__) . '/assets/css/cache.css'));

	if (empty($_POST['toc_save'])) {
		return;
	}

	check_admin_referer('toc_settings');

	$settings = toc_settings_get(wp_unslash($_POST));
	$engine = toc_dropin_engine();
	$test = $engine !== '' ? toc_dropin_test($settings['handler'], $settings) : ['ok' => true];

	if (!$test['ok']) {
		add_settings_error('toc', 'toc_test', 'The settings are not saved. ' . $test['message']);
	} elseif (!toc_settings_put($settings)) {
		add_settings_error('toc', 'toc_write', 'Cannot write ' . WP_CONTENT_DIR . '/oc-config.php. Make sure that the web server can write to the wp-content folder.');
	} else {
		$installed = ($engine === '' or $settings['handler'] === $engine or toc_dropin_install($settings['handler']));

		// The cache can hold values in another format or from another backend, so the drop-in flushes it before its first read.
		// The marker comes after the new drop-in, because a request with the old drop-in can take it.
		@touch(WP_CONTENT_DIR . '/oc-flush');

		if ($installed) {
			add_settings_error('toc', 'toc_saved', 'Settings saved.', 'success');
		} else {
			add_settings_error('toc', 'toc_install', 'The settings are saved, but the drop-in is not replaced. Make sure that the web server can write to the wp-content folder.');
		}
	}
}


/**
 * Show the Cache Settings page
 */
function toc_settings_page(): void
{
	$engine = toc_dropin_engine();
	$names = ['redis' => 'Redis', 'memcached' => 'Memcached', 'apcu' => 'APCu'];
	$loaded = ['redis' => extension_loaded('redis'), 'memcached' => extension_loaded('memcached'), 'apcu' => extension_loaded('apcu')];
	$test = null;
	$detected = null;

	// The form keeps its values after a test and after a save that failed. The test does not save them.
	if (!empty($_POST['toc_test']) or !empty($_POST['toc_save'])) {
		check_admin_referer('toc_settings');

		$settings = toc_settings_get(wp_unslash($_POST));
		$test = empty($_POST['toc_test']) ? null : toc_dropin_test($settings['handler'], $settings);
	} elseif (!empty($_POST['toc_detect'])) {
		check_admin_referer('toc_settings');

		// The detected values replace the values of the form, but Tracing stays as it is
		$detected = toc_settings_detect();
		$settings = toc_settings_get($detected['values'] + ['trace' => !empty($_POST['trace'])]);
	} else {
		// Without oc-config.php, the form shows the detected settings
		$detected = is_file(WP_CONTENT_DIR . '/oc-config.php') ? null : toc_settings_detect();
		$settings = toc_settings_get($detected['values'] ?? null);

		// The Admin Bar menu can enable a handler without a change of the settings
		if ($engine !== '') {
			$settings['handler'] = $engine;
		}
	}

	if ($settings['handler'] === '') {
		$settings['handler'] = array_key_first(array_filter($loaded)) ?? 'redis';
	}

	echo '<div class="wrap cache_box"><h1>Cache Settings</h1>';

	// The cache is flushed after a save, so the notices of the save show in the same request and not through a transient after a redirect.
	// WordPress shows them under Settings, but not in the Network Admin.
	if (is_network_admin()) {
		settings_errors('toc');
	}

	if ($test) {
		echo '<div class="notice inline ' . ($test['ok'] ? 'notice-success' : 'notice-error') . '"><p><strong>' . ($test['ok'] ? 'The test passed.' : 'The test failed.') . '</strong> ' . esc_html($test['message']) . '</p></div>';
	}

	if ($detected and $detected['sources']) {
		echo '<div class="notice notice-info inline"><p><strong>The form shows the detected settings.</strong> Sources: ' . esc_html(implode(', ', $detected['sources'])) . '. Select Test to examine them, then select Save Changes or Turn On.</p></div>';
	} elseif ($detected and !empty($_POST['toc_detect'])) {
		echo '<div class="notice notice-warning inline"><p>No settings were detected. The form shows the default values.</p></div>';
	}

	echo '<form method="post"><div class="sections">';

	wp_nonce_field('toc_settings');

	if ($engine !== '') {
		$status = '<span class="caption caption_success">On</span><div class="buttons"><a class="button" href="' . esc_url(wp_nonce_url(add_query_arg('toc_action', 'disable'), 'toc_action')) . '" title="Remove the drop-in">Turn Off</a></div>';
	} elseif (file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
		$status = '<span class="caption caption_error">Another plugin installed wp-content/object-cache.php</span>';
	} else {
		$status = '<span class="caption">Off</span><div class="buttons"><a class="button button-primary" href="' . esc_url(wp_nonce_url(add_query_arg('toc_action', 'enable_' . $settings['handler']), 'toc_action')) . '" title="Test the saved settings, install the drop-in and flush the cache">Turn On ' . esc_html($names[$settings['handler']]) . '</a></div>';
	}

	foreach (TOC_SETTINGS as $section => $fields) {
		$items = '';
		$options = '';

		if ($section === 'general') {
			echo '<section class="section"><div class="contents contents_split"><h2>Object Cache</h2>' . $status . '</div>';

			$items = '<div class="item"><label class="label" for="toc-handler">Handler</label><div class="field"><select id="toc-handler" name="handler">';

			foreach ($names as $handler => $name) {
				$items .= '<option value="' . $handler . '"' . selected($settings['handler'], $handler, false) . disabled(!$loaded[$handler], true, false) . '>' . $name . ($loaded[$handler] ? '' : ' (no extension)') . '</option>';
			}

			$items .= '</select></div><span class="text">When the cache is on, Save Changes replaces the drop-in.</span></div>';
			$options = '<label class="option"><input type="checkbox" name="trace" value="1"' . checked($settings['trace'], true, false) . '><span class="details"><span class="label">Tracing</span><span class="text">Record the backend calls for Query Monitor. Each call keeps a backtrace in memory, so use it only on development sites.</span></span></label>';
		} else {
			echo '<section class="section" data-handler="' . $section . '"><div class="contents"><h2>' . $names[$section] . '</h2></div>';
		}

		foreach ($fields as $name => [$label, $type, , $description]) {
			$value = $settings[$name];
			$global = ($name === 'memcached_servers');
			$locked = $global ? !empty($GLOBALS['memcached_servers']) : defined($name);
			$source = $global ? '$memcached_servers' : $name;
			$id = 'toc-' . strtolower(str_replace('_', '-', $name));
			$attributes = ' id="' . $id . '" name="' . $name . '"' . ($locked ? ' disabled' : '');
			$text = $locked ? '<code>' . $source . '</code> in <code>wp-config.php</code> sets this value.' : esc_html($description);
			$text = $text === '' ? '' : '<span class="text">' . $text . '</span>';
			$title = ' title="Constant: ' . $source . '"';

			if ($type === 'checkbox') {
				// The form does not send an unchecked checkbox, so the hidden field sends its value
				$options .= '<label class="option"' . $title . '><input type="hidden" name="' . $name . '" value="0"><input type="checkbox" value="1"' . checked($value, true, false) . $attributes . '><span class="details"><span class="label">' . esc_html($label) . '</span>' . $text . '</span></label>';

				continue;
			}

			if ($type === 'servers') {
				$lines = [];

				foreach ($value as [$server_host, $server_port]) {
					$lines[] = $server_port ? $server_host . ':' . $server_port : $server_host;
				}

				$control = '<textarea rows="3" class="code"' . $attributes . '>' . esc_textarea(implode("\n", $lines)) . '</textarea>';
			} else {
				$control = '<input type="' . $type . '" value="' . esc_attr(is_array($value) ? implode(',', $value) : (string) $value) . '"' . ($type === 'number' ? ' step="any"' : '') . ($type === 'password' ? ' autocomplete="new-password"' : '') . $attributes . '>';
			}

			// A text field needs two columns of the grid
			$items .= '<div class="item' . (in_array($type, ['text', 'servers'], true) ? ' item_wide' : '') . '"' . $title . '><label class="label" for="' . $id . '">' . esc_html($label) . '</label><div class="field">' . $control . '</div>' . $text . '</div>';
		}

		echo '<div class="items">' . $items . '</div>' . ($options ? '<div class="options">' . $options . '</div>' : '') . '</section>';
	}

	echo '</div><div class="buttons"><button type="submit" name="toc_save" value="1" class="button button-primary">Save Changes</button><button type="submit" name="toc_test" value="1" class="button">Test</button><button type="submit" name="toc_detect" value="1" class="button">Detect</button></div></form></div>';
}


/**
 * Get the URL of the Cache Settings page. On multisite, the page is in the Network Admin.
 */
function toc_settings_url(): string
{
	return is_multisite() ? network_admin_url('settings.php?page=twee-cache') : admin_url('options-general.php?page=twee-cache');
}


/**
 * Detect the settings of the host and of other object cache plugins
 *
 * The sources are the WP_REDIS_CONFIG array of Object Cache Pro, the $redis_server global of WP Redis, the constants of Redis Object Cache,
 * the $memcached_servers global, the environment variables, the sockets of the host and the default ports. A value from an earlier source wins.
 *
 * @return array The values with the keys of oc-config.php, and the names of the sources that gave them
 */
function toc_settings_detect(): array
{
	$values = [];
	$sources = [];
	$urls = [];

	// Twee Cache reads the constant itself, but the constant also shows that the host uses Redis
	if (defined('WP_REDIS_HOST')) {
		$values['WP_REDIS_HOST'] = WP_REDIS_HOST;
		$sources[] = 'WP_REDIS_HOST';
	}

	// Object Cache Pro. Hosts such as Nexcess also write it.
	if (defined('WP_REDIS_CONFIG') and is_array(WP_REDIS_CONFIG)) {
		$config = WP_REDIS_CONFIG;
		$keys = ['host' => 'WP_REDIS_HOST', 'port' => 'WP_REDIS_PORT', 'database' => 'WP_REDIS_DATABASE', 'username' => 'WP_REDIS_USERNAME', 'password' => 'WP_REDIS_PASSWORD', 'timeout' => 'WP_REDIS_TIMEOUT', 'retry_interval' => 'WP_REDIS_RETRY_INTERVAL', 'prefix' => 'WP_CACHE_KEY_SALT'];

		foreach ($keys as $key => $name) {
			if (isset($config[$key]) and is_scalar($config[$key])) {
				$values += [$name => $config[$key]];
			}
		}

		if (isset($config['compression'])) {
			$values += ['WP_REDIS_COMPRESSION' => ($config['compression'] !== 'none')];
		}

		if (isset($config['serializer'])) {
			$values += ['WP_REDIS_IGBINARY' => ($config['serializer'] === 'igbinary')];
		}

		$sources[] = 'WP_REDIS_CONFIG';
	}

	// WP Redis
	if (!empty($GLOBALS['redis_server']) and is_array($GLOBALS['redis_server'])) {
		$server = $GLOBALS['redis_server'];

		$values += array_filter([
			'WP_REDIS_HOST'     => $server['host'] ?? null,
			'WP_REDIS_PORT'     => $server['port'] ?? null,
			'WP_REDIS_PASSWORD' => $server['auth'] ?? null,
			'WP_REDIS_DATABASE' => $server['database'] ?? null,
		], 'is_scalar');

		$sources[] = '$redis_server';
	}

	// Redis Object Cache. Twee Cache reads its WP_REDIS_HOST, WP_REDIS_PORT and other constants with the same names itself.
	if (defined('WP_REDIS_SERVERS') and is_array(WP_REDIS_SERVERS) and is_string(current(WP_REDIS_SERVERS))) {
		$urls['WP_REDIS_SERVERS'] = current(WP_REDIS_SERVERS);
	}

	if (defined('WP_REDIS_SCHEME') and WP_REDIS_SCHEME === 'unix' and defined('WP_REDIS_PATH')) {
		$values += ['WP_REDIS_HOST' => WP_REDIS_PATH, 'WP_REDIS_PORT' => 0];
		$sources[] = 'WP_REDIS_PATH';
	}

	if (defined('WP_REDIS_PREFIX') and is_string(WP_REDIS_PREFIX)) {
		$values += ['WP_CACHE_KEY_SALT' => WP_REDIS_PREFIX];
		$sources[] = 'WP_REDIS_PREFIX';
	}

	if (defined('WP_REDIS_SERIALIZER')) {
		$values += ['WP_REDIS_IGBINARY' => in_array(WP_REDIS_SERIALIZER, ['igbinary', 2], true)];
		$sources[] = 'WP_REDIS_SERIALIZER';
	}

	// The environment variables of hosts such as Heroku and Pantheon
	foreach (['REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'CACHE_HOST', 'CACHE_PORT', 'CACHE_PASSWORD'] as $variable) {
		$value = getenv($variable) ?: ($_ENV[$variable] ?? $_SERVER[$variable] ?? '');

		if (!is_string($value) or $value === '') {
			continue;
		}

		if ($variable === 'REDIS_URL') {
			$urls[$variable] = $value;
		} else {
			$values += [str_replace(['REDIS_', 'CACHE_'], 'WP_REDIS_', $variable) => $value];
			$sources[] = $variable;
		}
	}

	// A URL such as redis://user:password@host:6379/0 or unix:///path/redis.sock?database=0
	foreach ($urls as $source => $url) {
		$parts = parse_url($url);

		if (!$parts) {
			continue;
		}

		parse_str($parts['query'] ?? '', $query);

		$unix = (($parts['scheme'] ?? '') === 'unix');
		$database = $unix ? ($query['database'] ?? null) : (trim($parts['path'] ?? '', '/') ?: ($query['database'] ?? null));

		$url_values = [
			'WP_REDIS_HOST'     => $unix ? ($parts['path'] ?? null) : ($parts['host'] ?? null),
			'WP_REDIS_PORT'     => $unix ? 0 : ($parts['port'] ?? null),
			'WP_REDIS_USERNAME' => isset($parts['user'], $parts['pass']) ? urldecode($parts['user']) : null,
			'WP_REDIS_PASSWORD' => isset($parts['pass']) ? urldecode($parts['pass']) : ($query['password'] ?? null),
			'WP_REDIS_DATABASE' => is_numeric($database) ? (int) $database : null,
		];

		foreach ($url_values as $name => $value) {
			if ($value !== null) {
				$values += [$name => $value];
			}
		}

		$sources[] = $source;
	}

	// The sockets of the host. On Nexcess, the name of the Redis socket has the name of the account from /chroot/home/{account}/.
	if (!isset($values['WP_REDIS_HOST'])) {
		$sockets = ['/var/run/redis/redis.sock', '/var/run/redis/redis-server.sock', '/run/redis/redis-server.sock', '/tmp/redis.sock'];

		if (preg_match('#^/chroot/home/([^/]+)/#', ABSPATH, $match)) {
			array_unshift($sockets, '/var/run/redis-multi-' . $match[1] . '.redis/redis.sock');
		}

		foreach ($sockets as $socket) {
			// open_basedir can block the path, so the warning is hidden
			if (@file_exists($socket)) {
				$values += ['WP_REDIS_HOST' => $socket, 'WP_REDIS_PORT' => 0];
				$sources[] = $socket;
				break;
			}
		}
	}

	// WP Engine and the Memcached plugins of WordPress.org
	if (!empty($GLOBALS['memcached_servers']) and is_array($GLOBALS['memcached_servers'])) {
		$values['memcached_servers'] = $GLOBALS['memcached_servers'];
		$sources[] = '$memcached_servers';
	}

	$host = getenv('WPE_CACHE_HOST') ?: ($_ENV['WPE_CACHE_HOST'] ?? '');

	if (!isset($values['memcached_servers']) and is_string($host) and $host !== '') {
		$values['memcached_servers'] = [$host];
		$sources[] = 'WPE_CACHE_HOST';
	}

	if (!isset($values['memcached_servers'])) {
		foreach (['/tmp/memcached.sock', '/var/run/memcached/memcached.sock'] as $socket) {
			if (@file_exists($socket)) {
				$values['memcached_servers'] = [[$socket, 0]];
				$sources[] = $socket;
				break;
			}
		}
	}

	// The default ports, such as Memcached on SiteGround
	if (!isset($values['WP_REDIS_HOST']) and !isset($values['memcached_servers'])) {
		$ports = [
			6379  => ['WP_REDIS_HOST' => '127.0.0.1', 'WP_REDIS_PORT' => 6379],
			11211 => ['memcached_servers' => [['127.0.0.1', 11211]]],
		];

		foreach ($ports as $port => $port_values) {
			$connection = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);

			if ($connection) {
				fclose($connection);
				$values += $port_values;
				$sources[] = '127.0.0.1:' . $port;
				break;
			}
		}
	}

	if (isset($values['WP_REDIS_HOST']) and extension_loaded('redis')) {
		$values['handler'] = 'redis';
	} elseif (isset($values['memcached_servers']) and extension_loaded('memcached')) {
		$values['handler'] = 'memcached';
	}

	return ['values' => $values, 'sources' => $sources];
}


/**
 * Get the settings with their defaults. A constant in wp-config.php wins over a setting.
 *
 * When oc-config.php does not exist, the detected settings replace it.
 *
 * @param array|null $input The values of the form or of the detection, or null for the saved settings
 *
 * @return array The handler, the trace flag and the value of each field in TOC_SETTINGS
 */
function toc_settings_get(?array $input = null): array
{
	$config_file = WP_CONTENT_DIR . '/oc-config.php';
	$values = $input ?? (is_file($config_file) ? (array) include $config_file : toc_settings_detect()['values']);
	$handler = (string) ($values['handler'] ?? '');

	$settings = [
		'handler' => in_array($handler, ['redis', 'memcached', 'apcu'], true) ? $handler : '',
		'trace'   => !empty($values['trace']),
	];

	// The switches with the default null are on when the server supports them, as in the drop-ins
	$supported = [
		'WP_REDIS_IGBINARY'     => (defined('Redis::SERIALIZER_IGBINARY') and extension_loaded('igbinary')),
		'WP_REDIS_COMPRESSION'  => (defined('Redis::COMPRESSION_ZSTD') or defined('Redis::COMPRESSION_LZ4') or defined('Redis::COMPRESSION_LZF')),
		'WP_MEMCACHED_IGBINARY' => (defined('Memcached::HAVE_IGBINARY') and Memcached::HAVE_IGBINARY and function_exists('igbinary_serialize')),
	];

	foreach (TOC_SETTINGS as $fields) {
		foreach ($fields as $name => [, $type, $default]) {
			$value = $values[$name] ?? $default ?? $supported[$name];

			// The $memcached_servers global of wp-config.php wins over the setting
			if ($name === 'memcached_servers' and !empty($GLOBALS['memcached_servers']) and is_array($GLOBALS['memcached_servers'])) {
				$value = $GLOBALS['memcached_servers'];
			}

			if ($type === 'checkbox') {
				$value = (bool) $value;
			} elseif ($type === 'number') {
				$value = is_numeric($value) ? $value + 0 : $default;
			} elseif ($type === 'servers') {
				// Other plugins and hosts such as WP Engine put the servers in buckets, as "host:port" or "unix://path" strings
				if (is_array($value) and !array_is_list($value)) {
					$value = (array) ($value['default'] ?? reset($value));
				}

				$servers = [];

				foreach (is_string($value) ? preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) : (array) $value as $server) {
					if (is_array($server)) {
						$servers[] = [(string) ($server[0] ?? ''), (int) ($server[1] ?? 0)];
						continue;
					}

					// A Unix socket has no port
					$server = preg_replace('#^unix://#', '', (string) $server);
					$servers[] = str_starts_with($server, '/') ? [$server, 0] : [strtok($server, ':'), (int) (strtok(':') ?: 11211)];
				}

				$value = $servers ?: $default;
			} else {
				$value = trim((string) $value);
			}

			if (defined($name)) {
				$value = constant($name);
			}

			$settings[$name] = $value;
		}
	}

	return $settings;
}


/**
 * Write the settings to wp-content/oc-config.php
 *
 * The file is replaced with a rename, so a request never loads a partly written file. The values of constants stay in wp-config.php only.
 *
 * @param array $settings The settings from toc_settings_get()
 *
 * @return bool
 */
function toc_settings_put(array $settings): bool
{
	foreach ($settings as $name => $value) {
		if (defined($name) or ($name === 'memcached_servers' and !empty($GLOBALS['memcached_servers']))) {
			unset($settings[$name]);
		}
	}

	$config_file = WP_CONTENT_DIR . '/oc-config.php';
	$temp_file = $config_file . '.' . uniqid() . '.tmp';
	$code = "<?php\n// The Cache Settings page of Twee Cache writes this file. A constant in wp-config.php wins over a value here.\nreturn " . var_export($settings, true) . ";\n";

	if (@file_put_contents($temp_file, $code) === false) {
		return false;
	}

	if (!@rename($temp_file, $config_file)) {
		@unlink($temp_file);
		return false;
	}

	// OPcache can keep the old settings when it does not examine the file time
	if (function_exists('opcache_invalidate')) {
		opcache_invalidate($config_file, true);
	}

	return true;
}
