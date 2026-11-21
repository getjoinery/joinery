<?php
/**
 * sandbox_runtime_files.php - files inside a sandboxed plugin's fence
 * (spec sandboxed_plugin_uploads). Loaded by utils/sandbox_runtime.php.
 *
 * The plugin writes file code as native code does: $_FILES on a posted page,
 * File::createFromUpload(), File::createFromBytes(), new File($id, TRUE),
 * MultiFile, read_bytes(), set() and save(), soft_delete(), get_url(). File
 * is core's own class here too. File::$remote is set to this, so making a
 * file and reading its bytes are asks to the site, which runs them with the
 * same class; the row itself is read and written as every model is
 * (SystemBase::$remote_store). The site allows it only when the plugin was
 * approved to take uploads (SandboxRelay, file.*).
 *
 * An upload's tmp_name is the site's own temporary path: the fence cannot
 * read it, and the site takes it back only when it is one of the uploads of
 * the request this call is drawing. Bytes cross the pipe base64, in parts of
 * PART_BYTES so each fits in one message; a file may be up to half the call's
 * memory either way (the site says so when one is larger).
 *
 * @version 1.1 - bytes in parts, both ways (owner 2026-11-21)
 * @version 1.0
 */

final class SandboxRemoteFiles implements FileRemote {

	/** One part's bytes (SandboxRelay::FILE_PART_BYTES). */
	const PART_BYTES = 1048576;

	public function createFromUpload(string $src_path, string $display_name, string $content_type, int $owner_id, array $restrictions): array {
		return self::row(self::ask('file.upload', array('tmp_name' => $src_path, 'name' => $display_name, 'type' => $content_type,
			'owner_id' => $owner_id, 'restrictions' => (object)$restrictions)));
	}

	public function createFromBytes(string $bytes, string $filename, string $content_type, int $owner_id, array $restrictions): array {
		$length = strlen($bytes);
		for ($at = 0; $at + self::PART_BYTES < $length; $at += self::PART_BYTES) {
			self::ask('file.bytes', array('part' => base64_encode(substr($bytes, $at, self::PART_BYTES)), 'more' => true));
		}
		return self::row(self::ask('file.bytes', array('part' => base64_encode((string)substr($bytes, $at)), 'more' => false,
			'name' => $filename, 'type' => $content_type, 'owner_id' => $owner_id, 'restrictions' => (object)$restrictions)));
	}

	public function readBytes(int $file_id): ?string {
		$bytes = '';
		$offset = 0;
		do {
			$answer = self::ask('file.read', array('id' => $file_id, 'offset' => $offset));
			$part = is_string($answer['bytes'] ?? null) ? base64_decode($answer['bytes'], true) : false;
			if ($part === false) {
				return null;
			}
			$bytes .= $part;
			$offset = $answer['next'] ?? null;
		} while (is_int($offset) && $offset === strlen($bytes));
		return $bytes;
	}

	/** One ask; what the site refused throws FileException, as the site's own File throws. */
	private static function ask(string $ask, array $args): array {
		try {
			return SandboxPipe::ask($ask, $args);
		} catch (SandboxPipeException $e) {
			throw new FileException($e->getMessage(), $e->getCode());
		}
	}

	private static function row(array $answer): array {
		return is_array($answer['record'] ?? null) ? $answer['record'] : array();
	}
}
