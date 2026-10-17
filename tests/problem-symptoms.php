<?php
/** Standalone data-contract regression; no problem/event writes or database needed. */
namespace Modules\NetAppMonitoring\Includes {
	class NetAppCollector {
		public static function listHosts(): array { return [['hostid' => '10'], ['hostid' => '20']]; }
	}
}
namespace {
	$ui = $argv[1] ?? '/usr/share/zabbix';
	require $ui.'/include/defines.inc.php';
	require __DIR__.'/../includes/NetAppProblems.php';
	if (!function_exists('_')) { function _($value) { return $value; } }
	if (!function_exists('_s')) { function _s($value, ...$args) { return vsprintf($value, $args); } }
	class CSettingsHelper {
		const SEARCH_LIMIT = 'limit', PROBLEM_ACK_STYLE = 'ack', PROBLEM_UNACK_STYLE = 'unack', BLINK_PERIOD = 'blink';
		static $limit = 100;
		static function get($key) { return $key === self::SEARCH_LIMIT ? self::$limit : 0; }
	}
	class CRoleHelper {
		const UI_MONITORING_PROBLEMS = 'p', ACTIONS_ADD_PROBLEM_COMMENTS = 'c', ACTIONS_CHANGE_SEVERITY = 's',
			ACTIONS_ACKNOWLEDGE_PROBLEMS = 'a', ACTIONS_CLOSE_PROBLEMS = 'x', ACTIONS_SUPPRESS_PROBLEMS = 'sup',
			ACTIONS_CHANGE_PROBLEM_RANKING = 'rank';
	}
	class CWebUser {
		static $data = ['rows_per_page' => 3];
		static function checkAccess($rule) { return true; }
	}
	function getTriggersHostsList($triggers) {
		return array_map(static function ($trigger) { return $trigger['hosts']; }, $triggers);
	}
	class CScreenProblem {
		static $calls = [];
		static $enriched = [];
		static $fixture = [];
		static function getData($filter, $limit) {
			self::$calls[] = $filter;
			$problems = [];
			$triggers = [];
			foreach (self::$fixture as $problem) {
				if (!in_array($problem['hostid'], $filter['hostids'], true)
						|| (!$filter['show_suppressed'] && $problem['suppressed'])
						|| (!$filter['show_symptoms'] && $problem['cause_eventid'] !== '0')
						|| ($filter['name'] !== '' && stripos($problem['name'], $filter['name']) === false)) {
					continue;
				}
				$problems[$problem['eventid']] = $problem;
				$triggers[$problem['objectid']] = ['hosts' => [['hostid' => $problem['hostid']]]];
			}
			krsort($problems, SORT_NUMERIC);
			return ['problems' => array_slice($problems, 0, $limit + 1, true), 'triggers' => $triggers];
		}
		static function sortData($data, ...$args) { return $data; }
		static function makeData($data, ...$args) {
			self::$enriched[] = array_keys($data['problems']);
			$data += ['users' => [], 'correlations' => [], 'actions' => ['messages' => []]];
			$triggerids = [];
			foreach ($data['problems'] as $problem) {
				$id = $problem['eventid'];
				$data['users'][$id] = ['userid' => $id];
				$data['correlations'][$id] = ['correlationid' => $id];
				$data['actions']['messages'][$id] = ['eventid' => $id];
				$triggerids[$problem['objectid']] = true;
			}
			$data['triggers'] = array_intersect_key($data['triggers'], $triggerids);
			return $data;
		}
	}
	foreach ([
		['112', '10', '110', 'match child', false], ['111', '10', '999', 'match orphan', false],
		['110', '10', '0', 'match cause', false], ['109', '10', '110', 'match next-page child', false],
		['108', '10', '0', 'match second cause', false], ['107', '10', '108', 'match second child', false],
		['106', '20', '1000', 'match other NetApp orphan', false], ['105', '999', '0', 'match unrelated host', false],
		['104', '10', '110', 'match suppressed child', true], ['103', '10', '110', 'different search child', false]
	] as [$eventid, $hostid, $cause, $name, $suppressed]) {
		CScreenProblem::$fixture[] = ['eventid' => $eventid, 'hostid' => $hostid, 'cause_eventid' => $cause,
			'objectid' => 'tr'.$eventid, 'clock' => (int) $eventid, 'name' => $name, 'suppressed' => $suppressed];
	}
	$checks = 0;
	function check($condition, $message) {
		global $checks;
		if (!$condition) { throw new RuntimeException($message); }
		$checks++;
	}
	function flattened($data) {
		$ids = [];
		foreach ($data['problems'] as $problem) {
			$ids[] = (string) $problem['eventid'];
			foreach ($problem['symptoms'] as $symptom) { $ids[] = (string) $symptom['eventid']; }
		}
		sort($ids, SORT_NUMERIC);
		return $ids;
	}
	$first = \Modules\NetAppMonitoring\Includes\NetAppProblems::forHosts(['10', '999'], 'match');
	check(count(CScreenProblem::$calls) === 1, 'Grouping must not perform an unscoped child query.');
	check(CScreenProblem::$calls[0]['hostids'] === ['10'], 'Selected hosts must intersect eligible hosts.');
	check(CScreenProblem::$calls[0]['show_symptoms'] === true, 'Orphan symptoms must remain discoverable.');
	check(CScreenProblem::$calls[0]['show_suppressed'] === false, 'Suppression filter must remain in force.');
	check(CScreenProblem::$calls[0]['name'] === 'match', 'Search must filter events before pagination.');
	check($first['total'] === 6 && $first['pages'] === 2 && $first['per_page'] === 3, 'Pagination must count events.');
	check($first['page_eventids'] === [112,111,110], 'Native event page membership must be retained.');
	check(flattened($first) === ['110','111','112'], 'Every page event must render exactly once.');
	check(!isset($first['problems'][112]), 'A nested symptom must not be duplicated as a top-level row.');
	check(isset($first['problems'][111]), 'A symptom with an out-of-scope cause must remain standalone.');
	check($first['problems'][110]['symptom_count'] === 1, 'Counter must reflect actual scoped expandable rows.');
	check((string) $first['problems'][110]['symptoms'][0]['cause_eventid'] === '110', 'Native nested-row relation must be retained.');
	check($first['show_three_columns'] && $first['show_two_columns'], 'Native cause/symptom columns must be enabled.');
	check(strpos($first['info'], 'on this page') !== false, 'Page-local symptom count must be explained.');
	foreach (['users','correlations','triggers','triggers_hosts'] as $key) {
		check(count($first[$key]) === 3, 'Nested event '.$key.' must remain available to native rendering.');
	}
	check(isset($first['actions']['messages'][112]), 'Nested event action metadata must remain available.');
	check(CScreenProblem::$enriched[0] === [112,111,110], 'Enrichment must include nested symptoms before grouping.');
	$second = \Modules\NetAppMonitoring\Includes\NetAppProblems::data('10', 'match', null, 2);
	check(flattened($second) === ['107','108','109'], 'Second page must contain the remaining unique events.');
	check(isset($second['problems'][109]), 'Symptom whose cause is on another page must stay visible.');
	check(!array_intersect(flattened($first), flattened($second)), 'No events may repeat across pages.');
	$all = \Modules\NetAppMonitoring\Includes\NetAppProblems::forHosts(['10','20'], 'match', 20);
	check(count($all['page_eventids']) === 7 && isset($all['problems'][106]), 'NetApp orphan must survive aggregate scope.');
	check($all['problems'][110]['symptom_count'] === 2, 'Counter must exclude suppressed and nonmatching children.');
	$orphan_search = \Modules\NetAppMonitoring\Includes\NetAppProblems::data('10', 'next-page child');
	check(isset($orphan_search['problems'][109]) && count($orphan_search['page_eventids']) === 1,
		'Search matching only a symptom must not discard it.');
	CSettingsHelper::$limit = 2;
	$limited = \Modules\NetAppMonitoring\Includes\NetAppProblems::data('10', 'match');
	check($limited['total'] === 2 && $limited['has_more'] && count($limited['page_eventids']) === 2,
		'Search limit must still cap event pagination and expose truncation.');
	check(flattened($limited) === ['111','112'], 'Limited orphans must remain independent, visible events.');
	$empty = \Modules\NetAppMonitoring\Includes\NetAppProblems::forHosts([], 'match');
	check(!$empty['problems'] && end(CScreenProblem::$calls)['hostids'] === ['0'], 'Empty scope must never widen.');
	echo 'Problem cause/symptom checks passed: '.$checks."\n";
}
