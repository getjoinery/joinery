<?php
/**
 * SandboxPlugins — which plugins in plugins/ are sandboxed, and their manifests.
 *
 * A folder is a sandboxed plugin when it holds exactly two entries, the
 * manifest (manifest.json, a regular file) and the plugin's code folder (src/,
 * a real directory), and nothing else at the top level. Neither may be a
 * link. Anything else is a native plugin and is judged as one
 * (plugin_tiers.md Q11). The kind is always read from the folder, never from
 * what a package or a row says about itself.
 *
 * Native plugin loading skips sandboxed folders (PluginHelper), so nothing in
 * one is ever included; this class is how the sandboxed tier finds them.
 * Nothing here reads what src/ holds.
 *
 * @version 1.2 - the shape is manifest.json and src/
 * @version 1.1 - enabled(): the sandboxed_plugins_enabled setting, off by default, gates upload, install,
 *               approval and activation
 * @version 1.0
 */

class SandboxPlugins {

	/** The setting that allows sandboxed plugins on this site. Off by default. */
	const ENABLED_SETTING = 'sandboxed_plugins_enabled';

	/** What a refused upload, install, approval or activation says. */
	const OFF_MESSAGE = 'Sandboxed plugins are switched off on this site. They are experimental; '
		. 'a superadmin can allow them under Settings ("Allow sandboxed plugins").';

	/**
	 * Whether this site allows a sandboxed plugin to be uploaded, installed,
	 * approved or activated. One already active is not stopped by turning
	 * this off: it runs until it is deactivated.
	 */
	public static function enabled(): bool {
		return (string)Globalvars::get_instance()->get_setting(self::ENABLED_SETTING) === '1';
	}

	/** @throws Exception when sandboxed plugins are switched off here */
	public static function demandEnabled(): void {
		if (!self::enabled()) {
			throw new Exception(self::OFF_MESSAGE);
		}
	}

	/** Per-request caches. */
	private static $kind_cache = array();
	private static $manifest_cache = array();
	private static $active_cache = null;
	private static $installed_cache = null;

	/** The folder that holds a sandboxed plugin's code. */
	const SOURCE_DIR = 'src';

	/** The plugins/ directory. */
	public static function baseDir(): string {
		return rtrim(PathHelper::getIncludePath('plugins'), '/');
	}

	public static function dirFor(string $name): string {
		return self::baseDir() . '/' . basename($name);
	}

	/**
	 * Whether a folder is a sandboxed plugin: exactly manifest.json (a
	 * regular file) and src/ (a real directory), neither a link, and nothing
	 * else.
	 */
	public static function isSandboxedDir(string $path): bool {
		$path = rtrim($path, '/');
		if (array_key_exists($path, self::$kind_cache)) {
			return self::$kind_cache[$path];
		}
		self::$kind_cache[$path] = self::contentsAreSandboxed($path);
		return self::$kind_cache[$path];
	}

	private static function contentsAreSandboxed(string $path): bool {
		if (!is_dir($path) || is_link($path)) {
			return false;
		}
		$entries = @scandir($path);
		if ($entries === false) {
			return false;
		}
		$entries = array_values(array_diff($entries, array('.', '..')));
		return self::namesAreSandboxed($entries, function ($entry) use ($path) {
			$full = $path . '/' . $entry;
			if (is_link($full)) {
				return 'link';
			}
			return is_file($full) ? 'file' : (is_dir($full) ? 'dir' : 'other');
		});
	}

	/**
	 * The contents rule on a list of top-level names, for a folder or a
	 * package's file list. $kind_of says what a name is: 'file' for a regular
	 * file, 'dir' for a real directory, anything else for a link or a thing
	 * that is neither.
	 */
	public static function namesAreSandboxed(array $names, callable $kind_of): bool {
		sort($names);
		if ($names !== array(SandboxManifest::FILE, self::SOURCE_DIR)) {
			return false;
		}
		return $kind_of(SandboxManifest::FILE) === 'file' && $kind_of(self::SOURCE_DIR) === 'dir';
	}

