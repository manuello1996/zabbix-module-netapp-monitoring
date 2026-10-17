<?php
namespace Modules\NetAppMonitoring\Includes {
	class NetAppCollector {
		public static function listHosts(): array { return [['hostid' => '10'], ['hostid' => '20']]; }
	}
}
namespace {
	$ui = $argv[1] ?? '/usr/share/zabbix';
	require $ui.'/include/defines.inc.php';
	require __DIR__.'/../includes/NetAppProblems.php';
	class CSettingsHelper {
		const SEARCH_LIMIT = 'limit', PROBLEM_ACK_STYLE = 'ack', PROBLEM_UNACK_STYLE = 'unack', BLINK_PERIOD = 'blink';
		static function get($key) { return $key === self::SEARCH_LIMIT ? 100 : 0; }
	}
	class CRoleHelper {
		const UI_MONITORING_PROBLEMS = 'p', ACTIONS_ADD_PROBLEM_COMMENTS = 'c', ACTIONS_CHANGE_SEVERITY = 's',
			ACTIONS_ACKNOWLEDGE_PROBLEMS = 'a', ACTIONS_CLOSE_PROBLEMS = 'x', ACTIONS_SUPPRESS_PROBLEMS = 'sup',
			ACTIONS_CHANGE_PROBLEM_RANKING = 'rank';
	}
	class CWebUser {
		static $data = ['rows_per_page' => 2];
		static function checkAccess($rule) { return true; }
	}
	class CScreenProblem {
		static array $filter = [];
		static function getData($filter, $limit) { self::$filter = $filter; return ['problems' => [], 'triggers' => []]; }
		static function sortData($data, ...$args) { return $data; }
		static function makeData($data, ...$args) { return $data; }
	}
	$checks = 0;
	foreach ([
		[[], ['0']], [['999'], ['0']], [['10', '999', '20'], ['10', '20']],
		[[20], ['20']], [['0'], ['0']]
	] as [$requested, $expected]) {
		\Modules\NetAppMonitoring\Includes\NetAppProblems::forHosts($requested);
		if (CScreenProblem::$filter['hostids'] !== $expected) { throw new \RuntimeException('Aggregate host intersection widened scope'); }
		$checks++;
	}
	\Modules\NetAppMonitoring\Includes\NetAppProblems::data('999');
	if (CScreenProblem::$filter['hostids'] !== ['0']) { throw new \RuntimeException('Detail adapter widened invalid host'); }
	echo 'Problem scope checks passed: '.($checks + 1)."\n";
}
