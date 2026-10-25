<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Includes;

use Modules\NetAppMonitoring\Includes\Common\LocationHierarchy;

require_once __DIR__.'/common/LocationHierarchy.php';

/** Location hierarchy plus NetApp's permission-aware problem aggregation. */
class NetAppLocationGroups extends LocationHierarchy {
	public const MACRO = '{$NETAPP.LOCATION}';

	public static function path(array $macros): array {
		return parent::pathFromMacros($macros, self::MACRO);
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
}
