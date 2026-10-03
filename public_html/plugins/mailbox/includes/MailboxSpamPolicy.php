<?php
/**
 * MailboxSpamPolicy — the one place the deployment's spam posture is decided
 * (spam_learning_in_core.md).
 *
 * A site owner answers one question — should suspected spam be moved out of the
 * inbox? — plus one optional capability: should this deployment learn from what
 * its users mark? Everything else is derived here from state that already
 * exists. No caller re-reads the raw settings or re-derives topology;
 * provisioning, the health probe, the classifier and the admin pages all ask
 * these predicates.
 *
 * Learning happens in the application (SpamBayes, SpamLearning), so it works on
 * every deployment, webhook-only included. rspamd, where it runs, is a
 * header-stamping milter with one stateless configuration
 * (provisioning/rspamd_stateless.sh): the app reads the headers it stamps and
 * never talks to it.
 *
 * Where a scanner runs is still a fact worth stating, for the Settings page's
 * description and the health probe:
 *   - upstreamScanner(): what scanned the message before it reached this box
 *     ('provider', 'relay', or 'none' when this box is the MX and its own milter
 *     is the scanner);
 *   - mailStackPresent() / milterWired() / milterAnswering(): whether this box's
 *     own milter is installed, in Postfix's chain, and listening.
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
 * @version 2.0 - learning lives in core: the rspamd controller, its probe and the
 *   ingest re-scan posture are gone; milterAnswering() is the health probe's check
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundProviderRegistry.php'));
// InboundEmailSetupCheck is required lazily in topologyMode(): it drags in the
// whole DNS/domain/alias chain, and the common answers never reach the topology
// question.

class MailboxSpamPolicy {

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
	 * Whether this deployment learns from what its users mark. Clamped by
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
	 * for the health probe.
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
	 * expected.
	 */
	public static function mailStackPresent(): bool {
		return is_file('/etc/postfix/main.cf');
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

	/** Whether rspamd's milter worker is listening on loopback. */
	public static function milterAnswering(float $timeout = 2.0): bool {
		$sock = @stream_socket_client('tcp://127.0.0.1:' . self::MILTER_PORT, $errno, $errstr, $timeout);
		if (!$sock) {
			return false;
		}
		@fclose($sock);
		return true;
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
	 * mid-run call this; nothing in production needs it.
	 */
	public static function reset(): void {
		self::$setup_check = null;
	}
}
?>
