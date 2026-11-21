<?php
/**
 * Plugin updates from the GitHub releases
 */

if (!defined('ABSPATH')) {
	exit;
}


/**
 * Get the latest release with a plugin ZIP attached, kept for 12 hours
 *
 * "Check again" on the Updates screen asks GitHub again.
 *
 * @return array The version, package, url and notes, or an empty array when there is no release to install
 */
function toc_update_release(): array
{
	$release = empty($_GET['force-check']) ? get_transient('toc_release') : false;

	if (is_array($release)) {
		return $release;
	}

	$response = wp_remote_get('https://api.github.com/repos/TwistedAndy/twee-cache/releases/latest', [
		'timeout' => 10,
		'headers' => ['Accept' => 'application/vnd.github.html+json'],
	]);

	$data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);

	// The source archives that GitHub adds hold the whole repository, so only an attached ZIP can be installed
	$zip = null;

	foreach ($data['assets'] ?? [] as $asset) {
		if (str_ends_with((string) ($asset['name'] ?? ''), '.zip')) {
			$zip = $asset;
			break;
		}
	}

	$release = (!empty($data['tag_name']) and $zip) ? [
		'version' => ltrim($data['tag_name'], 'vV'),
		'package' => $zip['browser_download_url'],
		'url'     => $data['html_url'] ?? '',
		'notes'   => $data['body_html'] ?? '',
	] : [];

	// A failed request is kept too, so a GitHub outage or rate limit does not slow every admin page
	set_transient('toc_release', $release, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);

	return $release;
}


/**
 * Offer the latest GitHub release as an update of this plugin
 *
 * WordPress compares the versions and shows the update only when the release is newer.
 *
 * @param array|false $update
 * @param array       $plugin_data
 * @param string      $plugin_file
 *
 * @return array|false
 */
function toc_update_check($update, array $plugin_data, string $plugin_file)
{
	// An update replaces the plugin folder, so a folder linked to a working copy is never updated
	if ($plugin_file !== plugin_basename(dirname(__DIR__) . '/twee-cache.php') or is_link(WP_PLUGIN_DIR . '/' . dirname($plugin_file))) {
		return $update;
	}

	$release = toc_update_release();

	if (!$release) {
		return $update;
	}

	return [
		'slug'    => dirname($plugin_file),
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	];
}


/**
 * Show the release notes in the "View version details" window
 *
 * @param false|object|array $result
 * @param string             $action
 * @param object             $args
 *
 * @return false|object|array
 */
function toc_update_details($result, string $action, $args)
{
	if ($action !== 'plugin_information' or ($args->slug ?? '') !== dirname(plugin_basename(dirname(__DIR__) . '/twee-cache.php'))) {
		return $result;
	}

	$release = toc_update_release();

	if (!$release) {
		return $result;
	}

	return (object) [
		'name'          => 'Twee Cache',
		'slug'          => $args->slug,
		'version'       => $release['version'],
		'homepage'      => $release['url'],
		'download_link' => $release['package'],
		'sections'      => ['changelog' => $release['notes']],
	];
}
