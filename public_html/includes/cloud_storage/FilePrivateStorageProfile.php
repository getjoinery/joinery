<?php
/**
 * FilePrivateStorageProfile — the private offload consumer for restricted files.
 *
 * The public FileStorageProfile drains world-readable files to the public
 * bucket. This profile is its mirror for files that carry ANY access
 * restriction (min-permission, group, event, or tier): they drain to the
 * verified-private bucket and are served back through serve.php's permission-
 * gated stream, never a public URL.
 *
 * It reuses every File method the public profile uses (the same key scheme,
 * disk placement, and per-row enumeration via the inherited itemsForRow /
 * reverseItemsForRow) and overrides only what differs: the visibility, the
 * eligibility (restricted-and-not-deleted instead of public), and the ownership
 * gate. The single CloudOffloadRun tick drives this profile by mode like any
 * other — the private store is enabled/drained independently via its own latch
 * and draining flag, no separate task needed.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/FileStorageProfile.php'));

class FilePrivateStorageProfile extends FileStorageProfile {

	public function visibility(): string { return 'private'; }

	/**
	 * Both private gates are DERIVED from the public store's gate
	 * (parent::eligibilityWhere(), which is "not deleted AND no restriction"),
	 * so the two stores can never disagree about which fil_files rows are theirs
	 * — add a permission column to the public gate one day and both of these
	 * follow automatically. parent:: binds to FileStorageProfile statically, so
	 * there is no override recursion.
	 *
	 * Ownership (reverse/drain/count): the exact complement of public — every
	 * 'cloud' row of fil_files belongs to exactly one store. Includes
	 * soft-deleted rows, which stay in the private bucket and are never served,
	 * so a drain must reclaim them too.
	 */
	public function reverseEligibilityWhere(): string {
		return 'NOT (' . parent::eligibilityWhere() . ')';
	}

	/**
	 * Forward offload: the private rows worth pushing — not deleted, restricted.
	 * That is the ownership gate minus the soft-deleted rows (a deleted file is
	 * pulled home, not pushed — see File::move_to_correct_directory()).
	 */
	public function eligibilityWhere(): string {
		return 'fil_delete_time IS NULL AND ' . $this->reverseEligibilityWhere();
	}

	public function isEligibleRow(int $id): bool {
		$file = new File($id, true);
		if (!$file->key) {
			return false;
		}
		$flag = $file->get('fil_storage_driver');
		$is_local = ($flag === null || $flag === '' || $flag === 'local');
		// Restricted = not public, but exclude soft-deleted rows (is_public()
		// also returns false for those — they belong home, not in a bucket).
		return $is_local && !$file->get('fil_delete_time') && !$file->is_public();
	}
}
