<?php
/**
 * MailReadBudget — how much mail content one person may be handed in an hour
 * (specs/mail_access_hardening.md WP2).
 *
 * The case this exists for is a stolen session, or a bug, reading a person's
 * own Standard mailbox, which no encryption protects: a human opening threads
 * by hand reads tens an hour, a thief reads everything. So every surface that
 * hands a viewer message content notes it here first, for every viewer,
 * foreign or not, and the ledger (MailReadLedger) counts distinct messages per
 * person, per credential kind, in a sliding window of one to two hours. Sealed
 * mail counts too: the budget does not know or care whether a row was opened
 * in the unseal daemon or read in the clear. The daemon deliberately refuses
 * no unwrap (the search fold needs them); this count is taken at the viewer
 * surfaces the fold never crosses, so it sees only reads a person asked for.
 *
 * Three credential kinds, metered apart: browser (the website session), device
 * (a linked phone or desktop on a session key), app (a mail program on an app
 * password). Two units: messages (one message once an hour, however often it is
 * opened) and pages (one per thread-list page, since a page of previews carries
 * the subject, the sender and the first line of every message on it).
 *
 * Thresholds, from the mailbox plugin's settings (0 turns one off):
 *   mailbox_read_budget_soft        300  messages/hour: one mail_read_budget_soft event row
 *                                        per window and a notification to the account
 *   mailbox_read_budget_hard      1,000  messages/hour on the browser: content reads answer
 *                                        requires_stepup until a fresh second factor
 *   mailbox_read_budget_app_hard 20,000  messages/hour on a device or app credential: content
 *                                        reads answer 429 with Retry-After, the transport
 *                                        limit's contract, so the program backs off
 *   mailbox_read_budget_pages_hard  500  thread-list pages/hour on the browser: the same pause
 *
 * A fresh second factor (SessionControl::stamp_second_factor(), a PasskeyCeremony
 * `stepup` marker keyed by the browser session, alive one hour) is a reset: the
 * ledger rows stay and the count restarts from the stamp. The marker lives one
 * hour, which is why the window is an hour and not longer. An account with no
 * second factor cannot step up, so for it the hard threshold pauses for the
 * remainder of the window, as a program's does, and the message says so. A
 * paused read writes no ledger row and one mail_read_budget_hard event row per
 * window.
 *
 * Exempt, by never calling here: unattended work with no viewer (the cron
 * export build, the deferred-work consumers, the DEK prefetch, the re-key, the
 * rebuild, the AI pipeline jobs). A download is counted once, as its message
 * count, when it is started.
 *
 * @version 1.2 - credentialKind() asks ApiAuth::requestCredentialClass(): only ApiAuth names the credential kinds
 * @version 1.1 - reviewer2: a download is judged on the count before it (B1); a pause within the ceremony's bounce
 *                window waits instead of asking again (B2); the notice counts messages alone (B4);
 *                stepUpRedirect() for page surfaces (B7)
 * @version 1.0
 */

final class MailReadBudget {

	const WINDOW_SECONDS = 3600;

	const KIND_MESSAGE = MailReadLedger::KIND_MESSAGE;
	const KIND_PAGE    = MailReadLedger::KIND_PAGE;

	const CREDENTIAL_BROWSER = MailReadLedger::CREDENTIAL_BROWSER;
	const CREDENTIAL_DEVICE  = MailReadLedger::CREDENTIAL_DEVICE;
	const CREDENTIAL_APP     = MailReadLedger::CREDENTIAL_APP;

	const EVENT_SOFT = 'mail_read_budget_soft';
	const EVENT_HARD = 'mail_read_budget_hard';
	/** The signal the soft threshold raises; plugin.json § signals notifies the account. */
	const SIGNAL_SOFT = 'mail_read_budget.soft';

	const DEFAULT_SOFT       = 300;
	const DEFAULT_HARD       = 1000;
	const DEFAULT_APP_HARD   = 20000;
	const DEFAULT_PAGES_HARD = 500;

	// Why a read was paused.
	const PAUSE_STEPUP = 'stepup';   // browser, account has a second factor: confirm and continue
	const PAUSE_WAIT   = 'wait';     // no way to step up here: wait for the window to pass

	/**
	 * Tests only: thresholds in place of the settings, keyed soft, hard,
	 * app_hard, pages_hard. There is no production path to it.
	 * @var array<string,int>|null
	 */
	public static $limit_overrides = null;

	/**
	 * The credential kind this request is on, from what ApiAuth stamped the
	 * request logger with: 'browser' (the website session, also a CLI with no
	 * request), a linked device's session key, or a program's app or machine key.
	 */
	public static function credentialKind(): string {
		// ApiAuth names the kinds; this only meters by their class.
		switch (ApiAuth::requestCredentialClass()) {
			case 'device': return self::CREDENTIAL_DEVICE;
			case 'app':    return self::CREDENTIAL_APP;
			default:       return self::CREDENTIAL_BROWSER;
		}
	}

