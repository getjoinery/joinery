<?php
require_once(__DIR__ . '/SealedEgressGuard.php');

/**
 * The database anchor for the hot-turn rule (specs/implemented/sealed_content_egress.md,
 * Layer 2).
 *
 * There is no single write path in this platform to hook. save() is one writer;
 * sealed consumers deliberately bypass it with raw UPDATEs; the leak that
 * motivated the spec was itself a raw UPDATE. The only layer every write
 * crosses without exception is the PDO statement layer, so the rule lives here,
 * under everything: models, Multi collections, hand-written SQL, plugins,
 * maintenance scripts.
 *
 * Nothing else in the codebase has to know. get_db_link() returns a PDO as it
 * always did — this is a PDO — and prepared statements come back as guarded
 * statements because PDO::ATTR_STATEMENT_CLASS says so.
 *
 * Cost when the process is cold, which is nearly always: one static boolean
 * check per executed statement.
 *
 * @version 1.3 - a page view does not write: on a web GET, HEAD or OPTIONS a statement that writes, and
 *                is not the server's own (SystemBase::server_initiated_write), is logged as [GET_WRITE]
 *                with the place that wrote it (PAGE_VIEW_WRITES 'log'; 'refuse' throws, as a probe does)
 * @version 1.2 - $read_only: while a page_probe request runs (PageProbe::claim_request), a statement
 *                that writes throws unless it is the server's own (SystemBase::server_initiated_write),
 *                so the probe session is view-only in fact, not by a log line (review B3).
 * @version 1.1 - $statements counts every statement run through this layer, for page_probe's query count
 *                (specs/agent_recipes_and_vocabulary.md): one integer increment per statement.
 * @version 1.0
 */
class GuardedPdo extends PDO {

	/** Statements run in this process: exec, query, and every prepared execute. */
	public static $statements = 0;

	/**
	 * Set for the one request a page_probe claims. A write that is not the
	 * server's own is refused here, below every model and every raw query, and
	 * the refusal lands in the probe's report as an error at its file:line.
	 */
	public static $read_only = false;

	/**
	 * What a page view's write meets. A page view (a web GET, HEAD or OPTIONS)
	 * persists nothing a user asked for: a link is a GET, and a browser follows
	 * one whenever it is told to, from any site, with the session cookie
	 * (SameSite=Lax). Anything a person does is a POST button (CLAUDE.md rule 7).
	 * The server's own writes during a view go through
	 * SystemBase::server_initiated_write. 'log' names each other write in the
	 * error log as [GET_WRITE]; 'refuse' throws, once the log has none.
	 */
	const PAGE_VIEW_WRITES = 'log';

	/** The SAPI, a property so a test can stand in a web request. */
	public static $sapi = PHP_SAPI;

	/** Places already logged this request: one line each, not one per statement. */
	private static $logged = array();

	/** A web request whose method only reads. The CLI (cron, tasks, scripts) never is. */
	public static function isPageView(): bool {
		if (self::$sapi === 'cli') {
			return false;
		}
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
		return $method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS';
	}

	/**
	 * Whether a statement writes. A read, nearly every statement, is let go on
	 * its first word. Leading comments and parentheses are looked past; a WITH
	 * writes when any of its parts does (WITH x AS (DELETE ... RETURNING ...)
	 * SELECT ...), and so does EXPLAIN ANALYZE of a write, which runs it.
	 * String literals are taken out first, so a word inside one never counts.
	 */
	public static function writes(string $sql): bool {
		$t = ltrim($sql);
		if (strncasecmp($t, 'SELECT', 6) === 0) {
			return false;
		}
		while (preg_match('#^(?:--[^\n]*(?:\n|$)|/\*.*?\*/|\(|\s+)#s', $t, $m) && $m[0] !== '') {
			$t = substr($t, strlen($m[0]));
		}
		$dml = '/\b(INSERT|UPDATE|DELETE|MERGE)\b/i';
		if (preg_match('/^WITH\b/i', $t)) {
			return (bool)preg_match($dml, self::withoutLiterals($t));
		}
		if (preg_match('/^EXPLAIN\b/i', $t)) {
			$body = self::withoutLiterals($t);
			return (bool)preg_match('/\bANALY[SZ]E\b/i', $body) && (bool)preg_match($dml, $body);
		}
		return (bool)preg_match('/^(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|ALTER|CREATE|DROP|GRANT|REVOKE|COPY)\b/i', $t);
	}

