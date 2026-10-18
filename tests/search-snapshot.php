<?php
/** Isolated cache checks; no real Zabbix sessions, credentials or API writes. */
namespace Modules\NetAppMonitoring\Includes {
	class SearchClock { public static int $now; public static string $directory; }
	function time() { return SearchClock::$now; }
	function sys_get_temp_dir() { return SearchClock::$directory; }
	class NetAppCollector {
		public static int $calls = 0;
		public static bool $large = false;
		public static function collect(string $hostid): array {
			return ['hostid' => $hostid, 'sample' => ++self::$calls,
				'payload' => self::$large ? str_repeat('x', 2097152) : 'fixture'];
		}
	}
}
namespace {
	use Modules\NetAppMonitoring\Includes\NetAppCollector;
	use Modules\NetAppMonitoring\Includes\NetAppSearchSnapshot;
	use Modules\NetAppMonitoring\Includes\SearchClock;
	class CWebUser { public static array $data = ['userid' => '1', 'sessionid' => 'fixture-session-a']; }
	require __DIR__.'/../includes/NetAppSearchSnapshot.php';
	SearchClock::$now = time();
	SearchClock::$directory = sys_get_temp_dir().'/netapp-search-test-'.bin2hex(random_bytes(8));
	mkdir(SearchClock::$directory, 0700);
	$checks = 0;
	function check($condition, string $message): void {
		global $checks;
		$checks++;
		if (!$condition) { throw new RuntimeException($message); }
	}
	try {
		$initial = NetAppSearchSnapshot::load('100', false);
		check(NetAppSearchSnapshot::load('100', true) === $initial && NetAppCollector::$calls === 1,
			'Typing reuses the full-page snapshot without recollecting');
		$files = glob(SearchClock::$directory.'/*/*.json');
		check(count($files) === 1 && (fileperms($files[0]) & 0077) === 0, 'Snapshot file is private');
		check((fileperms(dirname($files[0])) & 0077) === 0, 'Snapshot directory is private');
		check(!str_contains($files[0], 'fixture-session'), 'Session token is not written in the filename');
		$refreshed = NetAppSearchSnapshot::load('100', false);
		check($refreshed['sample'] === 2, 'Refresh always bypasses the cache');
		SearchClock::$now += NetAppSearchSnapshot::TTL;
		check(NetAppSearchSnapshot::load('100', true)['sample'] === 3, 'Expired snapshot is collected again');
		check(NetAppSearchSnapshot::load('200', true)['sample'] === 4, 'Host scope cannot share a cached snapshot');
		CWebUser::$data['sessionid'] = 'fixture-session-b';
		check(NetAppSearchSnapshot::load('200', true)['sample'] === 5, 'Separate sessions do not share snapshots');
		CWebUser::$data['userid'] = '2';
		check(NetAppSearchSnapshot::load('200', true)['sample'] === 6, 'Separate users do not share snapshots');
		$files = glob(SearchClock::$directory.'/*/*.json');
		foreach ($files as $file) { file_put_contents($file, '{}'); }
		check(NetAppSearchSnapshot::load('200', true)['sample'] === 7, 'Malformed metadata falls back to collection');
		NetAppCollector::$large = true;
		NetAppSearchSnapshot::load('200', false);
		check(NetAppSearchSnapshot::load('200', true)['sample'] === 9, 'Oversized snapshots bypass caching');
		NetAppCollector::$large = false;
		CWebUser::$data['sessionid'] = '';
		NetAppSearchSnapshot::load('200', true);
		check(NetAppSearchSnapshot::load('200', true)['sample'] === 11, 'Missing session identity bypasses caching');
		check(!isset($_SESSION['netapp.monitoring.search.snapshot']), 'Monitoring data never enters the Zabbix session cookie');
	}
	finally {
		foreach (glob(SearchClock::$directory.'/*/*') as $file) { unlink($file); }
		foreach (glob(SearchClock::$directory.'/*') as $directory) { rmdir($directory); }
		rmdir(SearchClock::$directory);
	}
	echo "Search snapshot checks passed: $checks\n";
}
