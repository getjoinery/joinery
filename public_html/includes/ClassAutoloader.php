<?php
/**
 * ClassAutoloader — resolves platform classes by name.
 *
 * Core `includes/` classes are 1:1 with their filename. Model classes are not
 * (class `Product` lives in `data/products_class.php`), so the rest of the
 * answer comes from a class => filepath map built by tokenizing every core and
 * active-plugin `includes/` and `data/` file. Tokenizing means the map is built
 * without executing any of the mapped files.
 *
 * The map is cached (APCu under the web server, a file under the site root's
 * cache/ directory on CLI, where APCu is not enabled). A lookup miss rebuilds
 * the map once and retries, so a class added since the cache was written
 * resolves without a cache flush.
 *
 * @version 1.0.0
 */
class ClassAutoloader {

	/** Bump when the map's shape changes so stale caches are ignored. */
	const CACHE_KEY = 'joinery_class_map_v1';

	/** Seconds a cached map is trusted before it is rebuilt. */
	const CACHE_TTL = 600;

	private static $registered = false;
	private static $map = null;
	private static $rebuilt = false;
	private static $resolving_theme_chain = false;

	/**
	 * Register the autoloader. Safe to call repeatedly.
	 */
	public static function register() {
		if (self::$registered) {
			return;
		}
		self::$registered = true;
		spl_autoload_register(array(__CLASS__, 'load'));
	}

	/**
	 * Resolve one class name to a file and require it.
	 *
	 * @param string $class
	 */
	public static function load($class) {
		// Namespaced code brings its own loader; this map is flat by design.
		if (strpos($class, '\\') !== false) {
			return;
		}
		if ($class === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
			return;
		}

		// Fast path: classes under includes/ are 1:1 by filename, so most core
		// classes resolve without building the map at all.
		//
		// The lookup goes through the theme chain rather than straight to the
		// core file, because a handful of these classes are the ones a theme
		// replaces by shipping its own includes/{Class}.php — PublicPage and
		// FormWriter. Loading the core file for those would silently install
		// the wrong markup for the whole request.
		$direct = self::theme_aware_path($class);
		if ($direct !== null) {
			require_once($direct);
			if (self::defined_now($class)) {
				return;
			}
		}

		$map = self::map();
		if (isset($map[$class]) && is_file($map[$class])) {
			require_once($map[$class]);
			if (self::defined_now($class)) {
				return;
			}
		}

		// Miss against a cached map: the class may have been added since the
		// map was written. Rebuild once per process and retry.
		if (!self::$rebuilt) {
			self::$rebuilt = true;
			$map = self::rebuild();
			if (isset($map[$class]) && is_file($map[$class])) {
				require_once($map[$class]);
			}
		}
	}

	/**
	 * Where an `includes/{Class}.php` file resolves for this request: the
	 * active theme's copy if it has one, then a plugin's, then core's. Returns
	 * NULL when no such file exists anywhere.
	 *
	 * @param string $class
	 * @return string|null
	 */
	private static function theme_aware_path($class) {
		$file = $class . '.php';
		$core = PathHelper::getIncludePath('includes/' . $file);

		// A name with no core file is not an includes/ class at all — a model,
		// or nothing. Checked first so the theme chain is only consulted for
		// the couple of hundred names it could possibly answer for.
		if (!is_file($core)) {
			return null;
		}

		// Resolving the theme chain reads settings and theme metadata, which can
		// itself want a class. One level in, answer from core rather than
		// recursing.
		if (self::$resolving_theme_chain) {
			return $core;
		}

		self::$resolving_theme_chain = true;
		try {
			return PathHelper::getThemeFilePath($file, 'includes');
		} catch (Throwable $e) {
			return $core;
		} finally {
			self::$resolving_theme_chain = false;
		}
	}

	/**
	 * The class => filepath map, from cache when available.
	 *
	 * @return array
	 */
	public static function map() {
		if (self::$map !== null) {
			return self::$map;
		}

		$cached = self::cache_read();
		if (is_array($cached)) {
			self::$map = $cached;
			return self::$map;
		}

		return self::rebuild();
	}

	/**
	 * Scan the tree, rebuild the map, and write it to the cache.
	 *
	 * @return array
	 */
	public static function rebuild() {
		$map = array();
		$complete = true;

		self::scan_directory(PathHelper::getIncludePath('includes'), $map);
		self::scan_directory(PathHelper::getIncludePath('data'), $map);

		foreach (self::active_plugins($complete) as $plugin) {
			$plugin_root = PathHelper::getIncludePath('plugins/' . $plugin);
			self::scan_directory($plugin_root . '/includes', $map);
			self::scan_directory($plugin_root . '/data', $map);
		}

		self::$map = $map;

		// A map missing its plugin half would poison every later lookup, so it
		// is used for this request only and never written to the cache.
		if ($complete) {
			self::cache_write($map);
		}

		return $map;
	}

