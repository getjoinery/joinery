<?php

/**
 * drive_list — the listing behind every Drive view. Returns a folder's children
 * (folders + files), or the Starred / Trash / Shared-with-me collections, plus
 * the breadcrumb and the storage meter. Read-only.
 *
 * Listings cap at DRIVE_LIST_CAP children and set 'truncated' past the cap
 * (v1 does not paginate).
 */

if (!defined('DRIVE_LIST_CAP')) {
	define('DRIVE_LIST_CAP', 2000);
}

function drive_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/folders_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$view      = (string)($input['view'] ?? 'mine');
	$folder_id = (isset($input['folder_id']) && (int)$input['folder_id'] > 0) ? (int)$input['folder_id'] : 0;
	$search    = trim((string)($input['search'] ?? ''));

	$folders = array();
	$files   = array();
	$breadcrumb = array();
	$current_folder = null;

	// Flat folder list for move / destination pickers.
	if ($view === 'folders') {
		return LogicResult::render(array(
			'ok'      => true,
			'view'    => 'folders',
			'folders' => DriveHelper::all_folders_flat($user_id),
		));
	}

	if ($search !== '') {
		$files = _drive_list_search($user_id, $search);
	} elseif ($view === 'starred') {
		$starred_ids = array_keys(DriveHelper::starred_file_ids($user_id));
		if (!empty($starred_ids)) {
			$match = new MultiFile(array('user_id' => $user_id, 'file_ids' => $starred_ids, 'deleted' => false, 'source' => File::SOURCE_DRIVE), array('fil_title' => 'ASC'));
			$match->load();
			foreach ($match as $f) {
				$files[] = $f;
			}
		}
	} elseif ($view === 'trash') {
		list($folders, $files) = _drive_list_trash($user_id);
	} elseif ($view === 'shared') {
		list($folders, $files) = _drive_list_shared($user_id);
	} else {
		// Default "My Drive" folder browse.
		$is_owner = true;
		if ($folder_id > 0) {
			$current_folder = DriveHelper::load_folder($folder_id);
			$is_trashed = $current_folder && $current_folder->get('fol_delete_time') !== null && $current_folder->get('fol_delete_time') !== '';
			if (!$current_folder || $is_trashed) {
				return LogicResult::error('Folder not found.');
			}
			if (!DriveHelper::can_read(DriveHelper::ENTITY_FOLDER, $current_folder, $user_id, $session->get_permission())) {
				return LogicResult::error('You do not have access to that folder.');
			}
			$is_owner = DriveHelper::owns(DriveHelper::ENTITY_FOLDER, $current_folder, $user_id);

			// Breadcrumb: root -> ... -> current for the owner. A grantee's crumb
			// starts at the topmost folder in the chain that carries a direct
			// grant for them (the share-link $in_scope rule) — the owner's
			// private ancestors above the shared root are never exposed.
			$chain = array_reverse(DriveHelper::ancestors($folder_id));
			$chain[] = $folder_id;
			$in_scope = $is_owner;
			foreach ($chain as $aid) {
				if (!$in_scope && FileAccessGrant::role_for(DriveHelper::ENTITY_FOLDER, $aid, $user_id) !== null) {
					$in_scope = true;
				}
				if (!$in_scope) {
					continue;
				}
				$af = ($aid === $folder_id) ? $current_folder : DriveHelper::load_folder($aid);
				if ($af) {
					$breadcrumb[] = array('id' => (int)$af->get('fol_folder_id'), 'name' => $af->get('fol_name'));
				}
			}
		}

		// Children are listed by the FOLDER OWNER's id: correct for the owner,
		// and for a grantee browsing a shared folder (single-owner-tree rule —
		// everything in the folder belongs to the folder's owner).
		$list_uid = $current_folder ? (int)$current_folder->get('fol_usr_user_id') : $user_id;

		$folder_rows = new MultiFolder(array('user_id' => $list_uid, 'parent_id' => $folder_id, 'deleted' => false), array('fol_name' => 'ASC'));
		$folder_rows->load();
		foreach ($folder_rows as $fo) {
			$folders[] = $fo;
		}

		$file_rows = new MultiFile(array('user_id' => $list_uid, 'folder_id' => $folder_id, 'deleted' => false, 'source' => File::SOURCE_DRIVE), array('fil_title' => 'ASC'));
		$file_rows->load();
		foreach ($file_rows as $f) {
			$files[] = $f;
		}
	}

	// Assemble the item payload, precomputing sizes + stars in two queries.
	$file_ids = array();
	foreach ($files as $f) {
		$file_ids[] = (int)$f->get('fil_file_id');
	}
	$size_map    = DriveHelper::file_sizes($file_ids);
	$starred_set = DriveHelper::starred_file_ids($user_id);

	$items = array();
	$count = 0;
	$truncated = false;

	foreach ($folders as $fo) {
		if ($count >= DRIVE_LIST_CAP) { $truncated = true; break; }
		$items[] = DriveHelper::folder_export($fo);
		$count++;
	}
	if (!$truncated) {
		foreach ($files as $f) {
			if ($count >= DRIVE_LIST_CAP) { $truncated = true; break; }
			$fid = (int)$f->get('fil_file_id');
			$items[] = DriveHelper::file_export($f, isset($size_map[$fid]) ? $size_map[$fid] : null, isset($starred_set[$fid]));
			$count++;
		}
	}

	// Read-only usage: never create the row here (drive_list also renders the page
	// on a GET). The row is materialized by recompute after a mutation.
	$bytes_used = DriveUsage::current_bytes($user_id);
	$quota = (int)SubscriptionTier::getUserFeature($user_id, 'drive_storage_bytes', 0);

	return LogicResult::render(array(
		'ok'         => true,
		'view'       => $view,
		'folder_id'  => $folder_id,
		'folder'     => $current_folder ? DriveHelper::folder_export($current_folder) : null,
		'breadcrumb' => $breadcrumb,
		'items'      => $items,
		'truncated'  => $truncated,
		'usage'      => array(
			'bytes_used'  => $bytes_used,
			'quota_bytes' => $quota,
		),
	));
}

