<?php
/**
 * Render against genuine Zabbix 7.0 HTML classes, without a database/session.
 * Run: php tests/views-smoke.php /path/to/zabbix/ui [optional-output-directory]
 */
$zabbix = $argv[1] ?? getenv('ZABBIX_UI_PATH');
if (!$zabbix || !is_file($zabbix.'/include/defines.inc.php')) {
	fwrite(STDERR, "Provide a Zabbix 7.0 ui directory as argument or ZABBIX_UI_PATH.\n");
	exit(2);
}
set_error_handler(static function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});
if (!function_exists('_')) { function _($text) { return $text; } }
function _s($text, ...$args) { return sprintf($text, ...$args); }
function _x($text, $context) { return $text; }
function _n($one, $many, $count) { return $count === 1 ? $one : $many; }
function get_prepared_messages($options = []) { return ''; }
function zbx_cleanHashes(&$value) { if (is_array($value)) { $value = array_values($value); } return $value; }
function zbx_formatDomId($value) { return str_replace(['[', ']'], ['_', ''], $value); }
function zbx_date2str($format, $time = null, $timezone = null) { return date('Y-m-d H:i:s', $time ?? time()); }
function BULLET() { return '•'; }
// Application/session dependencies only; all HTML components remain real Zabbix classes.
class CViewHelper { public static function loadLayoutMode(): int { return ZBX_LAYOUT_NORMAL; } }
class CSettingsHelper {
	public const SEVERITY_NAME_0 = 'Not classified';
	public const SEVERITY_NAME_1 = 'Information';
	public const SEVERITY_NAME_2 = 'Warning';
	public const SEVERITY_NAME_3 = 'Average';
	public const SEVERITY_NAME_4 = 'High';
	public const SEVERITY_NAME_5 = 'Disaster';
	public static function get($setting) { return $setting; }
}
require $zabbix.'/include/defines.inc.php';
require $zabbix.'/include/translateDefines.inc.php';
require $zabbix.'/include/js.inc.php';
require $zabbix.'/include/classes/core/CAutoloader.php';
$loader = new CAutoloader();
$paths = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zabbix.'/include/classes',
	FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
	if ($entry->isDir()) { $paths[] = $entry->getPathname(); }
}
$loader->addNamespace('', $paths);
$loader->addNamespace('Zabbix\\Core', [$zabbix.'/include/classes/core']);
$loader->addNamespace('Widgets\\Problems', [$zabbix.'/widgets/problems']);
$loader->addNamespace('Modules\\NetAppMonitoring', [dirname(__DIR__)]);
$loader->register();

