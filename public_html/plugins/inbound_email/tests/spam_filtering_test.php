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
 *   - content layer (specs/inbound_email_content_spam_filtering.md): classifySpam OR
 *     semantics, readSpamHeader parsing, resolveContentSpam gating + provider branch
 *
 * Run: php plugins/inbound_email/tests/spam_filtering_test.php
 *
 * @version 1.1
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

	/** Set one Globalvars setting by injecting into the singleton's private settings. */
	private function setSetting(string $name, string $value): void {
		$gv = Globalvars::get_instance();
		$ref = new ReflectionProperty('Globalvars', 'settings');
		$ref->setAccessible(true);
		$settings = $ref->getValue($gv);
		if (!is_array($settings)) { $settings = array(); }
		$settings[$name] = $value;
		$ref->setValue($gv, $settings);
	}

	/** Force the master spam-filtering gate on/off. */
	private function setGate(bool $on): void {
		$this->setSetting('inbound_email_spam_filtering_enabled', $on ? '1' : '0');
	}

	/** Force the content spam-filtering gate on/off. */
	private function setContentGate(bool $on): void {
		$this->setSetting('inbound_email_content_spam_filtering_enabled', $on ? '1' : '0');
	}

	/** Invoke the router's private classifySpam() with an auth array (+ content signal). */
	private function classify(array $auth, string $content_signal = 'none') {
		$router = new InboundEmailRouter();
		$m = new ReflectionMethod('InboundEmailRouter', 'classifySpam');
		$m->setAccessible(true);
		return $m->invoke($router, $auth, $content_signal);
	}

	/** Invoke the router's private readSpamHeader(). */
	private function readSpamHeader(string $raw): array {
		$router = new InboundEmailRouter();
		$m = new ReflectionMethod('InboundEmailRouter', 'readSpamHeader');
		$m->setAccessible(true);
		return $m->invoke($router, $raw);
	}

	/** Invoke the router's private resolveContentSpam(). */
	private function resolveContentSpam(string $raw, $provider_spam = null): array {
		$router = new InboundEmailRouter();
		$m = new ReflectionMethod('InboundEmailRouter', 'resolveContentSpam');
		$m->setAccessible(true);
		return $m->invoke($router, $raw, $provider_spam);
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

		// --- Content layer OR semantics (specs/inbound_email_content_spam_filtering.md) ---
		// content=spam OR's in regardless of a passing auth verdict.
		$this->eq($SPAM, $this->classify($this->auth('pass', 'pass', 'pass'), 'spam'),
			'content=spam + auth=ham → spam (content OR fires)');
		// auth=spam still fires even when content is ham/none.
		$this->eq($SPAM, $this->classify($this->auth('pass', 'pass', 'fail'), 'ham'),
			'content=ham + auth=spam → spam (auth rule still fires)');
		$this->eq($HAM, $this->classify($this->auth('pass', 'pass', 'pass'), 'ham'),
			'content=ham + auth=ham → ham');
		$this->eq($HAM, $this->classify($this->auth('pass', 'pass', 'pass'), 'none'),
			'content=none + auth=ham → ham (auth-only behavior unchanged)');
		// Master gate off → null even if content says spam.
		$this->setGate(false);
		$this->eq(null, $this->classify($this->auth('pass', 'pass', 'pass'), 'spam'),
			'master gate off → null even when content=spam');
		$this->setGate(true);

		// --- readSpamHeader() (Postfix milter path) ---
		$this->out('--- readSpamHeader ---');
		$spamRaw = "From: a@b.com\nX-Spam: Yes\nX-Spam-Status: Yes, score=7.31 required=6.00\nSubject: hi\n\nbody";
		$r = $this->readSpamHeader($spamRaw);
		$this->eq('spam', $r['signal'], 'X-Spam: Yes → spam signal');
		$this->eq(7.31, $r['score'], 'score parsed from X-Spam-Status score=');

		$flagRaw = "From: a@b.com\nX-Spam-Flag: YES\nX-Spam-Score: 9.0\n\nbody";
		$r = $this->readSpamHeader($flagRaw);
		$this->eq('spam', $r['signal'], 'X-Spam-Flag: YES → spam signal');
		$this->eq(9.0, $r['score'], 'bare X-Spam-Score preferred when present');

		$hamRaw = "From: a@b.com\nSubject: hi\n\nbody with X-Spam: Yes in the text";
		$r = $this->readSpamHeader($hamRaw);
		$this->eq('none', $r['signal'], 'no header (body mention ignored) → none');
		$this->eq(null, $r['score'], 'no score when absent');

		$noRaw = "From: a@b.com\nX-Spam: No\n\nbody";
		$this->eq('none', $this->readSpamHeader($noRaw)['signal'], 'X-Spam: No → none (header never asserts ham)');

		// --- resolveContentSpam() gating + provider branch ---
		$this->out('--- resolveContentSpam ---');
		$this->setContentGate(false);
		$this->eq('none', $this->resolveContentSpam($spamRaw)['signal'],
			'content gate off → none even with X-Spam: Yes present');
		$this->setContentGate(true);
		$this->eq('spam', $this->resolveContentSpam($spamRaw)['signal'],
			'content gate on → reads the milter header');
		// Webhook provider signal arrives as a sibling argument.
		$prov = $this->resolveContentSpam('', array('result' => 'spam', 'score' => 4.2, 'source' => 'mailgun'));
		$this->eq('spam', $prov['signal'], 'provider spam signal → spam');
		$this->eq(4.2, $prov['score'], 'provider score recorded');
		$provNone = $this->resolveContentSpam('', array('result' => 'none', 'score' => 1.0, 'source' => 'sendgrid'));
		$this->eq('none', $provNone['signal'], 'provider result=none → none (score recorded, no flag)');
		$this->eq(1.0, $provNone['score'], 'provider score still recorded');
		$this->setContentGate(false);

		$this->out('');
		$this->out('=== Result: ' . $this->pass . ' passed, ' . $this->fail . ' failed ===');
		return $this->fail === 0 ? 0 : 1;
	}
}

$test = new SpamFilteringTest();
exit($test->run());
