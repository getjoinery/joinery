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
 *   6. Node key, when one is set — it can write a probe and CANNOT delete it
 *      (that is the whole point of a second key); the main key cleans up.
 *   7. Backblaze keys — what each key says it may do: pinned to this bucket
 *      (warn when it opens the whole account, naming the file store's
 *      buckets it also opens), and every capability the job needs, plus the
 *      key-minting ones when minting per run is on.
 *
 * All providers (Backblaze via its S3 endpoint included) go through S3Signer;
 * only step 7 asks Backblaze itself.
 *
 * @version 4.0 - the full check (specs/storage_bucket_and_key_check.md): own bucket, private,
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
				$steps[] = $del['ok']
					? array('label' => 'Prune', 'status' => 'pass', 'message' => 'The main key can delete, so retention can prune.')
					: array('label' => 'Prune', 'status' => 'fail', 'message' => 'The main key cannot delete (' . $del['error'] . '). '
						. 'Retention could never prune, so the bucket would only grow. Use a key that can delete as the main key; a write-only key belongs in the node key field.');
			} else {
				$steps[] = array('label' => 'Write', 'status' => 'fail', 'message' => 'The main key cannot store an object in "' . $bucket . '" (' . $put['error'] . ').');
				return $done($steps);
			}

			// 6. The node key: write yes, delete no.
			$node_creds = array();
			try {
				$node_creds = $target->has_node_credentials() ? $target->get_node_credentials() : array();
			} catch (Exception $e) {
				$steps[] = array('label' => 'Node key', 'status' => 'fail', 'message' => $e->getMessage());
			}
			if (!empty($node_creds['access_key'])) {
				$node_probe = '/' . trim($prefix, '/') . '/_joinery_probe-node-' . bin2hex(random_bytes(4)) . '.txt';
				$nput = self::put($node_creds, $bucket, $node_probe, $probe_local);
				if (!$nput['ok']) {
					$steps[] = array('label' => 'Node key', 'status' => 'fail',
						'message' => 'The node key cannot store an object in "' . $bucket . '" (' . $nput['error'] . '). A backup run on a node would fail to upload.');
				} else {
					$ndel = self::delete($node_creds, $bucket, $node_probe);
					if ($ndel['ok']) {
						$steps[] = array('label' => 'Node key', 'status' => 'fail',
							'message' => 'The node key can delete objects, so a node handed it could erase backups. '
								. 'That key is meant to write and nothing else: make one with write but not delete capability, or leave the field empty and nodes use the main key.');
					} else {
						$steps[] = array('label' => 'Node key', 'status' => 'pass', 'message' => 'The node key can write and cannot delete.');
						self::delete($creds, $bucket, $node_probe);
					}
				}
			}
		} finally {
			@unlink($probe_local);
		}

		// 7. What Backblaze says each key may do.
		if ((string)$target->get('bkt_provider') === 'b2' || BucketCheck::is_b2((string)($creds['endpoint'] ?? ''))) {
			$needed = self::B2_MAIN_NEEDS;
			if (!empty($target->get('bkt_mint_run_keys'))) {
				$needed = array_merge($needed, BucketCheck::B2_MINT_CAPABILITIES);
			}
			$others = BucketCheck::file_store_buckets();
			foreach (BucketCheck::b2_key_steps($creds, $bucket, $needed, 'main key', $others) as $step) {
				$steps[] = $step;
			}
			if (!empty($node_creds['access_key'])) {
				foreach (BucketCheck::b2_key_steps($node_creds, $bucket, array('writeFiles'), 'node key', $others) as $step) {
					$steps[] = $step;
				}
			}
		}

		return $done($steps);
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

	/** A signed DELETE: ['ok' => bool, 'error' => string]. */
	private static function delete(array $creds, $bucket, $key) {
		try {
			$r = S3Signer::delete($creds, $bucket, $key);
		} catch (Exception $e) {
			return array('ok' => false, 'error' => $e->getMessage());
		}
		$ok = (int)$r['status'] >= 200 && (int)$r['status'] < 300;
		return array('ok' => $ok, 'error' => $ok ? '' : (S3Signer::extract_error((string)$r['body']) ?: 'HTTP ' . $r['status']));
	}
}
