<?php
/**
 * BackupChainListHelper — the restore points a node actually has.
 *
 * The fleet's scheduled backups are CHAINS: one full plus the incrementals that
 * depend on it, in a directory of their own on the shelf. The flat file listing
 * cannot represent that. It sees `files-0003.tar.gz.enc` as one more archive
 * and offers to restore it, which would apply an incremental with no full under
 * it — not a smaller restore, no restore at all.
 *
 * So chains are listed as chains: one row per chain, with the runs inside it as
 * the restore points, read from the manifest that is the restore contract.
 *
 * @version 1.1 - the shelf is resolved via JobCommandBuilder::get_target(), so a node that names no
 *                target still has its chains listed (from the sole enabled shelf) instead of appearing
 *                to have no restore points
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('includes/TargetLister.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
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
	 * The bucket path a chain lives at.
	 *
	 * `{prefix}/{slug}/{profile}/{chain_id}/`. The profile segment is not
	 * decoration: a site backs itself up and a management node takes its own
	 * copies, and those are two parties' backups under two recovery keys. A
	 * restore that guessed the segment would look for a management node's chain
	 * on the site's own shelf.
	 */
	public static function chain_path($target, $slug, $profile, $chain_id) {
		$prefix = rtrim((string)($target->get('bkt_path_prefix') ?: 'joinery-backups'), '/');
		return $prefix . '/' . $slug . '/' . BackupProfile::path_segment($profile) . '/' . $chain_id;
	}

	/**
	 * Chains on this node's shelf, newest first.
	 *
	 * Each: ['chain_id', 'created', 'updated', 'runs' => [['seq','level','time','bytes']], 'bytes'].
	 * Returns ['chains' => [...], 'error' => ?string]. An unreachable shelf is an
	 * error to report, never an empty list — "no restore points" and "we could
	 * not ask" must not look the same.
	 */
	public static function for_node($node, $max_chains = 20) {
		// Resolve the shelf the SAME way the job builder does, so a node that names
		// no target still has the chains it wrote to the sole enabled shelf listed
		// here. Reading the raw mgn_bkt_backup_target_id returned an empty list for
		// every such node — indistinguishable from "no restore points" when
		// backups were in fact landing fine. get_target returns only an enabled
		// target (or null), so no separate bkt_enabled check is needed.
		$target = JobCommandBuilder::get_target($node);
		if (!$target) {
			return ['chains' => [], 'error' => null];
		}

		$slug   = (string)$node->get('mgn_slug');
		$prefix = rtrim((string)($target->get('bkt_path_prefix') ?: 'joinery-backups'), '/') . '/';
		$node_prefix = $prefix . $slug . '/';

		$listing = TargetLister::list_files($target, 2000);
		if (!$listing['success']) {
			return ['chains' => [], 'error' => $listing['error'] ?? 'unknown error'];
		}

		// Gather the manifests, and the byte total of each chain's objects, in
		// one pass over the listing. Keys read {slug}/{profile}/{chain_id}/{name},
		// and the profile has to be carried through: it is part of the path a
		// restore reads from, and it names which party's backup this is.
		$manifest_keys = [];
		$profiles = [];
		$sizes = [];
		foreach ($listing['files'] as $f) {
			$key = $f['key'];
			if (strpos($key, $node_prefix) !== 0) { continue; }
			$parts = explode('/', substr($key, strlen($node_prefix)));
			if (count($parts) < 3) { continue; }

			list($profile, $dir) = $parts;
			if (strpos($dir, BackupChain::DIR_PREFIX) !== 0) { continue; }

			$sizes[$dir] = ($sizes[$dir] ?? 0) + (int)$f['size'];
			$profiles[$dir] = $profile;
			if (end($parts) === BackupChain::MANIFEST_NAME && count($parts) === 3) {
				$manifest_keys[$dir] = $key;
			}
		}

		krsort($manifest_keys);            // chain ids sort chronologically by name
		$manifest_keys = array_slice($manifest_keys, 0, $max_chains, true);

		$creds  = $target->get_credentials();
		$bucket = $target->get('bkt_bucket');

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
				foreach (($r['artifacts'] ?? []) as $a) { $bytes += (int)($a['bytes'] ?? 0); }
				$runs[] = [
					'seq'   => (int)($r['seq'] ?? count($runs)),
					'level' => (int)($r['level'] ?? 1),
					'time'  => (string)($r['time'] ?? ''),
					'bytes' => $bytes,
				];
			}

			$chains[] = [
				'chain_id' => (string)$chain_id,
				'profile'  => (string)($profiles[$chain_id] ?? BackupProfile::MANAGER),
				'created'  => (string)($m['created'] ?? ''),
				'updated'  => (string)($m['updated'] ?? ''),
				'runs'     => $runs,
				'bytes'    => (int)($sizes[$chain_id] ?? 0),
			];
		}

		return ['chains' => $chains, 'error' => null];
	}

	/** Bytes as a short human string, matching the flat file listing's style. */
	public static function format_size($bytes) {
		$bytes = (int)$bytes;
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i = 0;
		while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
		return round($bytes, ($i > 1 ? 1 : 0)) . ' ' . $units[$i];
	}
}