class NetAppViewHarness {
	public static array $scripts = [];
	private string $name;
	public function addJsFile(string $script): self {
		self::$scripts[$this->name][$script] = true;
		return $this;
	}
	public function render(string $name, array $data): string {
		$this->name = $name;
		ob_start();
		try { require __DIR__.'/../views/'.$name.'.php'; return ob_get_contents(); }
		finally { ob_end_clean(); }
	}
}
function render(string $name, array $data): string {
	return (new NetAppViewHarness())->render($name, $data);
}
function check(bool $ok, string $message): void {
	global $checks;
	$checks++;
	if (!$ok) { throw new RuntimeException($message); }
}
$checks = 0;
$module_relative = basename(dirname(__DIR__, 2)).'/'.basename(dirname(__DIR__));
$manager = new CModuleManager(dirname(__DIR__, 3));
$manifest = $manager->addModule($module_relative, '1');
check($manifest !== null, 'Real Zabbix module manager accepts manifest');
check($manifest['namespace'] === 'Modules\\NetAppMonitoring', 'Module namespace resolves correctly');
foreach ($manifest['actions'] as $action) {
	check(is_file(__DIR__.'/../actions/'.$action['class'].'.php'), 'Action implementation resolves');
	check(is_file(__DIR__.'/../views/'.$action['view'].'.php'), 'Action view resolves');
}
foreach ($manifest['assets']['css'] as $asset) {
	check(is_file(__DIR__.'/../assets/css/'.$asset), 'CSS asset resolves: '.$asset);
}
$host = ['hostid' => '20', 'name' => '<script>alert("host")</script>', 'host' => 'netapp-test', 'status' => '0'];
$host['inventory'] = ['notes' => "Fixture inventory notes\n<script>alert(\"notes\")</script>"];
function item(string $name, string $value, string $units = '', int $type = 1): array {
	return ['itemid' => '1001', 'name' => $name, 'lastvalue' => $value, 'lastclock' => 1792177200,
		'state' => '0', 'status' => '0', 'value_type' => $type, 'units' => $units];
}
$metric = item('<script>alert("metric")</script>', '<img src=x onerror=alert(1)>');
$groups = ['nodes', 'svms', 'volumes', 'luns', 'ethernet_ports', 'fc_ports', 'disks', 'chassis', 'frus'];
$snapshot = ['cluster' => ['name' => item('Cluster name', 'Cluster test'), 'status' => item('Health', 'ok'),
	'version' => item('Version', '9.16'), 'statistics.read' => item('Read IOPS', '25', '', 3)],
	'entities' => [], 'warnings' => ['<script>alert("warning")</script>'], 'lastclock' => 1792177200,
	'problems' => [['eventid' => '77', 'objectid' => '88', 'clock' => 1792177200,
		'severity' => '4', 'name' => '<svg onload=alert(1)>', 'acknowledged' => '0']]];
foreach ($groups as $group) {
	$snapshot['entities'][$group] = [['id' => 'id-1', 'name' => '<script>alert("entity")</script>',
		'parent' => 'svm-test', 'capacity' => ['size' => 1024, 'used' => 512, 'percent' => 50],
		'metrics' => ['state' => item('State', 'online'), 'comment' => $metric]]];
}
$snapshot['entities']['volumes'][0]['metrics']['statistics.latency.read'] = item(
	$snapshot['entities']['volumes'][0]['name'].': Volume latency, read', '0.8', '!ms', 0);
$base = ['host' => $host, 'snapshot' => $snapshot, 'tab' => 'overview', 'search' => '',
	'sort' => 'name', 'sortorder' => 'ASC', 'page' => 1, 'per_page' => 1];
