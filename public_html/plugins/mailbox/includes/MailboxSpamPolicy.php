<?php
/**
 * MailboxSpamPolicy — the one place the deployment's spam posture is decided
 * (specs/mailbox_spam_filtering_simplification.md).
 *
 * A site owner answers one question — should suspected spam be moved out of the
 * inbox? — plus one optional capability: should this deployment learn from what
 * its users mark? Everything else (whether a scanner runs on this box, where an
 * arriving verdict came from, which mail topology is in play) is derived here
 * from state that already exists. No caller re-reads the raw settings or
 * re-derives topology; provisioning, the health probe, the learning task, the
 * ingest scan and the admin pages all ask these predicates.
 *
 * The scanner itself is NOT derived: it ships with the mail stack
 * (install_email.sh installs it unconditionally) and is never removed by the
 * platform, so activating learning on day 2 is a pure settings toggle — no
 * command to run, nothing to install. What IS derived is how the scanner is
 * used, and that splits into three questions kept deliberately apart:
 *
 *   scanAtIngest()        = filingEnabled() AND something upstream scanned
 *   scannerAvailable()    = the controller is answering right now (OBSERVED)
 *   localVerdictReplaces() = learningEnabled()
 *
 * The first is POSTURE — pure settings and topology, so it is deterministic and
 * testable on any box. The second is CAPABILITY — a live observation, memoized
 * per request, that the router ANDs in before it tries. Keeping them apart is
 * what lets the posture be asserted in a test without a scanner running, and
 * stops a dead scanner from reading as a policy decision.
 *
 * Scanning at ingest does NOT require learning. An upstream scanner — a shared
 * relay or a webhook provider — is deliberately stateless, and its verdict is
 * the only content signal a fronted deployment would otherwise ever get. Where
 * a scanner is running here, running it is worth more than trusting a header
 * that may never have been stamped. What learning changes is how much that
 * local verdict is trusted:
 *
 *   - Learning OFF: the local verdict is OR'd into the upstream one. It can add
 *     spam, never subtract it. Without a corpus the local scan is the same
 *     static ruleset the upstream ran, minus the live SMTP client context a
 *     milter sees, so it is not better informed and must not override.
 *   - Learning ON: the local verdict REPLACES the upstream one. The corpus is
 *     knowledge that exists nowhere else, and replacement is what lets a user's
 *     "not spam" correction actually subtract — an OR could only ever add.
 *
 * A box with no mail stack of its own (webhook-only, or relay-fronted from
 * birth) never ran a root provisioning script and has no scanner; learning is
 * simply unavailable there — the Settings page disables the checkbox with the
 * reason — unless an operator hand-runs provision_spam_scanner.sh install.
 * Presence is always OBSERVED (controllerReachable()), never stored: there is
 * no "scanner installed" setting to contradict reality.
 *
 * Two deliberate details:
 *   - The provider is read RESOLVED (InboundProviderRegistry::active()), never
 *     as the raw mailbox_provider row: the registry falls back to Postfix when
 *     the setting is empty or names an unknown provider, and the policy must
 *     agree with whatever actually ingests mail.
 *   - learningEnabled() is CLAMPED by filingEnabled(). Learning with nothing
 *     filing spam is not a state to validate against; it simply cannot be
 *     reached. The stored row survives as a remembered preference, inert until
 *     filing returns.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundProviderRegistry.php'));
// InboundEmailSetupCheck is required lazily in topologyMode(): it drags in the
// whole DNS/domain/alias chain, and the common answers (learning off, or a
// webhook provider) never reach the topology question.

class MailboxSpamPolicy {

	/** Loopback controller endpoint used when the setting is empty. */
	const DEFAULT_CONTROLLER_URL = 'http://127.0.0.1:11334';

	/** Port rspamd's proxy (milter) worker listens on for Postfix. */
	const MILTER_PORT = 11332;

	/** The milter entry provisioning appends to Postfix's smtpd_milters. */
	const MILTER_ENTRY = 'inet:localhost:11332';

	/** @var InboundEmailSetupCheck|null Topology source, resolved once per request. */
	private static $setup_check = null;

	/** @var bool|null Memoized scannerAvailable() probe, one per request. */
	private static $scanner_available = null;

	/** @var bool|null Test-pinned scanner presence; outranks the probe. */
	private static $scanner_override = null;

	/**
	 * Whether suspected spam is filed into the reviewable Spam view. The one
	 * question most owners ever answer; on by default.
	 */
	public static function filingEnabled(): bool {
		return self::truthy(Globalvars::get_instance()->get_setting('mailbox_spam_filtering_enabled'));
	}

	/**
	 * Whether this deployment learns from user spam/ham corrections. Clamped by
	 * filingEnabled() — see the class docblock.
	 */
	public static function learningEnabled(): bool {
		if (!self::filingEnabled()) {
			return false;
		}
		return self::truthy(Globalvars::get_instance()->get_setting('mailbox_spam_learning_enabled'));
	}

	/**
	 * What already scanned a message before it reached this box, for display and
	 * for the P1-vs-P2/P3 ingest distinction.
	 *
	 * @return string 'provider' | 'relay' | 'none'
	 *         'none' means nothing upstream scans — this box IS the MX and its
	 *         own milter is the only scanner in the path.
	 */
	public static function upstreamScanner(): string {
		$provider = InboundProviderRegistry::active();
		if ($provider::isWebhook()) {
			return 'provider';
		}
		return (self::topologyMode() === 'colocated') ? 'none' : 'relay';
	}

	/**
	 * Whether this box hosts its own mail stack (install_email.sh ran here).
	 * The scanner ships with the mail stack, so this is also where a scanner is
	 * REQUIRED — the health probe flags a mail-stack box whose scanner is
	 * missing or down. A box without one (webhook-only, relay-fronted from
	 * birth) is never flagged: nothing of ours ever ran as root there.
	 */
	public static function mailStackPresent(): bool {
		return is_file('/etc/postfix/main.cf');
	}

	/**
	 * Whether a message SHOULD be re-scored locally at ingest — posture only.
	 * The caller ANDs in scannerAvailable() before it actually tries.
	 *
	 * Two conditions, both necessary:
	 *   - Filing is on. With it off no verdict is recorded at all, so scanning
	 *     could not change an outcome.
	 *   - Something OTHER than this box's own milter produced the arriving
	 *     verdict. On a colocated deployment the milter already scored the
	 *     message through the same rspamd and the same corpus, so re-scanning
	 *     would repeat work on a raw that already carries the headers rspamd
	 *     stamped.
	 *
	 * Learning is deliberately NOT a condition — see the class docblock. A
	 * fronted deployment's only content signal is a header from a stateless
	 * upstream, and a header that was never stamped is indistinguishable from a
	 * clean verdict. Scanning here is how that becomes observable. Whether the
	 * local verdict replaces the upstream one or merely adds to it is the
	 * separate question localVerdictReplaces() answers.
	 *
	 * (Auth-rule classification is unaffected and still OR's in — see
	 * classifySpam.)
	 */
	public static function scanAtIngest(): bool {
		return self::filingEnabled() && self::upstreamScanner() !== 'none';
	}

	/**
	 * Whether the local verdict REPLACES the upstream content signal rather than
	 * being OR'd with it. True only where a tenant corpus exists, because only
	 * then is the local scanner better informed than the upstream one — and only
	 * then does replacement buy anything, since an OR can add spam but never
	 * subtract it, so a user's "not spam" correction could never change a
	 * disposition. See the class docblock.
	 */
	public static function localVerdictReplaces(): bool {
		return self::learningEnabled();
	}

	/**
	 * Whether a scan can actually be attempted right now — controllerReachable()
	 * memoized for the request, so a spool run pulling a hundred messages probes
	 * once rather than per message.
	 *
	 * Separate from controllerReachable() itself, which the Settings page and the
	 * health probe call directly and must always see fresh.
	 */
	public static function scannerAvailable(): bool {
		if (self::$scanner_override !== null) {
			return self::$scanner_override;
		}
		if (self::$scanner_available === null) {
			self::$scanner_available = self::controllerReachable();
		}
		return self::$scanner_available;
	}

	/**
	 * Pin scanner presence for a test run; null restores live probing.
	 *
	 * Whether a message is re-scored is half posture (scanAtIngest, pure) and
	 * half live observation (scannerAvailable). A test asserting the behaviour
	 * that follows from both has to pin the observation, or it would pass or
	 * fail on whether rspamd happens to be running on the box executing it.
	 * Deliberately survives reset(), which clears the ordinary memo.
	 */
	public static function overrideScannerAvailable(?bool $available): void {
		self::$scanner_override = $available;
	}

	/** The rspamd controller endpoint, defaulted to loopback. */
	public static function controllerUrl(): string {
		$url = rtrim(trim((string)Globalvars::get_instance()->get_setting('mailbox_rspamd_controller_url')), '/');
		return ($url === '') ? self::DEFAULT_CONTROLLER_URL : $url;
	}

	/**
	 * Whether the local rspamd controller is answering — the observed scanner
	 * presence the health probe and the Settings page's learning gate both
	 * read. Never stored, always probed.
	 */
	public static function controllerReachable(float $timeout = 2.0): bool {
		$url  = self::controllerUrl();
		$host = parse_url($url, PHP_URL_HOST) ?: '127.0.0.1';
		$port = parse_url($url, PHP_URL_PORT) ?: 11334;
		$sock = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
		if (!$sock) {
			return false;
		}
		@fclose($sock);
		return true;
	}

	/**
	 * Whether Postfix is wired to hand mail to the rspamd milter. Only
	 * meaningful on a colocated deployment: a scanner installed while Postfix
	 * was absent (relay-fronted, listener decommissioned) never got wired, and
	 * restoring the listener later leaves that drift behind.
	 */
	public static function milterWired(): bool {
		$out = array();
		@exec('postconf -h smtpd_milters 2>/dev/null', $out);
		return strpos(strtolower(implode(' ', $out)), self::MILTER_ENTRY) !== false;
	}

	/** The command that installs or repairs the scanner on this box. */
	public static function installCommand(): string {
		return 'sudo bash ' . PathHelper::getAbsolutePath(
			'plugins/mailbox/provisioning/provision_spam_scanner.sh') . ' install';
	}

	/**
	 * The deployment's receive topology mode, from the engine that already owns
	 * that question. Never re-implemented here.
	 *
	 * @return string 'colocated' | 'self_hosted' | 'fleet'
	 */
	private static function topologyMode(): string {
		if (self::$setup_check === null) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
			self::$setup_check = new InboundEmailSetupCheck();
		}
		try {
			return (string)self::$setup_check->topology()['mode'];
		} catch (\Throwable $e) {
			// Relay table absent (before update_database) — colocated.
			return 'colocated';
		}
	}

	/** Settings arrive as strings from several writers; accept every truthy shape. */
	private static function truthy($value): bool {
		$v = strtolower(trim((string)$value));
		return in_array($v, array('1', 'true', 't', 'yes', 'on'), true);
	}

	/**
	 * Forget the cached topology source and the memoized scanner probe. Tests
	 * that create or delete relay rows mid-run call this; nothing in production
	 * needs it (neither can change within one request).
	 */
	public static function reset(): void {
		self::$setup_check = null;
		self::$scanner_available = null;
	}
}
?>
