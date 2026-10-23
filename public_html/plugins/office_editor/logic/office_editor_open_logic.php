<?php
/**
 * office_editor/office_editor_open — everything the editor page needs to show
 * a Drive file in the editor: the editor's address, an access token for this
 * member on this file, and its lifetime.
 *
 * The editor is started on demand. When it is not answering yet this asks root
 * to start it and answers `status: starting`; the page shows "Starting the
 * editor…" and asks again until `status: ready`.
 *
 * @version 0.1.0
 */

function office_editor_open_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('You must be signed in.', array('reason' => 'signed_out'));
	}
	if (!Globalvars::get_instance()->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.', array('reason' => 'drive_disabled'));
	}
	if (!OfficeEditor::installed()) {
		return LogicResult::error('The document editor is not available on this site.', array('reason' => 'unavailable', 'standing' => true));
	}

	$file = DriveHelper::load_file((int)($input['file_id'] ?? 0));
	if (!$file || ($file->get('fil_delete_time') !== null && $file->get('fil_delete_time') !== '')
			|| !DriveHelper::can_read(DriveHelper::ENTITY_FILE, $file, $user_id, $session->get_permission())) {
		return LogicResult::error('That file was not found.', array('reason' => 'not_found', 'standing' => true));
	}
	if (!OfficeEditor::opensExtension($file->get('fil_title'))) {
		return LogicResult::error('The editor does not open this kind of file.', array('reason' => 'not_openable', 'standing' => true));
	}
	if (!OfficeEditor::isStandard($file)) {
		return LogicResult::error('Private and Fortress files can\'t be opened on the server. Download to edit.', array('reason' => 'not_openable', 'standing' => true));
	}
	if (!OfficeEditor::memberMay($user_id)) {
		return LogicResult::error('Editing documents in Drive isn\'t part of your plan.', array('reason' => 'not_in_plan', 'standing' => true));
	}

	// The origin the page is on, which the editor posts its messages to. Taken
	// from the page, and only when it names this request's own host.
	$origin = (string)($input['origin'] ?? '');
	$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
	$parts = parse_url($origin);
	$origin_host = strtolower((string)($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
	if (!in_array($parts['scheme'] ?? '', array('http', 'https'), true) || $origin_host === '' || $origin_host !== $host) {
		return LogicResult::error('The page origin does not match this site.', array('reason' => 'invalid_request', 'standing' => true));
	}
	$origin = $parts['scheme'] . '://' . $origin_host;

	// Asked of the service itself on every open: a cached answer would send
	// the page to an editor stopped since (the idle stop). A stopped service
	// refuses the loopback connection at once.
	$discovery = OfficeEditor::discovery(true);
	if ($discovery === null) {
		if (!OfficeEditor::requestStart()) {
			return LogicResult::error('The document editor could not be started.', array('reason' => 'unavailable'));
		}
		return LogicResult::render(array('status' => 'starting'));
	}
	$load = OfficeEditor::load();
	if ($load !== null && OfficeEditor::wouldOverload($load, $file->key)) {
		return LogicResult::error('The editor is busy: too many documents are open on this site right now. Try again in a few minutes, or download the file.', array('reason' => 'busy'));
	}
	$url = OfficeEditor::editorUrl($discovery, $file);
	if ($url === null) {
		return LogicResult::error('The editor does not open this kind of file.', array('reason' => 'not_openable', 'standing' => true));
	}

	$can_write = DriveHelper::can_write(DriveHelper::ENTITY_FILE, $file, $user_id, $session->get_permission());
	try {
		$t = OfficeEditorToken::mint($user_id, $file->key, $can_write, $origin);
	} catch (Throwable $e) {
		error_log('[office_editor] token mint failed: ' . $e->getMessage());
		return LogicResult::error('The document editor is not available right now.', array('reason' => 'unavailable'));
	}

	$folder_id = (int)$file->get('fil_fol_folder_id');
	return LogicResult::render(array(
		'status'           => 'ready',
		'editor_url'       => $url,
		'access_token'     => $t['token'],
		// The editor wants the expiry as milliseconds since the epoch.
		'access_token_ttl' => $t['expires'] * 1000,
		'can_write'        => $can_write,
		'file'             => array(
			'id'        => (int)$file->key,
			'name'      => (string)$file->get('fil_title'),
			'folder_id' => $folder_id ?: null,
		),
		'folder_url'       => '/drive' . ($folder_id ? '?folder_id=' . $folder_id : ''),
	));
}

function office_editor_open_logic_descriptor(): array {
	return array(
		'description'      => 'Open a Drive file in the document editor: starts the editor if needed (status starting; ask again) and returns its address with an access token for this member on this file (status ready).',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'file_id' => array('type' => 'int', 'required' => true, 'label' => 'Drive file'),
			'origin'  => array('type' => 'string', 'required' => true, 'max_length' => 255, 'label' => 'The page origin (window.location.origin) the editor posts its messages to'),
		),
	);
}
