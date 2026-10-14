<?php
/**
 * BoardsUploads — files on their way onto a card.
 *
 * A small file arrives with the boards_upload request and is stored and attached
 * in one step. A large one travels over the platform's resumable chunked upload
 * (the 'boards_attachment' purpose, see includes/bootstrap.php) and lands as its
 * uploader's own private File; boards_upload then claims it onto a card. Either
 * way the finished attachment is a File gated on the board, so the people with a
 * role on the board can open it and nobody else can (BoardsAttachmentGate).
 *
 * @version 1.1.0 - FILE_SOURCE: the boards_attachment origin tag lives here, declared
 *   in the plugin's manifest (plugin.json "fileSources", specs/community_shared_components.md C6a).
 * @version 1.0.0
 */

class BoardsUploads {

	/** Origin tag (fil_source) on a file attached to a card (or a board's
	 *  background image), declared under "fileSources" in plugin.json. */
	const FILE_SOURCE = 'boards_attachment';

	/**
	 * File types a card refuses outright.
	 *
	 * Not a security boundary — the bytes are served as a download with the
	 * platform's own headers, never executed — but a task board has no business
	 * carrying a program, and refusing at the door is clearer than storing one and
	 * hoping nobody runs it.
	 */
	const BLOCKED_EXTENSIONS = array(
		'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
		'exe', 'com', 'bat', 'cmd', 'scr', 'msi', 'dll', 'jar', 'app',
		'sh', 'bash', 'zsh', 'ps1', 'vbs', 'js', 'jse', 'wsf', 'hta',
	);

