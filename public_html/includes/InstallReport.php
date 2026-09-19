<?php
/**
 * InstallReport — what the installer did with the services it was handed,
 * read back for the setup wizard.
 *
 * A first-boot install that received a sending key, a backup bucket or a DNS
 * credential records each outcome in config/install_services.txt (one
 * key=value line: mail=, backup=, dns_credential=; values start done:,
 * failed: or skipped:). The installer prints the same summary at the end of
 * its run — but on the Linode StackScript path nobody sees that output: the
 * deploy form redirects to the instance page and the log stays on the box.
 * The wizard's first screen is the first place the owner actually looks, so
 * it reads the file and shows the summary there.
 *
 * The file carries no secrets (the tools print theirs nowhere), which is what
 * lets it be web-readable.
 *
 * @version 1.0
 */
class InstallReport {

	/** The outcomes the installer records, in the order the wizard shows them. */
	const KEYS = array(
		'mail'           => 'Email',
		'backup'         => 'Backups',
		'dns_credential' => 'DNS credential',
	);

	/** The file the installer writes, relative to the site root. */
	const FILE = 'config/install_services.txt';

	/**
	 * Each recorded outcome as ['label' => 'Email', 'outcome' => 'done'|'partial'|'failed'|'skipped', 'text' => '...'],
	 * keyed by the installer's own key; empty when nothing was recorded or
	 * the file is not readable here. $path names another file (tests).
	 *
	 * @return array<string,array{label:string,outcome:string,text:string}>
	 */
	public static function outcomes(?string $path = null): array {
		$path = $path ?? PathHelper::getSiteRoot() . '/' . self::FILE;
		if (!is_readable($path)) {
			return array();
		}
		$raw = @file_get_contents($path);
		if (!is_string($raw) || trim($raw) === '') {
			return array();
		}
		$found = array();
		foreach (preg_split('/\r?\n/', $raw) as $line) {
			$line = trim($line);
			if ($line === '' || strpos($line, '=') === false) {
				continue;
			}
			list($key, $value) = explode('=', $line, 2);
			$key = trim($key);
			if (!isset(self::KEYS[$key]) || isset($found[$key])) {
				continue;
			}
			$found[$key] = self::parse($key, trim($value));
		}
		// The installer's order is incidental; the wizard's is the reader's.
		$ordered = array();
		foreach (self::KEYS as $key => $label) {
			if (isset($found[$key])) {
				$ordered[$key] = $found[$key];
			}
		}
		return $ordered;
	}

	/** True when at least one outcome was recorded. */
	public static function exists(): bool {
		return self::outcomes() !== array();
	}

	/**
	 * One "done: sending as x; DNS published: 3 records" value into its
	 * verdict and its text. A value without a recognised prefix is shown as
	 * recorded, graded 'failed' — an outcome the installer could not classify
	 * is not one to paint green. A 'done' whose own text reports a part that
	 * did not happen ("DNS not published: …", "could not be registered") is
	 * 'partial': the provider was written, but the owner still has something
	 * to do, and green would say otherwise.
	 */
	private static function parse(string $key, string $value): array {
		$outcome = 'failed';
		$text = $value;
		if (preg_match('/^(done|failed|skipped):\s*(.*)$/s', $value, $m)) {
			$outcome = $m[1];
			$text = trim($m[2]);
		}
		if ($outcome === 'done' && preg_match('/\b(not published|could not|failed|error|did not answer)\b/i', $text)) {
			$outcome = 'partial';
		}
		return array('label' => self::KEYS[$key], 'outcome' => $outcome, 'text' => $text);
	}

	/** The wizard's dot colour for an outcome. */
	public static function dot(string $outcome): string {
		switch ($outcome) {
			case 'done':    return 'green';
			case 'partial':
			case 'skipped': return 'amber';
			default:        return 'none';
		}
	}
}
