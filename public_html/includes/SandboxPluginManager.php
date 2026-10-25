<?php
/**
 * SandboxPluginManager — the sandboxed half of the plugin lifecycle.
 *
 * A sandboxed plugin is a row in plg_plugins like any plugin, installed,
 * activated, deactivated and uninstalled from the same Plugins page through
 * PluginManager. PluginManager hands the steps that read a native plugin's
 * PHP or plugin.json to this class when the plugin's folder is sandboxed
 * (SandboxPlugins::isSandboxed): validation, metadata, activation,
 * deactivation and the uninstall teardown. Everything common (the row, its
 * status, root removing the files) stays in PluginManager.
 *
 * What a sandboxed plugin owns on the site, and where each piece is made:
 *   - its model tables (SandboxModels), made on install and activation and
 *     dropped on uninstall;
 *   - its settings (SandboxSettings) and menu entries, made on activation;
 *   - what an admin approved it may do (SandboxConsent), which activation
 *     requires, and from that:
 *       - its API credential (SandboxCredentials), minted on activation,
 *         switched off on deactivation, deleted on uninstall;
 *       - its signal receiver (a `sandbox` SignalReceiver), for the signals
 *         approved, switched off on deactivation;
 *       - its scheduled runs (SandboxScheduledRun rows tagged with its name),
 *         suspended and resumed with it by PluginManager.
 *
 * reconcile() brings all of these up to the manifest as it stands, within
 * what was approved: on activation, on a new approval, and when an active
 * plugin's files are replaced.
 *
 * @version 1.1 - approval before activation, compatibility (Q13), credential, receiver, scheduled runs
 * @version 1.0
 */

class SandboxApprovalNeededException extends Exception {}

class SandboxPluginManager {

	/**
	 * What plg_metadata holds for a sandboxed plugin, in the keys the Plugins
	 * page reads from a native plugin.json, plus 'sandboxed' => true.
	 */
	public static function metadata(string $name): ?array {
		try {
			$m = SandboxPlugins::manifest($name);
		} catch (SandboxManifestException $e) {
			return null;
		}
		return array(
			'name'              => $m->title(),
			'version'           => $m->version(),
			'description'       => $m->description(),
			'author'            => $m->author(),
			'sandboxed'         => true,
			'platform'          => $m->platform(),
			'receives_upgrades' => false,
			'is_system'         => false,
		);
	}

	/**
	 * Whether a sandboxed plugin can be installed or activated here, in the
	 * shape PluginManager::validatePlugin() answers: its manifest is valid,
	 * and nothing it would create collides with something the site has.
	 *
	 * @return array{valid:bool,errors:string[],warnings:string[]}
	 */
	public static function validate(string $name): array {
		$errors = array();
		try {
			$manifest = SandboxPlugins::manifest($name);
		} catch (SandboxManifestException $e) {
			return array('valid' => false, 'errors' => array($e->getMessage()), 'warnings' => array());
		}
		$errors = array_merge($errors, self::modelCollisions($manifest), SandboxCompatibility::problems($manifest));
		// The module answers what the manifest says it will, and asks the
		// host for nothing it does not give (SandboxModule).
		try {
			$errors = array_merge($errors, SandboxModule::fromFile(SandboxPlugins::wasmPath($name))->problems($manifest));
		} catch (SandboxModuleException $e) {
			$errors[] = $e->getMessage();
		}
		return array('valid' => empty($errors), 'errors' => $errors, 'warnings' => array());
	}

	/**
	 * A model whose class name is taken by a class that is not this plugin's
	 * own. Derived names cannot meet a core, native or other sandboxed name
	 * (SandboxManifest), so this is a second line, not the first.
	 *
	 * @return string[]
	 */
	public static function modelCollisions(SandboxManifest $manifest): array {
		$errors = array();
		foreach ($manifest->models() as $model) {
			$class = SandboxManifest::classFor($manifest->name(), $model['name']);
			if (class_exists($class) && !(is_subclass_of($class, 'SandboxRecord') && $class::sandboxPlugin() === $manifest->name())) {
				$errors[] = "The model {$model['name']} would be the class $class, which this site already has.";
			}
		}
		return $errors;
	}

	/** The admin permission a sandboxed plugin's pages and settings need. */
	const ADMIN_PERMISSION = 8;

