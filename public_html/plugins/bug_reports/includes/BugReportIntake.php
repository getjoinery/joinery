<?php
/**
 * BugReportIntake — files a problem report another site sent.
 *
 * The receiving action (logic/report_submit_logic.php) is open to anyone and
 * rate-limited; this class does the rest, in order:
 *
 *   1. parse the bundle and refuse anything that is not a report: a JSON
 *      object whose `site` section names a host and a version;
 *   2. call the claimed site back (a HEAD request to its front page, which
 *      every Joinery site answers with X-Joinery-Version) and record the
 *      verdict — verified, version_mismatch or unverified;
 *   3. refuse, instead of storing, an unverified report from an address that
 *      already sent UNVERIFIED_PER_HOUR unverified reports in the hour;
 *   4. an automatic report (bundle scope "automatic") of a fault already
 *      stored as an automatic report for the same site and version is not
 *      stored again: its count is added to that report, which reopens if it
 *      was closed (specs/bug_reports.md, Part 2, D12);
 *   5. keep the image when it passes the same checks a member upload does,
 *      and note why when it does not (a bad image never refuses the report);
 *   6. save the row, and email the operator about a new error when asked to.
 *
 * Everything in a report is untrusted input from another machine: stored as
 * text, escaped on display, never executed, resolved, or used as a path. The
 * claimed host is used for exactly one thing, the callback, and that goes
 * through SafeHttpClient, which refuses private and internal addresses.
 *
 * @version 1.1.0 - fingerprints; automatic reports merge into the stored one and add their count
 * @version 1.0.0
 */
class BugReportRefusal extends Exception {
	/** @var int HTTP status for the refusal */
	public $status;
	public function __construct(string $message, int $status = 422) {
		parent::__construct($message);
		$this->status = $status;
	}
}

class BugReportIntake {

	const BUNDLE_MAX = 262144;
	const COMMENT_MAX = 5000;
	const UNVERIFIED_PER_HOUR = 3;
	const IMAGE_MAX_BYTES = 5242880;
	const IMAGE_TYPES = array('image/png', 'image/jpeg', 'image/webp', 'image/gif');

	/** Longest value kept in a summary column. */
	const COLUMN_CAP = 1000;

	/** Largest count one automatic report may add. */
	const OCCURRENCES_MAX = 1000000;

	/** @var SafeHttpClient|null A stand-in client for the callback, for tests. */
	private static $client = null;

	public static function useClient(?SafeHttpClient $client): void {
		self::$client = $client;
	}

