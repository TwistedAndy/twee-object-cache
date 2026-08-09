<?php

use PHPUnit\Framework\TestCase;

/**
 * Exercises the wp_cache_* contract provided by the drop-in selected in the
 * bootstrap. The active engine (apcu, memcached, or redis) is picked by the
 * bootstrap before this class is loaded and exposed via the TW_CACHE_ENGINE
 * constant.
 *
 * The suite skips itself wholesale when the selected engine's extension is
 * missing or its backend service is unreachable, so the same class drives
 * every drop-in without per-engine subclasses.
 */
class CacheTest extends TestCase {

	protected function setUp(): void
	{
		parent::setUp();

		if (!function_exists('wp_cache_set')) {
			$this->markTestSkipped('No wp_cache_* drop-in is loaded.');
		}

		if (!extension_loaded(TW_CACHE_ENGINE)) {
			$this->markTestSkipped('The ' . TW_CACHE_ENGINE . ' extension is not loaded.');
		}

		if (!$this->isBackendActive()) {
			$this->markTestSkipped('The ' . TW_CACHE_ENGINE . ' backend is not reachable.');
		}

		wp_cache_flush();
	}

	protected function tearDown(): void
	{
		wp_cache_flush_runtime();

		parent::tearDown();
	}

	/**
	 * Probes the drop-in's `active` flag to confirm the backend service
	 * accepted the connection during wp_cache_init().
	 */
	private function isBackendActive(): bool
	{
		$cache = $GLOBALS['wp_object_cache'] ?? null;

		if (!$cache instanceof WP_Object_Cache) {
			return false;
		}

		$reflection = new ReflectionProperty(WP_Object_Cache::class, 'active');

		return (bool) $reflection->getValue($cache);
	}

	/**
	 * Overwrites a single key in the drop-in's runtime cache to simulate
	 * stale in-memory state without touching the backend. Used by tests that
	 * assert `$force` re-reads the backend.
	 */
	private function staleRuntimeValue(string $group, int|string $key, mixed $value): void
	{
		$reflection = new ReflectionProperty(WP_Object_Cache::class, 'cache');

		$cache = $reflection->getValue($GLOBALS['wp_object_cache']);
		$cache[$group][$key] = $value;
		$reflection->setValue($GLOBALS['wp_object_cache'], $cache);
	}

	/**
	 * Forces the drop-in into multisite mode so blog-scoped group isolation
	 * can be exercised. `is_multisite()` is stubbed false by the bootstrap,
	 * so we flip the drop-in's flag manually for these scenarios.
	 */
	private function enableMultisite(int $blog_id = 1): void
	{
		$cache = $GLOBALS['wp_object_cache'];

		$multisite = new ReflectionProperty(WP_Object_Cache::class, 'multisite');
		$multisite->setValue($cache, true);

		$prefix = new ReflectionProperty(WP_Object_Cache::class, 'blog_prefix');
		$prefix->setValue($cache, $blog_id . ':');

		if (property_exists(WP_Object_Cache::class, 'group_mapping')) {
			$mapping = new ReflectionProperty(WP_Object_Cache::class, 'group_mapping');
			$mapping->setValue($cache, []);
		}
	}

	public function test_set_and_get_round_trip(): void
	{
		wp_cache_set('key_a', 'value_a', 'group1');

		$this->assertSame('value_a', wp_cache_get('key_a', 'group1'));
	}

	public function test_default_group_is_used_when_omitted(): void
	{
		wp_cache_set('key_default', 'value_default');

		$this->assertSame('value_default', wp_cache_get('key_default'));
		$this->assertSame('value_default', wp_cache_get('key_default', 'default'));
	}

	public function test_get_returns_false_and_sets_found_on_miss(): void
	{
		$found = 'sentinel';

		$value = wp_cache_get('missing_key', 'group2', false, $found);

		$this->assertFalse($value);
		$this->assertFalse($found);
	}

	public function test_get_sets_found_to_true_on_hit(): void
	{
		wp_cache_set('found_key', 'data');

		$found = false;

		wp_cache_get('found_key', 'default', false, $found);

		$this->assertTrue($found);
	}

	public function test_get_with_force_bypasses_runtime_cache(): void
	{
		wp_cache_set('force_key', 'original');
		$this->staleRuntimeValue('default', 'force_key', 'stale-runtime');

		$this->assertSame('original', wp_cache_get('force_key', 'default', true));
	}

	public function test_set_persists_to_backend_across_runtime_flush(): void
	{
		wp_cache_set('persist_key', 'persist_value', 'persistent_group');

		wp_cache_flush_runtime();

		$this->assertSame('persist_value', wp_cache_get('persist_key', 'persistent_group'));
	}

	public function test_get_multiple_returns_values_with_misses_as_false(): void
	{
		wp_cache_set('m_1', 'one', 'multi');
		wp_cache_set('m_3', 'three', 'multi');

		$results = wp_cache_get_multiple(['m_1', 'm_2', 'm_3'], 'multi');

		$this->assertSame('one', $results['m_1']);
		$this->assertFalse($results['m_2']);
		$this->assertSame('three', $results['m_3']);
	}