	public static function isSandboxed(string $name): bool {
		return preg_match(SandboxManifest::NAME_PATTERN, $name) === 1 && self::isSandboxedDir(self::dirFor($name));
	}

	/**
	 * A sandboxed plugin's validated manifest.
	 *
	 * @throws SandboxManifestException when it is not sandboxed or its manifest is invalid
	 */
	public static function manifest(string $name): SandboxManifest {
		if (!isset(self::$manifest_cache[$name])) {
			if (!self::isSandboxed($name)) {
				throw new SandboxManifestException("'" . basename($name) . "' is not a sandboxed plugin.");
			}
			self::$manifest_cache[$name] = SandboxManifest::fromFile(self::dirFor($name) . '/' . SandboxManifest::FILE, $name);
		}
		return self::$manifest_cache[$name];
	}

	/**
	 * @return string[] the sandboxed plugins on disk that are installed (a
	 * plugin row not uninstalled): a folder alone declares nothing.
	 */
	public static function installedOnDisk(): array {
		$installed = self::installed();
		return array_values(array_filter(self::onDisk(), function ($name) use ($installed) {
			return isset($installed[$name]);
		}));
	}

	/** @return string[] every sandboxed plugin folder on disk, by name */
	public static function onDisk(): array {
		$names = array();
		foreach (glob(self::baseDir() . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
			$name = basename($dir);
			if (preg_match(SandboxManifest::NAME_PATTERN, $name) && self::isSandboxedDir($dir)) {
				$names[] = $name;
			}
		}
		sort($names);
		return $names;
	}

	/**
	 * @return array<string,SandboxManifest> active sandboxed plugins whose
	 *   manifests are valid, by name. One whose manifest went bad on disk is
	 *   left out and logged: it runs nothing until it is fixed.
	 */
	public static function active(): array {
		if (self::$active_cache !== null) {
			return self::$active_cache;
		}
		$out = array();
		try {
			$dblink = DbConnector::get_instance()->get_db_link();
			$q = $dblink->query("SELECT plg_name FROM plg_plugins WHERE plg_active = 1 ORDER BY plg_name");
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $name) {
				if (!self::isSandboxed((string)$name)) {
					continue;
				}
				try {
					$out[$name] = self::manifest($name);
				} catch (SandboxManifestException $e) {
					error_log('[SandboxPlugins] ' . $name . ' is active but its manifest is invalid: ' . $e->getMessage());
				}
			}
		} catch (Throwable $e) {
			// No database (a CLI tool before it is configured): nothing is
			// active, for the rest of this request too, so every class miss
			// does not try the connection again.
			$out = array();
		}
		self::$active_cache = $out;
		return $out;
	}

	/**
	 * @return array<string,true> plugins that have a registry row and are not
	 *   uninstalled, active or not. A sandboxed folder with no such row has not
	 *   been installed, and declares nothing to model discovery.
	 */
	public static function installed(): array {
		if (self::$installed_cache !== null) {
			return self::$installed_cache;
		}
		$out = array();
		try {
			$q = DbConnector::get_instance()->get_db_link()->query(
				"SELECT plg_name FROM plg_plugins WHERE plg_status IS DISTINCT FROM 'uninstalled'");
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $name) {
				$out[(string)$name] = true;
			}
		} catch (Throwable $e) {
			$out = array();
		}
		self::$installed_cache = $out;
		return $out;
	}

	public static function isActive(string $name): bool {
		return isset(self::active()[$name]);
	}

	/** Forget what this request learned; after an install, activation or removal. */
	public static function reset(): void {
		self::$kind_cache = array();
		self::$manifest_cache = array();
		self::$active_cache = null;
		self::$installed_cache = null;
		// What is installed decides which manifests declare settings.
		if (class_exists('SettingsDeclarations', false)) {
			SettingsDeclarations::reset();
		}
	}
}