	/**
	 * File one report.
	 *
	 * @param string      $bundle_json the bundle as sent
	 * @param string      $comment     the reporter's words
	 * @param string|null $image_bytes the attached image, or NULL
	 * @param string      $sender_ip   the address the report came from
	 * @param string|null $image_problem why an attached image could not be read, when it could not
	 * @throws BugReportRefusal
	 */
	public static function receive(string $bundle_json, string $comment, ?string $image_bytes, string $sender_ip, ?string $image_problem = null): ReceivedBugReport {
		if (strlen($bundle_json) > self::BUNDLE_MAX) {
			throw new BugReportRefusal('The report is larger than ' . self::BUNDLE_MAX . ' bytes.', 413);
		}
		$bundle = json_decode($bundle_json, true);
		if (!is_array($bundle) || array_keys($bundle) === range(0, count($bundle) - 1)) {
			throw new BugReportRefusal('The bundle is not a JSON object.');
		}
		$host = strtolower(trim((string)($bundle['site']['host'] ?? '')));
		$version = trim((string)($bundle['site']['version'] ?? ''));
		if (!self::isHostName($host)) {
			throw new BugReportRefusal('The bundle does not name the site it came from.');
		}
		if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.+\-]{0,63}$/', $version)) {
			throw new BugReportRefusal('The bundle does not name the version the site runs.');
		}
		$automatic = ($bundle['scope'] ?? null) === 'automatic';
		$comment = trim($comment);
		if ($comment === '' && !$automatic) {
			throw new BugReportRefusal('The report has no description.');
		}
		$comment = mb_substr($comment, 0, self::COMMENT_MAX);

		list($verdict, $reason) = self::callback($host, $version);

		if ($verdict === ReceivedBugReport::VERDICT_UNVERIFIED
				&& self::unverifiedFrom($sender_ip) >= self::UNVERIFIED_PER_HOUR) {
			throw new BugReportRefusal('Too many reports from this address could not be traced to a Joinery site. Try again in an hour.', 429);
		}

		$error = is_array($bundle['error'] ?? null) ? $bundle['error'] : array();
		$fingerprint = ProblemReportBundle::fingerprint($error);
		$occurrences = self::occurrences($bundle);
		if ($automatic && $fingerprint !== null) {
			$stored = self::addToStored($fingerprint, $host, $version, $occurrences);
			if ($stored !== null) {
				return $stored;
			}
		}

		$file = null;
		$image_note = $image_problem;
		if ($image_bytes !== null && $image_bytes !== '') {
			try {
				$file = self::storeImage($image_bytes);
			} catch (BugReportRefusal $e) {
				$image_note = $e->getMessage();
			}
		}

		$location = isset($error['file']) ? $error['file'] . (isset($error['line']) ? ':' . $error['line'] : '') : null;
		$hash = (isset($error['hash']) && is_string($error['hash']) && preg_match('/^[0-9a-f]{32}$/', $error['hash']))
			? $error['hash'] : null;

		$report = new ReceivedBugReport(NULL);
		$report->set('rbr_sender_ip', mb_substr($sender_ip, 0, 64));
		$report->set('rbr_claimed_host', $host);
		$report->set('rbr_claimed_version', $version);
		$report->set('rbr_verdict', $verdict);
		$report->set('rbr_verdict_reason', mb_substr($reason, 0, self::COLUMN_CAP));
		$report->set('rbr_error_hash', $hash);
		$report->set('rbr_error_kind', self::column($error['kind'] ?? null, 255));
		$report->set('rbr_error_location', self::column($location));
		$report->set('rbr_error_message', self::column($error['message'] ?? null));
		$report->set('rbr_comment', $comment);
		$report->set('rbr_bundle', $bundle_json);
		$report->set('rbr_fil_file_id', $file ? (int)$file->key : null);
		$report->set('rbr_image_note', $image_note);
		$report->set('rbr_status', ReceivedBugReport::STATUS_NEW);
		$report->set('rbr_fingerprint', $fingerprint);
		$report->set('rbr_automatic', $automatic);
		$report->set('rbr_occurrences', $automatic ? $occurrences : 1);
		$report->set('rbr_last_seen_time', gmdate('Y-m-d H:i:s'));
		$report->save();

		self::notify($report);
		return $report;
	}

	/** How many times an automatic report says the fault happened: 1 to OCCURRENCES_MAX, 1 when missing. */
	public static function occurrences(array $bundle): int {
		$n = $bundle['occurrences'] ?? 1;
		if (!is_int($n) && !(is_string($n) && ctype_digit($n))) {
			return 1;
		}
		return max(1, min(self::OCCURRENCES_MAX, (int)$n));
	}

	/**
	 * Add $occurrences to the stored automatic report of this fault from this
	 * site and version, and reopen it if it was closed. One UPDATE, so two
	 * count updates arriving together both land. NULL when there is none.
	 */
	public static function addToStored(string $fingerprint, string $host, string $version, int $occurrences): ?ReceivedBugReport {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"UPDATE rbr_received_bug_reports
			    SET rbr_occurrences = LEAST(rbr_occurrences::int8 + :n, 2147483647),
			        rbr_last_seen_time = now(),
			        rbr_status = CASE WHEN rbr_status = 'closed' THEN 'new' ELSE rbr_status END,
			        rbr_closed_usr_user_id = CASE WHEN rbr_status = 'closed' THEN NULL ELSE rbr_closed_usr_user_id END,
			        rbr_closed_time = CASE WHEN rbr_status = 'closed' THEN NULL ELSE rbr_closed_time END
			  WHERE rbr_received_bug_report_id = (
			        SELECT rbr_received_bug_report_id FROM rbr_received_bug_reports
			         WHERE rbr_automatic AND rbr_fingerprint = :fp AND rbr_claimed_host = :host AND rbr_claimed_version = :version
			         ORDER BY rbr_received_bug_report_id ASC LIMIT 1)
			RETURNING rbr_received_bug_report_id");
		$stmt->bindValue(':n', $occurrences, PDO::PARAM_INT);
		$stmt->bindValue(':fp', $fingerprint);
		$stmt->bindValue(':host', $host);
		$stmt->bindValue(':version', $version);
		$stmt->execute();
		$id = $stmt->fetchColumn();
		return $id === false ? null : new ReceivedBugReport((int)$id, TRUE);
	}

	/**
	 * Ask the claimed site whether it is the Joinery site the bundle says.
	 *
	 * @return array{0: string, 1: string} verdict and the reason for it
	 */
	public static function callback(string $host, string $version): array {
		$client = self::$client ?: new SafeHttpClient(array(
			'timeout'            => 5,
			'connect_timeout'    => 5,
			'max_response_bytes' => 4096,
			'user_agent'         => 'Joinery/bug-reports callback',
		));
		try {
			$response = $client->head('https://' . $host . '/');
		} catch (\Throwable $e) {
			return array(ReceivedBugReport::VERDICT_UNVERIFIED, 'The site could not be reached: ' . $e->getMessage());
		}
		$answered = trim((string)$response->header('x-joinery-version'));
		if ($answered === '') {
			return array(ReceivedBugReport::VERDICT_UNVERIFIED,
				'The site answered HTTP ' . $response->status . ' without a Joinery version. A site behind an IP allowlist answers this way too.');
		}
		if ($answered !== $version) {
			return array(ReceivedBugReport::VERDICT_MISMATCH,
				'The site runs ' . mb_substr($answered, 0, 64) . ', not the ' . $version . ' the report claims.');
		}
		return array(ReceivedBugReport::VERDICT_VERIFIED, 'The site answered with the claimed version.');
	}

	/** Unverified reports from this address in the last hour. */
	public static function unverifiedFrom(string $ip): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT COUNT(*) FROM rbr_received_bug_reports
			  WHERE rbr_sender_ip = ? AND rbr_verdict = 'unverified'
			    AND rbr_received_time > now() - INTERVAL '1 hour'");
		$stmt->execute(array($ip));
		return (int)$stmt->fetchColumn();
	}

	/**
	 * Keep the image privately, judged by its bytes.
	 *
	 * @throws BugReportRefusal with the reason it was not kept
	 */
	public static function storeImage(string $bytes): File {
		if (strlen($bytes) > self::IMAGE_MAX_BYTES) {
			throw new BugReportRefusal('The image was larger than 5 MB and was not kept.');
		}
		$mime = File::detect_mime_bytes($bytes);
		if (!in_array($mime, self::IMAGE_TYPES, true)) {
			throw new BugReportRefusal('The attachment was not a PNG, JPEG, WebP or GIF image and was not kept.');
		}
		$extension = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif')[$mime];
		return File::createFromBytes($bytes, 'bug-report.' . $extension, $mime, null, array(
			'fil_private' => true,
			'fil_source'  => File::SOURCE_BUG_REPORT_IMAGE,
		));
	}

	/**
	 * Email the operator about an error not seen in the last day, when an
	 * address is set. Content-free apart from host, version, kind and place:
	 * the report itself stays on the admin page.
	 */
	public static function notify(ReceivedBugReport $report): void {
		$to = trim((string)Globalvars::get_instance()->get_setting('bug_reports_notify_email', true, true));
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return;
		}
		$group = $report->group_key();
		$db = DbConnector::get_instance()->get_db_link();
		if ($group !== '') {
			$stmt = $db->prepare("SELECT COUNT(*) FROM rbr_received_bug_reports
				WHERE " . ReceivedBugReport::GROUP_KEY . " = ? AND rbr_received_bug_report_id <> ?
				  AND rbr_received_time > now() - INTERVAL '1 day'");
			$stmt->execute(array($group, (int)$report->key));
			if ((int)$stmt->fetchColumn() > 0) {
				return;
			}
		}
		$settings = Globalvars::get_instance();
		$base = rtrim((string)$settings->get_setting('webDir', true, true), '/');
		$link = ($base !== '' ? (preg_match('#^https?://#', $base) ? $base : 'https://' . $base) : '')
			. '/plugins/bug_reports/admin/admin_bug_report?rbr_received_bug_report_id=' . (int)$report->key;
		$body = "A site reported a problem that has not been reported in the last day.\n\n"
			. 'Site: ' . $report->get('rbr_claimed_host') . ' (' . $report->get('rbr_verdict') . ")\n"
			. 'Version: ' . $report->get('rbr_claimed_version') . "\n"
			. 'Error: ' . ($report->get('rbr_error_kind') ?: 'none recorded') . "\n"
			. 'Where: ' . ($report->get('rbr_error_location') ?: 'not recorded') . "\n\n"
			. 'Read it: ' . $link . "\n";
		try {
			EmailSender::quickSend($to, 'New problem report from ' . $report->get('rbr_claimed_host'), $body);
		} catch (\Throwable $e) {
			error_log('bug_reports: the new-problem email was not sent: ' . $e->getMessage());
		}
	}

	/** A plausible public host name: labels of letters, digits and hyphens. */
	public static function isHostName(string $host): bool {
		return strlen($host) <= 253
			&& (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
	}

	private static function column($value, int $cap = self::COLUMN_CAP): ?string {
		if ($value === null || is_array($value)) {
			return null;
		}
		$text = (string)$value;
		return $text === '' ? null : mb_substr($text, 0, $cap);
	}
}
