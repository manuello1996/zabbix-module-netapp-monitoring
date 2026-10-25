<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Includes;

use Modules\NetAppMonitoring\Includes\Common\Formatting;

require_once __DIR__.'/common/Formatting.php';

class NetAppFormatter {
	public static function bytes($value, int $decimals = 1): string {
		if (!is_numeric($value)) { return '-'; }
		return Formatting::bytes($value, $decimals, ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB']);
	}

	public static function number($value, int $decimals = 1): string {
		return is_numeric($value) ? number_format((float) $value, $decimals) : '-';
	}

	public static function percent($value, int $decimals = 1): string {
		return is_numeric($value) ? self::number($value, $decimals).'%' : '-';
	}

	public static function rate($value): string {
		return is_numeric($value) ? self::bytes($value).'/s' : '-';
	}

	public static function latency($value): string {
		return is_numeric($value) ? self::number($value, 3).' ms' : '-';
	}

	public static function duration($seconds): string {
		if (!is_numeric($seconds)) { return '-'; }
		return Formatting::duration($seconds);
	}

	public static function state($value, string $healthy = 'ok'): array {
		if ($value === null || $value === '') { return ['text' => 'Unknown', 'kind' => 'unknown']; }
		$value = (string) $value;
		return ['text' => ucfirst(str_replace('_', ' ', $value)),
			'kind' => $value === $healthy ? 'running' : ($value === 'unknown' ? 'unknown' : 'stopped')];
	}

	public static function itemValue(?array $item): string {
		if ($item === null || ($item['lastvalue'] ?? null) === null) { return '-'; }
		$value = $item['lastvalue'];
		if (!empty($item['valuemap']['mappings']) && class_exists('CValueMapHelper')) {
			$mapped = \CValueMapHelper::applyValueMap($item['value_type'], (string) $value, $item['valuemap']);
			if ($mapped !== '') { return $mapped; }
		}
		// Numeric-looking identifiers and ONTAP versions are text, not measurements.
		if (isset($item['value_type']) && !in_array((int) $item['value_type'], [0, 3], true)) {
			return (string) $value;
		}
		$units = ltrim($item['units'] ?? '', '!');
		if ($units === 'B') { return self::bytes($value); }
		if ($units === 'Bps') { return self::rate($value); }
		if ($units === 'ms') { return self::latency($value); }
		if ($units === 'uptime' || $units === 's') { return self::duration($value); }
		return is_numeric($value) ? trim(self::number($value).' '.trim($units)) : (string) $value;
	}
}
