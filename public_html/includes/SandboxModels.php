<?php
/**
 * SandboxModels — turns a sandboxed plugin's declared models into model classes.
 *
 * Every model the platform knows is a PHP class named after it: the schema
 * builder, the record endpoints (/api/v1/{Class}), key scopes ('Class:verb')
 * and the deletion system all find models by class name. A sandboxed plugin
 * ships no PHP, so for each model its manifest declares this class declares
 * two classes at run time, from one fixed template:
 *
 *   final class Sbx_{plugin}_{Model} extends SandboxRecord { …static properties… }
 *   final class MultiSbx_{plugin}_{Model} extends SandboxRecordCollection { … }
 *
 * and fills their static properties from the manifest. The only text that
 * reaches the template is the class name, built from the plugin and model
 * names SandboxManifest has already held to [a-z0-9_] and [A-Za-z0-9], and
 * checked again against CLASS_PATTERN immediately before declaring, so a
 * manifest cannot put code there. This is the one place core declares a class
 * at run time; tests/unit/sandbox_models_test.php holds that, and that no
 * core or native class could ever be one of these names (none starts 'Sbx_').
 *
 * Names: plugin 'notifier', model 'SentLog' → class Sbx_notifier_SentLog,
 * table sbx_notifier__sent_log, columns notifier__sent_log_{field}
 * (SandboxManifest says why they cannot collide).
 *
 * Which classes resolve: an active sandboxed plugin's (ClassAutoloader asks
 * autoload() on a miss); one plugin's on request, whatever its status,
 * through classesFor() (install and uninstall); and, for model discovery over
 * many plugins, those installed here (a folder merely sitting in plugins/
 * declares nothing).
 *
 * @version 1.0
 */

class SandboxModels {

	/** What a declared class name must look like, checked before eval. */
	const CLASS_PATTERN = '~^Sbx_[a-z][a-z0-9]*(_[a-z0-9]+)*_[A-Z][A-Za-z0-9]{1,19}$~D';

	/** class name => array(plugin, model declaration), for the active plugins. */
	private static $active_map = null;

	/** class names this process has declared. */
	private static $declared = array();

	/**
	 * Autoloader hook: declare $class when it is a model of an active
	 * sandboxed plugin. Returns quietly otherwise.
	 */
	public static function autoload(string $class): void {
		if (stripos($class, 'Sbx_') === false) {
			return;
		}
		$model_class = strncasecmp($class, 'MultiSbx_', 9) === 0 ? substr($class, 5) : $class;
		if (strncasecmp($model_class, 'Sbx_', 4) !== 0) {
			return;
		}
		$map = self::activeMap();
		$entry = $map[strtolower($model_class)] ?? null;
		if ($entry === null) {
			return;
		}
		self::declare($entry[0], $entry[1]);
	}

	/**
	 * Declare and return the model classes of one sandboxed plugin, active or
	 * not. Empty for a plugin that is not sandboxed or whose manifest is invalid.
	 *
	 * @return string[]
	 */
	public static function classesFor(string $plugin): array {
		try {
			$manifest = SandboxPlugins::manifest($plugin);
		} catch (SandboxManifestException $e) {
			return array();
		}
		$classes = array();
		foreach ($manifest->models() as $model) {
			$class = self::declare($plugin, $model);
			if ($class !== null) {
				$classes[] = $class;
			}
		}
		return $classes;
	}

	/**
	 * The model classes of the sandboxed plugins among $plugins (names), for
	 * model discovery.
	 *
	 * @return string[]
	 */
	public static function classesForPlugins(array $plugins): array {
		$classes = array();
		$installed = SandboxPlugins::installed();
		foreach ($plugins as $plugin) {
			if (isset($installed[(string)$plugin]) && SandboxPlugins::isSandboxed((string)$plugin)) {
				$classes = array_merge($classes, self::classesFor((string)$plugin));
			}
		}
		return $classes;
	}

