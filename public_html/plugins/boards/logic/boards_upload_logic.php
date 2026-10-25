<?php
/**
 * API action: boards/boards_upload — attach a file to a card (or set a board's
 * background image).
 *
 * POST /api/v1/action/boards/boards_upload as multipart form data with one `file`
 * field, plus card_id. A multipart body leaves php://input empty, so the API
 * dispatcher falls back to $_POST and PHP fills $_FILES natively (the same
 * transport messenger/messenger_upload uses).
 *
 * A file too big for one request travels over the platform's resumable chunked
 * upload instead (purpose 'boards_attachment', opened with the board's id); once
 * it has landed, this action claims it: pass card_id and file_id instead of a
 * `file`, and the File is gated on the board and attached.
 *
 * For a board background (board admins): purpose=background, board_id and a
 * `file` that is an image.
 *
 * Needs an editor on the card's board. Returns the card's attachments.
 *
 * @version 1.0.0
 */

function boards_upload_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		if (($input['purpose'] ?? '') === 'background') {
			$board = Boards::boardFor($input['board_id'] ?? 0, $user_id, 'admin');
			if (empty($_FILES['file'])) {
				throw new BoardsRefusal('No file was chosen.');
			}
			$file = BoardsUploads::store($_FILES['file'], $user_id, $board);
			if (strpos((string)$file->get('fil_type'), 'image/') !== 0) {
				Boards::deleteFile((int)$file->key);
				throw new BoardsRefusal('A board background has to be an image.');
			}
			$old = Boards::backgroundFileId((string)$board->get('bbd_background'));
			$board->set('bbd_background', 'file:' . (int)$file->key);
			$board->set('bbd_update_time', 'now()');
			$board->save();
			$board->load();
			if ($old) {
				Boards::deleteFile($old);
			}
			return array('board' => BoardsPayload::board($board));
		}

		list($card, $board) = Boards::cardFor($input['card_id'] ?? 0, $user_id, 'editor');

		try {
			if (!empty($input['file_id'])) {
				$file = BoardsUploads::claim((int)$input['file_id'], $user_id, $board);
			} elseif (!empty($_FILES['file'])) {
				$file = BoardsUploads::store($_FILES['file'], $user_id, $board);
			} else {
				throw new BoardsRefusal('No file was chosen.');
			}
		} catch (FileException $e) {
			throw new BoardsRefusal('That file could not be stored.');
		}

		BoardsCards::attachFile($card, $board, $user_id, $file);
		if (!empty($input['as_cover']) && strpos((string)$file->get('fil_type'), 'image/') === 0) {
			$attachments = BoardsPayload::attachments((int)$card->key);
			foreach ($attachments as $a) {
				if (!empty($a['file_id']) && (int)$a['file_id'] === (int)$file->key) {
					BoardsCards::setCover($card, $board, $user_id, (int)$a['id']);
				}
			}
		}
		$card = new BoardCard((int)$card->key, TRUE);
		return array(
			'attachments' => BoardsPayload::attachments((int)$card->key, (int)$card->get('bcd_cover_fil_file_id')),
			'card'        => BoardsPayload::cardSummary((int)$card->key),
		);
	});
}

function boards_upload_logic_descriptor(): array {
	return array(
		'description'      => 'Attach an uploaded file (multipart `file`, or a finished chunked upload by file_id) to a card, or set a board\'s background image.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'card_id'  => array('type' => 'int', 'required' => false, 'label' => 'Card'),
			'board_id' => array('type' => 'int', 'required' => false, 'label' => 'Board'),
			'file_id'  => array('type' => 'int', 'required' => false, 'label' => 'Finished chunked upload'),
			'purpose'  => array('type' => 'string', 'required' => false, 'enum' => array('attachment', 'background'), 'label' => 'Purpose'),
			'as_cover' => array('type' => 'bool', 'required' => false, 'label' => 'Use as the card cover'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'write'),
	);
}
