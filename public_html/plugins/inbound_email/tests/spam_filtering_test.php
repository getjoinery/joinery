<?php
/**
 * Tests for inbound spam classification (specs/inbound_email_spam_filtering.md).
 *
 * Exercises InboundEmailRouter::classifySpam() directly via reflection with
 * representative auth-verdict arrays — the method is pure given the settings gate,
 * so no DB write or message ingest is needed. The gate
 * (inbound_email_spam_filtering_enabled) is toggled by injecting into the
 * Globalvars singleton's private settings (the same instance the router reads).
 *
 * Covers:
 *   - gate off → null verdict (behavior unchanged)
 *   - primary rule: DMARC fail → spam; DMARC pass → ham
 *   - no-DMARC fallback: SPF+DKIM both fail → spam; only one fails → ham;
 *     both pass → ham
 *   - the fallback fires for 'none' AND 'unverified' DMARC (Mailgun/SendGrid shape)
 *
 * Run: php plugins/inbound_email/tests/spam_filtering_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));

class SpamFilteringTest {
	private $pass = 0;
	private $fail = 0;

	private function out($msg) {
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . $msg . "\n";
	}
	private function eq($expected, $actual, $label) {
		if ($expected === $actual) {
			$this->pass++;
			$this->out('  PASS: ' . $label);
		} else {
			$this->fail++;
			$this->out('  FAIL: ' . $label . ' (expected ' . var_export($expected, true)
				. ', got ' . var_export($actual, true) . ')');
		}
	}

	/** Force the spam-filtering gate on/off by injecting into Globalvars' private settings. */
	private function setGate(bool $on): void {
		$gv = Globalvars::get_instance();
		$ref = new ReflectionProperty('Globalvars', 'settings');
		$ref->setAccessible(true);
		$settings = $ref->getValue($gv);
		if (!is_array($settings)) { $settings = array(); }
		$settings['inbound_email_spam_filtering_enabled'] = $on ? '1' : '0';
		$ref->setValue($gv, $settings);
	}

	/** Invoke the router's private classifySpam() with an auth array. */
	private function classify(array $auth) {
		$router = new InboundEmailRouter();
		$m = new ReflectionMethod('InboundEmailRouter', 'classifySpam');
		$m->setAccessible(true);
		return $m->invoke($router, $auth);
	}

	private function auth($spf, $dkim, $dmarc, $source = 'milter'): array {
		return array('spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'source' => $source);
	}

	function run() {
		$this->out('=== Spam classification tests ===');

		$HAM  = InboundEmailMessage::SPAM_VERDICT_HAM;
		$SPAM = InboundEmailMessage::SPAM_VERDICT_SPAM;

		// Gate off: never evaluated, regardless of verdicts.
		$this->setGate(false);
		$this->eq(null, $this->classify($this->auth('fail', 'fail', 'fail')),
			'gate off → null even when everything fails');

		// Gate on for the rest.
		$this->setGate(true);

		// Primary DMARC rule.
		$this->eq($SPAM, $this->classify($this->auth('pass', 'pass', 'fail')),
			'DMARC fail → spam (even with SPF/DKIM passing)');
		$this->eq($HAM, $this->classify($this->auth('pass', 'pass', 'pass')),
			'DMARC pass → ham');
		$this->eq($HAM, $this->classify($this->auth('fail', 'fail', 'pass')),
			'DMARC pass wins over SPF/DKIM fails → ham');

		// No-DMARC fallback (Mailgun/SendGrid shape): both SPF and DKIM must fail.
		$this->eq($SPAM, $this->classify($this->auth('fail', 'fail', 'none')),
			'no DMARC + SPF & DKIM both fail → spam');
		$this->eq($SPAM, $this->classify($this->auth('fail', 'fail', 'unverified')),
			'unverified DMARC + SPF & DKIM both fail → spam');
		$this->eq($HAM, $this->classify($this->auth('fail', 'pass', 'none')),
			'no DMARC + only SPF fails → ham (alignment caveat)');
		$this->eq($HAM, $this->classify($this->auth('pass', 'fail', 'none')),
			'no DMARC + only DKIM fails → ham (alignment caveat)');
		$this->eq($HAM, $this->classify($this->auth('pass', 'pass', 'none')),
			'no DMARC + SPF & DKIM both pass → ham');
		$this->eq($HAM, $this->classify($this->auth('unverified', 'unverified', 'unverified')),
			'fully unverified → ham (no fail signal)');

		$this->out('');
		$this->out('=== Result: ' . $this->pass . ' passed, ' . $this->fail . ' failed ===');
		return $this->fail === 0 ? 0 : 1;
	}
}

$test = new SpamFilteringTest();
exit($test->run());