$base['problem_widget'] = [
	'problems' => [], 'triggers' => [], 'show_three_columns' => false, 'show_two_columns' => false,
	'fields' => ['show' => TRIGGERS_OPTION_RECENT_PROBLEM, 'show_tags' => SHOW_TAGS_NONE,
		'show_timeline' => ZBX_TIMELINE_ON, 'highlight_row' => ZBX_HIGHLIGHT_OFF,
		'show_opdata' => OPERATIONAL_DATA_SHOW_SEPARATELY],
	'sortfield' => 'clock', 'sortorder' => ZBX_SORT_DOWN, 'info' => ''
];
$outputs = [];
foreach (['overview', 'nodes', 'svms', 'volumes', 'luns', 'ethernet', 'fc', 'hardware', 'performance', 'problems'] as $tab) {
	$html = render('netapp.monitoring.view', array_replace($base, ['tab' => $tab]));
	check(strpos($html, '<main>') !== false, "$tab renders a page");
	check(substr_count($html, 'id="netapp-host-summary"') === 1,
		"$tab provides a replaceable status, banner and counters region");
	check(strpos($html, '<script>') === false, "$tab escapes script names and warnings");
	check(strpos($html, '<img src=x') === false && strpos($html, '<svg onload') === false,
		"$tab escapes values and problem names");
	check(strpos($html, 'aria-current="page"') !== false, "$tab marks active navigation");
	check(strpos($html, 'Fixture inventory notes') !== false, "$tab shows inventory notes");
	check((strpos($html, 'data-netapp-search="1"') !== false) === ($tab !== 'overview'), "$tab search form visibility");
	check(substr_count($html, 'id="netapp-search-results"') === ($tab === 'overview' ? 0 : 1), "$tab results region");
	check(strpos($html, '>Apply<') === false, "$tab needs no Apply button");
	if ($tab === 'volumes') {
		check(strpos($html, 'colspan="5"') !== false && strpos($html, 'netapp-monitoring-metrics-row') !== false,
			"$tab expands metrics across the full inventory width");
		check(strpos($html, 'data-netapp-metrics-toggle="1"') !== false
			&& strpos($html, 'aria-expanded="false"') !== false && strpos($html, 'hidden="hidden"') !== false,
			"$tab has inline metrics controls with initially hidden panels");
	}
	elseif (in_array($tab, ['nodes', 'svms', 'luns', 'ethernet', 'fc', 'hardware'], true)) {
		check(strpos($html, 'data-netapp-metrics-toggle') === false,
			"$tab displays all simple resource metrics directly without a redundant expander");
	}
	check(strpos($html, '9.16') !== false, "$tab preserves ONTAP version string");
	check(strpos($html, 'Latest sample') === false, "$tab omits latest sample from the top bar");
	if (in_array($tab, ['nodes', 'svms', 'volumes', 'luns', 'ethernet', 'fc', 'hardware'], true)) {
		check(strpos($html, 'class="table-paging"') !== false, "$tab uses the native table pager");
	}
	$outputs[$tab] = $html;
}
foreach (['Recovery time', 'Status', 'Operational data', 'Duration', 'Update', 'Actions', 'dashboard-widget-problems'] as $text) {
	check(strpos($outputs['problems'], $text) !== false, 'Native Problems widget includes '.$text);
}
$paged = $base;
$paged['tab'] = 'problems';
$paged['search'] = 'disk';
$paged['problem_widget'] += ['total' => 12, 'page' => 1, 'pages' => 2, 'per_page' => 10, 'has_more' => false];
$pager_table = static function (array $data): string {
	$widget = $data['problem_widget'];
	return (string) (new CTableInfo())->setHeader(['Problem'])->addRow(['Disk fault'])
		->setPageNavigation(Modules\NetAppMonitoring\Includes\NetAppPager::forTotal(
			$widget['page'], $widget['total'], $widget['per_page'],
			Modules\NetAppMonitoring\Includes\NetAppRenderer::url(['hostid' => '20', 'tab' => 'problems', 'search' => $data['search']])
		));
};
$paged_html = $pager_table($paged);
check(strpos($paged_html, 'class="table-paging"') !== false, 'Problems uses native Zabbix pager styling');
check(strpos($paged_html, 'Displaying 1 to 10 of 12 found') !== false, 'Native pager displays the matching result range');
check(strpos($paged_html, 'Go to next page, 2') !== false, 'Native pager includes next-page arrow');
check(strpos($paged_html, 'search=disk&amp;page=2') !== false, 'Native pager retains the active search');
$paged['problem_widget']['page'] = 2;
$paged_html = $pager_table($paged);
check(strpos($paged_html, 'Displaying 11 to 12 of 12 found') !== false, 'Native pager reports final-page range');
check(strpos($paged_html, 'Go to previous page, 1') !== false, 'Native pager includes previous-page arrow');
$previous_errors = libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($outputs['volumes']);
$xpath = new DOMXPath($dom);
$toggle = $xpath->query('//button[@data-netapp-metrics-toggle]')->item(0);
$row = $xpath->query('ancestor::tr', $toggle)->item(0);
$panel = $xpath->query('following-sibling::tr[1]', $row)->item(0);
check($panel->getAttribute('id') === $toggle->getAttribute('aria-controls'), 'Inline toggle controls its own adjacent panel');
check($panel->hasAttribute('hidden'), 'Collapsed metrics do not occupy a visible extra row');
check($xpath->query('.//td', $row)->length === 5, 'Metrics control belongs to the resource summary row');
check($xpath->query('./td[2]/span', $row)->item(0)->textContent === 'svm-test / volumes',
	'Parent and type share one inline element');
