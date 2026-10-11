<?php
/**
 * API action: boards/boards_import — bring a board across from another kanban
 * tool's JSON export.
 *
 * POST /api/v1/action/boards/boards_import
 *
 *   preview   json | file   — what the import would bring, and who in the export
 *                             matches a site member. Writes nothing.
 *   import    json | file [, member_map]  — make the board. `member_map` maps an
 *                             export member's id to a site member's id (0 skips
 *                             them); people with an email that matches a site
 *                             member are mapped without being listed.
 *
 * The export is sent as `json` (the file's text) or as a multipart `file`. Any
 * signed-in member can import; the importer owns the new board. Uploaded files in
 * the export come across as links back to where they live, with a note on the
 * card: the site never fetches attachment bytes.
 *
 * @version 1.0.0
 */

function boards_import_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? '', array('preview', 'import'), 'operation');

		$json = '';
		if (!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
			if ((int)$_FILES['file']['size'] > BoardsImporter::MAX_BYTES) {
				throw new BoardsRefusal('That file is too large to import.');
			}
			$json = (string)file_get_contents($_FILES['file']['tmp_name']);
		} elseif (isset($input['json'])) {
			$json = (string)$input['json'];
		}
		if (trim($json) === '') {
			throw new BoardsRefusal('Choose the exported JSON file to import.');
		}

		if ($op === 'preview') {
			return BoardsImporter::preview($json);
		}

		$map = array();
		foreach ((array)($input['member_map'] ?? array()) as $source_id => $uid) {
			$map[(string)$source_id] = (int)$uid;
		}
		$board = BoardsImporter::import($json, $user_id, $map);
		return array('board' => BoardsPayload::board($board), 'url' => '/profile/boards/board?id=' . (int)$board->key);
	});
}

function boards_import_logic_descriptor(): array {
	return array(
		'description'      => 'Import a board from another kanban tool\'s JSON export: preview what it holds, then make the board.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'         => array('type' => 'string', 'required' => true, 'label' => 'Operation', 'enum' => array('preview', 'import')),
			'json'       => array('type' => 'text', 'required' => false, 'label' => 'Export JSON'),
			'member_map' => array('type' => 'object', 'required' => false, 'label' => 'Export member id → site member id'),
		),
		'auth'             => array('capability' => 'write'),
	);
}
