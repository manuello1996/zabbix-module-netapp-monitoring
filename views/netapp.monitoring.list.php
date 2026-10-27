<?php declare(strict_types = 0);

use Modules\NetAppMonitoring\Includes\Common\AutoRefresh;
use Modules\NetAppMonitoring\Includes\Common\Breadcrumbs;

use Modules\NetAppMonitoring\Includes\NetAppRenderer as R;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

\Modules\NetAppMonitoring\Includes\Common\ItemActions::init($this);

// Native Item dialogs depend on these parent-page scripts.

$tab = $data['tab'] ?? 'hosts';
$list_url = static function (array $args = []) use ($data): CUrl {
	return R::listUrl($data, $args);
};
$page = (new CHtmlPage())->setTitle(_('NetApp monitoring'))->setWebLayoutMode(CViewHelper::loadLayoutMode());
$page->setControls((new CTag('nav', true, (new CList())
	->addItem((new CRedirectButton(_('Refresh'), $list_url()))->setAttribute('data-netapp-refresh', '1'))
	->addItem(AutoRefresh::control('netapp-monitoring-auto-refresh', $data['refresh_interval'] ?? 60))
))->setAttribute('aria-label', _('NetApp actions')));

$page->addItem((new CDiv(Breadcrumbs::make([_('NetApp systems')])))
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
	->setProfile('web.netapp.monitoring.list.filter')
	->addVar('action', 'netapp.monitoring.list')->addVar('tab', $tab)
	->addVar('sort', 'name')->addVar('sortorder', $data['sortorder'])
	->addFilterTab(_('Filter'), [$filter_grid])
	->addFormItem((new CSpan())->setAttribute('data-netapp-search-status', '1')
		->setAttribute('data-searching', _('Searching...'))->setAttribute('data-error', _('Search failed. Please try again.'))
		->setAttribute('role', 'status')->setAttribute('aria-live', 'polite'))
	->setAttribute('data-netapp-list-filter', '1'));

$page->addItem((new CDiv(R::listSummary(array_fill_keys(['clusters', 'nodes', 'svms', 'volumes'], "\u{2014}"))))
	->setId('netapp-list-summary')->setAttribute('aria-busy', 'true'));

$tabs = [];
foreach (['hosts' => _('Hosts'), 'problems' => _('Problems')] as $key => $label) {
	$tabs[] = (new CLink($label, $list_url(['tab' => $key, 'page' => null])))
		->setAttribute('data-netapp-list-tab', $key)
		->addClass('netapp-monitoring-tab')->addClass($tab === $key ? 'netapp-monitoring-tab-active' : null)
		->setAttribute('aria-current', $tab === $key ? 'page' : 'false');
}
$page->addItem((new CTag('nav', true, $tabs))->addClass('netapp-monitoring-tabs')
	->setAttribute('aria-label', _('NetApp sections')));

$page->addItem(AutoRefresh::status('netapp-monitoring-refresh-status'));

$page->addItem((new CDiv(_('Loading NetApp data...')))->setId('netapp-search-results')
	->setAttribute('data-netapp-list', '1')->setAttribute('aria-busy', 'true')
	->setAttribute('data-loading', _('Loading NetApp data...'))
	->setAttribute('data-load-error', _('Unable to load NetApp data. Use Refresh to retry.'))
	->setAttribute('data-refresh-error', _('Unable to refresh NetApp data. Showing the last loaded values.')));
$page->addItem(new CTag('noscript', true, _('Enable JavaScript to load NetApp monitoring data.')));
$page->show();
