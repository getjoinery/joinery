<?php
/**
 * DriveZip — one Drive folder, with everything under it, as a zip download.
 *
 * The server builds it for Standard and Private trees and streams it as it goes
 * (ZipStreamWriter), so nothing is written to disk and a Private file's
 * plaintext exists only in the request sending it. A Fortress tree is the
 * browser's to build: its bytes are ciphertext the server cannot open
 * (assets/js/drive.js with assets/js/zip-writer.js). docs/drive.md § Downloading
 * a folder.
 *
 * plan() resolves everything that can fail before the first byte goes out —
 * read access, the owner's unlock window, every sealed file's key — so a
 * refusal is a clean status code rather than a short download. stream() then
 * writes the tree depth first: each folder, its files by name, its subfolders.
 *
 * Names inside the zip are the Drive names. A name that would not extract where
 * it says (a slash, a control character, '.' or '..') is cleaned, and two
 * siblings whose names differ only in case get ' (2)' on the second, because a
 * Mac or Windows disk would otherwise write one over the other.
 *
 * @version 1.1 - the missing-files note takes a name no real file in the top folder has
 * @version 1.0
 */
class DriveZip {

	/** The note left in the zip's top folder when some files' bytes could not be read. */
	const MISSING_NOTE = 'Files not included.txt';

	const CHUNK = 1048576;

	/**
	 * Everything the zip will hold, in the order it is written.
	 *
	 * @return array{name:string, entries:array, owner_id:int, level:string}
	 * @throws VaultLockedException when the tree is Private (or holds a sealed
	 *         file) and the owner's unlock window is shut
	 * @throws InvalidArgumentException for a Fortress tree, which the server cannot open
	 */
	public static function plan(Folder $root): array {
		require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
		$level = DriveHelper::folder_level($root);
		if ($level === ProtectionLevel::FORTRESS) {
			throw new InvalidArgumentException('A Fortress folder is zipped in the browser.');
		}
		$owner_id = (int)$root->get('fol_usr_user_id');
		$dblink = DbConnector::get_instance()->get_db_link();

		// The tree: every live folder under the root. A trashed folder takes its
		// subtree with it, the way every listing treats it.
		$ids = array_merge(array((int)$root->key), DriveHelper::descendant_folder_ids($root->key));
		$q = $dblink->query("SELECT fol_folder_id, fol_name, fol_parent_folder_id, fol_create_time
			FROM fol_folders WHERE fol_folder_id IN (" . DriveHelper::int_in_list($ids) . ") AND fol_delete_time IS NULL");
		$folders = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$folders[(int)$r['fol_folder_id']] = $r;
		}
		$children = array();
		foreach ($folders as $id => $r) {
			if ($id !== (int)$root->key) {
				$children[(int)$r['fol_parent_folder_id']][] = $id;
			}
		}

		// Its files: the folder owner's live Drive files (a tree has one owner,
		// so a grantee's zip is the same as the owner's).
		$q = $dblink->prepare("SELECT fil_file_id, fil_fol_folder_id FROM fil_files
			WHERE fil_fol_folder_id IN (" . DriveHelper::int_in_list(array_keys($folders)) . ")
			  AND fil_delete_time IS NULL AND fil_source = ? AND fil_usr_user_id = ?");
		$q->execute(array(File::SOURCE_DRIVE, $owner_id));
		$files_in = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$file = new File((int)$r['fil_file_id'], true);
			if ($file->key) {
				$files_in[(int)$r['fil_fol_folder_id']][] = $file;
			}
		}

		// Private is opened only inside the owner's window (P3). The tree's
		// level decides, and so does any sealed file in it (a level change still
		// converging leaves both kinds side by side).
		$needs_window = ($level === ProtectionLevel::PRIVATE_);
		foreach ($files_in as $list) {
			foreach ($list as $file) {
				$needs_window = $needs_window || $file->is_sealed();
			}
		}
		if ($needs_window) {
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			if (!VaultUnlock::isOpen($owner_id)) {
				throw new VaultLockedException();
			}
		}

		$root_name = ZipStreamWriter::cleanName((string)$root->get('fol_name'));
		$entries = array();
		$note_path = null;
		self::walk((int)$root->key, $root_name, $folders, $children, $files_in, $entries, $note_path);

		return array(
			'name'     => $root_name,
			'entries'  => $entries,
			'note_path' => $note_path,
			'owner_id' => $owner_id,
			'level'    => $level,
		);
	}

	/** The download's file name. */
	public static function filename(array $plan): string {
		return $plan['name'] . '.zip';
	}

	/**
	 * Write the planned zip to $sink.
	 *
	 * @param array $options passed to ZipStreamWriter
	 * @return array{folders:int, files:int, missing:string[]}
	 */
	public static function stream(array $plan, callable $sink, array $options = array()): array {
		$zip = new ZipStreamWriter($sink, $options);
		$folders = 0;
		$files = 0;
		$missing = array();
		foreach ($plan['entries'] as $e) {
			if ($e['type'] === 'folder') {
				$zip->addDirectory($e['path'] . '/', $e['mtime']);
				$folders++;
				continue;
			}
			if (self::writeFile($zip, $e)) {
				$files++;
			} else {
				$missing[] = $e['path'];
			}
		}
		if ($missing) {
			$zip->addFile($plan['note_path'],
				"These files are in the folder but their contents could not be read, so they are not in this zip:\n\n"
				. implode("\n", $missing) . "\n", time(), true);
		}
		$zip->finish();
		return array('folders' => $folders, 'files' => $files, 'missing' => $missing);
	}

