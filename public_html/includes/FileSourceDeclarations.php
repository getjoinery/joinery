<?php
/**
 * The file origin tags, read from core and from every plugin's manifest.
 *
 * Every file row carries an origin tag (`fil_source`) saying what made it, and the
 * catalog says what each tag is called and whether listings show it. Core's own
 * tags are written in File::core_source_catalog(). A plugin declares its tags in
 * its `plugin.json`:
 *
 *   "fileSources": {
 *     "forum_attachment": { "label": "Forum attachments", "internal": false, "default_view": false }
 *   }
 *
 *   label        Human name. Listing filters and pickers show it. Required.
 *   internal     A file the system made for its own use; no browse surface lists
 *                it. Optional, default false.
 *   default_view Included when a listing opens with no filter chosen. Optional,
 *                default false.
 *
 * Every plugin on disk is read, active or not: an inactive plugin's files keep
 * their name, and an internal tag stays unlisted while its plugin is off.
 *
 * A tag core owns cannot be declared by a plugin, and two plugins cannot declare
 * the same tag. Either is reported by errorsFor() (run when a plugin is
 * validated, and on every sync); at runtime core's entry wins, and between
 * plugins the active one installed first keeps the tag (byPrecedence), so the
 * catalog is total whether or not the check has been run.
 *
 * @version 1.2 - a tag two plugins claim goes to the active plugin installed first, not to directory order
 * @changelog 1.1 - a usable tag with a stray key stays in the catalog as declared; errorsFor() can check against a set of plugins
 * @changelog 1.0
 */
class FileSourceDeclarations {

	/** A tag is lowercase letters, digits and underscores, and fits fil_source (varchar(64)). */
	const TAG_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/** @var array|null tag => spec, the merged catalog */
	private static $catalog = null;

	/** @var array|null plugin => raw fileSources map from its manifest */
	private static $manifests = null;

	/** @var array plugin => true, for a plugin whose fileSources is not an object */
	private static $malformed = array();

	/** @var array tag => spec, declared at runtime by a test process */
	private static $test_declarations = array();

	/** @var string|null a test process's stand-in for the plugins directory */
	private static $test_plugin_dir = null;

	/**
	 * Core's tags, then every plugin's, then any a test declared. Keyed by tag.
	 *
	 * @return array<string,array{label:string,internal:bool,default_view:bool}>
	 */
	public static function catalog(): array {
		if (self::$catalog !== null) return self::$catalog;

		$catalog = File::core_source_catalog();
		foreach (self::byPrecedence(self::manifests()) as $plugin => $declared) {
			foreach ($declared as $tag => $spec) {
				// Only an unusable tag or label drops a declaration: an internal
				// tag with a stray key must stay internal, never become listable.
				// errorsFor() still reports every shape problem.
				if (isset($catalog[$tag]) || !self::usable($tag, $spec)) {
					continue;
				}
				$catalog[$tag] = self::normalize($spec);
			}
		}
		foreach (self::$test_declarations as $tag => $spec) {
			if (!isset($catalog[$tag])) $catalog[$tag] = $spec;
		}

		self::$catalog = $catalog;
		return self::$catalog;
	}

	/**
	 * What is wrong with one plugin's declarations: a malformed entry, a tag core
	 * owns, or a tag another plugin also declares — any plugin on disk, or only
	 * those named in $against (activation checks against the active plugins, so
	 * a newcomer is refused rather than the plugin that already owns the tag).
	 * Empty when nothing is.
	 *
	 * @return string[]
	 */
	public static function errorsFor(string $plugin, ?array $against = null): array {
		$manifests = self::manifests();
		if (isset(self::$malformed[$plugin])) {
			return array('fileSources must be an object of tag => declaration');
		}
		$declared = $manifests[$plugin] ?? array();

		$core = File::core_source_catalog();
		$errors = array();
		foreach ($declared as $tag => $spec) {
			foreach (self::shapeErrors($tag, $spec) as $error) {
				$errors[] = "fileSources '{$tag}': {$error}";
			}
			if (isset($core[$tag])) {
				$errors[] = "fileSources '{$tag}': core already owns this tag";
			}
			foreach ($manifests as $other => $other_declared) {
				if ($other === $plugin) continue;
				if ($against !== null && !in_array($other, $against, true)) continue;
				if (array_key_exists($tag, $other_declared)) {
					$errors[] = "fileSources '{$tag}': also declared by plugin '{$other}'";
				}
			}
		}
		return $errors;
	}

	/**
	 * Declare a tag for the life of this process. Tests use it so a core test
	 * never depends on a plugin being installed; nothing outside a test process
	 * calls it, and a web request cannot.
	 *
	 * @throws LogicException outside the CLI, or for a tag already in the catalog
	 */
	public static function declareForTest(string $tag, array $spec): void {
		if (php_sapi_name() !== 'cli') {
			throw new LogicException('FileSourceDeclarations::declareForTest is for test processes only.');
		}
		$errors = self::shapeErrors($tag, $spec);
		if ($errors !== array()) {
			throw new LogicException("Test file source '{$tag}': " . implode('; ', $errors));
		}
		if (isset(self::catalog()[$tag])) {
			throw new LogicException("Test file source '{$tag}' is already in the catalog.");
		}
		self::$test_declarations[$tag] = self::normalize($spec);
		self::$catalog = null;
	}

