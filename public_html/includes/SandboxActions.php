<?php
/**
 * SandboxActions — a sandboxed plugin's own API actions
 * (spec sandboxed_plugin_api_actions, phase 1).
 *
 * A sandboxed plugin writes an action as a first-party plugin does: a logic
 * file in src/logic/{action}_logic.php holding {action}_logic_descriptor()
 * and {action}_logic($input). It is called the way any plugin action is,
 * POST /api/v1/action/{plugin}/{action}, with any credential its descriptor
 * admits, and ApiLogicEndpoint runs it through the same steps as any action.
 *
 * What differs, because the site never includes a sandboxed plugin's file:
 *
 *   - The descriptor is read, not run: SandboxModelFile::readLogic(), in the
 *     parser jail, and held to an allow-list here (check()). The checked
 *     descriptors are kept on the plugin's row (plg_metadata._sandbox_actions),
 *     read and stored at the same moments as its model files (SandboxModels
 *     calls refresh() and storePending()). After that the stored copy is the
 *     only source.
 *   - The logic function runs in the fence: call() makes one fenced call
 *     (SandboxHost::action(), export "action") and rebuilds a LogicResult
 *     from the parts the runner sends back, each held to a size.
 *   - A call that never ran (busy, unavailable) is marked (neverRan()), so
 *     an Idempotency-Key is not held to an answer the plugin never gave.
 *
 * Stored descriptor: the descriptor as written, with requires_setting
 * carried as the setting's real name (sbx_{plugin}__{name}), and
 * '_plugin' => the plugin, which the catalog and the AI assistant read.
 *
 * Phase 2, webhooks: an action whose descriptor says requires_session false
 * is called by anyone, as a first-party sessionless action is: an outside
 * service POSTs to it, and the plugin's own code checks the signature
 * (SandboxRequest::rawBody() in the fence). Core treats each call as from
 * someone unknown: the anonymous pool, the webhook caps in the relay, and
 * two site-wide counts per plugin (SandboxHost::webhook()). It runs only
 * once approved (a grant of its own, webhooks), and is never offered to
 * the AI assistant.
 *
 * @version 1.3 - a webhook not approved is left out of the catalog too; the approved webhooks are read once a request
 *                (reviewer2 R1, N3)
 * @version 1.2 - webhooks: requires_session false, with no auth and no ai_agent; webhook() hands the fence the
 *                request's headers (credentials left out) and its raw body (spec sandboxed_plugin_api_actions phase 2)
 * @version 1.1 - call() holds the caller to the descriptor's level and requires_setting, for every caller (the API,
 *                the AI assistant, first-party code); a logic file changed since the site read it is refused until a sync
 *                (fileChanged()); keyOnly() (reviewer2 R1, R2)
 * @version 1.0
 */

class SandboxActions {

	/** The folder of src/ that holds the logic files. */
	const LOGIC_DIR = 'logic';

	/** A logic file's name, the action in it. */
	const FILE_PATTERN = '~^([a-z][a-z0-9_]{0,59})_logic\.php$~D';

	/** The runtime key of plg_metadata that holds the checked descriptors. */
	const METADATA_KEY = '_sandbox_actions';

	const MAX_ACTIONS = 50;

	/** The largest input a call carries, and the largest data its answer may. */
	const MAX_INPUT_BYTES = 1048576;
	const MAX_DATA_BYTES = 1048576;

	/** What an answer's error and validation errors are held to. */
	const MAX_ERROR = 500;
	const MAX_VALIDATION_FIELDS = 50;
	const MAX_VALIDATION_TEXT = 300;

	/** The longest description: it reaches the AI assistant's tool list as the plugin's own words. */
	const MAX_DESCRIPTION = 300;

	const MAX_INPUT_FIELDS = 50;

	/** The descriptor keys a sandboxed action may hold, at the top and in auth. */
	const TOP_KEYS = array('description', 'mutates', 'ai_agent', 'input', 'requires_setting', 'auth', 'requires_session');
	const AUTH_KEYS = array('capability', 'min_user_permission', 'requires_machine_key', 'requires_scoped_key',
		'requires_person_credential', 'requires_browser_session');
	const AUTH_FLAGS = array('requires_machine_key', 'requires_scoped_key', 'requires_person_credential', 'requires_browser_session');

	/** Refused by name, with the reason. */
	const REFUSED_KEYS = array(
		'allow_guest'   => 'a sandboxed action is for signed-in callers; its public pages serve visitors',
		'session_write' => 'a sandboxed action writes no session',
		'app_purpose'   => 'app purposes are the site\'s own',
	);

	/** What DescriptorValidator reads of an input field, and the words of one. */
	const INPUT_KEYS = array('type', 'required', 'label', 'description', 'default', 'enum', 'min', 'max', 'max_length', 'items', 'max_items');
	const INPUT_TYPES = array('string', 'text', 'password', 'int', 'integer', 'float', 'number', 'bool', 'boolean',
		'email', 'date', 'datetime', 'array', 'object');

	const FIELD_PATTERN = '~^[a-z_][a-z0-9_]{0,63}$~D';

	/** plugin => array(descriptors, files) read in this process and not yet stored. */
	private static $pending = array();

	/** plugin => stored entry, read once per state. */
	private static $stored = array();

