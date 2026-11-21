<?php
/**
 * The latency test and the cache groups of the Statistics tab on the Cache Settings page
 *
 * The functions use the connection, the key salt and the serializer of the loaded handler.
 * Call the functions only when a Twee handler is loaded and connected.
 */

if (!defined('ABSPATH')) {
	exit;
}


/**
 * Get the properties of the loaded handler, such as the connection (redis or memcached), the key salt (key_salt) and the value format (key_format)
 *
 * The handler keeps them in private properties, so a closure that is bound to the class reads them.
 *
 * @return array The values of all properties
 */
function toc_groups_handler(): array
{
	global $wp_object_cache;

	return Closure::bind(function(): array {
		return get_object_vars($this);
	}, $wp_object_cache, WP_Object_Cache::class)();
}


/**
 * Measure the latency of the backend with 100 writes and 100 reads of a 1 KB value
 *
 * The test uses the commands of the handler, so the time includes the serialization and the compression.
 *
 * @return array The average, minimum, 95th percentile and maximum time in µs of each operation (write, read), or the error message (error)
 */
function toc_groups_latency(): array
{
	$handler = toc_groups_handler();

	$engine = WP_Object_Cache::TWEE_CACHE;
	$key = $handler['key_salt'] . ':toc_latency';
	$value = ['data' => str_repeat('x', 1000)];
	$times = ['write' => [], 'read' => []];

	try {
		for ($i = 0; $i < 100; $i++) {
			$start = hrtime(true);

			if ($engine === 'redis') {
				$handler['redis']->hSet($key, 'test', $value);
			} elseif ($engine === 'memcached') {
				$handler['memcached']->set($key, $value, 60);
			} else {
				apcu_store($key, $value, 60);
			}

			$times['write'][] = hrtime(true) - $start;
			$start = hrtime(true);

			if ($engine === 'redis') {
				$handler['redis']->hGet($key, 'test');
			} elseif ($engine === 'memcached') {
				$handler['memcached']->get($key);
			} else {
				apcu_fetch($key);
			}

			$times['read'][] = hrtime(true) - $start;
		}

		if ($engine === 'redis') {
			$handler['redis']->del($key);
		} elseif ($engine === 'memcached') {
			$handler['memcached']->delete($key);
		} else {
			apcu_delete($key);
		}
	} catch (Throwable $e) {
		return ['error' => $e->getMessage()];
	}

	$result = [];

	foreach ($times as $operation => $values) {
		sort($values);

		$result[$operation] = [
			'average' => array_sum($values) / count($values) / 1e3,
			'minimum' => $values[0] / 1e3,
			'p95'     => $values[94] / 1e3,
			'maximum' => $values[99] / 1e3,
		];
	}

	return $result;
}


/**
 * Get the number of keys and the size of each cache group of the site
 *
 * Redis keeps each group in a hash, so the size is the estimated memory of the hash. APCu and Memcached give the memory of each key.
 * Memcached cannot list its keys through the extension, so the function sends "lru_crawler metadump all" to each server.
 * Memcached keeps a key of an old site or group version until it expires or the server evicts it. These keys are stale.
 *
 * @param string $group The name of a group, to also get the keys of this group. Only the Memcached branch of toc_groups_items() uses it.
 *
 * @return array The keys and the bytes of each group (groups) and of the stale keys (stale), the keys of $group with their size and expiration time (members), or the error message (error)
 */
