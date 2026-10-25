<?php
/** Run: php tests/controllers.php. Isolated action tests; no Zabbix server required. */
namespace Modules\NetAppMonitoring\Includes {
	class NetAppProblems {
		public static array $calls = [];
		public static function forHosts(array $hostids, string $search, int $lines, int $page): array {
			self::$calls[] = [$hostids, $search, $lines, $page];
			return ['native_widget' => true, 'page' => $page];
		}
		public static function data(string $hostid, string $search, int $lines, int $page): array {
			self::$calls[] = [$hostid, $search, $lines, $page];
			return ['native_widget' => true];
		}
	}
	class NetAppCollector {
		public static array $hosts = [];
		public static array $calls = [];
		public static array $groupCalls = [];
		public static array $inventoryCalls = [];
		public static array $summaryInventories = [];
		public static function locationProblems(array $groups): array {
			self::$groupCalls[] = ['groups' => $groups, 'calls_before' => self::$calls];
			return $groups;
		}
		public static function listHosts(?array $scope = null): array {
			self::$calls[] = $scope === null ? ['listHosts'] : ['listHosts', $scope];
			return $scope === null ? self::$hosts : array_filter(self::$hosts, static function ($host) use ($scope) {
				return count(array_intersect($host['groupids'] ?? [], $scope)) > 0;
			});
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
		public static function inventory(array $hosts): array {
			self::$inventoryCalls[] = array_column($hosts, 'hostid');
			return ['totals' => ['clusters' => count($hosts), 'nodes' => count($hosts) * 2,
				'svms' => count($hosts) * 3, 'volumes' => count($hosts) * 4]];
		}
		public static function collectSummaries(array $hosts, ?array $inventory = null): array {
			self::$summaryInventories[] = $inventory;
			$result = [];
			foreach ($hosts as $host) { $result[$host['hostid']] = ['hostid' => $host['hostid']]; }
			self::$calls[] = ['summaries', array_column($hosts, 'hostid')];
			return $result;
		}
		public static function collect(string $id): array {
			self::$calls[] = ['collect', $id];
			return ['hostid' => $id];
		}
	}
}

namespace {
	use Modules\NetAppMonitoring\Actions\CControllerNetAppList;
	use Modules\NetAppMonitoring\Actions\CControllerNetAppListData;
	use Modules\NetAppMonitoring\Actions\CControllerNetAppView;
	use Modules\NetAppMonitoring\Actions\CControllerNetAppSearch;
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
	class CUrl {
		public array $args = [];
		public function __construct($url) {}
		public function setArgument($name, $value): self { $this->args[$name] = $value; return $this; }
	}
	const ZBX_SORT_UP = 'ASC';
	class CSettingsHelper {
		const SEARCH_LIMIT = 'search_limit';
		public static $limit = 1000;
		public static function get($key) { return self::$limit; }
	}
	class CPagerHelper {
		public static function paginate($page, &$rows, $order, CUrl $url) {
			$size = CWebUser::$data['rows_per_page'];
			$total = min(count($rows), CSettingsHelper::$limit);
			$page = min(max(1, $page), max(1, (int) ceil($total / $size)));
			$rows = array_slice($rows, ($page - 1) * $size, min($size, $total - ($page - 1) * $size), true);
			return ['native_paging' => true, 'url' => $url->args];
		}
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
	class API {
		public static array $groups = [];
		public static array $calls = [];
		public static function HostGroup() { return new self(); }
		public function get(array $options): array {
			self::$calls[] = $options;
			return array_values(array_filter(self::$groups, static function ($group) use ($options) {
				return in_array($group['groupid'], $options['groupids'], true);
			}));
		}
	}
	function getSubGroups(array $ids): array {
		return in_array('1', $ids, true) ? array_merge($ids, ['2']) : $ids;
	}
	if (!function_exists('_')) { function _($text) { return $text; } }
	require __DIR__.'/../includes/NetAppLocationGroups.php';
	require __DIR__.'/../includes/NetAppSearchSnapshot.php';
	require __DIR__.'/../actions/CControllerNetAppList.php';
	require __DIR__.'/../actions/CControllerNetAppListData.php';
	require __DIR__.'/../includes/NetAppRenderer.php';
	require __DIR__.'/../actions/CControllerNetAppView.php';
	require __DIR__.'/../actions/CControllerNetAppSearch.php';

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