	/** @var WeakMap<LogicResult,true>|null the results of calls whose code never ran */
	private static $never_ran = null;

	// ------------------------------------------------------------------
	// Reading and checking
	// ------------------------------------------------------------------

	/**
	 * Read and check the plugin's action descriptors now, for this process.
	 * Stores nothing.
	 *
	 * @return array<string,array> action => stored descriptor
	 * @throws SandboxModelException listing every problem
	 */
	public static function refresh(string $plugin, SandboxManifest $manifest): array {
		$read = self::readDir($plugin, $manifest, SandboxPlugins::dirFor($plugin) . '/' . SandboxPlugins::SOURCE_DIR . '/' . self::LOGIC_DIR);
		self::$pending[$plugin] = $read;
		return $read['actions'];
	}

	/**
	 * The same, for the logic files in $dir.
	 *
	 * @return array{actions:array, files:array}
	 * @throws SandboxModelException listing every problem
	 */
	public static function readDir(string $plugin, SandboxManifest $manifest, string $dir): array {
		$files = self::files($dir);
		if (isset($files['problems'])) {
			throw new SandboxModelException($files['problems']);
		}
		if (!$files['bytes']) {
			return array('actions' => array(), 'files' => array());
		}
		if (!DocumentText::jailAvailable() || !DocumentText::jailFenced()) {
			throw new SandboxModelException(array('This server has no parser jail, so it cannot read a sandboxed plugin\'s action descriptors '
				. '(they are read in the jail, never in the site). Install the parser jail to use one.'));
		}
		$answers = array();
		foreach (DocumentText::parseWithMany('SandboxModelFile', $files['bytes'], array('shape' => 'logic')) as $file => $json) {
			$answers[$file] = is_string($json) ? json_decode($json, true) : null;
		}
		$checked = self::check($plugin, $manifest, $answers);
		if ($checked['problems']) {
			throw new SandboxModelException($checked['problems']);
		}
		return array('actions' => $checked['actions'], 'files' => $files['stats']);
	}

	/**
	 * The logic files of src/logic/ that declare a descriptor: their bytes,
	 * or the problems with the folder. A logic file with no descriptor is not
	 * an action (a page's own logic), and is not read.
	 *
	 * @return array{bytes?:array<string,string>, stats?:array, problems?:string[]}
	 */
	public static function files(string $dir): array {
		if (!file_exists($dir)) {
			return array('bytes' => array(), 'stats' => array());
		}
		if (is_link($dir) || !is_dir($dir)) {
			return array('problems' => array('src/' . self::LOGIC_DIR . ' must be a folder, not a link.'));
		}
		$problems = array();
		$bytes = array();
		$stats = array();
		$entries = scandir($dir) ?: array();
		sort($entries);
		foreach ($entries as $entry) {
			if (!preg_match(self::FILE_PATTERN, $entry, $m)) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if (is_link($path) || !is_file($path)) {
				$problems[] = 'src/' . self::LOGIC_DIR . '/' . $entry . ': a logic file is a file, not a link or a folder.';
				continue;
			}
			$text = file_get_contents($path);
			if (!is_string($text)) {
				$problems[] = 'src/' . self::LOGIC_DIR . '/' . $entry . ': it cannot be read.';
				continue;
			}
			if (!preg_match('~function\s+&?\s*' . preg_quote($m[1], '~') . '_logic_descriptor\s*\(~i', $text)) {
				continue;
			}
			if (strlen($text) > SandboxModelFile::MAX_BYTES) {
				$problems[] = 'src/' . self::LOGIC_DIR . '/' . $entry . ': a logic file with a descriptor is at most 256 KB.';
				continue;
			}
			$bytes[$entry] = $text;
			$stats[$entry] = array('sha256' => hash('sha256', $text), 'size' => strlen($text), 'mtime' => (int)filemtime($path));
		}
		if (count($bytes) > self::MAX_ACTIONS) {
			$problems[] = 'A plugin may declare at most ' . self::MAX_ACTIONS . ' actions.';
		}
		return $problems ? array('problems' => $problems) : array('bytes' => $bytes, 'stats' => $stats);
	}

