<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;
use Modules\NetAppMonitoring\Includes\NetAppCollector;
use Modules\NetAppMonitoring\Includes\NetAppLocationGroups;
use Modules\NetAppMonitoring\Includes\NetAppProblems;

class CControllerNetAppList extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'search' => 'string',
			'tab' => 'in hosts,problems',
			'filter_groupids' => 'array_db hstgrp.groupid',
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1',
			'sort' => 'in name',
			'sortorder' => 'in ASC,DESC',
			'page' => 'ge 1'
		]);
		if (!$valid) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $valid;
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		$search = trim($this->getInput('search', ''));
		$tab = $this->getInput('tab', 'hosts');
		$groupids = $this->getInput('filter_groupids', []);
		$groups = $groupids ? API::HostGroup()->get([
			'output' => ['groupid', 'name'], 'groupids' => $groupids
		]) ?: [] : [];
		// Invalid or inaccessible selections must not widen the scope.
		$scope = $groupids ? ($groups ? (getSubGroups(array_column($groups, 'groupid')) ?: ['0']) : ['0']) : null;
		$hosts = NetAppCollector::listHosts($scope);
		if ($search !== '') {
			$hosts = array_filter($hosts, static function (array $host) use ($search): bool {
				return stripos($host['name'], $search) !== false
					|| stripos($host['host'], $search) !== false
					|| stripos($host['inventory']['notes'] ?? '', $search) !== false;
			});
		}
		$order = $this->getInput('sortorder', 'ASC');
		$hosts = array_values(NetAppLocationGroups::sort($hosts, $order));
		$location_groups = NetAppLocationGroups::groups($hosts);
		if ($tab === 'hosts' && $location_groups) {
			$location_groups = NetAppCollector::locationProblems($location_groups);
		}
		$problem_hostids = array_column($hosts, 'hostid');
		$total = count($hosts);
		$per_page = max(1, (int) (CWebUser::$data['rows_per_page'] ?? 50));
		$page = min((int) $this->getInput('page', 1), max(1, (int) ceil($total / $per_page)));
		$hosts = array_slice($hosts, ($page - 1) * $per_page, $per_page);
		$snapshots = [];
		foreach ($tab === 'hosts' ? $hosts : [] as $host) {
			$snapshots[$host['hostid']] = NetAppCollector::collect((string) $host['hostid']);
		}
		$problem_widget = $tab === 'problems'
			? NetAppProblems::forHosts($problem_hostids, '', $per_page, (int) $this->getInput('page', 1)) : null;
		if ($problem_widget !== null) {
			$page = $problem_widget['page'];
		}
		$response = new CControllerResponseData([
			'tab' => $tab, 'groupids' => $groupids, 'groups' => array_values($groups),
			'location_groups' => $location_groups, 'problem_hostids' => $problem_hostids,
			'problem_widget' => $problem_widget,
			'hosts' => $hosts, 'snapshots' => $snapshots, 'host' => null,
			'total_hosts' => $total, 'total' => $total, 'search' => $search,
			'page' => $page, 'per_page' => $per_page, 'sort' => 'name', 'sortorder' => $order
		]);
		$response->setTitle(_('NetApp clusters'));
		$this->setResponse($response);
	}
}
