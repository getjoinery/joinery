<?php
/**
 * ProblemReportBundle — what a problem report says about this site.
 *
 * An explicit allowlist: each section is a named collector with a fixed
 * output shape, and there is no free-form dump. Every text value passes
 * through LogRedactor::text() before it is stored, shown or sent. The report
 * page renders the bundle for the reporter before anything is sent, so what
 * they read is what leaves.
 *
 * Who sees what. Any signed-in member may report (specs/bug_reports.md D3),
 * and the reporter reads every line of the bundle. So the sections that
 * describe the whole site — its other errors, its runtime, its plugins, its
 * settings, its health — go only into an operator's report (permission
 * OPERATOR_PERMISSION, the level that already reads the error log). A
 * member's report carries the site's name and version, the request, the
 * reporter, and the reported error, and a member may attach only an error
 * recorded on their own account.
 *
 * Never included: member names or addresses, email bodies or subjects, file
 * names from Drive or mail, form submissions, raw request bodies, session
 * contents, IP addresses, any setting value, anything under config/, anything
 * sealed. Building a bundle opens no sealed content: settings are compared as
 * stored, never decrypted.
 *
 * @version 1.1.0 - automatic(): the bundle a site sends on its own; fingerprint(): the same-fault key
 * @version 1.0.1
 */
class ProblemReportBundle {

	/** Bundle shape version, for the receiver. */
	const FORMAT = 1;

	/** Permission level whose reports carry the site-wide sections. */
	const OPERATOR_PERMISSION = 9;

	/** Error-log lines in an operator's report. */
	const RECENT_LINES = 40;

	/** Stack frames in the error section. */
	const TRACE_FRAMES = 5;

	/** Longest single text value carried, in characters. */
	const VALUE_CAP = 1000;

	/** Section keys, in display order, with the heading the page shows. */
	const SECTIONS = array(
		'site'          => 'Site',
		'request'       => 'Request',
		'who'           => 'Who is reporting',
		'error'         => 'Error',
		'recent_errors' => 'Recent errors on this site',
		'runtime'       => 'Runtime',
		'plugins'       => 'Plugins',
		'settings'      => 'Settings',
		'health'        => 'Health',
	);

	/**
	 * Build the bundle for one report.
	 *
	 * @param int         $user_id      the reporter
	 * @param int         $permission   the reporter's permission level
	 * @param bool        $logged_in_as the reporter is an administrator acting as this user
	 * @param int|null    $error_id     the err_general_errors row reported, if any
	 * @param string      $from         the path and query where the problem showed
	 * @param string      $message      the message the reporter saw, when there is no row
	 * @return array the bundle; only JSON-safe scalars and arrays
	 */
	public static function build(int $user_id, int $permission, bool $logged_in_as, ?int $error_id, string $from, string $message): array {
		$operator = $permission >= self::OPERATOR_PERMISSION;
		$error_row = self::errorRowFor($error_id, $user_id, $operator);

		$bundle = array(
			'format'  => self::FORMAT,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scope'   => $operator ? 'operator' : 'member',
			'site'    => self::site(),
			'request' => self::request($from),
			'who'     => array(
				'user_id'      => $user_id,
				'permission'   => $permission,
				'logged_in_as' => $logged_in_as,
			),
			'error'   => $error_row ? self::errorFromRow($error_row) : self::errorFromMessage($message),
		);

		if ($operator) {
			$bundle['recent_errors'] = self::recentErrors($error_row);
			$bundle['runtime']       = self::runtime();
			$bundle['plugins']       = self::plugins();
			$bundle['settings']      = self::settings();
			$bundle['health']        = self::health();
		}

		return $bundle;
	}