	/**
	 * Declare the two classes for one manifest model, once per process.
	 * Returns the model class, or null when the name is taken by a class that
	 * is not this model's (the install refuses such a manifest; on a site
	 * where it happens anyway, the model is left out rather than shadowing).
	 */
	private static function declare(string $plugin, array $model): ?string {
		$class = SandboxManifest::classFor($plugin, $model['name']);
		if (isset(self::$declared[strtolower($class)])) {
			return $class;
		}
		if (!preg_match(self::CLASS_PATTERN, $class) || !preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
			error_log('[SandboxModels] refused to declare ' . substr($class, 0, 80) . ': not a plain name');
			return null;
		}
		if (class_exists($class, false) || class_exists('Multi' . $class, false)) {
			error_log('[SandboxModels] ' . $plugin . '/' . $model['name'] . ': the class ' . $class . ' already exists; the model is left out');
			return null;
		}

		// The fixed template. $class has just been held to CLASS_PATTERN.
		eval('final class ' . $class . ' extends SandboxRecord {'
			. ' public static $prefix; public static $tablename; public static $pkey_column;'
			. ' public static $field_specifications = array();'
			. ' public static $api_readable = true; public static $api_writable = true;'
			. ' protected static $foreign_key_actions = array();'
			. ' public static $sandbox_plugin; public static $sandbox_model;'
			. ' }'
			. ' final class Multi' . $class . ' extends SandboxRecordCollection {'
			. ' protected static $model_class = \'' . $class . '\';'
			. ' }');

		$prefix = SandboxManifest::prefixFor($plugin, $model['name']);
		$class::$prefix = $prefix;
		$class::$tablename = SandboxManifest::tableFor($plugin, $model['name']);
		$class::$pkey_column = $prefix . '_id';
		$class::$field_specifications = SandboxRecord::specificationsFor($prefix, $model['fields']);
		$class::$sandbox_plugin = $plugin;
		$class::$sandbox_model = $model['name'];

		self::$declared[strtolower($class)] = true;
		return $class;
	}

	/** @return array<string,array{0:string,1:array}> */
	private static function activeMap(): array {
		if (self::$active_map !== null) {
			return self::$active_map;
		}
		$map = array();
		foreach (SandboxPlugins::active() as $plugin => $manifest) {
			foreach ($manifest->models() as $model) {
				$map[strtolower(SandboxManifest::classFor($plugin, $model['name']))] = array($plugin, $model);
			}
		}
		self::$active_map = $map;
		return $map;
	}

	/** Forget the active set; after an activation or removal in this process. */
	public static function reset(): void {
		self::$active_map = null;
	}

	/**
	 * Drop a plugin's model tables and their id sequences, for uninstall: the
	 * rows are the plugin's own and nothing outside it references them (a
	 * manifest field cannot be a foreign key). The tables are the ones its
	 * manifest names now and the ones recorded when they were made
	 * (SandboxPluginManager::recordTables()), so a model a later version
	 * dropped, or a manifest gone bad, leaves nothing behind.
	 *
	 * @return string[] the tables dropped
	 */
	public static function dropTables(string $plugin, array $recorded = array()): array {
		$tables = array();
		foreach (self::classesFor($plugin) as $class) {
			$tables[$class::$tablename] = $class::$pkey_column;
		}
		foreach ($recorded as $table => $pkey) {
			$tables[(string)$table] = (string)$pkey;
		}
		$dropped = array();
		$dblink = DbConnector::get_instance()->get_db_link();
		$owned = '~^sbx_' . preg_quote($plugin, '~') . '__[a-z0-9_]+$~D';
		foreach ($tables as $table => $pkey) {
			if (!preg_match($owned, $table) || !preg_match('~^[a-z0-9_]+$~D', $pkey)) {
				continue;
			}
			$dblink->exec('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
			// The id sequence is made without OWNED BY, so CASCADE leaves it.
			$dblink->exec('DROP SEQUENCE IF EXISTS ' . $table . '_' . $pkey . '_seq');
			$dropped[] = $table;
		}
		return $dropped;
	}
}