	NetAppCollector::$calls = [];
	NetAppCollector::$inventoryCalls = [];
	API::$calls = [];
	$shell = (new CControllerNetAppList(['search' => '  netapp  ', 'page' => 3]))->run()->data;
	expect(NetAppCollector::$calls, [], 'Initial HTML performs no discovery, collection, or problem queries');
	expect(NetAppCollector::$inventoryCalls, [], 'Initial HTML does not collect the counters');
	expect(API::$calls, [], 'Initial HTML without selected groups performs no API queries');
	expect([$shell['search'], $shell['page']], ['netapp', 3], 'Shell preserves filters and requested page');
	expect(isset($shell['snapshots']), false, 'Initial page contains controls only');
	CWebUser::$checks = [];
	$controller = new CControllerNetAppListData();
	$data = $controller->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['40', '10'], 'Natural sort and host ID tie breaker');
	expect(array_keys($data['snapshots']), [40, 10], 'Only current page has snapshots');
	expect($data['total_hosts'], 4, 'Total before pagination');
	expect($data['totals'], ['clusters' => 4, 'nodes' => 8, 'svms' => 12, 'volumes' => 16],
		'Counters include all matching hosts before pagination');
	expect(end(NetAppCollector::$inventoryCalls), ['40', '10', '20', '30'], 'Inventory includes hosts on every page');
	expect(end(NetAppCollector::$summaryInventories)['totals'], $data['totals'], 'Page summaries reuse the full filtered inventory');
	expect($data['page'], 1, 'Default page');
	expect($data['tab'], 'hosts', 'Main default tab is hosts');
	expect($controller->rules['filter_groupids'], 'array_db hstgrp.groupid', 'Group IDs validated against host groups');
	expect($controller->rules['tab'], 'in hosts,problems', 'Main tabs allowlisted');
	expect($controller->csrfDisabled, true, 'Read-only action permits GET');
	expect(CWebUser::$checks, [CRoleHelper::UI_MONITORING_LATEST_DATA], 'Monitoring role required');
	expect($controller->rules['search'], 'string', 'Search validation');
	expect($controller->rules['sortorder'], 'in ASC,DESC', 'Sort order allowlist');
	expect($controller->rules['page'], 'ge 1', 'Positive page validation');

	NetAppCollector::$calls = [];
	$data = (new CControllerNetAppListData(['page' => 999]))->run()->data;
	expect($data['page'], 2, 'Out of range page clamped');
	expect($data['totals']['clusters'], 4, 'Changing pages does not change the counters');
	expect(array_column($data['hosts'], 'hostid'), ['20', '30'], 'Final page selected');
	expect(NetAppCollector::$calls, [['listHosts'], ['summaries', ['20', '30']]],
		'Pagination occurs before expensive collection');
	CSettingsHelper::$limit = 3;
	$data = (new CControllerNetAppListData(['page' => 999]))->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['20'], 'Native pager honors the global search limit before collection');
	expect($data['paging']['native_paging'], true, 'Host table receives the native pagination result');
	CSettingsHelper::$limit = 1000;