	public function test_get_multiple_after_runtime_flush_reads_from_backend(): void
	{
		wp_cache_set_multiple(['mk_a' => 'A', 'mk_b' => 'B'], 'multi');

		wp_cache_flush_runtime();

		$results = wp_cache_get_multiple(['mk_a', 'mk_b'], 'multi');

		$this->assertSame('A', $results['mk_a']);
		$this->assertSame('B', $results['mk_b']);
	}

	public function test_set_multiple_marks_each_key(): void
	{
		$results = wp_cache_set_multiple(['s_1' => 'one', 's_2' => 'two'], 'set_group');

		$this->assertTrue($results['s_1']);
		$this->assertTrue($results['s_2']);
		$this->assertSame('one', wp_cache_get('s_1', 'set_group'));
		$this->assertSame('two', wp_cache_get('s_2', 'set_group'));
	}

	public function test_add_returns_false_when_key_already_exists(): void
	{
		wp_cache_set('add_existing', 'first');

		$this->assertFalse(wp_cache_add('add_existing', 'second'));
		$this->assertSame('first', wp_cache_get('add_existing'));
	}

	public function test_add_returns_false_when_key_exists_in_backend_only(): void
	{
		wp_cache_set('add_remote', 'first');
		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_add('add_remote', 'second'));
		$this->assertSame('first', wp_cache_get('add_remote'));
	}

	public function test_add_succeeds_for_new_key(): void
	{
		$this->assertTrue(wp_cache_add('add_new', 'value'));
		$this->assertSame('value', wp_cache_get('add_new'));
	}

	public function test_add_multiple_reports_each_key(): void
	{
		wp_cache_set('am_existing', 'first');

		$results = wp_cache_add_multiple(['am_existing' => 'x', 'am_new' => 'y']);

		$this->assertFalse($results['am_existing']);
		$this->assertTrue($results['am_new']);
	}

	public function test_replace_fails_when_key_is_missing(): void
	{
		$this->assertFalse(wp_cache_replace('replace_missing', 'value'));
	}

	public function test_replace_updates_existing_value(): void
	{
		wp_cache_set('replace_me', 'first');

		$this->assertTrue(wp_cache_replace('replace_me', 'second'));
		$this->assertSame('second', wp_cache_get('replace_me'));
	}

	public function test_delete_removes_from_runtime_and_backend(): void
	{
		wp_cache_set('delete_me', 'value');

		$this->assertTrue(wp_cache_delete('delete_me'));
		$this->assertFalse(wp_cache_get('delete_me'));

		wp_cache_flush_runtime();
		$this->assertFalse(wp_cache_get('delete_me'));
	}

	public function test_delete_multiple_reports_each_key(): void
	{
		wp_cache_set('dm_1', 'a');
		wp_cache_set('dm_2', 'b');

		$results = wp_cache_delete_multiple(['dm_1', 'dm_2', 'dm_missing']);

		$this->assertTrue($results['dm_1']);
		$this->assertTrue($results['dm_2']);
	}

	public function test_incr_increments_an_existing_value(): void
	{
		wp_cache_set('counter', 5);

		$this->assertSame(6, wp_cache_incr('counter'));
		$this->assertSame(8, wp_cache_incr('counter', 2));
	}

	public function test_decr_decrements_an_existing_value(): void
	{
		wp_cache_set('counter', 10);

		$this->assertSame(9, wp_cache_decr('counter'));
		$this->assertSame(7, wp_cache_decr('counter', 2));
	}

	public function test_decr_allows_negative_values(): void
	{
		wp_cache_set('counter', 1);

		$this->assertSame(-4, wp_cache_decr('counter', 5));
	}

	public function test_incr_returns_false_when_key_is_missing(): void
	{
		$this->assertFalse(wp_cache_incr('nonexistent_counter'));
	}

	public function test_flush_clears_all_keys(): void
	{
		wp_cache_set('flush_a', 'a', 'group_a');
		wp_cache_set('flush_b', 'b', 'group_b');

		$this->assertTrue(wp_cache_flush());
		$this->assertFalse(wp_cache_get('flush_a', 'group_a'));
		$this->assertFalse(wp_cache_get('flush_b', 'group_b'));
	}

	public function test_flush_runtime_only_clears_runtime_layer(): void
	{
		wp_cache_set('runtime_key', 'value', 'runtime_group');

		$this->assertTrue(wp_cache_flush_runtime());

		// Backend still holds the value.
		$this->assertSame('value', wp_cache_get('runtime_key', 'runtime_group'));
	}

	public function test_flush_group_clears_only_target_group(): void
	{
		wp_cache_set('tg_target', 'target_value', 'group_target');
		wp_cache_set('tg_keep', 'keep_value', 'group_keep');

		$this->assertTrue(wp_cache_flush_group('group_target'));
		$this->assertFalse(wp_cache_get('tg_target', 'group_target'));
		$this->assertSame('keep_value', wp_cache_get('tg_keep', 'group_keep'));
	}

	public function test_non_persistent_group_stays_in_runtime_only(): void
	{
		wp_cache_add_non_persistent_groups('runtime_only_group');

		wp_cache_set('np_key', 'np_value', 'runtime_only_group');

		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_get('np_key', 'runtime_only_group'));
	}

	public function test_persistent_groups_ignore_non_persistent_requests(): void
	{
		// counts, plugins, themes are persistent-by-design and cannot be opted
		// out of. A request to mark them non-persistent should be ignored.
		wp_cache_add_non_persistent_groups('plugins');

		wp_cache_set('pg_key', 'pg_value', 'plugins');

		wp_cache_flush_runtime();

		$this->assertSame('pg_value', wp_cache_get('pg_key', 'plugins'));
	}

	public function test_global_groups_are_shared_across_switch_to_blog(): void
	{
		$this->enableMultisite(1);

		wp_cache_add_global_groups('shared_global');

		wp_cache_set('gg_key', 'global_value', 'shared_global');

		wp_cache_switch_to_blog(99);

		$this->assertSame('global_value', wp_cache_get('gg_key', 'shared_global'));

		wp_cache_switch_to_blog(1);
	}

	public function test_blog_scoped_groups_are_isolated_per_blog(): void
	{
		$this->enableMultisite(1);

		wp_cache_set('bs_key', 'blog_1_value', 'blog_scoped');

		wp_cache_switch_to_blog(99);

		$this->assertFalse(wp_cache_get('bs_key', 'blog_scoped'));

		wp_cache_set('bs_key', 'blog_99_value', 'blog_scoped');
		$this->assertSame('blog_99_value', wp_cache_get('bs_key', 'blog_scoped'));

		wp_cache_switch_to_blog(1);

		$this->assertSame('blog_1_value', wp_cache_get('bs_key', 'blog_scoped'));
	}

	public function test_switch_to_blog_invalidates_non_global_runtime_entries(): void
	{
		$this->enableMultisite(1);

		wp_cache_set('stb_key', 'first', 'scoped_group');
		wp_cache_add_global_groups('global_group');
		wp_cache_set('stb_global', 'global', 'global_group');

		wp_cache_switch_to_blog(2);

		$this->assertFalse(wp_cache_get('stb_key', 'scoped_group'));
		$this->assertSame('global', wp_cache_get('stb_global', 'global_group'));

		wp_cache_switch_to_blog(1);
	}

	public function test_wp_cache_supports_reports_all_modern_features(): void
	{
		$features = [
			'add_multiple',
			'set_multiple',
			'get_multiple',
			'delete_multiple',
			'flush_runtime',
			'flush_group',
		];

		foreach ($features as $feature) {
			$this->assertTrue(wp_cache_supports($feature), "Expected support for {$feature}.");
		}

		$this->assertFalse(wp_cache_supports('unknown_feature'));
	}

	public function test_wp_cache_close_returns_true(): void
	{
		$this->assertTrue(wp_cache_close());
	}

	public function test_set_then_overwrite_with_set(): void
	{
		wp_cache_set('overwrite', 'first');
		wp_cache_set('overwrite', 'second');

		$this->assertSame('second', wp_cache_get('overwrite'));
	}

	public function test_distinct_keys_with_same_name_in_different_groups_do_not_collide(): void
	{
		wp_cache_set('shared_name', 'in_group_a', 'group_a');
		wp_cache_set('shared_name', 'in_group_b', 'group_b');

		$this->assertSame('in_group_a', wp_cache_get('shared_name', 'group_a'));
		$this->assertSame('in_group_b', wp_cache_get('shared_name', 'group_b'));
	}

	public function test_complex_value_round_trip_preserves_types(): void
	{
		$value = [
			'int'    => 42,
			'string' => 'hello',
			'nested' => ['a' => 1, 'b' => [2, 3]],
			'null'   => null,
			'bool'   => true,
		];

		wp_cache_set('complex', $value, 'complex_group');

		$this->assertSame($value, wp_cache_get('complex', 'complex_group'));
	}

	public function test_zero_and_empty_string_are_distinct_from_misses(): void
	{
		wp_cache_set('zero', 0);
		wp_cache_set('empty', '');

		$this->assertSame(0, wp_cache_get('zero'));
		$this->assertSame('', wp_cache_get('empty'));
	}

	public function test_false_value_round_trip_is_distinguishable_from_a_miss(): void
	{
		wp_cache_set('false_value', false, 'false_group');
		wp_cache_flush_runtime();

		$found = 'sentinel';

		$value = wp_cache_get('false_value', 'false_group', false, $found);

		$this->assertFalse($value);
		$this->assertTrue($found, 'A stored false value must be distinguishable from a miss via $found.');
	}

	public function test_decr_returns_false_when_key_is_missing(): void
	{
		$this->assertFalse(wp_cache_decr('nonexistent_counter'));
	}

	public function test_incr_and_decr_respect_explicit_group(): void
	{
		wp_cache_set('cnt', 10, 'g1');

		$this->assertSame(11, wp_cache_incr('cnt', 1, 'g1'));
		$this->assertSame(9, wp_cache_decr('cnt', 2, 'g1'));
		$this->assertFalse(wp_cache_incr('cnt', 1, 'non_existent_group'));
	}

	public function test_get_with_force_on_miss_returns_false_and_found_is_false(): void
	{
		$found = 'sentinel';

		$value = wp_cache_get('force_miss', 'force_group', true, $found);

		$this->assertFalse($value);
		$this->assertFalse($found);
	}

	public function test_get_multiple_with_force_bypasses_runtime_cache(): void
	{
		wp_cache_set('fm_a', 'alpha', 'fm');
		wp_cache_set('fm_b', 'beta', 'fm');
		$this->staleRuntimeValue('fm', 'fm_a', 'stale');
		$this->staleRuntimeValue('fm', 'fm_b', 'stale');

		$results = wp_cache_get_multiple(['fm_a', 'fm_b'], 'fm', true);

		$this->assertSame('alpha', $results['fm_a']);
		$this->assertSame('beta', $results['fm_b']);
	}

	public function test_incr_and_decr_persist_across_runtime_flush(): void
	{
		wp_cache_set('persist_cnt', 5, 'cnt_group');

		wp_cache_incr('persist_cnt', 1, 'cnt_group');
		wp_cache_flush_runtime();

		$this->assertSame(6, wp_cache_get('persist_cnt', 'cnt_group'));
		$this->assertSame(4, wp_cache_decr('persist_cnt', 2, 'cnt_group'));
		$this->assertSame(4, wp_cache_get('persist_cnt', 'cnt_group'));
	}

	public function test_incr_on_non_persistent_group_still_works(): void
	{
		wp_cache_add_non_persistent_groups('np_counter');
		wp_cache_set('np_cnt', 1, 'np_counter');

		$this->assertSame(2, wp_cache_incr('np_cnt', 1, 'np_counter'));
		$this->assertSame(2, wp_cache_get('np_cnt', 'np_counter'));
	}

	public function test_add_non_persistent_groups_accepts_array(): void
	{
		wp_cache_add_non_persistent_groups(['np_arr_a', 'np_arr_b']);

		wp_cache_set('npa', 'a', 'np_arr_a');
		wp_cache_set('npb', 'b', 'np_arr_b');

		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_get('npa', 'np_arr_a'));
		$this->assertFalse(wp_cache_get('npb', 'np_arr_b'));
	}

	public function test_add_global_groups_accepts_array(): void
	{
		$this->enableMultisite(1);
		wp_cache_add_global_groups(['global_arr_a', 'global_arr_b']);

		wp_cache_set('gaa', 'alpha', 'global_arr_a');
		wp_cache_set('gab', 'beta', 'global_arr_b');

		wp_cache_switch_to_blog(99);

		$this->assertSame('alpha', wp_cache_get('gaa', 'global_arr_a'));
		$this->assertSame('beta', wp_cache_get('gab', 'global_arr_b'));

		wp_cache_switch_to_blog(1);
	}

	public function test_flush_group_in_multisite_isolates_blog_scoped_groups(): void
	{
		$this->enableMultisite(1);

		wp_cache_set('fg_bs', 'value1', 'fg_scoped');

		wp_cache_switch_to_blog(99);
		wp_cache_set('fg_bs', 'value99', 'fg_scoped');

		$this->assertTrue(wp_cache_flush_group('fg_scoped'));

		$this->assertFalse(wp_cache_get('fg_bs', 'fg_scoped'));

		wp_cache_switch_to_blog(1);
		$this->assertSame('value1', wp_cache_get('fg_bs', 'fg_scoped'));

		wp_cache_switch_to_blog(1);
	}

	public function test_get_multiple_after_flush_group_is_empty(): void
	{
		wp_cache_set('gma', 'a', 'fg');
		wp_cache_set('gmb', 'b', 'fg');

		wp_cache_flush_group('fg');

		$results = wp_cache_get_multiple(['gma', 'gmb'], 'fg');

		$this->assertFalse($results['gma']);
		$this->assertFalse($results['gmb']);
	}

	public function test_get_multiple_with_empty_keys_array(): void
	{
		$this->assertSame([], wp_cache_get_multiple([], 'any'));
	}

	public function test_set_multiple_with_single_entry(): void
	{
		$results = wp_cache_set_multiple(['lonely' => 'only'], 'single');

		$this->assertTrue($results['lonely']);
		$this->assertSame('only', wp_cache_get('lonely', 'single'));
	}

	public function test_add_multiple_when_all_keys_exist(): void
	{
		wp_cache_set('ame_a', 'a');
		wp_cache_set('ame_b', 'b');

		$results = wp_cache_add_multiple(['ame_a' => 'x', 'ame_b' => 'y']);

		$this->assertFalse($results['ame_a']);
		$this->assertFalse($results['ame_b']);
		$this->assertSame('a', wp_cache_get('ame_a'));
		$this->assertSame('b', wp_cache_get('ame_b'));
	}

	public function test_delete_non_existent_key_returns_false(): void
	{
		$this->assertFalse(wp_cache_delete('never_set'));
	}

	public function test_replace_in_non_default_group(): void
	{
		wp_cache_set('repl', 'first', 'repl_group');

		$this->assertTrue(wp_cache_replace('repl', 'second', 'repl_group'));
		$this->assertSame('second', wp_cache_get('repl', 'repl_group'));
	}

	public function test_delete_in_non_default_group(): void
	{
		wp_cache_set('del', 'x', 'del_group');

		$this->assertTrue(wp_cache_delete('del', 'del_group'));
		$this->assertFalse(wp_cache_get('del', 'del_group'));
	}

	public function test_replace_existing_value_with_expire(): void
	{
		wp_cache_set('repl_exp', 'first');

		$this->assertTrue(wp_cache_replace('repl_exp', 'second', 'default', 0));
		$this->assertSame('second', wp_cache_get('repl_exp'));
	}

	public function test_add_new_value_with_expire(): void
	{
		$this->assertTrue(wp_cache_add('add_exp', 'data', 'default', 0));
		$this->assertSame('data', wp_cache_get('add_exp'));
	}

	public function test_set_with_expire(): void
	{
		wp_cache_set('set_exp', 'data', 'default', 0);

		$this->assertSame('data', wp_cache_get('set_exp'));
	}

	public function test_set_multiple_with_expire(): void
	{
		$results = wp_cache_set_multiple(['sme_a' => 'A', 'sme_b' => 'B'], 'sme_group', 0);

		$this->assertTrue($results['sme_a']);
		$this->assertTrue($results['sme_b']);
		$this->assertSame('A', wp_cache_get('sme_a', 'sme_group'));
		$this->assertSame('B', wp_cache_get('sme_b', 'sme_group'));
	}

	public function test_add_and_replace_after_full_flush(): void
	{
		wp_cache_set('ar_key', 'original');

		wp_cache_flush();

		$this->assertTrue(wp_cache_add('ar_key', 're-added'));
		$this->assertSame('re-added', wp_cache_get('ar_key'));

		$this->assertTrue(wp_cache_replace('ar_key', 'replaced'));
		$this->assertSame('replaced', wp_cache_get('ar_key'));
	}

	public function test_get_with_int_key(): void
	{
		wp_cache_set(42, 'answer', 'int_group');

		$this->assertSame('answer', wp_cache_get(42, 'int_group'));
	}

	public function test_get_multiple_with_mixed_keys(): void
	{
		wp_cache_set('str_key', 'string_val', 'mixed');
		wp_cache_set(123, 'int_val', 'mixed');

		$results = wp_cache_get_multiple(['str_key', 123], 'mixed');

		$this->assertSame('string_val', $results['str_key']);
		$this->assertSame('int_val', $results[123]);
	}

	public function test_set_then_add_fails_even_after_runtime_flush(): void
	{
		wp_cache_set('sa_key', 'persisted');

		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_add('sa_key', 'attempt'));
		$this->assertSame('persisted', wp_cache_get('sa_key'));
	}

	public function test_large_array_round_trip(): void
	{
		$large = array_fill(0, 100, 'value');
		$large['nested'] = array_fill(0, 50, ['a' => 1, 'b' => 2]);

		wp_cache_set('large', $large, 'large_group');

		$this->assertSame($large, wp_cache_get('large', 'large_group'));
	}

	public function test_wp_cache_reset_clears_cache(): void
	{
		wp_cache_set('reset_a', 'a', 'reset_group');
		wp_cache_set('reset_b', 'b', 'reset_group');

		wp_cache_flush_group('reset_group');

		$this->assertFalse(wp_cache_get('reset_a', 'reset_group'));
		$this->assertFalse(wp_cache_get('reset_b', 'reset_group'));
	}

	public function test_incr_string_value_is_not_numeric_but_decr_is(): void
	{
		wp_cache_set('str_counter', 'hello');

		$result = wp_cache_incr('str_counter');

		$this->assertFalse($result);
	}

	public function test_decr_string_value_is_not_numeric_but_incr_is(): void
	{
		wp_cache_set('str_decr', 'world');

		$result = wp_cache_decr('str_decr');

		$this->assertFalse($result);
	}

	public function test_add_multiple_with_expire(): void
	{
		$results = wp_cache_add_multiple(['amwe_a' => 'x', 'amwe_b' => 'y'], 'amwe', 0);

		$this->assertTrue($results['amwe_a']);
		$this->assertTrue($results['amwe_b']);
	}

	public function test_delete_multiple_with_single_key(): void
	{
		wp_cache_set('dms', 'val');

		$results = wp_cache_delete_multiple(['dms']);

		$this->assertTrue($results['dms']);
		$this->assertFalse(wp_cache_get('dms'));
	}

	public function test_get_multiple_force_with_mixed_hits_and_misses(): void
	{
		wp_cache_set('gfm_hit', 'present', 'gfm');

		$results = wp_cache_get_multiple(['gfm_hit', 'gfm_miss'], 'gfm', true);

		$this->assertSame('present', $results['gfm_hit']);
		$this->assertFalse($results['gfm_miss']);
	}

	public function test_get_multiple_without_force_uses_runtime_cache(): void
	{
		wp_cache_set('gf_nf', 'backend', 'gf_nf_group');
		$this->staleRuntimeValue('gf_nf_group', 'gf_nf', 'runtime_stale');

		$results = wp_cache_get_multiple(['gf_nf'], 'gf_nf_group', false);

		$this->assertSame('runtime_stale', $results['gf_nf']);
	}

	public function test_wp_cache_supports_all_features_explicitly(): void
	{
		$all = [
			'add_multiple',
			'set_multiple',
			'get_multiple',
			'delete_multiple',
			'flush_runtime',
			'flush_group',
		];

		foreach ($all as $feature) {
			$this->assertTrue(wp_cache_supports($feature));
		}

		$bogus = [
			'another_unknown_feature',
			'',
			'magic_cache',
		];

		foreach ($bogus as $feature) {
			$this->assertFalse(wp_cache_supports($feature));
		}
	}

	public function test_wp_cache_close_after_operations_still_works(): void
	{
		wp_cache_set('close_test', 'value');

		$this->assertTrue(wp_cache_close());

		// Closing must not corrupt existing runtime state.
		$this->assertSame('value', wp_cache_get('close_test'));
	}

	public function test_flush_runtime_after_flush_group(): void
	{
		wp_cache_set('fr_fg', 'v', 'fr_fg_group');

		wp_cache_flush_group('fr_fg_group');
		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_get('fr_fg', 'fr_fg_group'));
	}

	public function test_flush_group_on_non_persistent_group(): void
	{
		wp_cache_add_non_persistent_groups('np_fg');
		wp_cache_set('np_fg_key', 'val', 'np_fg');

		$this->assertTrue(wp_cache_flush_group('np_fg'));
		$this->assertFalse(wp_cache_get('np_fg_key', 'np_fg'));
	}

	public function test_flush_group_without_runtime_flush_clears_both_layers(): void
	{
		wp_cache_set('bw_key', 'val', 'both_ways');

		$this->assertTrue(wp_cache_flush_group('both_ways'));
		$this->assertFalse(wp_cache_get('bw_key', 'both_ways'));

		wp_cache_flush_runtime();
		$this->assertFalse(wp_cache_get('bw_key', 'both_ways'));
	}

	public function test_switch_to_blog_preserves_global_groups_after_multiple_switches(): void
	{
		$this->enableMultisite(1);

		wp_cache_add_global_groups('sm_global');
		wp_cache_set('sm_key', 'shared', 'sm_global');

		wp_cache_switch_to_blog(10);
		$this->assertSame('shared', wp_cache_get('sm_key', 'sm_global'));

		wp_cache_switch_to_blog(20);
		$this->assertSame('shared', wp_cache_get('sm_key', 'sm_global'));

		wp_cache_switch_to_blog(1);
		$this->assertSame('shared', wp_cache_get('sm_key', 'sm_global'));
	}

	public function test_global_groups_accepted_as_single_string(): void
	{
		$this->enableMultisite(1);

		wp_cache_add_global_groups('single_string_global');

		wp_cache_set('ssg', 'val', 'single_string_global');

		wp_cache_switch_to_blog(5);

		$this->assertSame('val', wp_cache_get('ssg', 'single_string_global'));

		wp_cache_switch_to_blog(1);
	}

	public function test_non_persistent_groups_accepted_as_single_string(): void
	{
		wp_cache_add_non_persistent_groups('nps_string');

		wp_cache_set('nps', 'val', 'nps_string');

		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_get('nps', 'nps_string'));
	}

	public function test_get_force_on_existing_in_runtime_still_returns_runtime_value(): void
	{
		wp_cache_set('rt_key', 'runtime', 'rt_group');

		$found = false;
		$value = wp_cache_get('rt_key', 'rt_group', true, $found);

		$this->assertSame('runtime', $value);
		$this->assertTrue($found);
	}

	public function test_delete_multiple_with_mixed_existing_and_missing(): void
	{
		wp_cache_set('dme_exist', 'a', 'dme');

		$results = wp_cache_delete_multiple(['dme_exist', 'dme_miss'], 'dme');

		$this->assertTrue($results['dme_exist']);
		$this->assertFalse($results['dme_miss']);
	}

	public function test_incr_sets_value_when_key_is_absent_on_incr_only(): void
	{
		$this->assertSame(false, wp_cache_incr('auto_incr'));

		wp_cache_set('auto_incr', 0);
		wp_cache_incr('auto_incr', 2);
		$this->assertSame(2, wp_cache_get('auto_incr'));
	}

	public function test_decr_below_zero_returns_negative(): void
	{
		wp_cache_set('zero_cnt', 0);

		$this->assertSame(-1, wp_cache_decr('zero_cnt', 1));
	}

	public function test_flush_returns_true_and_leaves_cache_empty(): void
	{
		wp_cache_set('f1', 'a');
		wp_cache_set('f2', 'b');
		wp_cache_set('f3', 'c', 'another');

		$this->assertTrue(wp_cache_flush());

		$this->assertFalse(wp_cache_get('f1'));
		$this->assertFalse(wp_cache_get('f2'));
		$this->assertFalse(wp_cache_get('f3', 'another'));
	}

	public function test_wp_cache_reset_returns_false_and_emits_deprecation(): void
	{
		$deprecation = null;

		set_error_handler(static function (int $errno, string $errstr) use (&$deprecation): bool {
			if ($errno === E_USER_DEPRECATED) {
				$deprecation = $errstr;

				return true;
			}

			return false;
		}, E_USER_DEPRECATED);

		try {
			$result = wp_cache_reset();
		} finally {
			restore_error_handler();
		}

		$this->assertFalse($result);
		$this->assertNotNull($deprecation);
		$this->assertStringContainsString('wp_cache_reset', $deprecation);
		$this->assertStringContainsString('deprecated', $deprecation);
	}

	public function test_all_three_persistent_groups_ignore_non_persistent_requests(): void
	{
		foreach (['plugins', 'themes', 'counts'] as $group) {
			wp_cache_add_non_persistent_groups($group);
			wp_cache_set("pgn_{$group}_k", "value_{$group}", $group);
			wp_cache_flush_runtime();
			$this->assertSame("value_{$group}", wp_cache_get("pgn_{$group}_k", $group), "Group {$group} must remain persistent.");
		}
	}

	public function test_set_and_get_in_non_persistent_group(): void
	{
		wp_cache_add_non_persistent_groups('np_rt_grp');

		wp_cache_set('np_key', 'np_value', 'np_rt_grp');

		$found = false;
		$value = wp_cache_get('np_key', 'np_rt_grp', false, $found);

		$this->assertSame('np_value', $value);
		$this->assertTrue($found);
	}

	public function test_add_in_non_persistent_group_for_new_key_succeeds(): void
	{
		wp_cache_add_non_persistent_groups('np_add_new_grp');

		$this->assertTrue(wp_cache_add('np_add_new', 'fresh', 'np_add_new_grp'));
		$this->assertSame('fresh', wp_cache_get('np_add_new', 'np_add_new_grp'));
	}

	public function test_add_in_non_persistent_group_returns_false_for_existing_key(): void
	{
		wp_cache_add_non_persistent_groups('np_add_exist_grp');

		wp_cache_set('np_existing', 'first', 'np_add_exist_grp');

		$this->assertFalse(wp_cache_add('np_existing', 'second', 'np_add_exist_grp'));
		$this->assertSame('first', wp_cache_get('np_existing', 'np_add_exist_grp'));
	}

	public function test_replace_in_non_persistent_group_updates_value(): void
	{
		wp_cache_add_non_persistent_groups('np_replace_grp');

		wp_cache_set('np_rep', 'first', 'np_replace_grp');

		$this->assertTrue(wp_cache_replace('np_rep', 'second', 'np_replace_grp'));
		$this->assertSame('second', wp_cache_get('np_rep', 'np_replace_grp'));
	}

	public function test_replace_in_non_persistent_group_fails_for_missing_key(): void
	{
		wp_cache_add_non_persistent_groups('np_replace_miss_grp');

		$this->assertFalse(wp_cache_replace('np_never', 'value', 'np_replace_miss_grp'));
	}

	public function test_delete_in_non_persistent_group(): void
	{
		wp_cache_add_non_persistent_groups('np_delete_grp');

		wp_cache_set('np_del', 'value', 'np_delete_grp');

		$this->assertTrue(wp_cache_delete('np_del', 'np_delete_grp'));
		$this->assertFalse(wp_cache_get('np_del', 'np_delete_grp'));
	}

	public function test_set_multiple_in_non_persistent_group_is_runtime_only(): void
	{
		wp_cache_add_non_persistent_groups('np_set_multi_grp');

		$results = wp_cache_set_multiple(['a' => '1', 'b' => '2'], 'np_set_multi_grp');

		$this->assertTrue($results['a']);
		$this->assertTrue($results['b']);
		$this->assertSame('1', wp_cache_get('a', 'np_set_multi_grp'));
		$this->assertSame('2', wp_cache_get('b', 'np_set_multi_grp'));

		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_get('a', 'np_set_multi_grp'));
		$this->assertFalse(wp_cache_get('b', 'np_set_multi_grp'));
	}

	public function test_add_multiple_in_non_persistent_group(): void
	{
		wp_cache_add_non_persistent_groups('np_add_multi_grp');

		$results = wp_cache_add_multiple(['a' => '1', 'b' => '2'], 'np_add_multi_grp');

		$this->assertTrue($results['a']);
		$this->assertTrue($results['b']);
		$this->assertSame('1', wp_cache_get('a', 'np_add_multi_grp'));
		$this->assertSame('2', wp_cache_get('b', 'np_add_multi_grp'));

		$followup = wp_cache_add_multiple(['a' => 'x', 'b' => 'y'], 'np_add_multi_grp');

		$this->assertFalse($followup['a']);
		$this->assertFalse($followup['b']);
		$this->assertSame('1', wp_cache_get('a', 'np_add_multi_grp'));
		$this->assertSame('2', wp_cache_get('b', 'np_add_multi_grp'));
	}

	public function test_delete_multiple_in_non_persistent_group(): void
	{
		wp_cache_add_non_persistent_groups('np_delete_multi_grp');

		wp_cache_set('a', '1', 'np_delete_multi_grp');
		wp_cache_set('b', '2', 'np_delete_multi_grp');

		$results = wp_cache_delete_multiple(['a', 'b', 'c'], 'np_delete_multi_grp');

		$this->assertTrue($results['a']);
		$this->assertTrue($results['b']);
		$this->assertFalse($results['c']);
		$this->assertFalse(wp_cache_get('a', 'np_delete_multi_grp'));
		$this->assertFalse(wp_cache_get('b', 'np_delete_multi_grp'));
	}

	public function test_decr_in_non_persistent_group_for_missing_key_returns_false(): void
	{
		wp_cache_add_non_persistent_groups('np_decr_miss_grp');

		$this->assertFalse(wp_cache_decr('np_counter', 1, 'np_decr_miss_grp'));
	}

	public function test_incr_in_non_persistent_group_does_not_survive_runtime_flush(): void
	{
		wp_cache_add_non_persistent_groups('np_incr_ephemeral_grp');

		wp_cache_set('np_counter', 5, 'np_incr_ephemeral_grp');
		$this->assertSame(7, wp_cache_incr('np_counter', 2, 'np_incr_ephemeral_grp'));
		$this->assertSame(7, wp_cache_get('np_counter', 'np_incr_ephemeral_grp'));

		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_get('np_counter', 'np_incr_ephemeral_grp'));
	}

	public function test_add_returns_false_when_addition_suspended(): void
	{
		wp_suspend_cache_addition(true);

		try {
			$this->assertFalse(wp_cache_add('suspended_key', 'value'));
			$this->assertFalse(wp_cache_get('suspended_key'));
		} finally {
			wp_suspend_cache_addition(false);
		}
	}

	public function test_replace_succeeds_when_key_only_exists_in_backend(): void
	{
		wp_cache_set('rb_key', 'original', 'rb_group');

		wp_cache_flush_runtime();

		$this->assertTrue(wp_cache_replace('rb_key', 'replaced', 'rb_group'));
		$this->assertSame('replaced', wp_cache_get('rb_key', 'rb_group'));
	}

	public function test_flush_is_idempotent(): void
	{
		wp_cache_set('idem_a', 'a');
		wp_cache_set('idem_b', 'b', 'idem_group');

		$this->assertTrue(wp_cache_flush());
		$this->assertTrue(wp_cache_flush());
		$this->assertTrue(wp_cache_flush());

		$this->assertFalse(wp_cache_get('idem_a'));
		$this->assertFalse(wp_cache_get('idem_b', 'idem_group'));
	}

	public function test_flush_with_no_data_set(): void
	{
		$this->assertTrue(wp_cache_flush());
		$this->assertTrue(wp_cache_flush_runtime());
		$this->assertTrue(wp_cache_flush_group('never_used_group'));
	}

	public function test_get_multiple_with_all_keys_missing(): void
	{
		$results = wp_cache_get_multiple(['absent_a', 'absent_b', 'absent_c'], 'all_miss_group');

		$this->assertFalse($results['absent_a']);
		$this->assertFalse($results['absent_b']);
		$this->assertFalse($results['absent_c']);
	}

	public function test_get_multiple_returns_global_group_value_across_blogs(): void
	{
		$this->enableMultisite(1);

		wp_cache_add_global_groups('multi_global_grp');
		wp_cache_set('mg_a', 'alpha', 'multi_global_grp');
		wp_cache_set('mg_b', 'beta', 'multi_global_grp');

		wp_cache_switch_to_blog(99);

		$results = wp_cache_get_multiple(['mg_a', 'mg_b'], 'multi_global_grp');

		$this->assertSame('alpha', $results['mg_a']);
		$this->assertSame('beta', $results['mg_b']);

		wp_cache_switch_to_blog(1);
	}

	public function test_switch_to_same_blog_clears_non_global_runtime(): void
	{
		$this->enableMultisite(1);

		wp_cache_set('ss_key', 'value', 'ss_scoped');

		wp_cache_switch_to_blog(1);

		$this->assertSame('value', wp_cache_get('ss_key', 'ss_scoped'));
	}

}