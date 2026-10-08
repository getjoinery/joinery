<?php
/**
 * TargetBackups — what is actually stored on a backup target.
 *
 * Backups live under {bkt_path_prefix}/{slug}/{filename}. This lists them straight
 * from the bucket via S3Signer, so nothing has to be running for them to be
 * reachable — which is the point, since the case that matters is the one where
 * the machine that made them is gone.
 *
 * Objects are grouped by their slug segment. Classifying those slugs needs to
 * know who owns them, and only the caller knows that (a map entry may name the
 * status, the owner and its storage space outright): a standalone site owns
 * exactly one slug, while a management node owns a whole fleet. So the ownership
 * map is passed IN (see group_objects), and server_manager's FleetBackups supplies
 * the fleet-wide one. Nothing here reads the node table.
 *
 *   live           — a current owner has this slug
 *   decommissioned — a former owner had this slug (the site is gone; its backups remain)
 *   orphaned       — nothing in the map matches this slug
 *
 * @version 2.2 - the folder is the target's normalised prefix (BackupTarget::prefix()); a caller's map may
 *                name a folder's status, owner and storage space; slug_backup_count() is gone (a node's
 *                backups are counted from its storage spaces)
 * @version 2.1 - slug_backup_count() counts switched-off targets too: they still hold backups
 * @version 2.0 - moved to core; slug ownership is supplied by the caller rather than
 *                read from the fleet node table
 */

require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));

class TargetBackupsException extends Exception {}

class TargetBackups {

	/** The target's base key prefix, always normalized to a single trailing slash. */
	public static function base_prefix($target) {
		return BackupTarget::normalise_prefix((string)$target->get('bkt_path_prefix')) . '/';
	}

	/**
	 * List the target's objects grouped by slug. Returns:
	 *   ['groups' => [slug => ['slug','status','node_id','objects'=>[...],'count','bytes']],
	 *    'total_objects' => int, 'total_bytes' => int]
	 * Throws TargetBackupsException on a credential or listing failure.
	 */
	public static function list_grouped($target, array $node_map = []) {
		$creds  = self::creds_or_throw($target);
		$bucket = self::bucket_or_throw($target);
		$base   = self::base_prefix($target);

		try {
			$objects = S3Signer::list($creds, $bucket, $base);
		} catch (S3SignerException $e) {
			throw new TargetBackupsException($e->getMessage());
		}

		return self::group_objects($objects, $base, $node_map);
	}

	/**
	 * Pure grouping/classification: turn a flat object list into per-slug groups,
	 * tagging each against $node_map (slug => ['node_id','deleted']). Separated from
	 * the network/credential fetch so it is directly testable.
	 */
	public static function group_objects(array $objects, $base, array $node_map) {
		$groups = [];
		$total_bytes = 0;
		$total_objects = 0;
		foreach ($objects as $obj) {
			$rest = substr((string)$obj['key'], strlen($base));
			if ($rest === '' || $rest === false) { continue; } // the prefix "folder" marker itself
			$slug = explode('/', $rest)[0];
			if ($slug === '') { continue; }
			if (!isset($groups[$slug])) {
				$known  = is_array($node_map[$slug] ?? null) ? $node_map[$slug] : null;
				$status = $known === null ? 'orphaned'
					: (string)($known['status'] ?? (!empty($known['deleted']) ? 'decommissioned' : 'live'));
				$groups[$slug] = [
					'slug' => $slug, 'status' => $status,
					'node_id' => $known === null ? null : ($known['node_id'] ?? null),
					'owner' => $known === null ? '' : (string)($known['owner'] ?? ''),
					'space_id' => $known === null ? null : ($known['space_id'] ?? null),
					'objects' => [], 'count' => 0, 'bytes' => 0,
				];
			}
			$groups[$slug]['objects'][] = $obj;
			$groups[$slug]['count']++;
			$groups[$slug]['bytes'] += (int)$obj['size'];
			$total_bytes += (int)$obj['size'];
			$total_objects++;
		}
		ksort($groups);
		return ['groups' => $groups, 'total_objects' => $total_objects, 'total_bytes' => $total_bytes];
	}

	/**
	 * Delete every object under {base}{slug}/. Returns the number deleted. The slug is
	 * validated so a crafted value cannot widen the delete beyond one site's prefix.
	 */
	public static function delete_prefix($target, $slug) {
		if (!preg_match('/^[A-Za-z0-9_-]+$/', (string)$slug)) {
			throw new TargetBackupsException('Invalid site name.');
		}
		$creds  = self::creds_or_throw($target);
		$bucket = self::bucket_or_throw($target);
		$prefix = self::base_prefix($target) . $slug . '/';

		try {
			$objects = S3Signer::list($creds, $bucket, $prefix);
		} catch (S3SignerException $e) {
			throw new TargetBackupsException($e->getMessage());
		}
		$deleted = 0;
		foreach ($objects as $obj) {
			self::delete_key($creds, $bucket, (string)$obj['key'], $deleted);
			$deleted++;
		}
		return $deleted;
	}

	/**
	 * Delete a single object. The key must sit under this target's base prefix, so a
	 * forged key cannot reach an unrelated object in the bucket.
	 */
	public static function delete_object($target, $key) {
		$base = self::base_prefix($target);
		$key  = (string)$key;
		if (strpos($key, $base) !== 0) {
			throw new TargetBackupsException('Refusing to delete a key outside this target prefix.');
		}
		$creds  = self::creds_or_throw($target);
		$bucket = self::bucket_or_throw($target);
		$n = 0;
		self::delete_key($creds, $bucket, $key, $n);
		return true;
	}

	// ── internals ──

	private static function delete_key($creds, $bucket, $key, $already) {
		$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
		$status = (int)$resp['status'];
		if ($status < 200 || $status >= 300) {
			$msg = S3Signer::extract_error($resp['body']) ?: ('HTTP ' . $status);
			throw new TargetBackupsException(
				'Delete failed for ' . $key . ': ' . $msg
				. ($already > 0 ? " ({$already} already deleted)." : '.')
			);
		}
	}

	private static function creds_or_throw($target) {
		try {
			$creds = $target->get_credentials();
		} catch (Exception $e) {
			throw new TargetBackupsException("Cannot read this target's credentials: " . $e->getMessage());
		}
		if (empty($creds)) {
			throw new TargetBackupsException('This target has no stored credentials.');
		}
		return $creds;
	}

	private static function bucket_or_throw($target) {
		$bucket = trim((string)$target->get('bkt_bucket'));
		if ($bucket === '') {
			throw new TargetBackupsException('This target has no bucket configured.');
		}
		return $bucket;
	}

}