	/**
	 * The bundle a site sends on its own when an unexpected error is recorded
	 * (specs/bug_reports.md, Part 2). Nobody reads it before it goes, so it
	 * carries less than an operator's: no reporter, no other requests' log
	 * lines, the page's path masked to its shape, and quoted text in the
	 * message masked. Built from the saved row, never the live exception, so
	 * an error recorded while sealed content was open carries only the row's
	 * withheld reference.
	 *
	 * @param GeneralError $row   the recorded error
	 * @param string       $class the exception's class
	 * @param string       $from  the path and query of the failing request
	 */
	public static function automatic(GeneralError $row, string $class, string $from): array {
		$error = self::errorFromRow($row);
		$error['class'] = self::clean($class);
		$error['message'] = self::maskQuoted($error['message']);
		$request = self::request(self::pathShape($from));
		unset($request['timezone']);
		if (php_sapi_name() === 'cli') {
			$request['surface'] = 'cli';
		}
		return array(
			'format'   => self::FORMAT,
			'created'  => gmdate('Y-m-d\TH:i:s\Z'),
			'scope'    => 'automatic',
			'site'     => self::site(),
			'request'  => $request,
			'error'    => $error,
			'runtime'  => self::runtime(),
			'plugins'  => self::plugins(),
			'settings' => self::settings(),
			'health'   => self::health(),
		);
	}

	/**
	 * A path masked to its shape: a segment that is not a number or a
	 * lowercase word (letters, digits, underscores) becomes "…". Slugs, names
	 * and addresses in a path go; the route stays. The query is kept for
	 * request(), which masks every value but a plain number.
	 */
	public static function pathShape(string $from): string {
		$path = (string)parse_url($from, PHP_URL_PATH);
		$query = (string)parse_url($from, PHP_URL_QUERY);
		$segments = array();
		foreach (explode('/', $path) as $segment) {
			$segments[] = ($segment === '' || preg_match('/^[a-z][a-z0-9_]{0,63}$|^\d{1,18}$/', $segment)) ? $segment : '…';
		}
		return implode('/', $segments) . ($query !== '' ? '?' . $query : '');
	}

	/** Quoted text in a message masked: 'bob' and "bob" become '…' and "…". */
	public static function maskQuoted(string $message): string {
		return preg_replace(array("/'[^']*'/u", '/"[^"]*"/u'), array("'…'", '"…"'), $message);
	}

	/**
	 * The same-fault key (specs/bug_reports.md D11): the error's kind, the
	 * file it was thrown in, and the files and function names of its stack
	 * frames, with every line number dropped so small edits do not split a
	 * group. With no frames, the message with numbers, quoted text and long
	 * hex runs blanked stands in. NULL when the error names neither a place
	 * nor a message.
	 *
	 * Computed from a bundle's error section, so the receiver can compute it
	 * for any report, from any version, without trusting the sender's own.
	 */
	public static function fingerprint(array $error): ?string {
		$kind = is_scalar($error['kind'] ?? null) ? (string)$error['kind'] : '';
		$file = is_scalar($error['file'] ?? null) ? (string)$error['file'] : '';
		$frames = array();
		foreach ((is_array($error['trace'] ?? null) ? $error['trace'] : array()) as $frame) {
			if (!is_string($frame)) {
				continue;
			}
			// "#0 lib/x.php(12): Class->method()" -> "lib/x.php: Class->method()"
			$frame = preg_replace('/^#\d+\s+/', '', trim($frame));
			$frame = preg_replace('/\(\d+\):/', ':', $frame);
			$frames[] = $frame;
		}
		if ($frames) {
			return md5('v1|' . $kind . '|' . $file . '|' . implode('|', $frames));
		}
		$message = is_scalar($error['message'] ?? null) ? (string)$error['message'] : '';
		if ($file === '' && trim($message) === '') {
			return null;
		}
		$message = self::maskQuoted($message);
		$message = preg_replace(array('/[0-9a-f]{8,}/i', '/\d+/', '/\s+/'), array('H', 'N', ' '), $message);
		return md5('v1|' . $kind . '|' . $file . '|' . trim($message));
	}

	/**
	 * The error row a reporter may attach: an operator any, a member only one
	 * recorded on their own account. NULL when there is none to attach.
	 */
	public static function errorRowFor(?int $error_id, int $user_id, bool $operator): ?GeneralError {
		if (!$error_id || $error_id <= 0) {
			return null;
		}
		try {
			$row = new GeneralError($error_id, TRUE);
		} catch (\Throwable $e) {
			return null;
		}
		if (!$row->key) {
			return null;
		}
		if (!$operator && (int)$row->get('err_usr_user_id') !== $user_id) {
			return null;
		}
		return $row;
	}

