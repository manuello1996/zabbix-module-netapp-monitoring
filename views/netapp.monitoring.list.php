<?php declare(strict_types = 0);
use Modules\NetAppMonitoring\Includes\NetAppRenderer as R;
use Modules\NetAppMonitoring\Includes\NetAppPager;
use Modules\NetAppMonitoring\Includes\NetAppLocationGroups;
use Widgets\Problems\Includes\WidgetProblems;

$tab = $data['tab'] ?? 'hosts';
$list_url = static function (array $args = []) use ($data, $tab): CUrl {
	$url = (new CUrl('zabbix.php'))->setArgument('action', 'netapp.monitoring.list');
	foreach (array_merge(['search' => $data['search'], 'filter_groupids' => $data['groupids'] ?? [],
		'tab' => $tab, 'sort' => 'name', 'sortorder' => $data['sortorder'], 'page' => $data['page']], $args) as $key => $value) {
		$url->setArgument($key, $value);
	}
	return $url;
};
$page = (new CHtmlPage())->setTitle(_('NetApp monitoring'))->setWebLayoutMode(CViewHelper::loadLayoutMode());
$page->setControls((new CTag('nav', true, (new CList())->addItem(
	(new CRedirectButton(_('Refresh'), $list_url()))->setAttribute('data-netapp-refresh', '1')
)))->setAttribute('aria-label', _('NetApp actions')));
$page->addItem((new CDiv((new CDiv(_('NetApp systems')))->addClass('netapp-monitoring-breadcrumb')))
	->addClass('netapp-monitoring-topbar'));

