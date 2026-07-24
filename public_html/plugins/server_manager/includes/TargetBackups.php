<?php
/**
 * TargetBackups — a control-plane view of what is actually stored on a backup target.
 *
 * Offsite backups live under {bkt_path_prefix}/{mgn_slug}/{filename}. This lists them
 * straight from the bucket via S3Signer, so no live node is required — a decommissioned
 * site's backups stay reachable and deletable. Objects are grouped by their slug segment
 * and each group is tagged against the node table:
 *
 *   live          — a non-deleted node owns this slug
 *   decommissioned — a soft-deleted node owned this slug (the site is gone; its backups remain)
 *   orphaned      — no node, deleted or otherwise, matches this slug
 *
 * @version 1.1 - slug_backup_count(): blocks hard-deleting a node record while its backups exist
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));

class TargetBackupsException extends Exception {}

class TargetBackups {

	/** The target's base key prefix, always normalized to a single trailing slash. */
	public static function base_prefix($target) {
		$p = trim((string)$target->get('bkt_path_prefix'));
		if ($p === '') { $p = 'joinery-backups'; }
		return rtrim($p, '/') . '/';
	}

	/**
	 * List the target's objects grouped by slug. Returns:
	 *   ['groups' => [slug => ['slug','status','node_id','objects'=>[...],'count','bytes']],
	 *    'total_objects' => int, 'total_bytes' => int]
	 * Throws TargetBackupsException on a credential or listing failure.
	 */
	public static function list_grouped($target) {
		$creds  = self::creds_or_throw($target);
		$bucket = self::bucket_or_throw($target);
		$base   = self::base_prefix($target);

		try {
			$objects = S3Signer::list($creds, $bucket, $base);
		} catch (S3SignerException $e) {
			throw new TargetBackupsException($e->getMessage());
		}

		return self::group_objects($objects, $base, self::slug_status_map());
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
				$status = $known === null ? 'orphaned' : (!empty($known['deleted']) ? 'decommissioned' : 'live');
				$groups[$slug] = [
					'slug' => $slug, 'status' => $status,
					'node_id' => $known === null ? null : ($known['node_id'] ?? null),
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

	/**
	 * Count the offsite backups stored under a node slug across every enabled
	 * target. Used to block hard-deleting a node record while its backups still
	 * exist (deleting the record orphans them from the node they belong to).
	 *
	 * Returns ['count' => int, 'unchecked' => string[]] where 'unchecked' names any
	 * target that could not be listed (bad credentials, unreachable). A caller that
	 * wants to fail safe should treat a non-empty 'unchecked' as "cannot confirm zero".
	 */
	public static function slug_backup_count($slug) {
		if (!preg_match('/^[A-Za-z0-9_-]+$/', (string)$slug)) {
			return ['count' => 0, 'unchecked' => []]; // no valid prefix → no prefixed backups
		}
		$targets = new MultiBackupTarget(['deleted' => false, 'enabled' => true]);
		$targets->load();

		$count = 0;
		$unchecked = [];
		foreach ($targets as $t) {
			$bucket = trim((string)$t->get('bkt_bucket'));
			if ($bucket === '') { continue; } // nothing configured → nothing stored here
			try {
				$creds = $t->get_credentials();
				if (empty($creds)) { continue; }
				$prefix = self::base_prefix($t) . $slug . '/';
				$count += count(S3Signer::list($creds, $bucket, $prefix));
			} catch (Exception $e) {
				$unchecked[] = (string)$t->get('bkt_name');
			}
		}
		return ['count' => $count, 'unchecked' => $unchecked];
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

	/** Map mgn_slug => ['node_id','deleted'] across ALL nodes (soft-deleted included). */
	private static function slug_status_map() {
		$map = [];
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->query('SELECT mgn_id, mgn_slug, mgn_delete_time FROM mgn_managed_nodes');
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$map[$row['mgn_slug']] = [
				'node_id' => (int)$row['mgn_id'],
				'deleted' => $row['mgn_delete_time'] !== null,
			];
		}
		return $map;
	}
}
