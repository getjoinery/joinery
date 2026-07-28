<?php
/**
 * OutlookReader - .pst and .olm, which this platform refuses to read.
 *
 * It exists so the refusal is SPECIFIC. Without it a .pst falls through every
 * sniff and the user is told "unrecognised file", which is both unhelpful and
 * untrue: the file is recognised perfectly well, it just cannot be read here.
 * Recognising it means the answer can name the format and point at the way that
 * does work — connect the account over IMAP and let the feed pull the mail in
 * live, which is better than an export anyway because it stays in sync.
 *
 * Reading these formats needs an external binary (readpst, libpff). Requiring one
 * would break the zero-config install promise, so it is not on the table.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReader.php'));

class OutlookReader extends MailArchiveReader {

	public static function key(): string { return 'outlook'; }
	public static function label(): string { return 'Outlook data file'; }

	public static function sniff(string $path, string $filename): bool {
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (in_array($ext, array('pst', 'ost', 'olm'), true)) {
			return true;
		}
		if (!is_file($path)) {
			return false;
		}
		$handle = @fopen($path, 'rb');
		if (!$handle) {
			return false;
		}
		$magic = (string)fread($handle, 4);
		fclose($handle);
		// "!BDN" opens every PST and OST ever written.
		return $magic === '!BDN';
	}

	public function refusal(): ?string {
		return 'Outlook data files (.pst and .olm) cannot be read directly. '
			. 'Connect the account as an IMAP feed instead and its mail will be pulled in — '
			. 'which also keeps working as new mail arrives.';
	}

	public function scan(string $path, callable $emit, array $state, float $deadline): array {
		throw new RuntimeException((string)$this->refusal());
	}

	public function read(string $path, string $locator): string {
		throw new RuntimeException((string)$this->refusal());
	}
}
?>
