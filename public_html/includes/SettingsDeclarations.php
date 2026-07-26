<?php
/**
 * The setting declarations, read from the manifests.
 *
 * A setting is declared once — in `settings.json` at the public_html root for
 * core settings, or in a plugin's `plugin.json` under `settings` — and every
 * page that renders it and every path that writes it asks here for the rules.
 * If a name is not declared, it is not a setting.
 *
 * Declaration keys beyond `name` / `default` / `helptext`:
 *
 *   group        Which box the field renders in. Ungrouped fields fall into
 *                the source's default group.
 *   label        Field label. Required for anything renderable.
 *   type         text | number | checkbox | select | password | textarea
 *   options      Literal value => label map, for `select`.
 *   options_from  'Class::method' returning a value => label map, for options
 *                that are discovered rather than fixed.
 *   options_include  Path to the file defining that class, when it is not one
 *                of the always-loaded core classes.
 *   validation   A FormWriter validation rule array, verbatim. No new
 *                vocabulary — see FormWriterV2Base::validateField().
 *   show_when    { "other_setting": "value" }, compiled to FormWriter
 *                visibility_rules.
 *   secret       Rendered as a password field, never emits the stored value,
 *                and only a non-empty submission is written.
 *   vault_gated  Writing it requires an open vault unlock window.
 *   managed      Machine-written. Never rendered on a form.
 *
 * @version 1.0
 */
class SettingsDeclarations {

	/** @var array|null name => declaration, with '_source' and '_group' resolved */
	private static $cache = null;

	/** @var array|null name => declaration, active plugins and core only */
	private static $active_cache = null;

	/** @var array|null source => group => heading */
	private static $group_labels = null;

	const DEFAULT_GROUP = 'general';

	/**
	 * Every declaration on disk, keyed by setting name.
	 *
	 * Plugins that are present but deactivated are included: their rows persist
	 * in stg_settings, so leaving them out would make every such row look
	 * undeclared to the orphan check.
	 */
	public static function all(): array {
		if (self::$cache !== null) return self::$cache;

		$declarations = array();

		$core_path = PathHelper::getIncludePath('settings.json');
		foreach (self::readManifest($core_path, 'settings') as $entry) {
			$resolved = self::resolve($entry, 'core');
			if ($resolved !== null) $declarations[$resolved['name']] = $resolved;
		}

		$plugin_dir = PathHelper::getIncludePath('plugins');
		foreach ((array)glob($plugin_dir . '/*/plugin.json') as $manifest_path) {
			$plugin_name = basename(dirname($manifest_path));
			foreach (self::readManifest($manifest_path, 'settings') as $entry) {
				$resolved = self::resolve($entry, $plugin_name);
				// A core setting always wins a name collision. PluginManager
				// already refuses such a plugin at sync time; this keeps the
				// resolver total rather than depending on that having run.
				if ($resolved !== null && !isset($declarations[$resolved['name']])) {
					$declarations[$resolved['name']] = $resolved;
				}
			}
		}

		self::$cache = $declarations;
		return self::$cache;
	}

	/**
	 * Declarations from core plus the plugins that are actually active — the
	 * set a page may render and a save may write. A deactivated plugin's rows
	 * stay in the database but are neither shown nor writable.
	 */
	public static function active(): array {
		if (self::$active_cache !== null) return self::$active_cache;

		$active = array();
		foreach (self::all() as $name => $declaration) {
			if ($declaration['_source'] === 'core' || PluginHelper::isPluginActive($declaration['_source'])) {
				$active[$name] = $declaration;
			}
		}

		self::$active_cache = $active;
		return self::$active_cache;
	}

	public static function get(string $name): ?array {
		$all = self::all();
		return $all[$name] ?? null;
	}

	public static function isDeclared(string $name): bool {
		return isset(self::all()[$name]);
	}

	/**
	 * A credential. Rendered as a password field, and an empty submission means
	 * "keep the stored value" rather than "blank it".
	 */
	public static function isSecret(string $name): bool {
		$declaration = self::get($name);
		return $declaration !== null && !empty($declaration['secret']);
	}

	/**
	 * Machine-written: seeded and readable, never on a form.
	 */
	public static function isManaged(string $name): bool {
		$declaration = self::get($name);
		return $declaration !== null && !empty($declaration['managed']);
	}

