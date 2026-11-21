<?php
/**
 * PluginInstallIdentity — one id per install of a plugin, and the ledger of
 * what that install made (spec plugin_install_identity).
 *
 * A plugin's plg_plugins row outlives its uninstall and is taken back by the
 * next install of that name, so neither the name nor the row id tells one
 * install from the next. Every install gets an install id
 * (plg_plugins.plg_install_id): made when install finds no row or an
 * uninstalled one, kept while the plugin stays installed (upgrades,
 * activation, deactivation), never reused. What outlives an uninstall on
 * purpose (a sandboxed plugin's kept files, its call log) carries the id, so
 * a later install of the same name never reaches it.
 *
 * What should go at uninstall but is a name by nature (a setting, a table, a
 * credential purpose) is written in the ledger (PluginInstallLedgerEntry)
 * under the install id: snapshot() records what the install holds after each
 * step that can make something (install, activation, sync), and migrations'
 * new tables and sequences as they appear (recordNewRelations()). Uninstall
 * finishes the ledger (finish()): each thing is removed and its row struck
 * only once it is confirmed gone. Rows left over are what uninstall could not
 * remove; the next install of that name finishes them before it makes its own
 * id, or refuses (begin()).
 *
 * One owner per thing: a thing another install's ledger holds is refused
 * (record()), so finishing one ledger never removes what another uses.
 *
 * @version 1.1 - reviewer2 R1-R10: a sequence is its longest-named table's; nothing core or another installed
 *               plugin holds is removed; keys and approvals are scrubbed whatever their state
 * @version 1.0
 */
class PluginInstallIdentity {

	/** Kinds, in the order finish() removes them: credentials and grants first, the records last. */
	const KINDS = array(
		'sandbox_install', // the sbi_ row: approval, key, sealed secret
		'app_purpose',     // app credentials (apk_purpose) of a purpose its manifest declared
		'model_class',     // scope entries Class:verb on hand-made scoped keys
		'action',          // scope entries {plugin}/action on hand-made scoped keys (name: '{plugin}/')
		'signal_receiver',
		'task',
		'menu',            // amu_slug
		'setting',         // stg_name
		'deletion_rule',
		'table',
		'sequence',
		'migration',
		'version',
		'dependency',
	);

	/** Row kinds: the ledger names the row's id in the model's table. */
	const ROW_MODELS = array(
		'signal_receiver' => 'SignalReceiver',
		'task'            => 'ScheduledTask',
		'deletion_rule'   => 'DeletionRule',
		'migration'       => 'PluginMigration',
		'version'         => 'PluginVersion',
		'dependency'      => 'PluginDependency',
		'sandbox_install' => 'SandboxInstall',
	);

	const ID_PATTERN = '/^[0-9a-f]{32}$/';

	const SQL_NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	public static function newId(): string {
		return bin2hex(random_bytes(16));
	}

	/** The install id on the plugin's row, or null (no row, or none yet). */
	public static function idOf(string $name): ?string {
		$row = Plugin::get_by_plugin_name($name);
		$id = $row ? (string)$row->get('plg_install_id') : '';
		return preg_match(self::ID_PATTERN, $id) ? $id : null;
	}

	/**
	 * The id an install of $name runs under, for install() to set on $row.
	 * A row that is new or uninstalled is a fresh install: the earlier
	 * install's ledger is finished first (refused, naming what is left, when
	 * it cannot be), a sandboxed plugin's leftovers under its own prefix are
	 * removed (D6), and a new id is made. An installed row keeps its id; one
	 * from before ids existed gets one.
	 *
	 * @throws Exception when the earlier install left things that cannot be removed
	 */
	public static function begin(Plugin $row, string $name): string {
		$old = (string)$row->get('plg_install_id');
		$old = preg_match(self::ID_PATTERN, $old) ? $old : null;
		$fresh = !$row->key || $row->is_uninstalled();
		if (!$fresh) {
			return $old ?? self::newId();
		}
		// The row's earlier install, and any other ledger under this name (a
		// row removed by hand leaves its ledger behind): all earlier installs.
		$earlier = $old !== null ? array($old) : array();
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT DISTINCT pil_install_id FROM pil_plugin_install_ledger WHERE pil_plugin = ?');
		$stmt->execute(array($name));
		$earlier = array_unique(array_merge($earlier, $stmt->fetchAll(PDO::FETCH_COLUMN)));
		$left = array();
		foreach ($earlier as $earlier_id) {
			$left = array_merge($left, self::finish((string)$earlier_id));
		}
		if (!empty($left)) {
			throw new Exception("The earlier install of '$name' left things that could not be removed: "
				. implode(', ', $left) . '. Remove them, then install again.');
		}
		if (SandboxPlugins::isSandboxed($name)) {
			self::removeSandboxLeftovers($name);
		}
		return self::newId();
	}