check($xpath->query('./td[2]//div', $row)->length === 0, 'Parent/type has no stacked block elements');
check($xpath->query('.//td[text()="Volume latency, read"]', $panel)->length === 1,
	'Expanded metric label omits the exact object prefix');
check(Modules\NetAppMonitoring\Includes\NetAppRenderer::metricName('a:b: State', 'a:b') === 'State',
	'Object names containing colons are stripped exactly');
check(Modules\NetAppMonitoring\Includes\NetAppRenderer::metricName('Other: State', 'a:b') === 'Other: State',
	'Unrelated prefixes are preserved');
libxml_clear_errors();
libxml_use_internal_errors($previous_errors);
$latest_link = (string) Modules\NetAppMonitoring\Includes\NetAppRenderer::inlineValue(item('Read latency', '0.8', '!ms', 0));
check(strpos($latest_link, '&quot;type&quot;:&quot;item&quot;') !== false
	&& strpos($latest_link, '&quot;context&quot;:&quot;host&quot;') !== false,
	'Latest values open native host item menus');
check(strpos($latest_link, 'history.php') === false && strpos($latest_link, 'aria-haspopup="true"') !== false,
	'Latest value opens an accessible menu instead of navigating to history');
$no_parent = $base;
$no_parent['tab'] = 'nodes';
$no_parent['snapshot']['entities']['nodes'][0]['parent'] = '';
$without_parent = render('netapp.monitoring.view', $no_parent);
check(strpos($without_parent, '<span class="nowrap">nodes</span>') !== false,
	'An absent parent displays just the resource type');
$list = ['hosts' => [$host], 'snapshots' => ['20' => $snapshot], 'total_hosts' => 2,
	'search' => '"><script>alert(1)</script>', 'page' => 1, 'per_page' => 1, 'sort' => 'name', 'sortorder' => 'ASC'];
$list += ['tab' => 'hosts', 'groupids' => ['31'], 'groups' => [['groupid' => '31', 'name' => '<script>Group</script>']],
	'problem_widget' => $base['problem_widget'], 'problem_hostids' => ['20']];
$list['hosts'][0]['location_path'] = ['CH', '<script>Zurich</script>'];
$offpage = ['hostid' => '21', 'name' => 'Off-page host', 'location_path' => $list['hosts'][0]['location_path']];
$list['location_groups'] = Modules\NetAppMonitoring\Includes\NetAppLocationGroups::withProblems(
	Modules\NetAppMonitoring\Includes\NetAppLocationGroups::groups(array_merge($list['hosts'], [$offpage])),
	[['eventid' => '77', 'objectid' => '88', 'severity' => '4'], ['eventid' => '78', 'objectid' => '89', 'severity' => '4']],
	[['triggerid' => '88', 'hosts' => [['hostid' => '20']]], ['triggerid' => '89', 'hosts' => [['hostid' => '21']]]]
);
$outputs['list'] = render('netapp.monitoring.list', $list);
foreach (['netapp.monitoring.list', 'netapp.monitoring.view'] as $view_name) {
	foreach (['multilineinput.js', 'items.js', 'class.tagfilteritem.js'] as $dependency) {
		check(isset(NetAppViewHarness::$scripts[$view_name][$dependency]),
			$view_name.' loads native item dialog dependency '.$dependency);
	}
}
check(strpos($outputs['list'], '<script>') === false, 'List escapes hostile host/search');
check(strpos($outputs['list'], 'Fixture inventory notes') !== false, 'List displays inventory notes');
check(strpos($outputs['list'], 'Go to next page, 2') !== false, 'List has native next page link');
check(strpos($outputs['list'], 'class="table-paging"') !== false, 'Host list uses the native table pager');
check(strpos($outputs['list'], 'data-netapp-main-filter="1"') !== false, 'Main filter uses native CFilter with live search hook');
check(strpos($outputs['list'], 'filter_groupids[]') !== false, 'Native group multiselect is available');
check(strpos($outputs['list'], 'data-netapp-host-link="1"') !== false && strpos($outputs['list'], 'data-menu-popup=') !== false,
	'Host names support detail navigation and native contextual menu');
