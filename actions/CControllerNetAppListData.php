<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Actions;

use API;
use CControllerResponseData;
use CPagerHelper;
use CSettingsHelper;
use CWebUser;
use Modules\NetAppMonitoring\Includes\NetAppCollector;
use Modules\NetAppMonitoring\Includes\NetAppLocationGroups;
use Modules\NetAppMonitoring\Includes\NetAppProblems;
use Modules\NetAppMonitoring\Includes\NetAppRenderer;

/** Permission-checked data request for the lightweight main page, as in VMware. */
class CControllerNetAppListData extends CControllerNetAppList {

	protected function doAction(): void {
		$groupids = $this->getInput('filter_groupids', []);
		$groups = $groupids ? API::HostGroup()->get([
			'output' => ['groupid', 'name'], 'groupids' => $groupids
		]) ?: [] : [];
		// Invalid or inaccessible selections must not widen the scope.
		$scope = $groupids ? ($groups ? (getSubGroups(array_column($groups, 'groupid')) ?: ['0']) : ['0']) : null;
		$search = trim($this->getInput('search', ''));
		$tab = $this->getInput('tab', 'hosts');
		$order = $this->getInput('sortorder', 'ASC');
		$hosts = NetAppCollector::listHosts($scope);
		if ($search !== '') {
			$hosts = array_filter($hosts, static function (array $host) use ($search): bool {
				return stripos($host['name'], $search) !== false
					|| stripos($host['host'], $search) !== false
					|| stripos($host['inventory']['notes'] ?? '', $search) !== false;
			});
		}
		$hosts = array_values(NetAppLocationGroups::sort($hosts, $order));
		$inventory = NetAppCollector::inventory($hosts);
		$data = [
			'tab' => $tab, 'groupids' => $groupids, 'groups' => array_values($groups),
			'search' => $search, 'sort' => 'name', 'sortorder' => $order,
			'page' => (int) $this->getInput('page', 1),
			'per_page' => max(1, (int) CWebUser::$data['rows_per_page']),
			'total_hosts' => count($hosts), 'problem_hostids' => array_column($hosts, 'hostid'),
			'totals' => $inventory['totals'],
			'location_groups' => [], 'snapshots' => [], 'problem_widget' => null, 'paging' => null
		];
		if ($tab === 'problems') {
			$data['problem_widget'] = NetAppProblems::forHosts($data['problem_hostids'], '',
				$data['per_page'], $data['page']);
			$data['page'] = $data['problem_widget']['page'];
			$hosts = [];
		}
		else {
			$data['location_groups'] = NetAppCollector::locationProblems(NetAppLocationGroups::groups($hosts));
			$data['page'] = min($data['page'], max(1, (int) ceil(min(count($hosts),
				(int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT)) / $data['per_page'])));
			$data['paging'] = CPagerHelper::paginate($data['page'], $hosts, ZBX_SORT_UP,
				NetAppRenderer::listUrl($data, ['page' => null]));
			$data['snapshots'] = NetAppCollector::collectSummaries($hosts, $inventory);
		}
		$data['hosts'] = array_values($hosts);
		$this->setResponse(new CControllerResponseData($data));
	}
}