	/**
	 * Read plugin manifests from $dir instead of plugins/ for the rest of this
	 * process, or from plugins/ again when $dir is null. Tests use it to stand up
	 * colliding and malformed manifests without touching the tree.
	 *
	 * @throws LogicException outside the CLI
	 */
	public static function usePluginDirForTest(?string $dir): void {
		if (php_sapi_name() !== 'cli') {
			throw new LogicException('FileSourceDeclarations::usePluginDirForTest is for test processes only.');
		}
		self::$test_plugin_dir = $dir;
		self::$catalog = null;
		self::$manifests = null;
		self::$malformed = array();
	}

	/** Drop the cached manifests (a sync or an activation changed them). */
	public static function reset(): void {
		self::$catalog = null;
		self::$manifests = null;
		self::$malformed = array();
	}

	/** reset(), and drop what a test process declared or pointed at. */
	public static function resetForTests(): void {
		self::reset();
		self::$test_declarations = array();
		self::$test_plugin_dir = null;
		self::$test_rank = null;
	}

	/** @var array|null plugin => installed time, standing in for plg_plugins in a test process */
	private static $test_rank = null;

	/**
	 * Treat these plugins as active, installed at these times, instead of reading
	 * plg_plugins — so a test can stand up a clash between active plugins.
	 *
	 * @param array<string,string> $rank plugin => installed time
	 * @throws LogicException outside the CLI
	 */
	public static function usePluginRankForTest(array $rank): void {
		if (php_sapi_name() !== 'cli') {
			throw new LogicException('FileSourceDeclarations::usePluginRankForTest is for test processes only.');
		}
		self::$test_rank = $rank;
		self::$catalog = null;
	}

	// ── internals ───────────────────────────────────────────────────────────

	/**
	 * plugin => its fileSources map, every plugin on disk, in directory order.
	 * A plugin whose fileSources is not an object is noted in $malformed instead.
	 */
	private static function manifests(): array {
		if (self::$manifests !== null) return self::$manifests;

		$out = array();
		self::$malformed = array();
		$plugin_dir = self::$test_plugin_dir ?? PathHelper::getIncludePath('plugins');
		foreach ((array)glob($plugin_dir . '/*/plugin.json') as $path) {
			$plugin = basename(dirname($path));
			$data = json_decode((string)@file_get_contents($path), true);
			if (!is_array($data) || !array_key_exists('fileSources', $data)) continue;
			$declared = $data['fileSources'];
			if (!is_array($declared) || ($declared !== array() && array_is_list($declared))) {
				self::$malformed[$plugin] = true;
				continue;
			}
			$out[$plugin] = $declared;
		}

		self::$manifests = $out;
		return self::$manifests;
	}

	/**
	 * Manifests in the order a tag two plugins claim is settled: active plugins
	 * first, earliest installed first — the plugin that already owned the tag
	 * keeps it when a newcomer claims it — then the rest by name.
	 */
	private static function byPrecedence(array $manifests): array {
		$rank = array();
		if (self::$test_rank !== null) {
			$rank = self::$test_rank;
		} else {
			try {
				$q = DbConnector::GetPreparedStatement('SELECT plg_name, plg_active, plg_installed_time FROM plg_plugins');
				$q->execute();
				foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
					if ((int)$row['plg_active'] === 1) {
						$rank[$row['plg_name']] = (string)($row['plg_installed_time'] ?? '9999');
					}
				}
			} catch (Throwable $e) {
				// No plugin table to read (a fresh install): directory order.
			}
		}
		uksort($manifests, function ($a, $b) use ($rank) {
			$ra = isset($rank[$a]);
			$rb = isset($rank[$b]);
			if ($ra !== $rb) {
				return $ra ? -1 : 1;
			}
			if ($ra && $rank[$a] !== $rank[$b]) {
				return strcmp($rank[$a], $rank[$b]);
			}
			return strcmp($a, $b);
		});
		return $manifests;
	}

	/** @return string[] */
	private static function shapeErrors($tag, $spec): array {
		$errors = array();
		if (!is_string($tag) || !preg_match(self::TAG_PATTERN, $tag)) {
			$errors[] = 'a tag is lowercase letters, digits and underscores, starting with a letter, at most 64 characters';
		}
		if (!is_array($spec)) {
			$errors[] = 'the declaration must be an object';
			return $errors;
		}
		if (!isset($spec['label']) || !is_string($spec['label']) || trim($spec['label']) === '') {
			$errors[] = "'label' is required";
		}
		foreach (array('internal', 'default_view') as $flag) {
			if (array_key_exists($flag, $spec) && !is_bool($spec[$flag])) {
				$errors[] = "'{$flag}' must be true or false";
			}
		}
		foreach (array_keys($spec) as $key) {
			if (!in_array($key, array('label', 'internal', 'default_view'), true)) {
				$errors[] = "unknown key '{$key}'";
			}
		}
		return $errors;
	}

	/** A tag and label good enough to list under, whatever else is wrong. */
	private static function usable($tag, $spec): bool {
		return is_string($tag) && preg_match(self::TAG_PATTERN, $tag)
			&& is_array($spec) && isset($spec['label']) && is_string($spec['label']) && trim($spec['label']) !== '';
	}

	private static function normalize(array $spec): array {
		return array(
			'label'        => (string)$spec['label'],
			'internal'     => !empty($spec['internal']),
			'default_view' => !empty($spec['default_view']),
		);
	}
}
