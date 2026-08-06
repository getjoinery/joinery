<?php
/**
 * DriveSealed — Drive's Sealed Vault consumer: everything about a Private file's
 * key, in one place.
 *
 * A Private Drive file is server custody. Its bytes on disk are a
 * SealedFileContainer sealed under a per-file key; that key is wrapped to the
 * owner's vault public key and stored on the file row (fil_sealed_key). Sealing
 * therefore needs nothing but the owner's PUBLIC key — an upload into a Private
 * folder succeeds with the vault locked, from any session with write access, the
 * same rule mail ingest lives by. Opening needs the owner's unlock window.
 *
 * This file is a CORE consumer (VaultUnlock::CONSUMER_CORE_FILES): it registers
 * the streaming decrypt hook and the rotation callback the same way a plugin
 * bootstrap does, because Drive has no plugin to carry them.
 *
 * The vault it seals to is the server-custody one — scope 'user', the same vault
 * mail and chat use. The 'drive' scope is CLIENT custody and belongs to Fortress
 * folders, whose keys the server must never hold.
 *
 * @version 1.1.0
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/SealedFileContainer.php'));
require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class DriveSealedException extends RuntimeException {}

class DriveSealed {

	/** fil_source tag whose files this consumer owns the decryption of. */
	const SOURCE = 'drive';

	/** Bytes re-encrypted per batch during a level change (see runTransitionBatch). */
	const TRANSITION_BYTE_BUDGET = 67108864; // 64 MB

	// ------------------------------------------------------------------
	// Keys
	// ------------------------------------------------------------------

	/** The owner's server-custody vault, or null when they have none yet. */
	public static function vaultFor($user_id) {
		return UserEncryptionVault::loadForUser((int)$user_id, UserEncryptionVault::SCOPE_USER);
	}

	/** The owner's vault, or a refusal naming what is missing. */
	public static function requireVault($user_id) {
		$vault = self::vaultFor($user_id);
		if ($vault === null) {
			throw new DriveSealedException('That owner has no vault, so nothing can be sealed to them.');
		}
		return $vault;
	}

	/**
	 * Unwrap a sealed file's key for reading. Needs the owner's window open.
	 *
	 * Unwrapping ARMS THE HOT-TURN RULE (SealedEgressGuard::markHot): from here
	 * on, this request has touched protected content, so anything it derives must
	 * land sealed or be refused. That single call is why Drive needs no
	 * egress-specific code of its own.
	 *
	 * @throws VaultLockedException when the owner's window is closed
	 */
	public static function fileKey(File $file) {
		$sealed_key = (string)$file->get('fil_sealed_key');
		if ($sealed_key === '') {
			throw new DriveSealedException('File ' . (int)$file->key . ' is marked sealed but carries no key.');
		}
		$owner_id = (int)($file->get('fil_sealed_owner_user_id') ?: $file->get('fil_usr_user_id'));
		if ($owner_id <= 0) {
			throw new VaultLockedException();
		}
		$secret = VaultUnlock::secretKey($owner_id);
		if ($secret === null) {
			throw new VaultLockedException();
		}

		require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
		SealedEgressGuard::markHot('fil:' . (int)$file->key . ':content');

		$crypto = new VaultCrypto();
		return $crypto->openItemDek($sealed_key, $secret);
	}

	/**
	 * The key a NEW VERSION of a sealed file must be sealed under: the file's
	 * existing one.
	 *
	 * A version cannot carry a fresh key — the row holds a single wrapping, and
	 * every earlier version's bytes were sealed under the key that wrapping
	 * opens. Minting a new one would strand them. Fortress versions follow the
	 * same rule for the same reason.
	 *
	 * This is the one write that needs the owner's window open, because reading
	 * the existing key is a read.
	 *
	 * @throws VaultLockedException when the owner's window is closed
	 */
	public static function versionKeyFor(File $file) {
		if (!$file->is_sealed()) {
			throw new DriveSealedException('That file is not sealed.');
		}
		return self::fileKey($file);
	}

	// ------------------------------------------------------------------
	// Write
	// ------------------------------------------------------------------

	/**
	 * Turn plaintext bytes on disk into a sealed Drive file.
	 *
	 * The order here is the whole design, and each step is where it is for a
	 * reason:
	 *
	 *  1. Sniff the MIME from the plaintext. Sniffing a container would report
	 *     application/octet-stream forever (the mistake InboundEmailRouter made
	 *     with createFromBytes on ciphertext).
	 *  2. Mint the file key and seal the bytes into a container in a temp file.
	 *     The container's AAD binds a content id and a chunk index, not a row id,
	 *     so this can happen before the row exists — which means the plaintext
	 *     never lands in the blob store at all, not even briefly.
	 *  3. Create the File from the CONTAINER. The blob measures ciphertext, which
	 *     is what storage and quota are charged for; the plaintext size is
	 *     recorded on the row for display and for Range arithmetic.
	 *  4. Record the key wrapping on the row.
	 *  5. Render the thumbnail from the still-present plaintext, seal it under
	 *     the same key, and store it in the encrypted variant slot.
	 *
	 * @param string $part_path    plaintext bytes (NOT consumed — the caller owns them)
	 * @param string $display_name original name, kept plaintext in fil_title
	 * @param string $mime_hint    client-supplied type; the sniff wins
	 * @param int    $owner_id     whose vault this seals to
	 * @param array  $restrictions extra columns for the new row
	 * @return File
	 */
	public static function createSealedFile($part_path, $display_name, $mime_hint, $owner_id, array $restrictions = array()) {
		$vault = self::requireVault($owner_id);

		$sniffed = File::detect_mime_file($part_path);
		if ($sniffed === null || $sniffed === '') {
			$sniffed = $mime_hint ?: 'application/octet-stream';
		}

		$crypto = new VaultCrypto();
		$fk = $crypto->newItemDek();

		$tmp = self::tempPath('seal');
		try {
			$info = SealedFileContainer::sealStream($part_path, $tmp, $fk);

			$restrictions = array_merge($restrictions, array(
				'fil_protection_level'  => ProtectionLevel::PRIVATE_,
				'fil_plain_size_bytes'  => (int)$info['plain_size'],
				// createFromUpload sets fil_type from the blob's magic bytes — which
				// for a container is meaningless. Restrictions are applied after
				// that, so this is what sticks.
				'fil_type'              => $sniffed,
			));

			// createFromUpload consumes its source; hand it the container.
			$file = File::createFromUpload($tmp, $display_name, $sniffed, $owner_id, $restrictions);
		} finally {
			if (is_file($tmp)) { @unlink($tmp); }
		}

		File::recordSealedKey($file->key, $vault, $fk);
		$file->load(); // pick up the wrapping columns the targeted UPDATE wrote

		self::storeSealedThumbnail($file, $part_path, $fk, $sniffed);
		return $file;
	}

	/**
	 * Seal an EXISTING plaintext file in place — the Standard -> Private raise.
	 * Public key only, so it runs from any owner session, window open or not.
	 *
	 * Copy-on-write aware through replace_bytes_from_path(): a deduped blob is
	 * split before it is rewritten, so a plaintext twin somewhere else in the
	 * system is never turned into ciphertext behind its owner's back.
	 *
	 * THE ORDER OF THE LAST TWO WRITES IS THE WHOLE DURABILITY ARGUMENT. The
	 * wrapping is recorded BEFORE the bytes are swapped, because the two failure
	 * states are not symmetric:
	 *
	 *   key first  — a pass that dies between them leaves a plaintext file
	 *                carrying a spare wrapping. Harmless: the next pass mints a
	 *                fresh key and writes over it.
	 *   bytes first — a pass that dies between them leaves ciphertext whose key
	 *                existed only in this process's memory. Unrecoverable.
	 *
	 * A raise is a batch job on a byte budget with no time budget, so "this pass
	 * is killed halfway through a large file" is an ordinary event, not a
	 * disaster scenario.
	 *
	 * @return int plaintext bytes sealed
	 */
	public static function sealExistingFile(File $file) {
		if ($file->is_sealed()) {
			return 0; // already there; a re-run of an interrupted batch
		}
		if ($file->is_encrypted()) {
			throw new DriveSealedException('A Fortress file cannot be sealed server-side.');
		}
		$owner_id = (int)$file->get('fil_usr_user_id');
		$vault = self::requireVault($owner_id);

		$src = $file->get_filesystem_path('original');
		if ($src === '' || $src === null || !is_file($src)) {
			throw new DriveSealedException('File ' . (int)$file->key . ' has no readable bytes to seal.');
		}

		// Resume before anything else: what is ON DISK decides, not the row. A
		// row that says Standard over bytes that are already a container is an
		// interrupted pass, and sealing those bytes again would bury them under a
		// second key.
		if (SealedFileContainer::looksSealed($src)) {
			return self::finishInterruptedRaise($file, $src);
		}

		$plain_size = (int)filesize($src);
		$mime = (string)$file->get('fil_type');

		// The thumbnail has to be rendered while the plaintext is still in place.
		$crypto = new VaultCrypto();
		$fk = $crypto->newItemDek();

		$tmp = self::tempPath('raise');
		try {
			SealedFileContainer::sealStream($src, $tmp, $fk);
			$thumb_plain = self::renderThumbnail($file, $src, $mime);

			// Durable key first (see the note above), then reload so the instance
			// knows it is sealed — save() protects a sealed row's wrapping columns
			// from being written back as the NULLs this copy still holds.
			File::recordSealedKey($file->key, $vault, $fk);
			$file->load();

			if (!$file->replace_bytes_from_path($tmp)) {
				throw new DriveSealedException('Could not write the sealed bytes for file ' . (int)$file->key . '.');
			}
		} finally {
			if (is_file($tmp)) { @unlink($tmp); }
		}

		$file->set('fil_protection_level', ProtectionLevel::PRIVATE_);
		$file->set('fil_plain_size_bytes', $plain_size);
		$file->save();
		self::markBlobSealed($file);
		$file->load();

		// replace_bytes_from_path dropped every plaintext variant with the old
		// bytes; the sealed thumb replaces them.
		if ($thumb_plain !== null) {
			self::storeSealedThumbnailBytes($file, $thumb_plain, $fk);
			unset($thumb_plain);
		}
		return $plain_size;
	}

	/**
	 * Converge a row whose bytes were sealed by a pass that died before it could
	 * finish — the resumable half of the raise.
	 *
	 * The plaintext size comes from the container's LENGTH, which is arithmetic
	 * and needs no key, so this stays a locked-safe operation exactly like the
	 * raise it is finishing.
	 *
	 * No thumbnail: the plaintext it would be rendered from is gone. The file
	 * shows a type icon, which is a better answer than reading the content back
	 * in-window during a ceremony designed to run locked.
	 */
	private static function finishInterruptedRaise(File $file, $src) {
		if ((string)$file->get('fil_sealed_key') === '') {
			// The bytes are ciphertext and no wrapping ever reached the row, so the
			// key is gone. Re-sealing would add a second layer over an inner one
			// that can never be opened. Refuse: the batch logs this file and leaves
			// it in the backlog, which is what "needs a human" should look like.
			throw new DriveSealedException('File ' . (int)$file->key
				. ' holds sealed bytes with no key wrapping — an interrupted raise lost its key. '
				. 'Sealing it again would bury the bytes under a second key, so it is left untouched.');
		}
		$plain_size = SealedFileContainer::plainSize($src);
		$file->set('fil_protection_level', ProtectionLevel::PRIVATE_);
		$file->set('fil_plain_size_bytes', $plain_size);
		$file->save();
		self::markBlobSealed($file);
		$file->load();
		error_log('Drive level change: file ' . (int)$file->key
			. ' resumed from an interrupted raise (bytes were already sealed).');
		return $plain_size;
	}

	/**
	 * Tell the blob what its bytes now are: a container, which sniffs as
	 * application/octet-stream and is not a decodable image whatever the file
	 * says it holds.
	 *
	 * The file's own fil_type keeps the REAL type — that is what the member, the
	 * UI and the serve path read. This is about the blob layer, whose resize,
	 * variant-cleanup and offload paths all branch on fbb_mime_type and would
	 * otherwise try to decode ciphertext as a JPEG.
	 *
	 * Called AFTER the rewrite, never before: replace_bytes_from_path() splits a
	 * shared blob first, so this only ever re-types bytes this file owns, and the
	 * variant cleanup inside the rewrite still runs against the old, honest type.
	 */
	private static function markBlobSealed(File $file) {
		$blob = $file->_blob();
		if (!$blob || !$blob->key) {
			return;
		}
		if ((string)$blob->get('fbb_mime_type') !== 'application/octet-stream') {
			$blob->set('fbb_mime_type', 'application/octet-stream');
			$blob->save();
		}
	}

	/**
	 * Open a sealed file back to plaintext in place — the Private -> Standard
	 * lower. Needs the owner's window: decrypting needs the secret half.
	 *
	 * Streams through a temp file rather than accumulating plaintext in memory.
	 *
	 * @return int plaintext bytes restored
	 */
	public static function unsealExistingFile(File $file) {
		if (!$file->is_sealed()) {
			return 0; // already plaintext; a re-run of an interrupted batch
		}
		$src = $file->get_filesystem_path('original');
		if ($src === '' || $src === null || !is_file($src)) {
			throw new DriveSealedException('File ' . (int)$file->key . ' has no readable bytes to open.');
		}

		// Resume, the mirror of the raise's: a row that still says Private over
		// bytes that are no longer a container is a pass that swapped and then
		// died. Finish the row rather than trying to decrypt plaintext, which
		// would fail on every pass and strand the file in the backlog forever.
		if (!SealedFileContainer::looksSealed($src)) {
			$restored = (int)filesize($src);
			self::finishLower($file);
			error_log('Drive level change: file ' . (int)$file->key
				. ' resumed from an interrupted lower (bytes were already plaintext).');
			return $restored;
		}

		$fk = self::fileKey($file); // throws VaultLockedException when locked

		$tmp = self::tempPath('lower');
		$out = @fopen($tmp, 'wb');
		if ($out === false) {
			throw new DriveSealedException('Could not stage the opened bytes for file ' . (int)$file->key . '.');
		}
		try {
			SealedFileContainer::openStream($src, $fk, $out);
			fclose($out);
			$out = null;
			$plain_size = (int)filesize($tmp);
			if (!$file->replace_bytes_from_path($tmp)) {
				throw new DriveSealedException('Could not write the opened bytes for file ' . (int)$file->key . '.');
			}
		} finally {
			if ($out !== null) { fclose($out); }
			if (is_file($tmp)) { @unlink($tmp); }
		}

		self::finishLower($file);
		return $plain_size;
	}

	/**
	 * The row-and-variant half of a lower, once the bytes on disk are plaintext.
	 * Shared by the ordinary path and by the resume, so an interrupted lower
	 * converges to exactly the same state a clean one produces.
	 */
	private static function finishLower(File $file) {
		// Drop the wrapping with the seal: a row that is no longer sealed must not
		// keep a key that opens nothing, and the rotation sweep selects on these.
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare(
			"UPDATE fil_files SET fil_protection_level = ?, fil_content_sealed = false,
			 fil_sealed_key = NULL, fil_sealed_owner_user_id = NULL, fil_key_generation = 0,
			 fil_plain_size_bytes = NULL WHERE fil_file_id = ?")
			->execute(array(ProtectionLevel::STANDARD, (int)$file->key));
		$file->load();

		// Plaintext bytes get plaintext variants again — but the blob has to be
		// told what it is holding first, in this order:
		//
		//  1. Drop the sealed thumbnail EXPLICITLY. The rewrite called
		//     delete_resized(), which does nothing on a blob typed
		//     application/octet-stream — so without this the container in the
		//     thumb slot survives, and once fbb_encrypted_variant_key is cleared
		//     no lifecycle path knows it is there. It would then be served, as an
		//     image, to anyone who asked for the thumbnail.
		//  2. Restore the real type, so the blob is a decodable image again.
		//  3. Regenerate, which is a no-op unless step 2 made it one.
		$blob = $file->_blob();
		if ($blob && $blob->key) {
			$blob->delete_encrypted_variant();
			$real_mime = (string)$file->get('fil_type');
			if ($real_mime !== '' && (string)$blob->get('fbb_mime_type') !== $real_mime) {
				$blob->set('fbb_mime_type', substr($real_mime, 0, 128));
				$blob->save();
			}
		}
		if ($file->is_image()) {
			$file->resize('all');
		}
	}

	// ------------------------------------------------------------------
	// Level transitions
	// ------------------------------------------------------------------

	/**
	 * Files in a subtree that are not yet at $target_level — the backlog a level
	 * change has to converge.
	 *
	 * A folder's level is a promise about the folder; each file's own level is
	 * the truth about its bytes. Right after a level change those disagree for
	 * everything already inside, and this is the count of the disagreement. New
	 * uploads land at the folder's level immediately, so the backlog only
	 * shrinks.
	 */
	public static function transitionBacklog($folder_id, $target_level) {
		require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
		$ids = self::subtreeFolderIds($folder_id);
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT COUNT(*), COALESCE(SUM(COALESCE(fil_plain_size_bytes, fbb_size_bytes)), 0)
			 FROM fil_files LEFT JOIN fbb_file_blobs ON fbb_file_blob_id = fil_fbb_file_blob_id
			 WHERE fil_fol_folder_id IN (" . DriveHelper::int_in_list($ids) . ")
			   AND fil_protection_level <> ? AND fil_delete_time IS NULL");
		$stmt->execute(array($target_level));
		$row = $stmt->fetch(PDO::FETCH_NUM);
		return array('files' => (int)$row[0], 'bytes' => (int)$row[1]);
	}

	/**
	 * Convert one bounded batch of a subtree's files to $target_level.
	 *
	 * BYTE-budgeted, not row-counted. Mail's 200-row batches are safe because a
	 * message is capped at 25 MB; a Drive batch of 200 rows could be a terabyte.
	 * The budget is checked after each file so one oversized file still makes
	 * progress rather than deadlocking the loop.
	 *
	 * Raising (-> private) needs only the owner's public key, so it runs from any
	 * owner session. Lowering (-> standard) decrypts, so it needs the owner's
	 * window; a closed vault raises VaultLockedException and the caller says so.
	 *
	 * A single file that fails is logged and left in the backlog rather than
	 * failing the batch — otherwise one unreadable file would block the whole
	 * folder forever. The caller detects "a pass converted nothing" and stops.
	 *
	 * @return array{converted:int, failed:int, bytes:int, remaining:int}
	 */
	public static function runTransitionBatch($folder_id, $target_level, $byte_budget = null) {
		require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
		$byte_budget = ($byte_budget === null) ? self::TRANSITION_BYTE_BUDGET : (int)$byte_budget;
		$ids = self::subtreeFolderIds($folder_id);

		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT fil_file_id FROM fil_files
			 WHERE fil_fol_folder_id IN (" . DriveHelper::int_in_list($ids) . ")
			   AND fil_protection_level <> ? AND fil_delete_time IS NULL
			 ORDER BY fil_file_id ASC LIMIT 500");
		$stmt->execute(array($target_level));
		$file_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

		$converted = 0;
		$failed = 0;
		$bytes = 0;
		foreach ($file_ids as $fid) {
			$file = DriveHelper::load_file((int)$fid);
			if (!$file) {
				continue;
			}
			if ($file->is_encrypted()) {
				continue; // Fortress never converts server-side (doctrine D2)
			}
			try {
				$bytes += ($target_level === ProtectionLevel::PRIVATE_)
					? self::sealExistingFile($file)
					: self::unsealExistingFile($file);
				$converted++;
			} catch (VaultLockedException $e) {
				throw $e; // the whole pass needs the window; say so once
			} catch (Throwable $e) {
				$failed++;
				error_log('Drive level change: file ' . (int)$fid . ' could not be converted: ' . $e->getMessage());
			}
			if ($bytes >= $byte_budget) {
				break;
			}
		}

		$backlog = self::transitionBacklog($folder_id, $target_level);
		return array(
			'converted' => $converted,
			'failed'    => $failed,
			'bytes'     => $bytes,
			'remaining' => $backlog['files'],
		);
	}

	/** A folder and every folder beneath it. */
	public static function subtreeFolderIds($folder_id) {
		require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
		$ids = DriveHelper::descendant_folder_ids((int)$folder_id);
		$ids[] = (int)$folder_id;
		return array_values(array_unique(array_map('intval', $ids)));
	}

	// ------------------------------------------------------------------
	// Read
	// ------------------------------------------------------------------

	/**
	 * Stream a byte range of a sealed file's PLAINTEXT to $sink.
	 *
	 * @throws VaultLockedException when the owner's window is closed
	 */
	public static function openTo(File $file, callable $sink, $offset = 0, $length = null, $size_key = 'original') {
		$fk = self::fileKey($file);
		$path = self::localPathFor($file, $size_key);
		self::assertContainerIsWhole($file, $path, $size_key);
		try {
			return SealedFileContainer::openRange($path, $fk, $sink, $offset, $length);
		} finally {
			self::releaseTempCopy($file, $path, $size_key);
		}
	}

	/**
	 * Refuse a container that has lost whole blocks off its end.
	 *
	 * Per-chunk AEAD binds a chunk to its file and to its position, so a chunk
	 * cannot be reordered or transplanted — but nothing in the format states how
	 * many chunks there should be. Truncate a container on a block boundary and
	 * every surviving chunk still authenticates, while plainSize() derives a
	 * smaller and entirely plausible length from the file's size. The read would
	 * be a clean 200 over a silently shortened file.
	 *
	 * The row is the second witness. fil_plain_size_bytes was recorded at seal
	 * time from the plaintext itself, so a disagreement with what the bytes on
	 * disk now claim means the bytes changed underneath it.
	 *
	 * Only for the ORIGINAL: a variant's plaintext size is known to nothing but
	 * its own container, and an unknown size key is treated as a variant — a
	 * check that cannot be made must not be guessed at.
	 *
	 * @throws DriveSealedException when the container is short
	 */
	public static function assertContainerIsWhole(File $file, $path, $size_key) {
		if ($size_key !== 'original') {
			return;
		}
		$recorded = $file->get('fil_plain_size_bytes');
		if ($recorded === null || $recorded === '') {
			return; // nothing to check against (a row sealed before the size was kept)
		}
		$actual = SealedFileContainer::plainSize($path);
		if ((int)$recorded !== (int)$actual) {
			throw new DriveSealedException('Sealed file ' . (int)$file->key . ' is ' . (int)$actual
				. ' plaintext bytes on disk but was sealed at ' . (int)$recorded
				. ' — the container has been truncated or replaced.');
		}
	}

	/** The whole plaintext of a small sealed variant (a thumbnail). */
	public static function openVariant(File $file, $size_key) {
		$out = '';
		self::openTo($file, function ($b) use (&$out) { $out .= $b; }, 0, null, $size_key);
		return $out;
	}

	// ------------------------------------------------------------------
	// Thumbnails
	// ------------------------------------------------------------------

	/**
	 * Render a thumbnail from plaintext bytes on disk, seal it under the file's
	 * key, and store it in the blob's encrypted variant slot — the same slot a
	 * Fortress file's browser-made thumbnail uses.
	 */
	public static function storeSealedThumbnail(File $file, $plain_path, $fk, $mime = null) {
		$bytes = self::renderThumbnail($file, $plain_path, $mime);
		if ($bytes === null) {
			return false;
		}
		return self::storeSealedThumbnailBytes($file, $bytes, $fk);
	}

	private static function storeSealedThumbnailBytes(File $file, $bytes, $fk) {
		require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
		$thumb_key = DriveHelper::thumb_size_key();
		if ($thumb_key === null) {
			return false;
		}
		$blob = $file->_blob();
		if (!$blob || !$blob->key) {
			return false;
		}
		// A thumbnail is small, so the in-memory form of the container is fine
		// here — file content never takes this path.
		return $blob->store_encrypted_variant($thumb_key, SealedFileContainer::sealBytes($bytes, $fk));
	}

	/** Rendered thumbnail bytes, or null when this file has no thumbnail to make. */
	private static function renderThumbnail(File $file, $plain_path, $mime = null) {
		require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
		$mime = ($mime === null) ? (string)$file->get('fil_type') : (string)$mime;
		if (!File::is_inline_safe_type($mime)) {
			return null; // not a raster image the platform decodes
		}
		$thumb_key = DriveHelper::thumb_size_key();
		if ($thumb_key === null) {
			return null; // no variants configured — clients fall back to a type icon
		}
		$blob = $file->_blob();
		if (!$blob || !$blob->key) {
			return null;
		}
		$tmp = self::tempPath('thumb');
		try {
			if (!$blob->render_variant_to($plain_path, $tmp, $thumb_key)) {
				return null;
			}
			$bytes = @file_get_contents($tmp);
			return ($bytes === false || $bytes === '') ? null : $bytes;
		} finally {
			if (is_file($tmp)) { @unlink($tmp); }
		}
	}

	// ------------------------------------------------------------------
	// Paths
	// ------------------------------------------------------------------

	/**
	 * A local path holding the container for $size_key. An offloaded blob is
	 * pulled to a temp file first — releaseTempCopy() drops it afterwards.
	 *
	 * Ranges still cost only what they read for LOCAL blobs, which is every
	 * blob until a site turns on cloud offload; a cloud range read is a separate
	 * optimization the storage driver has to answer for.
	 */
	private static function localPathFor(File $file, $size_key) {
		$path = $file->get_filesystem_path($size_key);
		if ($path !== '' && $path !== null && is_file($path)) {
			return $path;
		}
		$bytes = $file->read_bytes($size_key); // pulls a cloud object
		if ($bytes === null) {
			throw new DriveSealedException('Sealed bytes for file ' . (int)$file->key . ' are missing.');
		}
		$tmp = self::tempPath('fetch');
		file_put_contents($tmp, $bytes);
		self::$temp_copies[$tmp] = true;
		return $tmp;
	}

	private static $temp_copies = array();

	private static function releaseTempCopy(File $file, $path, $size_key) {
		if (isset(self::$temp_copies[$path])) {
			@unlink($path);
			unset(self::$temp_copies[$path]);
		}
	}

	private static function tempPath($tag) {
		$dir = sys_get_temp_dir() . '/drive_sealed';
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
			@chmod($dir, 0777);
		}
		return $dir . '/' . $tag . '_' . bin2hex(random_bytes(8));
	}
}

