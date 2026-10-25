<?php
/**
 * Who may open an admin page: the level its header declares
 * (spec declared_permissions 3.4; docs/permissions.md § Pages).
 *
 * An admin page opens with a header naming its class or a level:
 *
 *   /** @joinery-admin class=personal *\/   the class's read level
 *   /** @joinery-admin level=8 *\/         a number, for a page with no class
 *
 * A page without one, or with one that does not parse, needs 10.
 *
 * RouteHelper asks levelForPath() before any page code runs, and the admin
 * menus ask levelForMenuUrl() for each entry, so the sidebar shows what the
 * reader may open and nothing else. An admin page is reached three ways:
 *
 *   /admin/{name}                     adm/{name}.php
 *   /admin/{plugin}/{name}            plugins/{plugin}/views/admin/{name}.php
 *   /plugins/{plugin}/admin/{name}    plugins/{plugin}/admin/{name}.php
 *
 * and a plugin's dynamic route inside /admin/ names its view file. An
 * inactive plugin's pages are not served, so they map to no file.
 *
 * @version 1.1
 */
final class AdminPageAccess {

	/** A page with no header, or a malformed one, needs this. */
	const UNDECLARED_LEVEL = 10;

	/** @var array file => level, for this request */
	private static $levels = array();

	/** Whether a request path is in an admin URL family. */
	public static function isAdminPath(string $path): bool {
		return (bool)preg_match('#^/admin/.#', $path) || (bool)preg_match('#^/plugins/[^/]+/admin(/|$)#', $path);
	}

	/**
	 * The page file (relative to public_html) an admin path serves, or null
	 * when there is none. $view is a dynamic route's view (without .php), when
	 * one matched.
	 */
	public static function fileForPath(string $path, ?string $view = null): ?string {
		$path = rtrim((string)strtok($path, '?'), '/');
		$candidates = array();
		if ($view !== null && $view !== '') {
			$candidates[] = (substr($view, -4) === '.php') ? $view : $view . '.php';
		} elseif (preg_match('#^/plugins/([a-z0-9_]+)/admin(?:/(.*))?$#', $path, $m)) {
			if (self::pluginServes($m[1])) {
				$candidates[] = "plugins/{$m[1]}/admin/" . (($m[2] ?? '') !== '' ? $m[2] : 'index') . '.php';
			}
		} elseif (preg_match('#^/admin/(.+)$#', $path, $m)) {
			$rest = $m[1];
			$candidates[] = "adm/$rest.php";
			if (preg_match('#^([a-z0-9_]+)(?:/(.*))?$#', $rest, $p) && self::pluginServes($p[1])) {
				$candidates[] = "plugins/{$p[1]}/views/admin/" . (($p[2] ?? '') !== '' ? $p[2] : 'index') . '.php';
			}
		}
		foreach ($candidates as $rel) {
			if (strpos($rel, '..') !== false) continue;
			if (is_file(PathHelper::getIncludePath($rel))) {
				return $rel;
			}
		}
		return null;
	}

	/**
	 * Whether a plugin's pages are served: an inactive plugin's 404 like every
	 * other /plugins/* path, so its pages have no level to ask.
	 */
	private static function pluginServes(string $plugin): bool {
		return class_exists('PluginHelper') && PluginHelper::isPluginActive($plugin);
	}

	/**
	 * The level a page file needs: its header's (undeclared or malformed is 10).
	 * Cached by the file's mtime in APCu where it is there, else per request.
	 */
	public static function levelForFile(string $rel): int {
		if (isset(self::$levels[$rel])) {
			return self::$levels[$rel];
		}
		$path = PathHelper::getIncludePath($rel);
		$key = 'joinery_admin_page_level:' . md5($path . '|' . (int)@filemtime($path));
		$apcu = function_exists('apcu_fetch') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'));
		if ($apcu) {
			$hit = apcu_fetch($key, $ok);
			if ($ok) return self::$levels[$rel] = (int)$hit;
		}
		$level = self::headerLevel($rel);
		if ($apcu) apcu_store($key, $level, 3600);
		return self::$levels[$rel] = $level;
	}

	/** The level a page file's header alone declares; undeclared or malformed is 10. */
	public static function headerLevel(string $rel): int {
		$header = AccessDeclarations::pageHeader(PathHelper::getIncludePath($rel));
		if ($header === null || isset($header['error'])) {
			return self::UNDECLARED_LEVEL;
		}
		try {
			if (isset($header['class'])) {
				return Access::level($header['class'] . ':read');
			}
			return Access::level((int)$header['level']);
		} catch (InvalidArgumentException $e) {
			error_log("AdminPageAccess: $rel has a bad header (" . $e->getMessage() . '); it needs ' . self::UNDECLARED_LEVEL);
			return self::UNDECLARED_LEVEL;
		}
	}

	/** The level an admin path needs, or null when it serves no page file. */
	public static function levelForPath(string $path, ?string $view = null): ?int {
		$file = self::fileForPath($path, $view);
		return $file === null ? null : self::levelForFile($file);
	}

	/**
	 * The level a menu entry's link needs: an admin page's level (10 when the
	 * page is missing, as no one can open it), 0 for any other link. A menu
	 * URL is 'admin_users' (under /admin/), or a path.
	 */
	public static function levelForMenuUrl(string $url): int {
		$url = trim($url);
		if ($url === '') {
			return 0;
		}
		$path = (string)parse_url($url, PHP_URL_PATH);
		if ($path === '') {
			return 0;
		}
		if ($path[0] !== '/') {
			$path = '/admin/' . $path;
		}
		if (!self::isAdminPath($path)) {
			return 0;
		}
		return self::levelForPath($path) ?? self::UNDECLARED_LEVEL;
	}

	/** Forget the per-request cache (tests). */
	public static function reset(): void {
		self::$levels = array();
	}
}
