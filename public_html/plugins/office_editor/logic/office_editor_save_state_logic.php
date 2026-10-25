<?php
/**
 * office_editor/office_editor_save_state — did the editor's last save of this
 * file land? The editor tells its page "saved" for a save the site refused just
 * as for one it kept, so the page asks here each time the editor says so.
 *
 * @version 0.1.0
 */

function office_editor_save_state_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	$file = DriveHelper::load_file((int)($input['file_id'] ?? 0));
	if (!$user_id || !$file || !DriveHelper::can_read(DriveHelper::ENTITY_FILE, $file, $user_id, $session->get_permission())) {
		return LogicResult::error('That file was not found.', array('reason' => 'not_found', 'standing' => true));
	}
	$messages = array(
		'conflict'  => 'Not saved: the file was changed somewhere else.',
		'quota'     => 'Not saved: there is no room left in this Drive.',
		'too_large' => 'Not saved: the file is larger than your plan allows.',
		'read_only' => 'Not saved: the editor no longer has write access to this file.',
	);
	$state = OfficeEditorSession::saveState($file->key);
	$refusal = $state['refusal'];
	return LogicResult::render(array(
		'saved'   => $refusal === null,
		'refusal' => $refusal,
		'message' => $refusal !== null ? ($messages[$refusal] ?? 'Not saved.') : null,
		// Moves with every save the site answers, landed or refused: the page
		// takes it before asking for a save and waits for it to change.
		'marker'  => $state['marker'],
	));
}

function office_editor_save_state_logic_descriptor(): array {
	return array(
		'description'      => 'Whether the document editor\'s last save of a Drive file landed, and if not, why (conflict, quota, too_large, read_only).',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => false,
		'auth'             => array('min_user_permission' => 0, 'capability' => 'read'),
		'input'            => array(
			'file_id' => array('type' => 'int', 'required' => true, 'label' => 'Drive file'),
		),
	);
}
