<?php
require __DIR__.'/../includes/NetAppLocationGroups.php';
use Modules\NetAppMonitoring\Includes\NetAppLocationGroups as G;
$checks = 0;
function check($actual, $expected, string $message): void {
	global $checks;
	$checks++;
	if ($actual !== $expected) { throw new RuntimeException($message.': '.var_export($actual, true)); }
}
check(G::path([['macro' => G::MACRO, 'value' => ' CH, Zurich, , Rack 2 ']]), ['CH', 'Zurich', 'Rack 2'], 'Location macro trims empty components');
foreach ([1, 2] as $secret_type) {
	check(G::path([['macro' => G::MACRO, 'value' => 'secret-location', 'type' => $secret_type]]), [], 'Secret and vault macros never expose labels');
}
check(G::path([['macro' => '{$NETAPP.PASSWORD}', 'value' => 'secret']]), [], 'Unrelated macros ignored');
check(G::ancestors(['CH', 'Zurich']), [['CH'], ['CH', 'Zurich']], 'Complete prefix hierarchy');
check(G::key(['a,b', 'c']) === G::key(['a', 'b,c']), false, 'Group identity cannot collide on separators');
$hosts = [
	'1' => ['hostid' => '1', 'name' => 'Zulu', 'location_path' => ['CH', 'Zurich']],
	'2' => ['hostid' => '2', 'name' => 'Alpha', 'location_path' => ['CH', 'Zurich']],
	'3' => ['hostid' => '3', 'name' => 'AAA', 'location_path' => []],
	'4' => ['hostid' => '4', 'name' => 'Beta', 'location_path' => ['CH', 'Bern']]
];
check(array_column(G::sort($hosts), 'hostid'), ['4', '2', '1', '3'], 'Tree sort before host names; ungrouped last');
check(array_column(G::sort($hosts, 'DESC'), 'hostid'), ['4', '1', '2', '3'], 'Reverse order affects host names, retaining tree and ungrouped placement');
$groups = G::groups($hosts);
check($groups[G::key(['CH'])]['count'], 3, 'Parent count includes full subtree');
check($groups[G::key(['CH', 'Zurich'])]['hostids'], ['2', '1'], 'Leaf IDs retain sorted scope');
check(count($groups), 3, 'No artificial group for missing locations');
$headers = G::headers([$hosts['2'], $hosts['1']]);
check(count($headers['2']), 2, 'Ancestors repeat at page start');
check($headers['1'], [], 'Adjacent sibling host does not repeat ancestor rows');
check(G::groups([]), [], 'Empty scope has no stale groups');
$problems = [
	['eventid' => '10', 'objectid' => '100', 'severity' => '4'],
	['eventid' => '10', 'objectid' => '100', 'severity' => '4'],
	['eventid' => '11', 'objectid' => '101', 'severity' => '0'],
	['eventid' => '12', 'objectid' => '102', 'severity' => '4'],
	['eventid' => '13', 'objectid' => '103', 'severity' => '5']
];
$triggers = [
	['triggerid' => '100', 'hosts' => [['hostid' => '1'], ['hostid' => '2'], ['hostid' => '4']]],
	['triggerid' => '101', 'hosts' => [['hostid' => '1']]],
	['triggerid' => '102', 'hosts' => [['hostid' => '4']]],
	['triggerid' => '103', 'hosts' => [['hostid' => 'OUTSIDE']]]
];
$enriched = G::withProblems($groups, $problems, $triggers);
check($enriched[G::key(['CH'])]['problems'], [4 => 2, 0 => 1], 'Parent deduplicates shared hosts and duplicate event IDs');
check($enriched[G::key(['CH', 'Zurich'])]['problems'], [4 => 1, 0 => 1], 'Leaf counts include off-page hosts and severity zero');
check($enriched[G::key(['CH', 'Bern'])]['problems'], [4 => 2], 'Cross-leaf trigger counted once in each affected leaf');
check($enriched[G::key(['CH'])]['problems_available'], true, 'Successful metadata exposes counts');
foreach ([[false, []], [[], false]] as [$events, $relations]) {
	$unavailable = G::withProblems($groups, $events, $relations);
	check($unavailable[G::key(['CH'])]['problems_available'], false, 'Denied metadata marks badges unavailable');
	check($unavailable[G::key(['CH'])]['problems'], [], 'Unavailable metadata cannot expose stale prior counters');
}
check(G::withProblems($groups, [], [])[G::key(['CH'])]['problems'], [], 'Known empty event list produces no badges');
echo "Location grouping checks passed: $checks\n";
