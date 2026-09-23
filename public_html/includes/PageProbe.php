<?php
/**
 * PageProbe - the request side of the agent's page_probe word.
 *
 * specs/agent_recipes_and_vocabulary.md, "page_probe {page, viewer}". The
 * node renders one of its own pages as a throwaway viewer and reports facts
 * about the render, never the render. utils/page_probe.php (the script the
 * agent runs) mints a PageProbeGrant and sends ONE request over this
 * machine's own connection carrying the grant's token; this class is what
 * that request meets.
 *
 * THE PROBE SESSION, a session type of its own:
 *
 *   - claimed only by a request that carries the token, arrives from this
 *     machine (the loopback or the server's own address), is a GET or HEAD,
 *     asks for exactly the page the grant names with no query string, and
 *     comes within the grant's minute. The grant is consumed on first use.
 *   - no cookie is read or written and no PHP session is started: the
 *     identity lives for this one request, in memory, and is logged as a
 *     probe, never as a borrowed account. The viewer is the throwaway user
 *     the script made for it, or nobody.
 *   - view-only, enforced: once claimed, every statement that writes throws
 *     at the database layer (GuardedPdo::$read_only) unless it is the
 *     server's own write (SystemBase::server_initiated_write: the grant
 *     consumed, the report, a request-log or error row). A render that tries
 *     to persist anything fails there, and the report names where.
 *
 * THE REPORT, written to the grant at shutdown: render time, statements run,
 * peak memory, and each PHP warning or error raised as its type and file:line
 * only — never the message text, which can carry a row's value.
 *
 * @version 1.1 - review 2026-09-23: view-only enforced at the database layer (B3); failed assets named
 *                only when the file is in this tree (B4); a probe request is marked in the request log (B5)
 * @version 1.0
 */
class PageProbe {

	/** The request header, as PHP presents it. */
	const HEADER = 'HTTP_X_JOINERY_PROBE';

	/** The user agent the probe sends: a bot to the analytics filter, so no visitor event. */
	const USER_AGENT = 'joinery-page-probe bot/1.0';

	const VIEWERS = ['anonymous', 'member', 'admin'];

	/** A page a probe may name: a path, no query, no empty segment, at most six segments. The agent's own copy is pageProbePage. */
	const PAGE_PATTERN = '#^/([a-z0-9_-]{1,80}(/[a-z0-9_-]{1,80}){0,5})?$#';

	/** How long a grant may wait for its request. */
	const GRANT_SECONDS = 90;

	/** The most warnings one report carries. */
	const MAX_WARNINGS = 50;

	/**
	 * Pages that ACT on arrival: a handler whose view or logic changes state
	 * just by being fetched (a sign-out, an OAuth return, a wizard step, a
	 * token-carrying reset or verification). A probe never renders one,
	 * whatever the node's own page list says. Pinned by
	 * tests/unit/page_probe_test.php, which also fails when a core view's
	 * logic starts writing on a GET without being named here.
	 */
	const REFUSED_PAGES = [
		'/logout',
		'/oauth_callback',
		'/setup',
		'/app_bridge',
		'/services_connected',
		'/sm_ssl_probe',
		'/survey_finish',
		'/recovery-verify',
		'/password-reset-1',
		'/password-reset-2',
		'/password-reset-2fa',
		'/password-reset-totp',
		'/password-set',
		'/terms-accept',
		'/verify-stepup',
		'/verify-totp',
		'/change-password-required',
		'/admin/admin_user_login_as',
	];

	/** @var PageProbeGrant|null the grant this request claimed */
	private static $grant = null;
	private static $warnings = [];
	private static $previous_handler = null;

	public static function active(): bool {
		return self::$grant !== null;
	}

