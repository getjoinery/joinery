<?php
/**
 * SandboxManifest — the one file that says what a sandboxed plugin is and may do.
 *
 * A sandboxed plugin is a folder in plugins/ holding exactly two entries:
 * this manifest (manifest.json) and its code folder (src/). The manifest
 * declares everything the platform will let the plugin do; the plugin can do
 * nothing it does not declare (plugin_tiers.md, docs/sandboxed_plugins.md).
 *
 * This class parses and validates a manifest and answers questions about it.
 * It never trusts the manifest: every name that becomes a class, table,
 * column, URL or setting is checked against a strict pattern here, so code
 * downstream can interpolate them.
 *
 * Shape (every key but name, title, version and platform is optional):
 *
 *   {
 *     "name": "notifier",                   same as its folder; a-z, 0-9, single _ between words, 2-20
 *     "title": "Notifier",
 *     "id": "3f9a1c…",                     32 hex characters the author makes once and keeps in every version:
 *                                           an upgrade is a package with the same id (PluginInstallIdentity)
 *     "version": "1.0.0",
 *     "description": "…", "author": "…",
 *     "platform": 1,                        the platform API version it was built for
 *     "permissions": {                      door 1: what its API credential may do
 *       "actions": ["notification_unread_count"],
 *       "records": {"EventRegistrant": ["read"]},
 *       "own_records": {"Order": ["read"]}    each member's own rows, as the member viewing
 *     },
 *     "signals": ["event.registered"],      door 2: signals delivered to it
 *     "schedules": [                        door 3: scheduled runs
 *       {"name": "digest", "frequency": "daily", "time": "08:00"}
 *     ],
 *     "outbound_hosts": ["hooks.slack.com"],door 5: hosts it may call over HTTPS
 *     "send_email": true,                   door 6: it sends mail through the site's EmailSender
 *     "accept_uploads": true,               door 7: its own pages take uploaded files, kept with File
 *     "settings": [ {"name", "label", "type", "secret", "default", "required", "options", "help"} ],
 *     "models": [ {"name": "SentLog", "label": "…", "fields": [ {"name", "type", "label", "required", "max_length", "index"} ]} ],
 *     "admin_pages":   [ {"slug", "title", "sections": [ … ]} ],   door 4; with no sections the
 *                                           plugin's own view draws the page (a plain page, cleaned by core)
 *     "profile_pages": [ {"slug", "title", "sections": [ … ]} ],
 *     "public_pages":  [ {"slug", "title", "sections": [ … ], "cache": true, "cache_query": ["id"]} ],
 *                                           pages anyone can open, at /{plugin}/{slug}; cached for visitors
 *                                           not signed in unless "cache": false (spec sandboxed_plugin_public_pages)
 *     "menu": {"title": "Notifier"},
 *     "limits": {"memory_mb": 16, "timeout_ms": 5000}
 *   }
 *
 * @version 1.10 - a form field may not take a name the site uses on the form (SandboxHtmlCleaner::RESERVED_NAMES)
 * @version 1.9 - "public_pages": pages anyone can open, signed in or not, with "cache" and "cache_query"
 *                (spec sandboxed_plugin_public_pages)
 * @version 1.8 - "id", required: 32 hex characters naming the plugin across its versions; a package with another
 *                id is a different plugin, refused over an installed one (spec plugin_install_identity D4)
 * @version 1.7 - a call's memory may be up to what the site gives a page request (memoryCeilingMb()), not 64 MB
 * @version 1.6 - "accept_uploads": true, its drawn pages take files and it keeps them with File as native code does
 *                (spec sandboxed_plugin_uploads)
 * @version 1.5 - "send_email": true, the plugin sends mail with EmailSender as native code does (spec sandboxed_plugin_email)
 * @version 1.4 - a framed page's handlers: the only ones its frame may call (reviewer2 K1)
 * @version 1.3 - a page may be framed ("frame": true, design 9)
 * @version 1.2 - permissions.own_records (design 8)
 * @version 1.1 - a page may have no sections: its own view draws it (a plain page)
 * @version 1.0
 */

class SandboxManifestException extends Exception {}

class SandboxManifest {

	/** The manifest's file name inside a sandboxed plugin's folder. */
	const FILE = 'manifest.json';

	/**
	 * The platform API version this site speaks to sandboxed plugins (Q13).
	 * A manifest built for a later one is refused; additions never raise it,
	 * only a change that breaks an existing door does.
	 */
	const PLATFORM_VERSION = 1;

	/** No double underscore and none at the end: '__' is the separator in derived names. */
	const NAME_PATTERN    = '~^(?=.{2,20}$)[a-z][a-z0-9]*(_[a-z0-9]+)*$~D';

	/** The plugin's own id: 32 hex characters ("id"). */
	const ID_PATTERN      = '~^[0-9a-f]{32}$~D';

	const MODEL_PATTERN   = '~^[A-Z][A-Za-z0-9]{1,19}$~D';
	const FIELD_PATTERN   = '~^[a-z][a-z0-9_]{0,19}$~D';
	const SLUG_PATTERN    = '~^[a-z][a-z0-9_]{0,29}$~D';
	/** A query name a public page may list in cache_query. */
	const QUERY_NAME_PATTERN = '~^[a-z][a-z0-9_]{0,39}$~D';
	const SIGNAL_PATTERN  = '~^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$~D';
	const ACTION_PATTERN  = '~^[a-z0-9_]+(/[a-z0-9_]+)?$~D';
	const HOST_PATTERN    = '~^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$~D';
	const VERSION_PATTERN = '~^\d{1,4}\.\d{1,4}\.\d{1,4}$~D';

