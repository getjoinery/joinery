<?php
/**
 * EmlFileReader - one saved message on its own.
 *
 * The degenerate case, and worth having as a real reader rather than an edge in
 * somebody else's: forwarding a single .eml into a mailbox is a genuine thing
 * people do, and it goes through exactly the same run, report and undo as a
 * hundred-thousand-message archive.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReader.php'));

class EmlFileReader extends MailArchiveReader {

	public static function key(): string { return 'eml'; }
	public static function label(): string { return 'Single saved message'; }

	public static function sniff(string $path, string $filename): bool {
		if (!is_file($path)) {
			return false;
		}
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (in_array($ext, array('eml', 'emlx'), true)) {
			return true;
		}
		// An extensionless file that opens with plausible headers is a saved
		// message; anything else is not this reader's problem.
		$handle = @fopen($path, 'rb');
		if (!$handle) {
			return false;
		}
		$head = (string)fread($handle, 2048);
		fclose($handle);
		$headers = self::parseHeaders(self::headerBlock(self::stripEmlx($head)));
		return self::header($headers, 'from') !== '' || self::header($headers, 'message-id') !== '';
	}

	public function scan(string $path, callable $emit, array $state, float $deadline): array {
		if (!empty($state['done'])) {
			return $state;
		}
		$raw = self::stripEmlx((string)@file_get_contents($path));
		$headers = self::parseHeaders(self::headerBlock($raw));
		$filename = (string)($state['filename'] ?? basename($path));

		$meta = self::header($headers, 'x-gmail-labels') !== '' ? self::gmailLabels($headers) : null;
		if ($meta === null) {
			$flags = self::maildirFlags($filename);
			$meta = array('labels' => array(), 'is_read' => $flags['read'],
				'is_starred' => $flags['starred'],
				'class' => $flags['trashed'] ? 'trash' : 'normal', 'folder' => null);
		}

		$emit(self::describe('f|', 0, 'Imported', $headers, $meta));
		return array('done' => true, 'ordinal' => 1, 'filename' => $filename);
	}

	public function read(string $path, string $locator): string {
		$raw = @file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The message could not be read.');
		}
		return self::stripEmlx($raw);
	}
}
?>
