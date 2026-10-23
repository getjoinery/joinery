<?php
/**
 * OfficeEditorWopi — the three requests the editor makes of the site.
 *
 *   GET  /office_editor/wopi/files/{id}           CheckFileInfo
 *   GET  /office_editor/wopi/files/{id}/contents  GetFile
 *   POST /office_editor/wopi/files/{id}/contents  PutFile (X-WOPI-Override: PUT)
 *
 * A protocol surface whose paths, verbs and headers the editor dictates, the
 * same standing exception as a payment webhook: not an /api/v1 action. What
 * the browser calls is (office_editor_open, office_editor_create).
 *
 * Who may call: only the editor. Three locks, each sufficient alone against a
 * different attacker:
 *   1. The listener. The installer's Apache drop-in serves these paths on a
 *      vhost bound to 127.0.0.1:9981, which sets JOINERY_OFFICE_WOPI; a request
 *      must carry that variable and have arrived on a loopback local address
 *      (127.0.0.1 or ::1). Nothing off the machine can connect to that
 *      listener, so it admits exactly what a loopback client check would.
 *   2. The proof. Every request is signed by the editor's proof key (WopiProof).
 *   3. The token. It names the user and the file (OfficeEditorToken), and what
 *      it allows is asked again of Drive on every request.
 *
 * Answers follow the editor's reading of them: 401/403/404 mean "not allowed",
 * 409 with COOLStatusCode 1010 means "changed underneath you", 413 "too large".
 *
 * @version 0.1.0
 */
class OfficeEditorWopi {

	const DOC_CHANGED = 1010;

	/** The status of the last answer (the suite reads it; a CLI has no response line). */
	public static $last_status = null;

	/** Entry point from the route handler, which has left the page's output buffers. */
	public static function handle($file_id, $contents) {
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		try {
			if (!self::fromListener()) {
				return self::reply(403, array('error' => 'not here'));
			}
			$token = self::requestToken();
			$claims = OfficeEditorToken::verify($token, (int)$file_id);
			if ($claims === null) {
				return self::reply(401, array('error' => 'invalid token'));
			}
			if (!self::proofHolds($token)) {
				return self::reply(403, array('error' => 'invalid proof'));
			}
			$file = DriveHelper::load_file((int)$file_id);
			if (!$file || ($file->get('fil_delete_time') !== null && $file->get('fil_delete_time') !== '')) {
				return self::reply(404, array('error' => 'no such file'));
			}
			$user = new User($claims['user_id'], true);
			if (!$user->key || !DriveHelper::can_read(DriveHelper::ENTITY_FILE, $file, $user->key)) {
				return self::reply(404, array('error' => 'no such file'));
			}
			// The level is asked now, not at open: a file moved into a Private
			// folder mid-session stops at its next request.
			if (!OfficeEditor::isStandard($file) || !OfficeEditor::memberMay($user->key)) {
				return self::reply(403, array('error' => 'not openable'));
			}

			if (!$contents && $method === 'GET') {
				return self::checkFileInfo($file, $user, $claims);
			}
			if ($contents && $method === 'GET') {
				return self::getFile($file);
			}
			if ($contents && $method === 'POST' && strtoupper((string)($_SERVER['HTTP_X_WOPI_OVERRIDE'] ?? '')) === 'PUT') {
				return self::putFile($file, $user, $claims);
			}
			return self::reply(501, array('error' => 'not supported'));
		} catch (Throwable $e) {
			error_log('[office_editor] WOPI ' . $method . ' file ' . (int)$file_id . ' failed: ' . $e->getMessage());
			return self::reply(500, array('error' => 'server error'));
		}
	}

	/** Did this request come in on the loopback listener the installer made? */
	public static function fromListener() {
		if ((string)getenv('JOINERY_OFFICE_WOPI') !== '1' && (string)($_SERVER['JOINERY_OFFICE_WOPI'] ?? '') !== '1') {
			return false;
		}
		return IpAddress::in((string)($_SERVER['SERVER_ADDR'] ?? ''), array('127.0.0.1', '::1'));
	}

	private static function requestToken() {
		if (isset($_GET['access_token']) && is_string($_GET['access_token'])) {
			return $_GET['access_token'];
		}
		$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
		if (stripos($auth, 'Bearer ') === 0) {
			return trim(substr($auth, 7));
		}
		return '';
	}

