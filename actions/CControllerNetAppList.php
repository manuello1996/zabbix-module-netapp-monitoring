<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Actions;

use Modules\NetAppMonitoring\Includes\Common\AutoRefresh;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

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
		$order = $this->getInput('sortorder', 'ASC');
		$per_page = max(1, (int) (CWebUser::$data['rows_per_page'] ?? 50));
		// Match VMware: render the controls before discovering hosts or collecting data.
		$response = new CControllerResponseData([
			'tab' => $tab, 'groupids' => $groupids, 'groups' => array_values($groups),
			'search' => $search, 'page' => (int) $this->getInput('page', 1),
			'per_page' => $per_page, 'sort' => 'name', 'sortorder' => $order,
			'refresh_interval' => AutoRefresh::interval(timeUnitToSeconds(CWebUser::getRefresh()))
		]);
		$response->setTitle(_('NetApp clusters'));
		$this->setResponse($response);
	}
}
