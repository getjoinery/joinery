<?php
/**
 * BlobPrivateStorageProfile — the private offload consumer for restricted blobs.
 *
 * The mirror of BlobStorageProfile: blobs whose bytes are private
 * (fbb_is_private = TRUE) drain to the verified-private bucket and are served
 * back through serve.php's permission-gated stream, never a public URL. It
 * reuses every enumeration method of the public profile and overrides only the
 * visibility and the eligibility split. The single CloudOffloadRun tick drives
 * this profile like any other; the private store is enabled/drained via its own
 * latch.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/BlobStorageProfile.php'));

class BlobPrivateStorageProfile extends BlobStorageProfile {

	public function visibility(): string { return 'private'; }

	public function eligibilityWhere(): string {
		return 'fbb_is_private = TRUE';
	}

	/** Ownership: the exact complement of the public store. */
	public function reverseEligibilityWhere(): string {
		return $this->eligibilityWhere();
	}

	public function isEligibleRow(int $id): bool {
		$blob = new FileBlob($id, true);
		if (!$blob->key) {
			return false;
		}
		$flag = $blob->get('fbb_storage_driver');
		$is_local = ($flag === null || $flag === '' || $flag === 'local');
		return $is_local && $blob->is_private_bool();
	}
}
