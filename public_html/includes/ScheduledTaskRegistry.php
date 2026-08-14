<?php
/**
 * ScheduledTaskRegistry
 *
 * Everything that needs to know what tasks exist on disk, independent of the
 * admin page. The cron runner, PluginManager and update_database all reconcile
 * the sct_scheduled_tasks table against the filesystem, and they must agree on
 * what "exists" means.
 *
 * Four jobs:
 *  - discover()          what task classes are on disk, with their JSON metadata
 *  - activateDeclared()  create rows for tasks flagged activate_on_install
 *  - retentionRules()    collect $retention_policy declarations from data classes
 *  - reconcileMissing()  retire rows whose code file is gone
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

class ScheduledTaskRegistry {

	/**
	 * How long a task's file must be continuously absent before the row retires.
	 *
	 * A file can be missing for a moment mid-deploy or during a plugin sync, so a
	 * single miss proves nothing. Four cron ticks is longer than any upgrade path
	 * leaves files absent, and a deploy skips the wait entirely (see
	 * reconcileMissing's $skip_grace).
	 */
	const RETIRE_GRACE_SECONDS = 3600;

	/**
	 * Discover tasks by scanning /tasks/ and plugin task directories.
	 *
	 * A task is a .php and a .json sharing a basename; the basename is the class
	 * name. Both must exist — a .php with no .json cannot be configured, and a
	 * .json with no .php cannot run.
	 *
	 * @return array  Keyed by class name: json, source, json_path, php_path
	 */
	public static function discover() {
		$tasks = array();

		$core_tasks_dir = PathHelper::getIncludePath('tasks');
		if (is_dir($core_tasks_dir)) {
			foreach (glob($core_tasks_dir . '/*.json') as $json_file) {
				$entry = self::_readTaskFiles($json_file, 'core');
				if ($entry) {
					$tasks[$entry['class_name']] = $entry['data'];
				}
			}
		}

		$plugins_dir = PathHelper::getIncludePath('plugins');
		if (is_dir($plugins_dir)) {
			foreach (glob($plugins_dir . '/*/tasks/*.json') as $json_file) {
				$path_parts = explode('/', dirname($json_file));
				$plugin_name = $path_parts[count($path_parts) - 2];
				$entry = self::_readTaskFiles($json_file, 'plugin:' . $plugin_name);
				if ($entry) {
					$tasks[$entry['class_name']] = $entry['data'];
				}
			}
		}

		return $tasks;
	}

	/**
	 * Read one task's .json/.php pair. Returns null if either is missing or the
	 * JSON does not parse — a malformed task is invisible rather than fatal.
	 */
	private static function _readTaskFiles($json_file, $source) {
		$class_name = basename($json_file, '.json');
		$php_file = dirname($json_file) . '/' . $class_name . '.php';
		if (!file_exists($php_file)) {
			return null;
		}
		$json_data = json_decode(file_get_contents($json_file), true);
		if (!$json_data) {
			return null;
		}
		return array(
			'class_name' => $class_name,
			'data' => array(
				'json' => $json_data,
				'source' => $source,
				'json_path' => $json_file,
				'php_path' => $php_file,
			),
		);
	}

	/**
	 * Create rows for tasks declaring "activate_on_install": true.
	 *
	 * SAFETY RULE: a row is created only when no row exists for that class at
	 * all, INCLUDING soft-deleted ones. Deactivating a task in admin soft-deletes
	 * its row; if that were ignored here, an operator who deliberately turned a
	 * task off would get it back on the next upgrade or plugin toggle with no way
	 * to make the removal stick. Uninstall permanently deletes a plugin's task
	 * rows, so a genuine reinstall still activates correctly.
	 *
	 * @param string $scope  'core', or a plugin name
	 * @return array  Names of tasks activated, for the caller to report
	 */
	public static function activateDeclared($scope) {
		$want_source = ($scope === 'core') ? 'core' : 'plugin:' . $scope;
		$activated = array();

		foreach (self::discover() as $class_name => $info) {
			if (($info['source'] ?? null) !== $want_source) {
				continue;
			}
			if (empty($info['json']['activate_on_install'])) {
				continue;
			}

			// No 'deleted' option — soft-deleted rows count as existing.
			$existing = new MultiScheduledTask(array('task_class' => $class_name));
			if ($existing->count_all() > 0) {
				continue;
			}

			$json_data = $info['json'];
			$task = new ScheduledTask(null);
			$task->set('sct_name', $json_data['name'] ?? $class_name);
			$task->set('sct_task_class', $class_name);
			$task->set('sct_is_active', true);
			if ($scope !== 'core') {
				$task->set('sct_plugin_name', $scope);
			}
			$task->set('sct_frequency', $json_data['default_frequency'] ?? 'daily');
			if (isset($json_data['default_day_of_week'])) {
				$task->set('sct_schedule_day_of_week', $json_data['default_day_of_week']);
			}
			if (isset($json_data['default_time'])) {
				$task->set('sct_schedule_time', $json_data['default_time']);
			}
			$task->save();

			$activated[] = $task->get('sct_name');
		}

		return $activated;
	}

	/**
	 * Collect every retention rule declared on a data class.
	 *
	 * The rule lives on the class that owns the table, next to the
	 * $foreign_key_actions that declare the rest of its deletion behavior.
	 * discover_model_classes() already walks core and plugin data classes and
	 * already knows how to drop inactive plugins, so a deactivated plugin stops
	 * contributing rules with no extra check here.
	 *
	 * @return array  Keyed by table name: class, policy
	 */
	public static function retentionRules() {
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

		$classes = LibraryFunctions::discover_model_classes(array(
			'require_tablename' => true,
			'include_plugins' => true,
			'plugin_status' => 'active',
		));

		$rules = array();
		foreach ($classes as $class) {
			if (!property_exists($class, 'retention_policy')) {
				continue;
			}
			// Statics are inherited, so a subclass would otherwise report its
			// parent's rule as a second rule against the same table.
			try {
				$declaring = (new ReflectionProperty($class, 'retention_policy'))
					->getDeclaringClass()->getName();
			} catch (ReflectionException $e) {
				continue;
			}
			if ($declaring !== $class) {
				continue;
			}

			$policy = $class::$retention_policy;
			if (!is_array($policy) || empty($policy)) {
				continue;
			}

			$rules[$class::$tablename] = array(
				'class' => $class,
				'policy' => $policy,
			);
		}

		ksort($rules);
		return $rules;
	}

	/**
	 * Retire task rows whose code file is gone.
	 *
	 * Absent code means the task retires. It never means an error, and it never
	 * stops the run — every upgrade is free to remove or rename a task, and a
	 * production site must absorb that quietly.
	 *
	 * The ladder:
	 *  - first miss              stamp sct_missing_since, change nothing else
	 *  - file returns            clear the stamp, no trace
	 *  - missing past the grace  deactivate, status 'retired', row PRESERVED
	 *
	 * Retirement never soft-deletes. The schedule and sct_task_config survive, so
	 * restoring the file and reactivating restores the operator's configuration
	 * exactly — being wrong about a rename costs a click, not a reconstruction.
	 *
	 * @param bool $skip_grace  True from update_database, where the filesystem is
	 *                          authoritative the moment the deploy finishes.
	 * @return array  retired, pending (names), for the caller to report
	 */
	public static function reconcileMissing($skip_grace = false) {
		$successors = self::_supersessionMap();

		$tasks = new MultiScheduledTask(
			array('active' => true, 'deleted' => false),
			array('sct_scheduled_task_id' => 'ASC')
		);
		$tasks->load();

		$retired = array();
		$pending = array();

		foreach ($tasks as $task) {
			$task_class = $task->get('sct_task_class');

			if ($task->resolve_task_file()) {
				// Present. Clear any stamp from an earlier transient miss.
				if ($task->get('sct_missing_since')) {
					$task->set('sct_missing_since', null);
					$task->save();
				}
				continue;
			}

			$missing_since = $task->get('sct_missing_since');

			if (!$skip_grace) {
				if (!$missing_since) {
					$task->set('sct_missing_since', 'now()');
					$task->save();
					$pending[] = $task->get('sct_name');
					continue;
				}
				if ((time() - strtotime($missing_since . ' UTC')) < self::RETIRE_GRACE_SECONDS) {
					$pending[] = $task->get('sct_name');
					continue;
				}
			}

			$message = isset($successors[$task_class])
				? 'Superseded by ' . $successors[$task_class]
				: 'Task code file is no longer present; the task has been retired';

			$task->set('sct_is_active', false);
			$task->set('sct_last_run_status', 'retired');
			$task->set('sct_last_run_message', mb_substr($message, 0, 500));
			$task->set('sct_missing_since', null);
			$task->save();

			$retired[] = $task->get('sct_name');
		}

		return array('retired' => $retired, 'pending' => $pending);
	}

	/**
	 * Map a removed task class to the display name of whatever absorbed it.
	 *
	 * Built from the "replaces" key that a task's JSON may declare. This is
	 * retirement metadata only — it never moves config between tasks and never
	 * activates anything. It exists so a retired row reads "Superseded by
	 * Retention Sweep" rather than a message implying something broke.
	 *
	 * @return array  old class name => successor display name
	 */
	private static function _supersessionMap() {
		$map = array();
		foreach (self::discover() as $class_name => $info) {
			$replaces = $info['json']['replaces'] ?? null;
			if (!is_array($replaces)) {
				continue;
			}
			$successor = $info['json']['name'] ?? $class_name;
			foreach ($replaces as $old_class) {
				$map[$old_class] = $successor;
			}
		}
		return $map;
	}

	/**
	 * The declared JSON metadata for one task class, or null if it isn't on
	 * disk. Used by the runner to read a task's run_on_success chain.
	 */
	public static function metadataFor(string $class_name): ?array {
		$all = self::discover();
		return isset($all[$class_name]) ? ($all[$class_name]['json'] ?? null) : null;
	}

	/**
	 * Fire a completed task's declared success-chain: any AI recipes named in
	 * its JSON under `run_on_success.recipes` (by rcp_declared_key) are queued
	 * to run right away, so work a task produces is picked up in seconds rather
	 * than at the recipe's own next scheduled tick.
	 *
	 * A general scheduled-tasks hook — any task may declare it, not just this
	 * plugin's. Deliberately conservative:
	 *   - does nothing unless joinery_ai's recipe machinery is present;
	 *   - only fires recipes that exist AND are enabled (never resurrects a
	 *     recipe the operator turned off, never runs one still unconfigured);
	 *   - skips a recipe that already has a pending/running run, so a burst of
	 *     task completions can't stack duplicate runs.
	 *
	 * Two sources feed the chain, unioned and deduped here:
	 *   - $metadata — the task's own JSON `run_on_success.recipes` (declared
	 *     keys), a plugin author shipping a task+recipe pair chained by default;
	 *   - $ui_recipe_ids — recipe ids an operator picked on the task's edit page
	 *     (stored in sct_task_config.run_on_success_recipes), which also covers
	 *     operator-created recipes that carry no declared key.
	 *
	 * @param  array|null $metadata        the task's JSON (from metadataFor())
	 * @param  int[]       $ui_recipe_ids   operator-selected recipe ids
	 * @return string[]                     labels of recipes actually queued
	 */
	public static function fireSuccessChain(?array $metadata, array $ui_recipe_ids = []): array {
		$slugs = $metadata['run_on_success']['recipes'] ?? [];
		if (!is_array($slugs)) $slugs = [];
		if (!$slugs && !$ui_recipe_ids) return [];

		// joinery_ai owns recipes; without it there is nothing to fire. The
		// name lookup triggers the autoloader when the plugin is active.
		if (!class_exists('Recipe') || !class_exists('RecipeRun') || !class_exists('RecipeWorkerSpawner')) {
			return [];
		}

		// Resolve both sources to recipe rows, keyed by id so a recipe named by
		// both a declared key and a UI pick fires only once.
		$candidates = [];
		foreach ($slugs as $slug) {
			$slug = trim((string)$slug);
			if ($slug === '') continue;
			$matches = new MultiRecipe(['declared_key' => $slug, 'deleted' => false]);
			foreach ($matches as $r) { $candidates[(int)$r->key] = $r; break; }
		}
		foreach ($ui_recipe_ids as $id) {
			$id = (int)$id;
			if ($id <= 0 || isset($candidates[$id])) continue;
			$r = new Recipe($id, true);
			if ($r->key) $candidates[$id] = $r;
		}

		$db = DbConnector::get_instance()->get_db_link();
		$fired = [];

		foreach ($candidates as $recipe) {
			// Never resurrect a recipe the operator turned off or left unconfigured.
			if (!$recipe->get('rcp_enabled')) continue;

			// Don't stack a second run on top of one already in flight.
			$active = $db->prepare(
				"SELECT 1 FROM rcr_recipe_runs
				 WHERE rcr_rcp_recipe_id = ? AND rcr_status IN (?, ?) AND rcr_delete_time IS NULL
				 LIMIT 1");
			$active->execute([(int)$recipe->key, RecipeRun::STATUS_PENDING, RecipeRun::STATUS_RUNNING]);
			if ($active->fetchColumn()) continue;

			$run = new RecipeRun(NULL);
			$run->set('rcr_rcp_recipe_id', (int)$recipe->key);
			$run->set('rcr_status', RecipeRun::STATUS_PENDING);
			$run->set('rcr_trigger', RecipeRun::TRIGGER_SCHEDULE);
			$run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
			$run->prepare();
			$run->save();
			RecipeWorkerSpawner::spawnIfUnderCap($run);
			$fired[] = (string)$recipe->get('rcp_name');
		}

		return $fired;
	}
}
