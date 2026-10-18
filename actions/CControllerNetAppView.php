<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;
use Modules\NetAppMonitoring\Includes\NetAppCollector;
use Modules\NetAppMonitoring\Includes\NetAppProblems;

class CControllerNetAppView extends CController {

	private $host = null;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'hostid' => 'required|db hosts.hostid',
			'tab' => 'in overview,nodes,svms,volumes,luns,ethernet,fc,hardware,performance,problems',
			'search' => 'string',
			'focus' => 'string',
			'sort' => 'in name,state,used',
			'sortorder' => 'in ASC,DESC',
			'page' => 'ge 1'
		]);
		if (!$valid) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $valid;
	}

	protected function checkPermissions(): bool {
		if (!CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
			return false;
		}
		$this->host = NetAppCollector::getHost((string) $this->getInput('hostid'));
		return $this->host !== null;
	}

	protected function doAction(): void {
		$tab = $this->getInput('tab', 'overview');
		$search = trim($this->getInput('search', ''));
		$per_page = max(1, (int) (CWebUser::$data['rows_per_page'] ?? 50));
		$snapshot = NetAppCollector::collect((string) $this->host['hostid']);
		$response = new CControllerResponseData([
			'host' => $this->host,
			'snapshot' => $snapshot,
			'problem_widget' => $tab === 'problems' && ($snapshot['problems_available'] ?? true)
				? NetAppProblems::data((string) $this->host['hostid'], $search, $per_page, (int) $this->getInput('page', 1)) : null,
			'tab' => $tab,
			'search' => $search,
			'focus' => $this->getInput('focus', ''),
			'sort' => $this->getInput('sort', 'name'),
			'sortorder' => $this->getInput('sortorder', 'ASC'),
			'page' => (int) $this->getInput('page', 1),
			'per_page' => $per_page
		]);
		$response->setTitle(_('NetApp').' - '.$this->host['name']);
		$this->setResponse($response);
	}
}
