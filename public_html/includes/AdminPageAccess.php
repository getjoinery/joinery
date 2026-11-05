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
 * @version 1.4 - the menu cache is one RamStore entry holding every page's level, read once a request
 *                and written once at its end (strategy/object_free_window_store.md WP3)
 * @version 1.3 - the menu cache is read through ApcuStore::fetch() (unseal_daemon.md C4)
 * @version 1.2 - the level that opens a page is read from its file, never APCu (unseal_daemon.md C3)
 * @version 1.1
 */
final class AdminPageAccess {

	/** A page with no header, or a malformed one, needs this. */
	const UNDECLARED_LEVEL = 10;

	/** @var array file => level, for this request */
	private static $levels = array();
	/** Levels for drawing menus, possibly from the store; never read by levelForFile(). */
	private static $display_levels = array();

	/** The menu cache's one store entry: {md5(path|mtime) => level}. */
	const MENU_CACHE_KEY = 'admin_page_levels';
	const MENU_CACHE_TTL = 3600;
	/** Past this many entries the cache starts over: old mtimes are never asked again. */
	const MENU_CACHE_MAX = 4000;

	/** @var array|null The stored map, read at most once a request. */
	private static $stored = null;
	/** @var array Levels this request worked out, written at the end of it. */
	private static $pending = array();

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
	 * The level a page file needs: its header's (undeclared or malformed is 10),
	 * read from the file itself and remembered for the request. This is the
	 * answer that lets someone in, so it never comes from shared memory: the
	 * pool writes it, and a stored 0 would open the page to anyone (unseal_daemon.md C3).
	 */
	public static function levelForFile(string $rel): int {
		if (isset(self::$levels[$rel])) {
			return self::$levels[$rel];
		}
		return self::$levels[$rel] = self::headerLevel($rel);
	}

	/**
	 * The same level for drawing a menu, cached by the file's mtime in the
	 * store. A wrong cached value shows or hides a link and nothing else;
	 * opening the page asks levelForFile().
	 */
	private static function displayLevelForFile(string $rel): int {
		if (isset(self::$levels[$rel])) {
			return self::$levels[$rel];
		}
		if (isset(self::$display_levels[$rel])) {
			return self::$display_levels[$rel];
		}
		$path = PathHelper::getIncludePath($rel);
		$key = md5($path . '|' . (int)@filemtime($path));
		if (self::$stored === null) {
			self::$stored = self::readMenuCache();
		}
		if (isset(self::$stored[$key])) {
			return self::$display_levels[$rel] = self::$stored[$key];
		}
		$level = self::headerLevel($rel);
		if (!self::$pending) {
			register_shutdown_function(array(__CLASS__, 'flushMenuCache'));
		}
		self::$pending[$key] = $level;
		return self::$display_levels[$rel] = $level;
	}

	/** The stored map, keeping only entries that are a level. */
	private static function readMenuCache(): array {
		$map = RamStore::get(self::MENU_CACHE_KEY, $ok);
		if (!$ok || !is_array($map)) {
			return array();
		}
		return array_filter($map, 'is_int');
	}

	/**
	 * Write this request's new levels into the stored map, merged with what
	 * other requests wrote meanwhile. Two requests racing lose each other's
	 * additions, which only costs a header read next time.
	 */
	public static function flushMenuCache(): void {
		if (!self::$pending) {
			return;
		}
		$map = self::readMenuCache();
		if (count($map) + count(self::$pending) > self::MENU_CACHE_MAX) {
			$map = array();
		}
		$map = self::$pending + $map;
		self::$pending = array();
		RamStore::set(self::MENU_CACHE_KEY, $map, self::MENU_CACHE_TTL);
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
		$file = self::fileForPath($path);
		return $file === null ? self::UNDECLARED_LEVEL : self::displayLevelForFile($file);
	}

	/** Forget the per-request cache (tests). Levels not yet written are dropped. */
	public static function reset(): void {
		self::$levels = array();
		self::$display_levels = array();
		self::$stored = null;
		self::$pending = array();
	}
}
