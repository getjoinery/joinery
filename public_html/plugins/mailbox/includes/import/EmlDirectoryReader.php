<?php
/**
 * EmlDirectoryReader - a folder of saved messages on disk.
 *
 * This is what a Proton export looks like (a directory named for the address,
 * full of .eml files with .metadata.json sidecars), what a maildir looks like,
 * and what Apple Mail's Messages/ directory looks like. It is also what a tar
 * becomes once expanded, so TarReader is this reader with an extraction step in
 * front of it.
 *
 * A user cannot upload a directory, so this reader is reached through a container
 * rather than chosen directly — but it is the one that understands the shape.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveTreeReader.php'));

class EmlDirectoryReader extends MailArchiveTreeReader {

	public static function key(): string { return 'eml_dir'; }
	public static function label(): string { return 'Folder of saved messages'; }

	public static function sniff(string $path, string $filename): bool {
		return is_dir($path);
	}

	protected function listMembers(string $path): array {
		$members = array();
		$root = rtrim($path, '/');
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ($iterator as $file) {
			if (!$file->isFile()) {
				continue;
			}
			$relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
			if ($relative !== '') {
				$members[] = $relative;
			}
		}
		// A stable order is what makes resuming an index into this list safe.
		sort($members, SORT_STRING);
		return $members;
	}

	protected function readMember(string $path, string $member, ?int $maxBytes = null): string {
		$full = $this->resolve($path, $member);
		if ($maxBytes !== null) {
			$handle = @fopen($full, 'rb');
			if (!$handle) {
				throw new RuntimeException('The file could not be opened.');
			}
			try {
				return (string)fread($handle, $maxBytes);
			} finally {
				fclose($handle);
			}
		}
		$bytes = @file_get_contents($full);
		if ($bytes === false) {
			throw new RuntimeException('The file could not be read.');
		}
		return $bytes;
	}

	protected function hasMember(string $path, string $member): bool {
		try {
			return is_file($this->resolve($path, $member));
		} catch (Throwable $e) {
			return false;
		}
	}

	protected function seekablePath(string $path, string $member): string {
		return $this->resolve($path, $member);
	}

	/**
	 * Join a member path onto the root and refuse anything that climbs out of it.
	 * Locators are ours, but they make a round trip through the database, and a
	 * path that escapes the archive would read arbitrary files off the server.
	 */
	private function resolve(string $path, string $member): string {
		$root = rtrim($path, '/');
		$full = $root . '/' . ltrim(str_replace('\\', '/', $member), '/');
		if (strpos($member, '..') !== false) {
			throw new RuntimeException('That position is not inside the archive.');
		}
		return $full;
	}
}
?>
