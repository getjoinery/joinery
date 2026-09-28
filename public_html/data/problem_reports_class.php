<?php
/**
 * ProblemReport — one problem reported from this site, by a member or by the
 * site itself.
 *
 * A member presses "Report this problem" on an error page or an error
 * message, reads what will be sent, adds a comment and optionally an image,
 * and sends it. The row keeps the report here whether or not it reached the
 * upgrade source: the bundle exactly as the member saw it, the comment, the
 * image, and how sending went. The hourly ProblemReportSend task retries what
 * did not go through.
 *
 * The report page and its submit action share inputs(), reporter() and
 * buildBundle(), so what the page shows and what submit() saves cannot drift.
 * send() POSTs a multipart body (bundle JSON, comment, image) to
 * {upgrade_source}/api/v1/action/bug_reports/report_submit, the receiving
 * action of the bug_reports plugin on the site this one upgrades from. The
 * request goes through SafeHttpClient: no redirects, a short timeout, and a
 * small response cap, since the answer is only a report id.
 *
 * Automatic reports (specs/bug_reports.md, Part 2): with the operator's
 * problem_reports_auto_send switch on, noteError() keeps one row per fault
 * (ProblemReportBundle::fingerprint()) and version for every unexpected error
 * recorded, counting each recurrence. It never sends from the failing request;
 * the hourly task sends a new fault as a report and later recurrences as a
 * count, which the receiver adds to the report it already holds.
 *
 * Statuses:
 *   queued  saved, not yet sent
 *   sent    the upgrade source accepted it (prr_remote_report_id holds its id)
 *   failed  sending was tried and did not succeed (prr_last_reason says why);
 *           retried until MAX_ATTEMPTS
 *   kept    this site does not send reports (problem_reports_send is off);
 *           kept here only
 *
 * See specs/bug_reports.md.
 *
 * @version 1.1.0 - automatic reports: noteError(), counts, and the count update on send
 * @version 1.0.0
 */

/** A refusal with a message for the reporter: empty comment, too many reports, a bad image. */
class ProblemReportException extends SystemBaseException {}

class ProblemReport extends SystemBase {
	public static $prefix = 'prr';
	public static $tablename = 'prr_problem_reports';
	public static $pkey_column = 'prr_problem_report_id';

	const STATUS_QUEUED = 'queued';
	const STATUS_SENT   = 'sent';
	const STATUS_FAILED = 'failed';
	const STATUS_KEPT   = 'kept';

	/** Tries before a report is left as failed. */
	const MAX_ATTEMPTS = 5;

	/** Longest comment accepted, in characters. */
	const COMMENT_MAX = 5000;

	/** Reports one member may send in an hour. */
	const HOURLY_LIMIT = 10;

	/** New faults the site may start automatic reports for in a day; recurrences are always counted. */
	const AUTO_DAILY_LIMIT = 20;

	/** @var bool Set while noteError() runs, so an error inside it is not noted in turn. */
	private static $noting = false;

	/** Largest image accepted, in bytes. */
	const IMAGE_MAX_BYTES = 5242880;

	/** Image types accepted, judged by content, not by name. */
	const IMAGE_TYPES = array('image/png', 'image/jpeg', 'image/webp', 'image/gif');

	/** Receiving action path on the upgrade source. */
	const ACTION_PATH = '/api/v1/action/bug_reports/report_submit';

	const SEND_TIMEOUT = 20;

	const SEND_RESPONSE_CAP = 65536;

	/** @var SafeHttpClient|null A stand-in client, for tests. */
	private static $client = null;

