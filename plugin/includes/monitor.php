<?php
/**
 * Query Monitor panels for the Twee drop-ins
 *
 * The plugin loads this file only when Query Monitor is active and a Twee drop-in is installed.
 */

if (!defined('ABSPATH')) {
	exit;
}


/**
 * Collect the cache details and the backend calls at the end of the request
 */
class Twee_Cache_QM_Collector extends QM_Collector
{
	public $id = 'twee-cache';

	public function process()
	{
		global $wp_object_cache, $timestart;

		$hits = $wp_object_cache->cache_hits;
		$misses = $wp_object_cache->cache_misses;

		$this->data['info'] = $wp_object_cache->debug_info();
		$this->data['hits'] = $hits;
		$this->data['misses'] = $misses;
		$this->data['ratio'] = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 1) : 0;
		$this->data['sets'] = $wp_object_cache->cache_sets;
		$this->data['loads'] = $wp_object_cache->cache_loads;
		$this->data['debug'] = $wp_object_cache->debug;
		$this->data['commands'] = [];

		if (!$wp_object_cache->debug) {
			return;
		}

		// The caller of a command is the first frame outside the drop-in and the wp_cache_*() functions
		$args = [
			'ignore_class' => ['WP_Object_Cache' => true],
			'ignore_func'  => array_fill_keys([
				'wp_cache_get', 'wp_cache_get_multiple', 'wp_cache_set', 'wp_cache_set_multiple', 'wp_cache_add', 'wp_cache_add_multiple',
				'wp_cache_replace', 'wp_cache_delete', 'wp_cache_delete_multiple', 'wp_cache_incr', 'wp_cache_decr', 'wp_cache_flush',
				'wp_cache_flush_group', 'wp_cache_get_last_changed', 'wp_cache_set_last_changed',
			], true),
		];

		$commands = [];

		foreach ($wp_object_cache->commands as $command) {
			$commands[] = [
				'command'   => $command['command'],
				'target'    => $command['target'],
				'time'      => $command['time'],
				'backtrace' => new QM_Backtrace($args, $command['trace']),
			];
		}

		$time = array_sum(array_column($commands, 'time'));
		$request = (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? $timestart)) * 1000;

		$this->data['commands'] = $commands;
		$this->data['time'] = $time;
		$this->data['time_ratio'] = $request > 0 ? $time / $request * 100 : 0;
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

		echo '<section><h3>Backend</h3><p>';

		if ($data['debug']) {
			echo esc_html(number_format(count($data['commands']))) . ' calls<br>';
			echo esc_html(number_format($data['time'], 2)) . ' ms<br>';
			echo '<span class="qm-info">' . esc_html(number_format($data['time_ratio'], 1)) . '% of the request</span>';
		} else {
			echo 'Turn on Tracing on the <a href="' . esc_url(toc_settings_url()) . '">Cache Settings</a> page to log the backend calls, their time and their callers.';
		}

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


/**
 * The Commands panel under the Object Cache panel
 */
class Twee_Cache_QM_Commands_Output extends QM_Output_Html
{
	public function __construct(QM_Collector $collector)
	{
		parent::__construct($collector);

		add_filter('qm/output/panel_menus', [$this, 'panel_menu'], 30);
	}

	public function name()
	{
		return 'Commands';
	}

	public function panel_menu(array $menu)
	{
		if (isset($menu[$this->collector->id()])) {
			$menu[$this->collector->id()]['children'][] = $this->menu([
				'id'    => 'twee-cache-commands',
				'panel' => 'twee-cache-commands',
				'title' => 'Commands',
			]);
		}

		return $menu;
	}

	public function output()
	{
		$data = $this->collector->get_data();

		if (!$data['debug']) {
			$this->before_non_tabular_output('twee-cache-commands', 'Commands');
			echo $this->build_notice('Turn on Tracing on the <a href="' . esc_url(toc_settings_url()) . '">Cache Settings</a> page to log the backend calls of the object cache.');
			$this->after_non_tabular_output();

			return;
		}

		$commands = $data['commands'];
		$types = array_unique(array_column($commands, 'command'));
		$components = [];

		foreach ($commands as $command) {
			$components[] = $command['backtrace']->get_component()->name;
		}

		$components = array_unique($components);

		sort($types);
		sort($components);

		$this->before_tabular_output('twee-cache-commands', 'Commands');

		echo '<thead><tr>';
		echo '<th scope="col" class="qm-sorted-asc qm-sortable-column" role="columnheader" aria-sort="ascending">' . $this->build_sorter('#') . '</th>';
		echo '<th scope="col" class="qm-filterable-column">' . $this->build_filter('type', $types, 'Command') . '</th>';
		echo '<th scope="col">Caller</th>';
		echo '<th scope="col" class="qm-filterable-column">' . $this->build_filter('component', $components, 'Component') . '</th>';
		echo '<th scope="col" class="qm-num qm-sortable-column" role="columnheader" aria-sort="none">' . $this->build_sorter('Time (ms)') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($commands as $number => $command) {
			$component = $command['backtrace']->get_component()->name;
			$trace = $command['backtrace']->get_filtered_trace();

			echo '<tr data-qm-type="' . esc_attr($command['command']) . '" data-qm-component="' . esc_attr($component) . '" data-qm-time="' . esc_attr($command['time']) . '">';
			echo '<th scope="row" class="qm-row-num qm-num">' . ($number + 1) . '</th>';
			echo '<td class="qm-ltr qm-wrap"><code><b>' . esc_html($command['command']) . '</b> ' . esc_html($command['target']) . '</code></td>';
			echo '<td class="qm-row-caller qm-ltr qm-has-toggle qm-nowrap">' . self::build_toggler() . '<ol>';

			foreach ($trace as $index => $frame) {
				if ($index === 1) {
					echo '<div class="qm-toggled">';
				}

				echo '<li>' . self::output_filename($frame->id, $frame->file, (int) $frame->line) . '</li>';
			}

			if (count($trace) > 1) {
				echo '</div>';
			}

			echo '</ol></td>';
			echo '<td class="qm-nowrap">' . esc_html($component) . '</td>';
			echo '<td class="qm-num" data-qm-sort-weight="' . esc_attr($command['time']) . '">' . esc_html(number_format($command['time'], 3)) . '</td>';
			echo '</tr>';
		}

		echo '</tbody><tfoot><tr><td colspan="4">Total: <span class="qm-items-number">' . esc_html(number_format(count($commands))) . '</span></td>';
		echo '<td class="qm-num">' . esc_html(number_format(array_sum(array_column($commands, 'time')), 2)) . '</td></tr></tfoot>';

		$this->after_tabular_output();
	}
}