	/**
	 * Changing it requires an open vault unlock window. VaultGatedSettings
	 * consults this alongside the plugin-level list it already reads.
	 */
	public static function isVaultGated(string $name): bool {
		$declaration = self::get($name);
		return $declaration !== null && !empty($declaration['vault_gated']);
	}

	/**
	 * Renderable declarations for one group, in manifest order.
	 *
	 * @param string      $group  Group name.
	 * @param string|null $source Restrict to 'core' or one plugin name.
	 * @return array List of declarations.
	 */
	public static function forGroup(string $group, ?string $source = null): array {
		$fields = array();
		foreach (self::active() as $declaration) {
			if ($declaration['_group'] !== $group) continue;
			if ($source !== null && $declaration['_source'] !== $source) continue;
			if (!empty($declaration['managed'])) continue;
			$fields[] = $declaration;
		}
		return $fields;
	}

	/**
	 * Group names a source declares, in first-appearance order, skipping
	 * groups that hold nothing renderable.
	 *
	 * @return string[]
	 */
	public static function groupsFor(string $source): array {
		$groups = array();
		foreach (self::active() as $declaration) {
			if ($declaration['_source'] !== $source) continue;
			if (!empty($declaration['managed'])) continue;
			$groups[$declaration['_group']] = true;
		}
		return array_keys($groups);
	}

	/**
	 * Active plugins that declare at least one renderable setting, sorted so
	 * section order is stable across requests.
	 *
	 * This is what gives a declared setting a home: a plugin appears on the
	 * Plugin Settings tab because it declares something, not because it
	 * remembered to ship a form.
	 *
	 * @return string[]
	 */
	public static function renderableSources(): array {
		$sources = array();
		foreach (self::active() as $declaration) {
			if ($declaration['_source'] === 'core') continue;
			if (!empty($declaration['managed'])) continue;
			$sources[$declaration['_source']] = true;
		}
		$sources = array_keys($sources);
		sort($sources);
		return $sources;
	}

	/**
	 * Groups from another source that this one also shows. A plugin page may
	 * mirror a declared group — it is the same field shown twice, never a
	 * second field that can drift.
	 *
	 * @return string[]
	 */
	public static function mirrorGroupsFor(string $source): array {
		if ($source === 'core') return array();
		$path = PathHelper::getIncludePath('plugins/' . $source . '/plugin.json');
		$data = json_decode((string)@file_get_contents($path), true);
		return (is_array($data) && !empty($data['settingsMirrorGroups']) && is_array($data['settingsMirrorGroups']))
			? $data['settingsMirrorGroups']
			: array();
	}

	/**
	 * The heading a group renders under, from the manifest's `settingsGroups`
	 * map. Falls back to a readable form of the group key, so a group that
	 * nobody named still gets a heading rather than nothing.
	 */
	public static function groupLabel(string $source, string $group): string {
		if (self::$group_labels === null) self::loadGroupLabels();
		return self::$group_labels[$source][$group]
			?? ucwords(str_replace(array('_', '-'), ' ', $group));
	}

	/**
	 * Resolve `options_from` to its value => label map.
	 *
	 * The declaration names a static method as 'Class::method'; the class is
	 * expected to be loadable by the time a form renders it.
	 */
	public static function resolveOptions(array $declaration): array {
		if (isset($declaration['options']) && is_array($declaration['options'])) {
			return $declaration['options'];
		}
		if (empty($declaration['options_from'])) {
			return array();
		}
		$callable = $declaration['options_from'];
		if (!is_string($callable) || strpos($callable, '::') === false) {
			return array();
		}
		self::loadOptionsClass($declaration);
		if (!is_callable($callable)) {
			error_log("SettingsDeclarations: options_from '{$callable}' for setting '"
				. ($declaration['name'] ?? '?') . "' is not callable.");
			return array();
		}
		$options = call_user_func($callable);
		return is_array($options) ? $options : array();
	}