	/**
	 * Called by SessionControl on a web request before any session starts.
	 * Returns the identity array to run this one request as, or null when the
	 * request is not a probe (the overwhelming case: no header, one isset()).
	 */
	public static function claim_request(): ?array {
		$token = $_SERVER[self::HEADER] ?? '';
		if ($token === '') {
			return null;
		}
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			return null;
		}
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
		if ($method !== 'GET' && $method !== 'HEAD') {
			return null;
		}
		if (!self::from_this_machine()) {
			return null;
		}
		$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
		if (strpos($uri, '?') !== false) {
			return null;
		}
		$grant = PageProbeGrant::for_token_hash(hash('sha256', $token));
		if (!$grant || $grant->get('ppg_consumed_time')) {
			return null;
		}
		if (strtotime((string)$grant->get('ppg_expires_time') . ' UTC') < time()) {
			return null;
		}
		if (rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') !== rtrim((string)$grant->get('ppg_page'), '/')) {
			return null;
		}
		SystemBase::server_initiated_write(function () use ($grant) {
			$grant->set('ppg_consumed_time', gmdate('Y-m-d H:i:s'));
			$grant->save();
		});
		self::$grant = $grant;
		// View-only in fact: from here, a statement that writes throws unless
		// it is the server's own (the report below, a request-log row, an
		// error row), all of which go through server_initiated_write.
		GuardedPdo::$read_only = true;
		self::begin();

