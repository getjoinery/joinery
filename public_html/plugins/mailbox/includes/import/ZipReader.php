<?php
/**
 * ZipReader - a .zip wrapped around any of the other shapes.
 *
 * This is the format most users actually arrive with, because a folder cannot be
 * uploaded: a Proton export gets zipped, a Gmail Takeout is delivered zipped, a
 * maildir gets zipped.
 *
 * Saved messages are read STRAIGHT OUT of the zip through the zip:// stream
 * wrapper — a 50GB archive of small .eml files is never expanded, which would
 * otherwise double the disk this feature needs.
 *
 * An mbox member is the one exception. Splitting an mbox means seeking around
 * inside it, and zip streams cannot seek, so an mbox member is expanded into the
 * run's working area once and read from there. In practice that is the Takeout
 * case — a zip holding one large mbox — and there is no way to avoid the copy.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveTreeReader.php'));

class ZipReader extends MailArchiveTreeReader {

	/** Cached member list — a scan pass asks for it once. */
	private $members = null;

	public static function key(): string { return 'zip'; }
	public static function label(): string { return 'Zip archive'; }

	public static function sniff(string $path, string $filename): bool {
		if (!is_file($path)) {
			return false;
		}
		$handle = @fopen($path, 'rb');
		if (!$handle) {
			return false;
		}
		$magic = (string)fread($handle, 4);
		fclose($handle);
		// PK\003\004 for a normal archive, PK\005\006 for an empty one.
		return strncmp($magic, "PK\x03\x04", 4) === 0 || strncmp($magic, "PK\x05\x06", 4) === 0;
	}

	protected function listMembers(string $path): array {
		if ($this->members !== null) {
			return $this->members;
		}
		$zip = new ZipArchive();
		if ($zip->open($path) !== true) {
			throw new RuntimeException('The zip archive could not be opened.');
		}
		$members = array();
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if ($stat === false) {
				continue;
			}
			$name = (string)$stat['name'];
			if ($name === '' || substr($name, -1) === '/') {
				continue; // directory entry
			}
			$members[] = $name;
		}
		$zip->close();
		sort($members, SORT_STRING);
		$this->members = $members;
		return $members;
	}

	protected function readMember(string $path, string $member, ?int $maxBytes = null): string {
		$handle = @fopen('zip://' . $path . '#' . $member, 'rb');
		if (!$handle) {
			throw new RuntimeException('The archive member could not be opened.');
		}
		try {
			if ($maxBytes !== null) {
				return (string)fread($handle, $maxBytes);
			}
			$bytes = stream_get_contents($handle);
			return $bytes === false ? '' : $bytes;
		} finally {
			fclose($handle);
		}
	}

	protected function hasMember(string $path, string $member): bool {
		return in_array($member, $this->listMembers($path), true);
	}

	/**
	 * Expand one member into the run's working area so it can be seeked, reusing
	 * the copy on every later pass. Only mbox members ever get here.
	 */
	protected function seekablePath(string $path, string $member): string {
		if ($this->work_dir === '') {
			throw new RuntimeException('No working area was prepared for this archive.');
		}
		$local = $this->work_dir . '/' . sha1($member) . '.mbox';
		if (is_file($local) && filesize($local) > 0) {
			return $local;
		}
		if (!is_dir($this->work_dir)) {
			@mkdir($this->work_dir, 0777, true);
		}
		$in = @fopen('zip://' . $path . '#' . $member, 'rb');
		if (!$in) {
			throw new RuntimeException('The archive member could not be opened.');
		}
		$out = @fopen($local, 'wb');
		if (!$out) {
			fclose($in);
			throw new RuntimeException('The working area could not be written to.');
		}
		try {
			stream_copy_to_stream($in, $out);
		} finally {
			fclose($in);
			fclose($out);
		}
		@chmod($local, 0666);
		return $local;
	}
}
?>
