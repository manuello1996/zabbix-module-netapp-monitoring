<?php
/** Run with php tests/collector.php; no Zabbix installation or database required. */
require_once __DIR__.'/../includes/NetAppCollector.php';
require_once __DIR__.'/../includes/NetAppLocationGroups.php';
require_once __DIR__.'/../includes/NetAppFormatter.php';

use Modules\NetAppMonitoring\Includes\NetAppCollector;
use Modules\NetAppMonitoring\Includes\NetAppFormatter;

function check($condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
}

class API {
	public static $calls = [];
	public static $problems_fail = false;
	public static $http_fail = false;
	public static $scalar_fail = false;
	public static $group_problems = [];
	public static $group_triggers = [];
	public static function Template() { return new MockService('template'); }
	public static function Host() { return new MockService('host'); }
	public static function Item() { return new MockService('item'); }
	public static function Problem() { return new MockService('problem'); }
	public static function DiscoveryRule() { return new MockService('discovery'); }
	public static function Trigger() { return new MockService('trigger'); }
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
		if ($this->kind === 'trigger') { return API::$group_triggers; }
		if ($this->kind === 'problem') {
			return API::$problems_fail ? false : ($options['output'] === ['eventid', 'objectid', 'severity'] ? API::$group_problems : []);
		}
		check($options['hostids'] === ['100'], 'Item API must be scoped to the validated host.');
		if (isset($options['filter']['type'])) {
			check($options['filter']['type'] === 19, 'Collection diagnostics must query HTTP agents only.');
			check(!array_intersect(['lastvalue', 'lastclock'], $options['output']), 'HTTP diagnostic queries must not fetch raw payload values.');
			$keys = NetAppCollector::httpKeys($this->kind === 'discovery');
			check($options['filter']['key_'] === $keys, 'Collection diagnostics must use exact reference-template HTTP keys.');
			return array_map(static function ($key) { return ['itemid' => '900', 'name' => $key,
				'key_' => $key, 'type' => '19', 'status' => '0', 'state' => API::$http_fail ? '1' : '0',
				'error' => API::$http_fail ? 'Cannot resolve mock <script>alert(1)</script>' : '']; }, $keys);
		}
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
			$result[] = ['itemid' => (string) $id, 'name' => $key, 'key_' => $key, 'state' => API::$scalar_fail ? '1' : '0',
				'error' => '', 'status' => $id === 6 ? '1' : '0', 'units' => '', 'value_type' => '3'];
		}
		return $result;
	}
}

check(count(NetAppCollector::listHosts()) === 1, 'Only permitted hosts should be returned.');
$before = count(API::$calls);
check(NetAppCollector::getItems('999') === [], 'Inaccessible host must not expose items.');
check(count(API::$calls) === $before, 'Inaccessible host must not query items.');
NetAppCollector::getCollection('999');
check(count(API::$calls) === $before, 'Inaccessible host must not query HTTP diagnostics.');
$snapshot = NetAppCollector::collect('100');
check($snapshot['cluster']['status']['lastvalue'] === 'ok', 'Cluster metric normalization failed.');
check($snapshot['entities']['nodes'][0]['metrics']['uptime']['lastvalue'] === null,
	'No history must stay unknown rather than become a zero value.');
$volumes = array_column($snapshot['entities']['volumes'], null, 'name');
check($volumes['vol1']['capacity']['percent'] === 25.0, 'Capacity percentage must use used / size.');
check($volumes['vol-zero']['capacity']['size'] === null, 'Disabled metrics must not expose stale values.');
check($snapshot['collection']['status'] === 'ok', 'Supported HTTP checks report healthy collection independent of old scalar timestamps.');
API::$scalar_fail = true;
$unsupported = NetAppCollector::collect('100');
check($unsupported['cluster']['status']['lastvalue'] === 'ok' && $unsupported['cluster']['status']['stale'],
	'Unsupported scalar with retained history must preserve the last value and mark stale.');
check($unsupported['entities']['nodes'][0]['metrics']['uptime']['lastvalue'] === null,
	'Unsupported scalar without history must not invent a retained zero sample.');
$unsupported_volumes = array_column($unsupported['entities']['volumes'], null, 'name');
check($unsupported_volumes['vol-zero']['capacity']['size'] === null,
	'Disabled scalar remains hidden even if also unsupported.');
API::$scalar_fail = false;
API::$http_fail = true;
$outage = NetAppCollector::collect('100');
check($outage['collection']['status'] === 'failed' && $outage['collection']['failed_count'] === 18,
	'All ten HTTP master items and eight HTTP discovery rules must participate in collection status.');
check($outage['cluster']['status']['lastvalue'] === 'ok' && $outage['cluster']['status']['stale'],
	'Failed HTTP collection must mark retained supported dependents stale.');
$outage_volumes = array_column($outage['entities']['volumes'], null, 'name');
check($outage_volumes['vol1']['capacity']['percent'] === 25.0
	&& $outage_volumes['vol1']['metrics']['space_size']['stale'], 'Outage must preserve volume capacity and mark its inputs stale.');