	/** A statement with its quoted literals emptied ('it''s' -> ''). */
	private static function withoutLiterals(string $sql): string {
		return (string)preg_replace("/'(?:[^']|'')*'/", "''", $sql);
	}

	/** Throw when this process is read-only and the statement would write; on a page view, log or refuse it. */
	public static function assertMayRun(string $sql): void {
		if (!self::$read_only && !self::isPageView()) {
			return;
		}
		if (!self::writes($sql)) {
			return;
		}
		// Not loaded yet means nothing has said this write is the server's own.
		if (class_exists('SystemBase', false) && SystemBase::$allow_get_mutation) {
			return;
		}
		if (self::$read_only) {
			// Named in the probe's report at the caller's file:line before the
			// throw, so a refused write is visible even when something catches it.
			if (class_exists('PageProbe', false)) {
				PageProbe::note_refused_write(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12));
			}
			throw new RuntimeException('page_probe: this request is view-only, and a statement tried to write');
		}
		$trail = self::writer(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16));
		$what = self::statementName($sql);
		if (self::PAGE_VIEW_WRITES === 'refuse') {
			throw new RuntimeException('A page view may not write to the database (' . $what . ' at ' . ($trail[0] ?? '?')
				. '). Make the action a POST button; a write the server makes on its own goes through SystemBase::server_initiated_write.');
		}
		$where = $trail[0] ?? '?';
		if (isset(self::$logged[$where])) {
			return;
		}
		self::$logged[$where] = true;
		error_log('[GET_WRITE] ' . strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) . ' '
			. self::pathShown((string)($_SERVER['REQUEST_URI'] ?? '?')) . ' wrote (' . $what . ') at ' . implode(' < ', $trail)
			. ' - a page view must not write: make the action a POST button, or wrap a write the server makes on its own in SystemBase::server_initiated_write');
	}

	/**
	 * The request's path for the log: no query string, and a segment that looks
	 * like a token (24 or more characters, digits among them: a share link's
	 * /s/{token}) shown as '…', since a path can
	 * carry a secret as well as a query can.
	 */
	public static function pathShown(string $uri): string {
		$path = (string)strtok($uri, '?');
		return (string)preg_replace('#/(?=[A-Za-z_\-]*[0-9])[A-Za-z0-9_\-]{24,}(?=/|$)#', '/…', $path);
	}

	/** The verb and table of a statement, never its values: 'INSERT INTO rql_request_logs'. */
	public static function statementName(string $sql): string {
		if (preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|MERGE\s+INTO|TRUNCATE(?:\s+TABLE)?|ALTER\s+TABLE|CREATE\s+\w+|DROP\s+\w+|GRANT|REVOKE|COPY)\s+("?[\w.]+"?)/i', $sql, $m)) {
			return strtoupper(preg_replace('/\s+/', ' ', $m[1])) . ' ' . $m[2];
		}
		return 'a write';
	}

	/**
	 * Where a write came from, nearest first, as path:line under the site root:
	 * the frames past this layer and the model plumbing every save crosses.
	 */
	public static function writer(array $frames): array {
		$plumbing = array('GuardedPdo.php', 'SystemBase.php', 'DbConnector.php');
		$root = dirname(__DIR__) . '/';
		$trail = array();
		foreach ($frames as $frame) {
			if (empty($frame['file']) || in_array(basename($frame['file']), $plumbing, true)) {
				continue;
			}
			$trail[] = str_replace($root, '', $frame['file']) . ':' . ($frame['line'] ?? 0);
			if (count($trail) === 4) {
				break;
			}
		}
		return $trail;
	}

	public function __construct($dsn, $username = null, $password = null, $options = null) {
		parent::__construct($dsn, $username, $password, $options);
		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('GuardedPdoStatement', array()));
	}

	/**
	 * Unprepared statements carry their values inline, so the guard reads the
	 * quoted literals out of the SQL text. Prepared statements — everything the
	 * platform's own code uses — are covered in GuardedPdoStatement::execute().
	 */
	#[\ReturnTypeWillChange]
	public function exec($statement) {
		self::$statements++;
		self::assertMayRun((string)$statement);
		// The isHot() test comes first everywhere: scanning a statement for
		// literals is only worth doing on the rare process that could leak.
		if (SealedEgressGuard::isHot()) {
			SealedEgressGuard::assertStatementAllowed($statement, self::literalsIn($statement));
		}
		return parent::exec($statement);
	}

	#[\ReturnTypeWillChange]
	public function query($query, $fetchMode = null, ...$fetchModeArgs) {
		self::$statements++;
		self::assertMayRun((string)$query);
		if (SealedEgressGuard::isHot()) {
			SealedEgressGuard::assertStatementAllowed($query, self::literalsIn($query));
		}
		if ($fetchMode === null) {
			return parent::query($query);
		}
		return parent::query($query, $fetchMode, ...$fetchModeArgs);
	}

	/** Single-quoted literals in a statement, with doubled quotes unescaped. */
	private static function literalsIn(string $sql): array {
		if (!preg_match_all("/'((?:[^']|'')*)'/", $sql, $matches)) {
			return array();
		}
		return array_map(function ($literal) {
			return str_replace("''", "'", $literal);
		}, $matches[1]);
	}
}