function toc_groups_scan(string $group = ''): array
{
	$handler = toc_groups_handler();

	$engine = WP_Object_Cache::TWEE_CACHE;
	$prefix = $handler['key_salt'] . ':';
	$groups = [];
	$stale = ['keys' => 0, 'bytes' => 0];
	$members = [];

	try {
		if ($engine === 'redis') {
			$redis = $handler['redis'];
			$pattern = addcslashes($prefix, '*?[]\\') . '*';
			$iterator = null;

			do {
				$keys = $redis->scan($iterator, $pattern, 1000);

				if (!$keys) {
					continue;
				}

				$pipeline = $redis->pipeline();

				foreach ($keys as $key) {
					$pipeline->hLen($key);
					// Redis estimates the memory of a hash from 5 of its fields. An exact size reads all fields and blocks the other commands on a large hash.
					$pipeline->rawCommand('MEMORY', 'USAGE', $key);
				}

				$replies = $pipeline->exec();

				foreach ($keys as $index => $key) {
					// The key of a group hash is the key salt, the value format and the group with the site ID: salt:format:group
					$name = explode(':', substr($key, strlen($prefix)), 2)[1] ?? '';

					$groups[$name]['keys'] = ($groups[$name]['keys'] ?? 0) + (int) $replies[$index * 2];
					$groups[$name]['bytes'] = ($groups[$name]['bytes'] ?? 0) + (int) $replies[$index * 2 + 1];
				}
			} while ($iterator > 0);
		} elseif ($engine === 'memcached') {
			// The key of a value is the key salt with the site version, the group with the site ID, the group version and the key: salt_site:group:version:key
			$versions = [];

			foreach ($handler['memcached']->getServerList() as $server) {
				$address = $server['port'] ? 'tcp://' . $server['host'] . ':' . $server['port'] : 'unix://' . $server['host'];
				$socket = @stream_socket_client($address, $code, $message, 1);

				if (!$socket) {
					return ['error' => 'Cannot connect to Memcached at ' . $address . ': ' . $message];
				}

				stream_set_timeout($socket, 10);
				fwrite($socket, "lru_crawler metadump all\r\n");

				while (($line = fgets($socket)) !== false) {
					$line = rtrim($line);

					if ($line === 'END') {
						break;
					}

					// Memcached answers BUSY when another crawl runs, and ERROR when the crawler is off
					if (!str_starts_with($line, 'key=')) {
						fclose($socket);

						return ['error' => 'Memcached at ' . $address . ' cannot list the keys: ' . $line];
					}

					// A line has the fields of a key, such as "key=wp_a1b2c3d4:posts:0123456789abcdef:12 exp=-1 la=1700000000 cas=5 fetch=no cls=1 size=96". The key is URL-encoded.
					parse_str(strtr($line, ' ', '&'), $fields);

					$key = (string) $fields['key'];
					$size = (int) ($fields['size'] ?? 0);

					if (!str_starts_with($key, $prefix)) {
						if (str_starts_with($key, $handler['site_salt'] . '_')) {
							$stale['keys']++;
							$stale['bytes'] += $size;
						}

						continue;
					}

					$rest = substr($key, strlen($prefix));

					// The version keys of the groups
					if (str_starts_with($rest, 'group:')) {
						continue;
					}

					if (preg_match('/^(.+?):([0-9a-f]{16}):/', $rest, $match)) {
						$versions[$match[1]][$match[2]][] = $size;

						if ($match[1] === $group) {
							$members[$match[2]][$key] = ['size' => $size, 'expires' => max(0, (int) ($fields['exp'] ?? 0))];
						}
					}
				}

				fclose($socket);
			}

			$version_keys = [];

			foreach (array_keys($versions) as $name) {
				$version_keys[$name] = $prefix . 'group:' . $name;
			}

			$current = $version_keys ? (array) $handler['memcached']->getMulti(array_values($version_keys)) : [];

			foreach ($versions as $name => $sizes) {
				foreach ($sizes as $version => $values) {
					if ($version === ($current[$version_keys[$name]] ?? '')) {
						$groups[$name] = ['keys' => count($values), 'bytes' => array_sum($values)];
					} else {
						$stale['keys'] += count($values);
						$stale['bytes'] += array_sum($values);
					}
				}
			}

			// Only the keys of the current version of $group
			$members = $members[$current[$prefix . 'group:' . $group] ?? ''] ?? [];
		} else {
			foreach (new APCUIterator('/^' . preg_quote($prefix, '/') . '/', APC_ITER_KEY | APC_ITER_MEM_SIZE) as $entry) {
				// The key is the key salt, the group with the site ID and the key: salt:group:key
				$parts = explode(':', substr($entry['key'], strlen($prefix)), 3);
				$name = ($handler['multisite'] and ctype_digit($parts[0]) and isset($parts[2])) ? $parts[0] . ':' . $parts[1] : $parts[0];

				$groups[$name]['keys'] = ($groups[$name]['keys'] ?? 0) + 1;
				$groups[$name]['bytes'] = ($groups[$name]['bytes'] ?? 0) + (int) $entry['mem_size'];
			}
		}
	} catch (Throwable $e) {
		return ['error' => $e->getMessage()];
	}

	uasort($groups, function(array $a, array $b): int {
		return $b['bytes'] <=> $a['bytes'];
	});

	return ['groups' => $groups, 'stale' => $stale, 'members' => $members];
}