	$data = (new CControllerNetAppListData(['search' => '  netapp  ', 'sortorder' => 'DESC']))->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['30', '20'], 'Case-insensitive technical hostname search');
	expect($data['search'], 'netapp', 'Search trimmed');
	expect($data['total_hosts'], 2, 'Filtered count');
	expect($data['totals'], ['clusters' => 2, 'nodes' => 4, 'svms' => 6, 'volumes' => 8], 'Search scopes all four counters');
	$data = (new CControllerNetAppListData(['search' => 'cluster 2']))->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['10', '20'], 'Visible name search');
	NetAppCollector::$calls = [];
	$data = (new CControllerNetAppListData(['search' => 'missing']))->run()->data;
	expect($data['hosts'], [], 'Empty search result');
	expect($data['totals'], ['clusters' => 0, 'nodes' => 0, 'svms' => 0, 'volumes' => 0], 'Empty search returns zero counters');
	expect($data['page'], 1, 'Empty search uses valid page');
	expect(NetAppCollector::$calls, [['listHosts'], ['summaries', []]], 'Empty search collects nothing');
	CWebUser::$data['rows_per_page'] = 0;
	$data = (new CControllerNetAppList())->run()->data;
	expect($data['per_page'], 1, 'Invalid row preference cannot divide by zero');
	CWebUser::$data['rows_per_page'] = 2;

	foreach ([new CControllerNetAppList(), new CControllerNetAppListData(), new CControllerNetAppView(['hostid' => '20'])] as $action) {
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
	expect($data['focus'], '', 'Default detail has no highlighted resource');
	expect($controller->rules['focus'], 'string', 'Resource focus validates as string');
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
		'sortorder' => 'DESC', 'page' => 3, 'focus' => 'netapp-resource-test'
	]))->run()->data;
	expect($data['focus'], 'netapp-resource-test', 'Resource focus reaches permission-scoped view');
	$fragment = (new CControllerNetAppSearch(['hostid' => '20', 'tab' => 'volumes', 'search' => 'latency']))->run()->data;
	expect([$fragment['_fragment'], $fragment['tab'], $fragment['search']], [true, 'volumes', 'latency'],
		'Lightweight search uses the same validated detail inputs');
	denied(new CControllerNetAppSearch(['hostid' => '999']));
	expect([$data['tab'], $data['search'], $data['sort'], $data['sortorder'], $data['page']],
		['volumes', 'vol', 'used', 'DESC', 3], 'Validated detail preferences passed to view');

	foreach ([new CControllerNetAppList(), new CControllerNetAppListData(), new CControllerNetAppView()] as $action) {
		$action->valid = false;
		NetAppCollector::$calls = [];
		CWebUser::$checks = [];
		expect(get_class($action->run()), CControllerResponseFatal::class, 'Invalid input receives fatal response');
		expect(NetAppCollector::$calls, [], 'Invalid input never reaches collector');
		expect(CWebUser::$checks, [], 'Validation precedes permissions');
	}
	\Modules\NetAppMonitoring\Includes\NetAppProblems::$calls = [];
	$data = (new CControllerNetAppView(['hostid' => '20', 'tab' => 'problems', 'search' => '  disk  ']))->run()->data;
	expect(\Modules\NetAppMonitoring\Includes\NetAppProblems::$calls, [['20', 'disk', 2, 1]],
		'Native problems receive authorized host scope, trimmed search and profile row limit');
	expect($data['problem_widget'], ['native_widget' => true], 'Native widget data reaches the view');
	\Modules\NetAppMonitoring\Includes\NetAppProblems::$calls = [];
	(new CControllerNetAppView(['hostid' => '20', 'tab' => 'volumes']))->run();
	expect(\Modules\NetAppMonitoring\Includes\NetAppProblems::$calls, [], 'Other tabs do not prepare the Problems widget');
	// Main page groups are scoped before search, hierarchy aggregation and pagination.
	NetAppCollector::$hosts = [
		['hostid' => '1', 'name' => 'Zulu', 'host' => 'z', 'groupids' => ['1'], 'location_path' => ['CH', 'Zurich']],
		['hostid' => '2', 'name' => 'Alpha', 'host' => 'a', 'groupids' => ['2'], 'location_path' => ['CH', 'Zurich']],
		['hostid' => '3', 'name' => 'Other', 'host' => 'o', 'groupids' => ['3'], 'location_path' => ['US']],
		['hostid' => '4', 'name' => 'Beta', 'host' => 'b', 'groupids' => ['2'], 'location_path' => ['CH', 'Zurich'],
			'inventory' => ['notes' => 'Critical cabinet']]
	];
	API::$groups = [['groupid' => '1', 'name' => 'Sites'], ['groupid' => '3', 'name' => 'Other']];
	NetAppCollector::$calls = [];
	$data = (new CControllerNetAppListData(['filter_groupids' => ['1']]))->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['2', '4'], 'Descendant group hosts sorted and sliced');
	expect($data['total_hosts'], 3, 'Selected parent group includes descendants');
	expect($data['totals']['clusters'], 3, 'Counters include the complete selected group and its descendants');
	expect(NetAppCollector::$calls, [['listHosts', ['1', '2']], ['summaries', ['2', '4']]],
		'Group selection scopes collection before page slicing');
	expect($data['problem_hostids'], ['2', '4', '1'], 'Aggregate problems include all matching pages');
	$location = reset($data['location_groups']);
	expect($location['count'], 3, 'Location parent count includes off-page matching hosts only');
	$group_call = end(NetAppCollector::$groupCalls);
	$group_scope = reset($group_call['groups']);
	expect($group_scope['hostids'], ['2', '4', '1'], 'Group problems include hosts outside current page');
	expect($group_call['calls_before'], [['listHosts', ['1', '2']]], 'Group aggregation precedes snapshots and pagination');
	NetAppCollector::$calls = [];
	$data = (new CControllerNetAppListData(['filter_groupids' => ['999']]))->run()->data;
	expect($data['hosts'], [], 'Inaccessible group cannot widen to all hosts');
	expect($data['totals']['clusters'], 0, 'Inaccessible groups cannot widen the counters');
	expect(NetAppCollector::$calls, [['listHosts', ['0']], ['summaries', []]], 'Inaccessible group uses explicit no-match scope');
	$data = (new CControllerNetAppListData(['search' => 'critical']))->run()->data;
	expect(array_column($data['hosts'], 'hostid'), ['4'], 'Name search retains inventory notes compatibility');
	\Modules\NetAppMonitoring\Includes\NetAppProblems::$calls = [];
	NetAppCollector::$calls = [];
	NetAppCollector::$groupCalls = [];
	$data = (new CControllerNetAppListData(['tab' => 'problems', 'filter_groupids' => ['1'], 'page' => 2]))->run()->data;
	expect(NetAppCollector::$calls, [['listHosts', ['1', '2']]], 'Main Problems skips host snapshot collection');
	expect(NetAppCollector::$groupCalls, [], 'Main Problems avoids redundant location badge queries');
	expect(\Modules\NetAppMonitoring\Includes\NetAppProblems::$calls, [[['2', '4', '1'], '', 2, 2]],
		'Aggregate Problems receives full filtered scope and requested event page');
	expect($data['problem_widget'], ['native_widget' => true, 'page' => 2], 'Aggregate widget reaches main view');
	expect($data['totals'], ['clusters' => 3, 'nodes' => 6, 'svms' => 9, 'volumes' => 12],
		'Problems tab retains the same full-scope inventory counters');
	\Modules\NetAppMonitoring\Includes\NetAppProblems::$calls = [];
	$data = (new CControllerNetAppListData(['tab' => 'problems', 'search' => 'critical', 'page' => 99]))->run()->data;
	expect(\Modules\NetAppMonitoring\Includes\NetAppProblems::$calls, [[['4'], '', 2, 99]],
		'Main Name query scopes hosts, leaving event names unrestricted and event page untruncated');
	expect($data['page'], 99, 'Main response follows event widget page rather than host-list page count');
	echo "Controller checks passed: $count\n";
}
