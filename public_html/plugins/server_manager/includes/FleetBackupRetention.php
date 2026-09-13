<?php
/**
 * FleetBackupRetention — pruning the shelf this management node owns.
 *
 * This is the one place the manager profile deliberately keeps work OFF the
 * node. A node is handed a write-only credential: it can add its archives and
 * cannot remove any, so a compromised node cannot erase the fleet's backups —
 * which is the first move of any ransomware worth the name, and the exact thing
 * the manager copy exists to survive. Deletion therefore happens here, with a
 * credential that never leaves this machine.
 *
 * It is driven by a LISTING rather than by recorded history, which is the
 * opposite of what BackupRunner does for a site's own backups — deliberately,
 * and for a reason that only holds here. A site listing a shared bucket cannot
 * know which objects are its own; this management node defined the whole
 * {prefix}/{slug}/manager/ path, knows every slug in it, and is the only party
 * that can delete from it. Listing is also strictly safer for this job: it keeps
 * the newest N sets of objects that ACTUALLY EXIST, so a run that failed
 * part-way can never be counted as a restore point.
 *
 * Chains are kept or deleted whole. Deleting the oldest runs of a chain leaves
 * incrementals whose full is gone, which is not a smaller backup — it is no
 * backup, and it looks like a restore point right up until someone needs it.
 *
 * @version 1.3 - check_shelf() answers in two parts: what is wrong with the backups it read, and
 *                which manifests it could not read this pass — a transport error is reported by the
 *                pass, never stamped as an incomplete backup; the reader is injectable for the test
 * @version 1.2 - the shelf check: every artifact a chain's manifest names must be on the shelf at the
 *                recorded size, and the manifest must carry its envelope. compare_manifest is the pure
 *                rule; check_shelf reads each manifest off the listing prune() already takes
 * @version 1.1 - the pass also sizes the shelf, from the listing it already takes: the hosted
 *                tier's storage allowance needs no meter of its own
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));

class FleetBackupRetention {

	/**
	 * Prune one node's manager shelf to the newest $keep restore points.
	 *
	 * Called immediately BEFORE dispatching that node's next run, which is the
	 * right moment for two reasons: it is once per backup cycle rather than once
	 * per scheduler tick, and everything it counts is already confirmed present
	 * in the bucket.
	 *
	 * The result also carries what the listing SAW — `listed` and
	 * `newest_object_time` — because the listing is the bucket's own testimony
	 * about this node's shelf, taken with this management node's credential. The
	 * scheduler stamps it on the node, and the health check compares it against
	 * what the node claims: a node that reports success while nothing new lands
	 * on the shelf is the one failure the node's own reporting can never admit
	 * to.
	 *
	 * It also SIZES the shelf, from the same listing. That figure is what the
	 * hosted tier's storage allowance is measured against, and taking it here
	 * is why the allowance needs no meter of its own: the pass already walks the
	 * whole prefix and the provider already returns each object's size, so the
	 * number is free, is taken with the one credential that can see the whole
	 * shelf, and is measured AFTER the prune — which is what the customer is
	 * actually keeping.
	 *
	 * The listing itself comes back too (`objects`, less what was pruned, and
	 * the `base` it was taken under) so the shelf check can read from the same
	 * testimony without listing again.
	 *
	 * @return array{kept:int, pruned:int, deleted_objects:int, error:string,
	 *               listed:bool, newest_object_time:string, bytes:int,
	 *               objects:array, base:string}
	 */
	public static function prune($node, $target, $keep) {
		$keep = max(1, (int)$keep);
		$result = array('kept' => 0, 'pruned' => 0, 'deleted_objects' => 0, 'error' => '',
			'listed' => false, 'newest_object_time' => '', 'bytes' => 0, 'objects' => array(), 'base' => '');

		try {
			$creds  = $target->get_credentials();
			$bucket = trim((string)$target->get('bkt_bucket'));
			$prefix = rtrim(trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups', '/');
			$slug   = trim((string)$node->get('mgn_slug'));

			if ($bucket === '' || $slug === '' || empty($creds)) {
				$result['error'] = 'no bucket, slug or credentials';
				return $result;
			}

			$base = $prefix . '/' . $slug . '/' . BackupProfile::path_segment(BackupProfile::MANAGER) . '/';
			$objects = S3Signer::list($creds, $bucket, $base);
			if (!is_array($objects)) {
				$result['error'] = 'the shelf could not be listed';
				return $result;
			}
			$result['listed'] = true;
			$result['newest_object_time'] = self::newest_object_time($objects);

			$groups = self::group($objects, $base);
			$result['kept'] = min(count($groups), $keep);

			$surplus = array_slice(array_values($groups), $keep);
			$pruned_keys = array();
			foreach ($surplus as $group) {
				foreach ($group['keys'] as $key) {
					$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
					$status = (int)($resp['status'] ?? 0);
					// 404 is the state we were asking for.
					if (($status < 200 || $status >= 300) && $status !== 404) {
						throw new Exception('HTTP ' . $status . ' deleting ' . $key);
					}
					$result['deleted_objects']++;
					$pruned_keys[$key] = true;
				}
				$result['pruned']++;
			}
			// Sized from what is LEFT, so the figure is what this node is
			// keeping rather than what it briefly held. Objects the provider
			// reported no size for count as nothing: an under-count trips an
			// allowance late, and an invented number trips it wrongly.
			$result['bytes'] = self::total_bytes($objects, $pruned_keys);
			$result['base']  = $base;
			foreach ($objects as $obj) {
				$key = is_array($obj) ? (string)($obj['key'] ?? $obj['Key'] ?? '') : '';
				if ($key === '' || isset($pruned_keys[$key])) { continue; }
				$result['objects'][] = $obj;
			}
		} catch (Throwable $e) {
			// A shelf that could not be pruned is not a reason to skip the backup
			// that was about to run. Too many restore points is a bill; no backup
			// is an outage.
			$result['error'] = $e->getMessage();
			error_log('FleetBackupRetention: pruning failed for node '
				. $node->get('mgn_slug') . ': ' . $e->getMessage());
		}

		return $result;
	}

	/**
	 * Group a listing into restore points, newest first.
	 *
	 * A chain is one group keyed by its directory, whatever it holds — that is
	 * what makes deletion chain-atomic by construction rather than by a rule
	 * someone has to remember. A standalone archive is one group with its
	 * envelope, because an archive without its envelope is unreadable noise and
	 * an envelope without its archive is a restore point that is not there.
	 *
	 * Groups sort by the timestamp in their name — chain directories are
	 * chain-YYYYMMDD_HHMMSS and standalone archives carry the same stamp. The
	 * stamp rather than the whole name, because the two families have different
	 * prefixes ('chain-' vs the slug): a name sort would order a mixed shelf by
	 * family, and after a mode switch every archive of one family would outrank
	 * every archive of the other regardless of age — old fulls hogging the keep
	 * slots forever while newer chains get pruned. And the stamp rather than the
	 * provider's last-modified, which reflects when an object was WRITTEN — a
	 * chain still being extended would keep jumping to the front of a list that
	 * is meant to be ordered by when it started.
	 */
	public static function group(array $objects, $base) {
		$groups = array();

		foreach ($objects as $obj) {
			$key = is_array($obj) ? (string)($obj['key'] ?? $obj['Key'] ?? '') : (string)$obj;
			if ($key === '' || strpos($key, $base) !== 0) {
				continue;
			}
			$rel = substr($key, strlen($base));
			if ($rel === '') { continue; }

			$slash = strpos($rel, '/');
			if ($slash !== false) {
				// Anything inside a directory belongs to that directory's group.
				$name = substr($rel, 0, $slash);
			} else {
				// A standalone archive and its envelope share a group. The sidecar
				// suffix is stripped so the two land together.
				$name = $rel;
				if (BackupEnvelope::is_sidecar_name($name)) {
					$name = substr($name, 0, -strlen(BackupEnvelope::SIDECAR_SUFFIX));
				}
			}

			if (!isset($groups[$name])) {
				$groups[$name] = array('name' => $name, 'keys' => array());
			}
			$groups[$name]['keys'][] = $key;
		}

		// Newest first. Name as tiebreak, for a deterministic order between two
		// groups sharing a stamp.
		uksort($groups, function ($a, $b) {
			return strcmp(self::stamp_of($b) . $b, self::stamp_of($a) . $a);
		});
		return $groups;
	}

	/**
	 * The YYYYMMDD_HHMMSS stamp in a group name. A name with no stamp sorts as
	 * the oldest thing on the shelf: it is not a restore point this code ever
	 * wrote, so it must not occupy a keep slot that a real one needs.
	 */
	private static function stamp_of($name) {
		return preg_match('/\d{8}_\d{6}/', (string)$name, $m) ? $m[0] : '00000000_000000';
	}

	/**
	 * The bytes a listing accounts for, ignoring the keys just deleted.
	 */
	public static function total_bytes(array $objects, array $skip_keys = array()) {
		$total = 0;
		foreach ($objects as $obj) {
			if (!is_array($obj)) { continue; }
			$key = (string)($obj['key'] ?? $obj['Key'] ?? '');
			if ($key !== '' && isset($skip_keys[$key])) { continue; }
			$size = $obj['size'] ?? $obj['Size'] ?? null;
			if (is_numeric($size)) { $total += (int)$size; }
		}
		return $total;
	}

	/**
	 * When something last LANDED on this shelf, from the provider's
	 * last-modified stamps — UTC 'Y-m-d H:i:s', or '' for an empty listing.
	 *
	 * The write time rather than the name stamp on purpose: a chain directory
	 * keeps its start stamp for its whole life, but every run that extends it
	 * writes new objects — so the newest write is when a backup last actually
	 * arrived, which is the fact the node's own reporting cannot fake.
	 */
	public static function newest_object_time(array $objects) {
		$newest = 0;
		foreach ($objects as $obj) {
			$lm = is_array($obj) ? (string)($obj['last_modified'] ?? '') : '';
			if ($lm === '') { continue; }
			$ts = strtotime($lm);
			if ($ts !== false && $ts > $newest) { $newest = $ts; }
		}
		return $newest > 0 ? gmdate('Y-m-d H:i:s', $newest) : '';
	}

	/**
	 * The shelf check — level 1 of backup verification, and free: is every
	 * backup on this node's shelf whole?
	 *
	 * For every chain the listing holds a manifest for, the manifest is read
	 * (one small GET each) and compared to the listing: every artifact it names
	 * must be present at the recorded size, and it must carry its envelope, or
	 * there is no key to recover. This catches a partial upload, an object
	 * deleted out from under retention, and a manifest rewritten after its
	 * artifacts were pruned — three ways a backup can look present on the
	 * dashboard and be nothing when it is needed.
	 *
	 * Two answers, kept apart because they mean different things:
	 *
	 *   problem  what is wrong with a backup whose manifest WAS read — one line
	 *            naming it, '' when every backup read is whole. A fact about
	 *            the shelf, stamped on the node's card.
	 *   unread   manifests that could not be fetched this pass (a transport
	 *            error, an HTTP status) — one line naming them, '' when all
	 *            were read. A fact about this pass, not the shelf: reported in
	 *            the pass and never stamped, so a network blip is not shown as
	 *            an incomplete backup.
	 *
	 * Nothing here deletes or retries.
	 *
	 * @param array  $objects The listing under $base, as prune() returned it
	 * @param string $base    The manager-profile prefix the listing was taken under
	 * @param callable|null $read fn(string $key): array — the decoded manifest,
	 *                      or throws; defaults to a signed GET from the bucket
	 * @return array{problem:string, unread:string}
	 */
	public static function check_shelf(array $objects, $base, array $creds, $bucket, $read = null) {
		$base = rtrim((string)$base, '/') . '/';
		$present = array();      // chain dir => [name => size]
		$manifests = array();    // chain dir => manifest key
		foreach ($objects as $obj) {
			if (!is_array($obj)) { continue; }
			$key = (string)($obj['key'] ?? $obj['Key'] ?? '');
			if ($key === '' || strpos($key, $base) !== 0) { continue; }
			$rel = substr($key, strlen($base));
			$parts = explode('/', $rel);
			if (count($parts) !== 2 || strpos($parts[0], BackupChain::DIR_PREFIX) !== 0) { continue; }
			list($dir, $name) = $parts;
			$size = $obj['size'] ?? $obj['Size'] ?? null;
			$present[$dir][$name] = is_numeric($size) ? (int)$size : null;
			if ($name === BackupChain::MANIFEST_NAME) {
				$manifests[$dir] = $key;
			}
		}

		if ($read === null) {
			$read = function ($key) use ($creds, $bucket) {
				$resp = S3Signer::get($creds, $bucket, '/' . ltrim($key, '/'));
				if ((int)($resp['status'] ?? 0) !== 200) {
					throw new Exception('HTTP ' . (int)($resp['status'] ?? 0));
				}
				return BackupChain::decode((string)($resp['body'] ?? ''));
			};
		}

		$problems = array();
		$unread = array();
		ksort($manifests);
		foreach ($manifests as $dir => $key) {
			try {
				$manifest = $read($key);
				if (!is_array($manifest)) {
					throw new Exception('not a manifest');
				}
			} catch (Throwable $e) {
				$unread[] = 'the manifest of the backup set ' . self::set_words($dir, null)
					. ' could not be read (' . $e->getMessage() . ')';
				continue;
			}
			$problem = self::compare_manifest($manifest, $present[$dir] ?? array());
			if ($problem !== '') {
				$problems[] = $problem;
			}
		}
		return array('problem' => implode('; ', $problems), 'unread' => implode('; ', $unread));
	}

	/**
	 * The pure rule behind the shelf check: one manifest against what the
	 * listing holds under its directory, as [name => bytes]. Returns '' when
	 * whole, otherwise one line saying what is wrong, in words a person reads
	 * on the node's card.
	 */
	public static function compare_manifest(array $manifest, array $present) {
		$set = self::set_words((string)($manifest['chain_id'] ?? ''), $manifest);
		if (empty($manifest['envelope']) || !is_array($manifest['envelope'])) {
			return 'the backup set ' . $set . ' has no envelope in its manifest, so no key can be recovered for it';
		}
		foreach (($manifest['runs'] ?? array()) as $run) {
			foreach (($run['artifacts'] ?? array()) as $a) {
				$name = (string)($a['name'] ?? '');
				if ($name === '') { continue; }
				if (!array_key_exists($name, $present)) {
					return 'the backup set ' . $set . ' names ' . $name . ' in its manifest but it is not on the shelf';
				}
				$expected = (int)($a['bytes'] ?? 0);
				$actual = $present[$name];
				if ($expected > 0 && $actual !== null && $actual !== $expected) {
					return 'the backup set ' . $set . ' holds ' . $name . ' at ' . $actual
						. ' bytes on the shelf where its manifest records ' . $expected;
				}
			}
		}
		return '';
	}

	/** "begun 2026-09-12 04:45 UTC" for a person, from the manifest or the directory's stamp. */
	private static function set_words($dir, $manifest) {
		$created = is_array($manifest) ? (string)($manifest['created'] ?? '') : '';
		$ts = $created !== '' ? strtotime($created) : false;
		if ($ts === false && preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', (string)$dir, $m)) {
			$ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
		}
		return $ts ? 'begun ' . gmdate('Y-m-d H:i', $ts) . ' UTC' : 'on the shelf';
	}
}