// ---------------------------------------------------------------------------
// Consumer registration (docs/sealed_vault.md § consumer contract)
// ---------------------------------------------------------------------------

// --- Streaming decrypt hook -------------------------------------------------
// Drive's sealed files answer ranges, so they register the STREAMING hook shape
// rather than the whole-bytes one: File::serve_from_path() asks this opener for
// the plaintext size and then for the span the client wanted, and only the
// chunks covering that span are ever read or decrypted.
File::registerStreamingDecryptHook(DriveSealed::SOURCE, function (File $file, $size_key = null) {
	if (!$file->is_sealed()) {
		return null; // a plaintext or Fortress Drive file streams unchanged
	}
	return new DriveSealedStream($file, $size_key);
});

/**
 * The streaming decryptor for one sealed Drive file.
 *
 * The plaintext size is read from the CONTAINER, not from the row: the row knows
 * the original's size, and the same serve path also streams the sealed thumbnail
 * variant, whose size only its own container knows. One rule covers both — and
 * for the original the two are then cross-checked, which is what catches a
 * container that has lost whole blocks off its end (see
 * DriveSealed::assertContainerIsWhole).
 */
class DriveSealedStream implements FileStreamingDecryptor {
	private $file;
	private $size_key;
	private $fk = null;

	public function __construct(File $file, $size_key = null) {
		$this->file = $file;
		$this->size_key = $size_key;
	}

