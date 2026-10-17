<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Includes;

/** Pure location hierarchy helpers. Hosts must already be permission filtered. */
class NetAppLocationGroups {
	public const MACRO = '{$NETAPP.LOCATION}';

	/** Match the reference module's comma-separated, host-level text macro. */
	public static function path(array $macros): array {
		foreach ($macros as $macro) {
			if (($macro['macro'] ?? '') !== self::MACRO) {
				continue;
			}
			// Secret and vault values must never become location labels.
			if ((int) ($macro['type'] ?? 0) !== 0) {
				return [];
			}
			return self::cleanPath(explode(',', (string) ($macro['value'] ?? '')));
		}
		return [];
	}

	public static function key(array $path): string {
		return hash('sha256', json_encode(array_values($path)));
	}

	/** Includes the complete path; [] has no ancestors. */
	public static function ancestors(array $path): array {
		$ancestors = [];
		$prefix = [];
		foreach (self::cleanPath($path) as $part) {
			$prefix[] = $part;
			$ancestors[] = $prefix;
		}
		return $ancestors;
	}

	/** Sort the complete scope before pagination, retaining the host ID keys.
	 * Location paths stay in tree order; $order controls host names within a path.
	 * Hosts without a location follow all grouped hosts in either direction.
	 */
	public static function sort(array $hosts, string $order = 'ASC'): array {
		$direction = strtoupper($order) === 'DESC' ? -1 : 1;
		uasort($hosts, static function (array $left, array $right) use ($direction): int {
			$a = self::cleanPath($left['location_path'] ?? []);
			$b = self::cleanPath($right['location_path'] ?? []);
			if (!$a || !$b) {
				if ((bool) $a !== (bool) $b) {
					return $a ? -1 : 1;
				}
			}
			for ($i = 0, $length = min(count($a), count($b)); $i < $length; $i++) {
				$comparison = strnatcasecmp($a[$i], $b[$i]) ?: strcmp($a[$i], $b[$i]);
				if ($comparison !== 0) {
					return $comparison;
				}
			}
			if (count($a) !== count($b)) {
				return count($a) <=> count($b);
			}
			$name_a = (string) ($left['name'] ?? $left['host'] ?? '');
			$name_b = (string) ($right['name'] ?? $right['host'] ?? '');
			return $direction * (strnatcasecmp($name_a, $name_b) ?: strcmp($name_a, $name_b)
				?: strcmp((string) ($left['hostid'] ?? ''), (string) ($right['hostid'] ?? '')));
		});
		return $hosts;
	}

	/** Aggregate every matching host before slicing the current page.
	 * A parent's hostids/count include its entire subtree; depth starts at zero.
	 * Missing macro hosts are deliberately ungrouped, as in the reference UI.
	 */
	public static function groups(array $allHosts): array {
		$groups = [];
		foreach (self::sort($allHosts) as $fallback_id => $host) {
			$ancestor_keys = [];
			foreach (self::ancestors($host['location_path'] ?? []) as $path) {
				$key = self::key($path);
				if (!isset($groups[$key])) {
					$groups[$key] = ['key' => $key, 'path' => $path, 'label' => end($path),
						'depth' => count($path) - 1, 'parent_key' => $ancestor_keys ? end($ancestor_keys) : null,
						'ancestors' => $ancestor_keys, 'hostids' => [], 'count' => 0];
				}
				$groups[$key]['hostids'][(string) ($host['hostid'] ?? $fallback_id)] = true;
				$ancestor_keys[] = $key;
			}
		}
		foreach ($groups as &$group) {
			$group['hostids'] = array_map('strval', array_keys($group['hostids']));
			$group['count'] = count($group['hostids']);
		}
		unset($group);
		return $groups;
	}

	/** Count each event once per ancestor, including triggers shared by hosts. */
	public static function withProblems(array $groups, $problems, $triggers): array {
		$available = is_array($problems) && is_array($triggers);
		$host_groups = [];
		foreach ($groups as $key => &$group) {
			$group['problems_available'] = $available;
			$group['problems'] = [];
			foreach ($group['hostids'] as $hostid) { $host_groups[$hostid][$key] = true; }
		}
		unset($group);
		if (!$available) { return $groups; }
		$trigger_groups = [];
		foreach ($triggers as $trigger) {
			foreach ($trigger['hosts'] as $host) {
				$trigger_groups[$trigger['triggerid']] = ($trigger_groups[$trigger['triggerid']] ?? [])
					+ ($host_groups[$host['hostid']] ?? []);
			}
		}
		$events = [];
		foreach ($problems as $problem) {
			foreach ($trigger_groups[$problem['objectid']] ?? [] as $key => $_) {
				$events[$key][$problem['eventid']] = (int) $problem['severity'];
			}
		}
		foreach ($groups as $key => &$group) {
			$group['problems'] = array_count_values($events[$key] ?? []);
		}
		unset($group);
		return $groups;
	}

	/** For each visible host ID, group keys to emit immediately before its row.
	 * Call after full-scope sorting and pagination. Repeats ancestors at page start.
	 */
	public static function headers(array $hostsOnPage): array {
		$headers = [];
		$previous_keys = [];
		foreach ($hostsOnPage as $fallback_id => $host) {
			$keys = array_map([self::class, 'key'], self::ancestors($host['location_path'] ?? []));
			$common = 0;
			while (isset($keys[$common], $previous_keys[$common]) && $keys[$common] === $previous_keys[$common]) {
				$common++;
			}
			$headers[(string) ($host['hostid'] ?? $fallback_id)] = array_slice($keys, $common);
			$previous_keys = $keys;
		}
		return $headers;
	}

	private static function cleanPath(array $path): array {
		return array_values(array_filter(array_map(static function ($part): string {
			return is_scalar($part) ? trim((string) $part) : '';
		}, $path), static function (string $part): bool { return $part !== ''; }));
	}
}