$groups = array_map(static function (array $group): array {
	return ['id' => $group['groupid'], 'name' => $group['name']];
}, $data['groups'] ?? []);
$filter_grid = (new CFormGrid())->addItem([
	new CLabel(_('Name'), 'netapp-search'),
	new CFormField((new CTextBox('search', $data['search']))->setId('netapp-search')
		->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH))
])->addItem([
	new CLabel(_('Host groups'), 'filter_groupids__ms'),
	new CFormField((new CMultiSelect([
		'name' => 'filter_groupids[]', 'object_name' => 'hostGroup', 'data' => $groups,
		'popup' => ['parameters' => [
			'srctbl' => 'host_groups', 'srcfld1' => 'groupid', 'dstfrm' => 'zbx_filter',
			'dstfld1' => 'filter_groupids_', 'with_monitored_items' => true
		]]
	]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH))
]);
$page->addItem((new CFilter())->setResetUrl($list_url(['search' => null, 'filter_groupids' => null, 'page' => null]))
	->setProfile('web.netapp.monitoring.list.filter')->setActiveTab(1)
	->addVar('action', 'netapp.monitoring.list')->addVar('tab', $tab)
	->addVar('sort', 'name')->addVar('sortorder', $data['sortorder'])
	->addFilterTab(_('Filter'), [$filter_grid])
	->addFormItem((new CSpan())->setAttribute('data-netapp-search-status', '1')
		->setAttribute('data-searching', _('Searching...'))->setAttribute('data-error', _('Search failed. Please try again.'))
		->setAttribute('role', 'status')->setAttribute('aria-live', 'polite'))
	->setAttribute('data-netapp-main-filter', '1'));

$results = (new CDiv())->setId('netapp-search-results');
$tabs = [];
foreach (['hosts' => _('Hosts'), 'problems' => _('Problems')] as $key => $label) {
	$tabs[] = (new CLink($label, $list_url(['tab' => $key, 'page' => null])))
		->addClass('netapp-monitoring-tab')->addClass($tab === $key ? 'netapp-monitoring-tab-active' : null)
		->setAttribute('aria-current', $tab === $key ? 'page' : 'false');
}
$results->addItem((new CTag('nav', true, $tabs))->addClass('netapp-monitoring-tabs')
	->setAttribute('aria-label', _('NetApp sections')));

if ($tab === 'problems') {
	$widget = $data['problem_widget'];
	$table = (new WidgetProblems($widget))->setNoDataMessage(_('No recent problems found for the matching hosts.'));
	if (isset($widget['total'])) {
		$table->setPageNavigation(NetAppPager::forTotal($widget['page'], $widget['total'], $widget['per_page'],
			$list_url(['page' => null]), $widget['has_more'] ?? false));
	}
	$problem_url = (new CUrl('zabbix.php'))->setArgument('action', 'problem.view')
		->setArgument('hostids', $data['problem_hostids'] ?: ['0'])->setArgument('filter_set', 1);
	$results->addItem((new CDiv($table))->addClass('dashboard-widget-problems'));
	if (($widget['info'] ?? '') !== '') {
		$results->addItem((new CDiv($widget['info']))->addClass('netapp-monitoring-note'));
	}
	$results->addItem((new CDiv(new CLink(_('View all problems'), $problem_url)))->addClass(ZBX_STYLE_RIGHT));
	$page->addItem($results)->show();
	return;
}

$severity_badges = static function (array $severities, array $hostids, bool $available = true) {
	$url = (new CUrl('zabbix.php'))->setArgument('action', 'problem.view')
		->setArgument('hostids', $hostids ?: ['0'])->setArgument('filter_set', 1);
	if (!$available) { return new CLink(_('Unavailable'), $url); }
	if (!$severities) { return (new CLink(_('None'), $url))->addClass(ZBX_STYLE_GREEN); }
	krsort($severities);
	$badges = [];
	foreach ($severities as $severity => $count) {
		$badges[] = (new CSpan((string) $count))->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
			->addClass(CSeverityHelper::getStatusStyle($severity))->setTitle(CSeverityHelper::getName($severity));
	}
	return (new CLink((new CDiv($badges))->addClass(ZBX_STYLE_PROBLEM_ICON_LIST), $url))
		->addClass(ZBX_STYLE_PROBLEM_ICON_LINK);
};
$problem_badge = static function (array $snapshot, string $hostid) use ($severity_badges) {
	return $severity_badges(array_count_values(array_column($snapshot['problems'], 'severity')), [$hostid],
		$snapshot['problems_available'] ?? true);
};
$name_header = new CLink([_('Host / cluster'),
	(new CSpan())->addClass($data['sortorder'] === 'ASC' ? ZBX_STYLE_ARROW_UP : ZBX_STYLE_ARROW_DOWN)],
	$list_url(['sortorder' => $data['sortorder'] === 'ASC' ? 'DESC' : 'ASC', 'page' => null]));
$table = (new CTableInfo())->setId('netapp-monitoring-hosts-table')->setHeader([$name_header, _('Notes'), _('Status'), _('Version'), _('Nodes'),
	_('SVMs'), _('Volumes'), _('LUNs'), _('Problems'), _('Actions')])
	->setNoDataMessage(_('No matching NetApp hosts found.'));
$headers = NetAppLocationGroups::headers($data['hosts']);
foreach ($data['hosts'] as $host) {
	$hostid = (string) $host['hostid'];
	foreach ($headers[$hostid] ?? [] as $key) {
		$group = $data['location_groups'][$key];
		$button = (new CButton(null, [
			(new CSpan())->addClass('netapp-monitoring-location-chevron')->setAttribute('aria-hidden', 'true'),
			(new CSpan($group['label']))->addClass('netapp-monitoring-location-name')
		]))->addClass('netapp-monitoring-location-toggle')->setAttribute('data-location-toggle', $key)
			->setAttribute('aria-expanded', 'true');
		$label = (new CDiv([$button, (new CSpan(sprintf(_('%1$s hosts'), $group['count'])))
			->addClass('netapp-monitoring-location-count')]))->addClass('netapp-monitoring-location-group')
			->setAttribute('style', '--netapp-location-depth: '.(int) $group['depth']);
		$table->addRow((new CRow([(new CCol($label))->setColSpan(8),
			$severity_badges($group['problems'] ?? [], $group['hostids'], $group['problems_available'] ?? false), '']))
			->addClass('netapp-monitoring-location-row')->setAttribute('data-location-key', $key)
			->setAttribute('data-location-ancestors', json_encode($group['ancestors'])));
	}
	$snapshot = $data['snapshots'][$hostid];
	$counts = [];
	foreach (['nodes', 'svms', 'volumes', 'luns'] as $group) {
		$counts[] = new CLink((string) count($snapshot['entities'][$group] ?? []), R::url(['hostid' => $hostid, 'tab' => $group]));
	}
	$ancestors = array_map([NetAppLocationGroups::class, 'key'], NetAppLocationGroups::ancestors($host['location_path'] ?? []));
	$name = (new CLink($host['name'], R::url(['hostid' => $hostid])))
		->setMenuPopup(CMenuPopupHelper::getHost($hostid))->setAttribute('data-netapp-host-link', '1');
	$name_cell = (new CDiv([$name, (new CDiv(R::value($snapshot['cluster']['name'] ?? null)))
		->addClass('netapp-monitoring-muted')]))->addClass('netapp-monitoring-location-host')
		->setAttribute('style', '--netapp-location-depth: '.count($ancestors));
	$table->addRow((new CRow(array_merge([
		$name_cell,
		(new CDiv($host['inventory']['notes'] ?? ''))->addClass('netapp-monitoring-host-notes-preview')
			->setTitle($host['inventory']['notes'] ?? ''),
		new CDiv([R::value($snapshot['cluster']['status'] ?? null, true),
			R::collectionMarker($snapshot),
			(int) $host['status'] !== 0 ? (new CDiv(_('Disabled')))->addClass('netapp-monitoring-muted') : null]),
		R::value($snapshot['cluster']['version'] ?? null)
	], $counts, [
		$problem_badge($snapshot, $hostid), new CLink(_('Latest data'), R::latest($hostid))
	])))->setAttribute('data-location-ancestors', json_encode($ancestors)));
}
$table->setPageNavigation(NetAppPager::forTotal($data['page'], $data['total_hosts'], $data['per_page'], $list_url(['page' => null])));
$results->addItem((new CDiv($table))->addClass('netapp-monitoring-table-wrap')->setAttribute('data-netapp-locations', '1')
	->setAttribute('data-user', (string) (CWebUser::$data['userid'] ?? '0')));
$page->addItem($results)->show();