	/**
	 * Check every declaration for internal consistency. Returns a list of
	 * human-readable problems; empty means the manifests are well-formed.
	 *
	 * Called by plugin sync so a malformed manifest is reported where it is
	 * introduced, and by the declared-settings test.
	 */
	public static function schemaErrors(): array {
		$errors = array();
		$valid_types = array('text', 'number', 'checkbox', 'select', 'password', 'textarea');

		foreach (self::all() as $name => $d) {
			$where = "{$d['_source']}:{$name}";

			if (!empty($d['managed'])) {
				if (isset($d['label'])) {
					$errors[] = "{$where}: a managed setting is never rendered, so it must not declare a label.";
				}
				// Nothing else about a managed setting is meaningful.
				continue;
			}

			if (isset($d['type']) && !in_array($d['type'], $valid_types, true)) {
				$errors[] = "{$where}: unknown type '{$d['type']}'. Expected one of " . implode(', ', $valid_types) . '.';
			}

			if (isset($d['label']) && !is_string($d['label'])) {
				$errors[] = "{$where}: label must be a string.";
			}

			if (($d['type'] ?? '') === 'select') {
				$has_literal = isset($d['options']) && is_array($d['options']) && $d['options'] !== array();
				$has_source  = !empty($d['options_from']);
				if (!$has_literal && !$has_source) {
					$errors[] = "{$where}: a select must declare options or options_from.";
				}
			}

			if (!empty($d['options_from'])) {
				if (!is_string($d['options_from']) || strpos($d['options_from'], '::') === false) {
					$errors[] = "{$where}: options_from must be 'Class::method'.";
				} else {
					self::loadOptionsClass($d);
					if (!is_callable($d['options_from'])) {
						$errors[] = "{$where}: options_from '{$d['options_from']}' does not resolve to a callable"
							. (empty($d['options_include'])
								? ' — a class outside the always-loaded core needs an options_include path.'
								: " (tried options_include '{$d['options_include']}').");
					}
				}
			}

			if (isset($d['validation']) && !is_array($d['validation'])) {
				$errors[] = "{$where}: validation must be a rule array, not a " . gettype($d['validation']) . '.';
			}

			if (isset($d['show_when']) && !is_array($d['show_when'])) {
				$errors[] = "{$where}: show_when must be a map of setting name to value.";
			}

			// A secret is normally a password field, but some are genuinely
			// multi-line — a PEM private key, a service-account JSON — and a
			// single-line input is the wrong control for those. What `secret`
			// guarantees is that the stored value never reaches the page and
			// that a blank submission keeps it, which holds for both.
			if (!empty($d['secret']) && !in_array($d['type'] ?? 'password', array('password', 'textarea'), true)) {
				$errors[] = "{$where}: a secret renders as a password field or a textarea; "
					. "drop the conflicting type '{$d['type']}'.";
			}
		}

		return $errors;
	}

	/**
	 * Drop the cached manifests. Tests that write a manifest call this; nothing
	 * in a request should need it.
	 */
	public static function reset(): void {
		self::$cache = null;
		self::$active_cache = null;
		self::$group_labels = null;
	}

	// ── internals ───────────────────────────────────────────────────────────

	/**
	 * Pull in the file that defines an `options_from` class. Core classes are
	 * already loaded; anything else names its file in `options_include`.
	 */
	private static function loadOptionsClass(array $declaration): void {
		if (empty($declaration['options_include'])) return;
		$path = PathHelper::getIncludePath($declaration['options_include']);
		if (file_exists($path)) require_once($path);
	}

	private static function loadGroupLabels(): void {
		$labels = array();

		$core = json_decode((string)@file_get_contents(PathHelper::getIncludePath('settings.json')), true);
		if (is_array($core) && !empty($core['settingsGroups']) && is_array($core['settingsGroups'])) {
			$labels['core'] = $core['settingsGroups'];
		}

		foreach ((array)glob(PathHelper::getIncludePath('plugins') . '/*/plugin.json') as $manifest_path) {
			$data = json_decode((string)@file_get_contents($manifest_path), true);
			if (is_array($data) && !empty($data['settingsGroups']) && is_array($data['settingsGroups'])) {
				$labels[basename(dirname($manifest_path))] = $data['settingsGroups'];
			}
		}

		self::$group_labels = $labels;
	}

	private static function readManifest(string $path, string $key): array {
		if (!file_exists($path)) return array();
		$data = json_decode((string)file_get_contents($path), true);
		if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) return array();
		return $data[$key];
	}

	private static function resolve($entry, string $source): ?array {
		if (!is_array($entry) || empty($entry['name']) || !is_string($entry['name'])) {
			return null;
		}
		$entry['_source'] = $source;
		$entry['_group'] = isset($entry['group']) && $entry['group'] !== ''
			? (string)$entry['group']
			: ($source === 'core' ? self::DEFAULT_GROUP : $source);
		return $entry;
	}
}
