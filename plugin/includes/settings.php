<?php
/**
 * The Cache Settings page. It saves the settings in the toc_settings site option, and writes a copy of them into the drop-in.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The fields of each handler: the label, the type, the default value, the description and the unit that shows after a number field
 *
 * The key of a field is the name of the wp-config.php constant that wins over it, in lower case and without WP_.
 * The $memcached_servers global wins over the memcached_servers field.
 * A switch with the default null is on when the server supports it.
 */
const TOC_SETTINGS = [
	'general'   => [
		'cache_key_salt' => ['Key salt', 'text', '', 'The namespace of the cache keys. When it is empty, the drop-in uses the database name and the table prefix.'],
	],
	'redis'     => [
		'redis_host'               => ['Host', 'text', '127.0.0.1', 'A host name, an IP address or the path of a Unix socket.'],
		'redis_port'               => ['Port', 'number', 6379, ''],
		'redis_username'           => ['Username', 'text', '', 'The ACL user of Redis 6 and later. Keep it empty to log in with the password only.'],
		'redis_password'           => ['Password', 'password', '', 'Keep it empty when Redis has no password.'],
		'redis_database'           => ['Database', 'number', 0, ''],
		'redis_timeout'            => ['Timeout', 'number', 1, 'The time to connect and to read a reply.', 's'],
		'redis_retry_interval'     => ['Retry interval', 'number', 100, 'The time before the next connection attempt.', 'ms'],
		'redis_persistent_connect' => ['Persistent connection', 'checkbox', true, 'Keep the connection open between requests.'],
		'redis_igbinary'           => ['igbinary', 'checkbox', null, 'Serialize the values with igbinary. It is on by default when the igbinary extension is loaded.'],
		'redis_compression'        => ['Compression', 'checkbox', null, 'Compress the values with zstd, LZ4 or LZF. It is on by default when phpredis supports one of them.'],
		'redis_use_relay'          => ['Relay', 'checkbox', false, 'Connect through Relay when the extension is loaded.'],
		'redis_persistent_groups'  => ['Protected groups', 'text', 'plugins,themes,counts', 'Comma-separated groups that stay persistent when a plugin makes them non-persistent.'],
	],
	'memcached' => [
		'memcached_servers'           => ['Servers', 'servers', [['127.0.0.1', 11211]], 'One host:port or Unix socket path on each line.'],
		'memcached_connect_timeout'   => ['Connect timeout', 'number', 250, '', 'ms'],
		'memcached_send_timeout'      => ['Send timeout', 'number', 250, '', 'ms'],
		'memcached_recv_timeout'      => ['Receive timeout', 'number', 250, '', 'ms'],
		'memcached_igbinary'          => ['igbinary', 'checkbox', null, 'Serialize the values with igbinary. It is on by default when the memcached extension supports igbinary.'],
		'memcached_persistent_groups' => ['Protected groups', 'text', 'plugins,themes,counts', 'Comma-separated groups that stay persistent when a plugin makes them non-persistent.'],
	],
	'apcu'      => [
		'apcu_persistent_groups' => ['Protected groups', 'text', 'plugins,themes,counts', 'Comma-separated groups that stay persistent when a plugin makes them non-persistent.'],
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
 * When the cache is on, the drop-in holds a copy of the settings, so it is written again.
 */
function toc_settings_action(): void
{
	wp_enqueue_style('toc_cache', plugins_url('assets/css/cache.css', __DIR__), [], (string) filemtime(dirname(__DIR__) . '/assets/css/cache.css'));

	if (empty($_POST['toc_save'])) {
		return;
	}

	check_admin_referer('toc_settings');

	$result = toc_settings_save(toc_settings_get(wp_unslash($_POST)), toc_dropin_engine() !== '');

	add_settings_error('toc', 'toc_save', $result['ok'] ? 'Settings saved.' : $result['message'], $result['ok'] ? 'success' : 'error');
}


/**
 * Show the Cache Settings page
 */
function toc_settings_page(): void
{
	$engine = toc_dropin_engine();
	$names = ['redis' => 'Redis', 'memcached' => 'Memcached', 'apcu' => 'APCu'];
	$loaded = ['redis' => extension_loaded('redis'), 'memcached' => extension_loaded('memcached'), 'apcu' => extension_loaded('apcu')];
	$supported = toc_settings_supported();
	$test = null;
	$saved = is_array(get_site_option('toc_settings'));
	$detected = toc_settings_detect();
	$found = toc_settings_get($detected['values']);

	// The form keeps its values after a test and after a save that failed. The test does not save them.
	if (!empty($_POST['toc_test']) or !empty($_POST['toc_save'])) {
		check_admin_referer('toc_settings');

		$settings = toc_settings_get(wp_unslash($_POST));
		$test = empty($_POST['toc_test']) ? null : toc_dropin_test($settings['handler'], $settings);
	} elseif (!empty($_POST['toc_reset'])) {
		check_admin_referer('toc_settings');

		// The connection fields get the found values or the defaults. The other fields keep the values of the form, and nothing is saved.
		$settings = toc_settings_get(wp_unslash($_POST));

		foreach (['redis_host', 'redis_port', 'redis_username', 'redis_password', 'redis_database', 'redis_timeout', 'redis_retry_interval', 'memcached_servers', 'memcached_connect_timeout', 'memcached_send_timeout', 'memcached_recv_timeout'] as $name) {
			$settings[$name] = $found[$name];
		}
	} else {
		// Without saved settings, the form shows the settings found on the server
		$settings = $saved ? toc_settings_get() : $found;

		// The Admin Bar menu can enable a handler without a change of the settings
		if ($engine !== '') {
			$settings['handler'] = $engine;
		}
	}

	if ($settings['handler'] === '') {
		$settings['handler'] = array_key_first(array_filter($loaded)) ?? 'redis';
	}

	// A found value that is different from the value of the form shows under the field. A timeout of 1.0 is the same as 1.
	$hints = [];

	foreach (array_keys($detected['values']) as $name) {
		if (array_key_exists($name, $found) and $found[$name] != $settings[$name]) {
			$hints[$name] = $found[$name];
		}
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

	$sources = esc_html(implode(', ', $detected['sources']));

	if (!empty($_POST['toc_reset'])) {
		echo '<div class="notice notice-info inline"><p><strong>The connection fields show the values found on this server or the defaults.</strong> They are not saved. Select Test Connection to examine them, then select Save Changes.</p></div>';
	} elseif (!$saved and $sources) {
		echo '<div class="notice notice-info inline"><p><strong>The form shows the settings found on this server.</strong> Sources: ' . $sources . '. Select Test Connection to examine them, then select Save Changes or Enable Cache.</p></div>';
	} elseif ($hints) {
		echo '<div class="notice notice-info inline"><p><strong>Some settings found on this server are different.</strong> They show under the fields. Sources: ' . $sources . '.</p></div>';
	}

	echo '<form method="post"><div class="sections">';

	wp_nonce_field('toc_settings');

	// The Enter key selects the first submit button of the form, so it must be Save Changes and not a button of a section
	echo '<button type="submit" name="toc_save" value="1" hidden></button>';

	if ($engine !== '') {
		$status = '<span class="caption caption_success">On</span><div class="buttons"><a class="button" href="' . esc_url(wp_nonce_url(add_query_arg('toc_action', 'disable'), 'toc_action')) . '" title="Remove the drop-in">Disable Cache</a></div>';
	} elseif (file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
		$status = '<span class="caption caption_error">Another plugin installed wp-content/object-cache.php</span>';
	} else {
		$status = '<span class="caption">Off</span><div class="buttons"><a class="button button-primary" href="' . esc_url(wp_nonce_url(add_query_arg('toc_action', 'enable_' . $settings['handler']), 'toc_action')) . '" title="Test the saved settings, install the drop-in and flush the cache">Enable Cache</a></div>';
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

			$items .= '</select></div><span class="text">When the cache is on, Save Changes writes the drop-in again.</span>' . (isset($hints['handler']) ? '<span class="text text_found">Found on this server: ' . $names[$hints['handler']] . '</span>' : '') . '</div>';
		} else {
			// Only the section of the selected handler shows, so its buttons use the selected handler. APCu has no connection fields to reset.
			$buttons = '<button type="submit" name="toc_test" value="1" class="button" title="Connect with the values of the form, then write, read and delete a test key. The values are not saved.">Test Connection</button>';

			if ($section !== 'apcu') {
				$buttons .= '<button type="submit" name="toc_reset" value="1" class="button" title="Fill the connection fields with the values found on this server or the defaults. The values are not saved.">Reset Connection</button>';
			}

			echo '<section class="section" data-handler="' . $section . '"><div class="contents contents_split"><h2>' . $names[$section] . '</h2><div class="buttons">' . $buttons . '</div></div>';
		}

		foreach ($fields as $name => $field) {
			[$label, $type, , $description] = $field;
			$unit = $field[4] ?? '';
			$value = $settings[$name];
			$global = ($name === 'memcached_servers');
			$constant = 'WP_' . strtoupper($name);
			$locked = $global ? !empty($GLOBALS['memcached_servers']) : defined($constant);
			$source = $global ? '$memcached_servers' : $constant;
			$id = 'toc-' . strtolower(str_replace('_', '-', $name));
			$available = ($supported[$name] ?? true);
			$attributes = ' id="' . $id . '" name="' . $name . '"' . (($locked or !$available) ? ' disabled' : '');
			$text = $locked ? '<code>' . $source . '</code> in <code>wp-config.php</code> sets this value.' : esc_html($description) . ($available ? '' : ' Not available on this server.');
			$text = $text === '' ? '' : '<span class="text">' . $text . '</span>';
			$title = ' title="Constant: ' . $source . '"';

			// The found value shows as the field shows it, but the page does not show a found password
			if (isset($hints[$name])) {
				$hint = $hints[$name];

				if ($type === 'checkbox') {
					$hint = $hint ? 'on' : 'off';
				} elseif ($type === 'password') {
					$hint = 'a password';
				} elseif ($type === 'servers') {
					$hint = implode(', ', toc_settings_servers($hint));
				}

				$text .= '<span class="text text_found">Found on this server: ' . esc_html((string) $hint) . '</span>';
			}

			if ($type === 'checkbox') {
				// The form does not send an unchecked checkbox, so the hidden field sends its value
				$options .= '<label class="option"' . $title . '><input type="hidden" name="' . $name . '" value="0"><input type="checkbox" value="1"' . checked($value, true, false) . $attributes . '><span class="details"><span class="label">' . esc_html($label) . '</span>' . $text . '</span></label>';

				continue;
			}

			if ($type === 'servers') {
				$control = '<textarea rows="3" class="code"' . $attributes . '>' . esc_textarea(implode("\n", toc_settings_servers($value))) . '</textarea>';
			} else {
				$control = '<input type="' . $type . '" value="' . esc_attr(is_array($value) ? implode(',', $value) : (string) $value) . '"' . ($type === 'number' ? ' step="any"' : '') . ($type === 'password' ? ' autocomplete="new-password"' : '') . $attributes . '>';
			}

			// A text field takes two columns of the grid. The servers and the protected groups take the full row, so the number fields fill the rows between them.
			$class = 'item';

			if ($type === 'servers' or str_ends_with($name, '_persistent_groups')) {
				$class .= ' item_full';
			} elseif ($type === 'text') {
				$class .= ' item_wide';
			}

			$items .= '<div class="' . $class . '"' . $title . '><label class="label" for="' . $id . '">' . esc_html($label) . '</label><div class="field' . ($unit ? ' field_unit' : '') . '">' . $control . ($unit ? '<span class="unit">' . $unit . '</span>' : '') . '</div>' . $text . '</div>';
		}

		echo '<div class="items">' . $items . '</div>' . ($options ? '<div class="options">' . $options . '</div>' : '') . '</section>';
	}

	echo '</div><div class="buttons"><button type="submit" name="toc_save" value="1" class="button button-primary">Save Changes</button></div></form></div>';
}


/**
 * Get the Memcached servers as the Servers field shows them
 *
 * @param array $servers The [host, port] pairs from toc_settings_get()
 *
 * @return array A "host:port" string for each server, or the path of a Unix socket
 */
function toc_settings_servers(array $servers): array
{
	$lines = [];

	foreach ($servers as [$host, $port]) {
		$lines[] = $port ? $host . ':' . $port : $host;
	}

	return $lines;
}


/**
 * Detect the settings of the host and of other object cache plugins
 *
 * The sources are the WP_REDIS_CONFIG array of Object Cache Pro, the $redis_server global of WP Redis, the constants of Redis Object Cache,
 * the $memcached_servers global, the environment variables, the sockets of the host and the default ports. A value from an earlier source wins.
 *
 * @return array The values with the keys of TOC_SETTINGS, and the names of the sources that gave them
 */
function toc_settings_detect(): array
{
	$values = [];
	$sources = [];
	$urls = [];

	// Twee Cache reads the constant itself, but the constant also shows that the host uses Redis
	if (defined('WP_REDIS_HOST')) {
		$values['redis_host'] = WP_REDIS_HOST;
		$sources[] = 'WP_REDIS_HOST';
	}

	// Object Cache Pro. Hosts such as Nexcess also write it.
	if (defined('WP_REDIS_CONFIG') and is_array(WP_REDIS_CONFIG)) {
		$config = WP_REDIS_CONFIG;
		$keys = ['host' => 'redis_host', 'port' => 'redis_port', 'database' => 'redis_database', 'username' => 'redis_username', 'password' => 'redis_password', 'timeout' => 'redis_timeout', 'retry_interval' => 'redis_retry_interval', 'prefix' => 'cache_key_salt'];

		foreach ($keys as $key => $name) {
			if (isset($config[$key]) and is_scalar($config[$key])) {
				$values += [$name => $config[$key]];
			}
		}

		if (isset($config['compression'])) {
			$values += ['redis_compression' => ($config['compression'] !== 'none')];
		}

		if (isset($config['serializer'])) {
			$values += ['redis_igbinary' => ($config['serializer'] === 'igbinary')];
		}

		$sources[] = 'WP_REDIS_CONFIG';
	}

	// WP Redis
	if (!empty($GLOBALS['redis_server']) and is_array($GLOBALS['redis_server'])) {
		$server = $GLOBALS['redis_server'];

		$values += array_filter([
			'redis_host'     => $server['host'] ?? null,
			'redis_port'     => $server['port'] ?? null,
			'redis_password' => $server['auth'] ?? null,
			'redis_database' => $server['database'] ?? null,
		], 'is_scalar');

		$sources[] = '$redis_server';
	}

	// Redis Object Cache. Twee Cache reads its WP_REDIS_HOST, WP_REDIS_PORT and other constants with the same names itself.
	if (defined('WP_REDIS_SERVERS') and is_array(WP_REDIS_SERVERS) and is_string(current(WP_REDIS_SERVERS))) {
		$urls['WP_REDIS_SERVERS'] = current(WP_REDIS_SERVERS);
	}

	if (defined('WP_REDIS_SCHEME') and WP_REDIS_SCHEME === 'unix' and defined('WP_REDIS_PATH')) {
		$values += ['redis_host' => WP_REDIS_PATH, 'redis_port' => 0];
		$sources[] = 'WP_REDIS_PATH';
	}

	if (defined('WP_REDIS_PREFIX') and is_string(WP_REDIS_PREFIX)) {
		$values += ['cache_key_salt' => WP_REDIS_PREFIX];
		$sources[] = 'WP_REDIS_PREFIX';
	}

	if (defined('WP_REDIS_SERIALIZER')) {
		$values += ['redis_igbinary' => in_array(WP_REDIS_SERIALIZER, ['igbinary', 2], true)];
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
			$values += [str_replace(['REDIS_', 'CACHE_'], 'redis_', $variable) => $value];
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
			'redis_host'     => $unix ? ($parts['path'] ?? null) : ($parts['host'] ?? null),
			'redis_port'     => $unix ? 0 : ($parts['port'] ?? null),
			'redis_username' => isset($parts['user'], $parts['pass']) ? urldecode($parts['user']) : null,
			'redis_password' => isset($parts['pass']) ? urldecode($parts['pass']) : ($query['password'] ?? null),
			'redis_database' => is_numeric($database) ? (int) $database : null,
		];

		foreach ($url_values as $name => $value) {
			if ($value !== null) {
				$values += [$name => $value];
			}
		}

		$sources[] = $source;
	}

	// The sockets of the host. On Nexcess, the name of the Redis socket has the name of the account from /chroot/home/{account}/.
	if (!isset($values['redis_host'])) {
		$sockets = ['/var/run/redis/redis.sock', '/var/run/redis/redis-server.sock', '/run/redis/redis-server.sock', '/tmp/redis.sock'];

		if (preg_match('#^/chroot/home/([^/]+)/#', ABSPATH, $match)) {
			array_unshift($sockets, '/var/run/redis-multi-' . $match[1] . '.redis/redis.sock');
		}

		foreach ($sockets as $socket) {
			// open_basedir can block the path, so the warning is hidden
			if (@file_exists($socket)) {
				$values += ['redis_host' => $socket, 'redis_port' => 0];
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
	if (!isset($values['redis_host']) and !isset($values['memcached_servers'])) {
		$ports = [
			6379  => ["PING\r\n", ['redis_host' => '127.0.0.1', 'redis_port' => 6379]],
			11211 => ["version\r\n", ['memcached_servers' => [['127.0.0.1', 11211]]]],
		];

		foreach ($ports as $port => [$command, $port_values]) {
			$connection = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);

			if (!$connection) {
				continue;
			}

			// Redis answers +PONG, or NOAUTH when it needs a password, and Memcached answers VERSION. Another program on the port does not count.
			stream_set_timeout($connection, 0, 200000);
			fwrite($connection, $command);
			$reply = (string) fgets($connection, 128);
			fclose($connection);

			if (str_starts_with($reply, '+PONG') or str_starts_with($reply, 'VERSION ')) {
				$sources[] = '127.0.0.1:' . $port;
			} elseif (str_starts_with($reply, '-NOAUTH')) {
				$sources[] = '127.0.0.1:' . $port . ' (Redis needs a password)';
			} else {
				continue;
			}

			$values += $port_values;
			break;
		}
	}

	if (isset($values['redis_host']) and extension_loaded('redis')) {
		$values['handler'] = 'redis';
	} elseif (isset($values['memcached_servers']) and extension_loaded('memcached')) {
		$values['handler'] = 'memcached';
	}

	return ['values' => $values, 'sources' => $sources];
}


/**
 * Find the switches that the PHP extensions of the server support
 *
 * @return array The support of igbinary and compression for Redis, of Relay, and of igbinary for Memcached
 */
function toc_settings_supported(): array
{
	return [
		'redis_igbinary'     => (defined('Redis::SERIALIZER_IGBINARY') and extension_loaded('igbinary')),
		'redis_compression'  => (defined('Redis::COMPRESSION_ZSTD') or defined('Redis::COMPRESSION_LZ4') or defined('Redis::COMPRESSION_LZF')),
		'redis_use_relay'    => class_exists('Relay\Relay'),
		'memcached_igbinary' => (defined('Memcached::HAVE_IGBINARY') and Memcached::HAVE_IGBINARY and function_exists('igbinary_serialize')),
	];
}


/**
 * Get the settings with their defaults. A constant in wp-config.php wins over a setting.
 *
 * Without saved settings, the detected settings replace them.
 *
 * @param array|null $input The values of the form or of the detection, or null for the saved settings
 *
 * @return array The handler and the value of each field in TOC_SETTINGS
 */
function toc_settings_get(?array $input = null): array
{
	$values = $input ?? get_site_option('toc_settings');

	if (!is_array($values)) {
		$values = toc_settings_detect()['values'];
	}

	$handler = (string) ($values['handler'] ?? '');

	$settings = [
		'handler' => in_array($handler, ['redis', 'memcached', 'apcu'], true) ? $handler : '',
	];

	// The switches with the default null are on when the server supports them, as in the drop-ins
	$supported = toc_settings_supported();

	foreach (TOC_SETTINGS as $fields) {
		foreach ($fields as $name => [, $type, $default]) {
			$value = $values[$name] ?? $default ?? $supported[$name];

			// The $memcached_servers global of wp-config.php wins over the setting
			if ($name === 'memcached_servers' and !empty($GLOBALS['memcached_servers']) and is_array($GLOBALS['memcached_servers'])) {
				$value = $GLOBALS['memcached_servers'];
			}

			if ($type === 'checkbox') {
				// A switch that the server does not support is off, so that the page does not suggest it
				$value = ((bool) $value and ($supported[$name] ?? true));
			} elseif ($type === 'number') {
				$value = is_numeric($value) ? $value + 0 : $default;
			} elseif ($type === 'servers') {
				// Other plugins and hosts such as WP Engine put the servers in buckets, as "host:port" or "unix://path" strings
				if (is_array($value) and $value !== array_values($value)) {
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

			$constant = 'WP_' . strtoupper($name);

			if (defined($constant)) {
				$value = constant($constant);
			}

			$settings[$name] = $value;
		}
	}

	return $settings;
}


/**
 * Save the settings in the toc_settings site option, and write the drop-in with them
 *
 * The next request uses the new drop-in, so the settings must pass the test first. The values of constants stay in wp-config.php only.
 *
 * @param array $settings The settings from toc_settings_get()
 * @param bool  $install  Test the settings and write the drop-in. Without it, the settings are only saved.
 *
 * @return array The result (ok) and a message for the user when it failed (message)
 */
function toc_settings_save(array $settings, bool $install): array
{
	if ($install) {
		$test = toc_dropin_test($settings['handler'], $settings);

		if (!$test['ok']) {
			return ['ok' => false, 'message' => 'The settings are not saved. ' . $test['message']];
		}
	}

	foreach ($settings as $name => $value) {
		if (defined('WP_' . strtoupper($name)) or ($name === 'memcached_servers' and !empty($GLOBALS['memcached_servers']))) {
			unset($settings[$name]);
		}
	}

	update_site_option('toc_settings', $settings);

	if (!$install) {
		return ['ok' => true, 'message' => ''];
	}

	if (!toc_dropin_install()) {
		return ['ok' => false, 'message' => 'The settings are saved, but wp-content/object-cache.php is not written. Make sure that the web server can write to the wp-content folder.'];
	}

	// The cache can hold values from before the drop-in, in another format or from another backend, so the drop-in flushes it before its first read.
	// The marker comes after the new drop-in, because a request with the old drop-in can take it.
	@touch(WP_CONTENT_DIR . '/cache-flush');

	return ['ok' => true, 'message' => ''];
}