	/** The proof checked against discovery's key; asked again once on a miss (a reinstall rotates it). */
	private static function proofHolds($token) {
		$url = OfficeEditor::WOPI_BASE . (string)($_SERVER['REQUEST_URI'] ?? '');
		$ticks = (string)($_SERVER['HTTP_X_WOPI_TIMESTAMP'] ?? '');
		$proof = (string)($_SERVER['HTTP_X_WOPI_PROOF'] ?? '');
		$old = (string)($_SERVER['HTTP_X_WOPI_PROOFOLD'] ?? '');
		foreach (array(false, true) as $refresh) {
			$d = OfficeEditor::discovery($refresh);
			if (!$d || empty($d['proof'])) {
				continue;
			}
			if (WopiProof::verify($d['proof'], $token, $url, $ticks, $proof, $old)) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------

	private static function checkFileInfo($file, $user, array $claims) {
		$can_write = $claims['can_write'] && DriveHelper::can_write(DriveHelper::ENTITY_FILE, $file, $user->key);
		$meta = self::headMeta($file);
		$info = array(
			'BaseFileName'            => (string)$file->get('fil_title'),
			'Size'                    => $meta['size'],
			'OwnerId'                 => (string)(int)$file->get('fil_usr_user_id'),
			'UserId'                  => (string)(int)$user->key,
			'UserFriendlyName'        => (string)$user->display_name(),
			'Version'                 => (string)$meta['sha'],
			'LastModifiedTime'        => $meta['modified'],
			'UserCanWrite'            => $can_write,
			'PostMessageOrigin'       => (string)$claims['origin'],
			'SupportsUpdate'          => true,
			'SupportsLocks'           => false,
			'SupportsRename'          => false,
			'UserCanRename'           => false,
			'UserCanNotWriteRelative' => true,
			'EnableOwnerTermination'  => false,
			'EnableInsertRemoteImage' => false,
			'EnableInsertRemoteFile'  => false,
			'EnableShare'             => false,
			'IsAdminUser'             => false,
		);
		return self::reply(200, $info);
	}

	private static function getFile($file) {
		$blob_id = (int)$file->get('fil_fbb_file_blob_id');
		$blob = ($blob_id > 0) ? new FileBlob($blob_id, true) : null;
		if (!$blob || !$blob->key) {
			return self::reply(404, array('error' => 'no content'));
		}
		// The editor loading the file starts its editing span. The version it
		// is told is the blob served, read from that blob, never a fresh look
		// at the head that a write in between could have moved.
		$meta = array('sha' => (string)$blob->get('fbb_sha256'));
		SystemBase::server_initiated_write(function () use ($file) {
			OfficeEditorSession::begin($file->key);
		});
		if ($blob->get('fbb_storage_driver') === 'cloud') {
			$bytes = $blob->read_bytes('original');
			if ($bytes === null) {
				return self::reply(500, array('error' => 'content unreadable'));
			}
			self::$last_status = 200;
			if (!headers_sent()) {
				header('Content-Type: application/octet-stream');
				header('Content-Length: ' . strlen($bytes));
				header('X-WOPI-ItemVersion: ' . $meta['sha']);
			}
			echo $bytes;
			return true;
		}
		$path = $blob->filesystem_path('original');
		if (!is_file($path)) {
			return self::reply(500, array('error' => 'content unreadable'));
		}
		self::$last_status = 200;
		if (!headers_sent()) {
			header('Content-Type: application/octet-stream');
			header('Content-Length: ' . filesize($path));
			header('X-WOPI-ItemVersion: ' . $meta['sha']);
		}
		readfile($path);
		return true;
	}

	/**
	 * Save the editor's bytes, the way a Drive upload saves a new version:
	 * quota under the owner's quota lock, then usage, the change feed and the
	 * sync memo. See save() for what one editing span writes.
	 */
	private static function putFile($file, $user, array $claims) {
		if (!$claims['can_write'] || !DriveHelper::can_write(DriveHelper::ENTITY_FILE, $file, $user->key)) {
			OfficeEditorSession::recordRefusal($file->key, 'read_only');
			return self::reply(403, array('error' => 'read only'));
		}
		$owner_id = (int)$file->get('fil_usr_user_id');
		$max = (int)SubscriptionTier::getUserFeature($owner_id, 'drive_max_file_bytes', 0);

		$tmp = tempnam(sys_get_temp_dir(), 'oes_put_');
		if ($tmp === false) {
			return self::reply(500, array('error' => 'no temp space'));
		}
		try {
			$in = fopen('php://input', 'rb');
			$out = fopen($tmp, 'wb');
			$size = ($in && $out) ? stream_copy_to_stream($in, $out) : false;
			if ($in) { fclose($in); }
			if ($out) { fclose($out); }
			if ($size === false) {
				return self::reply(500, array('error' => 'body unreadable'));
			}
			if ($max > 0 && $size > $max) {
				OfficeEditorSession::recordRefusal($file->key, 'too_large');
				return self::reply(413, array('error' => 'too large'));
			}
			$header = isset($_SERVER['HTTP_X_COOL_WOPI_TIMESTAMP']) ? (string)$_SERVER['HTTP_X_COOL_WOPI_TIMESTAMP'] : null;
			$exit = strtolower((string)($_SERVER['HTTP_X_COOL_WOPI_ISEXITSAVE'] ?? '')) === 'true';
			$r = self::save($file, $user, $tmp, $header, $exit);
			return self::reply($r['status'], $r['body']);
		} finally {
			if (is_file($tmp)) {
				@unlink($tmp);
			}
		}
	}

	/**
	 * The save itself, from bytes on disk at $path (consumed). Public for the
	 * suite, which has no editor to sign a request with.
	 *
	 * One editing span is one version (OfficeEditorSession): its first save
	 * demotes the head the editor loaded, its later saves replace the head it
	 * wrote. Both are checked where the head is written, under the file's row
	 * hold — the timestamp compare up front is only the cheap first answer, since
	 * a sync upload can land between it and the write:
	 *   - a save that is not a replace (the span's first, or one with no span)
	 *     is guarded by the head the time compare just agreed
	 *     (save_new_content's base_sha256): moved since, it is a conflict;
	 *   - a later save replaces only the head this span wrote
	 *     (replace_head_content): anything else there, it is a conflict.
	 * With no timestamp the member chose to keep their copy over the other one:
	 * the save goes in as a new version, unguarded, and nothing is overwritten
	 * that Version history does not keep.
	 *
	 * @param string|null $timestamp_header X-COOL-WOPI-Timestamp, null when absent
	 * @param bool        $exit_save        the save made as the last person left
	 * @return array{status:int, body:array}
	 */
	public static function save($file, $user, $path, $timestamp_header, $exit_save = false) {
		$owner_id = (int)$file->get('fil_usr_user_id');
		$size = (int)filesize($path);
		$checked = ($timestamp_header !== null && $timestamp_header !== '');

		DriveHelper::quota_lock($owner_id);
		$owner_locked = false;
		$blob = null;
		try {
			$span = OfficeEditorSession::current($file->key);
			$head_now = (int)$file->get('fil_fbb_file_blob_id');
			$replacing = ($span !== null && $span['head_blob_id'] > 0 && $span['head_blob_id'] === $head_now);
			$quota = (int)SubscriptionTier::getUserFeature($owner_id, 'drive_storage_bytes', 0);
			$current = DriveUsage::recompute($owner_id);
			$adds = $replacing ? $size - self::headMeta($file)['size'] : $size;
			if ($quota > 0 && $current + $adds > $quota) {
				OfficeEditorSession::recordRefusal($file->key, 'quota');
				return array('status' => 413, 'body' => array('error' => 'quota'));
			}

			// Drive content is private, always: never the fast-serve directory.
			$blob = FileBlob::createFromPath($path, (string)$file->get('fil_type'), true);

			// Held against a trash of the owner's tree from the write to its
			// change row, as every Drive write is.
			DriveHelper::owner_write_lock($owner_id);
			$owner_locked = true;
			DriveHelper::forget_sync_meta($file->key);
			$fresh = DriveHelper::load_file($file->key);
			if (!$fresh || ($fresh->get('fil_delete_time') !== null && $fresh->get('fil_delete_time') !== '')) {
				return array('status' => 404, 'body' => array('error' => 'no such file'));
			}
			$now_meta = self::headMeta($fresh);
			if ($checked && $timestamp_header !== $now_meta['modified']) {
				return self::conflict($file->key);
			}
			$head_now = (int)$fresh->get('fil_fbb_file_blob_id');
			if ($head_now === (int)$blob->key) {
				// The head already holds these bytes: nothing new to keep.
				return self::unchanged($fresh, $now_meta, $exit_save);
			}

			$wrote = false;
			if ($span !== null && $span['head_blob_id'] > 0) {
				$wrote = FileVersion::replace_head_content($fresh, $blob, $span['head_blob_id']);
				if (!$wrote && $checked) {
					return self::conflict($file->key);
				}
			}
			if (!$wrote) {
				// Past the time compare, the editor provably holds the head as it
				// stood then; the guard keeps it so until the write.
				$guard = $checked ? array('base_sha256' => $now_meta['sha']) : array();
				try {
					$wrote = FileVersion::save_new_content($fresh, $blob, $user->key, $guard);
				} catch (FileHeadMovedException $moved) {
					return self::conflict($file->key);
				}
				if (!$wrote) {
					return self::unchanged($fresh, $now_meta, $exit_save);
				}
			}
			$new_head = (int)$blob->key;
			$blob = null; // the head holds its reference now

			// The editor sends no file date; the old version's must not stay on
			// the new content, or every computer stamps the edit with it.
			$fresh->set('fil_content_modified_time', null);
			$fresh->save();
			if ($exit_save) {
				OfficeEditorSession::end($fresh->key);
			} else {
				OfficeEditorSession::recordSave($fresh->key, $new_head);
			}
			DriveUsage::recompute($owner_id);
			FileChange::record(FileChange::KIND_CONTENT, DriveHelper::ENTITY_FILE, $fresh->key, $owner_id, $user->key);
			DriveHelper::owner_write_unlock($owner_id);
			$owner_locked = false;
			DriveHelper::forget_sync_meta($fresh->key);
			return array('status' => 200, 'body' => array('LastModifiedTime' => self::headMeta($fresh)['modified']));
		} finally {
			// Every way out that did not hand the blob to the head gives its
			// reference back: bytes nothing holds are never reclaimed.
			if ($blob !== null) {
				FileBlob::release($blob->key);
			}
			if ($owner_locked) {
				DriveHelper::owner_write_unlock($owner_id);
			}
			DriveHelper::quota_unlock($owner_id);
		}
	}

	private static function conflict($file_id) {
		OfficeEditorSession::recordRefusal($file_id, 'conflict');
		return array('status' => 409, 'body' => array('COOLStatusCode' => self::DOC_CHANGED));
	}

	private static function unchanged($file, array $meta, $exit_save) {
		if ($exit_save) {
			OfficeEditorSession::end($file->key);
		} else {
			// What the editor holds is what is stored: nothing waits to be saved.
			$span = OfficeEditorSession::current($file->key);
			OfficeEditorSession::recordSave($file->key, $span !== null ? $span['head_blob_id'] : 0);
		}
		return array('status' => 200, 'body' => array('LastModifiedTime' => $meta['modified']));
	}

	/**
	 * The head's size, hash and LastModifiedTime. LastModifiedTime is when the
	 * head blob was stored, so any save that changes the bytes — ours, a sync
	 * client's, a restore — moves it, and the editor echoes it back on its next
	 * save for the conflict check. (A restore back to the very bytes the editor
	 * holds reads as unchanged, which is true of the bytes.)
	 *
	 * @return array{size:int, sha:string, modified:string}
	 */
	public static function headMeta($file) {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT b.fbb_size_bytes, b.fbb_sha256, b.fbb_create_time
			   FROM fil_files f
			   LEFT JOIN fbb_file_blobs b ON b.fbb_file_blob_id = f.fil_fbb_file_blob_id
			  WHERE f.fil_file_id = ?');
		$q->execute(array((int)$file->key));
		$row = $q->fetch(PDO::FETCH_ASSOC) ?: array();
		return array(
			'size'     => (int)($row['fbb_size_bytes'] ?? 0),
			'sha'      => (string)($row['fbb_sha256'] ?? ''),
			'modified' => self::iso((string)($row['fbb_create_time'] ?? '')),
		);
	}

	/** A stored UTC timestamp as ISO 8601, keeping every digit it has. */
	public static function iso($stored) {
		$stored = trim((string)$stored);
		if ($stored === '') {
			return '1970-01-01T00:00:00Z';
		}
		$stored = preg_replace('/[+-]00(:?00)?$/', '', $stored);
		return str_replace(' ', 'T', $stored) . 'Z';
	}

	private static function reply($status, array $body) {
		self::$last_status = (int)$status;
		if (!headers_sent()) {
			http_response_code((int)$status);
		}
		if (!headers_sent()) {
			header('Content-Type: application/json');
			header('Cache-Control: no-store');
		}
		echo json_encode($body, JSON_UNESCAPED_SLASHES);
		return true;
	}
}
