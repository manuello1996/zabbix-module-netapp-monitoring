<?php declare(strict_types = 0);
use Modules\NetAppMonitoring\Includes\NetAppRenderer as R;

$page = (new CHtmlPage())->setTitle(_('NetApp monitoring'))->setWebLayoutMode(CViewHelper::loadLayoutMode());
$list_url = static function (array $args = []) use ($data): CUrl {
	$url = (new CUrl('zabbix.php'))->setArgument('action', 'netapp.monitoring.list');
	foreach (array_merge(['search' => $data['search'], 'sort' => 'name', 'sortorder' => $data['sortorder'],
		'page' => $data['page']], $args) as $key => $value) {
		$url->setArgument($key, $value);
	}
	return $url;
};
$page->setControls((new CTag('nav', true, (new CList())->addItem(new CRedirectButton(_('Refresh'), $list_url()))))
	->setAttribute('aria-label', _('NetApp actions')));
$page->addItem((new CDiv([
	(new CDiv(_('NetApp systems')))->addClass('netapp-monitoring-breadcrumb'),
	(new CSpan(_('Hosts linked to templates containing “NetApp AFF A700 by HTTP”')))->addClass('netapp-monitoring-muted')
]))->addClass('netapp-monitoring-topbar'));
$page->addItem((new CDiv([R::stat(_('Matching hosts'), (string) ($data['total_hosts'] ?? count($data['hosts']))) ]))->addClass('netapp-monitoring-statstrip'));
$page->addItem(R::filter($data, true));
$name_header = new CLink(_('Host / cluster').($data['sortorder'] === 'ASC' ? ' ↑' : ' ↓'),
	$list_url(['sortorder' => $data['sortorder'] === 'ASC' ? 'DESC' : 'ASC', 'page' => 1]));
$table = (new CTableInfo())->setHeader([$name_header, _('Status'), _('Version'), _('Nodes'), _('SVMs'), _('Volumes'), _('LUNs'), _('Problems'), _('Latest sample'), _('Actions')])
	->setNoDataMessage(_('No matching hosts. Link a template whose name contains "NetApp AFF A700 by HTTP" to a host you can access.'));
foreach ($data['hosts'] as $host) {
	$snapshot = $data['snapshots'][$host['hostid']];
	$counts = [];
	foreach (['nodes', 'svms', 'volumes', 'luns'] as $group) {
		$counts[] = new CLink((string) count($snapshot['entities'][$group] ?? []), R::url(['hostid' => $host['hostid'], 'tab' => $group]));
	}
	$table->addRow(array_merge([
		new CDiv([new CLink($host['name'], R::url(['hostid' => $host['hostid']])),
			(new CDiv(R::value($snapshot['cluster']['name'] ?? null)))->addClass('netapp-monitoring-muted')]),
		new CDiv([R::value($snapshot['cluster']['status'] ?? null, true),
			(int) $host['status'] !== 0 ? (new CDiv(_('Disabled')))->addClass('netapp-monitoring-muted') : null]),
		R::value($snapshot['cluster']['version'] ?? null)
	], $counts, [
		new CLink(($snapshot['problems_available'] ?? true) ? (string) count($snapshot['problems']) : _('Unavailable'),
			R::problemsUrl((string) $host['hostid'])),
		R::clock($snapshot['lastclock']), new CLink(_('Latest data'), R::latest((string) $host['hostid']))
	]));
}
$page->addItem((new CDiv($table))->addClass('netapp-monitoring-table-wrap'));
if (isset($data['paging'])) {
	$page->addItem($data['paging']);
}
else {
	$pages = max(1, (int) ceil($data['total_hosts'] / max(1, $data['per_page'])));
	$pager = [new CSpan(sprintf(_('Page %1$s of %2$s'), $data['page'], $pages))];
	foreach ([-1 => _('Previous'), 1 => _('Next')] as $offset => $label) {
		$target = $data['page'] + $offset;
		if ($target >= 1 && $target <= $pages) {
			$pager[] = new CLink($label, (new CUrl('zabbix.php'))->setArgument('action', 'netapp.monitoring.list')
				->setArgument('search', $data['search'])->setArgument('sort', $data['sort'])
				->setArgument('sortorder', $data['sortorder'])->setArgument('page', $target));
		}
	}
	$page->addItem((new CDiv($pager))->addClass('netapp-monitoring-pager'));
}
$page->show();
