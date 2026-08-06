<?php
/**
 * FleetBackupRetention — pruning the shelf this control plane owns.
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
 * know which objects are its own; this control plane defined the whole
 * {prefix}/{slug}/manager/ path, knows every slug in it, and is the only party
 * that can delete from it. Listing is also strictly safer for this job: it keeps
 * the newest N sets of objects that ACTUALLY EXIST, so a run that failed
 * part-way can never be counted as a restore point.
 *
 * Chains are kept or deleted whole. Deleting the oldest runs of a chain leaves
 * incrementals whose full is gone, which is not a smaller backup — it is no
 * backup, and it looks like a restore point right up until someone needs it.
 *
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
	 * about this node's shelf, taken with this control plane's credential. The
	 * scheduler stamps it on the node, and the health check compares it against
	 * what the node claims: a node that reports success while nothing new lands
	 * on the shelf is the one failure the node's own reporting can never admit
	 * to.
	 *
	 * @return array{kept:int, pruned:int, deleted_objects:int, error:string,
	 *               listed:bool, newest_object_time:string}
	 */
	public static function prune($node, $target, $keep) {
		$keep = max(1, (int)$keep);
		$result = array('kept' => 0, 'pruned' => 0, 'deleted_objects' => 0, 'error' => '',
			'listed' => false, 'newest_object_time' => '');

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
			foreach ($surplus as $group) {
				foreach ($group['keys'] as $key) {
					$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
					$status = (int)($resp['status'] ?? 0);
					// 404 is the state we were asking for.
					if (($status < 200 || $status >= 300) && $status !== 404) {
						throw new Exception('HTTP ' . $status . ' deleting ' . $key);
					}
					$result['deleted_objects']++;
				}
				$result['pruned']++;
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
}
