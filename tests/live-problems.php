<?php
/** Optional read-only integration check inside the Zabbix frontend container.
 * ZABBIX_PASSWORD must be set. Run: php tests/live-problems.php /usr/share/zabbix
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}
if (!getenv('ZABBIX_PASSWORD')) {
	fwrite(STDERR, "CLI only; set ZABBIX_PASSWORD for the test user.\n");
	exit(2);
}
$ui = $argv[1] ?? '/usr/share/zabbix';
// Docker's entrypoint exports these aliases only to its own child processes.
// Reuse existing container credentials in memory for this separate CLI process.
if (getenv('POSTGRES_DB') && !getenv('DB_SERVER_TYPE')) {
	foreach (['DB_SERVER_TYPE' => 'POSTGRESQL', 'DB_SERVER_DBNAME' => getenv('POSTGRES_DB'),
		'DB_SERVER_USER' => getenv('POSTGRES_USER'), 'DB_SERVER_PASS' => getenv('POSTGRES_PASSWORD'),
		'DB_SERVER_PORT' => '5432', 'DB_SERVER_SCHEMA' => 'public'] as $name => $value) {
		putenv($name.'='.$value);
	}
}
chdir($ui);
$_SERVER += ['SERVER_NAME' => 'localhost', 'SERVER_PORT' => '8080', 'REMOTE_ADDR' => '127.0.0.1',
	'SCRIPT_NAME' => '/api_jsonrpc.php', 'REQUEST_METHOD' => 'POST'];
require $ui.'/include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API);
restore_error_handler();
set_error_handler(static function ($level, $message, $file, $line) {
	if (error_reporting() & $level) { throw new ErrorException($message, 0, $level, $file, $line); }
	return true;
});
require_once $ui.'/widgets/problems/includes/WidgetProblems.php';
require_once __DIR__.'/../includes/NetAppCollector.php';
require_once __DIR__.'/../includes/NetAppLocationGroups.php';
require_once __DIR__.'/../includes/NetAppProblems.php';
require_once __DIR__.'/../includes/NetAppPager.php';
require_once __DIR__.'/../includes/NetAppRenderer.php';
require_once __DIR__.'/../includes/NetAppFormatter.php';
if (!CWebUser::login(getenv('ZABBIX_USER') ?: 'Admin', getenv('ZABBIX_PASSWORD'))) {
	throw new RuntimeException('Live test login failed.');
}
try {
	$hosts = API::Host()->get(['output' => ['hostid'], 'filter' => ['host' => 'netapp-module-demo']]);
	if (!$hosts) { throw new RuntimeException('Create the NetApp demo host first.'); }
	$hostid = (string) $hosts[0]['hostid'];
	$widget = Modules\NetAppMonitoring\Includes\NetAppProblems::data($hostid);
	$html = (string) new Widgets\Problems\Includes\WidgetProblems($widget);
	if (preg_match('~(?:href|src)=["\x27]\s*file:~i', $html)) {
		throw new RuntimeException('Native Problems widget contains a local file URL.');
	}
	foreach (['Recovery time', 'Operational data', 'Duration', 'Actions'] as $label) {
		if (strpos($html, $label) === false) { throw new RuntimeException('Missing native column '.$label); }
	}
	if ($widget['problems'] && strpos($html, 'data-eventid=') === false) {
		throw new RuntimeException('Native problem event rows are missing.');
	}
	// Exercise genuine native symptom markup using an in-memory relationship.
	// No event ranking, trigger, or problem changes are sent to the live API.
	$fixture_events = [];
	foreach ($widget['problems'] as $problem) {
		$fixture_events[] = $problem;
		foreach ($problem['symptoms'] as $symptom) { $fixture_events[] = $symptom; }
	}
	if (count($fixture_events) >= 2) {
		$parent = $fixture_events[0];
		$child = $fixture_events[1];
		$parent['cause_eventid'] = '0';
		$child['cause_eventid'] = (string) $parent['eventid'];
		$child['symptom_count'] = 0;
		$child['symptoms'] = [];
		$parent['symptom_count'] = 1;
		$parent['symptoms'] = [$child];
		$nested_widget = $widget;
		$nested_widget['problems'] = [$parent['eventid'] => $parent];
		$nested_widget['show_three_columns'] = true;
		$nested_widget['show_two_columns'] = false;
		$nested_html = (string) new Widgets\Problems\Includes\WidgetProblems($nested_widget);
		$previous_errors = libxml_use_internal_errors(true);
		$document = new DOMDocument();
		$document->loadHTML($nested_html);
		$xpath = new DOMXPath($document);
		$rows = $xpath->query('//tr[@data-cause-eventid="'.$parent['eventid'].'" and @data-eventid="'.$child['eventid'].'"]');
		if ($rows->length !== 1 || !preg_match('/(?:^|\s)hidden(?:\s|$)/', $rows->item(0)->getAttribute('class'))) {
			throw new RuntimeException('Native nested symptom must be rendered once, initially collapsed.');
		}
		if ($xpath->query('//button[@data-action="show_symptoms" and @data-eventid="'.$parent['eventid'].'"]')->length !== 1) {
			throw new RuntimeException('Native cause must expose its symptom toggle.');
		}
		libxml_clear_errors();
		libxml_use_internal_errors($previous_errors);
	}
	$rendered = (string) Modules\NetAppMonitoring\Includes\NetAppRenderer::problems([
		'host' => ['hostid' => $hostid], 'snapshot' => ['problems_available' => true],
		'problem_widget' => $widget, 'search' => ''
	]);
	if ($widget['problems'] && strpos($rendered, 'class="table-paging"') === false) {
		throw new RuntimeException('Native Problems table navigation is missing.');
	}
	$filtered = Modules\NetAppMonitoring\Includes\NetAppProblems::data($hostid, 'NO_MATCH_NETAPP_TEST_9d21');
	if ($filtered['problems']) { throw new RuntimeException('Native problem search must filter before limiting.'); }
	$outside_scope = Modules\NetAppMonitoring\Includes\NetAppProblems::data('0');
	if ($outside_scope['problems']) { throw new RuntimeException('Invalid host scope must never select all hosts.'); }
	$aggregate = Modules\NetAppMonitoring\Includes\NetAppProblems::forHosts([$hostid, '0']);
	if (array_keys($aggregate['problems']) !== array_keys($widget['problems'])) {
		throw new RuntimeException('Aggregate Problems must intersect requested IDs with eligible hosts.');
	}
	$empty_scope = Modules\NetAppMonitoring\Includes\NetAppProblems::forHosts([]);
	if ($empty_scope['problems']) { throw new RuntimeException('Empty aggregate scope must never select all hosts.'); }
	$original_rows = CWebUser::$data['rows_per_page'];
	foreach ([2, 5] as $rows) {
		CWebUser::$data['rows_per_page'] = $rows;
		$first = Modules\NetAppMonitoring\Includes\NetAppProblems::data($hostid);
		$second = Modules\NetAppMonitoring\Includes\NetAppProblems::data($hostid, '', null, 2);
		if ($first['per_page'] !== $rows || count($first['page_eventids']) !== min($rows, $first['total'])) {
			throw new RuntimeException('Problem row count does not follow the user preference.');
		}
		if ($first['pages'] > 1 && ($second['page'] !== 2
				|| array_intersect($first['page_eventids'], $second['page_eventids']))) {
			throw new RuntimeException('Problem pagination repeated first-page events.');
		}
	}
	CWebUser::$data['rows_per_page'] = $original_rows;
	echo 'Live native Problems widget passed: '.count($widget['problems'])." event rows; columns and search verified.\n";
}
finally {
	API::User()->logout([]);
}