check($outage_volumes['vol-zero']['metrics']['space_size']['lastvalue'] === null,
	'HTTP outage must not restore disabled values.');
API::$http_fail = false;
$recovered = NetAppCollector::collect('100');
check($recovered['collection']['status'] === 'ok' && !$recovered['cluster']['status']['stale'],
	'Recovered HTTP checks clear the collection warning and stale flag.');
$http = static function (array $overrides = []): array {
	return $overrides + ['itemid' => '7', 'name' => 'Get cluster', 'key_' => 'netapp.cluster.get',
		'type' => '19', 'state' => '0', 'status' => '0', 'error' => ''];
};
$partial = NetAppCollector::collectionState([$http(), $http(['key_' => 'netapp.nodes.get', 'state' => '1'])], [], 0, 123);
check($partial['status'] === 'failed' && $partial['failed_count'] === 1 && $partial['total'] === 2
	&& $partial['lastclock'] === 123, 'Partial HTTP outage must preserve check counts and last scalar sample time.');
check(NetAppCollector::collectionState([$http(['status' => '1', 'state' => '1'])], [])['status'] === 'disabled',
	'Disabled HTTP checks are paused rather than failures.');
check(NetAppCollector::collectionState([$http(['state' => '1'])], [], 1)['status'] === 'disabled',
	'Disabled host monitoring takes precedence over old HTTP failure state.');
check(NetAppCollector::collectionState(false, false)['status'] === 'unknown', 'Denied collection metadata cannot report healthy.');
check(NetAppCollector::collectionState([$http()], false)['status'] === 'unknown', 'Missing discovery metadata cannot report healthy.');
check(NetAppCollector::collectionState([], [])['status'] === 'unknown', 'No configured HTTP checks cannot report healthy.');
check(NetAppCollector::collectionState([$http(['type' => '18']), $http(['key_' => 'unrelated.http'])], [])['total'] === 0,
	'Unrelated or non-HTTP checks must not affect the collection summary.');
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
$template_http = [];
preg_match_all('/type: HTTP_AGENT\s+key: [\x27\x22]?(netapp\.[^\r\n]+)\r?$/m', $template, $http_matches);
foreach ($http_matches[1] as $key) {
	$key = trim($key, "'\" \r\n");
	if (preg_match('/\.(get|discovery)$/', $key)) { $template_http[] = $key; }
}
$http_keys = array_merge(NetAppCollector::httpKeys(), NetAppCollector::httpKeys(true));
$template_http = array_values(array_unique($template_http));
sort($template_http); sort($http_keys);
check($template_http === $http_keys && count($http_keys) === 18, 'Exact HTTP allowlist must cover all reference-template master and discovery keys.');
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
API::$problems_fail = false;
$groups = \Modules\NetAppMonitoring\Includes\NetAppLocationGroups::groups([
	['hostid' => '100', 'name' => 'Accessible', 'location_path' => ['CH']],
	['hostid' => '999', 'name' => 'Outside', 'location_path' => ['CH']]
]);
API::$group_problems = [['eventid' => '1', 'objectid' => '2', 'severity' => '4']];
API::$group_triggers = [['triggerid' => '2', 'hosts' => [['hostid' => '100']]]];
$before = count(API::$calls);
$enriched = NetAppCollector::locationProblems($groups);
$group_calls = array_slice(API::$calls, $before);
check(count($group_calls) === 2 && $group_calls[0][0] === 'problem' && $group_calls[1][0] === 'trigger',
	'Group counters query only problem metadata and trigger relations, never off-page snapshots.');
$query = $group_calls[0][1];
check($query['hostids'] === ['100'] && $query['recent'] === false && $query['suppressed'] === false,
	'Group counters restrict eligible hosts and active unsuppressed events.');
check(!isset($query['limit']) && $query['output'] === ['eventid', 'objectid', 'severity'],
	'Full-scope group counts must not inherit per-host limits or request descriptions.');
check($group_calls[1][1]['selectHosts'] === ['hostid'] && $group_calls[1][1]['triggerids'] === ['2'],
	'Trigger relations must be scoped to returned event object IDs.');
check(reset($enriched)['problems'] === [4 => 1], 'Accessible group event aggregates into severity counts.');
$before = count(API::$calls);
check(NetAppCollector::locationProblems([]) === [] && count(API::$calls) === $before, 'Empty groups issue no queries.');
$outside = $groups;
foreach ($outside as &$group) { $group['hostids'] = ['999']; } unset($group);
NetAppCollector::locationProblems($outside);
check(count(API::$calls) === $before, 'Ineligible group scope issues no unbounded query.');
foreach ([false, []] as $relations) {
	API::$group_triggers = $relations;
	$result = NetAppCollector::locationProblems($groups);
	check(!reset($result)['problems_available'], 'Denied or missing trigger relations cannot look like zero problems.');
}
API::$problems_fail = true;
$before = count(API::$calls);
$result = NetAppCollector::locationProblems($groups);
check(!reset($result)['problems_available'] && count(API::$calls) === $before + 1,
	'Denied group problems show unavailable and skip trigger lookup.');
echo 'Collector checks passed; covered '.count($checked)." distinct scalar template keys.\n";
