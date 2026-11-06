<?php
/**
 * VaultAudit — the record of when a Sealed Vault unlock window opened and
 * closed (docs/sealed_vault.md § The audit log).
 *
 * The window itself lives in the RAM store (RamStore), which vanishes
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
 * @version 1.6 - REASON_REPLACED: a window a later arm of the same slot took the place of is closed in the log
 * @version 1.5 - opened() records where the key is held (holder=daemon|pool, fallback=pool_rung); parseNote()
 *   reads every note this class writes, for the plane's vault window view (agent_event_log_filter.md)
 * @version 1.4 - REASON_DAEMON_RESTART and REASON_SENTINEL_DROP; closed() records the daemon window's unwrap count (unseal_daemon.md WP4)
 * @version 1.3 - comments: the window lives in RamStore, not APCu (strategy/object_free_window_store.md WP4)
 * @version 1.2 - VIA_APP_BORROW and REASON_APP_REVOKED for an app credential's borrowed
 *   window; handle() names a borrowed slot's credential instead of hashing it.
 * @version 1.1 - rows are written as a declared content-free site, so a window
 *               closing on a request that opened sealed content is still recorded
 * @version 1.0.1 - comment wording: the hardened idle cap
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
	// An app credential's borrowed window, armed by the person's website unlock
	// (VaultUnlock::armBorrowers()). The session field names the credential.
	const VIA_APP_BORROW = 'app_borrow';

	// Where an opened window's key is held (unseal_daemon.md): in the unseal
	// daemon, or in the PHP pool. A pool window the daemon's five-minute
	// silence sent there also says why.
	const HOLDER_DAEMON      = 'daemon';
	const HOLDER_POOL        = 'pool';
	const FALLBACK_POOL_RUNG = 'pool_rung';

	// Why a window ended. The first four are policy end-events the platform
	// decided on; the last three are things that happened to the session.
	const REASON_IDLE_CAP         = 'idle_cap';          // hardened caps: no content decrypt for too long
	const REASON_ABSOLUTE_CAP     = 'absolute_cap';      // armed too long ago, regardless of use
	const REASON_HEARTBEAT_STALE  = 'heartbeat_stale';   // the browser stopped answering
	const REASON_IDLE_EXPIRED     = 'idle_expired';      // the key aged out of the window store (vault_unlock_idle_minutes)
	const REASON_EXPLICIT_LOCK    = 'explicit_lock';     // someone pressed Lock now
	const REASON_LOGOUT           = 'logout';
	const REASON_IP_CHANGE        = 'ip_change';
	const REASON_CREDENTIAL_EVENT = 'credential_event';  // password change/reset, recovery-code use, 2FA change
	const REASON_APP_REVOKED      = 'app_credential_revoked'; // the app credential borrowing the window was revoked
	const REASON_DAEMON_RESTART   = 'daemon_restart';    // the unseal daemon restarted (or never answered for it) and the window went with it
	const REASON_SENTINEL_DROP    = 'sentinel_drop';     // root's drop and hold ended every window in the unseal daemon
	const REASON_REPLACED         = 'replaced';          // a new unlock or ceremony in the same session took the window's place

	/**
	 * A window was armed. $caps is the resolved end-event policy, recorded
	 * because it is what decides how long this window can live — the single
	 * most useful thing to know when a later read looks too late to be
	 * legitimate.
	 */
	public static function opened(int $user_id, string $scope, string $via,
			?array $caps, ?string $session_id, ?string $holder = null, ?string $fallback = null): void {
		$note = 'scope=' . $scope
			. ' via=' . $via
			. ' session=' . self::handle($session_id)
			. ' idle_cap=' . self::capText($caps['idle'] ?? null)
			. ' absolute_cap=' . self::capText($caps['absolute'] ?? null)
			. ' idle_setting=' . self::idleSettingText()
			. ($holder === null ? '' : ' holder=' . $holder)
			. ($fallback === null ? '' : ' fallback=' . $fallback);
		self::write(self::EVENT_OPENED, $user_id, $note);
	}

	/**
	 * A window ended. $open_seconds is how long it had been armed, where that
	 * is knowable — an expiry noticed after the fact has no metadata left to
	 * measure from, and says so rather than guessing.
	 */
	public static function closed(int $user_id, string $scope, string $reason,
			?string $session_id, ?int $open_seconds = null, ?int $unwraps = null): void {
		$note = 'scope=' . $scope
			. ' reason=' . $reason
			. ' session=' . self::handle($session_id)
			. ' open_seconds=' . ($open_seconds === null ? 'unknown' : (string)max(0, $open_seconds))
			. ($unwraps === null ? '' : ' unwraps=' . $unwraps);
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
		// A borrowed window's "session" is the app credential's slot name
		// (~apk{key id}): not a secret, and the one thing worth reading here.
		if (strpos($session_id, VaultUnlock::CREDENTIAL_SLOT_PREFIX) === 0) {
			return substr($session_id, 1);
		}
		return substr(hash('sha256', $session_id), 0, 12);
	}

	/**
	 * The fields of a note this class wrote, for a reader that has only the
	 * row (the plane's vault window view, over log_table_tail). Every value is
	 * checked: a word field against this class's constants, a number field as
	 * digits (with 's' or 'm' on the caps and the idle setting), the session
	 * handle by its shape. Anything else reads as 'other', and a field the note
	 * does not carry is absent, never guessed: a row written before holder
	 * existed has no holder.
	 *
	 * @return array<string, string|int|null> scope, via|reason, session, holder, fallback,
	 *         idle_cap, absolute_cap, idle_setting (seconds or null for none, 'unknown'),
	 *         open_seconds (int or 'unknown'), unwraps (int)
	 */
	public static function parseNote(string $event, string $note): array {
		$words = array(
			'via'      => self::constantsStarting('VIA_'),
			'reason'   => self::constantsStarting('REASON_'),
			'holder'   => array(self::HOLDER_DAEMON, self::HOLDER_POOL),
			'fallback' => array(self::FALLBACK_POOL_RUNG),
		);
		$fields = array();
		foreach (preg_split('/\s+/', trim($note)) as $pair) {
			$eq = strpos($pair, '=');
			if ($eq === false || $eq === 0) {
				continue;
			}
			$name = substr($pair, 0, $eq);
			$value = substr($pair, $eq + 1);
			if (isset($words[$name])) {
				$fields[$name] = in_array($value, $words[$name], true) ? $value : 'other';
			} elseif ($name === 'scope') {
				// Any scope a consumer registers: a name, checked by its shape.
				$fields[$name] = preg_match('/^[a-z][a-z0-9_]{0,31}$/', $value) ? $value : 'other';
			} elseif ($name === 'session') {
				$fields[$name] = preg_match('/^(none|[0-9a-f]{12}|apk[0-9]{1,12})$/', $value) ? $value : 'other';
			} elseif ($name === 'idle_cap' || $name === 'absolute_cap' || $name === 'idle_setting') {
				if ($value === 'none') {
					$fields[$name] = null;
				} elseif ($value === 'unknown') {
					$fields[$name] = 'unknown';
				} elseif (preg_match('/^([0-9]{1,9})(s|m)$/', $value, $m)) {
					$fields[$name] = (int)$m[1] * ($m[2] === 'm' ? 60 : 1);
				} else {
					$fields[$name] = 'other';
				}
			} elseif ($name === 'open_seconds') {
				$fields[$name] = $value === 'unknown' ? 'unknown'
					: (preg_match('/^[0-9]{1,12}$/', $value) ? (int)$value : 'other');
			} elseif ($name === 'unwraps') {
				$fields[$name] = preg_match('/^[0-9]{1,12}$/', $value) ? (int)$value : 'other';
			}
		}
		if ($event === self::EVENT_OPENED) {
			unset($fields['reason'], $fields['open_seconds'], $fields['unwraps']);
		} elseif ($event === self::EVENT_CLOSED) {
			unset($fields['via'], $fields['holder'], $fields['fallback'],
				$fields['idle_cap'], $fields['absolute_cap'], $fields['idle_setting']);
		} else {
			return array();
		}
		return $fields;
	}

	/** The values of this class's constants whose names start with $prefix. */
	private static function constantsStarting(string $prefix): array {
		$out = array();
		foreach ((new ReflectionClass(self::class))->getConstants() as $name => $value) {
			if (strpos($name, $prefix) === 0) {
				$out[] = $value;
			}
		}
		return $out;
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
			// The note is metadata only (see the class comment), so it is
			// declared content-free: a window can close on a request that has
			// already opened sealed content, and the audit row must still land.
			SealedEgressGuard::contentFree('vault_audit', function () use ($event, $user_id, $note) {
				SystemBase::server_initiated_write(function () use ($event, $user_id, $note) {
					$log = new EventLog(NULL);
					$log->set('evl_event',       $event);
					$log->set('evl_usr_user_id', $user_id);
					$log->set('evl_was_success', true);
					$log->set('evl_note',        $note);
					$log->save();
				});
			});
		} catch (\Throwable $e) {
			error_log($event . ': could not write the vault audit row for user '
				. $user_id . ' — ' . $e->getMessage());
		}
	}
}
?>
