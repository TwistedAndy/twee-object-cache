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

	public function test_oversized_string_round_trip(): void
	{
		$large = base64_encode(random_bytes(8 * 1024 * 1024));

		$this->assertTrue(wp_cache_set('large_string', $large, 'large_group'));
		wp_cache_flush_runtime();

		$this->assertSame($large, wp_cache_get('large_string', 'large_group'));
	}

	public function test_oversized_add_round_trip(): void
	{
		$large = base64_encode(random_bytes(8 * 1024 * 1024));

		$this->assertTrue(wp_cache_add('large_add', $large, 'large_add_group'));
		wp_cache_flush_runtime();

		$this->assertSame($large, wp_cache_get('large_add', 'large_add_group'));
	}

	public function test_oversized_set_multiple_round_trip(): void
	{
		$large = base64_encode(random_bytes(8 * 1024 * 1024));

		$results = wp_cache_set_multiple(
			['bulk_large' => $large, 'bulk_small' => 'small'],
			'bulk_chunk_group'
		);

		$this->assertTrue($results['bulk_large']);
		$this->assertTrue($results['bulk_small']);

		wp_cache_flush_runtime();

		$values = wp_cache_get_multiple(['bulk_large', 'bulk_small'], 'bulk_chunk_group');
		$this->assertSame($large, $values['bulk_large']);
		$this->assertSame('small', $values['bulk_small']);
	}

	public function test_oversized_replace_round_trip(): void
	{
		$large = base64_encode(random_bytes(8 * 1024 * 1024));

		wp_cache_set('large_replace', 'seed', 'large_replace_group');
		wp_cache_flush_runtime();

		$this->assertTrue(wp_cache_replace('large_replace', $large, 'large_replace_group'));
		wp_cache_flush_runtime();

		$this->assertSame($large, wp_cache_get('large_replace', 'large_replace_group'));
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

	public function test_set_with_oversized_user_key_round_trips(): void
	{
		$key = str_repeat('k', 300);

		wp_cache_set($key, 'value', 'oversized');

		$this->assertSame('value', wp_cache_get($key, 'oversized'));
	}

	public function test_set_with_oversized_user_key_does_not_break_subsequent_operations(): void
	{
		$key = str_repeat('k', 300);

		wp_cache_set($key, 'value', 'oversized');
		wp_cache_set('normal_key', 'normal_value', 'oversized');

		$this->assertSame('value', wp_cache_get($key, 'oversized'));
		$this->assertSame('normal_value', wp_cache_get('normal_key', 'oversized'));
	}

	public function test_two_different_oversized_keys_with_same_prefix_are_distinct(): void
	{
		$key_a = str_repeat('a', 300);
		$key_b = str_repeat('a', 299) . 'b';

		wp_cache_set($key_a, 'A', 'oversized');
		wp_cache_set($key_b, 'B', 'oversized');

		$this->assertSame('A', wp_cache_get($key_a, 'oversized'));
		$this->assertSame('B', wp_cache_get($key_b, 'oversized'));
	}

	public function test_set_with_oversized_group_name_round_trips(): void
	{
		$group = str_repeat('g', 240);

		wp_cache_set('key', 'value', $group);

		$this->assertSame('value', wp_cache_get('key', $group));
	}

	public function test_flush_group_with_oversized_group_name_clears_keys(): void
	{
		$group = str_repeat('g', 240);

		wp_cache_set('flush_long_a', 'A', $group);
		wp_cache_set('flush_long_b', 'B', $group);
		wp_cache_flush_runtime();

		wp_cache_flush_group($group);
		wp_cache_flush_runtime();

		$this->assertFalse(wp_cache_get('flush_long_a', $group));
		$this->assertFalse(wp_cache_get('flush_long_b', $group));
	}

	/**
	 * Reads one of the drop-in's public counter properties (cache_hits,
	 * cache_misses, cache_loads, cache_sets). These counters are part of
	 * the engine contract the suite asserts against.
	 */
	private function cacheCounter(string $name): int
	{
		return (int) $GLOBALS['wp_object_cache']->{$name};
	}

	/**
	 * Resets the hit/miss/load counters without touching stored values so a
	 * test can scope its assertions to a known baseline.
	 */
	private function resetCacheCounters(): void
	{
		$cache = $GLOBALS['wp_object_cache'];

		$cache->cache_hits = 0;
		$cache->cache_misses = 0;
		$cache->cache_loads = 0;
		$cache->cache_sets = 0;
	}

	public function test_miss(): void
	{
		$this->resetCacheCounters();

		$this->assertFalse(wp_cache_get('miss_key'));

		$this->assertSame(0, $this->cacheCounter('cache_hits'));
		$this->assertSame(1, $this->cacheCounter('cache_misses'));
		$this->assertSame(1, $this->cacheCounter('cache_loads'));
	}

	public function test_add_get(): void
	{
		wp_cache_add('add_get_key', 'value');

		$this->resetCacheCounters();

		$this->assertSame('value', wp_cache_get('add_get_key'));

		$this->assertSame(1, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_add_get_0(): void
	{
		wp_cache_add('add_zero_key', 0);

		$this->resetCacheCounters();

		$this->assertSame(0, wp_cache_get('add_zero_key'));

		$this->assertSame(1, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_add_get_null(): void
	{
		$this->assertTrue(wp_cache_add('add_null_key', null));

		$this->resetCacheCounters();

		$this->assertNull(wp_cache_get('add_null_key'));

		$this->assertSame(1, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_get_already_exists_internal(): void
	{
		wp_cache_set('internal_key', 'alpha');

		// Drop the runtime entry so the next get() is forced to consult the
		// backend. We then expect a single hit and exactly one load.
		wp_cache_flush_runtime();

		$this->resetCacheCounters();

		$this->assertSame('alpha', wp_cache_get('internal_key'));

		$this->assertSame(1, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
		$this->assertSame(1, $this->cacheCounter('cache_loads'));

		// A second get() must be served entirely from the runtime layer.
		$loadsAfterFirst = $this->cacheCounter('cache_loads');

		$this->assertSame('alpha', wp_cache_get('internal_key'));

		$this->assertSame(2, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
		$this->assertSame($loadsAfterFirst, $this->cacheCounter('cache_loads'));
	}

	public function test_get_missing_persistent(): void
	{
		$this->resetCacheCounters();

		wp_cache_get('persistent_missing_a');
		wp_cache_get('persistent_missing_a');
		wp_cache_get('persistent_missing_b');

		$this->assertSame(0, $this->cacheCounter('cache_hits'));
		$this->assertSame(3, $this->cacheCounter('cache_misses'));
	}

	public function test_get_true_value_persistent_cache(): void
	{
		wp_cache_set('true_value_key', true);

		wp_cache_flush_runtime();

		$this->resetCacheCounters();

		$found = null;
		$value = wp_cache_get('true_value_key', 'default', false, $found);

		$this->assertTrue($value);
		$this->assertTrue($found);
		$this->assertSame(1, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_get_null_value_persistent_cache(): void
	{
		wp_cache_set('null_value_key', null);

		wp_cache_flush_runtime();

		$this->resetCacheCounters();

		$found = null;
		$value = wp_cache_get('null_value_key', 'default', false, $found);

		$this->assertNull($value);
		$this->assertTrue($found, 'A stored null value must be distinguishable from a miss via $found.');
		$this->assertSame(1, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_get_int_values_persistent_cache(): void
	{
		wp_cache_set('int_small_key', 123);
		wp_cache_set('int_large_key', 2147483647);

		wp_cache_flush_runtime();

		$this->resetCacheCounters();

		$this->assertSame(123, wp_cache_get('int_small_key'));
		$this->assertSame(2147483647, wp_cache_get('int_large_key'));

		$this->assertSame(2, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_get_float_values_persistent_cache(): void
	{
		wp_cache_set('float_a_key', 123.456);
		wp_cache_set('float_b_key', +0123.45e6);

		wp_cache_flush_runtime();

		$this->resetCacheCounters();

		$this->assertSame(123.456, wp_cache_get('float_a_key'));
		$this->assertSame(123450000.0, wp_cache_get('float_b_key'));

		$this->assertSame(2, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_get_string_values_persistent_cache(): void
	{
		wp_cache_set('str_plain_key', 'a plain old string');
		// The remaining keys are numeric strings. They must NOT be coerced
		// to int or float by the backend serializer.
		wp_cache_set('str_int_key', '42');
		wp_cache_set('str_float_key', '123.456');
		wp_cache_set('str_sci_key', '+0123.45e6');

		wp_cache_flush_runtime();

		$this->resetCacheCounters();

		$this->assertSame('a plain old string', wp_cache_get('str_plain_key'));
		$this->assertSame('42', wp_cache_get('str_int_key'));
		$this->assertSame('123.456', wp_cache_get('str_float_key'));
		$this->assertSame('+0123.45e6', wp_cache_get('str_sci_key'));

		$this->assertSame(4, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_get_array_values_persistent_cache(): void
	{
		$value = ['one', 2, true];

		wp_cache_set('array_value_key', $value);

		wp_cache_flush_runtime();

		$this->resetCacheCounters();

		$this->assertSame($value, wp_cache_get('array_value_key'));

		$this->assertSame(1, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_get_object_values_persistent_cache(): void
	{
		$value = new stdClass();
		$value->one = 'two';
		$value->three = 'four';

		wp_cache_set('object_value_key', $value);

		wp_cache_flush_runtime();

		$this->resetCacheCounters();

		$this->assertEquals($value, wp_cache_get('object_value_key'));

		$this->assertSame(1, $this->cacheCounter('cache_hits'));
		$this->assertSame(0, $this->cacheCounter('cache_misses'));
	}

	public function test_get_multiple_partial_cache_miss(): void
	{
		wp_cache_set('partial_a', 'A', 'partial_group');
		wp_cache_set('partial_b', 'B', 'partial_group');
		wp_cache_set('partial_c', 'C', 'partial_group');

		// Drop the runtime layer so every key must be resolved through the
		// backend, then re-prime one key in memory to exercise the mixed
		// hit-from-runtime / miss-from-backend path.
		wp_cache_flush_runtime();
		wp_cache_get('partial_b', 'partial_group');

		$this->resetCacheCounters();

		$results = wp_cache_get_multiple(
			['partial_a', 'partial_b', 'partial_c', 'partial_missing'],
			'partial_group'
		);

		$this->assertSame('A', $results['partial_a']);
		$this->assertSame('B', $results['partial_b']);
		$this->assertSame('C', $results['partial_c']);
		$this->assertFalse($results['partial_missing']);

		// Three keys resolved (one from runtime, two from backend) and one
		// miss for the absent key.
		$this->assertSame(3, $this->cacheCounter('cache_hits'));
		$this->assertSame(1, $this->cacheCounter('cache_misses'));
	}

	public function test_get_multiple_non_persistent(): void
	{
		wp_cache_add_non_persistent_groups('np_multi');

		wp_cache_set('np_m1', 'one', 'np_multi');
		wp_cache_set('np_m2', 'two', 'np_multi');

		$this->resetCacheCounters();

		$results = wp_cache_get_multiple(['np_m1', 'np_m2', 'np_m3'], 'np_multi');

		$this->assertSame('one', $results['np_m1']);
		$this->assertSame('two', $results['np_m2']);
		$this->assertFalse($results['np_m3']);

		$this->assertSame(2, $this->cacheCounter('cache_hits'));
		$this->assertSame(1, $this->cacheCounter('cache_misses'));
		// Non-persistent groups must never touch the backend.
		$this->assertSame(0, $this->cacheCounter('cache_loads'));
	}

	public function test_incr_separate_groups(): void
	{
		// Missing keys increment to false in either group; the two groups
		// must not share state.
		$this->assertFalse(wp_cache_incr('incr_sep', 1, 'sep_g1'));
		$this->assertFalse(wp_cache_incr('incr_sep', 1, 'sep_g2'));

		wp_cache_set('incr_sep', 0, 'sep_g1');
		wp_cache_set('incr_sep', 0, 'sep_g2');

		$this->assertSame(1, wp_cache_incr('incr_sep', 1, 'sep_g1'));
		$this->assertSame(1, wp_cache_incr('incr_sep', 1, 'sep_g2'));

		$this->assertSame(1, wp_cache_get('incr_sep', 'sep_g1'));
		$this->assertSame(1, wp_cache_get('incr_sep', 'sep_g2'));

		// Bumping one group must not leak into the other.
		$this->assertSame(3, wp_cache_incr('incr_sep', 2, 'sep_g1'));
		$this->assertSame(2, wp_cache_incr('incr_sep', 1, 'sep_g2'));

		$this->assertSame(3, wp_cache_get('incr_sep', 'sep_g1'));
		$this->assertSame(2, wp_cache_get('incr_sep', 'sep_g2'));
	}

	public function test_decr_separate_groups(): void
	{
		$this->assertFalse(wp_cache_decr('decr_sep', 1, 'sep_g1'));
		$this->assertFalse(wp_cache_decr('decr_sep', 1, 'sep_g2'));

		wp_cache_set('decr_sep', 3, 'sep_g1');
		wp_cache_set('decr_sep', 2, 'sep_g2');

		$this->assertSame(2, wp_cache_decr('decr_sep', 1, 'sep_g1'));
		$this->assertSame(1, wp_cache_decr('decr_sep', 1, 'sep_g2'));

		$this->assertSame(2, wp_cache_get('decr_sep', 'sep_g1'));
		$this->assertSame(1, wp_cache_get('decr_sep', 'sep_g2'));

		$this->assertSame(0, wp_cache_decr('decr_sep', 2, 'sep_g1'));
		$this->assertSame(0, wp_cache_decr('decr_sep', 1, 'sep_g2'));

		$this->assertSame(0, wp_cache_get('decr_sep', 'sep_g1'));
		$this->assertSame(0, wp_cache_get('decr_sep', 'sep_g2'));
	}

	public function test_decr_non_persistent(): void
	{
		wp_cache_add_non_persistent_groups('np_decr');

		// Missing key in a non-persistent group must report a miss.
		$this->assertFalse(wp_cache_decr('np_decr_key', 1, 'np_decr'));

		wp_cache_set('np_decr_key', 0, 'np_decr');
		$this->assertSame(-1, wp_cache_decr('np_decr_key', 1, 'np_decr'));

		wp_cache_set('np_decr_key', 3, 'np_decr');
		$this->assertSame(2, wp_cache_decr('np_decr_key', 1, 'np_decr'));

		$this->assertSame(0, wp_cache_decr('np_decr_key', 2, 'np_decr'));
		$this->assertSame(0, wp_cache_get('np_decr_key', 'np_decr'));

		// Non-persistent groups must never reach the backend.
		$this->assertSame(0, $this->cacheCounter('cache_loads'));
	}

	public function test_add_succeeds_after_unsuspend(): void
	{
		wp_suspend_cache_addition(true);

		try {
			$this->assertFalse(wp_cache_add('suspended_key', 'value'));
			$this->assertFalse(wp_cache_get('suspended_key'));
		} finally {
			wp_suspend_cache_addition(false);
		}

		// After unsuspending, additions must work again for new keys and the
		// previously-attempted key must not be silently present.
		$this->assertTrue(wp_cache_add('suspended_key', 'value'));
		$this->assertSame('value', wp_cache_get('suspended_key'));
	}

	public function test_add_after_delete_reuses_key(): void
	{
		// Full lifecycle: set, get, delete, get (miss), add with the same key,
		// get the new value. Guards against implementations that mark a key
		// as deleted in a way that blocks subsequent adds.
		$this->assertTrue(wp_cache_set('reuse_key', 'first'));
		$this->assertSame('first', wp_cache_get('reuse_key'));

		$this->assertTrue(wp_cache_delete('reuse_key'));
		$this->assertFalse(wp_cache_get('reuse_key'));

		$this->assertTrue(wp_cache_add('reuse_key', 'second'));
		$this->assertSame('second', wp_cache_get('reuse_key'));
	}

	public function test_set_with_long_expiration_round_trips(): void
	{
		// 30 days in seconds. Some drop-ins misinterpret values this large as
		// Unix timestamps and expire the entry immediately. The drop-in must
		// treat the argument as a TTL in seconds from now.
		$expiration = 60 * 60 * 24 * 30;

		$this->assertTrue(wp_cache_set('long_exp_set', 'value', 'long_exp_group', $expiration));
		$this->assertSame('value', wp_cache_get('long_exp_set', 'long_exp_group'));
	}

	public function test_add_with_long_expiration_round_trips(): void
	{
		$expiration = 60 * 60 * 24 * 30;

		$this->assertTrue(wp_cache_add('long_exp_add', 'value', 'long_exp_group', $expiration));
		$this->assertSame('value', wp_cache_get('long_exp_add', 'long_exp_group'));
	}

	public function test_replace_with_long_expiration_round_trips(): void
	{
		$expiration = 60 * 60 * 24 * 30;

		$this->assertTrue(wp_cache_set('long_exp_rep', 'first', 'long_exp_group'));
		$this->assertTrue(wp_cache_replace('long_exp_rep', 'second', 'long_exp_group', $expiration));
		$this->assertSame('second', wp_cache_get('long_exp_rep', 'long_exp_group'));
	}

	public function test_short_ttl_expires(): void
	{
		// 1s TTL with a 2s sleep leaves a clear margin past the expiry.
		// A single sleep covers all three writers (set, add, replace) so the
		// suite doesn't pay the cost three times over.
		$cases = [
			'set'     => 'ttl_set_key',
			'add'     => 'ttl_add_key',
			'replace' => 'ttl_rep_key',
		];

		// Seed the replace() target without a TTL so replace() has something
		// to overwrite before applying its 1s TTL.
		wp_cache_set($cases['replace'], 'first', 'ttl_group');

		$this->assertTrue(wp_cache_set($cases['set'], 'set_value', 'ttl_group', 1));
		$this->assertTrue(wp_cache_add($cases['add'], 'add_value', 'ttl_group', 1));
		$this->assertTrue(wp_cache_replace($cases['replace'], 'rep_value', 'ttl_group', 1));

		// Every writer's value must be readable before the TTL elapses.
		$found = null;
		$this->assertSame('set_value', wp_cache_get($cases['set'], 'ttl_group', false, $found));
		$this->assertTrue($found);

		$found = null;
		$this->assertSame('add_value', wp_cache_get($cases['add'], 'ttl_group', false, $found));
		$this->assertTrue($found);

		$found = null;
		$this->assertSame('rep_value', wp_cache_get($cases['replace'], 'ttl_group', false, $found));
		$this->assertTrue($found);

		sleep(2);

		// The runtime layer still holds every value, so the next get() would
		// happily return the stale copies. Flush it to force a backend read
		// where the TTL is actually enforced.
		wp_cache_flush_runtime();

		foreach ($cases as $writer => $key) {
			$found = true;
			$this->assertFalse(
				wp_cache_get($key, 'ttl_group', false, $found),
				"{$writer}() with a 1s TTL must expire after sleep(2)."
			);
			$this->assertFalse(
				$found,
				"An expired {$writer}() value must be reported as a miss via \$found."
			);
		}
	}

}