	/**
	 * $user_id is about to be handed these messages' content. Counted once an
	 * hour each; the state says whether the read may go ahead.
	 *
	 * @return array{paused:bool, reason:?string, retry_after:int, count:int, limit:int, kind:string, credential:string}
	 */
	public static function messages(int $user_id, array $message_ids, ?string $credential = null, ?string $session_id = null): array {
		$ids = array();
		foreach ($message_ids as $id) {
			$id = intval($id);
			if ($id > 0) {
				$ids[$id] = true;
			}
		}
		return self::note($user_id, self::KIND_MESSAGE, array_keys($ids), 0, $credential, $session_id);
	}

	/** $user_id is about to be handed one page of thread previews. */
	public static function page(int $user_id, ?string $credential = null, ?string $session_id = null): array {
		return self::note($user_id, self::KIND_PAGE, array(), 1, $credential, $session_id);
	}

	/** $user_id has just been handed a whole mailbox of $count messages at once (a download started). */
	public static function mailbox(int $user_id, int $count, ?string $credential = null, ?string $session_id = null): array {
		return self::note($user_id, self::KIND_MESSAGE, array(), max(0, $count), $credential, $session_id);
	}

	/**
	 * The refusal a paused read answers with. On the browser credential with a
	 * second factor: requires_stepup, which the reader already knows how to
	 * follow. Without one: a wait, said plainly, with the way to a second
	 * factor. On a device or app credential: 429 with Retry-After, the
	 * transport limit's contract, so a program backs off and nothing is served
	 * in a message's place.
	 */
	public static function refusal(array $state): LogicResult {
		$retry = max(60, intval($state['retry_after'] ?? 0));
		if ($state['credential'] === self::CREDENTIAL_BROWSER) {
			if ($state['reason'] === self::PAUSE_STEPUP) {
				return LogicResult::error('You have opened an unusual amount of mail. Confirm it is you to continue.',
					array('requires_stepup' => true, 'read_budget_paused' => true));
			}
			if (!empty($state['just_confirmed'])) {
				return LogicResult::error('You confirmed it is you a moment ago, and this session has opened an unusual amount of '
					. 'mail since. Reading is paused for about ' . self::minutes($retry) . '.',
					array('read_budget_paused' => true, 'retry_after_seconds' => $retry));
			}
			return LogicResult::error('You have opened an unusual amount of mail, so reading is paused for about '
				. self::minutes($retry) . '. An account with a second factor can confirm it is you and carry on at once: '
				. 'add one under Profile > Security.',
				array('read_budget_paused' => true, 'retry_after_seconds' => $retry, 'link' => '/profile/security'));
		}
		return LogicResult::error('This connection has opened an unusual amount of mail; the limit is '
			. number_format(intval($state['limit'])) . ' messages an hour. Nothing is wrong — try again in '
			. self::minutes($retry) . '.',
			array('http_status' => 429, 'retry_after_seconds' => $retry, 'read_budget_paused' => true,
				'limit' => intval($state['limit']), 'count' => intval($state['count']), 'imap_code' => 'LIMIT'));
	}

	// ---------------------------------------------------------------------

