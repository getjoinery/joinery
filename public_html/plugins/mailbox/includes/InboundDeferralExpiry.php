<?php
/**
 * InboundDeferralExpiry — when a message the pipe keeps deferring is about to
 * outlive Postfix's queue.
 *
 * The pipe defers (exit 75) when a message cannot be stored yet: the store cap
 * for the window is reached, a sealing mailbox has no key, the database is
 * down. Postfix keeps the message and retries. A deferral still standing when
 * maximal_queue_lifetime runs out is bounced to the message's sender, and on
 * spam that sender is a forged stranger. So on its last retries the handler
 * drops the message and logs it instead of deferring again.
 *
 * "Last retries" is the final stretch of the queue lifetime two backoff
 * ceilings long: Postfix never waits longer than maximal_backoff_time between
 * attempts, so at least one attempt always lands inside it. On a queue lifetime
 * shorter than that, the stretch is its second half, so a first deferral is
 * never a drop.
 *
 * The arrival time is the date on the Received header this box's Postfix
 * wrote, found by the queue id the pipe passes. A message whose queue id no
 * header names (requeued with postsuper -r) is timed from its topmost Received
 * header, the latest hop: later than the true arrival, so it can only drop
 * late, never early.
 *
 * What this cannot reach: a handler Postfix kills at command_time_limit, which
 * Postfix defers itself and bounces at expiry.
 *
 * @version 1.1 - the window is at most half the queue lifetime; a queue id no Received header names
 *                falls back to the topmost Received header
 * @version 1.0
 */
class InboundDeferralExpiry {

	/** Postfix's own defaults, used when postconf cannot be asked. */
	const DEFAULT_QUEUE_LIFETIME = '5d';
	const DEFAULT_MAX_BACKOFF = '4000s';

	/**
	 * When this box accepted the message: the date on the Received header
	 * naming $queue_id, else the date on the topmost Received header, as a
	 * Unix time. Null when there is none to read.
	 */
	public static function arrival_time(string $raw, string $queue_id): ?int {
		if ($queue_id === '' || !preg_match('/^[A-Za-z0-9]+$/', $queue_id)) {
			return null;
		}
		$split = preg_split("/\r?\n\r?\n/", $raw, 2);
		$head = preg_replace("/\r?\n[ \t]+/", ' ', $split[0]);
		$topmost = null;
		foreach (preg_split("/\r?\n/", $head) as $line) {
			if (stripos($line, 'Received:') !== 0) {
				continue;
			}
			if ($topmost === null) {
				$topmost = $line;
			}
			if (preg_match('/\bid\s+' . preg_quote($queue_id, '/') . '\b/', $line)) {
				return self::received_date($line);
			}
		}
		return ($topmost === null) ? null : self::received_date($topmost);
	}

	/** The date after the last ';' of a Received header, or null. */
	private static function received_date(string $line): ?int {
		$semi = strrpos($line, ';');
		if ($semi === false) {
			return null;
		}
		$ts = strtotime(trim(substr($line, $semi + 1)));
		return ($ts === false) ? null : $ts;
	}

	/**
	 * A Postfix time value in seconds ('5d', '4000s', '3h', '7' with the
	 * parameter's default unit). Null when it does not parse.
	 */
	public static function seconds(string $value, string $default_unit): ?int {
		if (!preg_match('/^\s*(\d+)\s*([smhdw]?)\s*$/i', $value, $m)) {
			return null;
		}
		$unit = strtolower($m[2] !== '' ? $m[2] : $default_unit);
		$per = array('s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800);
		return isset($per[$unit]) ? (int)$m[1] * $per[$unit] : null;
	}

	/**
	 * This box's queue lifetime and backoff ceiling in seconds, read from
	 * postconf; Postfix's defaults when it cannot be asked.
	 */
	public static function queue_limits(): array {
		$lifetime = self::seconds(self::DEFAULT_QUEUE_LIFETIME, 'd');
		$backoff = self::seconds(self::DEFAULT_MAX_BACKOFF, 's');
		foreach (array('/usr/sbin/postconf', 'postconf') as $bin) {
			if ($bin[0] === '/' && !is_executable($bin)) {
				continue;
			}
			$out = array();
			$rc = 1;
			@exec(escapeshellcmd($bin) . ' -h maximal_queue_lifetime maximal_backoff_time 2>/dev/null', $out, $rc);
			if ($rc === 0 && count($out) === 2) {
				$lifetime = self::seconds($out[0], 'd') ?? $lifetime;
				$backoff = self::seconds($out[1], 's') ?? $backoff;
				break;
			}
		}
		return array($lifetime, $backoff);
	}

	/** Whether a deferral now would be one of the message's last. */
	public static function is_expiring(int $arrival, int $now, int $lifetime, int $max_backoff): bool {
		$window = min(2 * $max_backoff, intdiv($lifetime, 2));
		return $now >= $arrival + $lifetime - $window;
	}

	/**
	 * The pipe's exit code for a message it would defer: 75, unless this is
	 * one of the message's last retries, when the message is dropped (0) and
	 * logged to the domain's routing log. Never throws — the pipe handler also
	 * calls it from its last-resort catch — and anything that fails here
	 * defers, which Postfix retries.
	 *
	 * @param int|null   $now    the time to judge at (tests); the clock otherwise
	 * @param array|null $limits [queue lifetime, max backoff] in seconds (tests); postconf otherwise
	 */
	public static function defer_or_drop(string $raw, string $recipient, string $queue_id, string $why,
			?int $now = null, ?array $limits = null): int {
		try {
			$arrival = self::arrival_time($raw, $queue_id);
			if ($arrival === null) {
				error_log('InboundDeferralExpiry: deferring ' . $recipient . ' (' . $why . '); no Received header names queue id '
					. var_export($queue_id, true) . ', so it cannot be dropped before the queue lifetime ends');
				return 75;
			}
			list($lifetime, $backoff) = $limits ?? self::queue_limits();
			if (!self::is_expiring($arrival, $now ?? time(), $lifetime, $backoff)) {
				return 75;
			}
		} catch (\Throwable $e) {
			error_log('InboundDeferralExpiry: deferring ' . $recipient . '; the expiry check failed: ' . $e->getMessage());
			return 75;
		}

		$note = 'Deferred since ' . gmdate('Y-m-d H:i', $arrival) . ' UTC (' . $why . '); dropped before the queue '
			. 'lifetime ended, so no bounce was sent to the sender';
		error_log('InboundDeferralExpiry: ' . $recipient . ' queue ' . $queue_id . ': ' . $note);
		try {
			$router = new InboundEmailRouter();
			$parts = explode('@', strtolower($recipient), 2);
			$domain = (count($parts) === 2) ? InboundEmailDomain::GetByDomain($parts[1]) : false;
			$router->logTransaction($router->parseEmail($raw), null, InboundEmailLog::STATUS_DISCARDED,
				$recipient, null, $note, $domain ? $domain->key : null);
		} catch (\Throwable $e) {
			error_log('InboundDeferralExpiry: could not log the dropped deferral: ' . $e->getMessage());
		}
		return 0;
	}
}