/**
 * A PDOStatement that shows the hot-turn rule what it is about to write.
 *
 * Values reach a statement three ways in this codebase — execute($params),
 * bindValue() then execute(), and bindParam() then execute() — so all three are
 * recorded. The rule cares only about the values, never which column they are
 * bound to, so no name mapping is needed.
 */
class GuardedPdoStatement extends PDOStatement {

	/** @var array values bound by bindValue() */
	private $bound_values = array();

	/** @var array references bound by bindParam(), read at execute time */
	private $bound_refs = array();

	protected function __construct() {}

	#[\ReturnTypeWillChange]
	public function bindValue($param, $value, $type = PDO::PARAM_STR) {
		$this->bound_values[$param] = $value;
		return parent::bindValue($param, $value, $type);
	}

	#[\ReturnTypeWillChange]
	public function bindParam($param, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = null) {
		$this->bound_refs[$param] = &$var;
		if ($driverOptions === null) {
			return parent::bindParam($param, $var, $type, $maxLength);
		}
		return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
	}

	#[\ReturnTypeWillChange]
	public function execute($params = null) {
		GuardedPdo::$statements++;
		GuardedPdo::assertMayRun((string)$this->queryString);
		if (SealedEgressGuard::isHot()) {
			SealedEgressGuard::assertStatementAllowed((string)$this->queryString, $this->boundValues($params));
		}
		return parent::execute($params);
	}

	/**
	 * Every value this statement will write, keyed the way the SQL refers to it:
	 * ':name' for named placeholders, 1-based ints for positional ones. execute()
	 * takes a 0-based list, bindValue() takes 1-based indexes or names, so the two
	 * are normalised here — the guard needs to look a specific placeholder up (the
	 * row id in a WHERE clause) as well as scan the values.
	 */
	private function boundValues($params): array {
		$values = array();
		if (is_array($params)) {
			$position = 1;
			foreach ($params as $key => $value) {
				$values[is_int($key) ? $position++ : $key] = $value;
			}
		}
		foreach ($this->bound_values as $key => $value) { $values[$key] = $value; }
		foreach ($this->bound_refs as $key => $value)   { $values[$key] = $value; }
		return $values;
	}
}
?>
