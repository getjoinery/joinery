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
 * used:
 *
 *   scanAtIngest() = learningEnabled() AND something upstream scanned
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
 * @version 1.1
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
	 * Whether a message should be re-scored locally at ingest, replacing the
	 * verdict that arrived with it.
	 *
	 * Two conditions, both necessary:
	 *   - Learning is on, so a tenant corpus exists that the upstream scanner
	 *     does not have. Without it a local re-score adds nothing.
	 *   - Something OTHER than this box's own milter produced the arriving
	 *     verdict. On a colocated deployment the milter already scored the
	 *     message through the same rspamd and the same corpus, so re-scanning
	 *     would repeat work on a raw that already carries the headers rspamd
	 *     stamped.
	 *
	 * The local verdict REPLACES the upstream content signal rather than being
	 * OR'd with it: a Bayes-less relay verdict that OR'd in could only ever add
	 * spam, never subtract it, so a user's "not spam" corrections would never
	 * change a disposition. The local scanner runs the same static ruleset PLUS
	 * the tenant corpus, so it is strictly better informed. (Auth-rule
	 * classification is unaffected and still OR's in — see classifySpam.)
	 */
	public static function scanAtIngest(): bool {
		return self::learningEnabled() && self::upstreamScanner() !== 'none';
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
	 * Forget the cached topology source. Tests that create or delete relay rows
	 * mid-run call this; nothing in production needs it (topology cannot change
	 * within one request).
	 */
	public static function reset(): void {
		self::$setup_check = null;
	}
}
?>
