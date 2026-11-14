<?php
/**
 * VaultGate - an action that needs the vault open, unlocked where it stands.
 *
 * A page that renders a button only an open vault can carry out renders it
 * behind this gate while the vault is locked: the button is there but hidden,
 * and in its place an Unlock button runs the platform's one unlock ceremony
 * (JoineryVaultLock.unlock(), assets/js/vault-lock.js) without leaving the
 * page. On 'joinery:vault-unlocked' every gated button shows and every Unlock
 * button hides; on 'joinery:vault-locked' they swap back. The server still
 * checks the vault when the action arrives: the gate saves a trip, it decides
 * nothing.
 *
 *   echo VaultGate::unlockButton('Unlock your vault', 'to turn send protection on');
 *   echo VaultGate::behind($button_html, $vault_locked);
 *
 * A person with no vault has no ceremony to run, so the Unlock button sends
 * them to /profile/security instead (vault-lock.js is not on their page).
 *
 * @version 1.0
 */
class VaultGate {

	/** The script, written once per page by the first gate that needs it. */
	private static $script_written = false;

	/**
	 * An Unlock button that opens the vault in place, shown while it is locked.
	 */
	public static function unlockButton(string $label, string $reason = '', string $class = 'btn btn-sm btn-primary'): string {
		// The marker sits on a bare wrapper: a button's own class sets its
		// display, which would win over the hidden attribute.
		$html = '<span data-vault-locked><button type="button" class="' . htmlspecialchars($class, ENT_QUOTES)
			. '" data-vault-unlock'
			. ($reason !== '' ? ' data-vault-reason="' . htmlspecialchars($reason, ENT_QUOTES) . '"' : '')
			. '>' . htmlspecialchars($label) . '</button></span>';
		return $html . self::script();
	}

	/**
	 * $html as given when the vault is open; hidden until it opens otherwise.
	 * $tag is 'span' for an inline button, 'div' for a block.
	 */
	public static function behind(string $html, bool $locked, string $tag = 'span'): string {
		if (!$locked) {
			return $html;
		}
		$tag = $tag === 'div' ? 'div' : 'span';
		return '<' . $tag . ' data-vault-open hidden>' . $html . '</' . $tag . '>' . self::script();
	}

	/** Whatever is shown only while the vault is locked (a warning beside the Unlock button). */
	public static function whileLocked(string $html, bool $locked, string $tag = 'div'): string {
		if (!$locked) {
			return '';
		}
		$tag = $tag === 'span' ? 'span' : 'div';
		return '<' . $tag . ' data-vault-locked>' . $html . '</' . $tag . '>' . self::script();
	}

	private static function script(): string {
		if (self::$script_written) {
			return '';
		}
		self::$script_written = true;
		$path = 'assets/js/vault-gate.js';
		$v = @filemtime(PathHelper::getIncludePath($path)) ?: 1;
		return '<script defer src="/' . $path . '?v=' . intval($v) . '"></script>';
	}
}
