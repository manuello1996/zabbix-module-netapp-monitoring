<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Includes;

/** Use Zabbix's native pager for result sets already sliced by the collector. */
class NetAppPager extends \CPagerHelper {
	public static function forTotal(int $page, int $total, int $per_page, \CUrl $url,
			bool $limit_exceeded = false): \CTag {
		$per_page = max(1, $per_page);
		$total = max(0, $total);
		$pages = max(1, (int) ceil($total / $per_page));
		return parent::render(min(max(1, $page), $pages), $total, $pages, $url, $limit_exceeded, $per_page);
	}
}
