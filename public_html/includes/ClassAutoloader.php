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
 * The map is cached (APCu under the web server, a JSON file under the site
 * root's cache/ directory on CLI, where APCu is not enabled) together with a
 * fingerprint of the tree it was built from. A lookup miss rebuilds the map
 * once and retries when the tree has changed since — so a class added after
 * the cache was written resolves without a cache flush — and costs only a
 * stat walk when it has not, which is what a probe for a class that does not
 * exist here (an inactive plugin's) comes to.
 *
 * @version 1.3.0 - a lookup miss no longer rebuilds the map on every request:
 *   the cached map carries a fingerprint of the scanned tree (file count and
 *   mtimes, a 6 ms stat walk), and a miss rebuilds only when the tree has
 *   changed since the map was built — an absent class costs a walk, not a
 *   482 ms tokenize of every file. The TTL check also revalidates by
 *   fingerprint instead of rebuilding. The same scan records each model's
 *   `static $prefix`, served by modelPrefixes(), so FormWriter can find the
 *   model owning a field without loading every data class on the platform.
 * @version 1.2.0 - the cached map is cache/class_map.json, read with
 *   json_decode. It was cache/class_map.php, a PHP file the web user wrote and
 *   this class `include`d on every request — code the pool both writes and
 *   executes, which is what specs/implemented/read_only_tree.md removes. A class_map.php
 *   left by an earlier release is deleted on the first read.
 * @version 1.1.0 - restrictToCore(): the extraction subprocess resolves core
 *   classes only, never touching the theme chain or the plugin registry, both
 *   of which need the settings and the database it must not have
 * @version 1.0.0
 */
class ClassAutoloader {

	/** Bump when the map's shape changes so stale caches are ignored. */
	const CACHE_KEY = 'joinery_class_map_v2';

	/** Seconds a cached map is trusted before its fingerprint is rechecked. */
	const CACHE_TTL = 600;

	private static $registered = false;
	private static $map = null;
	/** prefix => class names declaring `static $prefix` with that value. */
	private static $prefixes = null;
	/** Fingerprint of the tree the current map was built from. */
	private static $stamp = null;
	private static $rebuilt = false;
	private static $resolving_theme_chain = false;
	private static $core_only = false;

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
	 * Answer for core classes only, from the filesystem alone. For a process
	 * that must never read settings or open the database — the extraction
	 * subprocess (utils/extract_document_text.php) — the theme chain and the
	 * active-plugin set are both off limits: resolving either loads Globalvars,
	 * and under the parser jail the config file is not readable, so that load
	 * is a fatal error rather than an exception. A plugin's sandbox parser is
	 * handed to that process by file path instead.
	 */
	public static function restrictToCore() {
		self::$core_only = true;
		self::$map = null;
		self::$prefixes = null;
		self::$stamp = null;
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
		// map was written — or it may simply not exist (a probe for an
		// optional plugin's class). Only a tree that has changed since the map
		// was built can hold a class the map does not know, so an unchanged
		// tree answers the miss with a stat walk rather than a rebuild. Once
		// per process either way.
		if (!self::$rebuilt) {
			self::$rebuilt = true;
			if (self::$stamp === null || self::fingerprint() !== self::$stamp) {
				$map = self::rebuild();
				if (isset($map[$class]) && is_file($map[$class])) {
					require_once($map[$class]);
				}
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
		// recursing. A core-only process never consults the chain at all.
		if (self::$resolving_theme_chain || self::$core_only) {
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
		if (self::$map === null) {
			self::entry();
		}
		return self::$map;
	}

	/**
	 * Model prefix => the class names declaring `static $prefix` with that
	 * value, for every model the map covers (core and active plugins). A
	 * prefix shared by several models lists each of them; the caller decides
	 * which owns the field it holds.
	 *
	 * @return array
	 */
	public static function modelPrefixes() {
		if (self::$prefixes === null) {
			self::entry();
		}
		return self::$prefixes;
	}

	/**
	 * Populate the map, prefixes and stamp from the cache, or by scanning.
	 */
	private static function entry() {
		$cached = self::cache_read();
		if (is_array($cached)) {
			self::$map = $cached['map'];
			self::$prefixes = $cached['prefixes'];
			self::$stamp = $cached['stamp'];
			return;
		}
		self::rebuild();
	}

	/**
	 * Scan the tree, rebuild the map, and write it to the cache.
	 *
	 * @return array
	 */
	public static function rebuild() {
		$map = array();
		$prefixes = array();
		$complete = true;
		// A rebuild answers this process's one allowed miss-rebuild: a map
		// built moments ago is not made stale by a miss.
		self::$rebuilt = true;

		foreach (self::scan_roots($complete) as $directory) {
			self::scan_directory($directory, $map, $prefixes);
		}

		self::$map = $map;
		self::$prefixes = $prefixes;

		// A core-only map is never cached: it is deliberately missing its
		// plugin half, and it belongs to a process that cannot write here.
		if (self::$core_only) {
			self::$stamp = null;
			return $map;
		}

		self::$stamp = self::fingerprint();

		// A map missing its plugin half would poison every later lookup, so it
		// is used for this request only and never written to the cache.
		if ($complete) {
			self::cache_write(self::payload());
		}

		return $map;
	}

	/**
	 * The directories the map is built from: core includes/ and data/, then
	 * each active plugin's, in that order (first declaration wins, so core
	 * cannot be shadowed). A core-only process stops after core.
	 *
	 * @param bool $complete set FALSE when the active plugin set is unknown
	 * @return string[]
	 */
	private static function scan_roots(&$complete) {
		$roots = array(
			PathHelper::getIncludePath('includes'),
			PathHelper::getIncludePath('data'),
		);
		if (self::$core_only) {
			return $roots;
		}
		foreach (self::active_plugins($complete) as $plugin) {
			$plugin_root = PathHelper::getIncludePath('plugins/' . $plugin);
			$roots[] = $plugin_root . '/includes';
			$roots[] = $plugin_root . '/data';
		}
		return $roots;
	}

	/**
	 * A cheap identity for the scanned tree: how many PHP files it holds and
	 * when they were last written. Any file added, removed or edited changes
	 * it; nothing else does. One stat per file — about 6 ms for the platform.
	 *
	 * @return string
	 */
	private static function fingerprint() {
		$complete = true;
		$count = 0;
		$newest = 0;
		$sum = 0;
		foreach (self::scan_roots($complete) as $directory) {
			if (!is_dir($directory)) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $file) {
				if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
					continue;
				}
				$mtime = $file->getMTime();
				$count++;
				$sum += $mtime;
				if ($mtime > $newest) {
					$newest = $mtime;
				}
			}
		}
		return $count . ':' . $newest . ':' . $sum;
	}

	/**
	 * What the cache holds: the map, the prefix index, the fingerprint of the
	 * tree they describe, and when they were built.
	 */
	private static function payload() {
		return array(
			'map'      => self::$map,
			'prefixes' => self::$prefixes,
			'stamp'    => self::$stamp,
			'built'    => time(),
		);
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
	 * the map, and every `static $prefix` a class declares to the prefix
	 * index. Files are tokenized, never executed.
	 *
	 * @param string $directory
	 * @param array $map
	 * @param array $prefixes
	 */
	private static function scan_directory($directory, &$map, &$prefixes) {
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
			foreach (self::declarations_in($path) as $name => $prefix) {
				// First declaration of a name wins: core before plugins, so a
				// plugin cannot shadow a core class by reusing its name.
				if (isset($map[$name])) {
					continue;
				}
				$map[$name] = $path;
				if ($prefix !== null) {
					$prefixes[$prefix][] = $name;
				}
			}
		}
	}

	/**
	 * Type names declared in one file, each mapped to the string its class
	 * assigns to `static $prefix` (a model's column prefix), or null when it
	 * declares none. A file declaring a namespace returns nothing —
	 * namespaced code (bundled libraries) loads through its own mechanism.
	 *
	 * @param string $path
	 * @return array name => prefix|null
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
		$current = null;
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

			// `static $prefix = 'usr';` inside the class declared last: the
			// model's column prefix, recorded without executing the file.
			if ($id === T_VARIABLE && $tokens[$i][1] === '$prefix' && $current !== null) {
				$before = self::meaningful($tokens, $i, -1, $skip);
				$eq     = self::meaningful($tokens, $i, 1, $skip);
				$value  = $eq === null ? null : self::meaningful($tokens, $eq, 1, $skip);
				if ($before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_STATIC
						&& $eq !== null && $tokens[$eq] === '='
						&& $value !== null && is_array($tokens[$value])
						&& $tokens[$value][0] === T_CONSTANT_ENCAPSED_STRING) {
					$names[$current] = trim($tokens[$value][1], '\'"');
				}
				continue;
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
				$before = self::meaningful($tokens, $i, -1, $skip);
				if ($before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_DOUBLE_COLON) {
					continue;
				}
			}

			// The declared name is the next meaningful token; an anonymous
			// class has none.
			$j = self::meaningful($tokens, $i, 1, $skip);
			if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
				$current = $tokens[$j][1];
				$names[$current] = null;
			}
		}

		return $names;
	}

	/**
	 * Index of the nearest token in $direction from $from that is not
	 * whitespace or a comment, or null at either end of the stream.
	 */
	private static function meaningful(array $tokens, $from, $direction, array $skip) {
		$count = count($tokens);
		for ($j = $from + $direction; $j >= 0 && $j < $count; $j += $direction) {
			if (is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
				continue;
			}
			return $j;
		}
		return null;
	}

	// ---- cache -------------------------------------------------------------

	private static function apcu_available() {
		return function_exists('apcu_enabled') && apcu_enabled();
	}

	private static function cache_file() {
		return PathHelper::getSiteRoot() . '/cache/class_map.json';
	}

	/**
	 * The map used to be a PHP file this class `include`d on every request —
	 * a file the web user writes, executed by the web user, which is the one
	 * shape specs/implemented/read_only_tree.md exists to remove. A cache is data; it is
	 * read with json_decode and never executed. Named so a site upgraded from
	 * a release that wrote the old file can have it removed.
	 */
	private static function legacy_cache_file() {
		return PathHelper::getSiteRoot() . '/cache/class_map.php';
	}

	private static function cache_read() {
		// Before the backend check, not after: a site running APCu never reaches
		// the file path at all, and it is exactly as entitled to have an
		// executable file the web user owns removed from its cache directory.
		self::drop_legacy_cache();

		if (self::apcu_available()) {
			$ok = false;
			$value = apcu_fetch(self::CACHE_KEY, $ok);
			if (!$ok) {
				$value = null;
			}
		} else {
			$file = self::cache_file();
			$raw = is_file($file) ? @file_get_contents($file) : false;
			$value = ($raw === false || $raw === '') ? null : json_decode($raw, true);
		}
		if (!is_array($value) || !isset($value['map'], $value['prefixes'], $value['stamp'], $value['built'])
				|| !is_array($value['map']) || !is_array($value['prefixes'])) {
			return null;
		}

		// Past the TTL the map is not thrown away but checked against the
		// tree: an unchanged tree keeps it for another TTL at the cost of a
		// stat walk, and only a changed tree pays the rebuild.
		if ((time() - intval($value['built'])) > self::CACHE_TTL) {
			if (self::fingerprint() !== $value['stamp']) {
				return null;
			}
			$value['built'] = time();
			self::cache_write($value);
		}
		return $value;
	}

	/**
	 * Remove a class_map.php left by an earlier release. It is inert the moment
	 * cache_file() stops naming it, but an executable file the web user owns
	 * does not get to sit in the tree's cache directory because nothing happens
	 * to read it today.
	 */
	private static function drop_legacy_cache() {
		$legacy = self::legacy_cache_file();
		if (is_file($legacy)) {
			@unlink($legacy);
		}
	}

	private static function cache_write($payload) {
		if (self::apcu_available()) {
			// No APCu TTL: expiry is the payload's own `built`, checked by
			// cache_read(), so a stale-by-age map is revalidated, not dropped.
			apcu_store(self::CACHE_KEY, $payload);
			return;
		}

		$file = self::cache_file();
		$dir  = dirname($file);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return;
		}

		// Written atomically: a half-written map would be read by another
		// process as malformed JSON and thrown away, costing it a rebuild.
		$json = json_encode($payload);
		if ($json === false) {
			return;
		}
		$temp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($temp, $json) !== false) {
			// 0660, not 0666: the accounts that run this are the web user and,
			// on a developer box or a CLI run, an account in its group. Nobody
			// else needs to write the cache, and a world-writable cache file is
			// something any local account can corrupt on every request.
			@chmod($temp, 0660);
			// The cache directory's group, not the writer's primary group: a
			// developer account's file would otherwise be unreadable by the
			// web user's cron runs, which then rebuild and write their own —
			// and the two rebuild past each other forever.
			$group = @filegroup($dir);
			if ($group !== false && @filegroup($temp) !== $group) {
				@chgrp($temp, $group);
			}
			@rename($temp, $file);
		}
	}

	/**
	 * Discard the cached map. Called when the tree changes underneath a
	 * long-lived cache (plugin activation, upgrade).
	 */
	public static function flush() {
		self::$map = null;
		self::$prefixes = null;
		self::$stamp = null;
		self::$rebuilt = false;
		if (self::apcu_available()) {
			apcu_delete(self::CACHE_KEY);
		}
		$file = self::cache_file();
		if (is_file($file)) {
			@unlink($file);
		}
		self::drop_legacy_cache();
	}

	private static function defined_now($class) {
		return class_exists($class, false)
			|| interface_exists($class, false)
			|| trait_exists($class, false)
			|| (function_exists('enum_exists') && enum_exists($class, false));
	}
}
