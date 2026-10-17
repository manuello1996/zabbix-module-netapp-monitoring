<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Includes;

use CRoleHelper;
use CScreenProblem;
use CSettingsHelper;
use CWebUser;

/** Prepare Zabbix's own Problems widget, scoped to eligible NetApp hosts. */
class NetAppProblems {
	public static function data(string $hostid, string $search = '', ?int $lines = null, int $page = 1): array {
		return self::forHosts([$hostid], $search, $lines, $page);
	}

	public static function forHosts(array $hostids, string $search = '', ?int $lines = null, int $page = 1): array {
		$eligible = array_column(NetAppCollector::listHosts(), 'hostid');
		// An empty native host filter means ALL accessible hosts. Always use a sentinel.
		$hostids = array_values(array_intersect(array_map('strval', $hostids), $eligible)) ?: ['0'];
		$lines = max(1, $lines ?? (int) (CWebUser::$data['rows_per_page'] ?? 50));
		$limit = max(1, (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT));
		$filter = [
			'show' => TRIGGERS_OPTION_RECENT_PROBLEM,
			'groupids' => null, 'exclude_groupids' => null, 'hostids' => $hostids,
			'name' => $search, 'severities' => [], 'evaltype' => TAG_EVAL_TYPE_AND_OR,
			'tags' => [], 'show_symptoms' => false, 'show_suppressed' => false,
			'acknowledgement_status' => ZBX_ACK_STATUS_ALL, 'acknowledged_by_me' => 0,
			'show_opdata' => OPERATIONAL_DATA_SHOW_SEPARATELY
		];
		$data = CScreenProblem::getData($filter, $limit);
		$data = CScreenProblem::sortData($data, $limit, 'clock', ZBX_SORT_DOWN);
		$has_more = count($data['problems']) > $limit;
		$total = min($limit, count($data['problems']));
		$pages = max(1, (int) ceil($total / $lines));
		$page = min(max(1, $page), $pages);
		$data['problems'] = array_slice($data['problems'], ($page - 1) * $lines, min($lines, $total - ($page - 1) * $lines), true);
		$data = CScreenProblem::makeData($data, [
			'show' => TRIGGERS_OPTION_RECENT_PROBLEM, 'details' => 0,
			'show_opdata' => OPERATIONAL_DATA_SHOW_SEPARATELY
		]);
		$data += ['show_three_columns' => false, 'show_two_columns' => false];
		if ($data['problems']) {
			$data['triggers_hosts'] = getTriggersHostsList($data['triggers']);
			foreach ($data['problems'] as &$problem) {
				$problem['symptom_count'] = 0;
				$problem['symptoms'] = [];
			}
			unset($problem);
		}
		return $data + [
			'has_more' => $has_more,
			'page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => $lines,
			'fields' => [
				'show' => TRIGGERS_OPTION_RECENT_PROBLEM, 'show_tags' => SHOW_TAGS_NONE,
				'show_timeline' => ZBX_TIMELINE_ON, 'highlight_row' => ZBX_HIGHLIGHT_OFF,
				'tags' => [], 'tag_name_format' => TAG_NAME_FULL, 'tag_priority' => '',
				'show_opdata' => OPERATIONAL_DATA_SHOW_SEPARATELY
			],
			'info' => $has_more ? _s('Results are limited to the latest %1$s matching problems. Refine your search or open all problems.', $limit) : '',
			'sortfield' => 'clock', 'sortorder' => ZBX_SORT_DOWN,
			'config' => [
				'problem_ack_style' => CSettingsHelper::get(CSettingsHelper::PROBLEM_ACK_STYLE),
				'problem_unack_style' => CSettingsHelper::get(CSettingsHelper::PROBLEM_UNACK_STYLE),
				'blink_period' => CSettingsHelper::get(CSettingsHelper::BLINK_PERIOD)
			],
			'allowed' => [
				'ui_problems' => CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS),
				'add_comments' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS),
				'change_severity' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY),
				'acknowledge' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS),
				'close' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS),
				'suppress_problems' => CWebUser::checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS),
				'rank_change' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
			]
		];
	}
}