check(strpos($outputs['list'], 'data-location-toggle=') !== false && strpos($outputs['list'], 'colspan="8"') !== false,
	'Location labels span the columns before Problems');
check(strpos($outputs['list'], 'problem-icon-list-item') !== false, 'Host problems use native severity badges');
$previous_errors = libxml_use_internal_errors(true);
$group_dom = new DOMDocument(); $group_dom->loadHTML($outputs['list']);
$group_xpath = new DOMXPath($group_dom);
$group_row = $group_xpath->query('//tr[@data-location-key]')->item(0);
check($group_xpath->query('./td', $group_row)->length === 3, 'Group row keeps label, Problems and Actions columns');
check($group_xpath->query('./td[1]', $group_row)->item(0)->getAttribute('colspan') === '8',
	'Group Problems badges align with the ninth host-table column');
$problem_link = $group_xpath->query('./td[2]/a', $group_row)->item(0);
check(strpos($problem_link->getAttribute('href'), 'hostids%5B0%5D=20') !== false
	&& strpos($problem_link->getAttribute('href'), 'hostids%5B1%5D=21') !== false,
	'Group problem link includes off-page descendants');
check(trim($problem_link->textContent) === '2', 'Group severity badge includes off-page events');
libxml_clear_errors(); libxml_use_internal_errors($previous_errors);
$outputs['main-problems'] = render('netapp.monitoring.list', array_replace($list, ['tab' => 'problems']));
check(strpos($outputs['main-problems'], 'dashboard-widget-problems') !== false, 'Main Problems tab uses native widget');
check(strpos($outputs['main-problems'], 'hostids%5B0%5D=20') !== false, 'Main open Problems link keeps complete eligible scope');
check(strpos($outputs['main-problems'], 'data-location-toggle=') === false, 'Main Problems omits the host inventory table');
$outputs['empty-list'] = render('netapp.monitoring.list', array_replace($list, ['hosts' => [], 'snapshots' => [], 'total_hosts' => 0]));
check(strpos($outputs['empty-list'], 'No matching NetApp hosts') !== false, 'Empty list explains host scope');
$empty = $snapshot;
$empty['cluster'] = [];
$empty['problems'] = [];
$empty['lastclock'] = 0;
foreach ($groups as $group) { $empty['entities'][$group] = []; }
foreach (['overview', 'volumes', 'hardware', 'performance', 'problems'] as $tab) {
	$html = render('netapp.monitoring.view', array_replace($base, ['tab' => $tab, 'snapshot' => $empty]));
	check(strpos($html, '<main>') !== false, "Empty $tab renders");
}
// Filtering and pagination should remove rows, including their metric details.
$filtered = render('netapp.monitoring.view', array_replace($base, ['tab' => 'volumes', 'search' => 'NO_MATCH']));
check(strpos($filtered, 'No discovered resources') !== false, 'Inventory filtering applies');
check(strpos($filtered, 'alert(&quot;entity&quot;)') === false, 'Filtered rows are absent');
foreach (['performance', 'problems'] as $tab) {
	$filtered = render('netapp.monitoring.view', array_replace($base, ['tab' => $tab, 'search' => 'NO_MATCH']));
	check(strpos($filtered, 'alert(&quot;metric&quot;)') === false && strpos($filtered, '&lt;svg onload') === false,
		"$tab applies its query to metrics/problems");
}
$unavailable = $snapshot;
$unavailable['problems'] = [];
$unavailable['problems_available'] = false;
$problem_html = render('netapp.monitoring.view', array_replace($base, ['tab' => 'problems', 'snapshot' => $unavailable]));
check(strpos($problem_html, 'Problem information is unavailable') !== false, 'Problem API failure is visible');
check(strpos($problem_html, 'No active problems found') === false, 'Problem API failure never claims healthy state');
$unavailable_list = render('netapp.monitoring.list', array_replace($list, ['snapshots' => ['20' => $unavailable]]));
check(strpos($unavailable_list, 'Unavailable') !== false, 'List marks unavailable problem count');