/**
 * Get the keys and the values of a cache group, 100 keys on each page in natural order
 *
 * @param string $group The name of the group with the site ID, as toc_groups_scan() gives it
 * @param int    $page  The page number, from 1
 *
 * @return array The number of keys (total), and the value, the stored size and the expiration time of each key on the page (items), or the error message (error)
 */
function toc_groups_items(string $group, int $page): array
{
	$handler = toc_groups_handler();

	$engine = WP_Object_Cache::TWEE_CACHE;
	$prefix = $handler['key_salt'] . ':';
	$offset = ($page - 1) * 100;
	$items = [];

	try {
		if ($engine === 'redis') {
			$redis = $handler['redis'];
			$group_key = $prefix . $handler['key_format'] . ':' . $group;
			$fields = $redis->hKeys($group_key) ?: [];

			sort($fields, SORT_NATURAL);

			$total = count($fields);
			$fields = array_slice($fields, $offset, 100);

			if ($fields) {
				$values = $redis->hMGet($group_key, $fields);
				$pipeline = $redis->pipeline();

				foreach ($fields as $field) {
					$pipeline->hStrLen($group_key, $field);
				}

				$sizes = $pipeline->exec();

				foreach ($fields as $index => $field) {
					$value = $values[$field] ?? false;
					$expires = 0;

					// The handler keeps a value with an expiration time and the value false in an array with the _t and _d keys
					if (is_array($value) and array_key_exists('_d', $value)) {
						$expires = (int) ($value['_t'] ?? 0);
						$value = $value['_d'];
					}

					$items[$field] = ['value' => $value, 'size' => (int) $sizes[$index], 'expires' => $expires];
				}
			}
		} else {
			// The full keys of the group with their size and expiration time, and the length of the start that the page does not show
			if ($engine === 'memcached') {
				$scan = toc_groups_scan($group);

				if (isset($scan['error'])) {
					return $scan;
				}

				$members = $scan['members'];

				// The key salt, the group and the group version of 16 characters
				$start = strlen($prefix . $group) + 18;
			} else {
				$members = [];

				foreach (new APCUIterator('/^' . preg_quote($prefix . $group . ':', '/') . '/', APC_ITER_KEY | APC_ITER_MEM_SIZE | APC_ITER_TTL | APC_ITER_CTIME) as $entry) {
					$members[$entry['key']] = ['size' => (int) $entry['mem_size'], 'expires' => $entry['ttl'] ? $entry['creation_time'] + $entry['ttl'] : 0];
				}

				$start = strlen($prefix . $group) + 1;
			}

			ksort($members, SORT_NATURAL);

			$total = count($members);
			$members = array_slice($members, $offset, 100, true);
			$values = [];

			if ($members and $engine === 'memcached') {
				$values = (array) $handler['memcached']->getMulti(array_keys($members));
			} elseif ($members) {
				$values = (array) apcu_fetch(array_keys($members));
			}

			foreach ($members as $key => $member) {
				$items[substr($key, $start)] = ['value' => $values[$key] ?? false] + $member;
			}
		}
	} catch (Throwable $e) {
		return ['error' => $e->getMessage()];
	}

	return ['total' => $total, 'items' => $items];
}
