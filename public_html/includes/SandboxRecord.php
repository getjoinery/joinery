<?php
/**
 * SandboxRecord and SandboxRecordCollection — the base of every model a
 * sandboxed plugin declares in its model files (spec
 * sandboxed_plugin_model_files).
 *
 * SandboxModels declares one subclass of each per model (the class
 * Sbx_notes_Note for the model Note of plugin notes, and
 * MultiSbx_notes_Note), filling the static properties every SystemBase model
 * carries from the model's checked declaration (sandboxFill()). From there
 * the model is an ordinary one: its table is built and migrated by
 * update_database and plugin sync, the deletion system knows its rules, and
 * it is a record endpoint on /api/v1 for its plugin's own key, and for
 * reading when its file says so.
 *
 * Who may do what with its rows:
 *
 *  - The plugin's own request (isOwnRequest(): its own key, asking for itself,
 *    with no member named) reads, writes and deletes them, and is not held to
 *    the model's row or column levels. First-party code reaches its models in
 *    process; those levels would otherwise be measured against the admin who
 *    approved the plugin. The plugin's code decides what each member may do.
 *    The test is on this class, so no core model can be reached by it.
 *  - No one else creates or changes a row (D3): only the plugin's own code
 *    does, so its prepare() and save() rules run on every save.
 *  - Reading follows the native rules (owner, or the read level).
 *  - Deleting follows the delete level alone, the owner given no pass: staff
 *    may delete a plugin's rows; a member deletes theirs through the plugin,
 *    which may have reasons to refuse (section 8).
 *
 * @version 2.2 - a rule that carries a block not yet approved is left out, own-table prevents included (reviewer1 R6)
 * @version 2.1 - delete levels not yet approved are 10; the unused $reach goes (reviewer1 R2, N2)
 * @version 2.0 - statics from the model file's declaration; the plugin's own request is exempt from the row and
 *                column levels; nobody else writes; delete at the level alone; the collection has no query of its
 *                own (spec sandboxed_plugin_model_files)
 * @version 1.3 - a filter takes a list of values (spec sandboxed_plugin_ask_speed WP2)
 * @version 1.2 - columnFor()
 * @version 1.1 - every sandboxed model is content (permissions.json)
 * @version 1.0
 */

abstract class SandboxRecord extends SystemBase {

	/** The sandboxed plugin whose own key makes this request, or null (set once by api/apiv1.php). */
	private static $requesting_plugin = null;

	/** api/apiv1.php, once a request's key is known: the sandboxed plugin it belongs to, or null. */
	public static function setRequestingPlugin(?string $plugin): void {
		self::$requesting_plugin = $plugin;
	}

	/**
	 * Whether this request is the plugin's own on its own model: its key, and
	 * no member named (core's relay names one only for an own-rows grant on
	 * another model, never for the plugin's own).
	 */
	public static function isOwnRequest(): bool {
		return self::$requesting_plugin !== null && self::$requesting_plugin === static::$sandbox_plugin
			&& (!class_exists('ApiAuth', false) || ApiAuth::$sandbox_viewer === null);
	}

