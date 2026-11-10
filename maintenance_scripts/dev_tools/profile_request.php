#!/usr/bin/env php
<?php
/**
 * Replay one GET through serve.php and report what it cost.
 *
 * Usage: php maintenance_scripts/dev_tools/profile_request.php PATH [USER_ID [PERMISSION]]
 *
 *   PATH         the path to request, with its query string: /events, '/admin/admin_user?usr_user_id=1'
 *   USER_ID      sign the request in as this user (omit or 0 for an anonymous visitor)
 *   PERMISSION   that user's permission level (default 10)
 *
 * Environment switches:
 *   DETAIL=n     list the n statements that took longest, grouped by their text
 *   STG=1        list any setting still asked for with a statement of its own, with repeats
 *   FILES=1      list every file loaded
 *   SAMPLE=1     sample the call stack and list where the time went (needs pcntl and posix)
 *   FRESH=1      empty the scratch page cache first, so a public page is built, not served from it
 *
 * The report goes to stderr; the page itself is thrown away.
 *
 * Nothing it does is kept. The page cache is pointed at a scratch directory
 * (a second run of a public page is therefore a cached hit, unless FRESH=1),
 * and the request runs inside a transaction that is rolled back.
 *
 * Reading the numbers: the command line loads files more slowly than a web
 * worker with warm opcache, so a cost made of file loads is overstated.
 * Statement counts and statement times are not affected. The command line
 * starts no session, so session start is not in the wall time; the database
 * connection is reported on its own and is not in the wall time either.
 * For steadier timings give the command line an opcache of its own:
 *   php -d opcache.enable_cli=1 -d opcache.file_cache=/some/scratch/dir -d opcache.file_cache_only=1 ...
 *
 * Dev and test sites only: it refuses unless the 'debug' setting is on, the
 * rule the test harness uses for a dev-only test.
 *
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
if ($argc < 2 || $argv[1] === '' || $argv[1][0] !== '/') {
	fwrite(STDERR, "Usage: php profile_request.php /path [user_id [permission]]\n");
	exit(2);
}

$profile_root = dirname(__DIR__, 2) . '/public_html';
$profile_path = $argv[1];
$profile_user = (int)($argv[2] ?? 0);
$profile_perm = (int)($argv[3] ?? 10);

chdir($profile_root);
require_once($profile_root . '/includes/PathHelper.php');
require_once($profile_root . '/includes/Globalvars.php');
require_once($profile_root . '/includes/DbConnector.php');

/** A statement that reports how long it ran and which setting it asked for. */
class ProfileRequestStatement extends GuardedPdoStatement {
	#[\ReturnTypeWillChange]
	public function bindValue($param, $value, $type = PDO::PARAM_STR) {
		if ($param === ':stg_name') {
			ProfileRequest::$settings_read[$value] = (ProfileRequest::$settings_read[$value] ?? 0) + 1;
		}
		return parent::bindValue($param, $value, $type);
	}

	#[\ReturnTypeWillChange]
	public function execute($params = null) {
		$start = hrtime(true);
		try {
			return parent::execute($params);
		} finally {
			ProfileRequest::note((string)$this->queryString, $start);
		}
	}
}

/**
 * The link the request runs on: times every statement, and holds the whole
 * request in one transaction. A transaction the page opens becomes a
 * savepoint inside it, so the page's own commit and rollback still work and
 * nothing reaches the database for good.
 */
class ProfileRequestPdo extends GuardedPdo {
	private $depth = 0;
	private $outer_open = false;

