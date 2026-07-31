<?php

/**
 * drive_index — walk everything the caller can see, in stable pages.
 *
 * This is what a client uses the first time it syncs, and whenever
 * drive_changes hands back {reset: true} because its cursor fell outside the
 * retained window. The alternative — a folder-by-folder drive_list descent —
 * mints a signed URL per file and issues a request per folder, which is the
 * wrong shape entirely at a hundred thousand files.
 *
 * The walk is keyset paginated: each page hands back the cursor to resume
 * from, and resuming re-reads nothing. Entities that change mid-walk are not a
 * problem — every one of them also lands in the change feed the client replays
 * once the walk finishes, so the walk only has to be a consistent-enough
 * starting point, not a snapshot.
 *
 * Trashed items are included, marked deleted. A sync client that could not see
 * the trash would read a trashed file as "gone from the server" and delete the
 * local copy, and would then have no way to recognize a restore.
 */

if (!defined('DRIVE_INDEX_MAX')) {
	define('DRIVE_INDEX_MAX', 2000);
}

function drive_index_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/folders_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));
	require_once(PathHelper::getIncludePath('data/file_key_grants_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$scope = (string)($input['scope'] ?? 'mine');
	if ($scope !== 'mine' && $scope !== 'shared') {
		return LogicResult::error('Unknown scope.');
	}
	$limit = (int)($input['limit'] ?? DRIVE_INDEX_MAX);
	if ($limit <= 0 || $limit > DRIVE_INDEX_MAX) {
		$limit = DRIVE_INDEX_MAX;
	}

	list($phase, $cursor) = _drive_index_parse_cursor($input['after_id'] ?? null);
	if ($phase === null) {
		return LogicResult::error('Invalid cursor.');
	}

	$dblink = DbConnector::get_instance()->get_db_link();

	// The shared scope resolves the caller's reach once per page: every folder
	// they hold a grant on plus its live subtree, and every directly granted
	// file, each remembering which granted entity it hangs off so the client can
	// mount it under the right "Shared with me" root.
	$grant_root_of_folder = array();
	$granted_file_roots = array();
	if ($scope === 'shared') {
		list($grant_root_of_folder, $granted_file_roots) = _drive_index_shared_reach($user_id);
		if (empty($grant_root_of_folder) && empty($granted_file_roots)) {
			return LogicResult::render(array(
				'ok' => true, 'scope' => $scope, 'items' => array(),
				'next_after_id' => _drive_index_token('file', 0), 'done' => true,
			));
		}
	}

	$folder_ids = array();
	$file_ids   = array();
	$done       = false;
	$remaining  = $limit;

	// Folders before files, so a client that materializes as it reads always has
	// somewhere to put the next file.
	if ($phase === 'folder') {
		$folder_ids = ($scope === 'mine')
			? _drive_index_own_folders($dblink, $user_id, $cursor, $remaining)
			: _drive_index_id_page(array_keys($grant_root_of_folder), $cursor, $remaining);
		$remaining -= count($folder_ids);
		if ($remaining > 0) {
			// Folders are exhausted; the rest of this page comes from files.
			$phase = 'file';
			$cursor = 0;
		}
	}

	if ($phase === 'file' && $remaining > 0) {
		$asked = $remaining;
		$file_ids = ($scope === 'mine')
			? _drive_index_own_files($dblink, $user_id, $cursor, $asked)
			: _drive_index_shared_files($dblink, array_keys($grant_root_of_folder), array_keys($granted_file_roots), $cursor, $asked);
		$done = count($file_ids) < $asked;
	}

	// Batch the per-file lookups for the whole page.
	$size_map    = DriveHelper::file_sizes($file_ids);
	$key_map     = FileKeyGrant::wrapped_keys_for_user($file_ids, $user_id);
	$starred_set = DriveHelper::starred_file_ids($user_id);
	DriveHelper::prime_sync_meta($file_ids);

	$items = array();
	$last_token = _drive_index_token($phase, $cursor);

	foreach ($folder_ids as $fid) {
		$folder = DriveHelper::load_folder($fid);
		if (!$folder) {
			continue;
		}
		$row = DriveHelper::folder_export($folder);
		if ($scope === 'shared' && isset($grant_root_of_folder[(int)$fid])) {
			$row['grant_root'] = $grant_root_of_folder[(int)$fid];
		}
		$items[] = $row;
		$last_token = _drive_index_token('folder', $fid);
	}

	foreach ($file_ids as $fid) {
		$file = DriveHelper::load_file($fid);
		if (!$file) {
			continue;
		}
		$fid = (int)$fid;
		$row = DriveHelper::file_export(
			$file,
			isset($size_map[$fid]) ? $size_map[$fid] : null,
			isset($starred_set[$fid]),
			isset($key_map[$fid]) ? $key_map[$fid] : null,
			false
		);
		if ($scope === 'shared') {
			if (isset($granted_file_roots[$fid])) {
				$row['grant_root'] = $granted_file_roots[$fid];
			} else {
				$parent = (int)$file->get('fil_fol_folder_id');
				if (isset($grant_root_of_folder[$parent])) {
					$row['grant_root'] = $grant_root_of_folder[$parent];
				}
			}
		}
		$items[] = $row;
		$last_token = _drive_index_token('file', $fid);
	}

	return LogicResult::render(array(
		'ok'            => true,
		'scope'         => $scope,
		'items'         => $items,
		'next_after_id' => $last_token,
		'done'          => $done,
	));
}

/**
 * Cursor token: which half of the walk we are in, and how far through it.
 *
 * Two id spaces are being walked, so a bare integer cannot say where it points
 * — folder 500 and file 500 are different places. Clients treat the token as
 * opaque and hand back whatever the last page returned; '' / 0 / null starts
 * from the beginning.
 *
 * @return array{0:?string,1:int} phase and id, or [null, 0] when unparseable
 */
function _drive_index_parse_cursor($raw) {
	if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
		return array('folder', 0);
	}
	if (is_int($raw) || ctype_digit((string)$raw)) {
		// A bare number is read as a position in the folder half — the shape a
		// caller reaches for before reading the docs, and the only reading that
		// cannot silently skip entities.
		return array('folder', (int)$raw);
	}
	if (!is_string($raw) || !preg_match('/^(folder|file):(\d+)$/', $raw, $m)) {
		return array(null, 0);
	}
	return array($m[1], (int)$m[2]);
}

