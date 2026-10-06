<?php
/**
 * OutboundTransferMeter — what this server has sent this month, counted by
 * the site itself (specs/node_outbound_and_transfer.md WP1, where nobody else
 * holds the provider account).
 *
 * Most providers charge for outbound transfer past a monthly allowance, and
 * most owners never think about it. A site whose provider account is ours is
 * watched by the management node from the provider's own figure; everywhere
 * else the site is the only thing that can say anything, so it counts.
 *
 * WHAT IS COUNTED. The bytes sent on the interfaces that carry this machine's
 * default routes (IPv4 and IPv6), from /proc/net/dev. Inside a container that
 * is the container's own interface, which carries its replies to the host's
 * proxy, so visitors' downloads are in it. On bare metal it is the machine's
 * public interface, so every site on the machine counts the machine: the
 * notice says "this server", not "this site". Packet headers are in it, so
 * it runs a few percent above a provider's figure, and traffic a provider
 * does not charge for (same data center, IPv6) is in it too.
 *
 * COUNTERS START AGAIN. A reboot or a new container starts them at zero. The
 * meter keys its last reading by the boot and the network namespace it read
 * them in; a different key means everything now on the counter is new. A
 * counter that went down under the same key started again too. An interface
 * first seen under the same key is a baseline, never a delta: it only just
 * took a default route, and its counter holds everything since boot.
 *
 * PAST THE OWNER'S FIGURE (outbound_monthly_notice_gb, 1 TB by default) the
 * admin header says so and the superadmins get one email that month. Silent
 * on a site the operator hosts on its own account (hosted_plan_state): the
 * management node watches that machine from the provider's figure.
 *
 * @version 1.1 - an interface new under the same boot is a baseline (a default-route flap counted
 *                its whole since-boot counter); a reading with no interface changes nothing;
 *                'since' the 1st only when the last reading was within a day of the turn
 * @version 1.0
 */
class OutboundTransferMeter {

	const STATE_SETTING  = 'outbound_transfer_month';
	const NOTICE_SETTING = 'outbound_monthly_notice_gb';
	const SIGNAL         = 'site.outbound_transfer_high';

	/** The hosting states in which the operator's own account holds the machine. */
	const OPERATOR_HOSTED_STATES = array('trial', 'subscribed', 'grace', 'shutdown');

	/** Tests catch the email's signal here instead of sending it: fn(string $signal, array $payload). */
	public static $dispatch = null;

	/**
	 * The interfaces carrying a default route and what each has sent, and
	 * the identity of the counters' life: ['identity' => string, 'ifaces' =>
	 * [name => tx bytes]]. $root is a filesystem root for tests.
	 */
	public static function counters(string $root = ''): array {
		$default = array();
		foreach (self::lines($root . '/proc/net/route') as $i => $line) {
			$f = preg_split('/\s+/', trim($line));
			if ($i > 0 && count($f) >= 8 && $f[1] === '00000000' && $f[7] === '00000000') {
				$default[$f[0]] = true;
			}
		}
		foreach (self::lines($root . '/proc/net/ipv6_route') as $line) {
			$f = preg_split('/\s+/', trim($line));
			if (count($f) >= 10 && $f[0] === str_repeat('0', 32) && $f[1] === '00' && $f[9] !== 'lo') {
				$default[$f[9]] = true;
			}
		}
		$ifaces = array();
		foreach (self::lines($root . '/proc/net/dev') as $line) {
			if (!preg_match('/^\s*([^:\s]+):\s*(.*)$/', $line, $m) || !isset($default[$m[1]])) {
				continue;
			}
			$f = preg_split('/\s+/', trim($m[2]));
			if (isset($f[8]) && ctype_digit($f[8])) {
				$ifaces[$m[1]] = (int)$f[8];
			}
		}
		$boot = trim((string)@file_get_contents($root . '/proc/sys/kernel/random/boot_id'));
		$netns = (string)@readlink($root . '/proc/self/ns/net');
		return array('identity' => $boot . '|' . $netns, 'ifaces' => $ifaces);
	}

