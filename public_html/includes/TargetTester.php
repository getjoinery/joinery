<?php
/**
 * TargetTester — prove a backup target can do its job before it is saved.
 *
 * One target, one answer: ['success' => bool, 'message' => string,
 * 'steps' => [...]]. The message is the failing steps' sentences when it
 * fails, or the pass line plus any warnings when it passes, so a page can
 * print it as it is. The steps are BucketCheck's shape for a page that
 * wants to draw them.
 *
 * What is proved, in order, stopping at the first thing that makes the rest
 * meaningless:
 *   1. Its own bucket — not the file store's (BucketCheck::collision_step).
 *   2. Reach — the main key can list the bucket (one ListObjectsV2, one key).
 *   3. Write — the main key can put a probe object under the target's prefix.
 *   4. Private — an anonymous read of that probe is refused.
 *   5. Prune — the main key can delete the probe (retention needs it).
 *   6. Backblaze — what the key says it may do: pinned to this bucket (warn
 *      when it opens the whole account, naming the file store's buckets it
 *      also opens), and every capability the job needs.
 *   7. Lock — for a target set to lock (bkt_lock_days): the bucket has object
 *      lock on, and a probe written locked for one day cannot be deleted. The
 *      probe stays until its date (_joinery_lock_probe/ under the prefix); each
 *      test deletes the probes whose date has passed.
 *
 * All providers (Backblaze via its S3 endpoint included) go through S3Signer;
 * only step 6 asks Backblaze itself.
 *
 * @version 4.2 - step 7, Lock: a target set to lock needs object lock on its bucket, proved with a probe locked for one day;
 *                a bucket that locks every new object by default fails Prune, saying so
 * @version 4.1 - one key per target: no node key to prove write-only and no minting capabilities to ask
 *                for (specs/storage_targets.md WP5)
 * @version 4.0 - the full check (specs/implemented/storage_bucket_and_key_check.md): own bucket, private,
 *                prune, the node key proven write-only, Backblaze capabilities; steps returned
 * @version 3.0
 */

require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

class TargetTester {