	/**
	 * The menus a sandboxed plugin declares, in the shape
	 * PluginManager::syncMenus() takes: one admin entry titled by its menu,
	 * holding a link to each of its admin pages. Who sees an entry is whoever
	 * may open admin_sandbox_page (MultiAdminMenu::visible_entries()).
	 */
	public static function menus(string $name): array {
		$out = array('admin' => array(), 'profile' => array());
		try {
			$m = SandboxPlugins::manifest($name);
		} catch (SandboxManifestException $e) {
			return $out;
		}
		$menu = $m->menu();
		if ($menu !== null && $m->adminPages()) {
			$items = array();
			foreach ($m->adminPages() as $i => $page) {
				$items[] = array(
					'slug'       => 'sbx-' . $name . '-' . $page['slug'],
					'title'      => $page['title'],
					'url'        => self::adminPageUrl($name, $page['slug']),
					'order'      => $i + 1,
				);
			}
			$out['admin'][] = array(
				'slug'       => 'sbx-' . $name,
				'title'      => $menu['title'],
				'icon'       => 'puzzle-piece',
				'order'      => 60,
				'items'      => $items,
			);
		}
		// Each approved profile page is an entry in the signed-in member's
		// menu (a member page is a grant: SandboxConsent). Its slug carries
		// '-p-' (page slugs hold no '-'), so an admin page and a profile page
		// of the same slug are two entries, not one.
		$approved_pages = SandboxConsent::effective($name)['member_pages'];
		foreach ($m->profilePages() as $i => $page) {
			if (!isset($approved_pages[$page['slug']])) {
				continue;
			}
			$out['profile'][] = array(
				'slug'       => self::profileMenuSlug($name, $page['slug']),
				'title'      => $page['title'],
				'url'        => SandboxPages::profilePageUrl($name, $page['slug']),
				'visibility' => 'in',
				'order'      => 70 + $i,
			);
		}
		return $out;
	}

	/** A profile page's member-menu slug: 'sbx-{plugin}-p-{page}', at most 57 characters. */
	public static function profileMenuSlug(string $name, string $slug): string {
		return 'sbx-' . $name . '-p-' . $slug;
	}

	public static function adminPageUrl(string $name, string $slug): string {
		return '/admin/admin_sandbox_page?plugin=' . rawurlencode($name) . '&page=' . rawurlencode($slug);
	}

	/** Seed the plugin's declared settings that have no row yet (SandboxSettings). */
	public static function syncSettings(string $name): void {
		SandboxSettings::seed($name);
	}

	/** Install: the plugin's model tables. */
	public static function install(string $name): void {
		self::syncTables($name);
	}

	/** Where an admin reviews and approves what a sandboxed plugin asks for. */
	public static function approvalUrl(string $name): string {
		return '/admin/admin_sandbox_approve?plugin=' . rawurlencode($name);
	}

	/**
	 * Activate: refused until an admin has approved everything the manifest
	 * asks for; then everything the plugin owns, brought up to date.
	 *
	 * @throws SandboxApprovalNeededException while it waits on an approval
	 * @throws Exception when the plugin cannot be activated
	 */
	public static function activate(string $name): void {
		$validation = self::validate($name);
		if (!$validation['valid']) {
			throw new Exception("Cannot activate plugin '$name': " . implode('; ', $validation['errors']));
		}
		if (SandboxConsent::needsApproval($name)) {
			throw new SandboxApprovalNeededException("Plugin '$name' asks for things no admin has approved yet. "
				. 'Review and approve them first: ' . self::approvalUrl($name));
		}
		self::reconcile($name);
	}

	/**
	 * Bring everything the plugin owns up to its manifest, within what was
	 * approved: tables, settings, credential, signal receiver, scheduled runs.
	 * Run on activation, on a new approval of an active plugin, and when an
	 * active plugin's files are replaced.
	 */
	public static function reconcile(string $name): void {
		self::syncTables($name);
		SandboxSettings::seed($name);
		SandboxPlugins::reset();
		SandboxModels::reset();
		SandboxCredentials::mint($name);
		self::syncReceiver($name);
		self::syncSchedules($name);
	}

	/**
	 * Deactivate: its credential and receiver are switched off (PluginManager
	 * suspends its runs). Nothing is removed; its data and approval stay for
	 * when it comes back.
	 */
	public static function deactivate(string $name): void {
		SandboxCredentials::suspend($name);
		foreach (new MultiSignalReceiver(array('kind' => SignalReceiver::KIND_SANDBOX, 'plugin' => $name)) as $receiver) {
			if ($receiver->get('sgr_is_active')) {
				$receiver->set('sgr_is_active', false);
				$receiver->save();
			}
		}
		SignalDeliveryQueue::reset_cache();
		SandboxPlugins::reset();
		SandboxModels::reset();
	}

	/**
	 * Uninstall teardown, before PluginManager marks the row uninstalled and
	 * asks root to remove the files (PluginManager deletes its runs): its
	 * credential and approval, its receiver with what it had queued, its
	 * settings, and its model tables with the deletion rules that named them.
	 */
	public static function teardown(string $name): void {
		SandboxCredentials::forget($name);
		foreach (new MultiSignalReceiver(array('kind' => SignalReceiver::KIND_SANDBOX, 'plugin' => $name)) as $receiver) {
			$receiver->permanent_delete();
		}
		SignalDeliveryQueue::reset_cache();
		SandboxSettings::unseed($name);
		SandboxModels::dropTables($name, self::recordedTables($name));
		DeletionRule::pruneOrphanedRules();
		SandboxPlugins::reset();
		SandboxModels::reset();
	}