	/**
	 * One text value, masked and capped. The site's own directory is dropped
	 * from paths: where this install lives on disk says nothing about the
	 * fault, and the file relative to public_html is what groups it.
	 */
	public static function clean($value): string {
		$text = (string)$value;
		$text = str_replace(array(
			rtrim(PathHelper::getRootDir(), '/') . '/',
			rtrim(PathHelper::getSiteRoot(), '/') . '/',
		), '', $text);
		$text = LogRedactor::text($text);
		if (mb_strlen($text) > self::VALUE_CAP) {
			$text = mb_substr($text, 0, self::VALUE_CAP) . '…';
		}
		return $text;
	}

	// ---------------------------------------------------------------- sections

	public static function site(): array {
		$dblink = DbConnector::get_instance()->get_db_link();
		$install_days = null;
		try {
			$first = $dblink->query('SELECT MIN(usr_signup_date) FROM usr_users')->fetchColumn();
			if ($first) {
				$install_days = (int)floor((time() - strtotime($first . ' UTC')) / 86400);
			}
		} catch (\Throwable $e) {
			// No users table: the age stays unknown.
		}
		// The schema version is the highest migration applied.
		$schema = '';
		try {
			$schema = (string)$dblink->query('SELECT MAX(mig_version) FROM mig_migrations')->fetchColumn();
		} catch (\Throwable $e) {
			// No migrations table: the version stays unknown.
		}
		return array(
			'host'             => self::clean(ServicesClient::host()),
			'version'          => self::clean(LibraryFunctions::get_joinery_version()),
			'schema_version'   => self::clean($schema),
			'theme'            => self::clean((string)ThemeHelper::getActive()),
			'install_age_days' => $install_days,
		);
	}

	/**
	 * Where it happened. The path is kept whole (D6: the reporter sees it in
	 * the preview); query values are masked except plain numbers, which are
	 * row ids and page numbers and name nobody.
	 */
	public static function request(string $from): array {
		$path = (string)parse_url($from, PHP_URL_PATH);
		$query = (string)parse_url($from, PHP_URL_QUERY);
		$masked = array();
		if ($query !== '') {
			foreach (explode('&', $query) as $pair) {
				if ($pair === '') {
					continue;
				}
				$parts = explode('=', $pair, 2);
				$key = urldecode($parts[0]);
				$value = isset($parts[1]) ? urldecode($parts[1]) : '';
				$masked[] = $key . '=' . (preg_match('/^\d{1,12}$/', $value) ? $value : '…');
			}
		}
		$shown = $path . ($masked ? '?' . implode('&', $masked) : '');

		$surface = 'web';
		if (strpos($path, '/admin') === 0 || strpos($path, '/adm/') === 0) {
			$surface = 'admin';
		} elseif (strpos($path, '/api/') === 0) {
			$surface = 'api';
		}

		$session = SessionControl::get_instance();
		return array(
			'path'     => self::clean($shown),
			'surface'  => $surface,
			'app'      => (bool)$session->is_app_session(),
			'browser'  => self::clean(SessionControl::getBrowser()),
			'os'       => self::clean(SessionControl::getOS()),
			'timezone' => self::clean((string)$session->get_timezone('UTC')),
		);
	}

