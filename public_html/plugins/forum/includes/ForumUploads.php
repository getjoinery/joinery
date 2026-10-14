<?php
/**
 * ForumUploads — files on their way onto a post.
 *
 * A member attaches files while writing, before the post exists: the
 * forum_upload action stores each one as the uploader's own private File
 * (fil_source forum_attachment, no gate). Saving the post claims them
 * (claimAll): each File is re-pointed at the post through the forum's access
 * gate, so it is exactly as visible as the post, and an EntityAttachment row
 * lists it. A File nobody claims within a day is removed by ForumMaintenance.
 *
 * @version 1.0.0
 */

class ForumUploads {

	/** Origin tag on a forum attachment, declared under fileSources in plugin.json. */
	const FILE_SOURCE = 'forum_attachment';

	/** Most files on one post. */
	const MAX_PER_POST = 10;

	/** Most files a member may hold uploaded but not yet posted. */
	const MAX_UNCLAIMED = 20;

	/** Uploads one member may make per UPLOAD_WINDOW seconds. */
	const UPLOAD_RATE = 30;
	const UPLOAD_WINDOW = 600;

	/** File types a post refuses outright (served as downloads anyway; refused for clarity). */
	const BLOCKED_EXTENSIONS = array(
		'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
		'exe', 'com', 'bat', 'cmd', 'scr', 'msi', 'dll', 'jar', 'app',
		'sh', 'bash', 'zsh', 'ps1', 'vbs', 'js', 'jse', 'wsf', 'hta', 'svg', 'html', 'htm',
	);