	/**
	 * Hold the reader's answers to the allow-list.
	 *
	 * @param array<string,?array> $answers file => SandboxModelFile::readLogic()'s decoded answer
	 * @return array{actions:array, problems:string[]}
	 */
	public static function check(string $plugin, SandboxManifest $manifest, array $answers): array {
		$problems = array();
		$actions = array();
		foreach ($answers as $file => $answer) {
			$file = (string)$file;
			$label = 'src/' . self::LOGIC_DIR . '/' . $file;
			if (!preg_match(self::FILE_PATTERN, $file, $m)) {
				continue;
			}
			$action = $m[1];
			if (!is_array($answer)) {
				$problems[] = "$label: the parser jail could not read it.";
				continue;
			}
			if (empty($answer['ok'])) {
				$problems[] = "$label " . (string)($answer['error'] ?? 'cannot be read.');
				continue;
			}
			$functions = array_change_key_case((array)($answer['functions'] ?? array()), CASE_LOWER);
			$descriptors = array_change_key_case((array)($answer['descriptors'] ?? array()), CASE_LOWER);
			$descriptor_fn = $action . '_logic_descriptor';
			if (!isset($descriptors[$descriptor_fn])) {
				$problems[] = "$label: it declares a descriptor, but not $descriptor_fn() at the top of the file.";
				continue;
			}
			if (!isset($functions[$action . '_logic'])) {
				$problems[] = "$label: it has $descriptor_fn() but no {$action}_logic(\$input) at the top of the file.";
				continue;
			}
			if (isset($functions[$action . '_logic_form'])) {
				$problems[] = "$label: {$action}_logic_form() (a form the API serves) is not available to a sandboxed plugin; leave it out.";
				continue;
			}
			// The fence resolves core's classes, and their files declare a few
			// functions of their own (process_logic(), which every view calls):
			// one of the same name fatals the call before it starts.
			foreach (array_keys($functions) as $function) {
				if (isset(self::coreFunctions()[$function])) {
					$problems[] = "$label: $function() is a function the site's own code declares; "
						. ($function === $action . '_logic' || $function === $descriptor_fn ? 'name the action something else.' : 'name it something else.');
				}
			}
			foreach (array_keys($descriptors) as $other) {
				if ($other !== $descriptor_fn) {
					$problems[] = "$label: $other() names another action; each action's descriptor is in its own file.";
				}
			}
			$value = SandboxModelDeclarations::decode($descriptors[$descriptor_fn]['value'] ?? null);
			$before = count($problems);
			$stored = self::descriptor($plugin, $manifest, $label, is_array($value) ? $value : array(), $problems);
			if (count($problems) === $before) {
				$actions[$action] = $stored;
			}
		}
		ksort($actions);
		return array('actions' => $problems ? array() : $actions, 'problems' => $problems);
	}

	/** @var array<string,true>|null */
	private static $core_functions = null;

