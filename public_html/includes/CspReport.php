<?php
/**
 * CspReport - what a browser says the strict script policy refused, recorded
 * as an error row (specs/strict_script_policy.md WP2).
 *
 * The browser POSTs each refusal to PublicPageBase::CSP_REPORT_PATH
 * (/api/v1/csp-report) with no sign-in and no CSRF header. apiv1.php hands the
 * request here before any other limiter, so reports never spend the address's
 * general API budget: a browser sends one per refused item, and an operator
 * browsing in report mode would otherwise lock their own address out of
 * sign-up and password reset. The channel has its own bucket instead
 * (RATE_LIMIT_REQUESTS an hour per network, every request it answers counted;
 * a refused one writes nothing).
 *
 * Anyone can post one, so a report is treated as a claim about this site's
 * own pages and kept small:
 *   - only a page on this site's own host is recorded, by path (no query);
 *   - the blocked item is "inline", "eval" or an origin, never a full URL;
 *   - the sample is the 40 characters the browser sends ('report-sample'),
 *     with anything but plain printable text and no markup characters;
 *   - one row per page, directive, source file and line an hour;
 *   - at most MAX_PER_NETWORK_HOUR rows an hour from one network, and
 *     MAX_PER_HOUR from everyone, so made-up pages fill little.
 * A row never becomes an automatic problem report: anyone can write one, and
 * a report each would let a stranger spend the site's daily allowance of them
 * (ProblemReport::AUTO_DAILY_LIMIT) and put their text in front of the
 * receiver. The operator reads them in the error list.
 *
 * A row reads "Strict script policy would refuse an inline handler on
 * /profile/security: doThing(1)" at level Warning, file
 * "csp:/profile/security script-src-attr ...".
 *
 * @version 1.2 - the channel's limit counts by network, and a refused request is not logged
 * @version 1.1 - its own pre-auth channel and bucket; never an automatic problem report;
 *                a per-network cap; no markup characters in the sample
 * @version 1.0
 */
class CspReport {

	/** Most rows recorded in any hour, from every page together. */
	const MAX_PER_HOUR = 100;

	/** Most rows one network (IpAddress::network()) records in an hour. */
	const MAX_PER_NETWORK_HOUR = 20;

	/** Requests one network may send to the channel in an hour, recorded or not. */
	const RATE_LIMIT_REQUESTS = 3000;
	const RATE_LIMIT_WINDOW = 3600;

	/** Largest body read. A browser's report is well under 2 KB. */
	const MAX_BODY_BYTES = 8192;

	/** The rql_feature every request to the channel is logged under. */
	const FEATURE = 'csp_report';

