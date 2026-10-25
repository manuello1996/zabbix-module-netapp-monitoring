<?php declare(strict_types = 0);

// Generated from zabbix-module-common. Columns and statistics belong to the module.
namespace Modules\NetAppMonitoring\Includes\Common;

class LocationRows {
	public static function header(array $group, string $count, array $columns, int $label_span = 1,
			array $groups = []): \CRow {
		$button = (new \CButton(null, [
			(new \CSpan())->addClass('netapp-monitoring-location-chevron')->setAttribute('aria-hidden', 'true'),
			(new \CSpan($group['label']))->addClass('netapp-monitoring-location-name')
		]))->addClass('netapp-monitoring-location-toggle')
			->setAttribute('data-location-toggle', $group['key'])
			->setAttribute('aria-expanded', ($group['collapsed'] ?? false) ? 'false' : 'true');
		$label = (new \CDiv([$button, (new \CSpan($count))->addClass('netapp-monitoring-location-count')]))
			->addClass('netapp-monitoring-location-group')
			->setAttribute('style', '--netapp-location-depth: '.(int) $group['depth']);
		$row = (new \CRow(array_merge([(new \CCol($label))->setColSpan($label_span)], $columns)))
			->addClass('netapp-monitoring-location-row')
			->setAttribute('data-location-key', $group['key']);
		return self::withAncestors($row, $group['ancestors'], $groups);
	}

	public static function withAncestors(\CRow $row, array $ancestors, array $groups = []): \CRow {
		$row->setAttribute('data-location-ancestors', json_encode($ancestors));
		foreach ($ancestors as $key) {
			if ($groups[$key]['collapsed'] ?? false) {
				$row->setAttribute('hidden', 'hidden');
				break;
			}
		}
		return $row;
	}
}
