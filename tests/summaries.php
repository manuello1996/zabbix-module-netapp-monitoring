<?php
/** Batched main-page collection at 30-appliance scale, without a Zabbix database. */
require __DIR__.'/../includes/NetAppLocationGroups.php';
require __DIR__.'/../includes/NetAppCollector.php';

use Modules\NetAppMonitoring\Includes\NetAppCollector as Collector;

function check($condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
}
class API {
	public static array $calls = [];
	public static bool $metadata_fail = false;
	public static bool $problems_fail = false;
	public static bool $triggers_fail = false;
	public static function Template() { return new Service('template'); }
	public static function Host() { return new Service('host'); }
	public static function Item() { return new Service('item'); }
	public static function DiscoveryRule() { return new Service('discovery'); }
	public static function Problem() { return new Service('problem'); }
	public static function Trigger() { return new Service('trigger'); }
}
class Service {
	private string $kind;
	public function __construct(string $kind) { $this->kind = $kind; }
	public function get(array $options) {
		API::$calls[] = [$this->kind, $options];
		if ($this->kind === 'template') {
			return isset($options['parentTemplateids']) ? []
				: [['templateid' => '10', 'host' => 'NetApp AFF A700 by HTTP', 'name' => 'NetApp AFF A700 by HTTP']];
		}
		if ($this->kind === 'host') {
			check($options['templateids'] === [10], 'Host discovery must use eligible templates.');
			return array_map(static function ($id) {
				return ['hostid' => (string) $id, 'host' => 'netapp-'.$id, 'name' => 'Cluster '.$id, 'status' => '0'];
			}, range(1, 30));
		}
		if ($this->kind === 'trigger') {
			return API::$triggers_fail ? [] : [['triggerid' => '90', 'hosts' => [['hostid' => '1'], ['hostid' => '2']]]];
		}
		check(!array_diff($options['hostids'], array_map('strval', range(1, 30))), 'Queries must exclude unrelated hosts.');
		if ($this->kind === 'problem') {
			return API::$problems_fail ? false : [['eventid' => '100', 'objectid' => '90', 'severity' => '4']];
		}
		if (isset($options['filter']['type'])) {
			check(!array_intersect(['lastvalue', 'lastclock'], $options['output']), 'HTTP payload values must never be read.');
			$rows = [];
			foreach ($options['hostids'] as $id) {
				$rows[] = ['itemid' => $id.'-http', 'hostid' => $id, 'name' => 'HTTP check',
					'key_' => Collector::httpKeys($this->kind === 'discovery')[0], 'type' => '19',
					'status' => '0', 'state' => $id === '1' ? '1' : '0', 'error' => ''];
			}
			return $rows;
		}
		$rows = [];
		if (isset($options['itemids'])) {
			foreach ($options['hostids'] as $id) {
				foreach (['name', 'status', 'version'] as $metric) {
					check(in_array($id.'-'.$metric, $options['itemids'], true), 'Value reads must use verified cluster item IDs.');
					$rows[] = ['itemid' => $id.'-'.$metric, 'hostid' => $id, 'name' => $metric,
						'key_' => 'netapp.cluster.'.$metric, 'lastvalue' => $metric === 'version' ? '9.16' : 'ok',
						'lastclock' => '123', 'status' => $id === '3' ? '1' : '0', 'state' => '0', 'value_type' => '1'];
				}
			}
			check(count($rows) === count($options['itemids']), 'Only three cluster values per host may be read.');
			return $rows;
		}
		check($options['output'] === ['itemid', 'hostid', 'key_'], 'Resource enumeration must retrieve identity metadata only.');
		check(!array_intersect(['netapp.disk.state[*]', 'netapp.port.eth.state[*]', 'netapp.cluster.statistics.latency.read'],
			$options['search']['key_']), 'Summary queries must skip hardware, port, and cluster performance items.');
		if (API::$metadata_fail) { return false; }
		foreach ($options['hostids'] as $id) {
			foreach (['name', 'status', 'version'] as $metric) {
				$rows[] = ['itemid' => $id.'-'.$metric, 'hostid' => $id, 'key_' => 'netapp.cluster.'.$metric];
			}
			foreach (['netapp.nodes.state[node-1]', 'netapp.nodes.membership[node-1]', 'netapp.node.version[node-1]',
				'netapp.nodes.state[node-2]', 'netapp.svm.state[svm-main]', 'netapp.svm.comment[svm-main]'] as $key) {
				$rows[] = ['itemid' => $id.'-'.$key, 'hostid' => $id, 'key_' => $key];
			}
			foreach (range(1, 100) as $volume) {
				foreach (['state', 'space_size', 'statistics.iops.read.rate'] as $metric) {
					$rows[] = ['itemid' => $id.'-'.$volume.'-'.$metric, 'hostid' => $id,
						'key_' => 'netapp.volume.'.$metric.'[volume-'.$volume.']'];
				}
			}
			// Broad API searches can return unknown keys. These must not qualify for value reads or counts.
			$rows[] = ['itemid' => 'raw', 'hostid' => $id, 'key_' => 'netapp.cluster.get'];
			$rows[] = ['itemid' => 'unknown', 'hostid' => $id, 'key_' => 'netapp.volume.unknown[ghost]'];
		}
		return $rows;
	}
}

