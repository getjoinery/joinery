<?php
/** @joinery-test
 * name: inbound_forwarding_relay
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Tests for inbound forwarding relay resolution (spec: route inbound forwarding
 * through the selected outbound provider).
 *
 * Two layers are covered:
 *
 *   1. Capability inventory — exactly the providers the spec decided on
 *      (Mailgun, SMTP, SES) implement RawMessageRelay; the structured-only
 *      providers (Postmark, SendGrid, Brevo, Mailjet, Resend) deliberately do
 *      not. This is pure reflection — no DB, no network.
 *
 *   2. Resolver path selection — InboundEmailRouter::resolveRelayProvider()
 *      picks the provider raw-MIME relay when the active provider supports it
 *      and no forwarding-SMTP override is set; falls back (returns null) for a
 *      non-supporting provider; and falls back even for a supporting provider
 *      when an explicit forwarding-SMTP host override is set.
 *
 * Settings are controlled in-process via reflection on the Globalvars
 * singleton's in-memory cache, so the resolver sees deterministic inputs.
 *
 * Run: php tests/integration/inbound_forwarding_relay_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

class InboundForwardingRelayTest {
	private function out($msg) {
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . $msg . "\n";
	}
	private function ok($cond, $label) {
		return check((bool)$cond, $label);
	}

	/** Override a setting in the Globalvars in-memory cache for this process. */
	private function setSetting($key, $value) {
		$gv = Globalvars::get_instance();
		$ref = new ReflectionProperty('Globalvars', 'settings');
		$ref->setAccessible(true);
		$arr = $ref->getValue($gv);
		if (!is_array($arr)) { $arr = array(); }
		$arr[$key] = $value;
		$ref->setValue($gv, $arr);
	}

	private function implementsRelay($class) {
		return class_exists($class) && in_array('RawMessageRelay', class_implements($class));
	}

	function run() {
		$this->out('=== Inbound forwarding relay tests ===');
		$this->testInventory();
		$this->testResolverProviderPath();
		$this->testResolverNonSupportingFallback();
		$this->testResolverOverrideForcesSmtp();
	}

	/** The spec's provider inventory, asserted against the actual classes. */
	private function testInventory() {
		$this->out('-- capability inventory --');

		$supports = array(
			'includes/email_providers/MailgunProvider.php' => 'MailgunProvider',
			'includes/email_providers/SmtpProvider.php'    => 'SmtpProvider',
			'includes/email_providers/SesProvider.php'     => 'SesProvider',
		);
		$no_support = array(
			'includes/email_providers/PostmarkProvider.php' => 'PostmarkProvider',
			'includes/email_providers/SendGridProvider.php' => 'SendGridProvider',
			'includes/email_providers/BrevoProvider.php'    => 'BrevoProvider',
			'includes/email_providers/MailjetProvider.php'  => 'MailjetProvider',
			'includes/email_providers/ResendProvider.php'   => 'ResendProvider',
		);

		foreach ($supports as $file => $class) {
			require_once(PathHelper::getIncludePath($file));
			$this->ok($this->implementsRelay($class), $class . ' implements RawMessageRelay');
		}
		foreach ($no_support as $file => $class) {
			require_once(PathHelper::getIncludePath($file));
			$this->ok(!$this->implementsRelay($class), $class . ' does NOT implement RawMessageRelay');
		}
	}

	/** Supporting provider + no override → provider raw-MIME relay chosen. */
	private function testResolverProviderPath() {
		$this->out('-- resolver: provider relay chosen --');
		$this->setSetting('email_service', 'mailgun');
		$this->setSetting('mailbox_forwarding_smtp_host', '');

		$router = new InboundEmailRouter();
		$relay = $router->resolveRelayProvider();
		$this->ok($relay instanceof RawMessageRelay, 'active mailgun resolves to a RawMessageRelay provider');
		$this->ok($relay instanceof MailgunProvider, 'resolved relay is MailgunProvider');

		$desc = $router->describeRelay();
		$this->ok($desc['mode'] === 'provider', 'describeRelay() reports provider mode');
	}

	/** Non-supporting provider → SMTP fallback (resolver returns null). */
	private function testResolverNonSupportingFallback() {
		$this->out('-- resolver: non-supporting provider falls back --');
		$this->setSetting('email_service', 'postmark');
		$this->setSetting('mailbox_forwarding_smtp_host', '');

		$router = new InboundEmailRouter();
		$relay = $router->resolveRelayProvider();
		$this->ok($relay === null, 'active postmark resolves to SMTP fallback (null)');

		$desc = $router->describeRelay();
		$this->ok($desc['mode'] === 'smtp', 'describeRelay() reports smtp mode');
	}

	/** Explicit forwarding-SMTP override → SMTP path even for a relay provider. */
	private function testResolverOverrideForcesSmtp() {
		$this->out('-- resolver: forwarding-SMTP override forces SMTP --');
		$this->setSetting('email_service', 'mailgun');
		$this->setSetting('mailbox_forwarding_smtp_host', 'relay.example.com');

		$router = new InboundEmailRouter();
		$relay = $router->resolveRelayProvider();
		$this->ok($relay === null, 'forwarding-SMTP override forces SMTP fallback despite mailgun support');
	}
}

$test = new InboundForwardingRelayTest();
$test->run();
harness_finish();
