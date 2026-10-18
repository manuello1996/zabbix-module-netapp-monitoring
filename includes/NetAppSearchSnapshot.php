<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Includes;

/** Private temporary files: Zabbix stores PHP session data in browser cookies. */
class NetAppSearchSnapshot {
	public const TTL = 10;
	private const MAX_BYTES = 2097152;

	public static function load(string $hostid, bool $search): array {
		$now = time();
		$userid = (string) (\CWebUser::$data['userid'] ?? '');
		$sessionid = (string) (\CWebUser::$data['sessionid'] ?? '');
		$directory = self::directory();
		$file = $directory !== null && $sessionid !== ''
			? $directory.'/'.hash('sha256', $userid.'/'.$sessionid).'.json' : null;
		$cached = null;
		if ($search && $file !== null && is_file($file) && filesize($file) <= self::MAX_BYTES) {
			$contents = @file_get_contents($file);
			$cached = $contents !== false ? json_decode($contents, true) : null;
		}
		if ($search && is_array($cached) && ($cached['hostid'] ?? null) === $hostid
				&& ($cached['userid'] ?? null) === $userid && is_array($cached['snapshot'] ?? null)
				&& is_int($cached['expires'] ?? null) && $cached['expires'] > $now) {
			return $cached['snapshot'];
		}
		// The caller validates current host access before this method. A normal
		// page load / Refresh always collects again and replaces the old snapshot.
		$snapshot = NetAppCollector::collect($hostid);
		if ($file !== null) {
			@unlink($file);
			$encoded = json_encode(['hostid' => $hostid, 'userid' => $userid,
				'expires' => $now + self::TTL, 'snapshot' => $snapshot]);
			if ($snapshot && $encoded !== false && strlen($encoded) <= self::MAX_BYTES) {
				$temp = @tempnam($directory, 'write-');
				if ($temp !== false) {
					if (@file_put_contents($temp, $encoded) === strlen($encoded)) { @rename($temp, $file); }
					if (is_file($temp)) { @unlink($temp); }
				}
			}
			// Bounded cleanup; abandoned sessions must not accumulate snapshots.
			$visited = 0;
			foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
				if (++$visited > 32) { break; }
				if ($entry->isFile() && $entry->getMTime() < $now - self::TTL) { @unlink($entry->getPathname()); }
			}
		}
		return $snapshot;
	}

	private static function directory(): ?string {
		// On other platforms, continue without caching rather than assume file privacy.
		if (PHP_OS_FAMILY === 'Windows') { return null; }
		$directory = sys_get_temp_dir().'/zabbix-netapp-search-'.substr(hash('sha256', __DIR__), 0, 16);
		if (!is_dir($directory) && !@mkdir($directory, 0700)) { return null; }
		if (is_link($directory) || (fileperms($directory) & 0077) !== 0 || !is_writable($directory)) { return null; }
		return $directory;
	}
}