	private static function note(int $user_id, string $kind, array $ids, int $weight, ?string $credential, ?string $session_id): array {
		$credential = $credential ?? self::credentialKind();
		$state = array('paused' => false, 'reason' => null, 'retry_after' => 0, 'count' => 0, 'limit' => 0,
			'kind' => $kind, 'credential' => $credential);
		if ($user_id <= 0 || (!$ids && $weight <= 0)) {
			return $state;
		}
		try {
			$limits = self::limits();
			// On a device or app credential every unit counts against the one
			// app budget; on the browser, messages and pages have their own.
			$browser = $credential === self::CREDENTIAL_BROWSER;
			$hard = $browser ? ($kind === self::KIND_PAGE ? $limits['pages_hard'] : $limits['hard']) : $limits['app_hard'];
			$kinds = $browser ? array($kind) : array(self::KIND_MESSAGE, self::KIND_PAGE);
			$state['limit'] = $hard;

			$now = time();
			$bucket = gmdate('Y-m-d H:00:00', $now);
			$window_start = gmdate('Y-m-d H:00:00', $now - self::WINDOW_SECONDS);
			// A fresh second factor restarts the count from its stamp.
			$since = $browser ? self::stampTime($session_id ?? (string)session_id(), $now) : null;

			$db = DbConnector::get_instance()->get_db_link();
			$before = self::count($db, $user_id, $credential, $kinds, $window_start, $since);
			if ($hard > 0 && $before['count'] >= $hard) {
				return self::pause($state, $user_id, $credential, $before, $now, $since);
			}

			SystemBase::server_initiated_write(function () use ($db, $user_id, $credential, $kind, $bucket, $ids, $weight) {
				$ins = $db->prepare('INSERT INTO mrb_mail_read_ledger (mrb_usr_user_id, mrb_credential_kind, mrb_kind, mrb_bucket_time,
						mrb_iem_inbound_email_message_id, mrb_count) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING');
				if ($ids) {
					foreach ($ids as $id) {
						$ins->execute(array($user_id, $credential, $kind, $bucket, $id, 1));
					}
				} else {
					$ins->execute(array($user_id, $credential, $kind, $bucket, null, $weight));
				}
			});

			$after = self::count($db, $user_id, $credential, $kinds, $window_start, $since);
			$state['count'] = $after['count'];
			if ($kind === self::KIND_MESSAGE && $limits['soft'] > 0) {
				// The notice counts messages alone: on a program's credential the
				// window total also holds its header fetches (pages), which are
				// not messages opened (reviewer2 B4).
				$soft_count = $browser ? $after['count']
					: self::count($db, $user_id, $credential, array(self::KIND_MESSAGE), $window_start, $since)['count'];
				if ($soft_count > $limits['soft']) {
					self::soft($db, $user_id, $credential, $soft_count, $limits['soft'], $window_start);
				}
			}
			// A whole mailbox handed over at once (a download) is judged on the
			// count before it alone: it was allowed through its own step-up gate,
			// and refusing it for its own size would refuse every download larger
			// than the line, every time (reviewer2 B1). What it adds to the count
			// pauses the reads that follow, which is the point of counting it.
			if ($ids && $hard > 0 && $after['count'] > $hard) {
				// One batch carried the count past the line (a program's fetch of
				// fifty): this read is the paused one.
				return self::pause($state, $user_id, $credential, $after, $now, $since);
			}
		} catch (\Throwable $e) {
			// The budget must never take a read down with it; a read that could
			// not be counted is served and the failure is in the log.
			error_log('MailReadBudget: could not count a read for user ' . $user_id . ' — ' . $e->getMessage());
		}
		return $state;
	}

	/** The window's total and its oldest bucket for a person on a credential kind. */
	private static function count(PDO $db, int $user_id, string $credential, array $kinds, string $window_start, ?string $since): array {
		$in = implode(',', array_fill(0, count($kinds), '?'));
		$sql = "SELECT COALESCE(SUM(mrb_count), 0) AS total, MIN(mrb_bucket_time) AS oldest FROM mrb_mail_read_ledger
				WHERE mrb_usr_user_id = ? AND mrb_credential_kind = ? AND mrb_kind IN ($in) AND mrb_bucket_time >= ?";
		$params = array_merge(array($user_id, $credential), $kinds, array($window_start));
		if ($since !== null) {
			$sql .= ' AND mrb_create_time > ?';
			$params[] = $since;
		}
		$q = $db->prepare($sql);
		$q->execute($params);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return array('count' => intval($row['total'] ?? 0), 'oldest' => $row['oldest'] ?? null);
	}

	/**
	 * The newest step-up stamp on this browser session younger than the
	 * window, as a UTC timestamp string, or null. The marker is the one
	 * SessionControl::stamp_second_factor() and PasskeyService::verifyStepUp()
	 * write; it lives one hour, the window's length.
	 */
	private static function stampTime(string $session_id, int $now): ?string {
		if ($session_id === '') {
			return null;
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT MAX(pks_create_time) FROM pks_passkey_ceremonies WHERE pks_session_id = ? AND pks_kind = 'stepup'
			 AND pks_create_time > ?");
		$q->execute(array($session_id, gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS)));
		$t = $q->fetchColumn();
		return ($t === false || $t === null) ? null : (string)$t;
	}

	/**
	 * The step-up ceremony answers at once, without asking, for a session that
	 * confirmed within this many seconds (verify_stepup_logic.php,
	 * has_recent_second_factor()'s default). A pause that asked for a step-up
	 * inside that time would bounce the page there and back without end.
	 */
	const STEPUP_BOUNCE_SECONDS = 300;

