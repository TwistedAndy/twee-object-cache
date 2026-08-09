<?php
/**
 * PHPUnit bootstrap for the Twee Object Cache drop-ins.
 *
 * The object cache drop-ins implement the wp_cache_* surface and rely on only
 * a small handful of WordPress helper functions. We define lightweight stubs
 * for those helpers here, then load the selected engine's drop-in directly so
 * the tests can exercise wp_cache_* without booting a full WordPress install.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

/* -------------------------------------------------------------------------
 * Step 1. WordPress function stubs required by the drop-ins.
 * -------------------------------------------------------------------------
 *
 * Each stub matches the WordPress signature the caches actually call. They
 * intentionally do the minimum: a single-site environment where cache writes
 * are never suspended and admin notices are discarded.
 */

if (!function_exists('is_multisite')) {
	function is_multisite(): bool
	{
		return false;
	}
}

if (!function_exists('get_current_blog_id')) {
	function get_current_blog_id(): int
	{
		return 1;
	}
}

if (!function_exists('wp_suspend_cache_addition')) {
	function wp_suspend_cache_addition(?bool $suspend = null): bool
	{
		static $suspended = false;

		if (func_num_args() > 0) {
			$suspended = (bool) $suspend;
		}

		return $suspended;
	}
}

if (!function_exists('add_action')) {
	function add_action($tag, $callback, $priority = 10, $accepted_args = 1): true
	{
		return true;
	}
}

if (!function_exists('add_filter')) {
	function add_filter($tag, $callback, $priority = 10, $accepted_args = 1): true
	{
		return true;
	}
}

if (!function_exists('do_action')) {
	function do_action($tag, ...$args): void
	{
	}
}

if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value, ...$args)
	{
		return $value;
	}
}

if (!function_exists('esc_html')) {
	function esc_html(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('wp_admin_notice')) {
	function wp_admin_notice(string $message, array $args = []): void
	{
	}
}

if (!function_exists('_deprecated_function')) {
	function _deprecated_function(string $function, string $version, ?string $replacement = null): void
	{
		trigger_error(
			sprintf(
				'%1$s is deprecated since version %2$s%3$s',
				$function,
				$version,
				$replacement === null ? '' : (': ' . $replacement)
			),
			E_USER_DEPRECATED
		);
	}
}

if (!function_exists('wp_using_ext_object_cache')) {
	/**
	 * The drop-ins do not call this, but the test scaffolding uses it to
	 * confirm that an external cache is loaded. We flip it to true when a
	 * drop-in registers wp_cache_init().
	 */
	function wp_using_ext_object_cache(?bool $using = null): bool
	{
		static $using_ext = false;

		if (func_num_args() > 0) {
			$using_ext = (bool) $using;
		}

		return $using_ext;
	}
}

/* -------------------------------------------------------------------------
 * Step 2. Pick the cache engine to test.
 * -------------------------------------------------------------------------
 *
 * The engine is selected by the TW_CACHE_ENGINE environment variable (set in
 * phpunit.xml.dist or overridden from the shell). The matching drop-in from
 * the plugin directory is loaded directly so the tests can exercise the
 * wp_cache_* surface without booting a full WordPress install.
 */
$engine = strtolower(trim(getenv('TW_CACHE_ENGINE') ?: ($_SERVER['TW_CACHE_ENGINE'] ?? '')));

if (!in_array($engine, ['apcu', 'memcached', 'redis'], true)) {
	echo "Error: TW_CACHE_ENGINE must be one of: apcu, memcached, redis. Got: \"{$engine}\"." . PHP_EOL;
	exit(1);
}

define('TW_CACHE_ENGINE', $engine);

/* -------------------------------------------------------------------------
 * Step 3. Pin the cache key salt so the test namespace is predictable.
 * -------------------------------------------------------------------------
 */
if (!defined('WP_CACHE_KEY_SALT')) {
	$salt = $_SERVER['TW_CACHE_KEY_SALT'] ?? getenv('TW_CACHE_KEY_SALT') ?: 'twee_tests';
	define('WP_CACHE_KEY_SALT', $salt);
}

/*
 * Define ABSPATH so the drop-in's `if (!defined('ABSPATH')) exit;` guard
 * passes. The value itself is irrelevant: the drop-in never reads it.
 */
if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');

	// Several drop-ins reach for WP_CONTENT_DIR when present (e.g. to drop a
	// flush flag on reconnect). Default it to the repo root's parent which
	// mirrors what WordPress would normally provide.
	if (!defined('WP_CONTENT_DIR')) {
		define('WP_CONTENT_DIR', dirname(__DIR__, 2));
	}
}

/* -------------------------------------------------------------------------
 * Step 4. Load the drop-in. It defines the wp_cache_* functions and the
 * WP_Object_Cache class. Each drop-in early-returns if its PHP extension is
 * missing, in which case wp_cache_init() stays undefined and the test classes
 * skip themselves.
 * -------------------------------------------------------------------------
 */
$dropin = dirname(__DIR__) . '/plugin/' . $engine . '/object-cache.php';

require_once $dropin;

if (function_exists('wp_cache_init')) {
	wp_cache_init();
	wp_using_ext_object_cache(true);
}