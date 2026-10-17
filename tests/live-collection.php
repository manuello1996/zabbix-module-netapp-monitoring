<?php
/** Read-only outage integration check. The demo mock must already be unreachable.
 * ZABBIX_PASSWORD=... php tests/live-collection.php /usr/share/zabbix
 * This test never starts/stops services or changes hosts/templates.
 */
if (PHP_SAPI !== 'cli' || !getenv('ZABBIX_PASSWORD')) {
	fwrite(STDERR, "CLI only; set ZABBIX_PASSWORD for the test user.\n");
	exit(2);
}
$ui = $argv[1] ?? '/usr/share/zabbix';
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
require_once __DIR__.'/../includes/NetAppLocationGroups.php';
require_once __DIR__.'/../includes/NetAppCollector.php';
use Modules\NetAppMonitoring\Includes\NetAppCollector;
if (!CWebUser::login(getenv('ZABBIX_USER') ?: 'Admin', getenv('ZABBIX_PASSWORD'))) {
	throw new RuntimeException('Live test login failed.');
}
try {
	$hosts = API::Host()->get(['output' => ['hostid'], 'filter' => ['host' => 'netapp-module-demo']]);
	if (!$hosts) { throw new RuntimeException('The isolated NetApp demo host is required.'); }
	$snapshot = NetAppCollector::collect((string) $hosts[0]['hostid']);
	if ($snapshot['collection']['status'] !== 'failed' || $snapshot['collection']['failed_count'] !== 18
			|| $snapshot['collection']['total'] !== 18) {
		throw new RuntimeException('Expected the stopped mock to produce 10 HTTP master and 8 discovery failures.');
	}
	if ($snapshot['cluster']['name']['lastvalue'] !== 'AFF-A700-DEMO' || !$snapshot['cluster']['name']['stale']) {
		throw new RuntimeException('The last cluster name must remain visible and stale during an outage.');
	}
	$capacity_retained = false;
	$latency_retained = false;
	foreach ($snapshot['entities']['volumes'] as $volume) {
		if ($volume['capacity']['size'] === 2199023255552.0) { $capacity_retained = true; }
		foreach ($volume['metrics'] as $key => $item) {
			if (strpos($key, 'latency') !== false && (float) $item['lastvalue'] === 0.8 && $item['stale']) {
				$latency_retained = true;
			}
			if ($item['lastvalue'] !== null && !$item['stale']) {
				throw new RuntimeException('Retained volume metrics must be marked potentially stale during HTTP failure.');
			}
		}
	}
	if (!$capacity_retained || !$latency_retained) {
		throw new RuntimeException('Expected retained 2 TiB volume and 0.8 ms latency from the demo fixture.');
	}
	echo "Live outage collection passed: 18 HTTP failures; retained cluster identity, 2 TiB capacity and 0.8 ms latency marked stale.\n";
}
finally {
	API::User()->logout([]);
}
