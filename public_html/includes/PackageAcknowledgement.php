<?php
/**
 * PackageAcknowledgement — the owner's "yes" to installing an unsigned
 * package, in a form root can check (specs/package_signing.md, D1 Option 1).
 *
 * An unsigned package reaches the tree only after a superadmin has read the
 * warning and said install anyway. The page says so by submitting an
 * `install_package` root request carrying an acknowledgement; root reads the
 * acknowledgement back before it moves a byte. The two halves live here so
 * that what the page mints and what root checks are one definition — and so
 * that a later design (Option 2, a root-pinned owner key verifying a WebAuthn
 * assertion) swaps the inside of these two functions without touching the
 * page or the runner.
 *
 * WHAT THE "YES" IS, today: the second-factor step-up marker. The warning
 * page's button runs through require_recent_second_factor(), the same gate a
 * domain security-level change uses (docs/account_security.md § Step-up
 * confirmation); the marker it leaves is session-bound and needs the second
 * factor at the keyboard, so a stolen session cookie cannot produce one. The
 * acknowledgement names that session by a one-way hash, and root looks for a
 * fresh marker under it.
 *
 * WHAT IT IS NOT: proof against an attacker already running code in the web
 * tier. The marker is a database row, which the pool writes. That attacker is
 * residual 6 of the accepted threat model, and the unsigned restrictions in
 * utils/install_extension.php (migrations as the web user, every superadmin
 * emailed, the event log) bound what they get.
 *
 * An account with no second factor cannot acknowledge. The step-up gate is a
 * no-op for such an account (there is nothing to step up with), so without
 * this refusal the "yes" would be the session cookie alone — exactly what the
 * gate exists to be more than. Same rule as resetting another admin's factors
 * (adm/logic/admin_user_logic.php).
 *
 * @version 1.1 - the acknowledgement names its marker row, so root reads one
 *                row rather than scanning every step-up (review round 1, R4)
 * @version 1.0
 */
class PackageAcknowledgement {

	/** The marker must be at most this old when the page mints the acknowledgement — the step-up gate's own window. */
	const STEP_UP_TTL = 300;

	/**
	 * An acknowledgement older than this when root reads it is stale; the
	 * operator answers the warning again. Under the marker's own life
	 * (PasskeyService::STEPUP_MARKER_TTL_SECONDS, an hour from its creation,
	 * after which a sweep removes the row) by the step-up window, so the row
	 * an acknowledgement names is still there whenever the acknowledgement
	 * is still acceptable.
	 */
	const MAX_AGE = 3000;

	/**
	 * The page side. Returns the acknowledgement to put in the request's
	 * `unsigned_ack`, or throws with the sentence to show the operator.
	 *
	 * The caller has already sent the operator through
	 * require_recent_second_factor(); this re-checks rather than trusting
	 * that, because the two are the same question asked at the same moment
	 * and a page that forgot the gate must not be able to mint one.
	 */
	public static function mint(SessionControl $session): array {
		$uid = (int)$session->get_user_id();
		$user = $uid > 0 ? new User($uid, TRUE) : null;
		if (!$user || !$user->key || (int)$user->get('usr_permission') < 10) {
			throw new Exception('Only a superadmin can acknowledge an unsigned package.');
		}
		if (!$session->user_has_second_factor($user)) {
			throw new Exception('Enroll a second factor on your own account before installing an unsigned package. '
				. 'The acknowledgement root checks is your second-factor confirmation, and an account without '
				. 'one cannot give it.');
		}
		$marker = self::freshMarker((string)session_id(), self::STEP_UP_TTL);
		if ($marker === null) {
			throw new Exception('A recent second-factor confirmation is needed to install an unsigned package.');
		}
		return array(
			'marker'  => (int)$marker->key,
			'session' => hash('sha256', (string)session_id()),
			'user_id' => $uid,
			'ip'      => (string)SessionControl::get_client_ip(),
			'at'      => time(),
		);
	}

