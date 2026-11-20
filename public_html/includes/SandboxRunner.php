<?php
/**
 * SandboxRunner - what runs a sandboxed PHP plugin's code: one fenced
 * process per call, and core's side of the conversation with it
 * (specs/sandboxed_php_plugins.md designs 2 and 3).
 *
 * SandboxHost hands it one envelope and gets back the runner answer
 * {ok, output, error, timed_out, duration_ms, calls}, or {unavailable} when
 * the plugin cannot be run on this server at all. It starts
 *
 *   joinery-jail --user=jpx-NAME --timeout=S --rlimit-as=B
 *                --read=<runner script> --read=<plugin's src/>
 *       -- php -n -d extension=... -d memory_limit=NM -d display_errors=stderr <runner script> <src/>
 *
 * writes the envelope as the first line of the process's stdin, and then
 * answers each ask the process writes to its stdout, one JSON line each,
 * through SandboxRelay, until the process says done, exits, or runs out of
 * time. The process is never started without the fence: no launcher with the
 * allowlist, no plugin user, or a host that refuses sandboxed plugins, and
 * the call is unavailable.
 *
 * The plugin's API key never travels toward the fence. The envelope carries
 * none (SandboxHost), the relay loads it by plugin name for each call it
 * makes, and the process is given a fixed environment of one variable.
 *
 * Everything that arrives from the process is untrusted: each line is capped,
 * the number of asks is capped, standard error is read to a cap and written
 * to the error log stripped of control characters, and the whole call is
 * bounded by its deadline, asks included.
 *
 * The run is ended by closing the launcher's watch pipe (--watch-fd=3,
 * launcher 2.1.0): core cannot signal the supervisor, which holds root's and
 * then the run's uid. Conversation is in strict turns: nothing more is read
 * from the process while an answer to it is unwritten.
 *
 * @version 1.4 - a line may be 4 MB, for a framed page's document
 * @version 1.3 - the fence reads the model layer, and the includes/ and data/ of the native plugins whose models
 *                the plugin was approved for (nativeModelDirs, also in the envelope as class_dirs)
 * @version 1.2 - the fence also reads the runtime (utils/sandbox_runtime.php, its stand-ins) and core's includes/ and data/ .php files
 * @version 1.1 - reviewer2 E2/E3: the run is ended through the watch pipe, the answer decided by what
 *                happened first (done before a timeout); strict turns; the launcher needs --watch-fd
 * @version 1.0
 */

class SandboxRunner {

	/** The fence's launcher (installed by install_parser_jail.sh). */
	const LAUNCHER = DocumentText::JAIL_LAUNCHER;

	/**
	 * The longest line the process may write: one ask, or its result. A
	 * framed page's document carries its script and styles inline, so this is
	 * 4 MB (SandboxPages::MAX_FRAME_BYTES); a plain page's body is cleaned
	 * only to 1 MB, and the relay's answers are 1 MB each.
	 */
	const MAX_LINE_BYTES = 4194304;

	/** The most asks one call may make. */
	const MAX_ASKS = 200;

	/** How much of the process's standard error is kept for the log. */
	const MAX_STDERR_BYTES = 8192;

	/**
	 * Address space PHP needs before the plugin's own memory: the binary,
	 * its libraries and the extensions below (measured at about 48 MB,
	 * 2026-11-20). The address-space limit is this plus the call's memory.
	 */
	const ADDRESS_SPACE_BASE_BYTES = 100663296;

	/**
	 * Shared extensions loaded into the fenced PHP, where this PHP has them.
	 * None widens the fence: it is judged as native code (design 2).
	 */
	const EXTENSIONS = array('ctype', 'mbstring', 'iconv', 'bcmath', 'dom', 'xml', 'simplexml', 'xmlreader', 'xmlwriter', 'posix', 'pdo');

	/** The core script the fence runs, and the runtime it loads. */
	const SCRIPT = 'utils/sandbox_php_runner.php';
	const RUNTIME = 'utils/sandbox_runtime.php';
	const STANDINS = array('utils/sandbox_runtime_standins.php', 'utils/sandbox_runtime_standins_session.php', 'utils/sandbox_runtime_models.php');

	/** What a call says on a host that refuses sandboxed plugins (design 10). */
	const SHARED_HOST = 'This server is shared with other sites, and does not run sandboxed plugins.';

