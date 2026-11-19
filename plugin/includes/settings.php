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
 */
const TOC_SETTINGS = [
	'general'   => [
		'WP_CACHE_KEY_SALT' => ['Key salt', 'text', '', 'The namespace of the cache keys. When it is empty, the drop-in uses the database name and the table prefix.'],
	],
	'redis'     => [
		'WP_REDIS_HOST'               => ['Host', 'text', '127.0.0.1', 'A host name, an IP address or the path of a Unix socket.'],
		'WP_REDIS_PORT'               => ['Port', 'number', 6379, ''],
		'WP_REDIS_PASSWORD'           => ['Password', 'password', '', 'Keep it empty when Redis has no password.'],
		'WP_REDIS_DATABASE'           => ['Database', 'number', 0, ''],
		'WP_REDIS_TIMEOUT'            => ['Timeout', 'number', 1, 'The time in seconds to connect and to read a reply.'],
		'WP_REDIS_RETRY_INTERVAL'     => ['Retry interval', 'number', 100, 'The time in milliseconds before the next connection attempt.'],
		'WP_REDIS_PERSISTENT_CONNECT' => ['Persistent connection', 'checkbox', true, 'Keep the connection open between requests.'],
		'WP_REDIS_IGBINARY'           => ['igbinary', 'checkbox', true, 'Serialize the values with igbinary when the extension is loaded.'],
		'WP_REDIS_COMPRESSION'        => ['Compression', 'checkbox', true, 'Compress the values with zstd, LZ4 or LZF when phpredis supports it.'],
		'WP_REDIS_USE_RELAY'          => ['Relay', 'checkbox', false, 'Connect through Relay when the extension is loaded.'],
		'WP_REDIS_PERSISTENT_GROUPS'  => ['Protected groups', 'text', 'plugins,themes,counts', 'Comma-separated groups that stay persistent when a plugin makes them non-persistent.'],
	],
	'memcached' => [
		'memcached_servers'              => ['Servers', 'servers', [['127.0.0.1', 11211]], 'One host:port or Unix socket path on each line.'],
		'WP_MEMCACHED_CONNECT_TIMEOUT'   => ['Connect timeout', 'number', 250, 'In milliseconds.'],
		'WP_MEMCACHED_SEND_TIMEOUT'      => ['Send timeout', 'number', 250, 'In milliseconds.'],
		'WP_MEMCACHED_RECV_TIMEOUT'      => ['Receive timeout', 'number', 250, 'In milliseconds.'],
		'WP_MEMCACHED_IGBINARY'          => ['igbinary', 'checkbox', false, 'Serialize the values with igbinary when the extension is loaded.'],
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
	$args = ['settings-updated' => 'true'];
	$test = $engine !== '' ? toc_dropin_test($settings['handler'], $settings) : ['ok' => true];

	if (!$test['ok']) {
		add_settings_error('toc', 'toc_test', 'The settings are not saved. ' . $test['message']);
	} elseif (!toc_settings_put($settings)) {
		add_settings_error('toc', 'toc_write', 'Cannot write ' . WP_CONTENT_DIR . '/oc-config.php. Make sure that the web server can write to the wp-content folder.');
	} elseif ($engine !== '' and $settings['handler'] !== $engine and !toc_dropin_install($settings['handler'])) {
		add_settings_error('toc', 'toc_install', 'The settings are saved, but the drop-in is not replaced. Make sure that the web server can write to the wp-content folder.');
	} else {
		add_settings_error('toc', 'toc_saved', 'Settings saved.', 'success');

		// The new drop-in loads on the next request, so that request removes the values that were saved before
		if ($engine !== '' and $settings['handler'] !== $engine) {
			$args['toc_action'] = 'flush';
			$args['_wpnonce'] = wp_create_nonce('toc_action');
		}
	}

	set_transient('settings_errors', get_settings_errors(), 30);

	wp_safe_redirect(add_query_arg($args, toc_settings_url()));
	exit;
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

	// The test uses the values of the form and does not save them
	if (!empty($_POST['toc_test'])) {
		check_admin_referer('toc_settings');

		$settings = toc_settings_get(wp_unslash($_POST));
		$test = toc_dropin_test($settings['handler'], $settings);
	} else {
		$settings = toc_settings_get();

		// The Admin Bar menu can enable a handler without a change of the settings
		if ($engine !== '') {
			$settings['handler'] = $engine;
		}
	}

	if ($settings['handler'] === '') {
		$settings['handler'] = array_key_first(array_filter($loaded)) ?? 'redis';
	}

	echo '<div class="wrap cache_box"><h1>Cache Settings</h1>';

	// WordPress shows the saved notice under Settings, but not in the Network Admin
	if (is_network_admin()) {
		settings_errors('toc');
	}

	if ($test) {
		echo '<div class="notice inline ' . ($test['ok'] ? 'notice-success' : 'notice-error') . '"><p><strong>' . ($test['ok'] ? 'The test passed.' : 'The test failed.') . '</strong> ' . esc_html($test['message']) . '</p></div>';
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
				$options .= '<label class="option"' . $title . '><input type="checkbox" value="1"' . checked($value, true, false) . $attributes . '><span class="details"><span class="label">' . esc_html($label) . '</span>' . $text . '</span></label>';

				continue;
			}

			if ($type === 'servers') {
				$lines = array_map(fn($server) => is_array($server) ? (empty($server[1]) ? $server[0] : $server[0] . ':' . $server[1]) : (string) $server, (array) $value);
				$control = '<textarea rows="3" class="code"' . $attributes . '>' . esc_textarea(implode("\n", $lines)) . '</textarea>';
			} else {
				$control = '<input type="' . $type . '" value="' . esc_attr(is_array($value) ? implode(',', $value) : (string) $value) . '"' . ($type === 'number' ? ' step="any"' : '') . ($type === 'password' ? ' autocomplete="new-password"' : '') . $attributes . '>';
			}

			// A text field needs two columns of the grid
			$items .= '<div class="item' . (in_array($type, ['text', 'servers'], true) ? ' item_wide' : '') . '"' . $title . '><label class="label" for="' . $id . '">' . esc_html($label) . '</label><div class="field">' . $control . '</div>' . $text . '</div>';
		}

		echo '<div class="items">' . $items . '</div>' . ($options ? '<div class="options">' . $options . '</div>' : '') . '</section>';
	}

	echo '</div><div class="buttons"><button type="submit" name="toc_save" value="1" class="button button-primary">Save Changes</button><button type="submit" name="toc_test" value="1" class="button">Test</button></div></form></div>';
}


