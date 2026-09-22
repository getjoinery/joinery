<?php
/**
 * BlobStorageProfile — the offload consumer for private file blobs.
 *
 * A thin adapter over FileBlob (fbb_file_blobs), re-expressing its physical
 * methods through the StorageProfile seam so the shared CloudOffloadEngine +
 * CloudStorageLifecycle can drive the blob offload. No blob code moves
 * here — this only maps the fbb_ descriptor columns and enumerates the
 * original + FileBlob::variant_size_keys() slots per blob (registry sizes for
 * images, the recorded encrypted-thumbnail slot for ciphertext blobs).
 *
 * Only a private blob (fbb_is_private = TRUE) is eligible: member uploads,
 * Drive files, sealed vault files. A public blob — anything a page serves —
 * is never eligible and stays on this server, so nothing on a page is ever
 * served from the bucket. A cloud blob that is made public is pulled back
 * before its record flips (FileBlob::flipVisibility()).
 *
 * @version 1.4 - lastErrorColumn()
 * @version 1.3 - one private store: visibility() answers private, eligibility is fbb_is_private = TRUE,
 *                the public profile is gone (specs/cloud_storage_private_only.md)
 * @version 1.2 - sizeColumn(): the health figures carry bytes beside counts
 * @version 1.1 - backupObjects()/backupObject(): the enumeration the backup's object store reads —
 *                every cloud row of this store with its name and the local paths its bytes
 *                occupy or would occupy (specs/backup_offloaded_files.md). A capability the
 *                backup probes with method_exists(), the way the engine probes putMany().
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
	public function lastErrorColumn(): string   { return 'fbb_sync_last_error'; }
	/** The column a row's size is read from, so the status can say how much sits where. */
	public function sizeColumn(): string        { return 'fbb_size_bytes'; }

	public function visibility(): string { return 'private'; }

	/** Only a private blob moves; a public blob is never eligible. */
	public function eligibilityWhere(): string {
		return 'fbb_is_private = TRUE';
	}

	/**
	 * Ownership gate for the reverse/drain path and the cloud-row count: the
	 * cloud rows that are this store's. A public blob never reaches the bucket,
	 * so the forward and ownership gates coincide.
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
		return $is_local && $blob->is_private_bool();
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

	/**
	 * Every row of THIS store whose bytes live in the cloud, as the backup's
	 * object store sees them: the immutable object name (fbb_stored_name), the
	 * local path the original occupies while it waits for a shelf, every local
	 * path original and variants occupy (the archive's exclude list), and how
	 * to fetch the original from the file store when no local copy is left.
	 * One query; the placement is computed, never stat()ed here.
	 */
	public function backupObjects(): array {
		$blobs = new MultiFileBlob(
			['storage_driver' => 'cloud', 'is_private' => true],
			['fbb_file_blob_id' => 'ASC']);
		$out = [];
		foreach ($blobs as $blob) {
			$out[] = $this->describe_for_backup($blob);
		}
		return $out;
	}

	/** One row in the shape backupObjects() lists, or null when the row is gone or not this store's. */
	public function backupObject(int $id): ?array {
		$blob = new FileBlob($id, true);
		if (!$blob->key || !$blob->is_private_bool()) {
			return null;
		}
		return $this->describe_for_backup($blob);
	}

	private function describe_for_backup(FileBlob $blob): array {
		// Both placements, not only the visibility's own: a blob's bytes may sit
		// in either directory during a visibility flip (filesystem_path() looks
		// in both), and a path the archive should skip is a path in either.
		$settings       = Globalvars::get_instance();
		$restricted_dir = rtrim((string)$settings->get_setting('upload_dir'), '/');
		$fast_dir       = dirname($restricted_dir) . '/static_files/uploads';
		$own_dir        = $blob->is_private_bool() ? $restricted_dir : $fast_dir;
		$name           = (string)$blob->get('fbb_stored_name');

		$paths = [];
		foreach ([$own_dir, ($own_dir === $fast_dir ? $restricted_dir : $fast_dir)] as $dir) {
			$paths[] = $dir . '/' . $name;
			foreach ($blob->variant_size_keys() as $size_key) {
				$paths[] = $dir . '/' . $size_key . '/' . $name;
			}
		}
		$original = $own_dir . '/' . $name;
		foreach ([$fast_dir . '/' . $name, $restricted_dir . '/' . $name] as $candidate) {
			if (is_file($candidate)) { $original = $candidate; break; }
		}
		return [
			'id'           => (int)$blob->key,
			'name'         => (string)$blob->get('fbb_stored_name'),
			'original'     => $original,
			'paths'        => $paths,
			'remote_key'   => $blob->remote_key_for('original'),
			'content_type' => $blob->get('fbb_mime_type') ?: 'application/octet-stream',
			'visibility'   => $this->visibility(),
		];
	}

	public function reverseItemsForRow(int $id): array {
		$blob = new FileBlob($id, true);
		if (!$blob->key) {
			return [];
		}
		return $this->placement($blob);
	}

	/** The reverse enumeration for a blob already in hand: no second load per row. */
	private function placement(FileBlob $blob): array {
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