	/** Is this a file name a card refuses? */
	public static function isBlockedName(string $name): bool {
		$extension = strtolower((string)pathinfo(trim($name), PATHINFO_EXTENSION));
		return $extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true);
	}

	/** The most bytes one attachment may be. */
	public static function maxBytes(): int {
		return Boards::settingInt('boards_max_attachment_mb', 25) * 1024 * 1024;
	}

	/**
	 * Store one uploaded file, gated on the board, ready to attach.
	 *
	 * @param array $upload one entry of $_FILES
	 * @return File
	 * @throws BoardsRefusal with something the member can act on
	 */
	public static function store(array $upload, int $user_id, Board $board): File {
		if (!isset($upload['error']) || is_array($upload['error'])) {
			throw new BoardsRefusal('That upload did not arrive properly.');
		}
		switch ($upload['error']) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				throw new BoardsRefusal('That file is too large for this server to accept.');
			case UPLOAD_ERR_NO_FILE:
				throw new BoardsRefusal('No file was chosen.');
			default:
				throw new BoardsRefusal('That upload did not finish.');
		}
		if (!is_uploaded_file($upload['tmp_name'])) {
			throw new BoardsRefusal('That upload did not arrive properly.');
		}

		$max_mb = Boards::settingInt('boards_max_attachment_mb', 25);
		if ((int)$upload['size'] > $max_mb * 1024 * 1024) {
			throw new BoardsRefusal('Files can be up to ' . $max_mb . ' MB. That one is larger.');
		}

		$name = trim(str_replace(array("\r", "\n", "\0"), '', basename((string)($upload['name'] ?? 'attachment'))));
		if ($name === '') {
			$name = 'attachment';
		}
		if (self::isBlockedName($name)) {
			throw new BoardsRefusal('That kind of file cannot be attached to a card.');
		}

		// The blob's own magic-byte detection wins over the browser's claim.
		$mime = File::detect_mime_file($upload['tmp_name']);
		if (!$mime) {
			$mime = (string)($upload['type'] ?? 'application/octet-stream');
		}

		return File::createFromUpload($upload['tmp_name'], $name, $mime, $user_id, array(
			'fil_private'         => false,
			'fil_access_provider' => BoardsAttachmentGate::KEY,
			'fil_access_ref'      => (int)$board->key,
			'fil_source'          => BoardsUploads::FILE_SOURCE,
		));
	}

	/**
	 * Take a File the person uploaded over the chunked transport and gate it on
	 * the board.
	 *
	 * Refuses anything that is not their own unclaimed boards upload — an id
	 * belonging to someone else, or one already hanging off a card, is how a stray
	 * file would end up on a board it was never meant for.
	 *
	 * @throws BoardsRefusal
	 */
	public static function claim(int $file_id, int $user_id, Board $board): File {
		if ($file_id <= 0 || !File::check_if_exists($file_id)) {
			throw new BoardsRefusal('That upload is no longer available.');
		}
		$file = new File($file_id, TRUE);
		if ((int)$file->get('fil_usr_user_id') !== $user_id
			|| $file->get('fil_source') !== BoardsUploads::FILE_SOURCE
			|| $file->get('fil_delete_time')
			|| $file->get('fil_access_provider')) {
			throw new BoardsRefusal('That upload is no longer available.');
		}
		$already = new MultiBoardAttachment(array('bat_fil_file_id' => $file_id));
		if ($already->count() > 0) {
			throw new BoardsRefusal('That file is already attached.');
		}
		if (self::isBlockedName((string)$file->get('fil_title'))) {
			throw new BoardsRefusal('That kind of file cannot be attached to a card.');
		}

		$file->set('fil_private', false);
		$file->set('fil_access_provider', BoardsAttachmentGate::KEY);
		$file->set('fil_access_ref', (int)$board->key);
		$file->save();
		$file->load();
		return $file;
	}

	/**
	 * Put a stored mailbox message's attachments on a card, without copying their bytes.
	 *
	 * Each attachment File the mailbox holds shares its refcounted blob with a new File
	 * that Boards gates itself (on the board), so the card's people can open it and the
	 * mailbox's access rules are not what lets them. Inline images, sealed or remote parts
	 * (a part with no File of its own), blocked file types, files over the attachment
	 * ceiling and anything past $max are skipped and counted.
	 *
	 * @param int $skipped set to the number of attachments not adopted
	 * @return BoardAttachment[] the attachments now on the card
	 */
	public static function adoptMessageAttachments(BoardCard $card, Board $board, int $user_id, InboundEmailMessage $msg, int $max, ?int &$skipped = null): array {
		$skipped = 0;
		$out = array();
		if (!class_exists('InboundMessageAttachment')) {
			return $out;
		}
		$rows = new MultiInboundMessageAttachment(array('message_id' => (int)$msg->key));
		foreach ($rows as $att) {
			$source_id = (int)$att->get('ima_fil_file_id');
			if ($att->get('ima_is_inline') || $att->get('ima_is_sealed') || $source_id <= 0 || count($out) >= $max) {
				$skipped++;
				continue;
			}
			$name = trim(str_replace(array("\r", "\n", "\0"), '', basename((string)$att->get('ima_filename'))));
			if ($name === '') {
				$name = 'attachment';
			}
			if (self::isBlockedName($name) || (int)$att->get('ima_size_bytes') > self::maxBytes()) {
				$skipped++;
				continue;
			}
			if (!File::check_if_exists($source_id)) {
				$skipped++;
				continue;
			}
			$source = new File($source_id, TRUE);
			$blob_id = (int)$source->get('fil_fbb_file_blob_id');
			if ($blob_id <= 0) {
				$skipped++;
				continue;
			}
			$file = File::createFromExistingBlob(new FileBlob($blob_id, TRUE), $name,
				(string)($att->get('ima_content_type') ?: 'application/octet-stream'), $user_id, array(
					'fil_private'         => false,
					'fil_access_provider' => BoardsAttachmentGate::KEY,
					'fil_access_ref'      => (int)$board->key,
					'fil_source'          => BoardsUploads::FILE_SOURCE,
				));
			if (!$file) {
				$skipped++;
				continue;
			}
			$out[] = BoardsCards::attachFile($card, $board, $user_id, $file);
		}
		return $out;
	}

	/** Put a stored File on a card. */
	public static function attach(BoardCard $card, File $file, int $user_id): BoardAttachment {
		$attachment = new BoardAttachment(NULL);
		$attachment->set('bat_bcd_card_id', (int)$card->key);
		$attachment->set('bat_fil_file_id', (int)$file->key);
		$attachment->set('bat_name', (string)$file->get('fil_title'));
		$attachment->set('bat_usr_user_id', $user_id);
		$attachment->save();
		return $attachment;
	}
}
