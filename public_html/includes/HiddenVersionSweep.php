<?php
/**
 * HiddenVersionSweep — finish deletes a versioned bucket only hid
 * (specs/storage_targets.md S28).
 *
 * A versioned bucket (every Backblaze bucket, any bucket with versioning on)
 * answers a delete that names no version by hiding the object behind a delete
 * marker; its bytes stay, billed, readable by version. S3Signer::delete()
 * removes every version, so a delete made through it leaves nothing. What can
 * still be hidden is what a delete left half done (the provider dropped the
 * connection between two versions) and anything deleted some other way. A
 * hidden object drops out of every listing the pruners read and out of every
 * record, so the bucket's own version listing is the one place it shows.
 *
 * run() lists the versions under one folder of a target and, for every key
 * whose newest entry is a delete marker — something already deleted — deletes
 * all its versions and markers. A key whose newest entry is a live object is
 * never touched. Each folder is swept at most daily; a pass that runs out of
 * time is taken up on the next call. What each folder last found is kept on
 * the target row (bkt_hidden_sweep), so every pruner can call this on every
 * pass and it costs a lookup until the folder is due.
 *
 * @version 1.0
 */

class HiddenVersionSweep {

	/** A folder that finished clean is swept again after this long. */
	const INTERVAL_SECONDS = 86400;

	/** How long one call may spend deleting, unless the caller says. */
	const BUDGET_SECONDS = 300;

	/**
	 * Sweep one folder of a target when it is due. $prefix is a key prefix
	 * ending in '/'. Returns null when not due, else
	 * ['keys' => finished, 'versions' => deleted, 'bytes' => freed,
	 *  'left' => keys still hidden (the budget ran out), 'refused' => n,
	 *  'problem' => first refusal or listing failure, '' when none].
	 */
	public static function run(BackupTarget $target, string $prefix, ?int $budget_seconds = null, ?int $now = null): ?array {
		$now = $now ?? time();
		$prefix = ltrim($prefix, '/');
		$state = self::state($target);
		$last = $state[$prefix] ?? null;
		if (is_array($last) && empty($last['left']) && (int)($last['time'] ?? 0) > $now - self::INTERVAL_SECONDS) {
			return null;
		}
		$out = array('keys' => 0, 'versions' => 0, 'bytes' => 0, 'left' => 0, 'refused' => 0, 'problem' => '');
		try {
			$creds = $target->get_credentials();
			$bucket = (string)$target->get('bkt_bucket');
			$versions = S3Signer::list_versions($creds, $bucket, $prefix);
		} catch (\Throwable $e) {
			$out['problem'] = 'listing versions under ' . $prefix . ' failed: ' . $e->getMessage();
			self::record($target, $prefix, $now, $out);
			return $out;
		}
		if ($versions === null) {
			self::record($target, $prefix, $now, $out);   // a provider that keeps no versions hides nothing
			return $out;
		}

		$by_key = array();
		foreach ($versions as $v) {
			$by_key[$v['key']][] = $v;
		}
		$deadline = microtime(true) + ($budget_seconds ?? self::BUDGET_SECONDS);
		foreach ($by_key as $key => $list) {
			$hidden = false;
			foreach ($list as $v) {
				if ($v['latest']) { $hidden = $v['marker']; }
			}
			if (!$hidden) {
				continue;
			}
			if (microtime(true) >= $deadline) {
				$out['left']++;
				continue;
			}
			$whole = true;
			foreach ($list as $v) {
				try {
					$resp = S3Signer::request_delete_version($creds, $bucket, $key, $v['version_id']);
					$status = (int)($resp['status'] ?? 0);
				} catch (\Throwable $e) {
					$status = 0;
					$resp = array('body' => $e->getMessage());
				}
				if (($status >= 200 && $status < 300) || $status === 404) {
					$out['versions']++;
					$out['bytes'] += (int)$v['size'];
					continue;
				}
				$whole = false;
				if ($out['problem'] === '') {
					$out['problem'] = $key . ': ' . ($status ? 'HTTP ' . $status . ' ' . S3Signer::extract_error((string)($resp['body'] ?? '')) : (string)$resp['body']);
				}
			}
			if ($whole) {
				$out['keys']++;
			} else {
				$out['refused']++;
			}
		}
		self::record($target, $prefix, $now, $out);
		return $out;
	}

	/** One line for a pass's message, or '' when a sweep did nothing worth saying. */
	public static function sentence(?array $r, string $where): string {
		if ($r === null || ($r['keys'] === 0 && $r['left'] === 0 && $r['refused'] === 0 && $r['problem'] === '')) {
			return '';
		}
		$s = 'removed ' . $r['keys'] . ' deleted object' . ($r['keys'] === 1 ? '' : 's') . ' still held as hidden versions in ' . $where
			. ' (' . BackupRunner::human($r['bytes']) . ')';
		if ($r['left']) { $s .= ', ' . $r['left'] . ' left for the next pass'; }
		if ($r['problem'] !== '') { $s .= '; ' . ($r['refused'] ? $r['refused'] . ' refused, first ' : '') . $r['problem']; }
		return $s;
	}

	/** What each folder of the target last found: prefix => [time, keys, left, refused, problem]. */
	private static function state(BackupTarget $target): array {
		$raw = $target->get('bkt_hidden_sweep');
		if (is_string($raw)) { $raw = json_decode($raw, true); }
		return is_array($raw) ? $raw : array();
	}

	private static function record(BackupTarget $target, string $prefix, int $now, array $out): void {
		$fresh = new BackupTarget((int)$target->key, TRUE);
		$state = self::state($fresh);
		// A key the provider refused (a version still locked) waits for the
		// next day's sweep; only keys the budget left are taken up next call.
		$state[$prefix] = array('time' => $now, 'keys' => $out['keys'], 'left' => $out['left'], 'refused' => $out['refused'],
			'problem' => $out['problem']);
		$fresh->set('bkt_hidden_sweep', $state);
		$fresh->save();
		$target->set('bkt_hidden_sweep', $state);
	}
}
