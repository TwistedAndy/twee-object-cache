<?php
/**
 * Query Monitor panel for the Twee drop-ins
 *
 * The plugin loads this file only when Query Monitor is active and a Twee drop-in is installed.
 */

if (!defined('ABSPATH')) {
	exit;
}


/**
 * Collect the cache details at the end of the request
 */
class Twee_Cache_QM_Collector extends QM_Collector
{
	public $id = 'twee-cache';

	public function process()
	{
		global $wp_object_cache;

		$hits = $wp_object_cache->cache_hits;
		$misses = $wp_object_cache->cache_misses;

		$this->data['info'] = $wp_object_cache->debug_info();
		$this->data['hits'] = $hits;
		$this->data['misses'] = $misses;
		$this->data['ratio'] = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 1) : 0;
		$this->data['sets'] = $wp_object_cache->cache_sets;
		$this->data['loads'] = $wp_object_cache->cache_loads;
	}
}


/**
 * The Object Cache panel. It replaces the panel of Query Monitor.
 */
class Twee_Cache_QM_Output extends QM_Output_Html
{
	public function __construct(QM_Collector $collector)
	{
		parent::__construct($collector);

		add_filter('qm/output/menus', [$this, 'admin_menu'], 30);
	}

	public function name()
	{
		return 'Object Cache';
	}

	public function admin_menu(array $menu)
	{
		$data = $this->collector->get_data();
		$args = ['title' => $this->name()];

		if (!$data['info']['active']) {
			$args['meta']['classname'] = 'qm-alert';
		} elseif ($data['info']['errors']) {
			$args['meta']['classname'] = 'qm-warning';
		}

		$menu[$this->collector->id()] = $this->menu($args);

		return $menu;
	}

	public function output()
	{
		$data = $this->collector->get_data();
		$info = $data['info'];

		$this->before_non_tabular_output();

		// The grid of Query Monitor gives each box the same width, so a long text wraps and does not stretch its box
		echo '<div class="qm-grid">';

		echo '<section><h3>Status</h3><p>';
		echo esc_html($info['engine']) . '<br>';
		echo $info['active'] ? 'Connected' : '<span class="qm-warn">Not available. The request uses the runtime cache only.</span>';
		echo '</p></section>';

		echo '<section><h3>Runtime Cache</h3><p>';
		echo esc_html($data['ratio']) . '% hit rate<br>';
		echo esc_html(number_format($data['hits'])) . ' hits<br>';
		echo esc_html(number_format($data['misses'])) . ' misses<br>';
		echo esc_html(number_format($data['sets'])) . ' sets<br>';
		echo esc_html(number_format($data['loads'])) . ' backend reads';
		echo '</p></section>';

		echo '<section><h3>Details</h3><p>';

		foreach ($info['details'] as $label => $value) {
			echo esc_html($label) . ': <code>' . esc_html((string) $value) . '</code><br>';
		}

		echo '</p></section>';

		if ($info['errors']) {
			echo '<section><h3>Errors</h3><p class="qm-warn">';

			foreach ($info['errors'] as $error) {
				echo esc_html($error) . '<br>';
			}

			echo '</p></section>';
		}

		echo '</div></div><div class="qm-boxed">';

		echo '<section><h3>Groups in the Runtime Cache</h3><table><thead><tr><th scope="col">Group</th><th scope="col">Type</th><th scope="col" class="qm-num">Entries</th></tr></thead><tbody>';

		$groups = $info['groups'];
		ksort($groups);

		foreach ($groups as $group => $group_info) {
			echo '<tr><td class="qm-ltr"><code>' . esc_html((string) $group) . '</code></td><td>' . esc_html($group_info['type']) . '</td><td class="qm-num">' . esc_html(number_format($group_info['entries'])) . '</td></tr>';
		}

		echo '</tbody></table></section>';

		$this->after_non_tabular_output();
	}
}

