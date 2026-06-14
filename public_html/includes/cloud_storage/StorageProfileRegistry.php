<?php
/**
 * StorageProfileRegistry — declarative enumeration of StorageProfiles.
 *
 * Profiles are declared, not self-registered at runtime, so the registry sees
 * them regardless of whether the owning plugin is active — matching how the
 * platform already declares plugin settings and menus. This is what lets the
 * lifecycle and guard 1 operate over "every profile of a given visibility":
 * a deactivated plugin leaves its files (and so its declaration and class) on
 * disk, so the guard can still see its cloud rows.
 *
 *   - Core profiles  → listed in storage_profiles.json at the public_html root,
 *                      class file at includes/cloud_storage/<ClassName>.php.
 *   - Plugin profiles → listed under a "storage_profiles" key in the plugin's
 *                      plugin.json, class file at
 *                      plugins/<plugin>/includes/<ClassName>.php.
 *
 * Each manifest entry is just a class name; visibility comes from the
 * instantiated profile's visibility(), never the manifest. Implementations
 * must have a no-argument constructor.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfile.php'));

class StorageProfileRegistry {

	/** @var StorageProfile[]|null lazily built, keyed by class name */
	private static $profiles = null;

	/**
	 * All declared, instantiable profiles (core + every plugin on disk,
	 * active or not). A declared class that cannot be loaded or instantiated
	 * is logged and skipped — one bad declaration never blanks the registry.
	 *
	 * @return StorageProfile[]
	 */
	public static function all(): array {
		if (self::$profiles !== null) {
			return array_values(self::$profiles);
		}
		self::$profiles = [];

		// Core manifest.
		$core_manifest = PathHelper::getIncludePath('storage_profiles.json');
		foreach (self::_read_declarations($core_manifest) as $class) {
			self::_load_and_register($class, PathHelper::getIncludePath('includes/cloud_storage/' . $class . '.php'));
		}

		// Every plugin's plugin.json — on disk, active or not.
		foreach (glob(PathHelper::getIncludePath('plugins/*/plugin.json')) as $plugin_json) {
			$plugin_dir = dirname($plugin_json);
			foreach (self::_read_declarations($plugin_json) as $class) {
				self::_load_and_register($class, $plugin_dir . '/includes/' . $class . '.php');
			}
		}

		return array_values(self::$profiles);
	}

	/**
	 * Profiles whose visibility() matches $visibility — the set the storage
	 * layer treats as a single store.
	 *
	 * @return StorageProfile[]
	 */
	public static function forVisibility(string $visibility): array {
		$out = [];
		foreach (self::all() as $profile) {
			if ($profile->visibility() === $visibility) {
				$out[] = $profile;
			}
		}
		return $out;
	}

	/** Clear the cache (tests after declaring a new profile on disk). */
	public static function reset(): void {
		self::$profiles = null;
	}

	/**
	 * Pull the storage_profiles array out of a manifest/plugin.json file.
	 * Missing file, unreadable JSON, or absent key all yield [].
	 *
	 * @return string[] class names
	 */
	private static function _read_declarations(string $json_path): array {
		if (!is_file($json_path)) {
			return [];
		}
		$data = json_decode((string)file_get_contents($json_path), true);
		if (!is_array($data) || empty($data['storage_profiles']) || !is_array($data['storage_profiles'])) {
			return [];
		}
		$classes = [];
		foreach ($data['storage_profiles'] as $entry) {
			if (is_string($entry) && $entry !== '') {
				$classes[] = $entry;
			}
		}
		return $classes;
	}

	private static function _load_and_register(string $class, string $file): void {
		if (isset(self::$profiles[$class])) {
			return; // already registered
		}
		try {
			if (!class_exists($class)) {
				if (!is_file($file)) {
					error_log('StorageProfileRegistry: class file not found for ' . $class . ' at ' . $file);
					return;
				}
				require_once($file);
			}
			if (!class_exists($class)) {
				error_log('StorageProfileRegistry: ' . $class . ' still undefined after requiring ' . $file);
				return;
			}
			$instance = new $class();
			if (!($instance instanceof StorageProfile)) {
				error_log('StorageProfileRegistry: ' . $class . ' does not implement StorageProfile');
				return;
			}
			self::$profiles[$class] = $instance;
		} catch (Throwable $e) {
			error_log('StorageProfileRegistry: failed to register ' . $class . ' — ' . $e->getMessage());
		}
	}
}
