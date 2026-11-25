<?php
/**
 * SandboxBlockingRows — the rows of a sandboxed plugin that stop a site record
 * being deleted, and the way an admin removes them (spec
 * sandboxed_plugin_model_files section 8).
 *
 * A sandboxed plugin's model may declare a prevent rule on a link to a site
 * record (a note holding its user), as a first-party model may (D5). What
 * makes that safe is this: an admin can always see the rows that block and
 * delete them, from the refused delete or from the plugin's row on the
 * Plugins page, whether the plugin is active, inactive, or its folder gone.
 * Everything here reads the declarations stored on the plugin's row
 * (SandboxModels), never its files.
 *
 * The page is admin_sandbox_blocks: with ?plugin= alone it lists the
 * plugin's blocking links, each with how many of its rows hold a link now;
 * with &model=&column= (and &id= for one record) it lists those rows, each
 * with a Delete permanently button, at the model's permanent delete level.
 * A delete is permanent_delete() on the site's own class, so the plugin's
 * own rules apply (its children cascade, its own prevents refuse).
 *
 * @version 1.0
 */

class SandboxBlockingRows {

	/** At most this many rows on the page at once. */
	const PAGE_ROWS = 200;

	/**
	 * The plugin's blocking links that are in force: each prevent on a site
	 * table the approval covers, and each prevent between its own models.
	 *
	 * @return array<int,array{model:string, column:string, source_table:string, class:string, count:int, url:string}>
	 */
	public static function links(string $plugin): array {
		$out = array();
		$declarations = SandboxModels::declarations($plugin);
		if (!$declarations) {
			return $out;
		}
		$approved = array_flip(SandboxConsent::effectiveModels($plugin)['blocks']);
		$classes = array_flip(SandboxModels::classesFor($plugin));
		$carried = SandboxConsent::blockEntries($plugin, $declarations);
		foreach ($declarations as $name => $declaration) {
			$class = SandboxManifest::classFor($plugin, (string)$name);
			if (!isset($classes[$class])) {
				continue;
			}
			foreach ((array)$declaration['foreign_key_actions'] as $column => $rule) {
				if (($rule['action'] ?? '') !== 'prevent') {
					continue;
				}
				if (!SandboxConsent::ruleApproved($carried[(string)$name][(string)$column] ?? array(), array_keys($approved))) {
					continue;
				}
				$out[] = array(
					'model'        => (string)$name,
					'column'       => (string)$column,
					'source_table' => (string)$rule['source_table'],
					'class'        => $class,
					'count'        => self::count($class, (string)$column, null),
					'url'          => self::url($plugin, (string)$name, (string)$column),
				);
			}
		}
		return $out;
	}

	/** The page's address: the plugin's links, or one link's rows (for one record). */
	public static function url(string $plugin, ?string $model = null, ?string $column = null, ?int $id = null): string {
		$url = '/admin/admin_sandbox_blocks?plugin=' . rawurlencode($plugin);
		if ($model !== null && $column !== null) {
			$url .= '&model=' . rawurlencode($model) . '&column=' . rawurlencode($column);
			if ($id !== null) {
				$url .= '&id=' . $id;
			}
		}
		return $url;
	}

	/**
	 * The page for a blocker a dry run reported ({table, column, key}), when
	 * the rows are a sandboxed plugin's; null otherwise.
	 */
	public static function urlForBlocker(array $blocker): ?string {
		$table = (string)($blocker['table'] ?? '');
		if (strncmp($table, 'sbx_', 4) !== 0) {
			return null;
		}
		foreach (SandboxModels::installedWithModels() as $plugin) {
			if (strpos($table, 'sbx_' . $plugin . '__') !== 0) {
				continue;
			}
			foreach (SandboxModels::declarations($plugin) as $name => $declaration) {
				if (($declaration['table'] ?? '') === $table && isset($declaration['field_specifications'][(string)($blocker['column'] ?? '')])) {
					$key = $blocker['key'] ?? null;
					return self::url($plugin, (string)$name, (string)$blocker['column'], is_numeric($key) ? (int)$key : null);
				}
			}
		}
		return null;
	}

	/**
	 * The pages for the sandboxed plugins' rows that block a delete a dry run
	 * refused (SystemBase::permanent_delete_dry_run()'s blockers), one each.
	 *
	 * @return string[]
	 */
	public static function urlsForDryRun(array $dry_run): array {
		$urls = array();
		foreach ((array)($dry_run['blockers'] ?? array()) as $blocker) {
			$url = is_array($blocker) ? self::urlForBlocker($blocker) : null;
			if ($url !== null) {
				$urls[$url] = $url;
			}
		}
		return array_values($urls);
	}

	/** How many rows of the model hold a link in $column (to $id, or to anything). */
	public static function count(string $class, string $column, ?int $id): int {
		if (!isset($class::$field_specifications[$column])) {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT COUNT(*) FROM ' . $class::$tablename . ' WHERE ' . $column
			. ($id === null ? ' IS NOT NULL' : ' = ?'));
		$q->execute($id === null ? array() : array($id));
		return (int)$q->fetchColumn();
	}

	/**
	 * The rows that hold a link in $column (to $id, or to anything), deleted
	 * ones too: a prevent counts every row, soft-deleted or not.
	 *
	 * @return SystemBase[]
	 */
	public static function rows(string $class, string $column, ?int $id): array {
		if (!isset($class::$field_specifications[$column])) {
			return array();
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT ' . $class::$pkey_column . ' FROM ' . $class::$tablename . ' WHERE ' . $column
			. ($id === null ? ' IS NOT NULL' : ' = ?') . ' ORDER BY ' . $class::$pkey_column . ' LIMIT ' . self::PAGE_ROWS);
		$q->execute($id === null ? array() : array($id));
		$rows = array();
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $key) {
			$row = new $class((int)$key, TRUE);
			if ($row->key) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/** The site class of one of a plugin's models, declared whatever its status; null when it has no such model. */
	public static function classOf(string $plugin, string $model): ?string {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin) || !preg_match(SandboxManifest::MODEL_PATTERN, $model)) {
			return null;
		}
		$class = SandboxManifest::classFor($plugin, $model);
		return in_array($class, SandboxModels::classesFor($plugin), true) ? $class : null;
	}

	/** The plugin's title as its row keeps it, which needs neither its folder nor its manifest. */
	public static function titleOf(string $plugin): string {
		$row = Plugin::get_by_plugin_name($plugin);
		$metadata = $row ? json_decode((string)$row->get('plg_metadata'), true) : null;
		return is_string($metadata['name'] ?? null) && $metadata['name'] !== '' ? $metadata['name'] : $plugin;
	}
}
