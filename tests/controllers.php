<?php
/** Run: php tests/controllers.php. Isolated action tests; no Zabbix server required. */
namespace Modules\NetAppMonitoring\Includes {
	class NetAppCollector {
		public static array $hosts = [];
		public static array $calls = [];
		public static function listHosts(): array {
			self::$calls[] = ['listHosts'];
			return self::$hosts;
		}
		public static function getHost(string $id): ?array {
			self::$calls[] = ['getHost', $id];
			foreach (self::$hosts as $host) {
				if ($host['hostid'] === $id) {
					return $host;
				}
			}
			return null;
		}
		public static function collect(string $id): array {
			self::$calls[] = ['collect', $id];
			return ['hostid' => $id];
		}
	}
}

namespace {
	use Modules\NetAppMonitoring\Actions\CControllerNetAppList;
	use Modules\NetAppMonitoring\Actions\CControllerNetAppView;
	use Modules\NetAppMonitoring\Includes\NetAppCollector;

	// Validation itself belongs to Zabbix. The harness records the actual declarations
	// and checks that a validation failure stops execution before authorization/API access.
	class CController {
		public array $rules = [];
		public bool $valid = true;
		public bool $csrfDisabled = false;
		public $response = null;
		protected array $input;
		public function __construct(array $input = []) {
			$this->input = $input;
			$this->init();
		}
		protected function disableCsrfValidation(): void { $this->csrfDisabled = true; }
		protected function validateInput(array $rules): bool { $this->rules = $rules; return $this->valid; }
		protected function getInput($key, $default = null) { return $this->input[$key] ?? $default; }
		protected function setResponse($response): void { $this->response = $response; }
		public function run() {
			if ($this->checkInput()) {
				if (!$this->checkPermissions()) { throw new \RuntimeException('Access denied'); }
				$this->doAction();
			}
			return $this->response;
		}
	}
	class CControllerResponseData {
		public array $data;
		public string $title = '';
		public function __construct(array $data) { $this->data = $data; }
		public function setTitle(string $title): void { $this->title = $title; }
	}
	class CControllerResponseFatal {}
	class CRoleHelper { public const UI_MONITORING_LATEST_DATA = 'monitoring.latest_data'; }
	class CWebUser {
		public static bool $allowed = true;
		public static array $data = ['rows_per_page' => 2];
		public static array $checks = [];
		public static function checkAccess(string $rule): bool {
			self::$checks[] = $rule;
			return self::$allowed;
		}
	}
	if (!function_exists('_')) { function _($text) { return $text; } }
	require __DIR__.'/../actions/CControllerNetAppList.php';
	require __DIR__.'/../actions/CControllerNetAppView.php';

	$count = 0;
	function expect($actual, $expected, string $label): void {
		global $count;
		$count++;
		if ($actual !== $expected) {
			throw new \RuntimeException($label.': expected '.var_export($expected, true)
				.', got '.var_export($actual, true));
		}
	}
	function denied(CController $controller): void {
		try { $controller->run(); }
		catch (\RuntimeException $error) {
			if ($error->getMessage() !== 'Access denied') { throw $error; }
			return;
		}
		throw new \RuntimeException('Expected access denial');
	}
	NetAppCollector::$hosts = [
		['hostid' => '30', 'name' => 'Cluster 10', 'host' => 'netapp-prod'],
		['hostid' => '20', 'name' => 'Cluster 2', 'host' => 'NETAPP-TEST'],
		['hostid' => '10', 'name' => 'Cluster 2', 'host' => 'backup'],
		['hostid' => '40', 'name' => 'Archive', 'host' => 'archive']
	];

