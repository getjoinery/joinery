<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * DrivePurgeTrash — permanently delete Drive files and folders that have sat in
 * the trash longer than the retention window. Blob refcounts handle shared
 * bytes (a purged file only frees disk once the last reference lets go).
 */
class DrivePurgeTrash implements ScheduledTaskInterface {
	public function run(array $config) {
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		require_once(PathHelper::getIncludePath('data/folders_class.php'));
		require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));

		$days = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 30;
		if ($days <= 0) {
			$days = 30;
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$cutoff = "now() - (INTERVAL '1 day' * :days)";

		// Files first (releases blob refcounts); collect owners for a usage recompute.
		// ONLY Drive files (fil_source='drive'): fil_files is platform-wide, and a
		// soft-deleted avatar / store image / mail attachment belongs to its own
		// subsystem's lifecycle — Drive trash retention must never destroy it.
		$qf = $dblink->prepare(
			"SELECT fil_file_id, fil_usr_user_id FROM fil_files
			  WHERE fil_delete_time IS NOT NULL AND fil_delete_time < $cutoff
			    AND fil_source = 'drive'");
		$qf->execute(array(':days' => $days));
		$owners = array();
		$files_deleted = 0;
		foreach ($qf->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$file = new File((int)$row['fil_file_id'], true);
			if ($file->key) {
				$file->permanent_delete();
				$files_deleted++;
				$owners[(int)$row['fil_usr_user_id']] = true;
			}
		}

		// Then folders (any remaining files are orphaned to root via the FK 'null'
		// rule, but every trashed descendant was already caught above).
		$qfo = $dblink->prepare(
			"SELECT fol_folder_id FROM fol_folders
			  WHERE fol_delete_time IS NOT NULL AND fol_delete_time < $cutoff");
		$qfo->execute(array(':days' => $days));
		$folders_deleted = 0;
		foreach ($qfo->fetchAll(PDO::FETCH_COLUMN) as $fid) {
			$folder = new Folder((int)$fid, true);
			if ($folder->key) {
				$folder->permanent_delete();
				$folders_deleted++;
			}
		}

		foreach (array_keys($owners) as $uid) {
			DriveUsage::recompute($uid);
		}

		if ($files_deleted === 0 && $folders_deleted === 0) {
			return array('status' => 'success', 'message' => 'No trashed Drive items past the retention window');
		}
		return array('status' => 'success',
			'message' => 'Purged ' . $files_deleted . ' file(s) and ' . $folders_deleted . ' folder(s) trashed over ' . $days . ' days ago');
	}
}