	/**
	 * Fill the class's statics from its checked declaration, within what was
	 * approved: an opening not approved yet leaves the narrower native value,
	 * and a rule that blocks deleting a site record not approved yet is not
	 * this class's.
	 *
	 * @param array $reach SandboxConsent::effectiveModels(): {model_reach: string[], blocks: string[]}
	 */
	public static function sandboxFill(string $plugin, array $declaration, array $reach): void {
		if (get_parent_class(get_called_class()) !== __CLASS__) {
			throw new LogicException('sandboxFill() is for the site\'s own sandboxed model classes.');
		}
		$name = (string)$declaration['class'];
		$open = array_flip((array)($reach['model_reach'] ?? array()));
		$blocks = array_flip((array)($reach['blocks'] ?? array()));
		static::$sandbox_plugin = $plugin;
		static::$sandbox_model = $name;
		static::$prefix = (string)$declaration['prefix'];
		static::$tablename = (string)$declaration['table'];
		static::$pkey_column = (string)$declaration['pkey_column'];
		static::$field_specifications = (array)$declaration['field_specifications'];
		static::$access_class = (string)$declaration['access_class'];
		static::$write_permission = $declaration['write_permission'];
		static::$api_unreadable_fields = (array)$declaration['api_unreadable_fields'];
		static::$api_writable = false;
		$reach_of = SandboxConsent::modelReachOf($name, $declaration);
		static::$api_readable = $declaration['api_readable'] === true && self::approved($reach_of, $open, 'api');
		static::$read_permission = $declaration['read_permission'];
		// The delete levels as approved; until then, deleting is for
		// superadmins only, whatever the class or the file says now.
		static::$delete_permission = self::approved($reach_of, $open, 'delete_permission') ? $declaration['delete_permission'] : 10;
		static::$permanent_delete_permission = self::approved($reach_of, $open, 'permanent_delete_permission')
			? $declaration['permanent_delete_permission'] : 10;
		$owner = SandboxConsent::ownerOf($declaration);
		static::$owner_column = $owner !== null && self::approved($reach_of, $open, 'owner') ? $owner : false;
		$rules = array();
		$carried = SandboxConsent::blockEntries($plugin, SandboxModels::declarations($plugin) + array($name => $declaration))[$name] ?? array();
		foreach ((array)$declaration['foreign_key_actions'] as $column => $rule) {
			if (!SandboxConsent::ruleApproved($carried[$column] ?? array(), array_keys($blocks))) {
				continue;
			}
			$rules[$column] = $rule;
		}
		static::$foreign_key_actions = $rules;
	}

	/** Whether the approval covers one way the model opens; one it does not open needs none. */
	private static function approved(array $reach_of, array $open, string $kind): bool {
		foreach ($reach_of as $entry => $what) {
			if ($what[0] === $kind) {
				return isset($open[$entry]);
			}
		}
		return true;
	}

	function authenticate_read($data) {
		if (static::isOwnRequest()) {
			return;
		}
		parent::authenticate_read($data);
	}

	/** Only the plugin's own code creates and changes its rows (D3). */
	function authenticate_write($data) {
		if (static::isOwnRequest()) {
			return;
		}
		throw new SystemAuthenticationError('Only the plugin ' . static::$sandbox_plugin . ' changes its '
			. SandboxModelDeclarations::words((string)static::$sandbox_model) . ' records; ask it through its pages or actions.');
	}

	function authenticate_columns($data) {
		if (static::isOwnRequest()) {
			return;
		}
		parent::authenticate_columns($data);
	}

	/**
	 * Deleting: the plugin's own request, or a caller at the model's level for
	 * the verb, with no pass for the row's owner and no write rule first.
	 */
	function authenticate_delete($data, string $verb = 'soft_delete') {
		if (static::isOwnRequest()) {
			return;
		}
		if (!Access::allows(static::access_level($verb), (int)($data['current_user_permission'] ?? 0))) {
			throw new SystemAuthenticationError(($verb === 'permanent_delete' ? 'Permanently deleting' : 'Deleting')
				. ' this entry in ' . static::$tablename . ' needs level ' . static::access_level($verb)
				. ($verb === 'soft_delete' ? '; a member deletes their own through the plugin.' : '.'));
		}
	}

	/** Whether the model keeps deleted rows (has {prefix}_delete_time). */
	public static function hasSoftDelete(): bool {
		return isset(static::$field_specifications[static::$prefix . '_delete_time']);
	}

	/** The plugin this model belongs to. */
	public static function sandboxPlugin(): string {
		return (string)static::$sandbox_plugin;
	}

	/** The model's class name in its plugin's files ('Note'). */
	public static function sandboxModel(): string {
		return (string)static::$sandbox_model;
	}
}

/**
 * A sandboxed model's collection: the built-in list query, by the model's
 * columns (spec collection_default_query), as the plugin's own
 * `class MultiNote extends SystemMultiBase { protected static $model_class = 'Note'; }` is.
 */
abstract class SandboxRecordCollection extends SystemMultiBase {
}