	/** The reported error, from its recorded row. Session and request dumps stay behind. */
	public static function errorFromRow(GeneralError $row): array {
		$file = ErrorReference::relativeFile((string)$row->get('err_file'));
		$same_location = null;
		try {
			$stmt = DbConnector::get_instance()->get_db_link()->prepare(
				"SELECT COUNT(*) FROM err_general_errors
				  WHERE err_file = :file AND err_line = :line
				    AND err_create_time > now() - INTERVAL '7 days'");
			$stmt->execute(array(':file' => (string)$row->get('err_file'), ':line' => (string)$row->get('err_line')));
			$same_location = (int)$stmt->fetchColumn();
		} catch (\Throwable $e) {
			// The count is a nicety; the report stands without it.
		}
		return array(
			'id'               => (int)$row->key,
			'kind'             => self::clean($row->get('err_level')),
			'code'             => self::clean($row->get('err_code')),
			'file'             => self::clean($file),
			'line'             => self::clean($row->get('err_line')),
			'message'          => self::clean($row->get('err_message')),
			'trace'            => self::traceFrames((string)$row->get('err_context')),
			'hash'             => ErrorReference::hashForRow($row),
			'time'             => (string)$row->get('err_create_time'),
			'same_location_7d' => $same_location,
		);
	}

	/** The error, when there is no recorded row: the message the reporter saw. */
	public static function errorFromMessage(string $message): array {
		return array(
			'id'      => null,
			'message' => self::clean($message),
			'hash'    => null,
		);
	}

	/**
	 * The first stack frames from a recorded error's context, with argument
	 * lists dropped (arguments are values, and values name people) and paths
	 * made relative.
	 */
	public static function traceFrames(string $context): array {
		$text = html_entity_decode(strip_tags($context), ENT_QUOTES, 'UTF-8');
		$frames = array();
		foreach (preg_split('/\r?\n/', $text) as $line) {
			$line = trim($line);
			if (!preg_match('/^#\d+ /', $line)) {
				continue;
			}
			// "#0 /path/file.php(12): Class->method('arg', 3)" -> "... Class->method()"
			$line = preg_replace('/^(#\d+ .*?\(\d+\): [^(]*)\(.*\)$/', '$1()', $line);
			$line = str_replace(rtrim(PathHelper::getRootDir(), '/') . '/', '', $line);
			$frames[] = self::clean($line);
			if (count($frames) >= self::TRACE_FRAMES) {
				break;
			}
		}
		return $frames;
	}

	/** The newest error-log lines naming an error, masked. Operator reports only. */
	public static function recentErrors(?GeneralError $row): array {
		$log_path = dirname(rtrim(PathHelper::getRootDir(), '/')) . '/logs/error.log';
		$lines = array();
		$readable = @is_readable($log_path);
		if ($readable) {
			foreach (array_reverse(self::tail($log_path, 4000)) as $line) {
				if (self::isErrorLine($line)) {
					$lines[] = self::clean(rtrim($line));
					if (count($lines) >= self::RECENT_LINES) {
						break;
					}
				}
			}
		}
		return array(
			'log_readable' => $readable,
			'lines'        => $lines,
		);
	}

	/**
	 * Whether a log line records a PHP-level error: a fatal, warning or parse
	 * error, an uncaught exception, or a structured error-handler entry. The
	 * web server's own lines (a probe for /wp-login.php answered 404) are
	 * noise that would crowd out the one line that matters.
	 */
	public static function isErrorLine(string $line): bool {
		if (stripos($line, 'ROUTING ERROR') !== false) {
			return false;
		}
		return (bool)preg_match('/PHP (Fatal|Parse|Warning|Recoverable)|Uncaught|"level":"(ERROR|CRITICAL|SECURITY)"|Stack trace|Exception:/', $line);
	}

	/** Versions of what runs this site. Operator reports only. */
	public static function runtime(): array {
		$pg = null;
		try {
			$full = (string)DbConnector::get_instance()->get_db_link()->query('SELECT version()')->fetchColumn();
			if (preg_match('/^PostgreSQL [0-9.]+/', $full, $m)) {
				$pg = $m[0];
			}
		} catch (\Throwable $e) {
			// Unknown stays null.
		}
		$join_state = ManagementNodeStatus::join_state();
		return array(
			'php'             => PHP_VERSION,
			'postgresql'      => $pg,
			'os'              => self::clean(php_uname('s') . ' ' . php_uname('r')),
			'web_server'      => self::clean((string)($_SERVER['SERVER_SOFTWARE'] ?? '')),
			'agent_installed' => $join_state !== null,
			'agent_connected' => ManagementNodeStatus::is_managed(),
		);
	}

	/** Every installed plugin, its version and whether it is active. Operator reports only. */
	public static function plugins(): array {
		$out = array();
		try {
			foreach (MultiPlugin::get_all_plugins_with_status() as $plugin) {
				// Installed means a registry row; a directory alone is not.
				if (empty($plugin['plugin']) || empty($plugin['name'])) {
					continue;
				}
				$out[] = array(
					'name'    => self::clean((string)$plugin['name']),
					'version' => self::clean((string)($plugin['version'] ?? '')),
					'active'  => !empty($plugin['is_active']),
				);
			}
		} catch (\Throwable $e) {
			// No plugin registry: an empty list.
		}
		return $out;
	}

	/**
	 * Names of the declared settings changed from their defaults, and counts
	 * of sealed secrets. Never a value. Credentials and machine-written
	 * settings are left out of the list: a credential's name says only that it
	 * is set, and a machine-written one changes on its own. Stored values are
	 * compared as stored, so nothing sealed is opened. Operator reports only.
	 */
	public static function settings(): array {
		$changed = array();
		$credentials_set = 0;
		try {
			$stored = array();
			$q = DbConnector::get_instance()->get_db_link()->query('SELECT stg_name, stg_value FROM stg_settings');
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$stored[$r['stg_name']] = (string)$r['stg_value'];
			}
			foreach (SettingsDeclarations::active() as $name => $declaration) {
				if (!array_key_exists($name, $stored)) {
					continue;
				}
				if (!empty($declaration['secret'])) {
					if ($stored[$name] !== '') {
						$credentials_set++;
					}
					continue;
				}
				if (!empty($declaration['managed'])) {
					continue;
				}
				if ($stored[$name] !== (string)($declaration['default'] ?? '')) {
					$changed[] = $name;
				}
			}
			sort($changed);
		} catch (\Throwable $e) {
			// No settings table: nothing to list.
		}

		$sealed_total = null;
		$dead = null;
		try {
			$sealed_total = count(new MultiSealedSecretRegistry(array()));
			$verdict = SecretReconciler::attention_verdict();
			$dead = (int)$verdict['operator'] + (int)$verdict['needs_ack'] + (int)$verdict['low'];
		} catch (\Throwable $e) {
			// No registry here: counts stay unknown.
		}

		return array(
			'changed_from_default' => $changed,
			'credentials_set'      => $credentials_set,
			'sealed_secrets'       => $sealed_total,
			'sealed_secrets_dead'  => $dead,
		);
	}