		$identity = ['probe' => true, 'uniqid' => 'probe-' . substr($token, 0, 12)];
		$user_id = (int)$grant->get('ppg_usr_user_id');
		if ($user_id > 0) {
			$user = new User($user_id, TRUE);
			$identity['loggedin'] = true;
			$identity['usr_user_id'] = $user->key;
			$identity['permission'] = $user->get('usr_permission');
			$identity['timezone'] = $user->get('usr_timezone') ?: 'UTC';
		}
		return $identity;
	}

	/** The loopback, or this server's own address (a vhost bound to it). */
	private static function from_this_machine(): bool {
		$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		if ($remote === '127.0.0.1' || $remote === '::1') {
			return true;
		}
		$server = (string)($_SERVER['SERVER_ADDR'] ?? '');
		return $remote !== '' && $remote === $server;
	}

	private static function begin(): void {
		self::$previous_handler = set_error_handler([self::class, 'collect']);
		register_shutdown_function([self::class, 'finish']);
	}

	/** Record a warning's type and place; never its message. Then carry on as before. */
	public static function collect($errno, $errstr, $file = '', $line = 0) {
		if (count(self::$warnings) < self::MAX_WARNINGS) {
			self::$warnings[] = ['type' => self::type_name($errno), 'at' => self::relative($file) . ':' . (int)$line];
		}
		if (self::$previous_handler) {
			return call_user_func(self::$previous_handler, $errno, $errstr, $file, $line);
		}
		return false;
	}

	/**
	 * A write the view-only probe session refused, as the first frame outside
	 * the database and model layers: where the render tried to persist.
	 */
	public static function note_refused_write(array $trace): void {
		if (!self::$grant || count(self::$warnings) >= self::MAX_WARNINGS) {
			return;
		}
		$at = 'unknown:0';
		foreach ($trace as $f) {
			$file = (string)($f['file'] ?? '');
			if ($file === '' || preg_match('#/includes/(GuardedPdo|SystemBase|DbConnector)\.php$#', $file)) { continue; }
			$at = self::relative($file) . ':' . (int)($f['line'] ?? 0);
			break;
		}
		self::$warnings[] = ['type' => 'refused_write', 'at' => $at];
	}

	public static function type_name($errno): string {
		switch ($errno) {
			case E_WARNING: case E_USER_WARNING: case E_CORE_WARNING: case E_COMPILE_WARNING: return 'warning';
			case E_NOTICE: case E_USER_NOTICE: return 'notice';
			case E_DEPRECATED: case E_USER_DEPRECATED: return 'deprecated';
			case E_ERROR: case E_USER_ERROR: case E_CORE_ERROR: case E_COMPILE_ERROR: case E_PARSE: case E_RECOVERABLE_ERROR: return 'error';
		}
		return 'other';
	}

	/** A file path relative to the site root, so no machine layout travels. */
	public static function relative($file): string {
		$root = rtrim(dirname(PathHelper::getRootDir()), '/') . '/';
		$file = (string)$file;
		if (strpos($file, $root) === 0) {
			$file = substr($file, strlen($root));
		}
		return substr(preg_replace('#[^A-Za-z0-9._/\-]#', '', $file), 0, 160);
	}

	/** At shutdown: the report, onto the grant. */
	public static function finish(): void {
		if (!self::$grant) {
			return;
		}
		$last = error_get_last();
		if ($last && in_array($last['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)
				&& count(self::$warnings) < self::MAX_WARNINGS) {
			self::$warnings[] = ['type' => 'error', 'at' => self::relative($last['file']) . ':' . (int)$last['line']];
		}
		$started = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
		$report = [
			'render_ms'   => (int)round((microtime(true) - $started) * 1000),
			'statements'  => class_exists('GuardedPdo', false) ? (int)GuardedPdo::$statements : null,
			'peak_memory' => memory_get_peak_usage(true),
			'warnings'    => self::$warnings,
		];
		$grant = self::$grant;
		try {
			SystemBase::server_initiated_write(function () use ($grant, $report) {
				$grant->set('ppg_report', $report);
				$grant->save();
			});
		} catch (\Throwable $e) {
			// The script reports a render with no report as such.
		}
	}

	/** Whether a page is one a probe never renders. */
	public static function refused(string $page): bool {
		return in_array(rtrim($page, '/') ?: '/', self::REFUSED_PAGES, true);
	}

	/**
	 * The pages this node serves that a probe may be asked for: its admin
	 * menu entries and its public views (core and the active theme's), each
	 * as a path with no query string, less REFUSED_PAGES.
	 */
	public static function node_pages(): array {
		$pages = ['/' => true];
		foreach (new MultiAdminMenu(array()) as $m) {
			if ((int)$m->get('amu_disable') === 1) { continue; }
			$p = trim((string)$m->get('amu_defaultpage'));
			if ($p === '' || strpos($p, '?') !== false || strpos($p, '#') !== false) { continue; }
			if ($p[0] !== '/') { $p = '/admin/' . $p; }
			$pages[$p] = true;
		}
		$view_dirs = [PathHelper::getIncludePath('views')];
		$theme = (string)Globalvars::get_instance()->get_setting('theme_template', true, true);
		if ($theme !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $theme)) {
			$view_dirs[] = PathHelper::getIncludePath('theme/' . $theme . '/views');
		}
		foreach ($view_dirs as $dir) {
			foreach (glob($dir . '/*.php') ?: [] as $f) {
				$name = basename($f, '.php');
				if ($name === 'index') { continue; }
				if (preg_match('/^[a-z0-9_-]+$/', $name)) {
					$pages['/' . $name] = true;
				}
			}
		}
		$out = [];
		foreach (array_keys($pages) as $p) {
			if (!self::refused($p) && preg_match(self::PAGE_PATTERN, $p)) {
				$out[] = $p;
			}
		}
		sort($out);
		return $out;
	}

	/**
	 * Remove grants whose probe died mid-way, and the throwaway users they
	 * made. Called by every probe before it starts, so a crash costs one
	 * leftover row until the next probe.
	 */
	public static function sweep(): int {
		$n = 0;
		foreach (new MultiPageProbeGrant(array('expired_before' => gmdate('Y-m-d H:i:s', time() - 600))) as $g) {
			self::discard($g);
			$n++;
		}
		return $n;
	}

	/** Delete a grant and, permanently, the throwaway user it names. Whether both are gone. */
	public static function discard(PageProbeGrant $grant): bool {
		$user_id = (int)$grant->get('ppg_usr_user_id');
		$ok = true;
		try {
			$grant->permanent_delete();
		} catch (\Throwable $e) {
			$ok = false;
		}
		if ($user_id > 0) {
			try {
				$user = new User($user_id, TRUE);
				if ($user->key) {
					$user->permanent_delete();
				}
				$ok = $ok && count(new MultiUser(array('usr_user_id' => $user_id))) === 0;
			} catch (\Throwable $e) {
				$ok = false;
			}
		}
		return $ok;
	}
}