	/** What a call says where the fence is not installed. */
	const NO_FENCE = 'This server cannot fence a sandboxed plugin (the joinery-jail launcher with the allowlist is not installed), so it does not run one.';

	/** What a call says where the launcher is the allowlist but older than 2.1 (no --watch-fd). */
	const OLD_LAUNCHER = 'This server\'s fence launcher is older than sandboxed plugins need; the next root step installs the new one.';

	/** What the launcher's usage line carries when it has --watch-fd (launcher 2.1.0). */
	const FEATURES_MARK = 'JOINERY_JAIL_FEATURES=watch-fd';

	/** What a call says while the plugin's own user is not made yet. */
	const NOT_SET_UP = 'The plugin is still being set up on this server (its own user is made within a minute of it arriving). Try again shortly.';

	/**
	 * Tests only: a callable (envelope) => argv used in place of the fenced
	 * command, so the conversation can be driven without the root launcher.
	 * Never set by the platform.
	 *
	 * @var callable|null
	 */
	public static $command_override = null;

	/** The runner SandboxHost calls. @return array the runner answer */
	public static function run(array $envelope): array {
		$plugin = (string)($envelope['plugin'] ?? '');
		// Where the fenced runtime finds the classes of the native plugins
		// whose models this plugin may use (granted in command()).
		$envelope['class_dirs'] = self::nativeModelDirs($plugin);
		if (self::$command_override === null) {
			$why = self::unavailable($plugin);
			if ($why !== null) {
				return array('unavailable' => $why);
			}
			$cmd = self::command($envelope);
		} else {
			$cmd = call_user_func(self::$command_override, $envelope);
		}
		return self::converse($cmd, $envelope, new SandboxRelay($plugin, $envelope));
	}

	/**
	 * Why this plugin cannot be run here, or null when it can.
	 */
	public static function unavailable(string $plugin): ?string {
		if (SandboxPlugins::hostRefuses()) {
			return self::SHARED_HOST;
		}
		if (!DocumentText::jailAvailable() || !DocumentText::jailFenced()) {
			return self::NO_FENCE;
		}
		if (!self::launcherHasFeatures()) {
			return self::OLD_LAUNCHER;
		}
		if (self::sourceDir($plugin) === null) {
			return 'The plugin\'s src folder is not a real folder inside plugins/.';
		}
		if (!function_exists('posix_getpwnam') || posix_getpwnam(self::userFor($plugin)) === false) {
			return self::NOT_SET_UP;
		}
		return null;
	}

	/** Whether the installed launcher names FEATURES_MARK. Read from the binary, once per binary. */
	private static function launcherHasFeatures(): bool {
		static $known = array();
		$st = @stat(self::LAUNCHER);
		if ($st === false) {
			return false;
		}
		$key = $st['ino'] . ':' . $st['size'] . ':' . $st['mtime'];
		if (!isset($known[$key])) {
			$apcu = 'joinery_jail_features_' . $key;
			$ok = false;
			$hit = ApcuStore::enabled() ? ApcuStore::fetch($apcu, $ok) : null;
			if (!empty($ok)) {
				$known[$key] = (bool)$hit;
			} else {
				$bin = @file_get_contents(self::LAUNCHER);
				$known[$key] = is_string($bin) && strpos($bin, self::FEATURES_MARK) !== false;
				ApcuStore::store($apcu, $known[$key], 86400);
			}
		}
		return $known[$key];
	}

	/**
	 * The includes/ and data/ folders (real paths) of each active native
	 * plugin that declares a model this plugin's key is scoped to.
	 *
	 * @return string[]
	 */
	public static function nativeModelDirs(string $plugin): array {
		$base = realpath(SandboxPlugins::baseDir());
		if ($base === false) {
			return array();
		}
		$dirs = array();
		foreach (SandboxCredentials::scopeFor($plugin) as $entry) {
			if ((!preg_match(ApiKey::SCOPE_RECORD_PATTERN, $entry, $m) && !preg_match(ApiKey::SCOPE_OWN_PATTERN, $entry, $m))
					|| strncmp($m[1], 'Sbx_', 4) === 0 || !class_exists($m[1])) {
				continue;
			}
			$owner = self::nativeOwnerOf($m[1]);
			if ($owner === null) {
				continue;
			}
			foreach (array('includes', 'data') as $sub) {
				$dir = realpath($base . '/' . $owner . '/' . $sub);
				if ($dir !== false && is_dir($dir) && !is_link($base . '/' . $owner . '/' . $sub)) {
					$dirs[$dir] = true;
				}
			}
		}
		return array_keys($dirs);
	}