	/** Field types a model may declare, and the column each becomes. */
	const FIELD_TYPES = array(
		'string'   => 'varchar',          // max_length, default 255, at most 2000
		'text'     => 'text',
		'integer'  => 'int8',
		'decimal'  => 'numeric(14,4)',
		'boolean'  => 'bool',
		'datetime' => 'timestamp(6)',
		'date'     => 'date',
		'json'     => 'jsonb',
	);

	/** Field names the platform adds to every model itself. */
	const RESERVED_FIELDS = array('id', 'create_time', 'update_time', 'delete_time');

	/** Setting types a manifest may declare. */
	const SETTING_TYPES = array('text', 'textarea', 'number', 'boolean', 'select', 'url', 'email');

	/** Section types a page may hold (door 4's UI primitives). */
	const SECTION_TYPES = array('text', 'table', 'form', 'settings');

	/** Form field types a page form may hold. */
	const FORM_FIELD_TYPES = array('text', 'textarea', 'number', 'boolean', 'select', 'email', 'url', 'date');

	const FREQUENCIES = array('hourly', 'daily', 'weekly');

	/**
	 * Ceilings applied to a call whatever a manifest asks for. Memory is the
	 * site's own: what PHP gives a page request here (memoryCeilingMb()), so
	 * a sandboxed plugin's call has what a native plugin's code has.
	 * MEMORY_SHAPE_MAX_MB only bounds what a manifest may write.
	 */
	const MEMORY_SHAPE_MAX_MB = 4096;
	const MAX_TIMEOUT_MS = 10000;

	/** The ceiling when the web server's PHP sets none, or cannot be read: PHP's own default. */
	const MEMORY_FALLBACK_MB = 128;

	/** Tests only: the memory ceiling in MB, in place of the site's. Never set by the platform. @var int|null */
	public static $memory_ceiling_override = null;

	/** @var int|null */
	private static $memory_ceiling = null;
	const DEFAULT_MEMORY_MB  = 16;
	const DEFAULT_TIMEOUT_MS = 5000;

	/** @var array the decoded, validated manifest */
	private $data;

	private function __construct(array $data) {
		$this->data = $data;
	}

	/**
	 * Read and validate a manifest file.
	 *
	 * @throws SandboxManifestException listing every problem found
	 */
	public static function fromFile(string $path, ?string $expected_name = null): self {
		if (!is_file($path)) {
			throw new SandboxManifestException('No ' . self::FILE . ' at ' . $path . '.');
		}
		$raw = file_get_contents($path);
		if ($raw === false || strlen($raw) > 262144) {
			throw new SandboxManifestException(self::FILE . ' is unreadable or larger than 256 KB.');
		}
		return self::fromJson($raw, $expected_name);
	}