function _drive_index_token($phase, $id) {
	return $phase . ':' . (int)$id;
}

/** One keyset page of the caller's own folders, trashed ones included. */
function _drive_index_own_folders($dblink, $user_id, $after, $limit) {
	$q = $dblink->prepare(
		"SELECT fol_folder_id FROM fol_folders
		  WHERE fol_usr_user_id = :uid AND fol_folder_id > :after
		  ORDER BY fol_folder_id ASC LIMIT :lim");
	$q->bindValue(':uid', (int)$user_id, PDO::PARAM_INT);
	$q->bindValue(':after', (int)$after, PDO::PARAM_INT);
	$q->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
	$q->execute();
	return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** One keyset page of the caller's own Drive files, trashed ones included. */
function _drive_index_own_files($dblink, $user_id, $after, $limit) {
	$q = $dblink->prepare(
		"SELECT fil_file_id FROM fil_files
		  WHERE fil_usr_user_id = :uid AND fil_source = 'drive' AND fil_file_id > :after
		  ORDER BY fil_file_id ASC LIMIT :lim");
	$q->bindValue(':uid', (int)$user_id, PDO::PARAM_INT);
	$q->bindValue(':after', (int)$after, PDO::PARAM_INT);
	$q->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
	$q->execute();
	return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** One keyset page of Drive files inside shared folders, plus directly shared files. */
function _drive_index_shared_files($dblink, array $folder_ids, array $direct_file_ids, $after, $limit) {
	$folder_in = DriveHelper::int_in_list($folder_ids);
	$file_in   = DriveHelper::int_in_list($direct_file_ids);
	$q = $dblink->prepare(
		"SELECT fil_file_id FROM fil_files
		  WHERE fil_source = 'drive' AND fil_file_id > :after
		    AND (fil_fol_folder_id IN ($folder_in) OR fil_file_id IN ($file_in))
		  ORDER BY fil_file_id ASC LIMIT :lim");
	$q->bindValue(':after', (int)$after, PDO::PARAM_INT);
	$q->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
	$q->execute();
	return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** Keyset page over an in-memory id set (the shared scope's folder reach). */
function _drive_index_id_page(array $ids, $after, $limit) {
	$ids = array_values(array_unique(array_map('intval', $ids)));
	sort($ids);
	$page = array();
	foreach ($ids as $id) {
		if ($id <= (int)$after) {
			continue;
		}
		$page[] = $id;
		if (count($page) >= $limit) {
			break;
		}
	}
	return $page;
}

/**
 * What the caller reaches through grants: folder id => granting root, and
 * directly granted file id => granting root. A folder reachable from more than
 * one root is attributed to the lowest-numbered one, so the mapping a client
 * builds is stable across walks.
 */
function _drive_index_shared_reach($user_id) {
	$folder_roots = array();
	$file_roots = array();

	$granted_folders = array_map('intval', FileAccessGrant::entity_ids_for_user($user_id, DriveHelper::ENTITY_FOLDER));
	sort($granted_folders);
	foreach ($granted_folders as $root_id) {
		$root = array('entity_type' => DriveHelper::ENTITY_FOLDER, 'id' => $root_id);
		if (!isset($folder_roots[$root_id])) {
			$folder_roots[$root_id] = $root;
		}
		foreach (DriveHelper::descendant_folder_ids($root_id) as $did) {
			if (!isset($folder_roots[$did])) {
				$folder_roots[$did] = $root;
			}
		}
	}

	$granted_files = array_map('intval', FileAccessGrant::entity_ids_for_user($user_id, DriveHelper::ENTITY_FILE));
	sort($granted_files);
	foreach ($granted_files as $file_id) {
		$file_roots[$file_id] = array('entity_type' => DriveHelper::ENTITY_FILE, 'id' => $file_id);
	}

	return array($folder_roots, $file_roots);
}

function drive_index_logic_descriptor(): array {
	return array(
		'description'      => 'Walk every Drive entity the caller can see, in keyset-paginated pages — the cold-start and post-reset counterpart to drive_changes. Lean exports: no signed URLs, no breadcrumbs. Trashed items are included with deleted:true so a client can tell a trashed file from a vanished one. Pass the previous page\'s next_after_id back as after_id (treat it as opaque); stop when done is true.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'after_id' => array('type' => 'string', 'required' => false, 'max_length' => 32, 'label' => 'Cursor from the previous page (omit to start)'),
			'scope'    => array('type' => 'string', 'required' => false, 'enum' => array('mine', 'shared'), 'label' => 'Own entities, or entities shared with the caller'),
			'limit'    => array('type' => 'int', 'required' => false, 'min' => 1, 'max' => DRIVE_INDEX_MAX, 'label' => 'Page size'),
		),
	);
}
?>
