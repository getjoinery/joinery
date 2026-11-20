<?php
/**
 * sandbox_php_runner.php - one call of a sandboxed PHP plugin, inside the fence.
 *
 * Core starts it (SandboxRunner) and nothing else does:
 *
 *   joinery-jail --user=jpx-NAME --timeout=S --rlimit-as=B --read=... -- \
 *       php -n -d extension=... -d memory_limit=... sandbox_php_runner.php SRC_DIR
 *
 * The process has no network, no database, no config file and no writable
 * path (specs/sandboxed_php_plugins.md design 2). It talks to core over its
 * own stdin and stdout, one JSON object per line (design 3):
 *
 *   core -> here, first line   the call's envelope (SandboxHost::envelope()); no credential is in it
 *   here -> core               an ask: {"ask": "record.get", "model": "Event", "id": 12}
 *   core -> here               its answer, one line: {"ok": true, ...} or {"ok": false, "error": "..."}
 *   here -> core, last         {"ask": "done", "ok": true, "output": ...}, or "ok": false with "error"
 *
 * Before any of the plugin's code, utils/sandbox_runtime.php declares the
 * stand-ins core code reaches for (PathHelper, Globalvars, SessionControl,
 * DbConnector) and resolves core classes from the tree and the plugin's own
 * from src/includes and src/data.
 *
 * A page call (on_page) runs the plugin's view for the page instead:
 * src/admin/{slug}.php for an admin page, src/views/{slug}.php for a
 * member's, after src/plugin.php when there is one. What the view echoes is
 * the page's body (core cleans it); SandboxPage gives it the request and lets
 * it set a title or ask for a redirect.
 *
 * The plugin's code is src/plugin.php, which defines the functions the call
 * names: on_ui(array $input), on_signal(array $input), on_schedule(array $input).
 * What it returns is the call's output. What it echoes is buffered and
 * dropped, so a stray echo never corrupts the pipe. It reaches core only
 * through SandboxPipe::ask() (and sandbox_log()), which ask core and wait for
 * the answer; core carries each ask out with the plugin's approved reach.
 *
 * Nothing here is a wall: the fence is. A plugin may write to its stdout
 * itself; it can only send asks it could send through SandboxPipe anyway.
 *
 * @version 1.4 - on_page: the plugin's view draws a page (SandboxPage)
 * @version 1.3 - a refused ask throws with the API's status as its code
 * @version 1.2 - the runtime boots inside the output buffer and the try, so a failure there is answered (reviewer2 F3)
 * @version 1.1 - the fenced runtime (sandbox_runtime.php) is booted before the plugin's code
 * @version 1.0
 */

if (PHP_SAPI !== 'cli' || !defined('STDIN') || !defined('STDOUT')) {
	exit(1);
}

/** What an ask core refused, or could not carry out, throws. */
class SandboxPipeException extends Exception {}

/** The plugin's one way to core. */
final class SandboxPipe {

	/** The envelope this call was started with. @var array */
	public static $envelope = array();

	/**
	 * Ask core for something and wait for the answer.
	 *
	 * @param string $ask  record.get, record.list, record.create, record.update, record.delete, action, http, log
	 * @param array  $args the ask's fields (SandboxRelay names them)
	 * @return array the answer, which has "ok" true
	 * @throws SandboxPipeException when core refused the ask or it failed
	 */
	public static function ask(string $ask, array $args = array()): array {
		if ($ask === 'done') {
			throw new SandboxPipeException('A call ends when its function returns.');
		}
		self::send(array('ask' => $ask) + $args);
		$line = fgets(STDIN);
		if ($line === false) {
			// Core has gone: nothing more can be asked or answered.
			exit(3);
		}
		$answer = json_decode($line, true, 64);
		if (!is_array($answer)) {
			throw new SandboxPipeException('Core\'s answer could not be read.');
		}
		if (empty($answer['ok'])) {
			// The API's status, when the ask reached it, as the code.
			throw new SandboxPipeException((string)($answer['error'] ?? 'Core refused the ask.'), (int)($answer['status'] ?? 0));
		}
		return $answer;
	}

	/** Write one line to core. */
	public static function send(array $message): void {
		$line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
		if ($line === false) {
			$line = json_encode(array('ask' => 'done', 'ok' => false, 'error' => 'The plugin\'s message could not be written as JSON.'));
		}
		fwrite(STDOUT, $line . "\n");
		fflush(STDOUT);
	}
}

/** A page call's request, and what the view may set besides its body. */
final class SandboxPage {

	/** @var array {page, kind, method, query, fields, user} */
	public static $input = array();