	/**
	 * Add what was sent since the last reading to the month and store it.
	 * The first reading ever only sets the baseline: what the counters held
	 * before counting began is not this month's to know. Returns the state.
	 */
	public static function tick(?int $now = null, ?array $counters = null): array {
		$now = $now ?? time();
		$counters = $counters ?? self::counters();
		$state = self::state();
		$period = gmdate('Y-m', $now);
		$month_start = strtotime($period . '-01 00:00:00 UTC');

		// Under the same boot and namespace an interface's counter only grows,
		// or went down because the interface was recreated (what is on it is
		// new). An interface not read before under this identity is a baseline,
		// never a delta: it was there all along, just not carrying the default
		// route at the last reading (a DHCP renew, a tunnel briefly taking
		// ::/0), and its counter holds everything since boot. Only a new
		// identity — a reboot, a new container — makes a whole counter new.
		// Readings are remembered per interface across ticks, so one that
		// drops out of the default set and comes back picks up where it was.
		$delta = 0;
		$same = !empty($state['identity']) && $state['identity'] === $counters['identity'];
		$known = $same ? (array)($state['ifaces'] ?? array()) : array();
		foreach ($counters['ifaces'] as $name => $tx) {
			if ($same) {
				$last = $known[$name] ?? null;
				$delta += $last === null ? 0 : ($tx >= $last ? $tx - $last : $tx);
			} elseif (!empty($state['identity'])) {
				$delta += $tx;
			}
		}

		if (($state['period'] ?? '') !== $period) {
			if (!empty($state['period'])) {
				$state['previous'] = array('period' => $state['period'], 'sent_bytes' => (int)($state['sent_bytes'] ?? 0));
			}
			// Counting ran through the turn of the month when the last reading
			// was within a day of it; after a longer gap it starts now.
			$continuous = !empty($state['last_time']) && (int)$state['last_time'] >= $month_start - 86400;
			$state['period'] = $period;
			$state['sent_bytes'] = 0;
			$state['since'] = ($continuous && !empty($state['identity'])) ? $month_start : $now;
			$state['emailed'] = '';
		}
		$state['sent_bytes'] = (int)($state['sent_bytes'] ?? 0) + $delta;
		// A reading that found no interface (an unreadable /proc for a run)
		// changes nothing it could be wrong about.
		if ($counters['ifaces']) {
			$state['identity'] = $counters['identity'];
			$state['ifaces'] = array_merge($known, $counters['ifaces']);
		}
		$state['last_time'] = $now;

		if (self::over($state, $now) && ($state['emailed'] ?? '') !== $period) {
			self::email($state);
			$state['emailed'] = $period;
		}
		Setting::put(self::STATE_SETTING, json_encode($state));
		return $state;
	}

	/** The stored state, or an empty array. */
	public static function state(): array {
		$state = json_decode((string)Globalvars::get_instance()->get_setting(self::STATE_SETTING, false, true), true);
		return is_array($state) ? $state : array();
	}

	/** This month so far: ['sent_bytes' => int, 'since' => unix], zero before any reading this month. */
	public static function month(?array $state = null, ?int $now = null): array {
		$state = $state ?? self::state();
		$now = $now ?? time();
		if (($state['period'] ?? '') !== gmdate('Y-m', $now)) {
			return array('sent_bytes' => 0, 'since' => strtotime(gmdate('Y-m-01 00:00:00', $now) . ' UTC'));
		}
		return array('sent_bytes' => (int)($state['sent_bytes'] ?? 0), 'since' => (int)($state['since'] ?? $now));
	}

	/** The owner's figure in GB, or 0 when it is switched off. Empty means the 1000 GB default. */
	public static function notice_gb(): int {
		$value = trim((string)Globalvars::get_instance()->get_setting(self::NOTICE_SETTING, false, true));
		return ctype_digit($value) ? (int)$value : 1000;
	}

	/** Whether the operator hosts this site on its own provider account (and watches it there). */
	public static function operator_hosted(): bool {
		$state = trim((string)Globalvars::get_instance()->get_setting('hosted_plan_state', false, true));
		return in_array($state, self::OPERATOR_HOSTED_STATES, true);
	}

	/** Whether this month is past the owner's figure, and the owner is the one to tell. */
	public static function over(?array $state = null, ?int $now = null): bool {
		$gb = self::notice_gb();
		return $gb > 0 && !self::operator_hosted() && self::month($state, $now)['sent_bytes'] > $gb * 1000000000;
	}

	/** The plain-words sentence for the month, shared by the notice and the email. */
	public static function sentence(array $month): string {
		return 'This server has sent ' . self::gb($month['sent_bytes']) . ' since '
			. gmdate('F j', $month['since']) . ', past the ' . number_format(self::notice_gb())
			. ' GB you asked to hear about. Most hosting providers charge for outbound transfer past a monthly '
			. 'allowance (often 1 TB on a small plan), so check this server\'s allowance and usage in your '
			. 'provider\'s control panel.';
	}

	public static function gb(int $bytes): string {
		$gb = $bytes / 1e9;
		return number_format($gb, $gb >= 100 ? 0 : 1) . ' GB';
	}

	private static function email(array $state): void {
		$ids = array();
		try {
			$q = DbConnector::get_instance()->get_db_link()
				->query('SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10 AND usr_delete_time IS NULL');
			$ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
		} catch (Exception $e) {
			error_log('OutboundTransferMeter: could not list the superadmins: ' . $e->getMessage());
		}
		$month = self::month($state);
		$send = self::$dispatch ?? array('SignalBus', 'dispatch');
		$send(self::SIGNAL, array(
			'sent'       => self::gb($month['sent_bytes']),
			'since'      => gmdate('F j', $month['since']),
			'detail'     => self::sentence($month),
			'recipients' => $ids,
		));
	}

	private static function lines(string $path): array {
		$text = @file_get_contents($path);
		return $text === false ? array() : preg_split('/\r?\n/', trim($text));
	}
}