	/**
	 * Existing installs (design 4): every installed plugin without an id gets
	 * one, its ledger is filled from what it holds now, and what it kept
	 * before ids existed is stamped with it: a sandboxed plugin's files
	 * (fil_source sbx_{name}), its call log, and its content-pack apply
	 * records since it was installed. Rows of plugins already uninstalled are
	 * left alone (D5). Idempotent.
	 *
	 * @return string[] one line per plugin
	 */
	public static function backfill(): array {
		$dblink = DbConnector::get_instance()->get_db_link();
		$rows = $dblink->query("SELECT plg_plugin_id, plg_name, plg_install_id, plg_installed_time FROM plg_plugins
			WHERE plg_status IS DISTINCT FROM 'uninstalled' ORDER BY plg_plugin_id")->fetchAll(PDO::FETCH_ASSOC);
		$lines = array();
		foreach ($rows as $r) {
			$name = (string)$r['plg_name'];
			$id = (string)$r['plg_install_id'];
			if (!preg_match(self::ID_PATTERN, $id)) {
				$id = self::newId();
				$dblink->prepare('UPDATE plg_plugins SET plg_install_id = ? WHERE plg_plugin_id = ?')->execute(array($id, (int)$r['plg_plugin_id']));
			}
			try {
				self::snapshot($name, $id);
			} catch (Throwable $e) {
				$lines[] = "$name: ledger not filled: " . $e->getMessage();
			}
			$dblink->prepare('UPDATE fil_files SET fil_install_id = ? WHERE fil_source = ? AND fil_install_id IS NULL')
				->execute(array($id, 'sbx_' . $name));
			$dblink->prepare('UPDATE sbl_sandbox_logs SET sbl_install_id = ? WHERE sbl_plugin = ? AND sbl_install_id IS NULL')
				->execute(array($id, $name));
			$dblink->prepare("UPDATE cpa_content_pack_applies SET cpa_source = cpa_source || '@' || ?
				WHERE cpa_source LIKE ? AND position('@' in cpa_source) = 0 AND cpa_applied_time >= COALESCE(?::timestamp, '1970-01-01')")
				->execute(array($id, 'plugin:' . str_replace('_', '\\_', $name) . '/%', $r['plg_installed_time']));
			$lines[] = "$name: install id $id";
		}
		return $lines;
	}

	/**
	 * Why a package must not go into plugins/$name now, or ''. Asked by the
	 * web side when a package is staged and by root before it moves one into
	 * the tree:
	 *
	 *   - the earlier plugin of that name is still waiting for root to remove
	 *     its files: until install makes the new id, that request still names
	 *     the row's id and would remove the new files (A10, I9);
	 *   - a sandboxed package over an installed sandboxed plugin whose
	 *     manifest id differs: a different plugin, not an upgrade (D4). An
	 *     installed copy from before ids existed takes the package's.
	 */
	public static function installRefusal(string $name, string $installed_dir, string $new_dir): string {
		if (self::removalPending($name)) {
			return "The earlier plugin named '$name' is still being removed. Install this one once that has finished (a minute or two).";
		}
		// Only over an installed plugin: an uninstalled one whose files root has
		// not removed is no plugin to tell this package apart from.
		$row = Plugin::get_by_plugin_name($name);
		if ($row && !$row->is_uninstalled() && is_dir($installed_dir)
				&& SandboxPlugins::isSandboxedDir($installed_dir) && SandboxPlugins::isSandboxedDir($new_dir)) {
			$old = self::manifestId($installed_dir);
			if ($old !== null && self::manifestId($new_dir) !== $old) {
				return "A different plugin named '$name' is installed (its id is not this package's). Uninstall it first.";
			}
		}
		return '';
	}

	/** Whether plugins/$name's removal (its uninstall's root request) has not run yet. */
	public static function removalPending(string $name): bool {
		$row = Plugin::get_by_plugin_name($name);
		if (!$row || !$row->is_uninstalled()) {
			return false;
		}
		$request = $row->get_remove_request_id();
		if ($request === '') {
			return false;
		}
		// The runner moves a request between its folders with mv, a rename on one
		// filesystem and copy-then-delete across two, so it is never in none of
		// them: 'unknown' is a request that is gone (pruned), not one in flight.
		return in_array(RootRequest::status($request)['state'], array('queued', 'running'), true);
	}

	/** A sandboxed folder's manifest id, or null when it has none. */
	private static function manifestId(string $dir): ?string {
		$data = json_decode((string)@file_get_contents($dir . '/' . SandboxManifest::FILE), true);
		$id = is_array($data) ? ($data['id'] ?? null) : null;
		return is_string($id) && preg_match(SandboxManifest::ID_PATTERN, $id) ? $id : null;
	}

	/**
	 * Record in $id's ledger what the plugin $name holds now. Called inside
	 * the transaction of each step that can make something, so a step that
	 * rolls back records nothing.
	 *
	 * @throws Exception when another install's ledger holds one of them
	 */
	public static function snapshot(string $name, ?string $id = null): void {
		$id = $id ?? self::idOf($name);
		if ($id === null) {
			return;
		}
		foreach (self::holdings($name) as $kind => $names) {
			self::record($id, $name, $kind, $names);
		}
	}

	/**
	 * Add things to $id's ledger. A thing in another install's ledger is
	 * refused, naming that plugin: two ledgers never cover one thing.
	 *
	 * @param string[] $names
	 * @throws Exception
	 */
	public static function record(string $id, string $name, string $kind, array $names): void {
		if (!in_array($kind, self::KINDS, true)) {
			throw new InvalidArgumentException("Unknown ledger kind '$kind'");
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$holder = $dblink->prepare('SELECT pil_plugin FROM pil_plugin_install_ledger
			WHERE pil_kind = ? AND pil_name = ? AND pil_install_id <> ? LIMIT 1');
		$insert = $dblink->prepare('INSERT INTO pil_plugin_install_ledger (pil_install_id, pil_plugin, pil_kind, pil_name)
			VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING');
		foreach (array_unique(array_map('strval', $names)) as $thing) {
			if ($thing === '') {
				continue;
			}
			$holder->execute(array($kind, $thing, $id));
			$other = $holder->fetchColumn();
			if ($other !== false) {
				throw new Exception("Plugin '$name' would own the $kind '$thing', which belongs to plugin '$other'.");
			}
			$insert->execute(array($id, $name, $kind, $thing));
		}
	}

	/**
	 * Every table and sequence in the schema, for recordNewRelations().
	 *
	 * @return array<string,string> name => 'table' or 'sequence'
	 */
	public static function relations(): array {
		$rows = DbConnector::get_instance()->get_db_link()->query(
			"SELECT c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
			 WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'S')")->fetchAll(PDO::FETCH_ASSOC);
		$out = array();
		foreach ($rows as $r) {
			$out[(string)$r['relname']] = $r['relkind'] === 'S' ? 'sequence' : 'table';
		}
		return $out;
	}

	/**
	 * The tables and sequences made since $before (relations()), into $id's
	 * ledger: what a migration made, which no class file names.
	 */
	public static function recordNewRelations(string $name, string $id, array $before): void {
		self::$elsewhere = null;
		$made = array('table' => array(), 'sequence' => array());
		foreach (array_diff_key(self::relations(), $before) as $rel => $kind) {
			// A relation another process made meanwhile (update_database making a
			// core table) is not the migration's.
			if (self::ownedElsewhere($kind, $rel, $name)) {
				continue;
			}
			$made[$kind][] = $rel;
		}
		foreach ($made as $kind => $names) {
			if (!empty($names)) {
				self::record($id, $name, $kind, $names);
			}
		}
	}

	/** @return array<int,array{kind:string,name:string,plugin:string}> $id's ledger rows, in removal order */
	public static function entries(string $id): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT pil_kind, pil_name, pil_plugin FROM pil_plugin_install_ledger WHERE pil_install_id = ? ORDER BY pil_plugin_install_ledger_id');
		$stmt->execute(array($id));
		$rows = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$rows[] = array('kind' => (string)$r['pil_kind'], 'name' => (string)$r['pil_name'], 'plugin' => (string)$r['pil_plugin']);
		}
		$order = array_flip(self::KINDS);
		usort($rows, function ($a, $b) use ($order) {
			return ($order[$a['kind']] ?? 99) <=> ($order[$b['kind']] ?? 99);
		});
		return $rows;
	}

	/**
	 * Remove everything in $id's ledger, striking each row once its thing is
	 * confirmed gone.
	 *
	 * @return string[] what is left, as 'kind name', empty when all of it went
	 */
	public static function finish(string $id): array {
		self::$elsewhere = null;
		$dblink = DbConnector::get_instance()->get_db_link();
		$strike = $dblink->prepare('DELETE FROM pil_plugin_install_ledger WHERE pil_install_id = ? AND pil_kind = ? AND pil_name = ?');
		$left = array();
		foreach (self::entries($id) as $e) {
			try {
				$gone = self::remove($e['kind'], $e['name'], $e['plugin']);
			} catch (Throwable $t) {
				error_log("[PluginInstallIdentity] could not remove {$e['kind']} '{$e['name']}' of '{$e['plugin']}': " . $t->getMessage());
				$gone = false;
			}
			if ($gone) {
				$strike->execute(array($id, $e['kind'], $e['name']));
			} else {
				$left[] = $e['kind'] . ' ' . $e['name'];
			}
		}
		return $left;
	}

	/**
	 * What plugin $name holds now, by kind. Read by name, which is right
	 * while it is installed: everything under its name is this install's,
	 * and a leftover of an earlier one was finished or removed by begin().
	 *
	 * @return array<string,string[]>
	 */
	public static function holdings(string $name): array {
		$out = array_fill_keys(self::KINDS, array());
		$sandboxed = SandboxPlugins::isSandboxed($name);
		$manager = PluginManager::getInstance();
		$manifest = null;
		try {
			$manifest = $manager->liveManifest($name);
		} catch (Throwable $e) {
			$manifest = null;
		}

		// Settings and credential purposes it declares.
		try {
			$declared = $sandboxed
				? SandboxSettings::declarationsFor(SandboxPlugins::manifest($name))
				: PluginHelper::getInstance($name)->getDeclaredSettings();
			foreach ((array)$declared as $d) {
				if (is_array($d) && is_string($d['name'] ?? null)) {
					$out['setting'][] = $d['name'];
				}
			}
		} catch (Throwable $e) {
			// No readable manifest: nothing declared to record.
		}
		if (is_array($manifest) && is_array($manifest['appAccess'] ?? null)) {
			foreach (array_keys($manifest['appAccess']) as $purpose) {
				if (preg_match(AppAccess::NAME_PATTERN, (string)$purpose)) {
					$out['app_purpose'][] = (string)$purpose;
				}
			}
		}

		// Tables and model classes.
		$tables = array();
		if ($sandboxed) {
			foreach (SandboxModels::classesFor($name) as $class) {
				$out['model_class'][] = $class;
				$tables[] = $class::$tablename;
			}
			$tables = array_merge($tables, array_keys(SandboxPluginManager::recordedTables($name)));
		} else {
			$data_dir = PathHelper::getAbsolutePath("plugins/{$name}/data");
			foreach (is_dir($data_dir) ? glob($data_dir . '/*_class.php') : array() as $file) {
				$content = (string)file_get_contents($file);
				if (preg_match('/\$tablename\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
					$tables[] = $m[1];
				}
				if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+SystemBase\b/m', $content, $mm)) {
					$out['model_class'] = array_merge($out['model_class'], $mm[1]);
				}
			}
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$exists = $dblink->prepare('SELECT to_regclass(?) IS NOT NULL');
		$seqs = $dblink->prepare("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
			WHERE n.nspname = current_schema() AND c.relkind = 'S' AND c.relname LIKE ?");
		foreach (array_unique($tables) as $table) {
			if (!preg_match(self::SQL_NAME, $table)) {
				continue;
			}
			$exists->execute(array($table));
			if (!$exists->fetchColumn()) {
				continue;
			}
			$out['table'][] = $table;
			$seqs->execute(array(str_replace('_', '\\_', $table) . '\\_%'));
			foreach ($seqs->fetchAll(PDO::FETCH_COLUMN) as $seq) {
				// abc_things_archive_id_seq is abc_things_archive's, not abc_things'.
				if (self::tableOfSequence((string)$seq) === $table) {
					$out['sequence'][] = $seq;
				}
			}
		}
		$out['action'][] = $name . '/';

		// Menu slugs it was given.
		$row = Plugin::get_by_plugin_name($name);
		$meta = $row ? json_decode((string)$row->get('plg_metadata'), true) : null;
		if (is_array($meta) && is_array($meta['_menu_slugs'] ?? null)) {
			$out['menu'] = array_values(array_filter($meta['_menu_slugs'], 'is_string'));
		}

		// Records under its name.
		foreach (array(
			'task'            => array('SELECT sct_scheduled_task_id FROM sct_scheduled_tasks WHERE sct_plugin_name = ?'),
			'version'         => array('SELECT plv_plugin_version_id FROM plv_plugin_versions WHERE plv_plugin_name = ?'),
			'dependency'      => array('SELECT pld_plugin_dependency_id FROM pld_plugin_dependencies WHERE pld_plugin_name = ?'),
			'migration'       => array('SELECT plm_plugin_migration_id FROM plm_plugin_migrations WHERE plm_plugin_name = ?'),
			'deletion_rule'   => array('SELECT del_deletion_rule_id FROM del_deletion_rules WHERE del_plugin = ?'),
			'signal_receiver' => array('SELECT sgr_signal_receiver_id FROM sgr_signal_receivers WHERE sgr_plugin = ?'),
			'sandbox_install' => array('SELECT sbi_sandbox_install_id FROM sbi_sandbox_installs WHERE sbi_plugin = ?'),
		) as $kind => $q) {
			$stmt = $dblink->prepare($q[0]);
			$stmt->execute(array($name));
			$out[$kind] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		}
		return array_filter($out);
	}

	/**
	 * Remove one thing, and say whether it is gone. Gone already is gone.
	 */
	private static function remove(string $kind, string $thing, string $plugin): bool {
		$dblink = DbConnector::get_instance()->get_db_link();
		// A thing a later version handed to core or to another plugin is no
		// longer this install's to remove: struck, left in place.
		if (in_array($kind, array('setting', 'table', 'sequence', 'menu'), true) && self::ownedElsewhere($kind, $thing, $plugin)) {
			return true;
		}
		if (isset(self::ROW_MODELS[$kind])) {
			$class = self::ROW_MODELS[$kind];
			if (!ctype_digit($thing)) {
				return true;
			}
			$count = $dblink->prepare('SELECT COUNT(*) FROM ' . $class::$tablename . ' WHERE ' . $class::$pkey_column . ' = ?');
			$count->execute(array((int)$thing));
			if ((int)$count->fetchColumn() === 0) {
				return true;
			}
			if ($kind === 'sandbox_install') {
				$row = new SandboxInstall((int)$thing, TRUE);
				if ($row->key && (string)$row->get('sbi_plugin') === $plugin) {
					SandboxCredentials::forget($plugin);
				} elseif ($row->key) {
					$row->permanent_delete();
				}
			} else {
				$row = new $class((int)$thing, TRUE);
				if ($row->key) {
					$row->permanent_delete();
				}
			}
			$count->execute(array((int)$thing));
			return (int)$count->fetchColumn() === 0;
		}
		switch ($kind) {
			case 'setting':
				$dblink->prepare('DELETE FROM stg_settings WHERE stg_name = ?')->execute(array($thing));
				$stmt = $dblink->prepare('SELECT COUNT(*) FROM stg_settings WHERE stg_name = ?');
				$stmt->execute(array($thing));
				return (int)$stmt->fetchColumn() === 0;
			case 'app_purpose':
				// A purpose an active plugin declares is that plugin's to keep.
				if (AppAccess::isDeclared($thing)) {
					return true;
				}
				foreach (new MultiApiKey(array('purpose' => $thing)) as $key) {
					$key->permanent_delete();
				}
				return count(new MultiApiKey(array('purpose' => $thing))) === 0;
			case 'model_class':
			case 'action':
				return self::scrubScopes($kind, $thing);
			case 'menu':
				$dblink->prepare('DELETE FROM amu_admin_menus WHERE amu_slug = ?')->execute(array($thing));
				$stmt = $dblink->prepare('SELECT COUNT(*) FROM amu_admin_menus WHERE amu_slug = ?');
				$stmt->execute(array($thing));
				return (int)$stmt->fetchColumn() === 0;
			case 'table':
			case 'sequence':
				if (!preg_match(self::SQL_NAME, $thing)) {
					return true;
				}
				$dblink->exec(($kind === 'table' ? 'DROP TABLE IF EXISTS ' : 'DROP SEQUENCE IF EXISTS ') . $thing . ' CASCADE');
				$stmt = $dblink->prepare('SELECT to_regclass(?) IS NULL');
				$stmt->execute(array($thing));
				return (bool)$stmt->fetchColumn();
		}
		return false;
	}

	/**
	 * Take a gone plugin's model class or actions out of every sandboxed
	 * plugin's approval (R9): a key's scope is rebuilt from the approval at
	 * each mint, so a later model of the same class name would otherwise be
	 * reached on the old approval. A plugin that still asks for it shows it as
	 * waiting for approval.
	 */
	private static function scrubConsents(string $kind, string $thing): void {
		foreach (new MultiSandboxInstall(array()) as $install) {
			$consent = $install->consent();
			$changed = false;
			if ($kind === 'model_class') {
				foreach (array('records', 'own_records') as $part) {
					if (isset($consent[$part][$thing])) {
						unset($consent[$part][$thing]);
						$changed = true;
					}
				}
			} elseif (isset($consent['actions']) && is_array($consent['actions'])) {
				$kept = array_values(array_filter($consent['actions'], function ($a) use ($thing) {
					return strpos((string)$a, $thing) !== 0;
				}));
				if ($kept !== $consent['actions']) {
					$consent['actions'] = $kept;
					$changed = true;
				}
			}
			if ($changed) {
				SandboxInstall::updateColumns((int)$install->key, array('sbi_consent' => json_encode($consent)));
				error_log("[PluginInstallIdentity] approval of sandboxed plugin '" . $install->get('sbi_plugin') . "': removed $kind '$thing'");
			}
		}
	}

	/** @var array<string,array<string,true>>|null kind => things core or another installed plugin holds, for one finish() */
	private static $elsewhere = null;

	/**
	 * Whether core or another installed plugin owns $thing: core's settings
	 * (settings.json), tables (core data classes) and menu slugs
	 * (admin_menus.json), and what another installed plugin holds now
	 * (holdings()). A sequence is owned with its table.
	 */
	private static function ownedElsewhere(string $kind, string $thing, string $plugin): bool {
		if ($kind === 'sequence') {
			$table = self::tableOfSequence($thing);
			return $table !== null && self::ownedElsewhere('table', $table, $plugin);
		}
		if ($kind === 'setting') {
			$d = SettingsDeclarations::get($thing);
			if ($d !== null && ($d['_source'] ?? '') !== $plugin) {
				return true;
			}
		}
		if (self::$elsewhere === null) {
			self::$elsewhere = array('table' => array(), 'menu' => array(), 'setting' => array());
			foreach (LibraryFunctions::discover_model_classes(array('require_tablename' => true, 'include_plugins' => false)) as $class) {
				self::$elsewhere['table'][$class::$tablename] = true;
			}
			$menus = json_decode((string)@file_get_contents(PathHelper::getIncludePath('admin_menus.json')), true);
			array_walk_recursive($menus, function ($value, $key) {
				if ($key === 'slug' && is_string($value)) {
					self::$elsewhere['menu'][$value] = true;
				}
			});
			self::$elsewhere['others'] = array();
		}
		if (isset(self::$elsewhere[$kind][$thing])) {
			return true;
		}
		// Another installed plugin's holdings, read once per plugin per finish().
		$rows = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT plg_name FROM plg_plugins WHERE plg_status IS DISTINCT FROM 'uninstalled' AND plg_name <> ?");
		$rows->execute(array($plugin));
		foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $other) {
			if (!isset(self::$elsewhere['others'][$other])) {
				try {
					self::$elsewhere['others'][$other] = self::holdings((string)$other);
				} catch (Throwable $e) {
					self::$elsewhere['others'][$other] = array();
				}
			}
			if (in_array($thing, self::$elsewhere['others'][$other][$kind] ?? array(), true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The table a sequence belongs to: the longest existing table name it
	 * starts with plus '_'. Once its own table is dropped, a shorter-named one
	 * can answer instead; a sequence then taken for core's is left in place, an
	 * orphan, never another table's dropped.
	 */
	public static function tableOfSequence(string $seq): ?string {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
			 WHERE n.nspname = current_schema() AND c.relkind = 'r' AND ? LIKE replace(c.relname, '_', '\\_') || '\\_%'
			 ORDER BY length(c.relname) DESC LIMIT 1");
		$stmt->execute(array($seq));
		$table = $stmt->fetchColumn();
		return $table === false ? null : (string)$table;
	}

	/**
	 * Take a gone plugin's model class or actions out of every scoped API key
	 * (A11): a hand-made key naming them would otherwise reach a later
	 * plugin's model of that class name. Each key changed is logged.
	 */
	private static function scrubScopes(string $kind, string $thing): bool {
		self::scrubConsents($kind, $thing);
		$dblink = DbConnector::get_instance()->get_db_link();
		$like = $kind === 'action' ? '%' . $thing . '%' : '%' . $thing . ':%';
		// Every key, on or off: one switched back on must not reach a later
		// plugin's model of the same name.
		$stmt = $dblink->prepare("SELECT apk_api_key_id FROM apk_api_keys WHERE apk_scope LIKE ?");
		$stmt->execute(array($like));
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key_id) {
			$key = new ApiKey((int)$key_id, TRUE);
			if (!$key->key) {
				continue;
			}
			$scope = $key->scope();
			$kept = array_values(array_filter($scope, function ($entry) use ($kind, $thing) {
				return $kind === 'action' ? strpos($entry, $thing) !== 0 : strpos($entry, $thing . ':') !== 0;
			}));
			if ($kept === $scope) {
				continue;
			}
			// An emptied scope would read as unscoped (ApiKey::scope()), which
			// reaches everything: a key with nothing else in its scope is
			// switched off, and its scope is the one entry '-', an action no
			// code can have, so switched back on it reaches nothing.
			if (empty($kept)) {
				ApiKey::updateColumns((int)$key->key, array('apk_scope' => '-', 'apk_is_active' => false));
				error_log("[PluginInstallIdentity] API key {$key->key}: switched off; its scope named only $kind '$thing' of a plugin that is gone");
			} else {
				ApiKey::updateColumns((int)$key->key, array('apk_scope' => implode(',', $kept)));
				error_log("[PluginInstallIdentity] API key {$key->key}: removed the scope entries for $kind '$thing'");
			}
		}
		return true;
	}

	/**
	 * D6: a fresh sandboxed install's own prefixed settings and tables
	 * (sbx_{name}__...) that no ledger holds can only be an earlier same-name
	 * install's; they are removed, so they are remade, not inherited.
	 */
	private static function removeSandboxLeftovers(string $name): void {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $name)) {
			return;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$prefix = str_replace('_', '\\_', 'sbx_' . $name . '__') . '%';
		$held = $dblink->prepare('SELECT 1 FROM pil_plugin_install_ledger WHERE pil_kind = ? AND pil_name = ? LIMIT 1');
		$stmt = $dblink->prepare('SELECT stg_name FROM stg_settings WHERE stg_name LIKE ?');
		$stmt->execute(array($prefix));
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $setting) {
			$held->execute(array('setting', $setting));
			if (!$held->fetchColumn()) {
				self::remove('setting', (string)$setting, $name);
			}
		}
		$stmt = $dblink->prepare("SELECT c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
			WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'S') AND c.relname LIKE ? ORDER BY c.relkind DESC");
		$stmt->execute(array($prefix));
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rel) {
			$kind = $rel['relkind'] === 'S' ? 'sequence' : 'table';
			$held->execute(array($kind, $rel['relname']));
			if (!$held->fetchColumn()) {
				self::remove($kind, (string)$rel['relname'], $name);
			}
		}
	}
}