	public static $field_specifications = array(
		'prr_problem_report_id'    => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		// The reporter; NULL on an automatic report, which nobody made.
		'prr_usr_user_id'          => array('type' => 'int4', 'is_nullable' => true, 'index' => true),
		// The err_general_errors row reported, when there was one. A plain
		// number: the error log is pruned on its own window, and the bundle
		// already holds everything the report needs from the row.
		'prr_err_general_error_id' => array('type' => 'int8', 'is_nullable' => true),
		'prr_error_hash'           => array('type' => 'varchar(32)', 'is_nullable' => true),
		'prr_comment'              => array('type' => 'text', 'is_nullable' => false),
		// The bundle as JSON, exactly as the member saw it before sending.
		'prr_bundle'               => array('type' => 'text', 'is_nullable' => false),
		'prr_fil_file_id'          => array('type' => 'int8', 'is_nullable' => true),
		'prr_status'               => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'queued'),
		'prr_attempts'             => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'prr_last_attempt_time'    => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'prr_last_reason'          => array('type' => 'text', 'is_nullable' => true),
		// Where it was sent (the upgrade source's host) and the id it answered.
		'prr_destination'          => array('type' => 'varchar(255)', 'is_nullable' => true),
		'prr_remote_report_id'     => array('type' => 'varchar(64)', 'is_nullable' => true),
		'prr_create_time'          => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		// Automatic reports: one row per fault and version, and how often the
		// fault happened. occurrences_sent is how many the receiver has heard.
		'prr_automatic'            => array('type' => 'bool', 'is_nullable' => false, 'default' => 'false'),
		'prr_fingerprint'          => array('type' => 'varchar(32)', 'is_nullable' => true),
		'prr_version'              => array('type' => 'varchar(64)', 'is_nullable' => true),
		'prr_occurrences'          => array('type' => 'int4', 'is_nullable' => false, 'default' => 1),
		'prr_occurrences_sent'     => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'prr_last_seen_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	public static $index_specifications = array(
		array('columns' => array('prr_status')),
		array('columns' => array('prr_create_time')),
		array('columns' => array('prr_fingerprint', 'prr_version')),
	);

	protected static $foreign_key_actions = array(
		// A report is its author's words and image: they go with the account.
		'prr_usr_user_id' => array('action' => 'permanent_delete'),
		'prr_fil_file_id' => array('action' => 'null'),
		// The error log prunes on its own window; the bundle already holds
		// what the report needs from the row.
		'prr_err_general_error_id' => array('action' => 'null'),
	);

	// Retention: reports older than the window are deleted with their image.
	// 0 in the setting keeps them. See docs/scheduled_tasks.md.
	public static $retention_policy = array(
		'label'          => 'Problem reports',
		'purge_method'   => 'purgeExpired',
		'window_setting' => 'problem_reports_retention_days',
	);

	/** Most rows the retention sweep removes in one run. */
	const PURGE_MAX_PER_RUN = 500;

	function authenticate_read($data) {
		if (($data['current_user_permission'] ?? 0) < 9
				&& (int)($data['current_user_id'] ?? 0) !== (int)$this->get('prr_usr_user_id')) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in ' . static::$tablename);
		}
	}

	function authenticate_write($data) {
		if (($data['current_user_permission'] ?? 0) < 9
				&& (int)($data['current_user_id'] ?? 0) !== (int)$this->get('prr_usr_user_id')) {
			throw new SystemAuthenticationError('Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/** The bundle, decoded. */
	public function bundle(): array {
		$decoded = json_decode((string)$this->get('prr_bundle'), true);
		return is_array($decoded) ? $decoded : array();
	}

	/** Whether the sender should still try this report. */
	public function is_sendable(): bool {
		$status = $this->get('prr_status');
		return $status === self::STATUS_QUEUED
			|| ($status === self::STATUS_FAILED && (int)$this->get('prr_attempts') < self::MAX_ATTEMPTS)
			|| ($status === self::STATUS_SENT && (bool)$this->get('prr_automatic') && $this->unsentOccurrences() > 0);
	}

	/** A short human description of the status, for the admin list. */
	public function status_label(): string {
		switch ($this->get('prr_status')) {
			case self::STATUS_SENT:   return 'Sent';
			case self::STATUS_QUEUED: return 'Waiting to send';
			case self::STATUS_KEPT:   return 'Kept on this site';
			case self::STATUS_FAILED:
				return (int)$this->get('prr_attempts') >= self::MAX_ATTEMPTS ? 'Not sent (gave up)' : 'Not sent yet';
		}
		return (string)$this->get('prr_status');
	}

	// ------------------------------------------------------------ making one

	/**
	 * The report's inputs, cleaned: the error row id, the page it happened on
	 * (a local path only) and the message the reporter saw.
	 *
	 * @return array{ref: ?int, from: string, msg: string}
	 */
	public static function inputs(array $input): array {
		$ref = isset($input['ref']) && preg_match('/^\d{1,18}$/', (string)$input['ref']) ? (int)$input['ref'] : null;
		$from = trim((string)($input['from'] ?? ''));
		// A local path only: a full URL or a protocol-relative one names
		// somewhere else, and the report is about this site.
		if ($from === '' || $from[0] !== '/' || strpos($from, '//') === 0) {
			$from = '';
		}
		$from = mb_substr($from, 0, 2000);
		$msg = mb_substr(trim((string)($input['msg'] ?? '')), 0, ErrorReference::MESSAGE_CAP);
		return array('ref' => $ref, 'from' => $from, 'msg' => $msg);
	}

	/** The signed-in reporter: id, permission, and whether an admin is acting as them. */
	public static function reporter(SessionControl $session): array {
		$user_id = (int)$session->get_user_id();
		$initial = $session->get_initial_user_id();
		return array(
			'user_id'      => $user_id,
			'permission'   => (int)$session->get_permission(),
			'logged_in_as' => $initial !== null && (int)$initial !== $user_id,
		);
	}

	/** The bundle for these inputs and this reporter. */
	public static function buildBundle(array $reporter, array $inputs): array {
		return ProblemReportBundle::build($reporter['user_id'], $reporter['permission'], $reporter['logged_in_as'],
			$inputs['ref'], $inputs['from'], $inputs['msg']);
	}

	/** Whether this site sends reports (the operator's switch). */
	public static function sendingEnabled(): bool {
		$value = Globalvars::get_instance()->get_setting('problem_reports_send', true, true);
		// Declared default on; an unseeded site behaves as the default says.
		return $value === null || $value === '' ? true : (bool)(int)$value;
	}

	/** The upgrade source's base URL, where reports go. */
	public static function destinationUrl(): string {
		$source = trim((string)Globalvars::get_instance()->get_setting('upgrade_source', true, true));
		return rtrim($source !== '' ? $source : 'https://getjoinery.com', '/');
	}

	/** The upgrade source's host name, for the page and the admin list. */
	public static function destinationHost(): string {
		return (string)parse_url(self::destinationUrl(), PHP_URL_HOST);
	}

	/**
	 * Save a report and try to send it once.
	 *
	 * @param array      $reporter from reporter()
	 * @param array      $inputs   from inputs()
	 * @param string     $comment  the reporter's words
	 * @param array|null $upload   one $_FILES entry, or NULL
	 * @return ProblemReport the saved row, with its status after the send attempt
	 * @throws ProblemReportException with a message for the reporter
	 */
	public static function submit(array $reporter, array $inputs, string $comment, ?array $upload): ProblemReport {
		$comment = trim($comment);
		if ($comment === '') {
			throw new ProblemReportException('Say in a few words what went wrong.');
		}
		if (mb_strlen($comment) > self::COMMENT_MAX) {
			throw new ProblemReportException('Keep the description under ' . number_format(self::COMMENT_MAX) . ' characters.');
		}
		$recent = count(new MultiProblemReport(array('user_id' => $reporter['user_id'], 'created_after' => gmdate('Y-m-d H:i:s', time() - 3600))));
		if ($recent >= self::HOURLY_LIMIT) {
			throw new ProblemReportException('You have sent ' . self::HOURLY_LIMIT . ' reports in the last hour. Try again later.');
		}

		$bundle = self::buildBundle($reporter, $inputs);
		$file = ($upload !== null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
			? self::storeImage($upload, $reporter['user_id']) : null;

		$report = new ProblemReport(NULL);
		$report->set('prr_usr_user_id', $reporter['user_id']);
		$report->set('prr_err_general_error_id', $bundle['error']['id'] ?? null);
		$report->set('prr_error_hash', $bundle['error']['hash'] ?? null);
		$report->set('prr_comment', $comment);
		$report->set('prr_bundle', json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$report->set('prr_fil_file_id', $file ? (int)$file->key : null);
		$report->set('prr_status', self::sendingEnabled() ? self::STATUS_QUEUED : self::STATUS_KEPT);
		$report->set('prr_destination', self::destinationHost());
		$report->save();

		if ($report->get('prr_status') === self::STATUS_QUEUED) {
			$report->send();
		}
		return $report;
	}

	/**
	 * Store the report's image, privately. The type is judged by the bytes,
	 * not the file name or the browser's claim.
	 *
	 * @throws ProblemReportException
	 */
	public static function storeImage(array $upload, int $user_id): File {
		if (!isset($upload['error']) || is_array($upload['error'])) {
			throw new ProblemReportException('That image did not arrive properly.');
		}
		switch ($upload['error']) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				throw new ProblemReportException('That image is too large. Images can be up to 5 MB.');
			default:
				throw new ProblemReportException('That image did not finish uploading.');
		}
		if (!is_uploaded_file($upload['tmp_name'])) {
			throw new ProblemReportException('That image did not arrive properly.');
		}
		if ((int)$upload['size'] > self::IMAGE_MAX_BYTES) {
			throw new ProblemReportException('That image is too large. Images can be up to 5 MB.');
		}
		$mime = File::detect_mime_file($upload['tmp_name']);
		if (!in_array($mime, self::IMAGE_TYPES, true)) {
			throw new ProblemReportException('Attach a PNG, JPEG, WebP or GIF image.');
		}
		$extension = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif')[$mime];
		return File::createFromUpload($upload['tmp_name'], 'problem-report.' . $extension, $mime, $user_id, array(
			'fil_private' => true,
			'fil_source'  => File::SOURCE_PROBLEM_REPORT,
		));
	}

	// ------------------------------------------------------------- automatic

	/** Whether this site sends a report on its own when something breaks. Off unless the operator turns it on. */
	public static function autoSendEnabled(): bool {
		return (bool)(int)Globalvars::get_instance()->get_setting('problem_reports_auto_send', true, true);
	}

	/**
	 * Whether an error is a bug rather than a person meeting a wall (D14): not
	 * a permission refusal, a sign-in requirement, a validation failure, or an
	 * error whose message is marked safe to show.
	 */
	public static function isUnexpected(\Throwable $e): bool {
		if ($e instanceof \DisplayableErrorMessage
				|| $e instanceof \DisplayableErrorMessageNoLog
				|| $e instanceof \DisplayablePermanentErrorMessage
				|| $e instanceof \DisplayablePermanentErrorMessageNoLog
				|| $e instanceof \SystemAuthenticationError
				|| $e instanceof \AuthenticationException
				|| $e instanceof \AuthorizationException
				|| $e instanceof \ValidationException) {
			return false;
		}
		return !($e instanceof \BaseException && $e->shouldDisplay());
	}

	/**
	 * An error was recorded as row $error_id: count it toward its automatic
	 * report, or start one. Runs on the failing request, so it sends nothing
	 * (the hourly task does), throws nothing, and does nothing unless both of
	 * the operator's switches are on and the error is unexpected. While the
	 * process holds sealed content only the count moves: a new report's text
	 * is a long write the sealed-content guard would refuse.
	 */
	public static function noteError(\Throwable $e, ?int $error_id): void {
		if (self::$noting || !$error_id) {
			return;
		}
		self::$noting = true;
		try {
			if (!self::autoSendEnabled() || !self::sendingEnabled() || !self::isUnexpected($e)) {
				return;
			}
			$row = new GeneralError($error_id, TRUE);
			if (!$row->key) {
				return;
			}
			$fingerprint = ProblemReportBundle::fingerprint(ProblemReportBundle::errorFromRow($row));
			if ($fingerprint === null) {
				return;
			}
			$version = (string)LibraryFunctions::get_joinery_version();
			if (self::countRecurrence($fingerprint, $version)) {
				return;
			}
			if (SealedEgressGuard::isHot() || self::automaticToday() >= self::AUTO_DAILY_LIMIT) {
				return;
			}
			$bundle = ProblemReportBundle::automatic($row, get_class($e), (string)($_SERVER['REQUEST_URI'] ?? ''));
			SystemBase::server_initiated_write(function () use ($bundle, $fingerprint, $version, $row) {
				$report = new ProblemReport(NULL);
				$report->set('prr_automatic', true);
				$report->set('prr_fingerprint', $fingerprint);
				$report->set('prr_version', mb_substr($version, 0, 64));
				$report->set('prr_occurrences', 1);
				$report->set('prr_last_seen_time', gmdate('Y-m-d H:i:s'));
				$report->set('prr_err_general_error_id', (int)$row->key);
				$report->set('prr_error_hash', $bundle['error']['hash'] ?? null);
				$report->set('prr_comment', '');
				$report->set('prr_bundle', json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
				$report->set('prr_status', self::STATUS_QUEUED);
				$report->set('prr_destination', self::destinationHost());
				$report->save();
			});
		} catch (\Throwable $t) {
			error_log('Problem reports: an automatic report was not noted: ' . $t->getMessage());
		} finally {
			self::$noting = false;
		}
	}

	/** Add one to the automatic report for this fault and version. Whether there was one. */
	private static function countRecurrence(string $fingerprint, string $version): bool {
		return (bool)SystemBase::server_initiated_write(function () use ($fingerprint, $version) {
			$stmt = DbConnector::get_instance()->get_db_link()->prepare(
				"UPDATE prr_problem_reports
				    SET prr_occurrences = prr_occurrences + 1, prr_last_seen_time = now()
				  WHERE prr_automatic AND prr_fingerprint = ? AND prr_version = ?");
			$stmt->execute(array($fingerprint, $version));
			return $stmt->rowCount() > 0;
		});
	}

	/** Automatic reports started in the last day. */
	private static function automaticToday(): int {
		return (int)DbConnector::get_instance()->get_db_link()->query(
			"SELECT COUNT(*) FROM prr_problem_reports
			  WHERE prr_automatic AND prr_create_time > now() - INTERVAL '1 day'")->fetchColumn();
	}

	/** Recurrences the receiver has not heard about yet. */
	public function unsentOccurrences(): int {
		return max(0, (int)$this->get('prr_occurrences') - (int)$this->get('prr_occurrences_sent'));
	}

	// --------------------------------------------------------------- sending

	/** Use $client instead of a real SafeHttpClient (NULL restores it). For tests. */
	public static function useClient(?SafeHttpClient $client): void {
		self::$client = $client;
	}

	/**
	 * Try to send this report. A 2xx marks it sent with the id the receiver
	 * answered; anything else marks it failed with the reason, and the hourly
	 * task tries again until MAX_ATTEMPTS. Nothing is sent while the
	 * operator's problem_reports_send switch is off. Saves the row either way.
	 *
	 * @return bool whether the upgrade source accepted it
	 */
	public function send(): bool {
		if (!self::sendingEnabled()) {
			$this->set('prr_status', self::STATUS_KEPT);
			$this->set('prr_last_reason', 'Sending problem reports is switched off on this site.');
			$this->save();
			return false;
		}

		$url = self::destinationUrl() . self::ACTION_PATH;
		$this->set('prr_attempts', (int)$this->get('prr_attempts') + 1);
		$this->set('prr_last_attempt_time', gmdate('Y-m-d H:i:s'));
		$this->set('prr_destination', (string)parse_url($url, PHP_URL_HOST));

		// An automatic report carries the recurrences the receiver has not
		// heard yet; on success they are marked heard.
		$occurrences = (bool)$this->get('prr_automatic') ? $this->unsentOccurrences() : 0;
		try {
			list($body, $content_type) = $this->multipart_body($occurrences);
			$client = self::$client ?: new SafeHttpClient(array(
				'timeout'            => self::SEND_TIMEOUT,
				'max_response_bytes' => self::SEND_RESPONSE_CAP,
				'user_agent'         => 'Joinery/' . LibraryFunctions::get_joinery_version() . ' problem-report',
			));
			$response = $client->post($url, $body, array('Content-Type' => $content_type, 'Accept' => 'application/json'));
		} catch (\Throwable $e) {
			return $this->mark_failed('Could not reach ' . $this->get('prr_destination') . ': ' . $e->getMessage());
		}

		if ($response->status >= 200 && $response->status < 300) {
			$env = json_decode($response->body, true);
			$remote = is_array($env) ? ($env['data']['report_id'] ?? null) : null;
			$this->set('prr_status', self::STATUS_SENT);
			$this->set('prr_remote_report_id', $remote !== null ? mb_substr((string)$remote, 0, 64) : null);
			$this->set('prr_last_reason', null);
			$this->set('prr_attempts', 0);
			// Recurrences counted while this send was in flight stay unsent.
			$this->set('prr_occurrences_sent', (int)$this->get('prr_occurrences_sent') + $occurrences);
			$this->save();
			return true;
		}

		$env = json_decode($response->body, true);
		$said = is_array($env) && isset($env['error']) && is_string($env['error']) ? ': ' . mb_substr($env['error'], 0, 300) : '';
		return $this->mark_failed($this->get('prr_destination') . ' answered HTTP ' . $response->status . $said);
	}

	/** Send every report still due a try. Returns [sent, failed]. */
	public static function sendDue(int $limit = 50): array {
		$sent = 0;
		$failed = 0;
		$due = new MultiProblemReport(array('sendable' => true), array('prr_create_time' => 'ASC'), $limit);
		foreach ($due as $report) {
			if ($report->send()) {
				$sent++;
			} else {
				$failed++;
			}
		}
		return array($sent, $failed);
	}

	private function mark_failed(string $reason): bool {
		$this->set('prr_status', self::STATUS_FAILED);
		$this->set('prr_last_reason', mb_substr($reason, 0, 1000));
		$this->save();
		return false;
	}

	/**
	 * The multipart body: bundle, comment, and the image when there is one.
	 *
	 * @return array{0: string, 1: string} body and Content-Type header
	 */
	private function multipart_body(int $occurrences = 0): array {
		$boundary = '----joinery-report-' . bin2hex(random_bytes(12));
		$bundle_json = (string)$this->get('prr_bundle');
		if ($occurrences > 0) {
			$bundle = json_decode($bundle_json, true);
			if (is_array($bundle)) {
				$bundle['occurrences'] = $occurrences;
				$bundle_json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		}
		$parts = array();
		$field = function ($name, $value) use ($boundary) {
			return '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n"
				. "Content-Type: text/plain; charset=utf-8\r\n\r\n"
				. $value . "\r\n";
		};
		$parts[] = $field('bundle', $bundle_json);
		$parts[] = $field('comment', (string)$this->get('prr_comment'));

		$file_id = (int)$this->get('prr_fil_file_id');
		if ($file_id > 0 && File::check_if_exists($file_id)) {
			$file = new File($file_id, TRUE);
			$bytes = $file->read_bytes();
			if (is_string($bytes) && $bytes !== '') {
				$type = (string)$file->get('fil_type');
				$extension = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif')[$type] ?? 'bin';
				$parts[] = '--' . $boundary . "\r\n"
					. 'Content-Disposition: form-data; name="image"; filename="problem-report.' . $extension . '"' . "\r\n"
					. 'Content-Type: ' . $type . "\r\n\r\n"
					. $bytes . "\r\n";
			}
		}
		return array(implode('', $parts) . '--' . $boundary . "--\r\n", 'multipart/form-data; boundary=' . $boundary);
	}

	/**
	 * Save, first reloading an automatic report's count: save() writes every
	 * column, and noteError() counts recurrences with its own UPDATE from other
	 * requests, so a row loaded before a recurrence would write its count back
	 * over it.
	 */
	function save($debug = false) {
		if ($this->key && (bool)$this->get('prr_automatic')) {
			$stmt = DbConnector::get_instance()->get_db_link()->prepare(
				'SELECT prr_occurrences FROM prr_problem_reports WHERE prr_problem_report_id = ?');
			$stmt->execute(array((int)$this->key));
			$current = $stmt->fetchColumn();
			if ($current !== false) {
				$this->set('prr_occurrences', (int)$current);
			}
		}
		return parent::save($debug);
	}

	// -------------------------------------------------------------- deleting

	/** Delete the report and its image. */
	function permanent_delete($debug = false) {
		$file_id = (int)$this->get('prr_fil_file_id');
		if ($file_id > 0 && File::check_if_exists($file_id)) {
			$file = new File($file_id, TRUE);
			$file->permanent_delete();
		}
		return parent::permanent_delete($debug);
	}

	/**
	 * The retention rule: reports older than the window, each deleted through
	 * permanent_delete() so its image goes too.
	 *
	 * @param int $days Retention window from the setting
	 * @return array removed, message
	 */
	public static function purgeExpired($days) {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT prr_problem_report_id FROM prr_problem_reports
			  WHERE prr_create_time < now() - (INTERVAL '1 day' * :days)
			  ORDER BY prr_create_time ASC
			  LIMIT :cap");
		$stmt->bindValue(':days', (int)$days, PDO::PARAM_INT);
		$stmt->bindValue(':cap', self::PURGE_MAX_PER_RUN, PDO::PARAM_INT);
		$stmt->execute();
		$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

		$removed = 0;
		foreach ($ids as $id) {
			$report = new ProblemReport((int)$id, TRUE);
			$report->permanent_delete();
			$removed++;
		}
		return array(
			'removed' => $removed,
			'message' => $removed ? $removed . ' problem report(s) past the ' . (int)$days . '-day window'
				: 'no problem reports past the ' . (int)$days . '-day window',
		);
	}
}

class MultiProblemReport extends SystemMultiBase {
	protected static $model_class = 'ProblemReport';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['prr_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['status'])) {
			$filters['prr_status'] = array($this->options['status'], PDO::PARAM_STR);
		}
		if (isset($this->options['created_after'])) {
			// Any time strtotime() reads, as UTC; an unreadable value means no lower bound.
			$after = strtotime((string)$this->options['created_after'] . ' UTC');
			$filters['prr_create_time'] = "> '" . gmdate('Y-m-d H:i:s', $after === false ? 0 : $after) . "'";
		}
		if (!empty($this->options['sendable'])) {
			// Due: never sent, a failed try with tries left, or an automatic
			// report that has counted recurrences since it was last sent.
			$filters['(prr_status'] = "= 'queued' OR (prr_status = 'failed' AND prr_attempts < "
				. (int)ProblemReport::MAX_ATTEMPTS . ")"
				. " OR (prr_status = 'sent' AND prr_automatic AND prr_occurrences > prr_occurrences_sent))";
		}
		if (isset($this->options['automatic'])) {
			$filters['prr_automatic'] = $this->options['automatic'] ? '= true' : '= false';
		}

		return $this->_get_resultsv2('prr_problem_reports', $filters, $this->order_by, $only_count, $debug);
	}
}
