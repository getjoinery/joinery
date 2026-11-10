<?php
/**
 * One stylesheet for every active plugin's declared styles.
 *
 * A plugin lists stylesheets under "styles" in its plugin.json and each
 * loads on every page while the plugin is active. Sent one by one, that is a
 * request per sheet on every first visit. This joins them, in the order they
 * are declared, into one file a page links once:
 *
 *   /assets/plugin-styles.css?v={hash}
 *
 * The hash covers every declared sheet's path, modification time and size,
 * so the URL changes when any sheet does, or when a plugin is switched on or
 * off, and a browser may keep the file for good (RouteHelper serves a
 * versioned asset for a year).
 *
 * Each sheet's part of the bundle starts with a comment naming its file, so
 * a rule seen in a browser's developer tools can be traced to its plugin.
 *
 * A sheet is left out of the bundle and linked on its own when joining it
 * would change what it means: one that holds a relative url() (it would
 * resolve against /assets/ instead of the plugin's folder), an @import or an
 * @charset (both must come first in a file), or one that is not a .css file
 * inside its plugin's folder.
 *
 * Built files live in {site root}/cache/plugin_styles/: {hash}.css, and
 * {hash}.json naming which sheets the bundle holds and which are linked
 * separately. They are data, read and sent, never executed. Where the
 * directory cannot be written every sheet is linked on its own.
 *
 * @version 1.0
 */
final class PluginStyleBundle {

	/** The URL every page links; RouteHelper answers it with serve(). */
	const URL_PATH = '/assets/plugin-styles.css';

	/** Part of the hash: bump when the bundle's make-up changes for the same sheets. */
	const FORMAT = 1;

	/** Built bundles older than this are removed when a new one is built. */
	const KEEP_SECONDS = 2592000;

	/** Tests only: where bundles are built, null for the site's own directory. */
	private static $directory = null;

	/**
	 * The <link> tags for the active plugins' declared stylesheets: the
	 * bundle, then any sheet that had to stay on its own.
	 */
	public static function links(): string {
		$sheets = self::declaredSheets();
		if (!$sheets) {
			return '';
		}
		$plan = self::plan($sheets);
		$out = '';
		if ($plan['bundled']) {
			$out .= self::tag(self::URL_PATH . '?v=' . $plan['hash']);
		}
		foreach ($sheets as $sheet) {
			if (!in_array($sheet['path'], $plan['bundled'], true)) {
				$out .= self::tag('/' . $sheet['path'] . '?v=' . $sheet['mtime']);
			}
		}
		return $out;
	}

	/**
	 * Send the bundle a page asked for. The file that hash names when it is
	 * here, with no database read. Otherwise the current bundle is built and
	 * sent, kept by the browser for good only when the hash asked for is the
	 * current one. False when there is nothing to send.
	 */
	public static function serve(string $hash): bool {
		if (preg_match('/^[a-f0-9]{32}$/', $hash) === 1) {
			$file = self::directory() . '/' . $hash . '.css';
			if (is_file($file)) {
				return RouteHelper::serveStaticFile($file, 43200, array(), true);
			}
		}
		$sheets = self::declaredSheets();
		if (!$sheets) {
			return false;
		}
		$plan = self::plan($sheets);
		$file = self::directory() . '/' . $plan['hash'] . '.css';
		if (!$plan['bundled'] || !is_file($file)) {
			return false;
		}
		return RouteHelper::serveStaticFile($file, 43200, array(), $plan['hash'] === $hash);
	}

	/**
	 * Every stylesheet an active plugin declares that exists, in declared
	 * order: ['path' => path under public_html, 'full' => file, 'mtime', 'size'].
	 */
	public static function declaredSheets(): array {
		$sheets = array();
		foreach (PluginHelper::getActivePlugins() as $plugin) {
			$styles = $plugin->get('styles', array());
			if (!is_array($styles)) {
				continue;
			}
			foreach ($styles as $rel) {
				$rel = ltrim((string)$rel, '/');
				if ($rel === '') {
					continue;
				}
				$full = $plugin->getIncludePath($rel);
				if (!is_string($full) || !is_file($full)) {
					continue;
				}
				$sheets[] = array(
					'path'   => $plugin->getBasePath() . '/' . $rel,
					'full'   => $full,
					'root'   => $plugin->getIncludePath(''),
					'mtime'  => (int)filemtime($full),
					'size'   => (int)filesize($full),
				);
			}
		}
		return $sheets;
	}