	public function __construct($dsn, $username = null, $password = null, $options = null) {
		$start = hrtime(true);
		parent::__construct($dsn, $username, $password, $options);
		ProfileRequest::$connect_ms += (hrtime(true) - $start) / 1e6;
		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('ProfileRequestStatement', array()));
	}

	public function openOuter(): void {
		parent::beginTransaction();
		$this->outer_open = true;
	}

	public function rollBackOuter(): void {
		if ($this->outer_open && parent::inTransaction()) {
			parent::rollBack();
		}
		$this->outer_open = false;
	}

	#[\ReturnTypeWillChange]
	public function beginTransaction() {
		if (!$this->outer_open) {
			return parent::beginTransaction();
		}
		if ($this->depth > 0) {
			throw new PDOException('There is already an active transaction');
		}
		parent::exec('SAVEPOINT profile_request');
		$this->depth = 1;
		return true;
	}

	#[\ReturnTypeWillChange]
	public function commit() {
		if (!$this->outer_open) {
			return parent::commit();
		}
		if ($this->depth === 0) {
			throw new PDOException('There is no active transaction');
		}
		parent::exec('RELEASE SAVEPOINT profile_request');
		$this->depth = 0;
		return true;
	}

	#[\ReturnTypeWillChange]
	public function rollBack() {
		if (!$this->outer_open) {
			return parent::rollBack();
		}
		if ($this->depth === 0) {
			throw new PDOException('There is no active transaction');
		}
		parent::exec('ROLLBACK TO SAVEPOINT profile_request');
		parent::exec('RELEASE SAVEPOINT profile_request');
		$this->depth = 0;
		return true;
	}

	#[\ReturnTypeWillChange]
	public function inTransaction() {
		return $this->outer_open ? $this->depth > 0 : parent::inTransaction();
	}

	#[\ReturnTypeWillChange]
	public function query($query, $fetchMode = null, ...$fetchModeArgs) {
		$start = hrtime(true);
		try {
			return parent::query($query, $fetchMode, ...$fetchModeArgs);
		} finally {
			ProfileRequest::note((string)$query, $start);
		}
	}

	#[\ReturnTypeWillChange]
	public function exec($statement) {
		$start = hrtime(true);
		try {
			return parent::exec($statement);
		} finally {
			ProfileRequest::note((string)$statement, $start);
		}
	}
}

final class ProfileRequest {
	public static $counting = false;
	public static $statements = array();
	public static $settings_read = array();
	public static $connect_ms = 0.0;
	public static $samples = array();
	public static $sampler_pid = 0;
	public static $bytes = 0;
	public static $started = 0;

	public static function note(string $sql, int $start): void {
		if (self::$counting && strpos($sql, 'SAVEPOINT profile_request') === false) {
			self::$statements[] = array($sql, (hrtime(true) - $start) / 1e6);
		}
	}

	public static function emptyDirectory(string $dir): void {
		foreach (glob($dir . '/*') ?: array() as $entry) {
			if (is_file($entry)) {
				@unlink($entry);
			}
		}
	}

	public static function report(string $path): void {
		$wall = (hrtime(true) - self::$started) / 1e6;
		self::$counting = false;
		if (self::$sampler_pid) {
			posix_kill(self::$sampler_pid, SIGKILL);
		}
		while (ob_get_level()) {
			ob_end_clean();
		}
		try {
			$link = DbConnector::get_instance()->get_db_link();
			if ($link instanceof ProfileRequestPdo) {
				$link->rollBackOuter();
			}
		} catch (Throwable $e) {
			fwrite(STDERR, 'profile_request: the rollback failed: ' . $e->getMessage() . "\n");
		}

		$statement_ms = 0;
		$settings_statements = 0;
		$grouped = array();
		foreach (self::$statements as list($sql, $ms)) {
			$statement_ms += $ms;
			if (strpos($sql, 'stg_settings') !== false) {
				$settings_statements++;
			}
			$key = preg_replace('/\s+/', ' ', substr($sql, 0, 150));
			$grouped[$key] = $grouped[$key] ?? array(0, 0);
			$grouped[$key][0]++;
			$grouped[$key][1] += $ms;
		}
		uasort($grouped, function ($a, $b) { return $b[1] <=> $a[1]; });
		$files = get_included_files();

		$out = sprintf("== %s  wall=%.1fms  statements=%d (%.1fms, %d on settings)  connect=%.1fms  files=%d  peak=%.1fMB  bytes=%d  status=%s\n",
			$path, $wall, count(self::$statements), $statement_ms, $settings_statements, self::$connect_ms,
			count($files), memory_get_peak_usage() / 1048576, self::$bytes, http_response_code() ?: '-');
		if (getenv('DETAIL')) {
			$shown = 0;
			foreach ($grouped as $key => list($count, $ms)) {
				$out .= sprintf("  %3dx %6.2fms  %s\n", $count, $ms, $key);
				if (++$shown >= (int)getenv('DETAIL')) {
					break;
				}
			}
		}
		if (getenv('STG')) {
			arsort(self::$settings_read);
			$out .= '  settings asked for one at a time: ' . count(self::$settings_read) . "\n";
			foreach (self::$settings_read as $name => $count) {
				if ($count > 1) {
					$out .= '    ' . $name . ' x' . $count . "\n";
				}
			}
		}
		if (getenv('FILES')) {
			$out .= implode("\n", $files) . "\n";
		}
		if (self::$samples) {
			$self = array();
			$inclusive = array();
			foreach (self::$samples as $stack) {
				$top = $stack[0] ?? 'main';
				$self[$top] = ($self[$top] ?? 0) + 1;
				foreach (array_unique($stack) as $frame) {
					$inclusive[$frame] = ($inclusive[$frame] ?? 0) + 1;
				}
			}
			arsort($self);
			arsort($inclusive);
			$out .= '  samples=' . count(self::$samples) . "\n  running in:\n";
			foreach (array_slice($self, 0, 25, true) as $frame => $count) {
				$out .= sprintf("    %4d  %s\n", $count, $frame);
			}
			$out .= "  on the stack:\n";
			foreach (array_slice($inclusive, 0, 40, true) as $frame => $count) {
				$out .= sprintf("    %4d  %s\n", $count, $frame);
			}
		}
		fwrite(STDERR, $out);
	}
}

