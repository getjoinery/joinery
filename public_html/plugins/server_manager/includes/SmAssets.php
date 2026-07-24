<?php
/**
 * SmAssets — emit the shared server_manager admin JS asset.
 *
 * The three helpers in assets/js/server_manager.js (smApiPost / smEsc /
 * smSafeUrl) are used only by the plugin's superadmin pages, so they load
 * per-page rather than globally. This centralises the one <script> tag (with an
 * mtime cache-buster) so the four consumers don't each re-derive it.
 *
 * @version 1.1 - script_tag() takes a filename, so pages can pull a second plugin asset
 * @version 1.0
 */
class SmAssets {
	/** @return string The <script> tag for a plugin JS asset, cache-busted by mtime. */
	public static function script_tag(string $file = 'server_manager.js'): string {
		$rel = 'plugins/server_manager/assets/js/' . basename($file);
		$full = PathHelper::getIncludePath($rel);
		$v = (is_string($full) && is_file($full)) ? filemtime($full) : '1';
		return '<script src="/' . $rel . '?v=' . $v . '"></script>' . "\n";
	}
}