	/**
	 * Answer one request to the channel and exit. Every request is logged
	 * under FEATURE (success: a row was written), which is what both limits
	 * count. The answer is {recorded} whatever happened, so a caller learns
	 * nothing about the site from it.
	 */
	public static function dispatchPreAuth(): void {
		// Counted by network (an IPv6 caller's whole /64), and a refused
		// request writes nothing, so a flood past the limit costs one count.
		if (!RequestLogger::check_attempt_limit(self::FEATURE, self::RATE_LIMIT_REQUESTS, self::RATE_LIMIT_WINDOW)) {
			api_error('Too many reports from this network.', 'RateLimitError', 429);
		}
		$recorded = false;
		register_shutdown_function(function () use (&$recorded) {
			$code = http_response_code();
			RequestLogger::log(self::FEATURE, 'report', $recorded, array('status_code' => is_int($code) ? $code : 200));
		});
		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			api_error('Reports are sent with POST.', 'ActionError', 405);
		}
		$raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
		$body = strlen($raw) <= self::MAX_BODY_BYTES ? json_decode($raw, true) : null;
		$report = is_array($body) ? ($body['csp-report'] ?? null) : null;
		if (is_array($report)) {
			$host = (string)parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
			$parsed = $host !== '' ? self::parse($report, $host) : null;
			if ($parsed !== null) {
				$recorded = self::record($parsed) !== null;
			}
		}
		api_success(array('recorded' => $recorded));
		exit;
	}

	/** Longest script sample kept. */
	const SAMPLE_CAP = 40;

	/** The err_file prefix every row this records carries. */
	const FILE_PREFIX = 'csp:';

	/**
	 * Read one browser report ({"csp-report": {...}} as report-uri sends it)
	 * into the fields recorded, or null when it is not about this site.
	 *
	 * @param array  $report the "csp-report" object
	 * @param string $host   this site's host, as the browser addressed it
	 * @return array{page: string, directive: string, blocked: string, source: string, line: int, sample: string}|null
	 */
	public static function parse(array $report, string $host): ?array {
		$document = (string)($report['document-uri'] ?? '');
		$parts = parse_url($document);
		if (!is_array($parts) || strcasecmp((string)($parts['host'] ?? ''), $host) !== 0
				|| !in_array(strtolower((string)($parts['scheme'] ?? '')), array('http', 'https'), true)) {
			return null;
		}
		$page = self::cleanPath((string)($parts['path'] ?? '/'));

		$directive = strtolower((string)($report['effective-directive'] ?? $report['violated-directive'] ?? ''));
		$directive = preg_match('/^[a-z-]{1,40}/', $directive, $m) ? $m[0] : 'unknown';

		$blocked_raw = (string)($report['blocked-uri'] ?? '');
		if (in_array($blocked_raw, array('inline', 'eval', 'wasm-eval', 'self', ''), true)) {
			$blocked = $blocked_raw === '' ? 'inline' : $blocked_raw;
		} else {
			$b = parse_url($blocked_raw);
			$blocked = (is_array($b) && isset($b['scheme'], $b['host']))
				? strtolower($b['scheme'] . '://' . $b['host']) : 'other';
			if (!preg_match('#^[a-z]+://[a-z0-9.\-\[\]:]+$#', $blocked) && $blocked !== 'other') {
				$blocked = 'other';
			}
		}

		$source = '';
		$s = parse_url((string)($report['source-file'] ?? ''));
		if (is_array($s) && strcasecmp((string)($s['host'] ?? ''), $host) === 0) {
			$source = self::cleanPath((string)($s['path'] ?? ''));
		}

		// Plain printable text only, and no character that could start markup
		// or close an attribute wherever the row is shown.
		$sample = (string)($report['script-sample'] ?? '');
		$sample = preg_replace('/[^\x20-\x7E]|[<>"\'`&]/', '?', mb_substr($sample, 0, self::SAMPLE_CAP));

		return array(
			'page'      => $page,
			'directive' => $directive,
			'blocked'   => $blocked,
			'source'    => $source,
			'line'      => max(0, min(1000000, (int)($report['line-number'] ?? 0))),
			'sample'    => (string)$sample,
		);
	}

	/** What the refusal was, in words. */
	public static function describe(array $parsed): string {
		if ($parsed['blocked'] === 'inline') {
			$what = $parsed['directive'] === 'script-src-attr' ? 'an inline handler'
				: ($parsed['directive'] === 'script-src-elem' || $parsed['directive'] === 'script-src' ? 'an inline script' : 'inline ' . $parsed['directive']);
		} elseif ($parsed['blocked'] === 'eval') {
			$what = 'eval';
		} else {
			$what = $parsed['directive'] . ' from ' . $parsed['blocked'];
		}
		$message = 'Strict script policy would refuse ' . $what . ' on ' . $parsed['page'];
		if ($parsed['source'] !== '' && $parsed['source'] !== $parsed['page']) {
			$message .= ' (from ' . $parsed['source'] . ')';
		}
		if ($parsed['sample'] !== '') {
			$message .= ': ' . $parsed['sample'];
		}
		return $message;
	}

	/**
	 * Record one parsed report. Returns the row id, or null when nothing was
	 * written: already recorded this hour, or the network's or the hour's cap
	 * reached.
	 */
	public static function record(array $parsed): ?int {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT count(*) FROM err_general_errors
			WHERE err_create_time > now() - interval '1 hour' AND err_file LIKE ?");
		$q->execute(array(self::FILE_PREFIX . '%'));
		if ((int)$q->fetchColumn() >= self::MAX_PER_HOUR) {
			return null;
		}
		if (!RequestLogger::check_attempt_limit(self::FEATURE, self::MAX_PER_NETWORK_HOUR, 3600, true)) {
			return null;
		}
		$file = self::FILE_PREFIX . $parsed['page'] . ' ' . $parsed['directive']
			. ($parsed['source'] !== '' ? ' ' . $parsed['source'] : '');
		$q = $dblink->prepare("SELECT 1 FROM err_general_errors
			WHERE err_create_time > now() - interval '1 hour' AND err_file = ? AND err_line = ? LIMIT 1");
		$q->execute(array($file, (string)$parsed['line']));
		if ($q->fetchColumn() !== false) {
			return null;
		}
		$warning = new \ErrorException(self::describe($parsed), 0, E_USER_WARNING, $file, $parsed['line']);
		$row = new GeneralError(NULL);
		$id = $row->logError($warning, array(), array());
		return $id ? (int)$id : null;
	}

	/** A URL path with anything but plain path characters replaced, at most 200 long. */
	private static function cleanPath(string $path): string {
		$path = preg_replace('#[^A-Za-z0-9/_\-.~%]#', '_', $path);
		return substr($path === '' ? '/' : $path, 0, 200);
	}
}
