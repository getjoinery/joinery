<?php

/**
 * share_logic — the public /s/{token} share page. Anonymous-safe: the live link
 * (not expired, not revoked, password satisfied) is the authorization. Files are
 * served through short-lived signed URLs; folders render a read-only listing
 * scoped to the shared subtree.
 */

function share_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_share_links_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/folders_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance(); // ensures a session for the password gate

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::render(array('title' => 'Not available', 'share_error' => 'Sharing is not available.'));
	}

	$token = (string)($input['token'] ?? '');
	$link = FileShareLink::load_by_token($token);
	if (!$link || !$link->is_live()) {
		return LogicResult::render(array('title' => 'Link unavailable', 'share_error' => 'This link is no longer available.'));
	}

	// Password gate (satisfied once per session).
	$sess_key = 'drive_share_ok_' . (int)$link->key;
	$authed = !empty($_SESSION[$sess_key]);
	if ($link->requires_password() && !$authed) {
		if (LibraryFunctions::isFormSubmission() && isset($input['drv_link_password'])) {
			if ($link->check_password($input['drv_link_password'])) {
				$_SESSION[$sess_key] = true;
				$authed = true;
			} else {
				return LogicResult::render(array('title' => 'Password required', 'link' => $link, 'token' => $token, 'need_password' => true, 'password_error' => 'Incorrect password.'));
			}
		} else {
			return LogicResult::render(array('title' => 'Password required', 'link' => $link, 'token' => $token, 'need_password' => true));
		}
	}

	$link->record_access();

	$entity_type = $link->get('fsl_entity_type');
	$entity_id   = (int)$link->get('fsl_entity_id');

	if ($entity_type === DriveHelper::ENTITY_FILE) {
		$file = new File($entity_id, true);
		if (!$file->key || $file->get('fil_delete_time')) {
			return LogicResult::render(array('title' => 'File unavailable', 'share_error' => 'The shared file is no longer available.'));
		}
		$blob_id = (int)$file->get('fil_fbb_file_blob_id');
		$size = 0;
		if ($blob_id > 0) {
			$dblink = DbConnector::get_instance()->get_db_link();
			$q = $dblink->prepare("SELECT fbb_size_bytes FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
			$q->execute(array($blob_id));
			$size = (int)$q->fetchColumn();
		}
		// A Private file cannot be served to an anonymous visitor at all: opening
		// it needs the owner's unlock window, and there is no owner present on a
		// link fetch. Link creation refuses these, so reaching here means the
		// file's level changed after a link was made — the link stops working,
		// which is the honest outcome.
		if ($file->is_sealed()) {
			return LogicResult::render(array(
				'title'       => 'File unavailable',
				'share_error' => 'This file is now Private, so it can only be opened by its owner.',
			));
		}

		// An encrypted file is decrypted in the browser with the key carried in the
		// URL fragment (never sent here). Serve ciphertext + the encrypted metadata
		// blob and let the page do the rest — no server preview, opaque name.
		if ($file->is_encrypted()) {
			return LogicResult::render(array(
				'title'        => 'Encrypted file',
				'token'        => $token,
				'entity_type'  => 'file',
				'file'         => array(
					'name'               => 'Encrypted file',
					'size'               => $size,
					'mime'               => 'application/octet-stream',
					'is_image'           => false,
					'encrypted'          => true,
					'encrypted_metadata' => $file->get('fil_encrypted_metadata'),
					'download_url'       => $file->mintSignedUrl('original', 900),
					'preview_url'        => null,
				),
			));
		}

		$is_image = File::is_inline_safe_type($file->get('fil_type'));
		return LogicResult::render(array(
			'title'        => $file->get('fil_title'),
			'token'        => $token,
			'entity_type'  => 'file',
			'file'         => array(
				'name'         => $file->get('fil_title'),
				'size'         => $size,
				'mime'         => $file->get('fil_type'),
				'is_image'     => $is_image,
				'encrypted'    => false,
				'download_url' => $file->mintSignedUrl('original', 900),
				'preview_url'  => $is_image ? $file->mintSignedUrl('original', 900) : null,
			),
		));
	}

	// Folder share: read-only listing scoped to the shared subtree.
	$root = new Folder($entity_id, true);
	if (!$root->key || $root->get('fol_delete_time')) {
		return LogicResult::render(array('title' => 'Folder unavailable', 'share_error' => 'The shared folder is no longer available.'));
	}

	$current_id = $entity_id;
	if (!empty($input['folder'])) {
		$req = (int)$input['folder'];
		if ($req === $entity_id || in_array($entity_id, DriveHelper::ancestors($req), true)) {
			$current = DriveHelper::load_folder($req);
			if ($current && !$current->get('fol_delete_time')) {
				$current_id = $req;
			}
		}
	}
	$current = DriveHelper::load_folder($current_id);

	// Breadcrumb from the shared root down to the current folder.
	$breadcrumb = array();
	$chain = array_reverse(DriveHelper::ancestors($current_id));
	$chain[] = $current_id;
	$in_scope = false;
	foreach ($chain as $fid) {
		if ($fid === $entity_id) { $in_scope = true; }
		if (!$in_scope) { continue; }
		$fo = DriveHelper::load_folder($fid);
		if ($fo) {
			$breadcrumb[] = array('id' => (int)$fid, 'name' => $fo->get('fol_name'));
		}
	}

	$items = array();
	$subfolders = new MultiFolder(array('parent_id' => $current_id, 'deleted' => false), array('fol_name' => 'ASC'));
	$subfolders->load();
	foreach ($subfolders as $sf) {
		$items[] = array('type' => 'folder', 'id' => (int)$sf->key, 'name' => $sf->get('fol_name'));
	}
	// Files the system made for itself are never listed — one declared set, so this
	// surface and the admin listing can't drift apart on what counts as internal.
	$files = new MultiFile(array('folder_id' => $current_id, 'deleted' => false, 'sources_not' => File::internal_sources()), array('fil_title' => 'ASC'));
	$files->load();
	foreach ($files as $f) {
		$items[] = array(
			'type'         => 'file',
			'id'           => (int)$f->key,
			'name'         => $f->get('fil_title'),
			'is_image'     => File::is_inline_safe_type($f->get('fil_type')),
			'download_url' => $f->mintSignedUrl('original', 900),
		);
	}

	return LogicResult::render(array(
		'title'       => $root->get('fol_name'),
		'token'       => $token,
		'entity_type' => 'folder',
		'root_id'     => $entity_id,
		'current_id'  => $current_id,
		'folder_name' => $current ? $current->get('fol_name') : $root->get('fol_name'),
		'breadcrumb'  => $breadcrumb,
		'items'       => $items,
	));
}
?>