	/**
	 * The plugin's signal receiver, carrying the approved signals; none when
	 * no signal is approved.
	 */
	public static function syncReceiver(string $name): void {
		$signals = SandboxConsent::effective($name)['signals'];
		$existing = null;
		foreach (new MultiSignalReceiver(array('kind' => SignalReceiver::KIND_SANDBOX, 'plugin' => $name)) as $receiver) {
			if ($existing === null && !empty($signals)) {
				$existing = $receiver;
			} else {
				$receiver->permanent_delete();
			}
		}
		if (!empty($signals)) {
			$receiver = $existing ?: new SignalReceiver(NULL);
			$receiver->set('sgr_kind', SignalReceiver::KIND_SANDBOX);
			$receiver->set('sgr_plugin', $name);
			$receiver->set('sgr_name', mb_substr('Sandboxed plugin: ' . SandboxPlugins::manifest($name)->title(), 0, 100));
			$receiver->set('sgr_signals', array_values($signals));
			$receiver->set('sgr_is_active', true);
			$receiver->save();
		}
		SignalDeliveryQueue::reset_cache();
	}

	/**
	 * One SandboxScheduledRun row per approved schedule, tagged with the
	 * plugin's name and set to the manifest's time; rows for schedules no
	 * longer approved or declared are removed.
	 */
	public static function syncSchedules(string $name): void {
		$approved = SandboxConsent::effective($name)['schedules'];
		$declared = array();
		foreach (SandboxPlugins::manifest($name)->schedules() as $s) {
			if (isset($approved[$s['name']])) {
				$declared[$s['name']] = $s;
			}
		}
		$title = SandboxPlugins::manifest($name)->title();
		$rows = new MultiScheduledTask(array('plugin_name' => $name, 'deleted' => false));
		$seen = array();
		foreach ($rows as $task) {
			if ($task->get('sct_task_class') !== 'SandboxScheduledRun') {
				continue;
			}
			$schedule = (string)($task->get_task_config()['schedule'] ?? '');
			if (!isset($declared[$schedule]) || isset($seen[$schedule])) {
				$task->permanent_delete();
				continue;
			}
			$seen[$schedule] = $task;
		}
		foreach ($declared as $schedule => $s) {
			$task = $seen[$schedule] ?? new ScheduledTask(NULL);
			$task->set('sct_name', mb_substr($title . ': ' . $schedule, 0, 255));
			$task->set('sct_task_class', 'SandboxScheduledRun');
			$task->set('sct_plugin_name', $name);
			$task->set('sct_frequency', $s['frequency']);
			$task->set('sct_schedule_time', isset($s['time']) ? $s['time'] . ':00' : '00:00:00');
			$task->set('sct_schedule_day_of_week', $s['frequency'] === 'weekly' ? (int)$s['day_of_week'] : null);
			$task->set('sct_task_config', json_encode(array('plugin' => $name, 'schedule' => $schedule)));
			if (!$task->key) {
				$task->set('sct_is_active', true);
			}
			$task->save();
		}
	}

	/**
	 * Create the plugin's model tables, add columns a newer manifest declares,
	 * and apply its indexes, through the same schema builder native plugins
	 * use. Columns a manifest stops declaring are kept (the builder never
	 * drops one here), as for a native plugin.
	 *
	 * @return string[] what changed, in words
	 * @throws Exception when a table cannot be made
	 */
	public static function syncTables(string $name): array {
		$classes = SandboxModels::classesFor($name);
		if (empty($classes)) {
			return array();
		}
		require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
		$updater = new DatabaseUpdater(false, true /* upgrade */, false);
		$result = $updater->runPluginTablesOnly($name);
		if (empty($result['success'])) {
			throw new Exception("Plugin '$name': its tables could not be made: " . implode('; ', $result['errors'] ?? array()));
		}
		$messages = $result['messages'] ?? array();
		$index_result = $updater->manageIndexes($classes);
		self::recordTables($name, $classes);
		return array_merge($messages, $index_result['messages'] ?? array());
	}

	/**
	 * Remember the tables made for a plugin, in its row's metadata under a
	 * runtime key (sync keeps '_' keys), so uninstall drops every one even
	 * after its manifest stops naming it. Before the row exists (a first
	 * install), the manifest still names them all.
	 */
	public static function recordTables(string $name, array $classes): void {
		$plugin = Plugin::get_by_plugin_name($name);
		if (!$plugin) {
			return;
		}
		$metadata = json_decode((string)$plugin->get('plg_metadata'), true);
		if (!is_array($metadata)) {
			$metadata = array();
		}
		$tables = is_array($metadata['_sandbox_tables'] ?? null) ? $metadata['_sandbox_tables'] : array();
		foreach ($classes as $class) {
			$tables[$class::$tablename] = $class::$pkey_column;
		}
		if ($tables === ($metadata['_sandbox_tables'] ?? null)) {
			return;
		}
		$metadata['_sandbox_tables'] = $tables;
		Plugin::updateColumns((int)$plugin->key, array('plg_metadata' => json_encode($metadata)));
	}

	/** @return array<string,string> table => id column, as recordTables() kept them */
	public static function recordedTables(string $name): array {
		$plugin = Plugin::get_by_plugin_name($name);
		$metadata = $plugin ? json_decode((string)$plugin->get('plg_metadata'), true) : null;
		return is_array($metadata['_sandbox_tables'] ?? null) ? $metadata['_sandbox_tables'] : array();
	}
}