/**
 * Get the URL of the Cache Settings page. On multisite, the page is in the Network Admin.
 */
function toc_settings_url(): string
{
	return is_multisite() ? network_admin_url('settings.php?page=twee-cache') : admin_url('options-general.php?page=twee-cache');
}


/**
 * Get the settings with their defaults. A constant in wp-config.php wins over a setting.
 *
 * @param array|null $input The values of the form, or null for the saved settings
 *
 * @return array The handler, the trace flag and the value of each field in TOC_SETTINGS
 */
function toc_settings_get(?array $input = null): array
{
	$file = WP_CONTENT_DIR . '/oc-config.php';
	$values = $input ?? (is_file($file) ? (array) include $file : []);
	$handler = (string) ($values['handler'] ?? '');

	$settings = [
		'handler' => in_array($handler, ['redis', 'memcached', 'apcu'], true) ? $handler : '',
		'trace'   => !empty($values['trace']),
	];

	foreach (TOC_SETTINGS as $fields) {
		foreach ($fields as $name => [, $type, $default]) {
			// The form does not send an unchecked checkbox
			$value = $values[$name] ?? (($input !== null and $type === 'checkbox') ? false : $default);

			if ($type === 'checkbox') {
				$value = (bool) $value;
			} elseif ($type === 'number') {
				$value = is_numeric($value) ? $value + 0 : $default;
			} elseif ($type === 'servers' and is_string($value)) {
				$servers = [];

				foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $server) {
					// A Unix socket has no port
					$servers[] = str_starts_with($server, '/') ? [$server, 0] : [strtok($server, ':'), (int) (strtok(':') ?: 11211)];
				}

				$value = $servers ?: $default;
			} elseif ($type !== 'servers') {
				$value = trim((string) $value);
			}

			if ($name === 'memcached_servers' and !empty($GLOBALS['memcached_servers']) and is_array($GLOBALS['memcached_servers'])) {
				$value = $GLOBALS['memcached_servers'];
			} elseif (defined($name)) {
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

	$file = WP_CONTENT_DIR . '/oc-config.php';
	$temp_file = $file . '.' . uniqid() . '.tmp';
	$code = "<?php\n// The Cache Settings page of Twee Cache writes this file. A constant in wp-config.php wins over a value here.\nreturn " . var_export($settings, true) . ";\n";

	if (@file_put_contents($temp_file, $code) === false) {
		return false;
	}

	if (!@rename($temp_file, $file)) {
		@unlink($temp_file);
		return false;
	}

	// OPcache can keep the old settings when it does not examine the file time
	if (function_exists('opcache_invalidate')) {
		opcache_invalidate($file, true);
	}

	return true;
}
