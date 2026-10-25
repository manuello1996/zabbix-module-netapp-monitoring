<?php declare(strict_types = 0);
use Modules\NetAppMonitoring\Includes\Common\ProblemBadges;
use Modules\NetAppMonitoring\Includes\Common\SortableHeader;
use Modules\NetAppMonitoring\Includes\Common\Pager;

use Modules\NetAppMonitoring\Includes\NetAppRenderer as R;
use Modules\NetAppMonitoring\Includes\NetAppPager;
use Modules\NetAppMonitoring\Includes\NetAppLocationGroups;
use Modules\NetAppMonitoring\Includes\Common\LocationRows;
use Widgets\Problems\Includes\WidgetProblems;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

$tab = $data['tab'];
$list_url = static function (array $args = []) use ($data): CUrl {
	return R::listUrl($data, $args);
};
$results = (new CDiv())->setId('netapp-search-results')->setAttribute('data-netapp-list', '1');
if ($tab === 'problems') {
	$widget = $data['problem_widget'];
	$table = (new WidgetProblems($widget))->setNoDataMessage(_('No recent problems found for the matching hosts.'));
	if (isset($widget['total'])) {
		$table->setPageNavigation(NetAppPager::forTotal($widget['page'], $widget['total'], $widget['per_page'],
			$list_url(['page' => null]), $widget['has_more'] ?? false));
	}
	$problem_url = (new CUrl('zabbix.php'))->setArgument('action', 'problem.view')
		->setArgument('hostids', $data['problem_hostids'] ?: ['0'])->setArgument('filter_set', 1);
	$results->addItem((new CDiv($table))->addClass('dashboard-widget-problems')->setAttribute('data-netapp-problems', '1'));
	if (($widget['info'] ?? '') !== '') {
		$results->addItem((new CDiv($widget['info']))->addClass('netapp-monitoring-note'));
	}
	$results->addItem((new CDiv(new CLink(_('View all problems'), $problem_url)))->addClass(ZBX_STYLE_RIGHT));
	echo json_encode(['html' => (string) $results, 'groups' => $data['groups'],
		'summary' => (string) R::listSummary($data['totals'])], JSON_THROW_ON_ERROR);
	return;
}

$severity_badges = static function (array $severities, array $hostids, bool $available = true) {
	return ProblemBadges::link($severities, $hostids, ['available' => $available, 'empty_link' => true]);
};
$problem_badge = static function (array $snapshot, string $hostid) use ($severity_badges) {
	return $severity_badges(array_count_values(array_column($snapshot['problems'], 'severity')), [$hostid],
		$snapshot['problems_available'] ?? true);
};
$name_header = SortableHeader::native(_('Host / cluster'), 'name', 'name', $data['sortorder'],
	$list_url(['page' => null])->getUrl());
$table = (new CTableInfo())->setId('netapp-monitoring-hosts-table')->setHeader([$name_header, _('Notes'), _('Status'), _('Version'), _('Nodes'),
	_('SVMs'), _('Volumes'), _('LUNs'), _('Problems'), _('Actions')])
	->setNoDataMessage(_('No matching NetApp hosts found.'));
$headers = NetAppLocationGroups::headers($data['hosts']);
foreach ($data['hosts'] as $host) {
	$hostid = (string) $host['hostid'];
	foreach ($headers[$hostid] ?? [] as $key) {
		$group = $data['location_groups'][$key];
		$table->addRow(LocationRows::header($group, sprintf(_('%1$s hosts'), $group['count']), [
			$severity_badges($group['problems'] ?? [], $group['hostids'], $group['problems_available'] ?? false), ''
		], 8));
	}
	$snapshot = $data['snapshots'][$hostid];
	$counts = [];
	foreach (['nodes', 'svms', 'volumes', 'luns'] as $group) {
		$counts[] = new CLink(isset($snapshot['counts'])
			? ($snapshot['counts'][$group] === null ? _('Unavailable') : (string) $snapshot['counts'][$group])
			: (string) count($snapshot['entities'][$group] ?? []), R::url(['hostid' => $hostid, 'tab' => $group]));
	}
	$ancestors = NetAppLocationGroups::ancestorKeys($host['location_path'] ?? []);
	$name = (new CLink($host['name'], R::url(['hostid' => $hostid])))
		->setMenuPopup(CMenuPopupHelper::getHost($hostid))->setAttribute('data-netapp-host-link', '1');
	$name_cell = (new CDiv([$name, (new CDiv(R::inlineValue($snapshot['cluster']['name'] ?? null)))
		->addClass('netapp-monitoring-muted')]))->addClass('netapp-monitoring-location-host')
		->setAttribute('style', '--netapp-location-depth: '.count($ancestors));
	$table->addRow(LocationRows::withAncestors(new CRow(array_merge([
		$name_cell,
		(new CDiv($host['inventory']['notes'] ?? ''))->addClass('netapp-monitoring-host-notes-preview')
			->setTitle($host['inventory']['notes'] ?? ''),
		new CDiv([R::inlineValue($snapshot['cluster']['status'] ?? null, true),
			R::collectionMarker($snapshot),
			(int) $host['status'] !== 0 ? (new CDiv(_('Disabled')))->addClass('netapp-monitoring-muted') : null]),
		R::inlineValue($snapshot['cluster']['version'] ?? null)
	], $counts, [
		$problem_badge($snapshot, $hostid), new CLink(_('Latest data'), R::latest($hostid))
	])), $ancestors));
}
$table->setPageNavigation($data['paging']);
$results->addItem((new CDiv($table))->addClass('netapp-monitoring-table-wrap')->setAttribute('data-netapp-locations', '1')
	->setAttribute('data-user', (string) (CWebUser::$data['userid'] ?? '0')));
echo json_encode(['html' => (string) $results, 'groups' => $data['groups'],
	'summary' => (string) R::listSummary($data['totals'])], JSON_THROW_ON_ERROR);