// The request profiler's link class, in place of GuardedPdo (which it extends).
(new ReflectionProperty('DbConnector', 'link_class'))->setValue(null, 'ProfileRequestPdo');

if (!Globalvars::get_instance()->get_setting('debug')) {
	fwrite(STDERR, "profile_request refuses to run: the 'debug' setting is off (this looks like production).\n");
	exit(3);
}

// A scratch page cache, so the site's own is neither read nor written.
$profile_cache = sys_get_temp_dir() . '/joinery_profile_request_' . md5($profile_root);
if (!is_dir($profile_cache)) {
	mkdir($profile_cache, 0700, true);
}
if (getenv('FRESH')) {
	ProfileRequest::emptyDirectory($profile_cache);
}
StaticPageCache::setCacheDirForTests($profile_cache);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $profile_path;
$_SERVER['HTTP_HOST'] = (string)Globalvars::get_instance()->get_setting('webDir');
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
$_SERVER['SCRIPT_NAME'] = '/serve.php';
$profile_url = parse_url($profile_path);
parse_str($profile_url['query'] ?? '', $_GET);
$_SERVER['QUERY_STRING'] = $profile_url['query'] ?? '';
$_GET['__route'] = ltrim($profile_url['path'] ?? '/', '/');
$_REQUEST = $_GET;
if ($profile_user) {
	$_SESSION = array(
		'loggedin' => true,
		'usr_user_id' => $profile_user,
		'permission' => $profile_perm,
		'initial_usr_user_id' => $profile_user,
		'terms_accepted' => true,
		'timezone' => 'America/New_York',
		'ip_address' => '127.0.0.1',
		'saved_messages' => array(),
	);
}

// The request starts with the settings it would start with in a web worker
// (only what the config file holds) and reads the rest as a web request
// does, the whole table in one statement.
Globalvars::get_instance()->forget_setting('debug');
Globalvars::read_whole_table(true);

DbConnector::get_instance()->get_db_link()->openOuter();

if (getenv('SAMPLE')) {
	if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
		fwrite(STDERR, "profile_request: SAMPLE=1 needs the pcntl and posix extensions.\n");
		exit(2);
	}
	pcntl_async_signals(true);
	pcntl_signal(SIGPROF, function () {
		$stack = array();
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
			$stack[] = (isset($frame['class']) ? $frame['class'] . '::' : '') . $frame['function'];
		}
		array_shift($stack);
		ProfileRequest::$samples[] = $stack;
	});
	$profile_parent = getmypid();
	$profile_child = pcntl_fork();
	if ($profile_child === 0) {
		while (posix_kill($profile_parent, SIGPROF)) {
			usleep(300);
		}
		exit(0);
	}
	ProfileRequest::$sampler_pid = $profile_child;
}

ob_start(function ($buffer) {
	ProfileRequest::$bytes += strlen($buffer);
	return '';
}, 65536);
register_shutdown_function(array('ProfileRequest', 'report'), $profile_path);

ProfileRequest::$settings_read = array();
ProfileRequest::$counting = true;
ProfileRequest::$started = hrtime(true);
require($profile_root . '/serve.php');