	/** Disk, memory, load and cron. Operator reports only. */
	public static function health(): array {
		$out = array(
			'disk_free_percent'   => null,
			'memory_used_percent' => null,
			'load_1m'             => null,
			'cron_last_run_minutes_ago' => null,
		);
		// The management API's stats are the one place these are read.
		require_once(PathHelper::getIncludePath('includes/management_api/stats_handler.php'));
		try {
			$stats = stats_handler(array());
		} catch (\Throwable $e) {
			return $out;
		}
		if (isset($stats['disk_usage_percent'])) {
			$out['disk_free_percent'] = 100 - (int)$stats['disk_usage_percent'];
		}
		if (!empty($stats['memory_total_mb'])) {
			$out['memory_used_percent'] = (int)round((int)$stats['memory_used_mb'] * 100 / (int)$stats['memory_total_mb']);
		}
		if (isset($stats['load_1m'])) {
			$out['load_1m'] = round((float)$stats['load_1m'], 2);
		}
		$last = (string)($stats['cron_last_run'] ?? '');
		if ($last !== '') {
			$ts = strtotime($last . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $last) ? '' : ' UTC'));
			if ($ts) {
				$out['cron_last_run_minutes_ago'] = (int)floor((time() - $ts) / 60);
			}
		}
		return $out;
	}

	// ----------------------------------------------------------------- display

	/** Readable names for bundle keys the page shows. Unlisted keys read as themselves. */
	const LABELS = array(
		'install_age_days'          => 'Installed (days ago)',
		'schema_version'            => 'Schema version',
		'os'                        => 'Operating system',
		'app'                       => 'In the mobile app',
		'user_id'                   => 'User id',
		'logged_in_as'              => 'An administrator acting as this user',
		'same_location_7d'          => 'Errors at this place, last 7 days',
		'log_readable'              => 'Error log readable',
		'lines'                     => 'Lines',
		'php'                       => 'PHP',
		'postgresql'                => 'PostgreSQL',
		'web_server'                => 'Web server',
		'agent_installed'           => 'Agent installed',
		'agent_connected'           => 'Agent connected',
		'changed_from_default'      => 'Changed from default (names only)',
		'credentials_set'           => 'Credentials set',
		'sealed_secrets'            => 'Sealed secrets',
		'sealed_secrets_dead'       => 'Sealed secrets that no longer open',
		'disk_free_percent'         => 'Disk free (%)',
		'memory_used_percent'       => 'Memory used (%)',
		'load_1m'                   => 'Load (1 minute)',
		'cron_last_run_minutes_ago' => 'Scheduled tasks last ran (minutes ago)',
		'id'                        => 'Error id',
		'class'                     => 'Exception class',
	);

	/**
	 * The bundle as labeled rows per section, for the page and the admin
	 * list: array of [heading, [[label, value], ...]]. A list of values is one
	 * row, one value per line; a list of records (plugins) is one row each.
	 * Values are plain text; the caller escapes them and keeps line breaks.
	 */
	public static function displayRows(array $bundle): array {
		$out = array();
		foreach (self::SECTIONS as $key => $heading) {
			if (!array_key_exists($key, $bundle)) {
				continue;
			}
			$rows = array();
			$value = $bundle[$key];
			if (is_array($value) && self::isList($value)) {
				self::listRows($value, $heading, $rows);
			} elseif (is_array($value)) {
				foreach ($value as $k => $v) {
					$label = self::LABELS[$k] ?? ucfirst(str_replace('_', ' ', (string)$k));
					if (is_array($v) && self::isList($v)) {
						self::listRows($v, $label, $rows);
					} else {
						$rows[] = array($label, self::scalarText($v));
					}
				}
			} else {
				$rows[] = array($heading, self::scalarText($value));
			}
			$out[] = array($heading, $rows);
		}
		return $out;
	}

	private static function isList(array $value): bool {
		return $value === array() || array_keys($value) === range(0, count($value) - 1);
	}

	/** A list of scalars as one row; a list of records as one row per record, named by its first value. */
	private static function listRows(array $list, string $label, array &$rows): void {
		if ($list === array()) {
			$rows[] = array($label, '(none)');
			return;
		}
		if (is_array(reset($list))) {
			foreach ($list as $record) {
				$record = (array)$record;
				$name = self::scalarText(array_shift($record));
				$rest = array();
				foreach ($record as $k => $v) {
					$rest[] = is_bool($v) ? ($v ? $k : 'not ' . $k) : self::scalarText($v);
				}
				$rows[] = array($name, implode(', ', $rest));
			}
			return;
		}
		$rows[] = array($label, implode("\n", array_map(array(__CLASS__, 'scalarText'), $list)));
	}

	private static function scalarText($value): string {
		if (is_bool($value)) {
			return $value ? 'yes' : 'no';
		}
		if ($value === null) {
			return '(none)';
		}
		if (is_array($value)) {
			return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		return (string)$value;
	}

	/** The last $max_lines lines of a file, read from the end. */
	private static function tail(string $path, int $max_lines): array {
		$fh = @fopen($path, 'r');
		if (!$fh) {
			return array();
		}
		$buffer = '';
		fseek($fh, 0, SEEK_END);
		$pos = ftell($fh);
		// Bounded read: the log is verbose and a report needs its tail only.
		$budget = 4 * 1024 * 1024;
		while ($pos > 0 && substr_count($buffer, "\n") <= $max_lines && strlen($buffer) < $budget) {
			$read = min(65536, $pos);
			$pos -= $read;
			fseek($fh, $pos);
			$buffer = fread($fh, $read) . $buffer;
		}
		fclose($fh);
		$lines = explode("\n", $buffer);
		return array_slice($lines, -$max_lines);
	}
}