	/**
	 * The newest `stepup` marker for a session, when one is at most $ttl old.
	 * The same question has_recent_second_factor() asks; this returns the
	 * row so the acknowledgement can name it.
	 */
	private static function freshMarker(string $session_id, int $ttl): ?PasskeyCeremony {
		if ($session_id === '') {
			return null;
		}
		$cutoff = time() - $ttl;
		$newest = null;
		$newest_at = 0;
		foreach (new MultiPasskeyCeremony(array('session_id' => $session_id, 'kind' => 'stepup')) as $m) {
			$t = strtotime($m->get('pks_created_time') . ' UTC');
			if ($t && $t >= $cutoff && $t > $newest_at) {
				$newest = $m;
				$newest_at = $t;
			}
		}
		return $newest;
	}

	/**
	 * The root side. '' when the acknowledgement stands; otherwise the reason
	 * it does not, for the transcript.
	 *
	 * Root has no session of its own, so it reads the marker table directly:
	 * the one `stepup` marker row the acknowledgement names, whose session
	 * must hash to what the acknowledgement says, created inside the step-up
	 * window before the acknowledgement was minted. The requester has to be
	 * a live superadmin, and the acknowledgement has to be recent — a request
	 * that waited an hour in a stalled queue is answered again, not honoured
	 * late.
	 *
	 * @param array $ack          The request's `unsigned_ack`
	 * @param int   $requested_by The request's `requested_by`
	 * @param int   $now          Injected for tests
	 */
	public static function check(array $ack, int $requested_by, ?int $now = null): string {
		$now = $now ?? time();
		$session_hash = (string)($ack['session'] ?? '');
		$user_id = (int)($ack['user_id'] ?? 0);
		$at = (int)($ack['at'] ?? 0);
		$marker_id = (int)($ack['marker'] ?? 0);

		if (!preg_match('/^[0-9a-f]{64}$/', $session_hash) || $user_id <= 0 || $at <= 0 || $marker_id <= 0) {
			return 'the acknowledgement is not in the form the warning page writes';
		}
		if ($user_id !== $requested_by) {
			return 'the acknowledgement names a different user than the request';
		}
		if ($at > $now + 60) {
			return 'the acknowledgement is dated in the future';
		}
		if ($now - $at > self::MAX_AGE) {
			return 'the acknowledgement is over fifty minutes old; answer the warning again';
		}

		$user = new User($user_id, TRUE);
		if (!$user->key || (int)$user->get('usr_permission') < 10 || $user->get('usr_delete_time')
			|| $user->get('usr_is_disabled') || $user->get('usr_is_admin_disabled')) {
			return 'the acknowledging user is not a live superadmin';
		}

		// The marker: the one row named, session-bound, kind `stepup`, created
		// in the step-up window that ended when the acknowledgement was minted.
		$m = new PasskeyCeremony($marker_id, TRUE);
		if (!$m->key || (string)$m->get('pks_kind') !== 'stepup') {
			return 'the acknowledgement names no second-factor confirmation';
		}
		if (!hash_equals($session_hash, hash('sha256', (string)$m->get('pks_session_id')))) {
			return 'the second-factor confirmation named belongs to another session';
		}
		$created = strtotime($m->get('pks_created_time') . ' UTC');
		if (!$created || $created > $at + 60 || $created < $at - self::STEP_UP_TTL) {
			return 'the second-factor confirmation named was not fresh when the warning was answered';
		}
		return '';
	}

	/** The warning, in the owner's words. One place, so the page, the email and the transcript agree. */
	public static function warning(): string {
		return 'Installing an unsigned package is extremely dangerous. It was not built by Joinery and nobody has '
			. 'checked what it does. Once installed it has access to everything on this site, including all mail, '
			. 'every user\'s data and every setting, and it can change any of them.';
	}
}
