<?php
/**
 * API action: messenger/messenger_upload — put a photo or file where the
 * composer can show it.
 *
 * POST /api/v1/action/messenger/messenger_upload as multipart form data with
 * one `file` field. A multipart body leaves php://input empty, so the API
 * dispatcher falls back to $_POST and PHP fills $_FILES natively — no special
 * transport (mailbox/send and joinery_ai/chat_send are the shipped precedents).
 *
 * Returns the stored attachment's id and a preview URL. Nothing is in any
 * conversation yet: messenger_send claims the id onto a message.
 *
 * @version 1.0.0
 */


function messenger_upload_logic(array $input): LogicResult {

	try {
		$user_id = Messenger::requireMember();
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	if (empty($_FILES['file'])) {
		return LogicResult::error('No file was chosen.');
	}

	try {
		$file = MessengerUploads::store($_FILES['file'], $user_id);
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	} catch (FileException $e) {
		return LogicResult::error('That file could not be stored.');
	}

	$is_image = strpos((string)$file->get('fil_type'), 'image/') === 0;

	return LogicResult::render(array(
		'attachment_id' => (int)$file->key,
		'name'          => $file->get('fil_title'),
		'mime'          => $file->get('fil_type'),
		'size'          => (int)$file->size_bytes(),
		'is_image'      => $is_image,
		'url'           => $file->get_url('original'),
		'thumb_url'     => $is_image ? $file->get_url(Messenger::thumbnailSize()) : null,
	));
}

function messenger_upload_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'requires_setting' => 'messenger_active',
		'description' => 'Store one uploaded photo or file for the messenger composer. Multipart form data with a single `file` field; returns an attachment id that messenger_send claims onto a message.',
	);
}
?>
