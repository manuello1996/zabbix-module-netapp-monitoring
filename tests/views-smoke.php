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
function zbx_formatDomId($value) { return str_replace(['[', ']'], ['_', ''], $value); }
function zbx_date2str($format, $time = null, $timezone = null) { return date('Y-m-d H:i:s', $time ?? time()); }
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
$loader->addNamespace('Modules\\NetAppMonitoring', [dirname(__DIR__)]);
$loader->register();

function render(string $name, array $data): string {
	ob_start();
	try { require __DIR__.'/../views/'.$name.'.php'; return ob_get_contents(); }
	finally { ob_end_clean(); }
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
$base = ['host' => $host, 'snapshot' => $snapshot, 'tab' => 'overview', 'search' => '',
	'sort' => 'name', 'sortorder' => 'ASC', 'page' => 1, 'per_page' => 1];
$outputs = [];
foreach (['overview', 'nodes', 'svms', 'volumes', 'luns', 'ethernet', 'fc', 'hardware', 'performance', 'problems'] as $tab) {
	$html = render('netapp.monitoring.view', array_replace($base, ['tab' => $tab]));
	check(strpos($html, '<main>') !== false, "$tab renders a page");
	check(strpos($html, '<script>') === false, "$tab escapes script names and warnings");
	check(strpos($html, '<img src=x') === false && strpos($html, '<svg onload') === false,
		"$tab escapes values and problem names");
	check(strpos($html, 'aria-current="page"') !== false, "$tab marks active navigation");
	check(strpos($html, '9.16') !== false, "$tab preserves ONTAP version string");
	$outputs[$tab] = $html;
}
$list = ['hosts' => [$host], 'snapshots' => ['20' => $snapshot], 'total_hosts' => 2,
	'search' => '"><script>alert(1)</script>', 'page' => 1, 'per_page' => 1, 'sort' => 'name', 'sortorder' => 'ASC'];
$outputs['list'] = render('netapp.monitoring.list', $list);
check(strpos($outputs['list'], '<script>') === false, 'List escapes hostile host/search');
check(strpos($outputs['list'], 'Next') !== false, 'List has next page link');
$outputs['empty-list'] = render('netapp.monitoring.list', array_replace($list, ['hosts' => [], 'snapshots' => [], 'total_hosts' => 0]));
check(strpos($outputs['empty-list'], 'No matching hosts') !== false, 'Empty list explains template scope');
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
$unavailable = $snapshot;
$unavailable['problems'] = [];
$unavailable['problems_available'] = false;
$problem_html = render('netapp.monitoring.view', array_replace($base, ['tab' => 'problems', 'snapshot' => $unavailable]));
check(strpos($problem_html, 'Problem information is unavailable') !== false, 'Problem API failure is visible');
check(strpos($problem_html, 'No active problems found') === false, 'Problem API failure never claims healthy state');
$unavailable_list = render('netapp.monitoring.list', array_replace($list, ['snapshots' => ['20' => $unavailable]]));
check(strpos($unavailable_list, 'Unavailable') !== false, 'List marks unavailable problem count');

if (isset($argv[2])) {
	if (!is_dir($argv[2])) { mkdir($argv[2], 0777, true); }
	foreach ($outputs as $name => $html) {
		file_put_contents($argv[2].'/'.$name.'.html', '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
	}
}
echo "Zabbix HTML view checks passed: $checks\n";