// Collection failures must preserve retained values while making their freshness explicit.
$outage = $snapshot;
$outage['collection'] = ['status' => 'failed', 'failed_count' => 1, 'total' => 18, 'lastclock' => $snapshot['lastclock'],
	'failed' => [['itemid' => '9', 'name' => '<script>Get cluster</script>', 'key_' => 'netapp.cluster.get',
		'kind' => 'item', 'error' => 'DNS failed <img src=x onerror=alert(1)>']]];
$outage['entities']['volumes'][0]['metrics']['space_size'] = item('Size', '1024', 'B', 3);
$outage['entities']['volumes'][0]['metrics']['space_used'] = item('Used', '512', 'B', 3);
foreach ($outage['cluster'] as &$outage_item) { $outage_item['stale'] = true; }
unset($outage_item);
foreach ($outage['entities'] as &$rows) {
	foreach ($rows as &$row) {
		foreach ($row['metrics'] as &$outage_item) { $outage_item['stale'] = true; }
		unset($outage_item);
	}
	unset($row);
}
unset($rows);
foreach (['overview', 'nodes', 'svms', 'volumes', 'luns', 'ethernet', 'fc', 'hardware', 'performance', 'problems'] as $tab) {
	$html = render('netapp.monitoring.view', array_replace($base, ['tab' => $tab, 'snapshot' => $outage]));
	check(strpos($html, 'netapp-monitoring-collection-notice') !== false
		&& strpos($html, 'Some NetApp HTTP requests are failing') !== false, "$tab shows partial failure banner");
	check(strpos($html, 'Showing the last collected values') !== false && strpos($html, '1 of 18 collection checks') !== false,
		"$tab explains retained data and failed check count");
	check(strpos($html, '9.16') !== false && strpos($html, 'netapp-monitoring-stale-value') !== false,
		"$tab retains last version with stale marker");
	check(strpos($html, '<script>') === false && strpos($html, '<img src=x') === false
		&& strpos($html, '&lt;img src=x') !== false, "$tab escapes failed HTTP check names and errors");
	if ($tab === 'volumes') {
		check(strpos($html, '512 B') !== false && strpos($html, '1.0 KiB') !== false
			&& strpos($html, 'netapp-monitoring-capacity-stale') !== false, 'Volume retained capacity has a stale label');
	}
}
$outage_list = render('netapp.monitoring.list', array_replace($list, ['snapshots' => ['20' => $outage]]));
check(strpos($outage_list, 'HTTP collection failing') !== false && strpos($outage_list, 'netapp-monitoring-collection-marker') !== false,
	'Main host row exposes the current collection failure');
$stale_health = (string) Modules\NetAppMonitoring\Includes\NetAppRenderer::value(
	array_replace(item('Health', 'ok'), ['state' => '1', 'stale' => true]), true);
check(strpos($stale_health, 'ok') !== false && strpos($stale_health, 'Stale') !== false
	&& strpos($stale_health, 'netapp-monitoring-state-good') === false, 'Unsupported retained health never gets current green styling');
$missing_health = (string) Modules\NetAppMonitoring\Includes\NetAppRenderer::value(
	array_replace(item('Health', 'ok'), ['state' => '1', 'lastclock' => 0, 'lastvalue' => null]), true);
check(strpos($missing_health, 'Unsupported') !== false && strpos($missing_health, '>ok<') === false,
	'Unsupported value without retained history remains unavailable');
