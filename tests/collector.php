<?php
/** Run with php tests/collector.php; no Zabbix installation or database required. */
require_once __DIR__.'/../includes/NetAppCollector.php';
require_once __DIR__.'/../includes/NetAppFormatter.php';

use Modules\NetAppMonitoring\Includes\NetAppCollector;
use Modules\NetAppMonitoring\Includes\NetAppFormatter;

function check($condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
}

class API {
	public static $calls = [];
	public static $problems_fail = false;
	public static function Template() { return new MockService('template'); }
	public static function Host() { return new MockService('host'); }
	public static function Item() { return new MockService('item'); }
	public static function Problem() { return new MockService('problem'); }
}

class MockService {
	private $kind;
	public function __construct(string $kind) { $this->kind = $kind; }
	public function get(array $options) {
		API::$calls[] = [$this->kind, $options];
		if ($this->kind === 'template') {
			if (!isset($options['parentTemplateids'])) {
				return [
					['templateid' => '10', 'name' => 'stp - NetApp AFF A700 by HTTP', 'host' => 'custom-netapp'],
					['templateid' => '11', 'name' => 'Unrelated storage', 'host' => 'not-a-match']
				];
			}
			return in_array('10', $options['parentTemplateids']) ? [['templateid' => '20']] : [];
		}
		if ($this->kind === 'host') {
			check(in_array('10', $options['templateids']) && in_array('20', $options['templateids']),
				'Direct and descendant template IDs must be included.');
			check(!in_array('11', $options['templateids']), 'Unrelated templates must be excluded.');
			// The API returns only hosts readable by this user, even if other hosts use the template.
			return [['hostid' => '100', 'host' => 'netapp01', 'name' => 'NetApp 01', 'status' => '0']];
		}
		if ($this->kind === 'problem') { return API::$problems_fail ? false : []; }
		check($options['hostids'] === ['100'], 'Item API must be scoped to the validated host.');
		if (isset($options['itemids'])) {
			check($options['itemids'] === [1, 2, 3, 4, 5, 6], 'Raw master and unknown scalar keys must never be read.');
			return [
				['itemid' => '1', 'lastvalue' => 'ok', 'lastclock' => '123'],
				['itemid' => '2', 'lastvalue' => '0', 'lastclock' => '0'],
				['itemid' => '3', 'lastvalue' => 'online', 'lastclock' => '123'],
				['itemid' => '4', 'lastvalue' => '1000', 'lastclock' => '123'],
				['itemid' => '5', 'lastvalue' => '250', 'lastclock' => '123'],
				['itemid' => '6', 'lastvalue' => '1000', 'lastclock' => '123']
			];
		}
		check(!in_array('lastvalue', $options['output']), 'Metadata fetch must not read raw values.');
		$result = [];
		foreach ([
			1 => 'netapp.cluster.status', 2 => 'netapp.nodes.uptime[node1]',
			3 => 'netapp.volume.state[vol1]', 4 => 'netapp.volume.space_size[vol1]',
			5 => 'netapp.volume.space_used[vol1]', 6 => 'netapp.volume.space_size[vol-zero]',
			90 => 'netapp.cluster.get', 91 => 'netapp.unknown.metric'
		] as $id => $key) {
			$result[] = ['itemid' => (string) $id, 'name' => $key, 'key_' => $key, 'state' => '0',
				'error' => '', 'status' => $id === 6 ? '1' : '0', 'units' => '', 'value_type' => '3'];
		}
		return $result;
	}
}

check(count(NetAppCollector::listHosts()) === 1, 'Only permitted hosts should be returned.');
$before = count(API::$calls);
check(NetAppCollector::getItems('999') === [], 'Inaccessible host must not expose items.');
check(count(API::$calls) === $before, 'Inaccessible host must not query items.');
$snapshot = NetAppCollector::collect('100');
check($snapshot['cluster']['status']['lastvalue'] === 'ok', 'Cluster metric normalization failed.');
check($snapshot['entities']['nodes'][0]['metrics']['uptime']['lastvalue'] === null,
	'No history must stay unknown rather than become a zero value.');
$volumes = array_column($snapshot['entities']['volumes'], null, 'name');
check($volumes['vol1']['capacity']['percent'] === 25.0, 'Capacity percentage must use used / size.');
check($volumes['vol-zero']['capacity']['size'] === null, 'Disabled metrics must not expose stale values.');
check(NetAppCollector::classifyKey('netapp.port.fc.state["node,1","port\\name"]')
	=== ['fc_ports', 'state', ['node,1', 'port\\name']], 'Quoted comma and literal backslash must survive.');
check(NetAppCollector::classifyKey('netapp.lun.status.state[svm,/vol/data/lun1]')[2]
	=== ['svm', '/vol/data/lun1'], 'LUN path must survive.');
foreach (['netapp.cluster.get', 'netapp.volumes.discovery', 'netapp.cluster.status[extra]',
	'netapp.disk.state[one]', 'netapp.nodes.state["unclosed]', 'netapp.nodes.state[[array]]',
	'netapp.nodes.state["name"suffix]'] as $key) {
	check(NetAppCollector::classifyKey($key) === null, 'Unsupported key accepted: '.$key);
}

// Check every scalar item/prototype in the supplied template, including raw numeric counters.
$template = file_get_contents(__DIR__.'/../template_san_netapp_aff_a700_http.yaml');
preg_match_all('/^\s+key: [\x27\x22]?(netapp\.[^\r\n]+)\r?$/m', $template, $matches);
$checked = [];
foreach ($matches[1] as $key) {
	$key = trim($key, "'\" \r\n");
	if (preg_match('/\.(get|discovery)$/', $key)) { continue; }
	check(NetAppCollector::classifyKey($key) !== null, 'Template scalar key missing: '.$key);
	$checked[$key] = true;
}
check(count($checked) === 69, 'Template coverage check did not find the expected 69 scalar metrics.');
check(NetAppFormatter::bytes(1024) === '1.0 KiB', 'Binary capacity units must be explicit.');
check(NetAppFormatter::latency(0.125) === '0.125 ms', 'Template latency is already milliseconds.');
check(NetAppFormatter::bytes(null) === '-', 'Missing capacity must not be shown as zero.');
check(NetAppFormatter::state(null)['kind'] === 'unknown', 'Missing status must be unknown.');
check(NetAppFormatter::itemValue(['lastvalue' => '9.16', 'value_type' => '1']) === '9.16',
	'ONTAP versions must remain text rather than be rounded as numbers.');
check(NetAppFormatter::itemValue(['lastvalue' => '00123', 'value_type' => '1']) === '00123',
	'Numeric-looking identifiers must preserve leading zeroes.');
API::$problems_fail = true;
$denied_problems = NetAppCollector::collect('100');
check(!$denied_problems['problems_available'], 'Denied problem access must not look like zero active problems.');
check($denied_problems['cluster']['status']['lastvalue'] === 'ok',
	'Denied problem access must preserve available item data.');
check(count($denied_problems['warnings']) > count($snapshot['warnings']),
	'Denied problem access must produce a visible diagnostic.');
echo 'Collector checks passed; covered '.count($checked)." distinct scalar template keys.\n";
