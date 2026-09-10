<?php
/**
 * ParserJailNotice — the admin-header notice on a node whose parser jail is
 * not installed (specs/parser_jail.md).
 *
 * Attachments, deliverability reports, received HTML and the AI's fetched
 * pages are parsed by C libraries in a subprocess. With the launcher in
 * place that subprocess is the joinery-jail user, which holds nothing; without
 * it, it is the web user, which holds the database password, the config file
 * and every open vault window. The notice names the fact and the one command
 * that changes it, to the admin who can run it. Information, never a gate.
 *
 * Reads one stored fact: whether the launcher is installed as the installer
 * leaves it (DocumentText::jailAvailable(), a single stat). Silent once it is.
 *
 * @version 1.0
 */
class ParserJailNotice {

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		if (DocumentText::jailAvailable()) {
			return '';
		}
		return self::forState(VaultHealth::parserJailInstallCommand());
	}

	/** The notice for a missing launcher. Public and pure so the wording can be tested. */
	public static function forState(string $command): string {
		$lead = 'Strangers\' bytes are parsed as the web user on this node.';
		$body = 'The parser jail is not installed, so a bug in a document, report or page parser runs as the '
			. 'user that holds the database and every open vault window. Run this on the host as root:';
		return self::css()
			. '<div class="jy-jail-notice" role="status">'
			. '<div class="jy-jail-notice__text"><strong>' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</strong> '
			. htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
			. ' <code class="jy-jail-notice__cmd">' . htmlspecialchars($command, ENT_QUOTES, 'UTF-8') . '</code></div>'
			. '</div>';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-jail-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;background:#fffbeb;color:#78350f;font-size:.95rem}'
			. '.jy-jail-notice__cmd{display:inline-block;margin-top:.25rem;padding:.15rem .4rem;border-radius:4px;background:#fef3c7;font-size:.9em;user-select:all;overflow-wrap:anywhere}'
			. '</style>';
	}
}