	private static function pause(array $state, int $user_id, string $credential, array $counted, int $now, ?string $stamp): array {
		$state['paused'] = true;
		$state['count'] = $counted['count'];
		// The oldest counted bucket leaves the window two hours after it opened.
		$oldest = $counted['oldest'] ? strtotime($counted['oldest'] . ' UTC') : $now;
		$state['retry_after'] = max(60, ($oldest ?: $now) + 2 * self::WINDOW_SECONDS - $now);
		$state['reason'] = ($credential === self::CREDENTIAL_BROWSER && self::canStepUp($user_id))
			? self::PAUSE_STEPUP : self::PAUSE_WAIT;
		// The count is already the count since the newest stamp, so a stamp
		// younger than the ceremony's bounce window means this session confirmed
		// a moment ago and then read past the line again. Asking for another
		// step-up would bounce (reviewer2 B2): it waits instead, until the
		// ceremony would ask again.
		if ($state['reason'] === self::PAUSE_STEPUP && $stamp !== null) {
			$age = $now - (strtotime($stamp . ' UTC') ?: $now);
			if ($age < self::STEPUP_BOUNCE_SECONDS) {
				$state['reason'] = self::PAUSE_WAIT;
				$state['just_confirmed'] = true;
				$state['retry_after'] = max(60, self::STEPUP_BOUNCE_SECONDS - $age);
			}
		}
		self::event(self::EVENT_HARD, $user_id, $credential, $state['kind'], $counted['count'], $state['limit'],
			gmdate('Y-m-d H:00:00', $now - self::WINDOW_SECONDS));
		return $state;
	}

	/**
	 * For a surface that is a page, not an API answer (a download link, the
	 * admin pages): the redirect to the step-up ceremony and back to $return
	 * when the pause asks for one, else null and the caller renders the
	 * refusal's words (reviewer2 B7).
	 */
	public static function stepUpRedirect(array $state, string $return): ?LogicResult {
		if (($state['reason'] ?? null) !== self::PAUSE_STEPUP) {
			return null;
		}
		return LogicResult::redirect('/verify-stepup?return=' . urlencode($return));
	}

	/** Can this account answer a step-up at all? One with no second factor cannot (SessionControl::step_up_outstanding()). */
	private static function canStepUp(int $user_id): bool {
		try {
			$user = new User($user_id, TRUE);
			return $user->key && SessionControl::get_instance()->user_has_second_factor($user);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/** Past the soft line: one event row and one notification per window. */
	private static function soft(PDO $db, int $user_id, string $credential, int $count, int $limit, string $window_start): void {
		if (!self::event(self::EVENT_SOFT, $user_id, $credential, self::KIND_MESSAGE, $count, $limit, $window_start)) {
			return;
		}
		SignalBus::dispatch(self::SIGNAL_SOFT, array(
			'count'      => $count,
			'limit'      => $limit,
			'credential' => $credential,
			'recipients' => array($user_id),
		));
	}

	/**
	 * One event-log row per person per window for $event; true when this call
	 * wrote it. Metadata only: the kind, the credential, the count, the line.
	 */
	private static function event(string $event, int $user_id, string $credential, string $kind, int $count, int $limit, string $window_start): bool {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT 1 FROM evl_event_logs WHERE evl_event = ? AND evl_create_time >= ? AND evl_usr_user_id = ? LIMIT 1');
			$q->execute(array($event, $window_start, $user_id));
			if ($q->fetchColumn()) {
				return false;
			}
			SystemBase::server_initiated_write(function () use ($event, $user_id, $credential, $kind, $count, $limit) {
				$log = new EventLog(NULL);
				$log->set('evl_event',       $event);
				$log->set('evl_usr_user_id', $user_id);
				$log->set('evl_was_success', true);
				$log->set('evl_note',        'credential=' . $credential . ' kind=' . $kind . ' count=' . $count . ' limit=' . $limit);
				$log->save();
			});
			return true;
		} catch (\Throwable $e) {
			error_log($event . ': could not write the read budget row for user ' . $user_id . ' — ' . $e->getMessage());
			return false;
		}
	}

	/** @return array{soft:int, hard:int, app_hard:int, pages_hard:int} */
	private static function limits(): array {
		if (is_array(self::$limit_overrides)) {
			return array_merge(array('soft' => self::DEFAULT_SOFT, 'hard' => self::DEFAULT_HARD,
				'app_hard' => self::DEFAULT_APP_HARD, 'pages_hard' => self::DEFAULT_PAGES_HARD), self::$limit_overrides);
		}
		$settings = Globalvars::get_instance();
		$read = function (string $name, int $default) use ($settings): int {
			$v = $settings->get_setting($name);
			return ($v === null || $v === '') ? $default : max(0, intval($v));
		};
		return array(
			'soft'       => $read('mailbox_read_budget_soft', self::DEFAULT_SOFT),
			'hard'       => $read('mailbox_read_budget_hard', self::DEFAULT_HARD),
			'app_hard'   => $read('mailbox_read_budget_app_hard', self::DEFAULT_APP_HARD),
			'pages_hard' => $read('mailbox_read_budget_pages_hard', self::DEFAULT_PAGES_HARD),
		);
	}

	private static function minutes(int $seconds): string {
		$m = max(1, (int)ceil($seconds / 60));
		return $m === 1 ? 'a minute' : $m . ' minutes';
	}
}
?>