	/**
	 * Active plugin names. Sets $complete to FALSE when the active set cannot
	 * be determined (early bootstrap, no database) — the caller then treats the
	 * map as request-scoped rather than caching a core-only answer.
	 *
	 * @param bool $complete
	 * @return array
	 */
	private static function active_plugins(&$complete) {
		$plugins_dir = PathHelper::getIncludePath('plugins');
		if (!is_dir($plugins_dir)) {
			return array();
		}

		try {
			if (!class_exists('PluginHelper', false)) {
				require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
			}
			$active = array();
			foreach (scandir($plugins_dir) as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				if (!is_dir($plugins_dir . '/' . $entry)) {
					continue;
				}
				if (PluginHelper::isPluginActive($entry)) {
					$active[] = $entry;
				}
			}
			return $active;
		} catch (Throwable $e) {
			$complete = false;
			return array();
		}
	}

	/**
	 * Add every class, interface, trait and enum declared under a directory to
	 * the map. Files are tokenized, never executed.
	 *
	 * @param string $directory
	 * @param array $map
	 */
	private static function scan_directory($directory, &$map) {
		if (!is_dir($directory)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
				continue;
			}
			$path = $file->getPathname();
			foreach (self::declarations_in($path) as $name) {
				// First declaration of a name wins: core before plugins, so a
				// plugin cannot shadow a core class by reusing its name.
				if (!isset($map[$name])) {
					$map[$name] = $path;
				}
			}
		}
	}

	/**
	 * Type names declared in one file. A file declaring a namespace returns
	 * nothing — namespaced code (bundled libraries) loads through its own
	 * mechanism.
	 *
	 * @param string $path
	 * @return array
	 */
	private static function declarations_in($path) {
		$source = @file_get_contents($path);
		if ($source === false || strpos($source, '<?php') === false) {
			return array();
		}

		// Cheap pre-filter: files with no declaration keyword are the majority
		// of misses and never need tokenizing.
		if (!preg_match('/\b(class|interface|trait|enum)\s/i', $source)) {
			return array();
		}

		try {
			$tokens = @token_get_all($source);
		} catch (Throwable $e) {
			return array();
		}
		if (!is_array($tokens)) {
			return array();
		}

		$names = array();
		$count = count($tokens);
		$skip  = array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT);

		for ($i = 0; $i < $count; $i++) {
			if (!is_array($tokens[$i])) {
				continue;
			}
			$id = $tokens[$i][0];

			if ($id === T_NAMESPACE) {
				return array();
			}

			$is_declaration = ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT);
			if (!$is_declaration && defined('T_ENUM') && $id === T_ENUM) {
				$is_declaration = true;
			}
			if (!$is_declaration) {
				continue;
			}

			// `Foo::class` is not a declaration.
			if ($id === T_CLASS) {
				$preceded_by_double_colon = false;
				for ($j = $i - 1; $j >= 0; $j--) {
					if (is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
						continue;
					}
					$preceded_by_double_colon = is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON;
					break;
				}
				if ($preceded_by_double_colon) {
					continue;
				}
			}

			// The declared name is the next meaningful token; an anonymous
			// class has none.
			for ($j = $i + 1; $j < $count; $j++) {
				if (is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
					continue;
				}
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					$names[] = $tokens[$j][1];
				}
				break;
			}
		}

		return $names;
	}

	// ---- cache -------------------------------------------------------------

	private static function apcu_available() {
		return function_exists('apcu_enabled') && apcu_enabled();
	}

	private static function cache_file() {
		return PathHelper::getSiteRoot() . '/cache/class_map.php';
	}

	private static function cache_read() {
		if (self::apcu_available()) {
			$ok = false;
			$value = apcu_fetch(self::CACHE_KEY, $ok);
			return ($ok && is_array($value)) ? $value : null;
		}

		$file = self::cache_file();
		if (!is_file($file) || (time() - @filemtime($file)) > self::CACHE_TTL) {
			return null;
		}
		$value = @include($file);
		return is_array($value) ? $value : null;
	}

	private static function cache_write($map) {
		if (self::apcu_available()) {
			apcu_store(self::CACHE_KEY, $map, self::CACHE_TTL);
			return;
		}

		$file = self::cache_file();
		$dir  = dirname($file);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return;
		}

		// Written atomically: a half-written map would be included by another
		// process as a parse error.
		$temp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($temp, '<?php return ' . var_export($map, true) . ';') !== false) {
			@chmod($temp, 0666);
			@rename($temp, $file);
		}
	}

	/**
	 * Discard the cached map. Called when the tree changes underneath a
	 * long-lived cache (plugin activation, upgrade).
	 */
	public static function flush() {
		self::$map = null;
		self::$rebuilt = false;
		if (self::apcu_available()) {
			apcu_delete(self::CACHE_KEY);
		}
		$file = self::cache_file();
		if (is_file($file)) {
			@unlink($file);
		}
	}

	private static function defined_now($class) {
		return class_exists($class, false)
			|| interface_exists($class, false)
			|| trait_exists($class, false)
			|| (function_exists('enum_exists') && enum_exists($class, false));
	}
}