$hosts = Collector::listHosts();
API::$calls = [];
$empty_inventory = Collector::inventory([]);
check($empty_inventory['totals'] === ['clusters' => 0, 'nodes' => 0, 'svms' => 0, 'volumes' => 0]
	&& API::$calls === [], 'An empty scope returns zero counters without querying monitoring data.');
$inventory = Collector::inventory($hosts);
check($inventory['totals'] === ['clusters' => 30, 'nodes' => 60, 'svms' => 30, 'volumes' => 3000],
	'Counters must deduplicate metrics per resource and retain identical resource names on different clusters.');
check(count(API::$calls) === 1, 'All four counters must use one batch of identity metadata.');
$summaries = Collector::collectSummaries($hosts, $inventory);
check(count(API::$calls) === 6, '30 appliances must use six batch queries, independent of host count.');
check(count($summaries) === 30, 'Every eligible appliance must have a summary.');
foreach ($summaries as $summary) {
	check($summary['counts']['volumes'] === 100, 'Multiple metrics must count once per resource.');
	check($summary['entities']['volumes'] === [], 'The main page must not build full resource snapshots.');
}
check($summaries[1]['collection']['status'] === 'failed' && $summaries[1]['cluster']['version']['stale'],
	'HTTP failures must label retained cluster values stale.');
check($summaries[2]['collection']['status'] === 'ok' && !$summaries[2]['cluster']['version']['stale'],
	'Failures on one host must not affect another host.');
check($summaries[3]['cluster']['version']['lastvalue'] === null, 'Disabled items must not expose retained values.');
check(count($summaries[1]['problems']) === 1 && count($summaries[2]['problems']) === 1
	&& !$summaries[3]['problems'], 'Shared trigger events must be counted on the associated hosts only.');
API::$calls = [];
$page = Collector::collectSummaries(array_slice($hosts, 0, 2, true), $inventory);
check(count($page) === 2 && count(API::$calls) === 5, 'Page summaries must reuse the inventory metadata without querying it again.');
foreach (API::$calls as [$kind, $options]) {
	if (isset($options['hostids'])) {
		check($options['hostids'] === ['1', '2'], 'Values and diagnostics must remain limited to the current page.');
	}
}
API::$problems_fail = true;
check(!Collector::collectSummaries([$hosts[1]])[1]['problems_available'], 'Problem API failures must remain unavailable.');
API::$problems_fail = false;
API::$triggers_fail = true;
check(!Collector::collectSummaries([$hosts[1]])[1]['problems_available'], 'Missing trigger relations must not become a healthy zero.');
API::$metadata_fail = true;
$failed_inventory = Collector::inventory([$hosts[1]]);
check($failed_inventory['totals'] === ['clusters' => 1, 'nodes' => null, 'svms' => null, 'volumes' => null],
	'Failed metadata must preserve the cluster count and mark resource totals unavailable.');
check(Collector::collectSummaries([$hosts[1]])[1]['counts']['volumes'] === null, 'Failed resource metadata must not become a zero count.');
API::$calls = [];
check(Collector::inventory([['hostid' => '999']])['totals']['clusters'] === 0,
	'Unrelated hosts must never contribute to the counters.');
check(Collector::collectSummaries([['hostid' => '999']]) === [], 'Unrelated hosts must not be collected.');
check(API::$calls === [], 'Unrelated hosts must never reach monitoring APIs.');
echo "Summary checks passed: 30 appliances, 3,000 volumes, six batch queries, permission and failure isolation.\n";