	/**
	 * Test a BackupTarget, saved or not. Returns ['success', 'message', 'steps'].
	 */
	public static function test($target) {
		$bucket = trim((string)$target->get('bkt_bucket'));
		$steps = array();
		$done = function ($steps) use ($bucket) {
			$failed = BucketCheck::failed($steps);
			if ($failed) {
				$message = implode(' ', BucketCheck::messages($steps, 'fail'));
			} else {
				$message = 'Credentials valid and bucket "' . $bucket . '" is accessible.';
				$warnings = BucketCheck::messages($steps, 'warn');
				if ($warnings) {
					$message .= ' ' . implode(' ', $warnings);
				}
			}
			return array('success' => !$failed, 'message' => $message, 'steps' => $steps);
		};

		if ($bucket === '') {
			$steps[] = array('label' => 'Bucket', 'status' => 'fail', 'message' => 'No bucket configured.');
			return $done($steps);
		}
		try {
			$creds = $target->get_credentials();
		} catch (Exception $e) {
			$steps[] = array('label' => 'Main key', 'status' => 'fail', 'message' => $e->getMessage());
			return $done($steps);
		}
		if (empty($creds['access_key']) || empty($creds['secret_key'])) {
			$steps[] = array('label' => 'Main key', 'status' => 'fail', 'message' => 'No credentials configured.');
			return $done($steps);
		}

		// 1. Its own bucket. Decided from what this site already knows; no network.
		$steps[] = BucketCheck::collision_step($bucket, (string)($creds['endpoint'] ?? ''), BucketCheck::file_store_buckets(), 'backups');
		if (BucketCheck::failed($steps)) {
			return $done($steps);
		}

		// 2. Reach: one signed list, one key.
		try {
			$response = S3Signer::get($creds, $bucket, '/', array('list-type' => '2', 'max-keys' => '1'));
		} catch (S3SignerException $e) {
			$steps[] = array('label' => 'Reach', 'status' => 'fail', 'message' => 'Configuration error: ' . $e->getMessage());
			return $done($steps);
		} catch (Exception $e) {
			$steps[] = array('label' => 'Reach', 'status' => 'fail', 'message' => 'Test error: ' . $e->getMessage());
			return $done($steps);
		}
		$reach = self::reach_verdict((int)$response['status'], (string)$response['body'], $bucket);
		$steps[] = $reach;
		if ($reach['status'] === 'fail') {
			return $done($steps);
		}

		// 3–5. Write a probe, prove nobody can read it without a key, prune it.
		$prefix = trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups';
		$probe_key = '/' . trim($prefix, '/') . '/_joinery_probe-' . bin2hex(random_bytes(4)) . '.txt';
		$probe_local = tempnam(sys_get_temp_dir(), 'jyprobe');
		file_put_contents($probe_local, "joinery-backup-target-test\n");
		try {
			$put = self::put($creds, $bucket, $probe_key, $probe_local);
			if ($put['ok']) {
				$steps[] = array('label' => 'Write', 'status' => 'pass', 'message' => 'The main key can store an object under ' . $prefix . '/.');
				$steps[] = BucketCheck::private_read_step(BucketCheck::object_url($creds, $bucket, ltrim($probe_key, '/')));
				$del = self::delete($creds, $bucket, $probe_key);
				if ($del['ok']) {
					$steps[] = array('label' => 'Prune', 'status' => 'pass', 'message' => 'The main key can delete, so retention can prune.');
				} elseif ($del['locked']) {
					$steps[] = array('label' => 'Prune', 'status' => 'fail', 'message' => 'This bucket locks every new object (object lock with a '
						. 'default retention), so retention could not prune on its own schedule. Use a bucket without default retention; '
						. 'to lock backups, set this target\'s lock days instead.');
				} else {
					$steps[] = array('label' => 'Prune', 'status' => 'fail', 'message' => 'The main key cannot delete (' . $del['error'] . '). '
						. 'Retention could never prune, so the bucket would only grow. Use a key that can delete.');
				}
			} else {
				$steps[] = array('label' => 'Write', 'status' => 'fail', 'message' => 'The main key cannot store an object in "' . $bucket . '" (' . $put['error'] . ').');
				return $done($steps);
			}
		} finally {
			@unlink($probe_local);
		}

		// 6. What Backblaze says the key may do.
		$locks = $target->lock_days() > 0;
		if ((string)$target->get('bkt_provider') === 'b2' || BucketCheck::is_b2((string)($creds['endpoint'] ?? ''))) {
			$needs = $locks ? array_merge(self::B2_MAIN_NEEDS, self::B2_LOCK_NEEDS) : self::B2_MAIN_NEEDS;
			foreach (BucketCheck::b2_key_steps($creds, $bucket, $needs, 'main key', BucketCheck::file_store_buckets()) as $step) {
				$steps[] = $step;
			}
		}

		// 7. The lock holds.
		if ($locks) {
			$steps[] = self::lock_step($creds, $bucket, $prefix);
		}

		return $done($steps);
	}

	/** What a locking target's key must also be able to do on Backblaze. */
	const B2_LOCK_NEEDS = array('readBucketRetentions', 'readFileRetentions', 'writeFileRetentions');

	/** Where the lock probes go, under the target's prefix. */
	const LOCK_PROBE_DIR = '_joinery_lock_probe/';

