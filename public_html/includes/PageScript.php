<?php
/**
 * PageScript - the two ways a page hands the browser code and data under the
 * strict script policy (specs/strict_script_policy.md): a script file loaded
 * by src, and a JSON data block the file reads. Neither is inline script, so
 * a script-src without 'unsafe-inline' runs the one and the browser never
 * runs the other.
 *
 *   echo PageScript::json('security-page-config', array('next' => $url));
 *   echo PageScript::src('assets/js/security-page.js');
 *
 * and in the file:
 *
 *   var cfg = joineryPageData('security-page-config') || {};
 *
 * @version 1.1 - encode(): the same safe text for a block written by hand
 * @version 1.0
 */
class PageScript {

	/**
	 * A script element loading a file in the tree by src, with its mtime as the cache key.
	 *
	 * @param string $rel   path from the site root, e.g. 'assets/js/x.js' or
	 *                      'plugins/mailbox/assets/js/x.js'
	 * @param bool   $defer run after the page is parsed (the default); pass
	 *                      false for a file later markup or a later file needs
	 *                      to have run already
	 */
	public static function src(string $rel, bool $defer = true): string {
		$rel = ltrim($rel, '/');
		$full = PathHelper::getIncludePath($rel);
		$v = (is_string($full) && is_file($full)) ? filemtime($full) : '1';
		return '<script' . ($defer ? ' defer' : '') . ' src="/' . htmlspecialchars($rel, ENT_QUOTES) . '?v=' . $v . '"></script>';
	}

	/**
	 * A JSON data block with an id. The flags make the text safe inside a
	 * script element whatever $data holds: no '<', '>', '&' or quote survives
	 * raw, so nothing in it can close the element.
	 *
	 * @param string $id   the element's id, read with joineryPageData(id)
	 * @param mixed  $data anything json_encode takes
	 */
	public static function json(string $id, $data): string {
		return '<script type="application/json" id="' . htmlspecialchars($id, ENT_QUOTES) . '">'
			. self::encode($data) . '</script>';
	}

	/**
	 * The text for any JSON data block, for one written by hand (a block found
	 * by class, say): no '<', '>', '&' or quote survives raw, so nothing in
	 * $data can close the element it sits in.
	 *
	 * @param mixed $data anything json_encode takes
	 */
	public static function encode($data): string {
		return (string)json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			| JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}
}