$disabled_health = (string) Modules\NetAppMonitoring\Includes\NetAppRenderer::value(
	array_replace(item('Health', 'ok'), ['status' => '1', 'stale' => true]), true);
check(strpos($disabled_health, 'Disabled') !== false && strpos($disabled_health, '>ok<') === false,
	'Disabled metric does not expose its retained health value');
foreach (['disabled' => 'Monitoring is disabled', 'unknown' => 'collection status is unavailable'] as $state => $label) {
	$diagnostic = $outage;
	$diagnostic['collection']['status'] = $state;
	$diagnostic['collection']['failed'] = [];
	$html = (string) Modules\NetAppMonitoring\Includes\NetAppRenderer::collectionNotice($diagnostic, '20');
	check(strpos($html, $label) !== false, "$state collection state has a specific notice");
}
$healthy = $outage;
$healthy['collection']['status'] = 'ok';
check(Modules\NetAppMonitoring\Includes\NetAppRenderer::collectionNotice($healthy, '20') === null
	&& Modules\NetAppMonitoring\Includes\NetAppRenderer::collectionMarker($healthy) === null,
	'Recovery removes collection banner and main marker');

$inventory = $base;
$inventory['tab'] = 'volumes';
$inventory['per_page'] = 2;
$resource = $snapshot['entities']['volumes'][0];
$inventory['snapshot']['entities']['volumes'] = [];
foreach ([['z-volume', 'svm-a'], ['a-volume', 'svm-b'], ['b-volume', 'svm-a'], ['unknown-volume', '']] as [$name, $parent]) {
	$inventory['snapshot']['entities']['volumes'][] = array_replace($resource,
		['id' => $name, 'name' => $name, 'parent' => $parent]);
}
$inventory_html = render('netapp.monitoring.view', $inventory);
check(substr_count($inventory_html, 'data-netapp-inventory-group="svm"') === 1
	&& strpos($inventory_html, 'SVM: svm-a') !== false, 'SVM groups stay together before resource pagination');
check(strpos($inventory_html, 'b-volume') < strpos($inventory_html, 'z-volume'), 'Name sorting applies within each SVM');
check(strpos($inventory_html, '2 resources') !== false
	&& strpos($inventory_html, 'Displaying 1 to 2 of 4 found') !== false, 'Group headings do not consume page rows');
$inventory['page'] = 2;
$inventory_html = render('netapp.monitoring.view', $inventory);
check(strpos($inventory_html, 'SVM: svm-b') !== false && strpos($inventory_html, 'SVM: Unavailable') !== false,
	'Missing ownership stays visible after known SVM groups');
$inventory['page'] = 2;
$inventory['per_page'] = 1;
$inventory_html = render('netapp.monitoring.view', $inventory);
check(strpos($inventory_html, 'SVM: svm-a') !== false && strpos($inventory_html, '2 resources') !== false,
	'Continued groups repeat their heading and full matching count');
$inventory['search'] = 'svm-b';
$inventory_html = render('netapp.monitoring.view', $inventory);
check(strpos($inventory_html, 'SVM: svm-b') !== false && strpos($inventory_html, 'SVM: svm-a') === false,
	'Live search matches ownership and filters group headings');
foreach (['luns' => 'svm', 'ethernet' => 'node', 'fc' => 'node', 'hardware' => 'node'] as $tab => $kind) {
	$html = render('netapp.monitoring.view', array_replace($base, ['tab' => $tab, 'per_page' => 50]));
	check(strpos($html, 'data-netapp-inventory-group="'.$kind.'"') !== false, "$tab groups by supplied ownership");
}

if (isset($argv[2])) {
	if (!is_dir($argv[2])) { mkdir($argv[2], 0777, true); }
	foreach ($outputs as $name => $html) {
		file_put_contents($argv[2].'/'.$name.'.html', '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
	}
}
echo "Zabbix HTML view checks passed: $checks\n";
