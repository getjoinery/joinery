<?php
/**
 * MailSendOutcome - how this site's mail left, counted per From-domain: signed
 * here (and with which selector and d=), left unsigned here (and why), or
 * handed on to something that may sign it (a mail provider's API, an SMTP
 * server such as the box's own Postfix and rspamd, Joinery Direct).
 *
 * One row per distinct (domain, path, outcome, selector, d, reason, provider,
 * test mode), with a count and the first and last time. Written only by
 * EmailSender after a send succeeds, from the note the transport left
 * (noteSigned / noteUnsigned / notePath). Read by summaryForDomain(), which the
 * mailbox_status agent word reports: proof that a domain's mail is actually
 * signed, which an error count cannot give (a send path that never reaches the
 * signer logs nothing).
 *
 * Never an address, a subject or a body: the identifying columns are compiled
 * vocabulary, a domain, a DKIM selector and d= tag, and a provider key.
 *
 * Counting never costs a send: record() catches everything and error_logs.
 * Reserved example and test names (isReservedDomain()) are never counted.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailSendOutcomeException extends SystemBaseException {}

class MailSendOutcome extends SystemBase {
	public static $prefix = 'mso';
	public static $tablename = 'mso_mail_send_outcomes';
	public static $access_class = 'restricted';
	public static $pkey_column = 'mso_mail_send_outcome_id';

	/** Where the message left from. */
	const PATH_SMTP          = 'smtp';           // SmtpProvider (and providers built on it): SMTP submission
	const PATH_RELAY_COMPOSE = 'relay_compose';  // RawRelayComposeTransport: the app-built raw message through an API relay
	const PATH_API           = 'api';            // an HTTP-API provider (Mailgun, SES, Postmark ...) given the message
	const PATH_CONNECTED     = 'connected';      // ConnectedMailboxProvider: sent as an external account through its own server
	const PATH_DIRECT        = 'joinery_direct'; // Joinery Direct, instance to instance

	/** What happened to its signature. */
	const OUTCOME_SIGNED    = 'signed';     // a DKIM signature was added here
	const OUTCOME_UNSIGNED  = 'unsigned';   // this site meant to sign it here and could not
	const OUTCOME_HANDED_ON = 'handed_on';  // no signature added here; whatever took it may sign it. Not a pass

	/** Why a message this site meant to sign left unsigned. */
	const REASON_KEY_MISSING    = 'key_missing';
	const REASON_KEY_UNREADABLE = 'key_unreadable';
	const REASON_KEY_EMPTY      = 'key_empty';

	const PATHS    = array(self::PATH_SMTP, self::PATH_RELAY_COMPOSE, self::PATH_API, self::PATH_CONNECTED, self::PATH_DIRECT);
	const OUTCOMES = array(self::OUTCOME_SIGNED, self::OUTCOME_UNSIGNED, self::OUTCOME_HANDED_ON);
	const REASONS  = array('', self::REASON_KEY_MISSING, self::REASON_KEY_UNREADABLE, self::REASON_KEY_EMPTY);

	/** Rows summaryForDomain() returns at most. */
	const SUMMARY_CAP = 20;

	public static $field_specifications = array(
		'mso_mail_send_outcome_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		// Identifying columns: NOT NULL with '' for "none", so the unique key and
		// record()'s ON CONFLICT match (NULLs never conflict in PostgreSQL).
		'mso_domain'     => array('type' => 'varchar(253)', 'is_nullable' => false, 'default' => '',
			'unique_with' => array('mso_path', 'mso_outcome', 'mso_selector', 'mso_d', 'mso_reason', 'mso_provider', 'mso_test_mode')),
		'mso_path'       => array('type' => 'varchar(20)', 'is_nullable' => false, 'allowed_values' => self::PATHS),
		'mso_outcome'    => array('type' => 'varchar(20)', 'is_nullable' => false, 'allowed_values' => self::OUTCOMES),
		'mso_selector'   => array('type' => 'varchar(63)', 'is_nullable' => false, 'default' => ''),
		'mso_d'          => array('type' => 'varchar(253)', 'is_nullable' => false, 'default' => ''),
		'mso_reason'     => array('type' => 'varchar(30)', 'is_nullable' => false, 'default' => '', 'allowed_values' => self::REASONS),
		'mso_provider'   => array('type' => 'varchar(100)', 'is_nullable' => false, 'default' => ''),
		'mso_test_mode'  => array('type' => 'bool', 'is_nullable' => false, 'default' => 'false'),
		'mso_count'      => array('type' => 'int8', 'is_nullable' => false, 'default' => '0'),
		'mso_first_time' => array('type' => 'timestamp(6)', 'default' => 'now()'),
		'mso_last_time'  => array('type' => 'timestamp(6)', 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array();

	// ------------------------------------------------------------ the transport's note

	/** What the transport handling the current send said about its signature. */
	private static $note = null;

	/** Clear the note. EmailSender calls this before each transport send. */
	public static function resetNote(): void {
		self::$note = null;
	}

	/** The transport added a DKIM signature. */
	public static function noteSigned(string $path, string $selector, string $d, string $provider = ''): void {
		self::$note = array('path' => $path, 'outcome' => self::OUTCOME_SIGNED,
			'selector' => $selector, 'd' => $d, 'reason' => '', 'provider' => $provider);
	}

	/**
	 * The transport added no signature. When the signer said why it could not
	 * sign (noteUnsignedReason()), this is an unsigned send; otherwise the
	 * message was handed on to $provider, which may sign it.
	 */
	public static function notePath(string $path, string $provider): void {
		$reason = self::$pending_reason;
		self::$pending_reason = '';
		self::$note = array('path' => $path, 'outcome' => $reason !== '' ? self::OUTCOME_UNSIGNED : self::OUTCOME_HANDED_ON,
			'selector' => '', 'd' => '', 'reason' => $reason, 'provider' => $provider);
	}

	/** A reason the signer gave for not signing, waiting for the transport's notePath(). */
	private static $pending_reason = '';

	/** Called by a DKIM signer that meant to sign and could not (one of REASONS). */
	public static function noteUnsignedReason(string $reason): void {
		self::$pending_reason = in_array($reason, self::REASONS, true) ? $reason : '';
	}

	/** The current note, or null. For EmailSender and the tests. */
	public static function currentNote(): ?array {
		return self::$note;
	}

	// ------------------------------------------------------------ writing

	/**
	 * Count $messages successful sends from $domain under the current note, or,
	 * with no note, as handed on through $service over the API path. Never
	 * throws: a failure to count is logged and the send stands.
	 */
	public static function recordAfterSend(string $domain, string $service, int $messages = 1, bool $test_mode = false): void {
		$note = self::$note ?: array('path' => self::PATH_API, 'outcome' => self::OUTCOME_HANDED_ON,
			'selector' => '', 'd' => '', 'reason' => '', 'provider' => $service);
		self::$note = null;
		self::$pending_reason = '';
		self::record($domain, $note['path'], $note['outcome'], $note['selector'], $note['d'],
			$note['reason'], $note['provider'], $messages, $test_mode);
	}

	/**
	 * Add $messages to the row for these values, creating it. One statement, so
	 * web workers and cron counting at once never lose a count. Never throws.
	 */
	public static function record(string $domain, string $path, string $outcome, string $selector, string $d,
			string $reason, string $provider, int $messages = 1, bool $test_mode = false): void {
		try {
			$domain = mb_substr(strtolower(trim($domain)), 0, 253);
			if ($domain === '' || $messages < 1 || self::isReservedDomain($domain)
					|| !in_array($path, self::PATHS, true) || !in_array($outcome, self::OUTCOMES, true)) {
				return;
			}
			$params = array(
				':domain'   => $domain,
				':path'     => $path,
				':outcome'  => $outcome,
				':selector' => mb_substr(trim($selector), 0, 63),
				':d'        => mb_substr(strtolower(trim($d)), 0, 253),
				':reason'   => in_array($reason, self::REASONS, true) ? $reason : '',
				':provider' => mb_substr(trim($provider), 0, 100),
				':test'     => $test_mode ? 'true' : 'false',
				':count'    => $messages,
			);
			// A send can happen inside a page view (a receipt mailed by the page
			// that took the payment); counting it is the platform's bookkeeping.
			SystemBase::server_initiated_write(function () use ($params) {
				$sql = 'INSERT INTO mso_mail_send_outcomes
						(mso_domain, mso_path, mso_outcome, mso_selector, mso_d, mso_reason, mso_provider,
						 mso_test_mode, mso_count, mso_first_time, mso_last_time)
					VALUES (:domain, :path, :outcome, :selector, :d, :reason, :provider,
						 CAST(:test AS boolean), :count, now() at time zone \'utc\', now() at time zone \'utc\')
					ON CONFLICT (mso_domain, mso_path, mso_outcome, mso_selector, mso_d, mso_reason, mso_provider, mso_test_mode)
					DO UPDATE SET mso_count = mso_mail_send_outcomes.mso_count + EXCLUDED.mso_count,
						mso_last_time = EXCLUDED.mso_last_time';
				$q = DbConnector::get_instance()->get_db_link()->prepare($sql);
				$q->execute($params);
			});
		} catch (\Throwable $e) {
			error_log('[MailSendOutcome] could not count a send for ' . $domain . ': ' . $e->getMessage());
		}
	}

	/**
	 * A name reserved for examples and tests (RFC 2606, RFC 6761): example.com,
	 * .net and .org and their subdomains, and anything under .example, .test,
	 * .invalid or .localhost. No real mail is sent from one, and the tests that
	 * send through a stub transport use them, so none is counted: a test that
	 * runs on a production site leaves no row behind.
	 */
	public static function isReservedDomain(string $domain): bool {
		$domain = strtolower(rtrim(trim($domain), '.'));
		if (preg_match('/(^|\.)example\.(com|net|org)$/', $domain)) {
			return true;
		}
		return (bool)preg_match('/(^|\.)(example|test|invalid|localhost)$/', $domain);
	}

	// ------------------------------------------------------------ reading

	/**
	 * How $domain's mail has left, newest first, at most SUMMARY_CAP rows.
	 * Each row: path, outcome, selector, d, reason, provider, test_mode,
	 * count, first_time, last_time (ISO 8601 UTC).
	 *
	 * @return array<int, array{path:string, outcome:string, selector:string, d:string, reason:string,
	 *   provider:string, test_mode:bool, count:int, first_time:string, last_time:string}>
	 */
	public static function summaryForDomain(string $domain): array {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return array();
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT mso_path, mso_outcome, mso_selector, mso_d, mso_reason, mso_provider, mso_test_mode,
				mso_count, mso_first_time, mso_last_time
			FROM mso_mail_send_outcomes WHERE mso_domain = :domain
			ORDER BY mso_last_time DESC, mso_mail_send_outcome_id DESC LIMIT ' . (int)self::SUMMARY_CAP);
		$q->execute(array(':domain' => $domain));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$out[] = array(
				'path'       => (string)$r['mso_path'],
				'outcome'    => (string)$r['mso_outcome'],
				'selector'   => (string)$r['mso_selector'],
				'd'          => (string)$r['mso_d'],
				'reason'     => (string)$r['mso_reason'],
				'provider'   => (string)$r['mso_provider'],
				'test_mode'  => (bool)$r['mso_test_mode'],
				'count'      => (int)$r['mso_count'],
				'first_time' => self::iso((string)$r['mso_first_time']),
				'last_time'  => self::iso((string)$r['mso_last_time']),
			);
		}
		return $out;
	}

	/** A stored UTC timestamp as ISO 8601 with Z. */
	private static function iso(string $ts): string {
		if ($ts === '') {
			return '';
		}
		$t = strtotime($ts . ' UTC');
		return $t === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $t);
	}
}

class MultiMailSendOutcome extends SystemMultiBase {
	protected static $model_class = 'MailSendOutcome';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['domain'])) {
			$filters['mso_domain'] = array(strtolower(trim((string)$this->options['domain'])), PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('mso_mail_send_outcomes', $filters, $this->order_by, $only_count, $debug);
	}
}