	/** The native plugin a model class is declared in, or null (core's, a sandboxed plugin's). */
	public static function nativeOwnerOf(string $class): ?string {
		$base = realpath(SandboxPlugins::baseDir());
		if ($base === false || !class_exists($class) || is_subclass_of($class, 'SandboxRecord')) {
			return null;
		}
		$file = (string)realpath((string)(new ReflectionClass($class))->getFileName());
		if (strpos($file, $base . '/') !== 0) {
			return null;
		}
		$owner = explode('/', substr($file, strlen($base) + 1))[0];
		return preg_match('~^[a-z0-9_]+$~D', $owner) && !SandboxPlugins::isSandboxed($owner) ? $owner : null;
	}

	/** The plugin's own Unix user. */
	public static function userFor(string $plugin): string {
		return 'jpx-' . $plugin;
	}

	/**
	 * The plugin's src/ by its real path, or null unless it is a real folder
	 * directly inside the plugin's folder in plugins/ (the launcher refuses a
	 * grant through a link too).
	 */
	public static function sourceDir(string $plugin): ?string {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
			return null;
		}
		$base = realpath(SandboxPlugins::baseDir());
		$dir = SandboxPlugins::dirFor($plugin) . '/' . SandboxPlugins::SOURCE_DIR;
		$real = realpath($dir);
		if ($base === false || $real === false || is_link($dir) || is_link(dirname($dir))
				|| $real !== $base . '/' . $plugin . '/' . SandboxPlugins::SOURCE_DIR || !is_dir($real)) {
			return null;
		}
		return $real;
	}

	/**
	 * The fenced command for one call. Nothing in it comes from the plugin
	 * but its name, which the manifest pattern has checked.
	 *
	 * @return string[]
	 */
	public static function command(array $envelope): array {
		$plugin = (string)$envelope['plugin'];
		$memory_mb = max(1, (int)($envelope['limits']['memory_mb'] ?? SandboxManifest::DEFAULT_MEMORY_MB));
		$timeout_ms = max(1, (int)($envelope['limits']['timeout_ms'] ?? SandboxManifest::DEFAULT_TIMEOUT_MS));
		$script = (string)realpath(PathHelper::getIncludePath(self::SCRIPT));
		$runtime = (string)realpath(PathHelper::getIncludePath(self::RUNTIME));

		$tree = (string)realpath(PathHelper::getRootDir());
		$src = (string)self::sourceDir($plugin);
		$php = self::phpBinary();
		$cmd = array(
			self::LAUNCHER,
			'--user=' . self::userFor($plugin),
			'--watch-fd=3',
			// A backstop behind core's own deadline, which ends the run first.
			'--timeout=' . ((int)ceil($timeout_ms / 1000) + 1),
			'--rlimit-as=' . (self::ADDRESS_SPACE_BASE_BYTES + $memory_mb * 1048576),
			'--read=' . $script,
			'--read=' . $runtime,
			'--read=' . (string)realpath(PathHelper::getIncludePath(self::STANDINS[0])),
			'--read=' . (string)realpath(PathHelper::getIncludePath(self::STANDINS[1])),
			'--read=' . (string)realpath(PathHelper::getIncludePath(self::STANDINS[2])),
			// The setting declarations (names and factory defaults, the public
			// repository's): core code reads them as it resolves a setting.
			'--read=' . $tree . '/settings.json',
			// Core's classes, file by file (.php only, listed by the launcher at
			// each call): includes/ and data/ hold other files too.
			'--read-php=' . $tree . '/includes',
			'--read-php=' . $tree . '/data',
			'--read=' . $src,
		);
		// The includes/ and data/ of each native plugin whose model the
		// plugin was approved for, .php files only, as core's are.
		foreach (self::nativeModelDirs($plugin) as $dir) {
			$cmd[] = '--read-php=' . $dir;
		}
		$cmd = array_merge($cmd, array(
			'--',
			$php, '-n',
		));
		$dir = (string)ini_get('extension_dir');
		foreach (self::EXTENSIONS as $ext) {
			if ($dir !== '' && is_file($dir . '/' . $ext . '.so')) {
				$cmd[] = '-d';
				$cmd[] = 'extension=' . $ext;
			}
		}
		return array_merge($cmd, array(
			'-d', 'memory_limit=' . $memory_mb . 'M',
			'-d', 'display_errors=stderr',
			'-d', 'log_errors=0',
			$script, $src,
		));
	}

	/**
	 * Start the process, hand it the envelope, answer its asks.
	 *
	 * @param string[] $cmd
	 * @return array the runner answer
	 */
	public static function converse(array $cmd, array $envelope, SandboxRelay $relay): array {
		$plugin = (string)($envelope['plugin'] ?? '');
		$timeout_ms = max(1, (int)($envelope['limits']['timeout_ms'] ?? SandboxManifest::DEFAULT_TIMEOUT_MS));
		$started = microtime(true);
		$deadline = $started + $timeout_ms / 1000;
		$relay->setDeadline($deadline);

		$pipes = array();
		// Descriptor 3 is the launcher's watch pipe (--watch-fd=3): closing our
		// end is how core ends the run, since it cannot signal the supervisor.
		// A fixed environment: nothing of the web worker's reaches the launcher.
		$proc = @proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'), 3 => array('pipe', 'r')),
			$pipes, null, array('PATH' => '/usr/bin:/bin'));
		if (!is_resource($proc)) {
			return self::answer(false, null, 'The plugin could not be started.', false, $started, $relay);
		}
		foreach (array(0, 1, 2) as $i) {
			stream_set_blocking($pipes[$i], false);
		}

		$line = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		$to_write = ($line === false ? '{}' : $line) . "\n";
		$stdout = '';
		$stderr = '';
		$asks = 0;
		$done = null;
		$failure = null;
		$timed_out = false;
		$too_long = 'The plugin wrote a message longer than ' . (self::MAX_LINE_BYTES >> 20) . ' MB.';

		// Strict turns: while an answer is owed to the process, nothing more is
		// read from it, so a plugin that asks without reading its answers
		// cannot pile them up in this worker's memory.
		while (true) {
			if ($to_write === '') {
				$nl = strpos($stdout, "\n");
				if ($nl !== false) {
					$message = substr($stdout, 0, $nl);
					$stdout = (string)substr($stdout, $nl + 1);
					if (strlen($message) > self::MAX_LINE_BYTES) {
						$failure = $too_long;
						break;
					}
					$ask = json_decode($message, true, 64);
					if (!is_array($ask) || !is_string($ask['ask'] ?? null)) {
						$failure = 'The plugin wrote something that is not an ask.';
						break;
					}
					if ($ask['ask'] === 'done') {
						$done = $ask;
						break;
					}
					if (++$asks > self::MAX_ASKS) {
						$failure = 'The plugin asked more than ' . self::MAX_ASKS . ' times in one call.';
						break;
					}
					$reply = json_encode($relay->handle($ask), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
					$to_write = ($reply === false ? '{"ok":false,"error":"The answer could not be written."}' : $reply) . "\n";
					continue;
				}
				if (strlen($stdout) > self::MAX_LINE_BYTES) {
					$failure = $too_long;
					break;
				}
				if (!is_resource($pipes[1])) {
					// Its stdout is closed and holds no whole ask: it has stopped.
					break;
				}
			}
			$left = $deadline - microtime(true);
			if ($left <= 0) {
				$timed_out = true;
				break;
			}
			$read = array();
			if ($to_write === '' && is_resource($pipes[1])) $read[] = $pipes[1];
			if (is_resource($pipes[2])) $read[] = $pipes[2];
			$write = ($to_write !== '' && is_resource($pipes[0])) ? array($pipes[0]) : array();
			if (!$read && !$write) {
				break;
			}
			$except = null;
			$sec = (int)floor($left);
			$ready = @stream_select($read, $write, $except, $sec, (int)(($left - $sec) * 1000000));
			if ($ready === false) {
				$failure = 'The plugin\'s pipe failed.';
				break;
			}
			foreach ($write as $w) {
				$n = @fwrite($w, substr($to_write, 0, 65536));
				if ($n === false || $n === 0) {
					// It closed its stdin: it will read no answer.
					fclose($pipes[0]);
					$pipes[0] = null;
					$to_write = '';
				} else {
					$to_write = (string)substr($to_write, $n);
				}
			}
			foreach ($read as $r) {
				$chunk = fread($r, 65536);
				if ($chunk === '' || $chunk === false) {
					if (feof($r)) {
						$i = $r === $pipes[1] ? 1 : 2;
						fclose($r);
						$pipes[$i] = null;
					}
					continue;
				}
				if ($r === $pipes[2]) {
					if (strlen($stderr) < self::MAX_STDERR_BYTES) {
						$stderr .= substr($chunk, 0, self::MAX_STDERR_BYTES - strlen($stderr));
					}
					continue;
				}
				$stdout .= $chunk;
			}
		}

		foreach (array(0, 1) as $i) {
			if (is_resource($pipes[$i])) {
				fclose($pipes[$i]);
			}
		}
		// After done, a moment to exit (a shutdown function the plugin left may
		// still be running), never past the deadline; otherwise none. Then the
		// watch pipe is closed and the supervisor kills what is left.
		$exit = null;
		$until = min($deadline, microtime(true) + ($done === null ? 0 : 0.5));
		do {
			$status = proc_get_status($proc);
			if (!$status['running']) {
				$exit = (int)$status['exitcode'];
				break;
			}
			if (microtime(true) >= $until) {
				break;
			}
			usleep(10000);
		} while (true);
		fclose($pipes[3]);
		if ($exit === null && self::$command_override !== null) {
			// Unfenced (tests): this worker's own child, which it may signal.
			proc_terminate($proc, 9);
		}
		// What it wrote to standard error after its last ask, read briefly.
		if (is_resource($pipes[2])) {
			$until = microtime(true) + 0.2;
			while (microtime(true) < $until && !feof($pipes[2]) && strlen($stderr) < self::MAX_STDERR_BYTES) {
				$r = array($pipes[2]);
				$x = null;
				$y = null;
				if (!@stream_select($r, $x, $y, 0, 50000)) {
					continue;
				}
				$chunk = fread($pipes[2], self::MAX_STDERR_BYTES - strlen($stderr));
				if ($chunk === '' || $chunk === false) {
					break;
				}
				$stderr .= $chunk;
			}
			fclose($pipes[2]);
		}
		$closed = proc_close($proc);
		if ($exit === null) {
			$exit = $closed;
		}
		self::logStderr($plugin, $stderr);

		// What happened first decides: an answer, then our deadline or a refusal.
		if ($done !== null) {
			if (empty($done['ok'])) {
				return self::answer(false, null, is_scalar($done['error'] ?? null) ? (string)$done['error'] : 'The plugin failed.', false, $started, $relay);
			}
			return self::answer(true, $done['output'] ?? null, '', false, $started, $relay);
		}
		if ($failure !== null) {
			return self::answer(false, null, $failure, false, $started, $relay);
		}
		if ($timed_out || $exit === 124) {
			return self::answer(false, null, 'The plugin did not respond in time.', true, $started, $relay);
		}
		if ($exit === 125) {
			error_log('[SandboxRunner] the fence refused to run ' . $plugin . ': ' . mb_substr(trim(preg_replace('~[\x00-\x1F\x7F]+~', ' ', $stderr) ?? ''), 0, 300));
			return self::answer(false, null, 'The fence refused to run the plugin.', false, $started, $relay);
		}
		$why = (stripos($stderr, 'Allowed memory size') !== false || stripos($stderr, 'Out of memory') !== false)
			? ' (it ran out of memory)' : '';
		return self::answer(false, null, 'The plugin stopped without answering' . $why . '.', false, $started, $relay);
	}

	private static function answer(bool $ok, $output, string $error, bool $timed_out, float $started, SandboxRelay $relay): array {
		return array(
			'ok'          => $ok,
			'output'      => $output,
			'error'       => mb_substr($error, 0, 500),
			'timed_out'   => $timed_out,
			'duration_ms' => (int)round((microtime(true) - $started) * 1000),
			'calls'       => $relay->calls(),
		);
	}

	/** The plugin's standard error, to the error log: capped, one line, no control characters. */
	private static function logStderr(string $plugin, string $stderr): void {
		$text = trim(preg_replace('~[\x00-\x1F\x7F]+~', ' | ', $stderr) ?? '');
		if ($text !== '') {
			error_log('[SandboxRunner] ' . $plugin . ' wrote to standard error: ' . mb_substr($text, 0, self::MAX_STDERR_BYTES));
		}
	}

	private static function phpBinary(): string {
		foreach (array(PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php') as $c) {
			if (@is_executable($c)) {
				return $c;
			}
		}
		return 'php';
	}
}
