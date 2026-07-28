<?php
/**
 * MailArchiveReaderRegistry - which reader claims a file.
 *
 * The inventory of supported formats as data. Adding a format is one new class
 * and one line in READERS; nothing else in the importer knows how many formats
 * there are or what they are called.
 *
 * ORDER IS THE PRIORITY. Readers are asked in turn and the first to claim the
 * file wins, so the list runs from the most specific sniff to the loosest:
 *
 *   1. Outlook   - refuses by magic bytes, and must be asked before anything
 *                  guesses, so the user gets the real reason rather than
 *                  "unrecognised file"
 *   2. Zip, Tar  - containers, identified by magic bytes
 *   3. Directory - only reachable once a container has been expanded
 *   4. Mbox      - a From_ line in the first column, or the extension
 *   5. Eml       - last, because "does this look like headers" is the loosest
 *                  question any reader asks and would otherwise swallow the rest
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReader.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/OutlookReader.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/ZipReader.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/TarReader.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/EmlDirectoryReader.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MboxReader.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/EmlFileReader.php'));

class MailArchiveReaderRegistry {

	/** @var string[] Reader class names, most specific sniff first. */
	const READERS = array(
		'OutlookReader',
		'ZipReader',
		'TarReader',
		'EmlDirectoryReader',
		'MboxReader',
		'EmlFileReader',
	);

	/**
	 * The reader that claims this file, or null if nothing does.
	 *
	 * $filename is the name the user gave the file, which is often the only place
	 * the format is written down — a Drive-stored blob has an opaque name on disk.
	 */
	public static function detect(string $path, string $filename): ?MailArchiveReader {
		foreach (self::READERS as $class) {
			try {
				if ($class::sniff($path, $filename)) {
					return new $class();
				}
			} catch (Throwable $e) {
				// A reader that throws while sniffing has not claimed the file; the
				// next one still gets its turn.
				error_log('MailArchiveReaderRegistry: ' . $class . ' failed to sniff ' . $filename . ' — ' . $e->getMessage());
			}
		}
		return null;
	}

	/** Rebuild a reader from the key stored on a run. */
	public static function fromKey(string $key): ?MailArchiveReader {
		foreach (self::READERS as $class) {
			if ($class::key() === $key) {
				return new $class();
			}
		}
		return null;
	}

	/** Every format's key and label — what the upload form tells the user it takes. */
	public static function catalog(): array {
		$out = array();
		foreach (self::READERS as $class) {
			$out[$class::key()] = $class::label();
		}
		return $out;
	}

	/**
	 * The file extensions worth offering in an upload picker. Not a validation
	 * list — detection is by content, and an oddly-named export still works.
	 */
	public static function acceptedExtensions(): array {
		return array('.zip', '.tar', '.tar.gz', '.tgz', '.mbox', '.mbx', '.eml', '.emlx');
	}
}
?>
