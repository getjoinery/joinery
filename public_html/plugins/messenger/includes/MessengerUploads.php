<?php
/**
 * MessengerUploads — photos and files on their way into a conversation.
 *
 * Sending an attachment is two steps, because the picture should be visible in
 * the composer before the member decides to press send: the browser uploads the
 * bytes first (store()), gets an id back, and the send action claims those ids
 * onto the message (claim()).
 *
 * Between the two steps a file belongs to nobody but its uploader — it is a
 * private File, readable by them alone. Claiming re-points it at the
 * conversation, and from then on the people in that conversation can open it
 * and nobody else can (MessengerAttachmentGate). An upload that is never
 * claimed simply stays the uploader's own private file.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/message_attachments_class.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/includes/MessengerAttachmentGate.php'));

class MessengerUploads {

	/**
	 * File types a conversation refuses outright.
	 *
	 * Not a security boundary — the bytes are served as a download with the
	 * platform's own headers, never executed — but a chat has no business
	 * carrying a program, and refusing at the door is clearer than storing one
	 * and hoping nobody runs it.
	 */
	const BLOCKED_EXTENSIONS = array(
		'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
		'exe', 'com', 'bat', 'cmd', 'scr', 'msi', 'dll', 'jar', 'app',
		'sh', 'bash', 'zsh', 'ps1', 'vbs', 'js', 'jse', 'wsf', 'hta',
	);

	/**
	 * Store one uploaded file for later attaching.
	 *
	 * @param array $upload one entry of $_FILES
	 * @param int   $user_id the uploader
	 * @return File
	 * @throws MessengerRefusal with something the member can act on
	 */
	public static function store(array $upload, int $user_id): File {
		if (!isset($upload['error']) || is_array($upload['error'])) {
			throw new MessengerRefusal('That upload did not arrive properly.');
		}
		switch ($upload['error']) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				throw new MessengerRefusal('That file is too large for this server to accept.');
			case UPLOAD_ERR_NO_FILE:
				throw new MessengerRefusal('No file was chosen.');
			default:
				throw new MessengerRefusal('That upload did not finish.');
		}

		if (!is_uploaded_file($upload['tmp_name'])) {
			throw new MessengerRefusal('That upload did not arrive properly.');
		}

		$max_mb = max(1, (int)Globalvars::get_instance()
			->get_setting('messenger_max_attachment_mb', 25, true));
		if ((int)$upload['size'] > $max_mb * 1024 * 1024) {
			throw new MessengerRefusal('Files can be up to ' . $max_mb . ' MB. That one is larger.');
		}

		$name = (string)($upload['name'] ?? 'attachment');
		$name = trim(str_replace(array("\r", "\n", "\0"), '', basename($name)));
		if ($name === '') {
			$name = 'attachment';
		}
		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
		if ($extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
			throw new MessengerRefusal('That kind of file cannot be sent in a message.');
		}

		// The blob's own magic-byte detection wins over the browser's claim; the
		// browser type is only a hint of last resort.
		$mime = File::detect_mime_file($upload['tmp_name']);
		if (!$mime) {
			$mime = (string)($upload['type'] ?? 'application/octet-stream');
		}

		return File::createFromUpload($upload['tmp_name'], $name, $mime, $user_id, array(
			// Nobody's but the uploader's until a message claims it.
			'fil_private' => true,
			'fil_source'  => File::SOURCE_MESSENGER_ATTACHMENT,
		));
	}

	/**
	 * Hand a set of uploads to a conversation.
	 *
	 * Refuses anything that is not the caller's own unclaimed messenger upload —
	 * an id belonging to someone else, or one already hanging off a message, is
	 * how a stray attachment would end up in a room it was never sent to.
	 *
	 * @param int[] $file_ids
	 * @return File[] in the order given
	 * @throws MessengerRefusal
	 */
	public static function claim(array $file_ids, int $user_id, Conversation $conversation): array {
		$out = array();
		foreach ($file_ids as $file_id) {
			$file_id = (int)$file_id;
			if ($file_id <= 0 || !File::check_if_exists($file_id)) {
				throw new MessengerRefusal('One of those attachments is no longer available.');
			}
			$file = new File($file_id, TRUE);

			if ((int)$file->get('fil_usr_user_id') !== $user_id
				|| $file->get('fil_source') !== File::SOURCE_MESSENGER_ATTACHMENT
				|| $file->get('fil_delete_time')) {
				throw new MessengerRefusal('One of those attachments is no longer available.');
			}

			$already = new MultiMessageAttachment(array('file_id' => $file_id));
			if ($already->count() > 0) {
				throw new MessengerRefusal('That attachment has already been sent.');
			}

			// From loose upload to conversation attachment: the gate replaces
			// private ownership, so everyone in the room can open it and the
			// bytes stay out of the public store either way.
			$file->set('fil_private', false);
			$file->set('fil_access_provider', MessengerAttachmentGate::KEY);
			$file->set('fil_access_ref', (int)$conversation->key);
			$file->save();
			$file->load();

			$out[] = $file;
		}
		return $out;
	}
}
