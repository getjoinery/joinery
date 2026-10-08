<?php
/**
 * BackupChainListHelper — the restore points a node actually has.
 *
 * The fleet's scheduled backups are CHAINS: one full plus the incrementals that
 * depend on it, in a directory of their own in backup storage. The flat file listing
 * cannot represent that. It sees `files-0003.tar.gz.enc` as one more archive
 * and offers to restore it, which would apply an incremental with no full under
 * it — not a smaller restore, no restore at all.
 *
 * So chains are listed as chains: one row per chain, with the runs inside it as
 * the restore points, read from the manifest that is the restore contract.
 *
 * @version 1.7 - chains are listed from every live storage space of the node, each naming its space;
 *                chain_path() is a space's (specs/storage_targets.md WP4)
 * @version 1.6 - chains are listed from the target the node names, switched off included; nothing is inferred
 * @version 1.5 - a chain carries its manifest version and each run the level of every artifact that records
 *                one (manifest version 2), so a reader can plan a restore per kind from the listing
 * @version 1.4 - format_size() is BackupRunner::human(): decimal units, one format for every backup size
 * @version 1.3 - the listing is this node's own prefix, every page of it (S3Signer::list), not the first
 *                2000 keys of the whole target: ten thousand offloaded-file objects under one node's
 *                objects/ would otherwise push another node's chain manifests off the end and empty its
 *                list. The pass also totals the object store per profile ('objects': count, bytes,
 *                epochs) for the node tab's "Offloaded files in backup storage" line
 * @version 1.2 - each run also carries its artifacts' sizes by kind (files, db, meta), so a page can
 *                say how much room a rehearsal of that run needs without reading the manifest again
 * @version 1.1 - backup storage is resolved via JobCommandBuilder::get_target(), so a node that names no
 *                target still has its chains listed (from the sole enabled backup storage) instead of appearing
 *                to have no restore points
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

class BackupChainListHelper {

	/**
	 * Is this object key part of a chain directory rather than a standalone
	 * archive? The flat listing uses this to leave chain artifacts out.
	 */
	public static function is_chain_object($key) {
		return (bool)preg_match('#/' . preg_quote(BackupChain::DIR_PREFIX, '#') . '[^/]+/#', (string)$key);
	}

	/**
	 * The bucket path a chain lives at, in the storage space that holds it.
	 *
	 * `{space base}{profile}/{chain_id}`. The profile segment is not
	 * decoration: a site backs itself up and a management node takes its own
	 * copies, and those are two parties' backups under two recovery keys. A
	 * restore that guessed the segment would look for a management node's chain
	 * in the own backup storage.
	 */
	public static function chain_path(StorageSpace $space, $profile, $chain_id) {
		return $space->base() . BackupProfile::path_segment($profile) . '/' . $chain_id;
	}

	/**
	 * Chains in this node's backup storage, newest first, across every space
	 * that still holds its backups: the one new backups go to, and any it was
	 * moved away from (switched-off targets included), each chain naming its
	 * space.
	 *
	 * Each: ['chain_id', 'space_id', 'space_state', 'target_name', 'created', 'updated',
	 * 'runs' => [['seq','level','time','bytes', 'artifacts' => [kind => bytes]]], 'bytes'].
	 * Returns ['chains' => [...], 'objects' => [profile => ['count', 'bytes', 'epochs']],
	 * 'error' => ?string]. 'objects' is the object store — the offloaded files
	 * each profile keeps once under objects/ — as the listings show it.
	 * A space that cannot be listed is named in 'error'; the others still list.
	 */
	public static function for_node($node, $max_chains = 20) {
		$chains = [];
		$objects = [];
		$errors = [];
		foreach (StorageSpace::of_owner(StorageSpace::OWNER_NODE, (int)$node->key) as $space) {
			$one = self::for_space($space, $max_chains);
			if ($one['error'] !== null) {
				$errors[] = $one['error'];
			}
			foreach ($one['chains'] as $c) { $chains[] = $c; }
			foreach ($one['objects'] as $profile => $o) {
				$objects[$profile] = $objects[$profile] ?? ['count' => 0, 'bytes' => 0, 'epochs' => 0];
				$objects[$profile]['count']  += $o['count'];
				$objects[$profile]['bytes']  += $o['bytes'];
				$objects[$profile]['epochs'] += $o['epochs'];
			}
		}
		usort($chains, function ($a, $b) { return strcmp($b['chain_id'], $a['chain_id']); });
		return ['chains' => array_slice($chains, 0, $max_chains), 'objects' => $objects,
			'error' => $errors ? implode('; ', $errors) : null];
	}

	/** for_node() for one space. */
	public static function for_space(StorageSpace $space, $max_chains = 20) {
		try {
			list($target, $creds, $bucket) = $space->reach();
		} catch (StorageSpaceException $e) {
			return ['chains' => [], 'objects' => [], 'error' => $e->getMessage()];
		}
		$node_prefix = $space->base();
		// This space only, every page of it: backup storage holds one object
		// per offloaded file, and a cap on the whole target would fill with them.
		try {
			$files = S3Signer::list($creds, $bucket, $node_prefix);
		} catch (Exception $e) {
			return ['chains' => [], 'objects' => [], 'error' => $space->describe() . ': ' . $e->getMessage()];
		}

		// Gather the manifests, the byte total of each chain's objects, and the
		// object store per profile, in one pass over the listing. Keys read
		// {slug}/{profile}/{chain_id}/{name}, and the profile has to be carried
		// through: it is part of the path a restore reads from, and it names
		// which party's backup this is.
		$manifest_keys = [];
		$profiles = [];
		$sizes = [];
		$objects = [];
		foreach ($files as $f) {
			$key = $f['key'];
			if (strpos($key, $node_prefix) !== 0) { continue; }
			$parts = explode('/', substr($key, strlen($node_prefix)));
			if (count($parts) < 3) { continue; }

			list($profile, $dir) = $parts;
			if ($dir === BackupObjects::DIR) {
				// objects/{epoch}/{name}.enc, and one envelope.json per epoch.
				if (count($parts) !== 4 || strpos($parts[2], BackupObjects::EPOCH_PREFIX) !== 0) { continue; }
				$objects[$profile] = $objects[$profile] ?? ['count' => 0, 'bytes' => 0, 'epochs' => []];
				$objects[$profile]['epochs'][$parts[2]] = true;
				if (substr($parts[3], -strlen(BackupObjects::OBJECT_SUFFIX)) === BackupObjects::OBJECT_SUFFIX) {
					$objects[$profile]['count']++;
					$objects[$profile]['bytes'] += (int)$f['size'];
				}
				continue;
			}
			if (strpos($dir, BackupChain::DIR_PREFIX) !== 0) { continue; }

			$sizes[$dir] = ($sizes[$dir] ?? 0) + (int)$f['size'];
			$profiles[$dir] = $profile;
			if (end($parts) === BackupChain::MANIFEST_NAME && count($parts) === 3) {
				$manifest_keys[$dir] = $key;
			}
		}

		krsort($manifest_keys);            // chain ids sort chronologically by name
		$manifest_keys = array_slice($manifest_keys, 0, $max_chains, true);
		foreach ($objects as &$o) { $o['epochs'] = count($o['epochs']); }
		unset($o);

		$chains = [];
		foreach ($manifest_keys as $chain_id => $key) {
			try {
				$resp = S3Signer::get($creds, $bucket, '/' . ltrim($key, '/'));
			} catch (Exception $e) {
				continue;
			}
			if ((int)($resp['status'] ?? 0) !== 200) { continue; }

			$m = json_decode($resp['body'], true);
			if (!is_array($m) || empty($m['runs'])) { continue; }

			$runs = [];
			foreach ($m['runs'] as $r) {
				$bytes = 0;
				$by_kind = [];
				$levels = [];
				foreach (($r['artifacts'] ?? []) as $kind => $a) {
					$bytes += (int)($a['bytes'] ?? 0);
					$by_kind[(string)$kind] = (int)($a['bytes'] ?? 0);
					if (isset($a['level'])) { $levels[(string)$kind] = (int)$a['level']; }
				}
				$runs[] = [
					'seq'       => (int)($r['seq'] ?? count($runs)),
					'level'     => (int)($r['level'] ?? 1),
					'time'      => (string)($r['time'] ?? ''),
					'bytes'     => $bytes,
					'artifacts' => $by_kind,
					'levels'    => $levels,
				];
			}

			$chains[] = [
				'chain_id' => (string)$chain_id,
				'space_id' => (int)$space->key,
				'space_state' => (string)$space->get('sps_state'),
				'target_name' => (string)$target->get('bkt_name'),
				'version'  => (int)($m['version'] ?? 1),
				'profile'  => (string)($profiles[$chain_id] ?? BackupProfile::MANAGER),
				'created'  => (string)($m['created'] ?? ''),
				'updated'  => (string)($m['updated'] ?? ''),
				'runs'     => $runs,
				'bytes'    => (int)($sizes[$chain_id] ?? 0),
			];
		}

		return ['chains' => $chains, 'objects' => $objects, 'error' => null];
	}

	/** Bytes as a short human string — BackupRunner::human(), the one backup-size format. */
	public static function format_size($bytes) {
		return BackupRunner::human((int)$bytes);
	}
}