	/**
	 * Which sheets the bundle for this set holds: ['hash' => string,
	 * 'bundled' => [paths]]. Read from the record beside a built bundle, or
	 * built now. With nothing built and nowhere to build, 'bundled' is empty
	 * and every sheet is linked on its own.
	 */
	public static function plan(array $sheets): array {
		$identity = array(self::FORMAT);
		foreach ($sheets as $sheet) {
			$identity[] = array($sheet['path'], $sheet['mtime'], $sheet['size']);
		}
		$hash = md5((string)json_encode($identity));
		$dir = self::directory();
		$record = $dir . '/' . $hash . '.json';
		if (is_file($record)) {
			$kept = json_decode((string)@file_get_contents($record), true);
			if (is_array($kept) && isset($kept['bundled']) && is_array($kept['bundled'])
					&& (!$kept['bundled'] || is_file($dir . '/' . $hash . '.css'))) {
				return array('hash' => $hash, 'bundled' => array_values(array_filter($kept['bundled'], 'is_string')));
			}
		}
		return array('hash' => $hash, 'bundled' => self::build($sheets, $hash));
	}

	/** Join the sheets that can be joined and write the bundle and its record. The bundled paths. */
	private static function build(array $sheets, string $hash): array {
		$dir = self::directory();
		if (!is_dir($dir)) {
			@mkdir($dir, 0770, true);
			@chmod($dir, 0770);
			$group = @filegroup(dirname($dir));
			if ($group !== false) {
				@chgrp($dir, $group);
			}
		}
		if (!is_dir($dir) || !is_writable($dir)) {
			return array();
		}
		$css = '';
		$bundled = array();
		foreach ($sheets as $sheet) {
			$text = self::joinable($sheet);
			if ($text === null) {
				continue;
			}
			$css .= '/* ' . $sheet['path'] . " */\n" . rtrim($text) . "\n\n";
			$bundled[] = $sheet['path'];
		}
		if ($bundled && !self::write($dir . '/' . $hash . '.css', $css)) {
			return array();
		}
		if (!self::write($dir . '/' . $hash . '.json', (string)json_encode(array('bundled' => $bundled)))) {
			// Without the record the bundle is rebuilt on the next page: still right.
			return $bundled;
		}
		self::removeOld($dir, $hash);
		return $bundled;
	}

	/** The sheet's text when it can be joined into the bundle, null when it must be linked on its own. */
	private static function joinable(array $sheet): ?string {
		if (strtolower(substr($sheet['full'], -4)) !== '.css') {
			return null;
		}
		$real = realpath($sheet['full']);
		$root = realpath($sheet['root']);
		if ($real === false || $root === false || strncmp($real, $root . '/', strlen($root) + 1) !== 0) {
			return null;
		}
		$text = @file_get_contents($real);
		if ($text === false) {
			return null;
		}
		if (preg_match('/@import\b|@charset\b/i', $text)) {
			return null;
		}
		// A url() that is not absolute, a data: or other scheme, or a fragment
		// resolves against the file that holds it.
		if (preg_match('~url\(\s*+["\']?+(?!/|#|[a-z][a-z0-9+.-]*:)~i', $text)) {
			return null;
		}
		// A comment could not be closed by the text before it; a stray one
		// left open would swallow the next plugin's sheet.
		if (substr_count($text, '/*') !== substr_count($text, '*/')) {
			return null;
		}
		return $text;
	}

	private static function directory(): string {
		return self::$directory ?? PathHelper::getSiteRoot() . '/cache/plugin_styles';
	}

	/** Tests: build into $dir, or null for the site's own directory. */
	public static function useDirectory(?string $dir): void {
		self::$directory = $dir === null ? null : rtrim($dir, '/');
	}

	/** Written whole or not at all, readable by the web user and its group. */
	private static function write(string $file, string $content): bool {
		$temp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($temp, $content) === false) {
			@unlink($temp);
			return false;
		}
		@chmod($temp, 0660);
		$group = @filegroup(dirname($file));
		if ($group !== false && @filegroup($temp) !== $group) {
			@chgrp($temp, $group);
		}
		if (!@rename($temp, $file)) {
			@unlink($temp);
			return false;
		}
		return true;
	}

	/** Remove bundles nothing has built for KEEP_SECONDS. A page still naming one gets the current bundle. */
	private static function removeOld(string $dir, string $current): void {
		$before = time() - self::KEEP_SECONDS;
		foreach (glob($dir . '/*.{css,json,tmp}', GLOB_BRACE) ?: array() as $file) {
			if (strpos(basename($file), $current . '.') === 0) {
				continue;
			}
			if ((int)@filemtime($file) < $before) {
				@unlink($file);
			}
		}
	}

	private static function tag(string $url): string {
		return '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . "\n";
	}
}