	/** @throws SandboxManifestException */
	public static function fromJson(string $json, ?string $expected_name = null): self {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new SandboxManifestException(self::FILE . ' is not a JSON object: ' . json_last_error_msg());
		}
		return self::fromArray($data, $expected_name);
	}

	/** @throws SandboxManifestException */
	public static function fromArray(array $data, ?string $expected_name = null): self {
		try {
			$errors = self::problems($data, $expected_name);
		} catch (Throwable $e) {
			// A value of a kind no check expected; refused like any other problem.
			throw new SandboxManifestException('This manifest cannot be read: a value is of the wrong kind (' . $e->getMessage() . ').');
		}
		if (!empty($errors)) {
			throw new SandboxManifestException("This manifest cannot be used:\n- " . implode("\n- ", $errors));
		}
		return new self($data);
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	/**
	 * Every problem with a decoded manifest, in words. Empty when it is valid.
	 * Checks shape only; whether this site has what the manifest needs (an
	 * action, a signal, a model to read) is SandboxCompatibility's question.
	 *
	 * @return string[]
	 */
	public static function problems(array $d, ?string $expected_name = null): array {
		$e = array();
		$known = array('name', 'id', 'title', 'version', 'description', 'author', 'platform', 'permissions', 'signals',
			'schedules', 'outbound_hosts', 'send_email', 'accept_uploads', 'settings', 'models', 'admin_pages', 'profile_pages', 'public_pages', 'menu', 'limits');
		foreach (array_keys($d) as $key) {
			if (!in_array($key, $known, true)) {
				$e[] = "unknown key '" . self::show($key) . "'";
			}
		}

		$name = $d['name'] ?? null;
		if (!is_string($name) || !preg_match(self::NAME_PATTERN, $name)) {
			$e[] = "name must be 2-20 characters of a-z and 0-9, starting with a letter, with single _ between words";
		} elseif (in_array($name, PluginManager::RESERVED_NAMES, true)) {
			$e[] = "name '" . $name . "' is reserved";
		} elseif ($expected_name !== null && $name !== $expected_name) {
			$e[] = "name '" . $name . "' does not match its folder '" . self::show($expected_name) . "'";
		}
		if (!is_string($d['id'] ?? null) || !preg_match(self::ID_PATTERN, $d['id'])) {
			$e[] = 'id is required: 32 characters of 0-9 and a-f, made once (php -r "echo bin2hex(random_bytes(16));") and kept in every version';
		}
		if (!self::isText($d['title'] ?? null, 80, true)) {
			$e[] = 'title is required, at most 80 characters';
		}
		if (!is_string($d['version'] ?? null) || !preg_match(self::VERSION_PATTERN, $d['version'])) {
			$e[] = 'version must look like 1.0.0';
		}
		foreach (array('description' => 1000, 'author' => 120) as $key => $max) {
			if (isset($d[$key]) && !self::isText($d[$key], $max, false)) {
				$e[] = "$key must be text of at most $max characters";
			}
		}
		if (!is_int($d['platform'] ?? null) || $d['platform'] < 1) {
			$e[] = 'platform must be a whole number, the platform API version the plugin was built for';
		}

		self::checkPermissions($d['permissions'] ?? array(), $e);
		self::checkList($d, 'signals', self::SIGNAL_PATTERN, 'a signal name such as event.registered', $e);
		self::checkList($d, 'outbound_hosts', self::HOST_PATTERN, 'a hostname such as hooks.slack.com (no scheme, port, address or wildcard)', $e);
		self::checkSchedules($d['schedules'] ?? array(), $e);
		if (isset($d['send_email']) && !is_bool($d['send_email'])) {
			$e[] = 'send_email must be true or false';
		}
		if (isset($d['accept_uploads']) && !is_bool($d['accept_uploads'])) {
			$e[] = 'accept_uploads must be true or false';
		}
		$settings = self::checkSettings($d['settings'] ?? array(), $e);
		$models = self::checkModels($d['models'] ?? array(), is_string($name) ? $name : '', $e);
		$slugs = array();
		foreach (array('admin_pages', 'profile_pages', 'public_pages') as $key) {
			self::checkPages($d[$key] ?? array(), $key, $models, $settings, $slugs, $e);
		}
		if (isset($d['menu'])) {
			if (!is_array($d['menu']) || !self::isText($d['menu']['title'] ?? null, 40, true)) {
				$e[] = 'menu needs a title of at most 40 characters';
			} elseif (empty($d['admin_pages'])) {
				$e[] = 'menu needs at least one admin page to open';
			}
		}
		if (isset($d['limits'])) {
			$l = $d['limits'];
			if (!is_array($l) || array_diff(array_keys($l), array('memory_mb', 'timeout_ms'))) {
				$e[] = 'limits may hold only memory_mb and timeout_ms';
			} else {
				if (isset($l['memory_mb']) && (!is_int($l['memory_mb']) || $l['memory_mb'] < 1 || $l['memory_mb'] > self::MEMORY_SHAPE_MAX_MB)) {
					$e[] = 'limits.memory_mb must be a whole number of MB from 1 (a call gets at most what this site gives a page request, '
						. self::memoryCeilingMb() . ' MB here)';
				}
				if (isset($l['timeout_ms']) && (!is_int($l['timeout_ms']) || $l['timeout_ms'] < 100 || $l['timeout_ms'] > self::MAX_TIMEOUT_MS)) {
					$e[] = 'limits.timeout_ms must be 100 to ' . self::MAX_TIMEOUT_MS;
				}
			}
		}
		return $e;
	}

	private static function checkPermissions($p, array &$e): void {
		if (!is_array($p)) {
			$e[] = 'permissions must be an object';
			return;
		}
		if (array_diff(array_keys($p), array('actions', 'records', 'own_records'))) {
			$e[] = 'permissions may hold only actions, records and own_records';
		}
		// own_records: each member's own rows of a model, read as the member
		// who is viewing (design 8), checked as records are.
		$own = $p['own_records'] ?? array();
		if (!is_array($own) || (self::isList($own) && !empty($own))) {
			$e[] = 'permissions.own_records must map a model to its verbs, e.g. {"Order": ["read"]}';
		} else {
			foreach ($own as $model => $verbs) {
				if (!is_string($model) || !preg_match('~^[A-Z][A-Za-z0-9_]*$~D', $model)) {
					$e[] = "permissions.own_records: '" . self::show($model) . "' is not a model name";
				}
				if (!is_array($verbs) || !self::isList($verbs) || empty($verbs) || array_diff($verbs, ApiKey::RECORD_VERBS)) {
					$e[] = "permissions.own_records.$model must be a list of " . implode(', ', ApiKey::RECORD_VERBS);
				}
			}
		}
		foreach ((array)($p['actions'] ?? array()) as $action) {
			if (!is_string($action) || !preg_match(self::ACTION_PATTERN, $action)) {
				$e[] = "permissions.actions: '" . self::show($action) . "' is not an action name";
			}
		}
		if (isset($p['actions']) && !self::isList($p['actions'])) {
			$e[] = 'permissions.actions must be a list';
		}
		$records = $p['records'] ?? array();
		if (!is_array($records) || (self::isList($records) && !empty($records))) {
			$e[] = 'permissions.records must map a model to its verbs, e.g. {"Event": ["read"]}';
			return;
		}
		foreach ($records as $model => $verbs) {
			if (!is_string($model) || !preg_match('~^[A-Z][A-Za-z0-9_]*$~D', $model)) {
				$e[] = "permissions.records: '" . self::show($model) . "' is not a model name";
			}
			if (!is_array($verbs) || !self::isList($verbs) || empty($verbs)) {
				$e[] = "permissions.records.$model must be a list of verbs";
				continue;
			}
			foreach ($verbs as $verb) {
				if (!in_array($verb, ApiKey::RECORD_VERBS, true)) {
					$e[] = "permissions.records.$model: '" . self::show($verb) . "' is not one of " . implode(', ', ApiKey::RECORD_VERBS);
				}
			}
		}
	}

	private static function checkList(array $d, string $key, string $pattern, string $what, array &$e): void {
		if (!isset($d[$key])) {
			return;
		}
		if (!is_array($d[$key]) || !self::isList($d[$key])) {
			$e[] = "$key must be a list";
			return;
		}
		foreach ($d[$key] as $item) {
			if (!is_string($item) || !preg_match($pattern, $item)) {
				$e[] = "$key: '" . self::show($item) . "' is not $what";
			}
		}
		if (count(array_unique(array_filter($d[$key], 'is_string'))) !== count($d[$key])) {
			$e[] = "$key lists something twice";
		}
	}

	private static function checkSchedules($schedules, array &$e): void {
		if (!is_array($schedules) || !self::isList($schedules)) {
			$e[] = 'schedules must be a list';
			return;
		}
		$names = array();
		foreach ($schedules as $i => $s) {
			$label = 'schedules[' . $i . ']';
			if (!is_array($s) || !is_string($s['name'] ?? null) || !preg_match(self::SLUG_PATTERN, $s['name'])) {
				$e[] = "$label needs a name of a-z, 0-9 and _";
				continue;
			}
			if (isset($names[$s['name']])) {
				$e[] = "$label: the name '" . $s['name'] . "' is used twice";
			}
			$names[$s['name']] = true;
			if (array_diff(array_keys($s), array('name', 'frequency', 'time', 'day_of_week'))) {
				$e[] = "$label may hold only name, frequency, time and day_of_week";
			}
			if (!in_array($s['frequency'] ?? null, self::FREQUENCIES, true)) {
				$e[] = "$label: frequency must be one of " . implode(', ', self::FREQUENCIES);
				continue;
			}
			if ($s['frequency'] !== 'hourly' && (!is_string($s['time'] ?? null) || !preg_match('~^([01]\d|2[0-3]):[0-5]\d$~D', $s['time']))) {
				$e[] = "$label: a {$s['frequency']} schedule needs a time such as 08:00";
			}
			if ($s['frequency'] === 'weekly' && (!is_int($s['day_of_week'] ?? null) || $s['day_of_week'] < 0 || $s['day_of_week'] > 6)) {
				$e[] = "$label: a weekly schedule needs day_of_week 0 (Sunday) to 6";
			}
		}
	}

	/** @return array<string,array> setting name => declaration, for pages to refer to */
	private static function checkSettings($settings, array &$e): array {
		$out = array();
		if (!is_array($settings) || !self::isList($settings)) {
			$e[] = 'settings must be a list';
			return $out;
		}
		foreach ($settings as $i => $s) {
			$label = 'settings[' . $i . ']';
			if (!is_array($s) || !is_string($s['name'] ?? null) || !preg_match(self::FIELD_PATTERN, $s['name'])) {
				$e[] = "$label needs a name of a-z, 0-9 and _ (at most 20)";
				continue;
			}
			$name = $s['name'];
			if (isset($out[$name])) {
				$e[] = "$label: '$name' is declared twice";
			}
			if (array_diff(array_keys($s), array('name', 'label', 'type', 'secret', 'default', 'required', 'options', 'help'))) {
				$e[] = "settings.$name may hold only name, label, type, secret, default, required, options and help";
			}
			if (!self::isText($s['label'] ?? null, 80, true)) {
				$e[] = "settings.$name needs a label of at most 80 characters";
			}
			$type = $s['type'] ?? 'text';
			if (!in_array($type, self::SETTING_TYPES, true)) {
				$e[] = "settings.$name: type must be one of " . implode(', ', self::SETTING_TYPES);
			}
			if (isset($s['secret']) && !is_bool($s['secret'])) {
				$e[] = "settings.$name: secret must be true or false";
			}
			if (!empty($s['secret']) && !in_array($type, array('text', 'url'), true)) {
				$e[] = "settings.$name: only a text or url setting can be secret";
			}
			if (!empty($s['secret']) && isset($s['default']) && $s['default'] !== '') {
				$e[] = "settings.$name: a secret setting cannot carry a default";
			}
			if (isset($s['default']) && !is_scalar($s['default'])) {
				$e[] = "settings.$name: default must be a plain value";
			}
			if ($type === 'select' && !self::isOptions($s['options'] ?? null)) {
				$e[] = "settings.$name: a select needs options, a map of value to label";
			}
			if (isset($s['help']) && !self::isText($s['help'], 300, false)) {
				$e[] = "settings.$name: help must be at most 300 characters";
			}
			$out[$name] = $s;
		}
		return $out;
	}

	/** @return array<string,array> model name => field name => field */
	private static function checkModels($models, string $plugin, array &$e): array {
		$out = array();
		if (!is_array($models) || !self::isList($models)) {
			$e[] = 'models must be a list';
			return $out;
		}
		if (count($models) > 20) {
			$e[] = 'a plugin may declare at most 20 models';
		}
		foreach ($models as $i => $m) {
			$label = 'models[' . $i . ']';
			if (!is_array($m) || !is_string($m['name'] ?? null) || !preg_match(self::MODEL_PATTERN, $m['name'])) {
				$e[] = "$label needs a name like SentLog: a capital letter, then letters and digits (at most 20)";
				continue;
			}
			$model = $m['name'];
			foreach (array_keys($out) as $seen) {
				if (strcasecmp($seen, $model) === 0) {
					$e[] = "$label: '$model' is declared twice (model names are not told apart by case)";
				}
			}
			if (array_diff(array_keys($m), array('name', 'label', 'fields'))) {
				$e[] = "models.$model may hold only name, label and fields";
			}
			if (isset($m['label']) && !self::isText($m['label'], 80, true)) {
				$e[] = "models.$model: label must be at most 80 characters";
			}
			$fields = array();
			if (!is_array($m['fields'] ?? null) || !self::isList($m['fields']) || empty($m['fields'])) {
				$e[] = "models.$model needs a list of fields";
			} else {
				if (count($m['fields']) > 40) {
					$e[] = "models.$model: at most 40 fields";
				}
				foreach ($m['fields'] as $j => $f) {
					$flabel = "models.$model.fields[$j]";
					if (!is_array($f) || !is_string($f['name'] ?? null) || !preg_match(self::FIELD_PATTERN, $f['name'])) {
						$e[] = "$flabel needs a name of a-z, 0-9 and _ (at most 20)";
						continue;
					}
					$fname = $f['name'];
					if (in_array($fname, self::RESERVED_FIELDS, true)) {
						$e[] = "models.$model.$fname: the platform adds " . implode(', ', self::RESERVED_FIELDS) . ' itself';
					}
					if (substr($fname, -3) === '_id') {
						$e[] = "models.$model.$fname: a field name may not end in _id (it would read as a link to another table); name it for what it holds";
					}
					if (isset($fields[$fname])) {
						$e[] = "models.$model.$fname is declared twice";
					}
					if (array_diff(array_keys($f), array('name', 'type', 'label', 'required', 'max_length', 'index'))) {
						$e[] = "models.$model.$fname may hold only name, type, label, required, max_length and index";
					}
					$type = $f['type'] ?? null;
					if (!is_string($type) || !isset(self::FIELD_TYPES[$type])) {
						$e[] = "models.$model.$fname: type must be one of " . implode(', ', array_keys(self::FIELD_TYPES));
					}
					if (isset($f['max_length']) && ($type !== 'string' || !is_int($f['max_length']) || $f['max_length'] < 1 || $f['max_length'] > 2000)) {
						$e[] = "models.$model.$fname: max_length is for a string field, 1 to 2000";
					}
					foreach (array('required', 'index') as $flag) {
						if (isset($f[$flag]) && !is_bool($f[$flag])) {
							$e[] = "models.$model.$fname: $flag must be true or false";
						}
					}
					if (isset($f['label']) && !self::isText($f['label'], 80, true)) {
						$e[] = "models.$model.$fname: label must be at most 80 characters";
					}
					$fields[$fname] = $f;
				}
			}
			$out[$model] = $fields;
			if ($plugin !== '' && preg_match(self::NAME_PATTERN, $plugin)) {
				// Every name the database gets must fit its 63-character limit,
				// which it would otherwise cut silently: the table, its id
				// sequence, and each column.
				$prefix = self::prefixFor($plugin, $model);
				$names = array(
					self::tableFor($plugin, $model) . '_' . $prefix . '_id_seq',
					$prefix . '_create_time', $prefix . '_update_time', $prefix . '_delete_time',
				);
				foreach (array_keys($fields) as $fname) {
					$names[] = $prefix . '_' . $fname;
				}
				$longest = max(array_map('strlen', $names));
				if ($longest > 63) {
					$e[] = "models.$model: the plugin and model names together are too long for the database's names (at most 63 characters, this needs $longest); shorten one";
				}
			}
		}
		return $out;
	}

	private static function checkPages($pages, string $key, array $models, array $settings, array &$slugs, array &$e): void {
		if (!is_array($pages) || !self::isList($pages)) {
			$e[] = "$key must be a list";
			return;
		}
		// A public page holds what a member page may, and no more (design 1).
		$member = $key === 'profile_pages' || $key === 'public_pages';
		$public = $key === 'public_pages';
		foreach ($pages as $i => $page) {
			$label = $key . '[' . $i . ']';
			if (!is_array($page) || !is_string($page['slug'] ?? null) || !preg_match(self::SLUG_PATTERN, $page['slug'])) {
				$e[] = "$label needs a slug of a-z, 0-9 and _";
				continue;
			}
			$slug = $page['slug'];
			if (isset($slugs[$key][$slug])) {
				$e[] = "$label: the slug '$slug' is used twice";
			}
			$slugs[$key][$slug] = true;
			// A member page and a public page are both drawn by src/views/{slug}.php.
			if ($public && isset($slugs['profile_pages'][$slug])) {
				$e[] = "$label: the slug '$slug' is a member page's too, and both would be drawn by src/views/$slug.php";
			}
			if ($public) {
				if (array_diff(array_keys($page), array('slug', 'title', 'sections', 'frame', 'handlers', 'cache', 'cache_query'))) {
					$e[] = "$key.$slug may hold only slug, title, sections, frame, handlers, cache and cache_query";
				}
				if (array_key_exists('cache', $page) && !is_bool($page['cache'])) {
					$e[] = "$key.$slug: cache is true or false";
				}
				// The query names that make a different page, each cached apart;
				// a request with any other name is not cached (design 5).
				if (array_key_exists('cache_query', $page)) {
					if (!is_array($page['cache_query']) || !self::isList($page['cache_query']) || count($page['cache_query']) > 10) {
						$e[] = "$key.$slug: cache_query is a list of at most 10 query names";
					} else {
						foreach ($page['cache_query'] as $q) {
							if (!is_string($q) || !preg_match(self::QUERY_NAME_PATTERN, $q) || in_array($q, array('plugin', 'page'), true)) {
								$e[] = "$key.$slug: '" . self::show($q) . "' is not a query name (a-z, 0-9 and _, not plugin or page)";
							}
						}
					}
				}
			} elseif (array_diff(array_keys($page), array('slug', 'title', 'sections', 'frame', 'handlers'))) {
				$e[] = "$key.$slug may hold only slug, title, sections, frame and handlers";
			}
			// The handlers a framed page's script may call through core's
			// bridge; no other (an admin form's handler stays the admin's).
			if (array_key_exists('handlers', $page)) {
				if (empty($page['frame'])) {
					$e[] = "$key.$slug: handlers belong to a framed page";
				} elseif (!is_array($page['handlers']) || !self::isList($page['handlers'])) {
					$e[] = "$key.$slug: handlers is a list of handler names";
				} else {
					foreach ($page['handlers'] as $h) {
						if (!is_string($h) || !preg_match('~^[a-z][a-z0-9_]{0,39}$~D', $h)) {
							$e[] = "$key.$slug: '" . self::show($h) . "' is not a handler name";
						}
					}
				}
			}
			if (array_key_exists('frame', $page)) {
				// A framed page (design 9): the plugin's own document, script and
				// styles, served in a sealed frame. Drawn by its view, so no sections.
				if (!is_bool($page['frame'])) {
					$e[] = "$key.$slug: frame is true or false";
				} elseif ($page['frame'] && array_key_exists('sections', $page)) {
					$e[] = "$key.$slug: a framed page is drawn by its own view, so it has no sections";
				}
			}
			if (!self::isText($page['title'] ?? null, 80, true)) {
				$e[] = "$key.$slug needs a title of at most 80 characters";
			}
			// No sections: the page is drawn by the plugin's own view
			// (src/admin/{slug}.php or src/views/{slug}.php), cleaned by core.
			if (!array_key_exists('sections', $page)) {
				continue;
			}
			if (!is_array($page['sections']) || !self::isList($page['sections']) || empty($page['sections'])) {
				$e[] = "$key.$slug: sections, when given, is a list of at least one section";
				continue;
			}
			foreach ($page['sections'] as $j => $section) {
				self::checkSection($section, "$key.$slug.sections[$j]", $member, $models, $settings, $e);
			}
		}
	}

	private static function checkSection($s, string $label, bool $member, array $models, array $settings, array &$e): void {
		$type = is_array($s) ? ($s['type'] ?? null) : null;
		if (!in_array($type, self::SECTION_TYPES, true)) {
			$e[] = "$label: type must be one of " . implode(', ', self::SECTION_TYPES);
			return;
		}
		if (isset($s['title']) && !self::isText($s['title'], 80, true)) {
			$e[] = "$label: title must be at most 80 characters";
		}
		switch ($type) {
			case 'text':
				if (array_diff(array_keys($s), array('type', 'title', 'text'))) {
					$e[] = "$label: a text section holds type, title and text";
				}
				if (!self::isText($s['text'] ?? null, 2000, true)) {
					$e[] = "$label: text is required, at most 2000 characters";
				}
				break;

			case 'table':
				if ($member) {
					$e[] = "$label: a member or public page cannot show a table; a model's rows are for admins";
					break;
				}
				if (array_diff(array_keys($s), array('type', 'title', 'model', 'columns', 'order', 'limit'))) {
					$e[] = "$label: a table section holds type, title, model, columns, order and limit";
				}
				$model = $s['model'] ?? null;
				if (!is_string($model) || !isset($models[$model])) {
					$e[] = "$label: model must name one of this plugin's models";
					break;
				}
				$allowed = array_merge(array_keys($models[$model]), self::RESERVED_FIELDS);
				if (!is_array($s['columns'] ?? null) || !self::isList($s['columns']) || empty($s['columns'])) {
					$e[] = "$label: columns must list the fields to show";
				} else {
					foreach ($s['columns'] as $col) {
						if (!in_array($col, $allowed, true)) {
							$e[] = "$label: '" . self::show($col) . "' is not a field of $model";
						}
					}
				}
				if (isset($s['order'])) {
					$order = is_string($s['order']) ? ltrim($s['order'], '-') : null;
					if ($order === null || !in_array($order, $allowed, true)) {
						$e[] = "$label: order must be a field of $model, with a leading - for newest first";
					}
				}
				if (isset($s['limit']) && (!is_int($s['limit']) || $s['limit'] < 1 || $s['limit'] > 200)) {
					$e[] = "$label: limit must be 1 to 200";
				}
				break;

			case 'form':
				if (array_diff(array_keys($s), array('type', 'title', 'handler', 'fields', 'submit_label'))) {
					$e[] = "$label: a form section holds type, title, handler, fields and submit_label";
				}
				if (!is_string($s['handler'] ?? null) || !preg_match(self::SLUG_PATTERN, $s['handler'])) {
					$e[] = "$label: handler names what the plugin runs on submit, a-z, 0-9 and _";
				}
				if (isset($s['submit_label']) && !self::isText($s['submit_label'], 40, true)) {
					$e[] = "$label: submit_label must be at most 40 characters";
				}
				$fields = $s['fields'] ?? array();
				if (!is_array($fields) || !self::isList($fields)) {
					$e[] = "$label: fields must be a list";
					break;
				}
				$seen = array();
				foreach ($fields as $k => $f) {
					$flabel = "$label.fields[$k]";
					if (!is_array($f) || !is_string($f['name'] ?? null) || !preg_match(self::FIELD_PATTERN, $f['name'])) {
						$e[] = "$flabel needs a name of a-z, 0-9 and _";
						continue;
					}
					if (isset($seen[$f['name']])) {
						$e[] = "$flabel: '{$f['name']}' is used twice";
					}
					$seen[$f['name']] = true;
					// Names core reads or adds itself on a page's form (reviewer2 R4).
					if (in_array($f['name'], SandboxHtmlCleaner::RESERVED_NAMES, true)) {
						$e[] = "$flabel: '{$f['name']}' is a name the site uses on the form itself (its page keys and spam checks); choose another";
					}
					if (array_diff(array_keys($f), array('name', 'label', 'type', 'required', 'options', 'help'))) {
						$e[] = "$flabel may hold only name, label, type, required, options and help";
					}
					if (!self::isText($f['label'] ?? null, 80, true)) {
						$e[] = "$flabel needs a label of at most 80 characters";
					}
					$ftype = $f['type'] ?? 'text';
					if (!in_array($ftype, self::FORM_FIELD_TYPES, true)) {
						$e[] = "$flabel: type must be one of " . implode(', ', self::FORM_FIELD_TYPES);
					}
					if ($ftype === 'select' && !self::isOptions($f['options'] ?? null)) {
						$e[] = "$flabel: a select needs options, a map of value to label";
					}
					if (isset($f['help']) && !self::isText($f['help'], 300, false)) {
						$e[] = "$flabel: help must be at most 300 characters";
					}
				}
				break;

			case 'settings':
				if ($member) {
					$e[] = "$label: a member or public page cannot hold the plugin's settings";
					break;
				}
				if (array_diff(array_keys($s), array('type', 'title'))) {
					$e[] = "$label: a settings section holds type and title";
				}
				if (empty($settings)) {
					$e[] = "$label: the plugin declares no settings to show";
				}
				break;
		}
	}

	// ------------------------------------------------------------------
	// Answers
	// ------------------------------------------------------------------

	public function name(): string { return $this->data['name']; }

	/** The id the author gave the plugin, the same in every version of it. */
	public function id(): string { return $this->data['id']; }
	public function title(): string { return $this->data['title']; }
	public function version(): string { return $this->data['version']; }
	public function platform(): int { return (int)$this->data['platform']; }
	public function description(): string { return (string)($this->data['description'] ?? ''); }
	public function author(): string { return (string)($this->data['author'] ?? ''); }
	public function toArray(): array { return $this->data; }

	/** @return string[] */
	public function permittedActions(): array { return array_values($this->data['permissions']['actions'] ?? array()); }

	/** @return array<string,string[]> model class => verbs, for models outside the plugin */
	public function permittedRecords(): array { return $this->data['permissions']['records'] ?? array(); }

	/** @return array<string,string[]> model class => verbs on each member's own rows (design 8) */
	public function permittedOwnRecords(): array { return $this->data['permissions']['own_records'] ?? array(); }

	/** @return string[] */
	public function signals(): array { return array_values($this->data['signals'] ?? array()); }

	/** @return string[] */
	public function outboundHosts(): array { return array_values($this->data['outbound_hosts'] ?? array()); }

	/** Whether it sends mail (door 6). */
	public function sendsEmail(): bool { return ($this->data['send_email'] ?? false) === true; }

	/** Whether its drawn pages take uploaded files (door 7). */
	public function acceptsUploads(): bool { return ($this->data['accept_uploads'] ?? false) === true; }

	/** @return array[] */
	public function schedules(): array { return array_values($this->data['schedules'] ?? array()); }

	/** @return array<string,array> setting name => declaration */
	public function settings(): array {
		$out = array();
		foreach ($this->data['settings'] ?? array() as $s) {
			$out[$s['name']] = $s;
		}
		return $out;
	}

	/** @return array[] model declarations */
	public function models(): array { return array_values($this->data['models'] ?? array()); }

	/** @return array[] */
	public function adminPages(): array { return array_values($this->data['admin_pages'] ?? array()); }

	/** @return array[] */
	public function profilePages(): array { return array_values($this->data['profile_pages'] ?? array()); }

	/** Pages anyone can open, signed in or not, at /{plugin}/{slug}. */
	public function publicPages(): array { return array_values($this->data['public_pages'] ?? array()); }

	public function menu(): ?array { return $this->data['menu'] ?? null; }

	/**
	 * The most memory one call may have, in MB: the memory_limit the site's
	 * web server gives PHP, which is what a native plugin's code runs with.
	 * The same from a page and from cron (whose CLI PHP has no limit): in a
	 * web request it is this process's own; elsewhere it is read from the
	 * web server's PHP configuration: the first FPM pool found that sets
	 * php_admin_value or php_value [memory_limit], else the FPM php.ini, else
	 * Apache's (conf.d/*.ini overrides are not read). The fleet runs one FPM
	 * pool per box; on a box serving through mod_php beside an FPM php.ini, a
	 * call from cron may get FPM's value where a page call gets Apache's.
	 * With none set, or none readable, PHP's own default (MEMORY_FALLBACK_MB).
	 */
	public static function memoryCeilingMb(): int {
		if (self::$memory_ceiling_override !== null) {
			return max(1, (int)self::$memory_ceiling_override);
		}
		if (self::$memory_ceiling !== null) {
			return self::$memory_ceiling;
		}
		$value = null;
		if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
			$value = (string)ini_get('memory_limit');
		} else {
			$dir = '/etc/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
			foreach (glob($dir . '/fpm/pool.d/*.conf') ?: array() as $pool) {
				if (preg_match('~^\s*php(?:_admin)?_value\[memory_limit\]\s*=\s*(\S+)~m', (string)@file_get_contents($pool), $m)) {
					$value = $m[1];
					break;
				}
			}
			foreach (array($dir . '/fpm/php.ini', $dir . '/apache2/php.ini') as $ini) {
				if ($value === null && preg_match('~^\s*memory_limit\s*=\s*(\S+)~m', (string)@file_get_contents($ini), $m)) {
					$value = $m[1];
				}
			}
		}
		self::$memory_ceiling = self::megabytes((string)$value) ?? self::MEMORY_FALLBACK_MB;
		return self::$memory_ceiling;
	}

	/** A php.ini size (128M, 1G, 134217728) in whole MB; null for none, -1 or unreadable. */
	private static function megabytes(string $value): ?int {
		if (!preg_match('~^([0-9]+)([KMG]?)$~iD', trim($value, " \t\"'"), $m) || (int)$m[1] <= 0) {
			return null;
		}
		$bytes = (int)$m[1] * array('' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824)[strtoupper($m[2])];
		return $bytes >= 1048576 ? intdiv($bytes, 1048576) : null;
	}

	/** @return array{memory_mb:int,timeout_ms:int} the limits applied to every call */
	public function limits(): array {
		$l = $this->data['limits'] ?? array();
		return array(
			'memory_mb'  => min((int)($l['memory_mb'] ?? self::DEFAULT_MEMORY_MB), self::memoryCeilingMb()),
			'timeout_ms' => min((int)($l['timeout_ms'] ?? self::DEFAULT_TIMEOUT_MS), self::MAX_TIMEOUT_MS),
		);
	}

	/**
	 * The scope entries the plugin's API credential carries: the actions and
	 * records its permissions name, and every verb on its own models.
	 *
	 * @return string[]
	 */
	public function scopeEntries(): array {
		$entries = $this->permittedActions();
		foreach ($this->permittedRecords() as $model => $verbs) {
			foreach ($verbs as $verb) {
				$entries[] = $model . ':' . $verb;
			}
		}
		foreach ($this->models() as $model) {
			foreach (ApiKey::RECORD_VERBS as $verb) {
				$entries[] = self::classFor($this->name(), $model['name']) . ':' . $verb;
			}
		}
		return array_values(array_unique($entries));
	}

	// ------------------------------------------------------------------
	// Names the platform derives (each from validated parts)
	// ------------------------------------------------------------------

	/*
	 * Derived names are unique by construction, so two plugins cannot meet in
	 * a class, table or setting, and no sandboxed name can be a core or native
	 * one:
	 *  - a plugin name has no '__' and does not end in '_', and a model's
	 *    snake case has no '__', so '__' splits plugin from model, and from
	 *    a setting's name, exactly one way;
	 *  - a model name has no '_', so a class name splits at its last '_';
	 *  - every class starts 'Sbx_', and every table and setting 'sbx_', which
	 *    no core or native name does (tests/unit/sandbox_models_test.php).
	 *    PHP compares class names without case; 'Sbx_' + the lower-case
	 *    plugin holds whatever the case.
	 */

	/** The model's class: 'Sbx_' + plugin + '_' + model ('Sbx_notifier_SentLog'). */
	public static function classFor(string $plugin, string $model): string {
		return 'Sbx_' . $plugin . '_' . $model;
	}

	/** The model's column prefix: plugin + '__' + model in snake case ('notifier__sent_log'). */
	public static function prefixFor(string $plugin, string $model): string {
		return $plugin . '__' . self::snake($model);
	}

	/** The model's table: 'sbx_' + prefix ('sbx_notifier__sent_log'). */
	public static function tableFor(string $plugin, string $model): string {
		return 'sbx_' . self::prefixFor($plugin, $model);
	}

	public static function snake(string $studly): string {
		return strtolower(preg_replace('~(?<!^)[A-Z]~', '_$0', $studly));
	}

	/** The stored setting's name: 'sbx_' + plugin + '__' + setting ('sbx_notifier__webhook_url'). */
	public static function settingName(string $plugin, string $setting): string {
		return 'sbx_' . $plugin . '__' . $setting;
	}

	// ------------------------------------------------------------------

	private static function isText($v, int $max, bool $required): bool {
		if (!is_string($v)) {
			return !$required && $v === null;
		}
		$len = mb_strlen($v);
		return $len <= $max && (!$required || trim($v) !== '');
	}

	private static function isList($v): bool {
		return is_array($v) && ($v === array() || array_keys($v) === range(0, count($v) - 1));
	}

	private static function isOptions($v): bool {
		if (!is_array($v) || empty($v) || self::isList($v)) {
			return false;
		}
		foreach ($v as $value => $label) {
			if (!is_string($label) || mb_strlen($label) > 80 || mb_strlen((string)$value) > 80) {
				return false;
			}
		}
		return true;
	}

	/** A value for an error message, cut short and stripped of control characters. */
	private static function show($v): string {
		$s = is_scalar($v) ? (string)$v : gettype($v);
		return mb_substr(preg_replace('~[\x00-\x1F\x7F]~u', '?', $s), 0, 60);
	}
}