	/**
	 * The Lock step: the bucket has object lock on, and an object written
	 * locked for one day is refused a delete. Clears the earlier probes whose
	 * lock has passed first.
	 */
	private static function lock_step(array $creds, $bucket, $prefix) {
		$fail = function ($why) {
			return array('label' => 'Lock', 'status' => 'fail', 'message' => $why);
		};
		try {
			$conf = S3Signer::get($creds, $bucket, '/', array('object-lock' => ''));
		} catch (Exception $e) {
			return $fail('The bucket\'s object lock setting could not be read: ' . $e->getMessage());
		}
		if ((int)$conf['status'] !== 200 || !preg_match('#<ObjectLockEnabled>\s*Enabled\s*</ObjectLockEnabled>#', (string)$conf['body'])) {
			$err = ((int)$conf['status'] === 200) ? '' : ' (' . (S3Signer::extract_error((string)$conf['body']) ?: 'HTTP ' . $conf['status']) . ')';
			return $fail('Bucket "' . $bucket . '" does not have object lock on' . $err . '. It can only be turned on when a bucket is '
				. 'created: create a bucket with object lock on and point this target at it, or set the lock days to 0.');
		}

		$dir = trim($prefix, '/') . '/' . self::LOCK_PROBE_DIR;
		try {
			foreach (S3Signer::list($creds, $bucket, $dir) as $o) {
				S3Signer::delete($creds, $bucket, '/' . ltrim((string)$o['key'], '/'));   // a probe still locked answers LOCKED and stays
			}
		} catch (Exception $e) {
			// Tidying is not the test.
		}

		$local = tempnam(sys_get_temp_dir(), 'jylock');
		file_put_contents($local, "joinery-object-lock-test\n");
		$key = '/' . $dir . gmdate('Ymd_His') . '-' . bin2hex(random_bytes(3)) . '.txt';
		try {
			$put = self::put(S3Signer::with_lock($creds, 1), $bucket, $key, $local);
		} finally {
			@unlink($local);
		}
		if (!$put['ok']) {
			return $fail('The main key cannot store a locked object (' . $put['error'] . '). It needs to be allowed to set and read retention.');
		}
		try {
			$del = S3Signer::delete($creds, $bucket, $key);
		} catch (Exception $e) {
			return $fail('Deleting the locked probe failed oddly: ' . $e->getMessage());
		}
		$status = (int)$del['status'];
		if ($status === S3Signer::LOCKED) {
			return array('label' => 'Lock', 'status' => 'pass', 'message' => 'Object lock is on: a probe locked for one day could not be '
				. 'deleted, so nobody can delete a backup here before its date. The probe stays in ' . $dir . ' until then.');
		}
		if ($status >= 200 && $status < 300) {
			return $fail('A probe written locked was deleted at once, so the bucket\'s lock does not hold. Check its object lock setting.');
		}
		return $fail('The locked probe was refused a delete, but the main key cannot read why (' . (S3Signer::extract_error((string)$del['body']) ?: 'HTTP ' . $status)
			. '). It needs to be allowed to read retention, or retention cannot tell a locked backup from a failure.');
	}

	/** What a backup target's main key must be able to do on Backblaze. */
	const B2_MAIN_NEEDS = BucketCheck::B2_BACKUP_CAPABILITIES;

	/** The Reach step from the list call's answer. */
	private static function reach_verdict($status, $body, $bucket) {
		if ($status === 200) {
			return array('label' => 'Reach', 'status' => 'pass', 'message' => 'The main key can list "' . $bucket . '".');
		}
		if ($status === 403) {
			return array('label' => 'Reach', 'status' => 'fail', 'message' => 'Access denied (403) — credentials rejected or lack permission on bucket "' . $bucket . '".');
		}
		if ($status === 404) {
			return array('label' => 'Reach', 'status' => 'fail', 'message' => 'Bucket "' . $bucket . '" not found (404).');
		}
		if ($status === 301 || $status === 400) {
			$err = S3Signer::extract_error($body);
			return array('label' => 'Reach', 'status' => 'fail', 'message' => 'Request rejected (HTTP ' . $status . '): ' . ($err ?: 'check region/endpoint') . '.');
		}
		$err = S3Signer::extract_error($body) ?: ('HTTP ' . $status);
		return array('label' => 'Reach', 'status' => 'fail', 'message' => 'Test failed: ' . $err);
	}

	/** A signed PUT of a small local file: ['ok' => bool, 'error' => string]. */
	private static function put(array $creds, $bucket, $key, $local) {
		try {
			$r = S3Signer::put_file($creds, $bucket, $key, $local, 'text/plain');
		} catch (Exception $e) {
			return array('ok' => false, 'error' => $e->getMessage());
		}
		$ok = (int)$r['status'] >= 200 && (int)$r['status'] < 300;
		return array('ok' => $ok, 'error' => $ok ? '' : (S3Signer::extract_error((string)$r['body']) ?: 'HTTP ' . $r['status']));
	}

	/** A signed DELETE: ['ok' => bool, 'locked' => bool, 'error' => string]. */
	private static function delete(array $creds, $bucket, $key) {
		try {
			$r = S3Signer::delete($creds, $bucket, $key);
		} catch (Exception $e) {
			return array('ok' => false, 'locked' => false, 'error' => $e->getMessage());
		}
		$ok = (int)$r['status'] >= 200 && (int)$r['status'] < 300;
		return array('ok' => $ok, 'locked' => (int)$r['status'] === S3Signer::LOCKED,
			'error' => $ok ? '' : (S3Signer::extract_error((string)$r['body']) ?: 'HTTP ' . $r['status']));
	}
}
