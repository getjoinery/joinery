<?php
/**
 * BlobStorageProfile — the public offload consumer for file blobs.
 *
 * A thin adapter over FileBlob (fbb_file_blobs), re-expressing its physical
 * methods through the StorageProfile seam so the shared CloudOffloadEngine +
 * CloudStorageLifecycle can drive the public-blob offload. No blob code moves
 * here — this only maps the fbb_ descriptor columns and enumerates the
 * original + FileBlob::variant_size_keys() slots per blob (registry sizes for
 * images, the recorded encrypted-thumbnail slot for ciphertext blobs).
 *
 * fbb_file_blobs is shared by the public and private blob profiles, split by
 * fbb_is_private (the same shared-table mechanism the old File pair used). The
 * public profile owns the world-readable blobs (fbb_is_private = FALSE); the
 * private subclass owns the rest.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfile.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));

class BlobStorageProfile implements StorageProfile {

	public function table(): string            { return 'fbb_file_blobs'; }
	public function pkeyColumn(): string        { return 'fbb_file_blob_id'; }
	public function driverColumn(): string      { return 'fbb_storage_driver'; }
	public function failedCountColumn(): string { return 'fbb_sync_failed_count'; }
	public function lastAttemptColumn(): string { return 'fbb_sync_last_attempt'; }

	public function visibility(): string { return 'public'; }

	/** Public store: the world-readable blobs. */
	public function eligibilityWhere(): string {
		return 'fbb_is_private = FALSE';
	}

	/**
	 * Ownership gate for the reverse/drain path when the table is shared. Every
	 * cloud blob belongs to exactly one store, split by fbb_is_private — for the
	 * public store the forward and ownership gates coincide.
	 */
	public function reverseEligibilityWhere(): string {
		return $this->eligibilityWhere();
	}

	public function rowExists(int $id): bool {
		$blob = new FileBlob($id, true);
		return (bool)$blob->key;
	}

	public function isEligibleRow(int $id): bool {
		$blob = new FileBlob($id, true);
		if (!$blob->key) {
			return false;
		}
		$flag = $blob->get('fbb_storage_driver');
		$is_local = ($flag === null || $flag === '' || $flag === 'local');
		return $is_local && !$blob->is_private_bool();
	}

	public function itemsForRow(int $id): ?array {
		$blob = new FileBlob($id, true);
		if (!$blob->key) {
			return null;
		}
		$content_type = $blob->get('fbb_mime_type') ?: 'application/octet-stream';

		$original_path = $blob->filesystem_path('original');
		if (!file_exists($original_path)) {
			return null; // required bytes missing → engine records a failure
		}
		$items = [[
			'local_path'   => $original_path,
			'remote_key'   => $blob->remote_key_for('original'),
			'content_type' => $content_type,
		]];
		foreach ($blob->variant_size_keys() as $size_key) {
			$variant_path = $blob->filesystem_path($size_key);
			if (file_exists($variant_path)) {
				$items[] = [
					'local_path'   => $variant_path,
					'remote_key'   => $blob->remote_key_for($size_key),
					'content_type' => $content_type,
				];
			}
		}
		return $items;
	}

	public function reverseItemsForRow(int $id): array {
		$blob = new FileBlob($id, true);
		if (!$blob->key) {
			return [];
		}
		$settings       = Globalvars::get_instance();
		$restricted_dir = $settings->get_setting('upload_dir');
		$fast_dir       = dirname($restricted_dir) . '/static_files/uploads';
		// Placement follows the blob's own visibility class.
		$target_dir   = $blob->is_private_bool() ? $restricted_dir : $fast_dir;
		$stored_name  = $blob->get('fbb_stored_name');
		$content_type = $blob->get('fbb_mime_type') ?: 'application/octet-stream';

		$size_keys = array_merge(['original'], $blob->variant_size_keys());

		$items = [];
		foreach ($size_keys as $size_key) {
			$local_path = ($size_key === 'original')
				? $target_dir . '/' . $stored_name
				: $target_dir . '/' . $size_key . '/' . $stored_name;
			$items[] = [
				'remote_key'   => $blob->remote_key_for($size_key),
				'local_path'   => $local_path,
				'content_type' => $content_type,
			];
		}
		return $items;
	}
}
