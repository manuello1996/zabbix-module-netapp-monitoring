<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Includes;

use API;

/** Read-only data access. Every entry point checks the current user's host scope. */
class NetAppCollector {
	public const TEMPLATE_NAME = 'NetApp AFF A700 by HTTP';
	public const ENTITY_GROUPS = ['nodes', 'svms', 'volumes', 'luns', 'disks', 'chassis', 'frus',
		'ethernet_ports', 'fc_ports'];
	private static $host_cache = [];

	public static function listHosts(?array $groupids = null): array {
		$key = json_encode($groupids);
		if (isset(self::$host_cache[$key])) {
			return self::$host_cache[$key];
		}
		$templates = API::Template()->get([
			'output' => ['templateid', 'host', 'name'],
			'search' => ['host' => self::TEMPLATE_NAME, 'name' => self::TEMPLATE_NAME],
			'searchByAny' => true
		]) ?: [];
		$templateids = [];
		foreach ($templates as $template) {
			// Verify substring literally: prefixed names qualify, similar names do not.
			if (stripos($template['name'], self::TEMPLATE_NAME) !== false
					|| stripos($template['host'], self::TEMPLATE_NAME) !== false) {
				$templateids[(string) $template['templateid']] = true;
			}
		}
		$frontier = array_keys($templateids);
		while ($frontier) {
			$children = API::Template()->get([
				'output' => ['templateid'], 'parentTemplateids' => $frontier
			]) ?: [];
			$frontier = [];
			foreach ($children as $child) {
				$id = (string) $child['templateid'];
				if (!isset($templateids[$id])) {
					$templateids[$id] = true;
					$frontier[] = $id;
				}
			}
		}
		if (!$templateids) {
			return self::$host_cache[$key] = [];
		}
		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'description'],
			'templateids' => array_keys($templateids), 'preservekeys' => true,
			'selectHostGroups' => ['groupid', 'name'], 'sortfield' => 'name'
		];
		if ($groupids !== null) {
			$options['groupids'] = $groupids;
		}
		$hosts = API::Host()->get($options) ?: [];
		$result = [];
		foreach ($hosts as $host) {
			$result[(string) $host['hostid']] = $host;
		}
		return self::$host_cache[$key] = $result;
	}

	public static function getHost(string $hostid): ?array {
		return self::listHosts()[$hostid] ?? null;
	}

	/** Only scalar keys from the supplied monitoring template are requested. */
	public static function getItems(string $hostid): array {
		if (self::getHost($hostid) === null) {
			return [];
		}
		$patterns = [];
		foreach (self::scalarKeys() as $base => $definition) {
			$patterns[] = $base.($definition[2] === 0 ? '' : '[*]');
		}
		// First fetch metadata only; raw HTTP payload values never cross this boundary.
		$metadata = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'units', 'state', 'error', 'status', 'value_type'],
			'hostids' => [$hostid], 'search' => ['key_' => $patterns],
			'searchWildcardsEnabled' => true, 'searchByAny' => true,
			'selectTags' => ['tag', 'value'], 'selectValueMap' => ['mappings']
		]) ?: [];
		$items = [];
		foreach ($metadata as $item) {
			if (self::classifyKey($item['key_']) !== null) {
				$items[(string) $item['itemid']] = $item;
			}
		}
		if (!$items) {
			return [];
		}
		$values = API::Item()->get([
			'output' => ['itemid', 'lastvalue', 'lastclock'],
			'hostids' => [$hostid], 'itemids' => array_keys($items)
		]) ?: [];
		foreach ($values as $value) {
			$id = (string) $value['itemid'];
			if (isset($items[$id])) {
				$items[$id] = array_merge($items[$id], $value);
			}
		}
		foreach ($items as &$item) {
			$item['lastclock'] = (int) ($item['lastclock'] ?? 0);
			$item['lastvalue'] = $item['lastclock'] > 0 && (int) ($item['state'] ?? 0) === 0
				&& (int) ($item['status'] ?? 0) === 0 ? ($item['lastvalue'] ?? null) : null;
			$item['value'] = $item['lastvalue'];
		}
		unset($item);
		return array_values($items);
	}

	public static function collect(string $hostid): array {
		$host = self::getHost($hostid);
		if ($host === null) {
			return [];
		}
		$result = self::normalizeItems(self::getItems($hostid));
		$result['host'] = $host;
		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'suppressed'],
			'hostids' => [$hostid], 'recent' => false, 'suppressed' => false,
			'selectTags' => ['tag', 'value'], 'sortfield' => ['eventid'], 'sortorder' => 'DESC',
			'limit' => 1001
		]);
		$result['problems_available'] = is_array($problems);
		$result['problems'] = is_array($problems) ? $problems : [];
		if (!$result['problems_available']) {
			$result['warnings'][] = 'Active problems could not be retrieved. Check API access and permissions.';
		}
		if (count($result['problems']) > 1000) {
			$result['problems'] = array_slice($result['problems'], 0, 1000);
			$result['warnings'][] = 'Only the latest 1,000 active problems are displayed.';
		}
		if ((int) $host['status'] !== 0) {
			$result['warnings'][] = 'Monitoring is disabled for this host.';
		}
		return $result;
	}

	/** Pure normalization, also used by the regression fixtures. */
	public static function normalizeItems(array $items): array {
		$result = ['cluster' => [], 'entities' => array_fill_keys(self::ENTITY_GROUPS, []),
			'counts' => [], 'lastclock' => 0, 'warnings' => []];
		$unsupported = 0;
		$missing = 0;
		foreach ($items as $item) {
			$classification = self::classifyKey($item['key_']);
			if ($classification === null) {
				continue;
			}
			[$group, $metric, $parameters] = $classification;
			$result['lastclock'] = max($result['lastclock'], (int) ($item['lastclock'] ?? 0));
			$unsupported += (int) ($item['state'] ?? 0) !== 0 ? 1 : 0;
			$missing += (int) ($item['lastclock'] ?? 0) === 0 ? 1 : 0;
			if ($group === 'cluster') {
				$result['cluster'][$metric] = $item;
				continue;
			}
			$id = json_encode($parameters);
			if (!isset($result['entities'][$group][$id])) {
				$result['entities'][$group][$id] = ['id' => $id, 'name' => end($parameters),
					'parent' => count($parameters) > 1 ? $parameters[0] : '', 'metrics' => []];
			}
			$result['entities'][$group][$id]['metrics'][$metric] = $item;
		}
		foreach ($result['entities'] as $group => &$rows) {
			foreach ($rows as &$row) {
				$metrics = $row['metrics'];
				$size = self::numericValue($metrics[$group === 'luns' ? 'space.size' : 'space_size'] ?? null);
				$used = self::numericValue($metrics[$group === 'luns' ? 'space.used' : 'space_used'] ?? null);
				$available = self::numericValue($metrics['space_available'] ?? null);
				$row['capacity'] = ['size' => $size, 'used' => $used, 'available' => $available,
					'percent' => $size !== null && $size > 0 && $used !== null ? $used / $size * 100 : null];
			}
			unset($row);
			$rows = array_values($rows);
			usort($rows, static function (array $a, array $b): int {
				return strnatcasecmp($a['parent'].'/'.$a['name'], $b['parent'].'/'.$b['name']);
			});
			$result['counts'][$group] = count($rows);
		}
		unset($rows);
		if (!$items) {
			$result['warnings'][] = 'No supported NetApp scalar monitoring items are accessible on this host.';
		}
		if ($unsupported) {
			$result['warnings'][] = $unsupported.' monitoring item(s) are unsupported; inspect Latest data for details.';
		}
		if ($missing) {
			$result['warnings'][] = $missing.' monitoring item(s) have no collected value yet.';
		}
		return $result;
	}

	private static function numericValue(?array $item): ?float {
		return $item !== null && is_numeric($item['lastvalue'] ?? null) ? (float) $item['lastvalue'] : null;
	}

	/** Parse quoted Zabbix key parameters, preserving commas and backslashes in names. */
	public static function classifyKey(string $key): ?array {
		if (!preg_match('/^([^\[\]]+)(?:\[(.*)\])?$/sD', $key, $matches)) {
			return null;
		}
		$definition = self::scalarKeys()[$matches[1]] ?? null;
		if ($definition === null) {
			return null;
		}
		if ($definition[2] === 0) {
			return count($matches) === 2 ? [$definition[0], $definition[1], []] : null;
		}
		if (!isset($matches[2])) {
			return null;
		}
		$parameters = [];
		$parameter = '';
		$quoted = false;
		$closed = false;
		$input = $matches[2];
		for ($i = 0, $length = strlen($input); $i < $length; $i++) {
			$c = $input[$i];
			if ($quoted && $c === '\\' && $i + 1 < $length && $input[$i + 1] === '"') {
				$parameter .= '"';
				$i++;
			}
			elseif ($c === '"') {
				if ($quoted) { $quoted = false; $closed = true; }
				elseif ($parameter === '' && !$closed) { $quoted = true; }
				else { return null; }
			}
			elseif ($c === ',' && !$quoted) {
				$parameters[] = $parameter;
				$parameter = '';
				$closed = false;
			}
			elseif (!$quoted && ($closed || $c === '[' || $c === ']')) { return null; }
			else { $parameter .= $c; }
		}
		if ($quoted) { return null; }
		$parameters[] = $parameter;
		if (count($parameters) !== $definition[2] || in_array('', $parameters, true)) {
			return null;
		}
		return [$definition[0], $definition[1], $parameters];
	}

	private static function scalarKeys(): array {
		static $keys = null;
		if ($keys !== null) { return $keys; }
		$keys = [];
		$groups = [
			['netapp.cluster.', 'cluster', 0, ['location', 'name', 'status', 'version']],
			['netapp.nodes.', 'nodes', 1, ['controller.over_temperature', 'location', 'membership', 'state', 'uptime']],
			['netapp.node.', 'nodes', 1, ['version']],
			['netapp.svm.', 'svms', 1, ['comment', 'state']],
			['netapp.volume.', 'volumes', 1, ['comment', 'space_available', 'space_size', 'space_used', 'state', 'svm_name', 'type']],
			['netapp.lun.', 'luns', 2, ['space.size', 'space.used', 'status.container_state', 'status.state']],
			['netapp.disk.', 'disks', 2, ['state']],
			['netapp.chassis.', 'chassis', 1, ['state']],
			['netapp.chassis.fru.', 'frus', 2, ['state']],
			['netapp.port.eth.', 'ethernet_ports', 2, ['state']],
			['netapp.port.fc.', 'fc_ports', 2, ['description', 'state']]
		];
		foreach ($groups as [$prefix, $group, $arity, $metrics]) {
			foreach ($metrics as $metric) { $keys[$prefix.$metric] = [$group, $metric, $arity]; }
		}
		foreach (['cluster' => 0, 'volume' => 1] as $object => $arity) {
			foreach (['other', 'read', 'total', 'write'] as $operation) {
				foreach (['iops.%s.rate', 'iops_raw.%s', 'latency.%s', 'latency_raw.%s', 'throughput.%s.rate'] as $metric) {
					$suffix = 'statistics.'.sprintf($metric, $operation);
					$keys['netapp.'.$object.'.'.$suffix] = [$object === 'cluster' ? 'cluster' : 'volumes', $suffix, $arity];
				}
			}
		}
		return $keys;
	}
}
