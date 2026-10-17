<?php
/** Read-only group badge integration. No mock availability or host macro changes required.
 * ZABBIX_PASSWORD=... php tests/live-group-problems.php /usr/share/zabbix
 */
if (PHP_SAPI !== 'cli' || !getenv('ZABBIX_PASSWORD')) {
	fwrite(STDERR, "CLI only; set ZABBIX_PASSWORD for the test user.\n"); exit(2);
}
$ui = $argv[1] ?? '/usr/share/zabbix';
if (getenv('POSTGRES_DB') && !getenv('DB_SERVER_TYPE')) {
	foreach (['DB_SERVER_TYPE' => 'POSTGRESQL', 'DB_SERVER_DBNAME' => getenv('POSTGRES_DB'),
		'DB_SERVER_USER' => getenv('POSTGRES_USER'), 'DB_SERVER_PASS' => getenv('POSTGRES_PASSWORD'),
		'DB_SERVER_PORT' => '5432', 'DB_SERVER_SCHEMA' => 'public'] as $name => $value) { putenv($name.'='.$value); }
}
chdir($ui);
$_SERVER += ['SERVER_NAME' => 'localhost', 'SERVER_PORT' => '8080', 'REMOTE_ADDR' => '127.0.0.1',
	'SCRIPT_NAME' => '/api_jsonrpc.php', 'REQUEST_METHOD' => 'POST'];
require $ui.'/include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API);
restore_error_handler();
set_error_handler(static function ($level, $message, $file, $line) {
	if (error_reporting() & $level) { throw new ErrorException($message, 0, $level, $file, $line); } return true;
});
require_once __DIR__.'/../includes/NetAppLocationGroups.php';
require_once __DIR__.'/../includes/NetAppCollector.php';
use Modules\NetAppMonitoring\Includes\NetAppCollector;
use Modules\NetAppMonitoring\Includes\NetAppLocationGroups;
if (!CWebUser::login(getenv('ZABBIX_USER') ?: 'Admin', getenv('ZABBIX_PASSWORD'))) {
	throw new RuntimeException('Live test login failed.');
}
try {
	$hosts = array_values(NetAppCollector::listHosts());
	if (!$hosts) { throw new RuntimeException('An eligible NetApp host is required.'); }
	// Create test ancestry only in memory; stored host macros remain untouched.
	foreach ($hosts as &$host) { $host['location_path'] = ['Integration', 'Rack']; } unset($host);
	$groups = NetAppCollector::locationProblems(NetAppLocationGroups::groups($hosts));
	$events = API::Problem()->get(['output' => ['eventid', 'objectid', 'severity'],
		'hostids' => array_column($hosts, 'hostid'), 'recent' => false, 'suppressed' => false]);
	$unique = [];
	foreach ($events as $event) { $unique[$event['eventid']] = (int) $event['severity']; }
	$expected = array_count_values($unique); ksort($expected);
	foreach ($groups as $group) {
		$actual = $group['problems']; ksort($actual);
		if (!$group['problems_available'] || $actual !== $expected) {
			throw new RuntimeException('Ancestor severity counts differ from unique active unsuppressed API events.');
		}
	}
	echo 'Live group Problems passed: '.count($unique)." unique events per ancestor with matching severity counts; no host changes.\n";
}
finally { API::User()->logout([]); }