	public static function isBlockedName(string $name): bool {
		$extension = strtolower((string)pathinfo(trim($name), PATHINFO_EXTENSION));
		return $extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true);
	}

	public static function maxBytes(): int {
		return Forum::settingInt('forum_max_attachment_mb', 10, 1) * 1024 * 1024;
	}

	/**
	 * Store one uploaded file as the uploader's own, not yet on any post.
	 *
	 * @param array $upload one entry of $_FILES
	 * @throws ForumRefusal with something the member can act on
	 */
	public static function store(array $upload, int $user_id): File {
		$state = RequestLogger::rate_limit_state('forum_upload', self::UPLOAD_RATE, self::UPLOAD_WINDOW, null, $user_id);
		if (!$state['allowed']) {
			throw new ForumRefusal('You are uploading quickly. Try again in ' . $state['retry_after'] . ' seconds.');
		}
		RequestLogger::log('forum_upload', 'upload', true, array('user_id' => $user_id));
		$q = DbConnector::GetPreparedStatement("SELECT COUNT(*) FROM fil_files
			WHERE fil_usr_user_id = ? AND fil_source = ? AND fil_access_provider IS NULL AND fil_delete_time IS NULL");
		$q->execute(array($user_id, self::FILE_SOURCE));
		if ((int)$q->fetchColumn() >= self::MAX_UNCLAIMED) {
			throw new ForumRefusal('You have ' . self::MAX_UNCLAIMED . ' files waiting to be posted. Post them, or wait a day for them to clear.');
		}
		if (!isset($upload['error']) || is_array($upload['error'])) {
			throw new ForumRefusal('That upload did not arrive properly.');
		}
		switch ($upload['error']) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				throw new ForumRefusal('That file is too large for this server to accept.');
			case UPLOAD_ERR_NO_FILE:
				throw new ForumRefusal('No file was chosen.');
			default:
				throw new ForumRefusal('That upload did not finish.');
		}
		if (!is_uploaded_file($upload['tmp_name'])) {
			throw new ForumRefusal('That upload did not arrive properly.');
		}
		$max = self::maxBytes();
		if ((int)$upload['size'] > $max) {
			throw new ForumRefusal('Files can be up to ' . (int)($max / 1048576) . ' MB. That one is larger.');
		}
		$name = trim(str_replace(array("\r", "\n", "\0"), '', basename((string)($upload['name'] ?? 'attachment'))));
		if ($name === '') {
			$name = 'attachment';
		}
		if (self::isBlockedName($name)) {
			throw new ForumRefusal('That kind of file cannot be attached to a post.');
		}
		$mime = File::detect_mime_file($upload['tmp_name']);
		if (!$mime) {
			$mime = (string)($upload['type'] ?? 'application/octet-stream');
		}
		try {
			return File::createFromUpload($upload['tmp_name'], $name, $mime, $user_id, array(
				'fil_private' => true,
				'fil_source'  => self::FILE_SOURCE,
			));
		} catch (Exception $e) {
			throw new ForumRefusal('That file could not be stored.');
		}
	}

	/**
	 * Attach the member's own unclaimed uploads to a post: gate each File on the
	 * post and list it. Ids that are not theirs, not forum uploads, or already on
	 * a post are refused — that is how a stray file would land somewhere it was
	 * never meant for.
	 *
	 * @param int[] $file_ids
	 */
	public static function claimAll(array $file_ids, int $user_id, ForumPost $post): void {
		$file_ids = array_values(array_unique(array_filter(array_map('intval', $file_ids))));
		if (!$file_ids) {
			return;
		}
		if (count($file_ids) > self::MAX_PER_POST) {
			throw new ForumRefusal('A post can carry up to ' . self::MAX_PER_POST . ' files.');
		}
		$order = 0;
		foreach ($file_ids as $file_id) {
			if (!File::check_if_exists($file_id)) {
				throw new ForumRefusal('One of the attached files is no longer available.');
			}
			$file = new File($file_id, TRUE);
			if ((int)$file->get('fil_usr_user_id') !== $user_id
				|| $file->get('fil_source') !== self::FILE_SOURCE
				|| $file->get('fil_delete_time')
				|| $file->get('fil_access_provider')) {
				throw new ForumRefusal('One of the attached files is no longer available.');
			}
			File::updateColumns($file_id, array(
				'fil_private'         => false,
				'fil_access_provider' => ForumAttachmentGate::KEY,
				'fil_access_ref'      => (int)$post->key,
			));
			$attachment = new EntityAttachment(NULL);
			$attachment->set('eat_entity_type', ForumPost::ENTITY_TYPE);
			$attachment->set('eat_entity_id', (int)$post->key);
			$attachment->set('eat_fil_file_id', $file_id);
			$attachment->set('eat_usr_user_id', $user_id);
			$attachment->set('eat_filename', mb_substr((string)$file->get('fil_title') ?: (string)$file->get('fil_name'), 0, 255));
			$attachment->set('eat_mime_type', (string)$file->get('fil_type'));
			$attachment->set('eat_byte_size', self::byteSize($file));
			$attachment->set('eat_sort_order', $order++);
			$attachment->save();
		}
	}

	/** A post's attachments as a page shows them. */
	public static function forPosts(array $post_ids): array {
		$out = array();
		foreach (EntityAttachment::forEntities(ForumPost::ENTITY_TYPE, $post_ids) as $post_id => $attachments) {
			foreach ($attachments as $a) {
				$file = new File((int)$a->get('eat_fil_file_id'), TRUE);
				$out[$post_id][] = array(
					'name'     => (string)$a->get('eat_filename'),
					'is_image' => $a->isImage(),
					'size'     => (int)$a->get('eat_byte_size'),
					'url'      => $file->get_url(),
				);
			}
		}
		return $out;
	}

	/** Remove a post's attachments for good (the post is going). */
	public static function removeAllFor(int $post_id): void {
		EntityAttachment::removeAllFor(ForumPost::ENTITY_TYPE, $post_id);
	}

	/**
	 * Remove forum uploads nobody attached to a post within $hours.
	 *
	 * @return int how many went
	 */
	public static function pruneUnclaimed(int $hours = 24): int {
		$q = DbConnector::GetPreparedStatement("SELECT fil_file_id FROM fil_files
			WHERE fil_source = ? AND fil_access_provider IS NULL AND fil_delete_time IS NULL
			  AND fil_create_time < now() - (? * INTERVAL '1 hour')");
		$q->execute(array(self::FILE_SOURCE, $hours));
		$n = 0;
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $file_id) {
			$file = new File((int)$file_id, TRUE);
			$file->permanent_delete();
			$n++;
		}
		return $n;
	}

	/** The file's size: it belongs to its bytes (the blob), not the File row. */
	private static function byteSize(File $file): int {
		return $file->size_bytes();
	}
}
