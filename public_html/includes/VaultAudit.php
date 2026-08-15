<?php
/**
 * VaultAudit — the record of when a Sealed Vault unlock window opened and
 * closed (docs/sealed_vault.md § The audit log).
 *
 * The window itself lives in APCu and a /dev/shm marker, both of which vanish
 * without trace. Without this, "was my vault open at 3am?" is answerable only
 * by inference — correlating request-log traffic against unlock endpoints and
 * guessing at the idle arithmetic — and only for as long as request logging
 * happens to be switched on.
 *
 * Rows land in the platform's general event log (evl_event_logs), which is
 * where the rest of the platform's audit trail already lives, rather than in a
 * table of its own. Two events: `vault_window_opened` and
 * `vault_window_closed`.
 *
 * One row per state TRANSITION, never per heartbeat. A beacon beats every 25
 * seconds for as long as a tab is open; logging those would bury the two facts
 * that matter under thousands that do not.
 *
 * WHAT IS RECORDED IS METADATA ONLY: who, which scope, how it was armed, why
 * it ended, and how long it was open. The session id is a bearer credential and
 * is NEVER written — `handle()` reduces it to a truncated one-way digest, which
 * is enough to tie an open to its close and useless to anyone reading the log.
 * No secret key, no wrapping, and no sealed content passes through here.
 *
 * @version 1.0
 */

class VaultAudit {

	const EVENT_OPENED = 'vault_window_opened';
	const EVENT_CLOSED = 'vault_window_closed';

	// How a window was armed.
	const VIA_PASSKEY    = 'passkey';
	const VIA_PASSPHRASE = 'passphrase';
	const VIA_RECOVERY   = 'recovery';
	const VIA_SETUP      = 'setup';
	const VIA_ROTATE     = 'rotate';
	const VIA_REENROLL   = 'reenroll';
	const VIA_UNKNOWN    = 'unknown';

	// Why a window ended. The first four are policy end-events the platform
	// decided on; the last three are things that happened to the session.
	const REASON_IDLE_CAP         = 'idle_cap';          // Fortress: no content decrypt for too long
	const REASON_ABSOLUTE_CAP     = 'absolute_cap';      // armed too long ago, regardless of use
	const REASON_HEARTBEAT_STALE  = 'heartbeat_stale';   // the browser stopped answering
	const REASON_IDLE_EXPIRED     = 'idle_expired';      // the key aged out of APCu (vault_unlock_idle_minutes)
	const REASON_EXPLICIT_LOCK    = 'explicit_lock';     // someone pressed Lock now
	const REASON_LOGOUT           = 'logout';
	const REASON_IP_CHANGE        = 'ip_change';
	const REASON_CREDENTIAL_EVENT = 'credential_event';  // password change/reset, recovery-code use, 2FA change

	/**
	 * A window was armed. $caps is the resolved end-event policy, recorded
	 * because it is what decides how long this window can live — the single
	 * most useful thing to know when a later read looks too late to be
	 * legitimate.
	 */
	public static function opened(int $user_id, string $scope, string $via,
			?array $caps, ?string $session_id): void {
		$note = 'scope=' . $scope
			. ' via=' . $via
			. ' session=' . self::handle($session_id)
			. ' idle_cap=' . self::capText($caps['idle'] ?? null)
			. ' absolute_cap=' . self::capText($caps['absolute'] ?? null)
			. ' idle_setting=' . self::idleSettingText();
		self::write(self::EVENT_OPENED, $user_id, $note);
	}

	/**
	 * A window ended. $open_seconds is how long it had been armed, where that
	 * is knowable — an expiry noticed after the fact has no metadata left to
	 * measure from, and says so rather than guessing.
	 */
	public static function closed(int $user_id, string $scope, string $reason,
			?string $session_id, ?int $open_seconds = null): void {
		$note = 'scope=' . $scope
			. ' reason=' . $reason
			. ' session=' . self::handle($session_id)
			. ' open_seconds=' . ($open_seconds === null ? 'unknown' : (string)max(0, $open_seconds));
		self::write(self::EVENT_CLOSED, $user_id, $note);
	}

	/**
	 * A one-way handle for a session id: enough to match an open against its
	 * close, not enough to be the session id. Truncation is deliberate — a full
	 * digest of a live session id is still a lookup key for anyone holding the
	 * id, and 12 hex characters distinguish a user's handful of concurrent
	 * sessions without being one.
	 */
	public static function handle(?string $session_id): string {
		if ($session_id === null || $session_id === '') {
			return 'none';
		}
		return substr(hash('sha256', $session_id), 0, 12);
	}

	private static function capText(?int $seconds): string {
		return $seconds === null ? 'none' : $seconds . 's';
	}

	/** The configured idle window, for context on why a close landed when it did. */
	private static function idleSettingText(): string {
		try {
			$settings = Globalvars::get_instance();
			$minutes = (int)($settings->get_setting('vault_unlock_idle_minutes')
				?: VaultUnlock::DEFAULT_IDLE_MINUTES);
			return max(1, $minutes) . 'm';
		} catch (\Throwable $e) {
			return 'unknown';
		}
	}

	/**
	 * Wrapped in server_initiated_write(): a window closing is an observation
	 * the server makes on whatever request happened to notice it, which is
	 * often a GET page view. Losing the row must never break the request that
	 * noticed — an audit trail that can take a feature down with it stops being
	 * something anyone is willing to leave switched on.
	 */
	private static function write(string $event, int $user_id, string $note): void {
		try {
			SystemBase::server_initiated_write(function () use ($event, $user_id, $note) {
				$log = new EventLog(NULL);
				$log->set('evl_event',       $event);
				$log->set('evl_usr_user_id', $user_id);
				$log->set('evl_was_success', true);
				$log->set('evl_note',        $note);
				$log->save();
			});
		} catch (\Throwable $e) {
			error_log($event . ': could not write the vault audit row for user '
				. $user_id . ' — ' . $e->getMessage());
		}
	}
}
?>