	// ------------------------------------------------------------------

	private static function walk(int $id, string $path, array $folders, array $children, array $files_in, array &$entries, ?string &$note_path): void {
		$entries[] = array('type' => 'folder', 'path' => $path, 'mtime' => self::ts($folders[$id]['fol_create_time']));

		$files = $files_in[$id] ?? array();
		usort($files, function ($a, $b) { return strcmp((string)$a->get('fil_title'), (string)$b->get('fil_title')); });
		$subs = $children[$id] ?? array();
		usort($subs, function ($a, $b) use ($folders) { return strcmp((string)$folders[$a]['fol_name'], (string)$folders[$b]['fol_name']); });

		// One namespace per folder, folders first, as Drive lists them.
		$taken = array();
		$sub_paths = array();
		foreach ($subs as $sid) {
			$sub_paths[$sid] = $path . '/' . self::unique(ZipStreamWriter::cleanName((string)$folders[$sid]['fol_name']), $taken);
		}
		foreach ($files as $file) {
			$name = self::unique(ZipStreamWriter::cleanName((string)$file->get_name()), $taken);
			$sealed = $file->is_sealed();
			$entries[] = array(
				'type'     => 'file',
				'path'     => $path . '/' . $name,
				'file'     => $file,
				'sealed'   => $sealed,
				'streamer' => $sealed ? self::prepareSealed($file) : null,
				'size'     => $sealed ? $file->plain_size_bytes() : $file->size_bytes(),
				'compress' => ZipStreamWriter::compressible($name, (string)$file->get('fil_type')),
				'mtime'    => self::ts($file->get('fil_content_modified_time') ?: $file->get('fil_create_time')),
			);
		}
		if ($note_path === null) {
			// The top folder: the note's name is claimed here, after every real
			// name, so a file already called that keeps its own.
			$note_path = $path . '/' . self::unique(self::MISSING_NOTE, $taken);
		}
		foreach ($subs as $sid) {
			self::walk($sid, $sub_paths[$sid], $folders, $children, $files_in, $entries, $note_path);
		}
	}

	/** The sealed file's key, resolved now, inside the window (a closed one throws). */
	private static function prepareSealed(File $file): DriveSealedStream {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		VaultUnlock::loadConsumerBootstraps();
		$streamer = new DriveSealedStream($file, 'original');
		$streamer->prepare('');
		return $streamer;
	}

	/** $name, or $name with ' (2)', ' (3)'… before its extension if a sibling has it in any case. */
	private static function unique(string $name, array &$taken): string {
		$candidate = $name;
		$n = 1;
		while (isset($taken[mb_strtolower($candidate)])) {
			$n++;
			$dot = strrpos($name, '.');
			$candidate = ($dot !== false && $dot > 0)
				? substr($name, 0, $dot) . ' (' . $n . ')' . substr($name, $dot)
				: $name . ' (' . $n . ')';
		}
		$taken[mb_strtolower($candidate)] = true;
		return $candidate;
	}

	/**
	 * One file's bytes into the zip. Everything that can fail before its header
	 * is written does fail there, and the file is left out (false). A failure
	 * after its header is a broken archive, so it is thrown.
	 */
	private static function writeFile(ZipStreamWriter $zip, array $e): bool {
		$file = $e['file'];
		$temp = null;
		$path = $file->get_filesystem_path('original');
		if ($path === '' || $path === null || !is_file($path)) {
			$path = null;
			if ($file->storage_driver() === 'cloud' && ($driver = $file->cloud_driver())) {
				$temp = tempnam(sys_get_temp_dir(), 'drive_zip_');
				try {
					$driver->get($file->remote_key_for('original'), $temp);
					$path = $temp;
				} catch (Exception $ex) {
					error_log('DriveZip: cloud GET failed for fil=' . (int)$file->key . ': ' . $ex->getMessage());
				}
			}
		}
		try {
			if ($path === null) {
				return false;
			}
			if ($e['sealed']) {
				try {
					DriveSealed::assertContainerIsWhole($file, $path, 'original');
				} catch (DriveSealedException $ex) {
					error_log('DriveZip: ' . $ex->getMessage());
					return false;
				}
				$zip->beginFile($e['path'], $e['mtime'], $e['compress'], $e['size']);
				$e['streamer']->stream($path, function ($piece) use ($zip) { $zip->write($piece); });
				$zip->endFile();
				return true;
			}
			$fh = @fopen($path, 'rb');
			if ($fh === false) {
				return false;
			}
			try {
				$zip->beginFile($e['path'], $e['mtime'], $e['compress'], $e['size']);
				while (!feof($fh)) {
					$chunk = fread($fh, self::CHUNK);
					if ($chunk === false) {
						throw new RuntimeException('DriveZip: read failed for fil=' . (int)$file->key);
					}
					$zip->write($chunk);
				}
				$zip->endFile();
			} finally {
				fclose($fh);
			}
			return true;
		} finally {
			if ($temp !== null) {
				@unlink($temp);
			}
		}
	}

	/** A stored UTC timestamp as a Unix time. */
	private static function ts($value): int {
		$t = $value ? strtotime($value . ' UTC') : false;
		return $t !== false ? $t : time();
	}
}
