<?php
/**
 * SmAssets — emit the shared server_manager admin JS asset.
 *
 * The three helpers in assets/js/server_manager.js (smApiPost / smEsc /
 * smSafeUrl) are used only by the plugin's superadmin pages, so they load
 * per-page rather than globally. This centralises the one <script> tag (with an
 * mtime cache-buster) so the four consumers don't each re-derive it.
 *
 * @version 1.0
 */
class SmAssets {
	/** @return string The <script> tag for the shared helper asset. */
	public static function script_tag(): string {
		$rel = 'plugins/server_manager/assets/js/server_manager.js';
		$full = PathHelper::getIncludePath($rel);
		$v = (is_string($full) && is_file($full)) ? filemtime($full) : '1';
		return '<script src="/' . $rel . '?v=' . $v . '"></script>' . "\n";
	}
}