	/** @var string|null */
	public static $title = null;

	/** @var array|null {page, query} */
	public static $redirect = null;

	/** The page's title, in place of its manifest title. */
	public static function title(string $title): void {
		self::$title = $title;
	}

	/** Send the viewer to one of this plugin's own pages (core refuses any other). */
	public static function redirect(string $page, array $query = array()): void {
		self::$redirect = array('page' => $page, 'query' => $query);
	}

	/** Whether the page was posted, and a posted field or query value. */
	public static function isPost(): bool {
		return (self::$input['method'] ?? 'GET') === 'POST';
	}

	public static function field(string $name, $default = null) {
		return self::$input['fields'][$name] ?? $default;
	}

	public static function query(string $name, $default = null) {
		return self::$input['query'][$name] ?? $default;
	}
}

/** A line in the call's log, which the admin reads (level debug, info, warn or error). */
function sandbox_log(string $message, string $level = 'info'): void {
	SandboxPipe::ask('log', array('level' => $level, 'message' => $message));
}

/** End the call and exit. */
function sandbox_runner_finish(bool $ok, $output, string $error = ''): never {
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	$done = array('ask' => 'done', 'ok' => $ok);
	if ($ok) {
		$done['output'] = $output;
	} else {
		$done['error'] = mb_substr($error, 0, 500);
	}
	SandboxPipe::send($done);
	exit(0);
}

$src = $argv[1] ?? '';
$line = fgets(STDIN);
$envelope = is_string($line) ? json_decode($line, true, 64) : null;
if (!is_array($envelope)) {
	sandbox_runner_finish(false, null, 'The runner was started without a call.');
}
SandboxPipe::$envelope = $envelope;

$exports = array('on_ui', 'on_page', 'on_signal', 'on_schedule');
$export = (string)($envelope['export'] ?? '');
if (!in_array($export, $exports, true)) {
	sandbox_runner_finish(false, null, 'The runner does not know the call ' . $export . '.');
}

$src_real = realpath($src);
$entry = $src_real === false ? false : realpath($src_real . '/plugin.php');
if ($src_real !== false && $entry !== false && (strpos($entry, $src_real . '/') !== 0 || !is_file($entry))) {
	$entry = false;
}
// A page is the plugin's view for it: src/admin/{slug}.php or src/views/{slug}.php.
$view = false;
if ($export === 'on_page') {
	$slug = (string)($envelope['input']['page'] ?? '');
	$folder = ($envelope['input']['kind'] ?? '') === 'admin' ? 'admin' : 'views';
	$view = ($src_real !== false && preg_match('~^[a-z][a-z0-9_]{0,29}$~D', $slug)) ? realpath($src_real . '/' . $folder . '/' . $slug . '.php') : false;
	if ($view === false || strpos($view, $src_real . '/' . $folder . '/') !== 0 || !is_file($view)) {
		sandbox_runner_finish(false, null, 'The plugin has no src/' . $folder . '/' . $slug . '.php for this page.');
	}
} elseif ($src_real === false || $entry === false) {
	sandbox_runner_finish(false, null, 'The plugin has no src/plugin.php.');
}

// Buffered from here on: whatever core or the plugin echoes never reaches the pipe.
ob_start();
try {
	// The stand-ins and class resolution (design 4), before any plugin code.
	require __DIR__ . '/sandbox_runtime.php';
	SandboxRuntime::boot($envelope, (string)realpath(dirname(__DIR__)), $src_real);

	if ($entry !== false) {
		(static function (string $entry): void {
			require $entry;
		})($entry);
	}
	if ($view !== false) {
		// What the view echoes is the page's body; it may set a title or ask
		// for a redirect to one of its own pages (SandboxPage).
		SandboxPage::$input = is_array($envelope['input'] ?? null) ? $envelope['input'] : array();
		ob_start();
		(static function (string $view): void {
			require $view;
		})($view);
		$body = (string)ob_get_clean();
		$page_out = array('body' => $body, 'title' => SandboxPage::$title);
		if (SandboxPage::$redirect !== null) {
			$page_out = array('redirect' => SandboxPage::$redirect);
		}
		sandbox_runner_finish(true, $page_out);
	}
	if (!function_exists($export)) {
		sandbox_runner_finish(false, null, 'The plugin does not answer ' . $export . ' (src/plugin.php defines no ' . $export . '()).');
	}
	$output = $export(is_array($envelope['input'] ?? null) ? $envelope['input'] : array());
} catch (Throwable $e) {
	sandbox_runner_finish(false, null, get_class($e) . ': ' . $e->getMessage());
}
sandbox_runner_finish(true, $output);