	/**
	 * The functions core's includes/ and data/ files declare at the top
	 * level, lower case: what a call in the fence may meet once a core class
	 * is loaded. Read from the files, so it never depends on what this
	 * request happened to load.
	 *
	 * @return array<string,true>
	 */
	public static function coreFunctions(): array {
		if (self::$core_functions === null) {
			self::$core_functions = array();
			foreach (array('includes', 'data') as $dir) {
				$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PathHelper::getIncludePath($dir), FilesystemIterator::SKIP_DOTS));
				foreach ($files as $file) {
					if (substr((string)$file, -4) !== '.php') {
						continue;
					}
					$text = (string)@file_get_contents((string)$file);
					if (preg_match_all('~^function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(~m', $text, $m)) {
						foreach ($m[1] as $name) {
							self::$core_functions[strtolower($name)] = true;
						}
					}
				}
			}
		}
		return self::$core_functions;
	}

	/** One descriptor held to the allow-list, as it is stored. */
	private static function descriptor(string $plugin, SandboxManifest $manifest, string $label, array $d, array &$problems): array {
		if (self::holdsConstant($d)) {
			$problems[] = "$label: a descriptor holds plain values only (no constants).";
			return array();
		}
		foreach ($d as $key => $value) {
			if (isset(self::REFUSED_KEYS[$key])) {
				$problems[] = "$label: '$key' is not available to a sandboxed action: " . self::REFUSED_KEYS[$key] . '.';
			} elseif (!in_array($key, self::TOP_KEYS, true)) {
				$problems[] = "$label: '" . self::show($key) . "' is not a key a sandboxed action's descriptor may hold ("
					. implode(', ', self::TOP_KEYS) . ').';
			}
		}
		$out = array();
		if (array_key_exists('description', $d)) {
			if (!is_string($d['description']) || mb_strlen($d['description']) > self::MAX_DESCRIPTION) {
				$problems[] = "$label: description is a string of at most " . self::MAX_DESCRIPTION . ' characters.';
			} else {
				$out['description'] = $d['description'];
			}
		}
		if (array_key_exists('mutates', $d)) {
			if (!is_bool($d['mutates'])) {
				$problems[] = "$label: mutates is true or false.";
			} else {
				$out['mutates'] = $d['mutates'];
			}
		}
		if (array_key_exists('ai_agent', $d)) {
			if (!in_array($d['ai_agent'], array('confirm', 'auto'), true)) {
				$problems[] = "$label: ai_agent is 'confirm' (the assistant asks first) or 'auto' (it runs without asking).";
			} else {
				$out['ai_agent'] = $d['ai_agent'];
			}
		}
		$webhook = false;
		if (array_key_exists('requires_session', $d)) {
			if ($d['requires_session'] === false) {
				// A webhook: anyone may call it, and its own code checks who did.
				$webhook = true;
				$out['requires_session'] = false;
				if (array_key_exists('auth', $d)) {
					$problems[] = "$label: a webhook (requires_session false) has no auth: anyone may call it, and its own code checks the signature.";
				}
				if (array_key_exists('ai_agent', $d)) {
					$problems[] = "$label: a webhook (requires_session false) is not offered to the AI assistant; leave out ai_agent.";
				}
			} elseif ($d['requires_session'] !== true) {
				$problems[] = "$label: requires_session is true or false.";
			}
		}
		if (array_key_exists('requires_setting', $d)) {
			$settings = $manifest->settings();
			if (!is_string($d['requires_setting']) || !isset($settings[$d['requires_setting']])) {
				$problems[] = "$label: requires_setting names one of this plugin's own settings, by its name in manifest.json.";
			} else {
				// The gate and the catalog read the setting by its real name.
				$out['requires_setting'] = SandboxManifest::settingName($plugin, $d['requires_setting']);
			}
		}
		$auth = $d['auth'] ?? null;
		if ($webhook) {
			// Checked above: a webhook declares none.
		} elseif (!is_array($auth) || !array_key_exists('min_user_permission', $auth)) {
			$problems[] = "$label: auth.min_user_permission is required: the level a caller needs (0 for any signed-in member).";
		} else {
			$out['auth'] = self::auth($label, $auth, $problems);
		}
		if (array_key_exists('input', $d)) {
			if (!is_array($d['input']) || ($d['input'] !== array() && array_is_list($d['input']))) {
				$problems[] = "$label: input is a map of field name => what the field is.";
			} elseif (count($d['input']) > self::MAX_INPUT_FIELDS) {
				$problems[] = "$label: input declares at most " . self::MAX_INPUT_FIELDS . ' fields.';
			} else {
				$out['input'] = self::inputFields($label . ': input', $d['input'], $problems, 0);
			}
		}
		$out['_plugin'] = $plugin;
		return $out;
	}

	private static function auth(string $label, array $auth, array &$problems): array {
		$out = array();
		foreach ($auth as $key => $value) {
			if (isset(self::REFUSED_KEYS[$key])) {
				$problems[] = "$label: auth.$key is not available to a sandboxed action: " . self::REFUSED_KEYS[$key] . '.';
				continue;
			}
			if (!in_array($key, self::AUTH_KEYS, true)) {
				$problems[] = "$label: auth." . self::show($key) . ' is not a key a sandboxed action may hold (' . implode(', ', self::AUTH_KEYS) . ').';
				continue;
			}
			if (in_array($key, self::AUTH_FLAGS, true)) {
				if (!is_bool($value)) {
					$problems[] = "$label: auth.$key is true or false.";
					continue;
				}
			} elseif ($key === 'capability') {
				if (!in_array($value, array(ApiAuth::CAP_READ, ApiAuth::CAP_WRITE, ApiAuth::CAP_DELETE, null), true)) {
					$problems[] = "$label: auth.capability is 'read', 'write', 'delete' or null.";
					continue;
				}
			} elseif ($key === 'min_user_permission') {
				$ok = is_int($value) && $value >= 0 && $value <= 10;
				if (!$ok && is_string($value) && preg_match('~^[a-z_]+:[a-z_]+$~D', $value)) {
					try {
						Access::level($value);
						$ok = true;
					} catch (Throwable $e) {
						$ok = false;
					}
				}
				if (!$ok) {
					$problems[] = "$label: auth.min_user_permission is a level from 0 to 10, or a class and verb such as 'content:write'.";
					continue;
				}
			}
			$out[$key] = $value;
		}
		return $out;
	}

	/** An input schema, as DescriptorValidator reads it; an array's items recurse once per level. */
	private static function inputFields(string $label, array $fields, array &$problems, int $depth): array {
		$out = array();
		foreach ($fields as $name => $spec) {
			if (!is_string($name) || !preg_match(self::FIELD_PATTERN, $name)) {
				$problems[] = "$label: '" . self::show($name) . "' is not a field name (lower case letters, digits and _).";
				continue;
			}
			if (!is_array($spec)) {
				$problems[] = "$label.$name is a map of what the field is (type, required, …).";
				continue;
			}
			$out[$name] = self::inputSpec("$label.$name", $spec, $problems, $depth);
		}
		return $out;
	}

	private static function inputSpec(string $label, array $spec, array &$problems, int $depth): array {
		foreach (array_keys($spec) as $key) {
			if (!in_array($key, self::INPUT_KEYS, true)) {
				$problems[] = "$label: '" . self::show($key) . "' is not something the API checks a field for (" . implode(', ', self::INPUT_KEYS) . ').';
			}
		}
		$type = $spec['type'] ?? 'string';
		if (!in_array($type, self::INPUT_TYPES, true)) {
			$problems[] = "$label: type is one of " . implode(', ', self::INPUT_TYPES) . '.';
		}
		foreach (array('required') as $flag) {
			if (array_key_exists($flag, $spec) && !is_bool($spec[$flag])) {
				$problems[] = "$label: $flag is true or false.";
			}
		}
		foreach (array('label', 'description') as $text) {
			if (array_key_exists($text, $spec) && (!is_string($spec[$text]) || mb_strlen($spec[$text]) > self::MAX_DESCRIPTION)) {
				$problems[] = "$label: $text is a string of at most " . self::MAX_DESCRIPTION . ' characters.';
			}
		}
		foreach (array('min', 'max') as $bound) {
			if (array_key_exists($bound, $spec) && !is_int($spec[$bound]) && !is_float($spec[$bound])) {
				$problems[] = "$label: $bound is a number.";
			}
		}
		foreach (array('max_length', 'max_items') as $count) {
			if (array_key_exists($count, $spec) && (!is_int($spec[$count]) || $spec[$count] < 0)) {
				$problems[] = "$label: $count is a whole number.";
			}
		}
		if (array_key_exists('default', $spec) && !is_scalar($spec['default']) && $spec['default'] !== null) {
			$problems[] = "$label: default is a plain value.";
		}
		if (array_key_exists('enum', $spec)) {
			$ok = is_array($spec['enum']) && array_is_list($spec['enum']) && count($spec['enum']) <= 200;
			foreach ($ok ? $spec['enum'] : array() as $v) {
				$ok = $ok && (is_string($v) || is_int($v) || is_float($v));
			}
			if (!$ok) {
				$problems[] = "$label: enum is a list of at most 200 strings or numbers.";
			}
		}
		if (array_key_exists('items', $spec)) {
			if ($type !== 'array') {
				$problems[] = "$label: items belongs to a field of type array.";
			} elseif ($depth >= 3) {
				$problems[] = "$label: items nest at most three deep.";
			} elseif (!is_array($spec['items'])) {
				$problems[] = "$label: items is one field's description, or a map of the fields of each element.";
			} elseif (isset($spec['items']['type']) && is_string($spec['items']['type'])) {
				$spec['items'] = self::inputSpec("$label.items", $spec['items'], $problems, $depth + 1);
			} else {
				$spec['items'] = self::inputFields("$label.items", $spec['items'], $problems, $depth + 1);
			}
		}
		return $spec;
	}

	/** Whether User::USER_DELETED, the one constant the reader reads, appears anywhere. */
	private static function holdsConstant($value): bool {
		if ($value === SandboxModelDeclarations::DELETED_USER) {
			return true;
		}
		if (is_array($value)) {
			foreach ($value as $member) {
				if (self::holdsConstant($member)) {
					return true;
				}
			}
		}
		return false;
	}

	private static function show($v): string {
		$s = is_scalar($v) ? (string)$v : gettype($v);
		return mb_substr((string)preg_replace('~[\x00-\x1F\x7F]~u', '?', $s), 0, 60);
	}

	// ------------------------------------------------------------------
	// Storage (SandboxModels reads and stores them with the model files)
	// ------------------------------------------------------------------

	/** What refresh() read in this process and has not stored, or null. */
	public static function pending(string $plugin): ?array {
		return self::$pending[$plugin] ?? null;
	}

	/** The stored entry has been written with the row's metadata (SandboxModels::storePending()). */
	public static function markStored(string $plugin): void {
		if (isset(self::$pending[$plugin])) {
			self::$stored[$plugin] = self::$pending[$plugin];
			unset(self::$pending[$plugin]);
		}
	}

	/** The entry kept on the plugin's row: array(actions, files), or null. */
	public static function stored(string $plugin): ?array {
		if (!array_key_exists($plugin, self::$stored)) {
			$entry = null;
			try {
				$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT plg_metadata FROM plg_plugins WHERE plg_name = ?');
				$q->execute(array($plugin));
				$metadata = json_decode((string)$q->fetchColumn(), true);
				$entry = is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : null;
			} catch (Throwable $e) {
				$entry = null;
			}
			self::$stored[$plugin] = $entry;
		}
		return self::$stored[$plugin];
	}

	/**
	 * The descriptors the site uses for a plugin: what this process read and
	 * has not stored yet, else the stored copy.
	 *
	 * @return array<string,array> action => descriptor
	 */
	public static function descriptors(string $plugin): array {
		$entry = self::$pending[$plugin] ?? self::stored($plugin);
		return is_array($entry['actions'] ?? null) ? $entry['actions'] : array();
	}

	/**
	 * Forget the stored copies (SandboxModels::reset()). What refresh() read
	 * and has not stored is kept, as the model files' read is: an install
	 * writes it with the plugin's row.
	 */
	public static function reset(): void {
		self::$stored = array();
		self::$approved_webhooks = array();
	}

	// ------------------------------------------------------------------
	// Lookup, the catalog, and the call
	// ------------------------------------------------------------------

	/**
	 * The stored descriptor of {plugin}/{action} when the plugin is an
	 * active sandboxed plugin with that action, else null. Asked before any
	 * credential is read: what it answers for a plugin or action that is not
	 * there is the caller's same 404, so it tells nobody which sandboxed
	 * plugins a site has.
	 */
	public static function lookup(string $plugin, string $action): ?array {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin) || !preg_match('~^[a-z][a-z0-9_]{0,59}$~D', $action)) {
			return null;
		}
		if (!SandboxPlugins::isActive($plugin)) {
			return null;
		}
		$descriptor = self::stored($plugin)['actions'][$action] ?? null;
		if (!is_array($descriptor)) {
			return null;
		}
		// A webhook is there only once an admin approved anyone calling it.
		if (self::isWebhook($descriptor) && !in_array($plugin . '/' . $action, self::approvedWebhooks($plugin), true)) {
			return null;
		}
		return $descriptor;
	}

	/** @var array<string,string[]> plugin => its approved webhooks, read once a request (reviewer2 N3) */
	private static $approved_webhooks = array();

	/** The plugin's webhooks an admin approved, by '{plugin}/{action}'. */
	public static function approvedWebhooks(string $plugin): array {
		if (!isset(self::$approved_webhooks[$plugin])) {
			self::$approved_webhooks[$plugin] = SandboxConsent::effective($plugin)['webhooks'] ?? array();
		}
		return self::$approved_webhooks[$plugin];
	}

	/** Whether a descriptor is a webhook's: called by anyone, with no credential. */
	public static function isWebhook(array $descriptor): bool {
		return ($descriptor['requires_session'] ?? true) === false;
	}

	/** Whether a '{plugin}/{action}' name is a sandboxed plugin's action (active or not, by its stored descriptors). */
	public static function isSandboxedAction(string $name): bool {
		$parts = explode('/', $name);
		if (count($parts) !== 2 || !preg_match(SandboxManifest::NAME_PATTERN, $parts[0]) || !SandboxPlugins::isSandboxed($parts[0])) {
			return false;
		}
		return isset(self::descriptors($parts[0])[$parts[1]]);
	}

	/**
	 * Every active sandboxed plugin's actions, keyed '{plugin}/{action}', in
	 * the catalog's shape (ApiLogicEndpoint::actionCatalog()).
	 *
	 * @return array<string,array{meta:array,has_form:bool,sandboxed:string}>
	 */
	public static function catalog(): array {
		$out = array();
		foreach (array_keys(SandboxPlugins::active()) as $plugin) {
			$approved = null;
			foreach ((array)(self::stored((string)$plugin)['actions'] ?? array()) as $action => $descriptor) {
				// A webhook no admin approved is not there, here as in lookup() (reviewer2 R1).
				if (is_array($descriptor) && self::isWebhook($descriptor)) {
					$approved = $approved ?? self::approvedWebhooks((string)$plugin);
					if (!in_array($plugin . '/' . $action, $approved, true)) {
						continue;
					}
				}
				if (is_array($descriptor)) {
					$out[$plugin . '/' . $action] = array('meta' => $descriptor, 'has_form' => false, 'sandboxed' => (string)$plugin);
				}
			}
		}
		return $out;
	}

	/**
	 * The callable that stands for {plugin}/{action}'s logic function: it
	 * takes the input and returns the LogicResult of one fenced call, made
	 * as whoever the request is acting as (the session ApiLogicEndpoint set).
	 */
	public static function callable(string $plugin, string $action): Closure {
		return function ($input) use ($plugin, $action) {
			$descriptor = self::lookup($plugin, $action);
			if ($descriptor !== null && self::isWebhook($descriptor)) {
				return self::webhook($plugin, $action, is_array($input) ? $input : array());
			}
			$session = SessionControl::get_instance();
			$user_id = $session->get_user_id();
			$user = $user_id ? new User((int)$user_id, TRUE) : null;
			return self::call($plugin, $action, is_array($input) ? $input : array(), $user, $user ? (int)$session->get_permission() : null);
		};
	}

	/**
	 * Run {plugin}/{action} for $user and return its LogicResult. What
	 * first-party code calls in place of including a sandboxed plugin's
	 * logic file; the API's path uses it too.
	 *
	 * @param int|null $permission the level the call acts at (the session's, or the key's user's); null is the user's stored level
	 */
	public static function call(string $plugin, string $action, array $input, ?User $user, ?int $permission = null): LogicResult {
		$descriptor = self::lookup($plugin, $action);
		if ($descriptor === null) {
			return LogicResult::error('Unknown action: ' . $plugin . '/' . $action, array('http_status' => 404));
		}
		if (self::isWebhook($descriptor)) {
			// Anyone's call, held to the webhook limits: only its address runs it.
			return LogicResult::error($plugin . '/' . $action . ' is a webhook: it runs when its address is called.', array('http_status' => 400));
		}
		if ($user === null || !$user->key) {
			// A sandboxed action is for a signed-in caller (no allow_guest, no sessionless action yet).
			return LogicResult::error('This action needs a signed-in caller.', array('http_status' => 401));
		}
		// The fields it declares, and nothing else.
		$values = array_intersect_key($input, (array)($descriptor['input'] ?? array()));
		$json = json_encode((object)$values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false || strlen($json) > self::MAX_INPUT_BYTES) {
			return LogicResult::error('The input is larger than 1 MB.', array('http_status' => 413));
		}
		$permission = $permission ?? (int)$user->get('usr_permission');
		// The descriptor's word on who may call, held here so every caller
		// meets it (the API's ApiAuth checks it too; the AI assistant and
		// first-party code do not): the plugin's author is told the site
		// checks the caller before the file runs.
		if (!Access::allows($descriptor['auth']['min_user_permission'] ?? 0, $permission)) {
			return LogicResult::error('You do not have permission to run ' . $plugin . '/' . $action . '.', array('http_status' => 403));
		}
		$setting = $descriptor['requires_setting'] ?? null;
		if ($setting && !Globalvars::get_instance()->get_setting($setting)) {
			return LogicResult::error($plugin . '/' . $action . ' is unavailable: the ' . $setting . ' setting is off on this instance.',
				array('http_status' => 403));
		}
		$result = SandboxHost::action($plugin, $action, $values, $user, $permission);
		return self::result($result);
	}

	/**
	 * Whether an action is for a key alone (requires_machine_key or
	 * requires_scoped_key): only the API, which knows the credential, can
	 * hold a caller to it, so the AI assistant is never offered one.
	 */
	public static function keyOnly(array $descriptor): bool {
		return !empty($descriptor['auth']['requires_machine_key']) || !empty($descriptor['auth']['requires_scoped_key']);
	}

	/** The message of a call refused because its logic file changed since the site read it. */
	const FILE_CHANGED = 'its action file changed since the site last read it; sync the plugin (Plugins page, Sync with Filesystem) to read it again';

	/**
	 * Whether {action}'s logic file differs from what the site read (a new
	 * level in its descriptor would not hold until a sync). Size and time
	 * first; the file is hashed only when one of them differs.
	 */
	public static function fileChanged(string $plugin, string $action): bool {
		$file = $action . '_logic.php';
		$stat = self::stored($plugin)['files'][$file] ?? null;
		$path = SandboxPlugins::dirFor($plugin) . '/' . SandboxPlugins::SOURCE_DIR . '/' . self::LOGIC_DIR . '/' . $file;
		if (!is_array($stat) || is_link($path) || !is_file($path)) {
			return true;
		}
		clearstatcache(true, $path);
		if ((int)filesize($path) === (int)($stat['size'] ?? -1) && (int)filemtime($path) === (int)($stat['mtime'] ?? -1)) {
			return false;
		}
		return !hash_equals((string)($stat['sha256'] ?? ''), (string)hash_file('sha256', $path));
	}

	/** The longest raw body a webhook hands the fence. */
	const MAX_BODY_BYTES = 1048576;

	/** At most this many request headers cross, each value at most MAX_HEADER_BYTES. */
	const MAX_HEADERS = 100;
	const MAX_HEADER_BYTES = 8192;

	/**
	 * Run a webhook for the request being answered (anyone's POST): its
	 * declared fields, its headers with every credential left out, and its
	 * raw body, which the plugin's code signs over. Refused before it runs
	 * with its setting off or a body over 1 MB.
	 */
	public static function webhook(string $plugin, string $action, array $input): LogicResult {
		$descriptor = self::lookup($plugin, $action);
		if ($descriptor === null || !self::isWebhook($descriptor)) {
			return LogicResult::error('Unknown action: ' . $plugin . '/' . $action, array('http_status' => 404));
		}
		$setting = $descriptor['requires_setting'] ?? null;
		if ($setting && !Globalvars::get_instance()->get_setting($setting)) {
			return LogicResult::error($plugin . '/' . $action . ' is unavailable: the ' . $setting . ' setting is off on this instance.',
				array('http_status' => 403));
		}
		$body = self::$raw_body ?? (string)file_get_contents('php://input');
		if (strlen($body) > self::MAX_BODY_BYTES) {
			return LogicResult::error('The body is larger than 1 MB.', array('http_status' => 413));
		}
		$values = array_intersect_key($input, (array)($descriptor['input'] ?? array()));
		$json = json_encode((object)$values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false || strlen($json) > self::MAX_INPUT_BYTES) {
			return LogicResult::error('The input is larger than 1 MB.', array('http_status' => 413));
		}
		$headers = self::webhookHeaders(self::$raw_headers ?? (function_exists('getallheaders') ? (getallheaders() ?: array()) : array()));
		return self::result(SandboxHost::webhook($plugin, $action, $values, $headers, $body));
	}

	/** Tests only: the request's raw body and headers, in place of php://input and getallheaders(). */
	public static $raw_body = null;
	public static $raw_headers = null;

	/**
	 * The request's headers as $_SERVER names them (HTTP_X_SIGNATURE), with
	 * every credential left out: Cookie, Authorization, Proxy-Authorization,
	 * the key headers, and core's own X-Joinery-*.
	 *
	 * @return array<string,string>
	 */
	public static function webhookHeaders(array $headers): array {
		$out = array();
		foreach ($headers as $name => $value) {
			$norm = str_replace('-', '_', strtolower((string)$name));
			if (!preg_match('~^[a-z0-9_]{1,100}$~D', $norm)
					|| in_array($norm, array('cookie', 'authorization', 'proxy_authorization', 'public_key', 'secret_key'), true)
					|| strpos($norm, 'x_joinery_') === 0) {
				continue;
			}
			if (count($out) >= self::MAX_HEADERS) {
				break;
			}
			$out['HTTP_' . strtoupper($norm)] = substr((string)$value, 0, self::MAX_HEADER_BYTES);
		}
		return $out;
	}

	/** Whether $result is of a call whose code never ran (busy, unavailable): an Idempotency-Key is not held to it. */
	public static function neverRan($result): bool {
		return $result instanceof LogicResult && self::$never_ran !== null && isset(self::$never_ran[$result]);
	}

	/** The LogicResult of one fenced call's answer (SandboxHost's result). */
	public static function result(array $call): LogicResult {
		$status = (string)($call['status'] ?? SandboxLog::STATUS_ERROR);
		if ($status !== SandboxLog::STATUS_OK) {
			// A webhook refused by a count (the network's, or the plugin's
			// site-wide) is told when to try again, as any limit is.
			if (in_array($call['refused'] ?? null, array('limited', 'site_limited'), true)) {
				$result = LogicResult::error(mb_substr(trim((string)($call['message'] ?? '')), 0, self::MAX_ERROR),
					array('http_status' => 429, 'reason' => 'rate_limited', 'retry_after_seconds' => 60));
				self::$never_ran = self::$never_ran ?? new WeakMap();
				self::$never_ran[$result] = true;
				return $result;
			}
			$codes = array(
				SandboxLog::STATUS_BUSY        => 503,
				SandboxLog::STATUS_UNAVAILABLE => 503,
				SandboxLog::STATUS_TIMEOUT     => 504,
			);
			$message = trim((string)($call['message'] ?? '')) ?: 'The plugin failed.';
			$result = LogicResult::error(mb_substr($message, 0, self::MAX_ERROR), array('http_status' => $codes[$status] ?? 500,
				'reason' => $status === SandboxLog::STATUS_BUSY ? 'plugin_busy' : ($status === SandboxLog::STATUS_UNAVAILABLE ? 'plugin_unavailable'
					: ($status === SandboxLog::STATUS_TIMEOUT ? 'plugin_timeout' : 'action_failed'))));
			// Busy and unavailable mean the plugin's code did not run (a
			// timeout may have run it): a retry with the same key runs it.
			if (in_array($status, array(SandboxLog::STATUS_BUSY, SandboxLog::STATUS_UNAVAILABLE), true)) {
				self::$never_ran = self::$never_ran ?? new WeakMap();
				self::$never_ran[$result] = true;
			}
			return $result;
		}
		$out = $call['output'] ?? null;
		if (!is_array($out)) {
			return LogicResult::error('The plugin\'s action did not answer with a LogicResult.', array('http_status' => 500, 'reason' => 'action_failed'));
		}
		$data = $out['data'] ?? array();
		if (!is_array($data)) {
			$data = array();
		}
		$encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($encoded === false || strlen($encoded) > self::MAX_DATA_BYTES) {
			return LogicResult::error('The plugin\'s answer is larger than 1 MB.', array('http_status' => 500, 'reason' => 'action_failed'));
		}
		$error = $out['error'] ?? null;
		if ($error !== null && $error !== '' && $error !== false) {
			$result = LogicResult::error(mb_substr(is_scalar($error) ? (string)$error : 'The action failed.', 0, self::MAX_ERROR), $data);
			$errors = array();
			foreach (is_array($out['validation_errors'] ?? null) ? $out['validation_errors'] : array() as $field => $text) {
				if (count($errors) >= self::MAX_VALIDATION_FIELDS) {
					break;
				}
				if (is_string($field) && preg_match(self::FIELD_PATTERN, $field) && is_scalar($text)) {
					$errors[$field] = mb_substr((string)$text, 0, self::MAX_VALIDATION_TEXT);
				}
			}
			$result->validation_errors = $errors;
			return $result;
		}
		$redirect = $out['redirect'] ?? null;
		if ($redirect !== null && $redirect !== '') {
			if (!self::isSitePath($redirect)) {
				return LogicResult::error('The plugin\'s action sent a redirect off this site.', array('http_status' => 500, 'reason' => 'action_failed'));
			}
			return LogicResult::redirect($redirect, $data);
		}
		return LogicResult::render($data);
	}

	/** A path on this site: /something, never //host, a scheme, a backslash or a control character. */
	public static function isSitePath($path): bool {
		return is_string($path) && strlen($path) <= 2000 && preg_match('~^/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*$~D', $path) === 1;
	}

	// ------------------------------------------------------------------
	// The AI assistant and the approval page
	// ------------------------------------------------------------------

	/**
	 * The assistant's tier for an action as approved (D5): 'auto' only when
	 * an admin approved it running without asking; an action marked 'auto'
	 * whose approval has not caught up asks first. Null: not offered.
	 */
	public static function assistantTier(string $plugin, string $action, array $descriptor): ?string {
		$tier = $descriptor['ai_agent'] ?? null;
		if ($tier === 'auto') {
			return in_array($action, SandboxConsent::effective($plugin)['assistant_auto'] ?? array(), true) ? 'auto' : 'confirm';
		}
		return $tier === 'confirm' ? 'confirm' : null;
	}

	/** Each action, in words, for the approval page: who may call it and what the assistant may do. */
	public static function lines(array $actions): array {
		$lines = array();
		foreach ($actions as $action => $d) {
			$description = trim((string)($d['description'] ?? ''));
			$line = $action . ($description !== '' ? ' (' . rtrim($description, '.') . ')' : '') . ': ' . self::whoWords($d['auth'] ?? array(), self::isWebhook($d));
			if (($d['ai_agent'] ?? null) === 'auto') {
				$line .= '. The assistant may run this without asking';
			} elseif (($d['ai_agent'] ?? null) === 'confirm') {
				$line .= '. The assistant may run this after asking';
			}
			$lines[] = $line . '.';
		}
		return $lines;
	}

	private static function whoWords(array $auth, bool $webhook = false): string {
		if ($webhook) {
			return 'anyone on the internet, with no sign-in (a webhook: its own code checks who sent it)';
		}
		$level = $auth['min_user_permission'] ?? 0;
		$who = (is_int($level) && $level === 0) ? 'any signed-in member' : 'staff at level ' . (is_int($level) ? $level : Access::level((string)$level)) . ' and above';
		if (!empty($auth['requires_scoped_key'])) {
			return 'a machine key given this action';
		}
		if (!empty($auth['requires_machine_key'])) {
			return 'a machine key';
		}
		if (!empty($auth['requires_browser_session'])) {
			return $who . ', in their browser';
		}
		if (!empty($auth['requires_person_credential'])) {
			return $who . ', with their own sign-in';
		}
		return $who;
	}
}
