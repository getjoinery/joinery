<?php
/**
 * FileStorageProfile — the first (public) offload consumer.
 *
 * A thin adapter over the existing File model methods (get_filesystem_path,
 * remote_key_for, is_public, is_image). No File code moves here; this profile
 * only re-expresses those methods through the StorageProfile seam so the
 * unified engine + lifecycle can drive the public-files offload exactly as the
 * standalone CloudStorageSync / CloudStorageReverseSync tasks did.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfile.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));

class FileStorageProfile implements StorageProfile {

	public function table(): string            { return 'fil_files'; }
	public function pkeyColumn(): string        { return 'fil_file_id'; }
	public function driverColumn(): string      { return 'fil_storage_driver'; }
	public function failedCountColumn(): string { return 'fil_sync_failed_count'; }
	public function lastAttemptColumn(): string { return 'fil_sync_last_attempt'; }

	public function visibility(): string { return 'public'; }

	public function forwardTaskClass(): string { return 'CloudStorageSync'; }
	public function reverseTaskClass(): string { return 'CloudStorageReverseSync'; }

	/**
	 * The is_public() permission gates, mirrored as SQL so the batch query
	 * lines up with isEligibleRow(). Verbatim from the original CloudStorageSync
	 * eligibility query (minus the generic driver/failed-count/limit clauses the
	 * engine owns).
	 */
	public function eligibilityWhere(): string {
		return "fil_delete_time IS NULL
			AND (fil_min_permission IS NULL OR fil_min_permission = 0)
			AND (fil_grp_group_id IS NULL OR fil_grp_group_id = 0)
			AND (fil_evt_event_id IS NULL OR fil_evt_event_id = 0)
			AND (fil_tier_min_level IS NULL OR fil_tier_min_level = 0)";
	}

	public function rowExists(int $id): bool {
		$file = new File($id, true);
		return (bool)$file->key;
	}

	public function isEligibleRow(int $id): bool {
		$file = new File($id, true);
		if (!$file->key) {
			return false;
		}
		$flag = $file->get('fil_storage_driver');
		$is_local = ($flag === null || $flag === '' || $flag === 'local');
		return $is_local && $file->is_public();
	}

	public function itemsForRow(int $id): ?array {
		$file = new File($id, true);
		if (!$file->key) {
			return null;
		}
		$content_type = $file->get('fil_type') ?: 'application/octet-stream';

		$original_path = $file->get_filesystem_path('original');
		if (!file_exists($original_path)) {
			return null; // required bytes missing → engine records a failure
		}
		$items = [[
			'local_path'   => $original_path,
			'remote_key'   => $file->remote_key_for('original'),
			'content_type' => $content_type,
		]];
		if ($file->is_image()) {
			foreach (ImageSizeRegistry::get_sizes() as $size_key => $cfg) {
				$variant_path = $file->get_filesystem_path($size_key);
				if (file_exists($variant_path)) {
					$items[] = [
						'local_path'   => $variant_path,
						'remote_key'   => $file->remote_key_for($size_key),
						'content_type' => $content_type,
					];
				}
			}
		}
		return $items;
	}

	public function reverseItemsForRow(int $id): array {
		$file = new File($id, true);
		if (!$file->key) {
			return [];
		}
		$settings       = Globalvars::get_instance();
		$restricted_dir = $settings->get_setting('upload_dir');
		$fast_dir       = dirname($restricted_dir) . '/static_files/uploads';
		// Placement is re-evaluated against the freshly-loaded row (don't trust
		// was-public-when-pushed) — same rule the original _pull_row applied.
		$target_dir   = $file->is_public() ? $fast_dir : $restricted_dir;
		$filename     = $file->get('fil_name');
		$content_type = $file->get('fil_type') ?: 'application/octet-stream';

		$size_keys = ['original'];
		if ($file->is_image()) {
			foreach (ImageSizeRegistry::get_sizes() as $size_key => $cfg) {
				$size_keys[] = $size_key;
			}
		}

		$items = [];
		foreach ($size_keys as $size_key) {
			$local_path = ($size_key === 'original')
				? $target_dir . '/' . $filename
				: $target_dir . '/' . $size_key . '/' . $filename;
			$items[] = [
				'remote_key'   => $file->remote_key_for($size_key),
				'local_path'   => $local_path,
				'content_type' => $content_type,
			];
		}
		return $items;
	}
}