	public function prepare(string $path): void {
		// Resolving the key here — before serve_from_path() writes a header — is
		// what turns a closed vault into a clean 423 instead of a truncated body.
		$this->fk = DriveSealed::fileKey($this->file);
	}

	public function plainSize(string $path): int {
		// Runs inside serve_from_path()'s pre-header try, so a truncated container
		// becomes a 404 rather than a short, confident 200.
		DriveSealed::assertContainerIsWhole($this->file, $path, $this->size_key);
		return SealedFileContainer::plainSize($path);
	}

	public function stream(string $path, callable $sink, int $offset = 0, ?int $length = null): int {
		if ($this->fk === null) {
			$this->prepare($path);
		}
		return SealedFileContainer::openRange($path, $this->fk, $sink, $offset, $length);
	}
}

// --- Rotation re-seal callback (docs/sealed_vault.md § Key rotation) --------
// Re-wraps the per-file keys of EXACTLY the generation being drained — the only
// one $old_secret_key can open. The container bytes are untouched: rotation
// changes who can unwrap the key, never the key itself. Every file is attempted
// and any failure throws, so the ceremony cannot retire the old wrappings while
// a file still depends on them.
VaultUnlock::onReseal(function (int $user_id, string $old_secret_key, int $old_key_generation,
		string $new_public_key, int $new_key_generation) {
	$db = DbConnector::get_instance()->get_db_link();
	$crypto = new VaultCrypto();

	$stmt = $db->prepare(
		"SELECT fil_file_id, fil_sealed_key FROM fil_files
		 WHERE fil_content_sealed = true AND fil_key_generation = ?
		   AND fil_sealed_owner_user_id = ?");
	$stmt->execute(array($old_key_generation, $user_id));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$failed = 0;
	foreach ($rows as $row) {
		$id = (int)$row['fil_file_id'];
		try {
			$sealed = (string)$row['fil_sealed_key'];
			if ($sealed === '') {
				continue;
			}
			$fk = $crypto->openItemDek($sealed, $old_secret_key);
			$upd = $db->prepare(
				'UPDATE fil_files SET fil_sealed_key = ?, fil_key_generation = ? WHERE fil_file_id = ?');
			$upd->execute(array($crypto->sealItemDek($fk, $new_public_key), $new_key_generation, $id));
		} catch (Throwable $e) {
			$failed++;
			error_log('Drive vault reseal: failed for file ' . $id . ': ' . $e->getMessage());
		}
	}

	if ($failed > 0) {
		throw new RuntimeException(
			'Drive reseal: ' . $failed . ' of ' . count($rows) . ' sealed files could not be re-sealed; '
			. 'the old key generation must not be retired.');
	}
});

// No onWipe callback: a Private file keeps no in-window plaintext working copy —
// every read streams from the container and nothing is cached. When content
// search lands (specs/drive_content_search.md) its sealed FTS working copy WILL
// need one; that is the moment to add it here.
?>