/** Top-level trashed folders + files owned by the user (roots of each cascade). */
function _drive_list_trash($user_id) {
	$dblink = DbConnector::get_instance()->get_db_link();

	$qf = $dblink->prepare(
		"SELECT fol_folder_id FROM fol_folders p
		  WHERE p.fol_usr_user_id = :uid AND p.fol_delete_time IS NOT NULL
		    AND (p.fol_parent_folder_id IS NULL OR NOT EXISTS (
		        SELECT 1 FROM fol_folders q
		         WHERE q.fol_folder_id = p.fol_parent_folder_id AND q.fol_delete_time IS NOT NULL))
		  ORDER BY p.fol_delete_time DESC");
	$qf->execute(array(':uid' => (int)$user_id));
	$folders = array();
	foreach ($qf->fetchAll(PDO::FETCH_COLUMN) as $fid) {
		$fo = DriveHelper::load_folder($fid);
		if ($fo) { $folders[] = $fo; }
	}

	$qfi = $dblink->prepare(
		"SELECT fil_file_id FROM fil_files f
		  WHERE f.fil_usr_user_id = :uid AND f.fil_delete_time IS NOT NULL
		    AND f.fil_source = 'drive'
		    AND (f.fil_fol_folder_id IS NULL OR NOT EXISTS (
		        SELECT 1 FROM fol_folders q
		         WHERE q.fol_folder_id = f.fil_fol_folder_id AND q.fol_delete_time IS NOT NULL))
		  ORDER BY f.fil_delete_time DESC");
	$qfi->execute(array(':uid' => (int)$user_id));
	$files = array();
	foreach ($qfi->fetchAll(PDO::FETCH_COLUMN) as $fid) {
		$f = DriveHelper::load_file($fid);
		if ($f) { $files[] = $f; }
	}

	return array($folders, $files);
}

/**
 * Filename/title search: the caller's own Drive files, plus files shared to
 * them — directly granted files, and files anywhere inside granted folder
 * subtrees. Merged and deduped by file id.
 */
function _drive_list_search($user_id, $search) {
	$files = array();
	$seen = array();
	$collect = function ($multi) use (&$files, &$seen) {
		foreach ($multi as $f) {
			$fid = (int)$f->get('fil_file_id');
			if (!isset($seen[$fid])) {
				$seen[$fid] = true;
				$files[] = $f;
			}
		}
	};

	// Own files.
	$own = new MultiFile(array('user_id' => $user_id, 'title_like' => $search, 'deleted' => false, 'source' => File::SOURCE_DRIVE), array('fil_title' => 'ASC'));
	$own->load();
	$collect($own);

	// Directly granted files.
	$granted_files = FileAccessGrant::entity_ids_for_user($user_id, DriveHelper::ENTITY_FILE);
	if (!empty($granted_files)) {
		$m = new MultiFile(array('file_ids' => $granted_files, 'title_like' => $search, 'deleted' => false, 'source' => File::SOURCE_DRIVE), array('fil_title' => 'ASC'));
		$m->load();
		$collect($m);
	}

	// Files inside granted folder subtrees.
	$folder_set = array();
	foreach (FileAccessGrant::entity_ids_for_user($user_id, DriveHelper::ENTITY_FOLDER) as $gfid) {
		$folder_set[(int)$gfid] = true;
		foreach (DriveHelper::descendant_folder_ids($gfid) as $did) {
			$folder_set[$did] = true;
		}
	}
	if (!empty($folder_set)) {
		$m = new MultiFile(array('folder_ids' => array_keys($folder_set), 'title_like' => $search, 'deleted' => false, 'source' => File::SOURCE_DRIVE), array('fil_title' => 'ASC'));
		$m->load();
		$collect($m);
	}

	usort($files, function ($a, $b) { return strcasecmp($a->get('fil_title'), $b->get('fil_title')); });
	return $files;
}

/** Files and folders shared TO the user. */
function _drive_list_shared($user_id) {
	$folders = array();
	foreach (FileAccessGrant::entity_ids_for_user($user_id, DriveHelper::ENTITY_FOLDER) as $fid) {
		$fo = DriveHelper::load_folder($fid);
		if ($fo && ($fo->get('fol_delete_time') === null || $fo->get('fol_delete_time') === '')) {
			$folders[] = $fo;
		}
	}

	$file_ids = FileAccessGrant::entity_ids_for_user($user_id, DriveHelper::ENTITY_FILE);
	$files = array();
	foreach ($file_ids as $fid) {
		$f = DriveHelper::load_file($fid);
		if ($f && ($f->get('fil_delete_time') === null || $f->get('fil_delete_time') === '')) {
			$files[] = $f;
		}
	}

	return array($folders, $files);
}

function drive_list_logic_descriptor(): array {
	return array(
		'description'      => 'List a Drive folder\'s contents, or the Starred / Trash / Shared-with-me collections.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'folder_id' => array('type' => 'int', 'required' => false, 'label' => 'Folder id (omit for root)'),
			'view'      => array('type' => 'string', 'required' => false, 'enum' => array('mine', 'starred', 'trash', 'shared', 'folders'), 'label' => 'View'),
			'search'    => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'Filename search'),
		),
	);
}
?>
