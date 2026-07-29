<?php
/**
 * MboxReader - a single mbox file: Gmail Takeout, Thunderbird, Apple Mail.
 *
 * The whole archive is one file, so there is nothing to extract and nothing to
 * list: MboxSplitter walks it and every message is addressed by where it sits.
 * A locator is "offset:length".
 *
 * Folder comes from Gmail's X-Gmail-Labels when the export carries it (Takeout
 * does), and otherwise from the file's own name — "Archived.mbox" is a folder
 * called Archived, which is exactly what Thunderbird means by it.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReader.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MboxSplitter.php'));

class MboxReader extends MailArchiveReader {

	public static function key(): string { return 'mbox'; }
	public static function label(): string { return 'Mailbox file (mbox)'; }

	public static function sniff(string $path, string $filename): bool {
		if (!is_file($path)) {
			return false;
		}
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (in_array($ext, array('mbox', 'mbx'), true)) {
			return true;
		}
		// An extensionless or oddly-named export is still an mbox if it opens like
		// one. Nothing else in scope starts a file with a From_ line.
		$handle = @fopen($path, 'rb');
		if (!$handle) {
			return false;
		}
		$head = (string)fread($handle, 5);
		fclose($handle);
		return $head === 'From ';
	}

	public function scan(string $path, callable $emit, array $state, float $deadline): array {
		$offset = intval($state['offset'] ?? 0);
		$ordinal = intval($state['ordinal'] ?? 0);
		$folder = self::folderFromFilename($state['filename'] ?? $this->sourceName($path));

		$handle = fopen($path, 'rb');
		if (!$handle) {
			throw new RuntimeException('The archive could not be opened for reading.');
		}
		try {
			$result = MboxSplitter::split($handle, $offset,
				function (array $msg) use ($emit, &$ordinal, $folder) {
					$emit(self::describe(
						$msg['offset'] . ':' . $msg['length'],
						$ordinal++,
						$folder,
						$msg['headers']
					));
				},
				$deadline,
				intval($state['limit'] ?? 20000)
			);
		} finally {
			fclose($handle);
		}

		return array(
			'offset'   => $result['next'] ?? $offset,
			'ordinal'  => $ordinal,
			'filename' => $state['filename'] ?? $this->sourceName($path),
			'done'     => $result['next'] === null,
		);
	}

	public function read(string $path, string $locator): string {
		list($offset, $length) = self::parseLocator($locator);
		$handle = fopen($path, 'rb');
		if (!$handle) {
			throw new RuntimeException('The archive could not be opened for reading.');
		}
		try {
			return MboxSplitter::readMessage($handle, $offset, $length);
		} finally {
			fclose($handle);
		}
	}

	/** "offset:length" — the only locator shape this reader issues. */
	public static function parseLocator(string $locator): array {
		$parts = explode(':', $locator);
		if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
			throw new RuntimeException('Unreadable position in the archive.');
		}
		return array(intval($parts[0]), intval($parts[1]));
	}

	/**
	 * "All mail Including Spam and Trash.mbox" is Takeout's everything-file and
	 * names no folder; anything else names the folder it holds.
	 */
	public static function folderFromFilename(string $filename): string {
		$name = pathinfo($filename, PATHINFO_FILENAME);
		$name = trim(str_replace('_', ' ', $name));
		if ($name === '' || stripos($name, 'all mail') === 0) {
			return 'All mail';
		}
		return $name;
	}
}
?>
