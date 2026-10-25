<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Includes\Common;

class Sparkline {
	public static function holder(array $attributes = [], string $kind = ''): \CDiv {
		$holder = (new \CDiv())->addClass('netapp-monitoring-sparkline')->setAttribute('aria-label', _('Loading history'));
		if ($kind !== '') { $holder->addClass('netapp-monitoring-spark-'.$kind); }
		foreach ($attributes as $name => $value) { $holder->setAttribute($name, $value); }
		return $holder;
	}
}
