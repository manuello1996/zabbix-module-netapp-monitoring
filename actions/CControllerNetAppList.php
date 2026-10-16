<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;
use Modules\NetAppMonitoring\Includes\NetAppCollector;

class CControllerNetAppList extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'search' => 'string',
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
		$hosts = NetAppCollector::listHosts();
		if ($search !== '') {
			$hosts = array_filter($hosts, static function (array $host) use ($search): bool {
				return stripos($host['name'], $search) !== false
					|| stripos($host['host'], $search) !== false;
			});
		}
		$order = $this->getInput('sortorder', 'ASC');
		usort($hosts, static function (array $a, array $b) use ($order): int {
			$result = strnatcasecmp($a['name'], $b['name'])
				?: strnatcmp((string) $a['hostid'], (string) $b['hostid']);
			return $order === 'DESC' ? -$result : $result;
		});
		$total = count($hosts);
		$per_page = max(1, (int) (CWebUser::$data['rows_per_page'] ?? 50));
		$page = min((int) $this->getInput('page', 1), max(1, (int) ceil($total / $per_page)));
		$hosts = array_slice($hosts, ($page - 1) * $per_page, $per_page);
		$snapshots = [];
		foreach ($hosts as $host) {
			$snapshots[$host['hostid']] = NetAppCollector::collect((string) $host['hostid']);
		}
		$response = new CControllerResponseData([
			'hosts' => $hosts, 'snapshots' => $snapshots, 'host' => null,
			'total_hosts' => $total, 'total' => $total, 'search' => $search,
			'page' => $page, 'per_page' => $per_page, 'sort' => 'name', 'sortorder' => $order
		]);
		$response->setTitle(_('NetApp clusters'));
		$this->setResponse($response);
	}
}