	$controller = new CControllerNetAppList();
	$data = $controller->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['40', '10'], 'Natural sort and host ID tie breaker');
	expect(array_keys($data['snapshots']), [40, 10], 'Only current page has snapshots');
	expect($data['total_hosts'], 4, 'Total before pagination');
	expect($data['page'], 1, 'Default page');
	expect($controller->csrfDisabled, true, 'Read-only action permits GET');
	expect(CWebUser::$checks, [CRoleHelper::UI_MONITORING_LATEST_DATA], 'Monitoring role required');
	expect($controller->rules['search'], 'string', 'Search validation');
	expect($controller->rules['sortorder'], 'in ASC,DESC', 'Sort order allowlist');
	expect($controller->rules['page'], 'ge 1', 'Positive page validation');

	NetAppCollector::$calls = [];
	$data = (new CControllerNetAppList(['page' => 999]))->run()->data;
	expect($data['page'], 2, 'Out of range page clamped');
	expect(array_column($data['hosts'], 'hostid'), ['20', '30'], 'Final page selected');
	expect(NetAppCollector::$calls, [['listHosts'], ['collect', '20'], ['collect', '30']],
		'Pagination occurs before expensive collection');

	$data = (new CControllerNetAppList(['search' => '  netapp  ', 'sortorder' => 'DESC']))->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['30', '20'], 'Case-insensitive technical hostname search');
	expect($data['search'], 'netapp', 'Search trimmed');
	expect($data['total_hosts'], 2, 'Filtered count');
	$data = (new CControllerNetAppList(['search' => 'cluster 2']))->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['10', '20'], 'Visible name search');
	NetAppCollector::$calls = [];
	$data = (new CControllerNetAppList(['search' => 'missing']))->run()->data;
	expect($data['hosts'], [], 'Empty search result');
	expect($data['page'], 1, 'Empty search uses valid page');
	expect(NetAppCollector::$calls, [['listHosts']], 'Empty search collects nothing');
	CWebUser::$data['rows_per_page'] = 0;
	$data = (new CControllerNetAppList())->run()->data;
	expect($data['per_page'], 1, 'Invalid row preference cannot divide by zero');
	CWebUser::$data['rows_per_page'] = 2;

	foreach ([new CControllerNetAppList(), new CControllerNetAppView(['hostid' => '20'])] as $action) {
		CWebUser::$allowed = false;
		NetAppCollector::$calls = [];
		denied($action);
		expect(NetAppCollector::$calls, [], 'Denied role cannot enumerate or collect hosts');
	}
	CWebUser::$allowed = true;
	NetAppCollector::$calls = [];
	denied(new CControllerNetAppView(['hostid' => '999']));
	expect(NetAppCollector::$calls, [['getHost', '999']], 'Unauthorized/non-NetApp ID never collected');

	$controller = new CControllerNetAppView(['hostid' => '20']);
	$data = $controller->run()->data;
	expect($data['host']['hostid'], '20', 'Authorized host resolved');
	expect($data['snapshot']['hostid'], '20', 'Authorized host collected');
	expect($data['tab'], 'overview', 'Default detail tab');
	expect($data['sort'], 'name', 'Default detail sort');
	expect($data['page'], 1, 'Default detail page');
	expect($controller->rules['hostid'], 'required|db hosts.hostid', 'Required database ID validation');
	expect($controller->rules['tab'], 'in overview,nodes,svms,volumes,luns,ethernet,fc,hardware,performance,problems',
		'Detail tabs allowlisted');
	expect($controller->rules['sort'], 'in name,state,used', 'Detail sort allowlisted');
	$data = (new CControllerNetAppView([
		'hostid' => '20', 'tab' => 'volumes', 'search' => '  vol  ', 'sort' => 'used',
		'sortorder' => 'DESC', 'page' => 3
	]))->run()->data;
	expect([$data['tab'], $data['search'], $data['sort'], $data['sortorder'], $data['page']],
		['volumes', 'vol', 'used', 'DESC', 3], 'Validated detail preferences passed to view');

	foreach ([new CControllerNetAppList(), new CControllerNetAppView()] as $action) {
		$action->valid = false;
		NetAppCollector::$calls = [];
		CWebUser::$checks = [];
		expect(get_class($action->run()), CControllerResponseFatal::class, 'Invalid input receives fatal response');
		expect(NetAppCollector::$calls, [], 'Invalid input never reaches collector');
		expect(CWebUser::$checks, [], 'Validation precedes permissions');
	}
	echo "Controller checks passed: $count\n";
}
