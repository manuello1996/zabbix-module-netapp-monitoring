<?php declare(strict_types = 0);
use Modules\NetAppMonitoring\Includes\NetAppRenderer as R;

$page = (new CHtmlPage())->setTitle(_('NetApp monitoring'))->setWebLayoutMode(CViewHelper::loadLayoutMode());
if ($data['host'] === null) {
	$page->addItem((new CTableInfo())->setNoDataMessage(_('Select a NetApp host from the monitoring list.')))->show();
	return;
}
$hostid = (string) $data['host']['hostid'];
$snapshot = $data['snapshot'];
$notes = trim($data['host']['inventory']['notes'] ?? '');
$page->setControls((new CTag('nav', true, (new CList())
	->addItem(new CRedirectButton(_('Latest data'), R::latest($hostid)))
	->addItem(new CRedirectButton(_('Problems'), R::problemsUrl($hostid)))
	->addItem((new CRedirectButton(_('Refresh'), R::url(['hostid' => $hostid, 'tab' => $data['tab'],
		'search' => $data['search'], 'page' => $data['page'], 'sort' => $data['sort'], 'sortorder' => $data['sortorder']])))->setAttribute('data-netapp-refresh', '1'))))
	->setAttribute('aria-label', _('Host actions')));
$page->addItem((new CDiv([
	(new CDiv([new CLink(_('NetApp systems'), (new CUrl('zabbix.php'))->setArgument('action', 'netapp.monitoring.list')),
		(new CSpan('›'))->addClass('netapp-monitoring-breadcrumb-sep'), new CSpan($data['host']['name'])]))
		->addClass('netapp-monitoring-breadcrumb'),
	(new CDiv([
		$notes !== '' ? (new CTag('small', true, $notes))->addClass('netapp-monitoring-context-notes') : null,
		(new CSpan([
			new CSpan((int) $data['host']['status'] === 0 ? _('Enabled') : _('Disabled')),
			R::value($snapshot['cluster']['status'] ?? null, true)
		]))->addClass('netapp-monitoring-context-status'),
		(new CSpan([_('Version').': ', R::value($snapshot['cluster']['version'] ?? null)]))
			->addClass('netapp-monitoring-context-version')
	]))->addClass('netapp-monitoring-topbar-context')
]))->addClass('netapp-monitoring-topbar'));
$page->addItem(R::collectionNotice($snapshot, $hostid));
$stats = [];
foreach (['nodes' => _('Nodes'), 'svms' => _('SVMs'), 'volumes' => _('Volumes'), 'luns' => _('LUNs')] as $key => $label) {
	$stats[] = R::stat($label, new CLink((string) count($snapshot['entities'][$key] ?? []), R::url(['hostid' => $hostid, 'tab' => $key])));
}
$stats[] = R::stat(_('Active problems'), new CLink(($snapshot['problems_available'] ?? true)
	? (string) count($snapshot['problems']) : _('Unavailable'), R::url(['hostid' => $hostid, 'tab' => 'problems'])));
$page->addItem((new CDiv($stats))->addClass('netapp-monitoring-statstrip'));
foreach ($snapshot['warnings'] ?? [] as $warning) {
	$page->addItem((new CDiv($warning))->addClass('netapp-monitoring-note'));
}
$page->addItem(R::tabs($data));
$content = new CDiv();
if (in_array($data['tab'], ['performance', 'problems'], true)) {
	$page->addItem(R::filter($data));
	$content->setId('netapp-search-results');
}
if ($data['tab'] === 'overview') {
	$content->addItem(R::overview($data));
}
elseif ($data['tab'] === 'performance') {
	$content->addItem((new CDiv(_('IOPS measures operations per second. Throughput measures bytes transferred per second. Latency is the average time per operation in milliseconds; lower values mean faster responses. Read, write, and other describe operation types; total combines them. Compare history and workload before interpreting a change.')))->addClass('netapp-monitoring-note'));
	$metrics = array_filter($snapshot['cluster'], static function ($key) { return strpos($key, 'statistics.') === 0; }, ARRAY_FILTER_USE_KEY);
	$content->addItem(R::section(_('Cluster performance'), R::metricTable($metrics, $data['search'])));
	$content->addItem((new CDiv(_('Rates are the values calculated by the template. Raw counters are cumulative. Open a metric history to inspect changes over time; volume counters are available under Volumes → Metrics.')))->addClass('netapp-monitoring-note'));
}
elseif ($data['tab'] === 'problems') {
	$content->addItem(R::problems($data));
}
else {
	$groups = ['ethernet' => ['ethernet_ports'], 'fc' => ['fc_ports'], 'hardware' => ['chassis', 'disks', 'frus']];
	$content->addItem(R::inventory($data, $groups[$data['tab']] ?? [$data['tab']]));
}
$page->addItem($content);
$page->show();
