<?php
/** @joinery-test
 * name: spam_filtering
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Tests for inbound spam classification (specs/inbound_email_spam_filtering.md).
 *
 * Exercises InboundEmailRouter::classifySpam() directly via reflection with
 * representative auth-verdict arrays — the method is pure given the settings gate,
 * so no DB write or message ingest is needed. The gate
 * (mailbox_spam_filtering_enabled) is toggled by injecting into the
 * Globalvars singleton's private settings (the same instance the router reads).
 *
 * Covers:
 *   - gate off → null verdict (behavior unchanged)
 *   - primary rule: DMARC fail → spam; DMARC pass → ham
 *   - no-DMARC fallback: SPF+DKIM both fail → spam; only one fails → ham;
 *     both pass → ham
 *   - the fallback fires for 'none' AND 'unverified' DMARC (Mailgun/SendGrid shape)
 *   - content layer (specs/mailbox_spam_filtering_simplification.md): classifySpam
 *     OR semantics, readSpamHeader parsing, and that resolveContentSpam reads an
 *     arriving verdict whether or not this box runs its own scanner
 *   - ingest-time re-scan, where something upstream scanned and a scanner runs
 *     here. Learning OFF: the scan runs and can only ADD — local spam fires on a
 *     message the upstream let through, but local ham never overturns an upstream
 *     spam verdict. Learning ON: the local verdict REPLACES the upstream one in
 *     BOTH directions, so local ham RESCUES a message the upstream flagged. A
 *     scanner that is down leaves the upstream verdict standing; a box with no
 *     scanner at all is never called.
 *   - the /checkv2 response reading, including the score fallback and garbage
 *
 * The ingest scan is exercised through a router subclass that substitutes the
 * transport, so the test needs no rspamd; the response READING is tested against
 * literal rspamd bodies. Scanner PRESENCE is pinned with
 * MailboxSpamPolicy::overrideScannerAvailable() rather than probed, so the same
 * assertions hold on a box that happens to be running rspamd and on one that is
 * not. Topology stays out of it — the webhook provider makes "something upstream
 * scanned" true from settings alone, so this stays a safe (no DB write, no host
 * state) test. The topology matrix is spam_policy_test.
 *
 * Run: php plugins/mailbox/tests/spam_filtering_test.php
 *
 * @version 1.4
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

/**
 * Router with a scripted local scanner. $scan_result is what the substituted
 * transport returns — an array to stand in for a successful scan, null for a
 * scanner that is missing, down or unreadable.
 */
class ScriptedScanRouter extends InboundEmailRouter {
	public $scan_result = null;
	public $scan_calls = 0;
	protected function scanContentSpam(string $raw_email): ?array {
		$this->scan_calls++;
		return $this->scan_result;
	}
}

class SpamFilteringTest {

	private function out($msg) {
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . $msg . "\n";
	}
	private function eq($expected, $actual, $label) {
		return check($expected === $actual, $label, 'expected ' . var_export($expected, true)
			. ', got ' . var_export($actual, true));
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
		$this->setSetting('mailbox_spam_filtering_enabled', $on ? '1' : '0');
	}

	/** Force the learning switch (and with it the local scanner) on/off. */
	private function setLearning(bool $on): void {
		$this->setSetting('mailbox_spam_learning_enabled', $on ? '1' : '0');
		MailboxSpamPolicy::reset();
	}

	/**
	 * Put the deployment behind a webhook provider (or back on local Postfix).
	 * A webhook provider makes "something upstream already scanned" true without
	 * any relay row, which is what keeps the ingest-scan cases DB-free.
	 */
	private function setUpstreamScanned(bool $on): void {
		$this->setSetting('mailbox_provider', $on ? 'mailgun' : 'postfix');
		MailboxSpamPolicy::reset();
	}

	/** Invoke resolveContentSpam() on a router with a scripted local scanner. */
	private function resolveWithScan($scan_result, string $raw, $provider_spam = null): array {
		$router = new ScriptedScanRouter();
		$router->scan_result = $scan_result;
		$m = new ReflectionMethod('InboundEmailRouter', 'resolveContentSpam');
		$m->setAccessible(true);
		return $m->invoke($router, $raw, $provider_spam);
	}

	/** Invoke the router's private interpretScanResponse() on a literal body. */
	private function interpret(string $body) {
		$router = new InboundEmailRouter();
		$m = new ReflectionMethod('InboundEmailRouter', 'interpretScanResponse');
		$m->setAccessible(true);
		return $m->invoke($router, $body);
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
		section('Spam classification');

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
		section('readSpamHeader');
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

		// --- resolveContentSpam(): an arriving verdict is always read ---
		section('resolveContentSpam');
		$hamBody = "From: a@b.com\nSubject: hi\n\nbody";
		// No scanner here, so this section reads arriving verdicts only — pinned
		// rather than probed so it asserts the same on any box (and never fires a
		// real HTTP scan from a test).
		MailboxSpamPolicy::overrideScannerAvailable(false);
		$this->setLearning(false);
		$this->eq('spam', $this->resolveContentSpam($spamRaw)['signal'],
			'no local scanner → the relay-stamped X-Spam header is still read');
		$this->setLearning(true);
		$this->setUpstreamScanned(false); // colocated: the milter already scored it
		$this->eq('spam', $this->resolveContentSpam($spamRaw)['signal'],
			'local scanner on → same reading, same result');
		$this->setLearning(false);
		$this->eq('none', $this->resolveContentSpam($hamBody)['signal'],
			'no scanner verdict on the message → none');
		// Webhook provider signal arrives as a sibling argument.
		$prov = $this->resolveContentSpam('', array('result' => 'spam', 'score' => 4.2, 'source' => 'mailgun'));
		$this->eq('spam', $prov['signal'], 'provider spam signal → spam');
		$this->eq(4.2, $prov['score'], 'provider score recorded');
		$provNone = $this->resolveContentSpam('', array('result' => 'none', 'score' => 1.0, 'source' => 'sendgrid'));
		$this->eq('none', $provNone['signal'], 'provider result=none → none (score recorded, no flag)');
		$this->eq(1.0, $provNone['score'], 'provider score still recorded');

		// --- ingest-time re-scan ---
		section('ingest re-scan');
		$LOCAL_SPAM = array('signal' => 'spam', 'score' => 11.5);
		$LOCAL_HAM  = array('signal' => 'ham',  'score' => -1.2);
		MailboxSpamPolicy::overrideScannerAvailable(true);

		// Learning off: the scan still RUNS — a stateless upstream's header may
		// never have been stamped, which is indistinguishable from a clean
		// verdict — but its answer can only ADD spam.
		$this->setUpstreamScanned(true);
		$this->setLearning(false);
		$r = $this->resolveWithScan($LOCAL_SPAM, $hamBody);
		$this->eq('spam', $r['signal'], 'learning off → local scan still adds spam the upstream missed');
		$this->eq(11.5, $r['score'], 'the local score is recorded when the local scan is what fired');

		// ...and it must NOT subtract. Without a corpus the local scan is the same
		// static ruleset the upstream ran, minus the milter's live SMTP context.
		$r = $this->resolveWithScan($LOCAL_HAM, $spamRaw);
		$this->eq('spam', $r['signal'], 'learning off → local ham never overturns an upstream spam verdict');
		$this->eq(7.31, $r['score'], 'the upstream score stands: it is what decided the disposition');
		$r = $this->resolveWithScan($LOCAL_HAM, $hamBody,
			array('result' => 'spam', 'score' => 9.9, 'source' => 'mailgun'));
		$this->eq('spam', $r['signal'], 'learning off → nor does it overturn a webhook provider flag');

		// A colocated box is never re-scanned even with a scanner right there:
		// its own milter already ran exactly this scan.
		$this->setUpstreamScanned(false);
		$router = new ScriptedScanRouter();
		$router->scan_result = $LOCAL_SPAM;
		$m = new ReflectionMethod('InboundEmailRouter', 'resolveContentSpam');
		$m->setAccessible(true);
		$m->invoke($router, $hamBody, null);
		check($router->scan_calls === 0, 'colocated → the scanner is not called',
			'scan_calls = ' . $router->scan_calls);
		$this->setUpstreamScanned(true);

		// Learning on, something upstream scanned: the local verdict wins.
		$this->setLearning(true);
		$r = $this->resolveWithScan($LOCAL_SPAM, $hamBody);
		$this->eq('spam', $r['signal'], 'local scan says spam → overrides an upstream that said nothing');
		$this->eq(11.5, $r['score'], 'the local score is the one recorded');

		// The direction that only a local corpus can produce: rescuing a message
		// the upstream static ruleset flagged. This is the whole reason the local
		// verdict replaces rather than OR's.
		$r = $this->resolveWithScan($LOCAL_HAM, $spamRaw);
		$this->eq('ham', $r['signal'], 'local scan says ham → RESCUES a message the relay flagged');
		$this->eq(-1.2, $r['score'], 'the rescuing scan\'s score is recorded');

		// Same rescue against a webhook provider's own flag.
		$r = $this->resolveWithScan($LOCAL_HAM, $hamBody,
			array('result' => 'spam', 'score' => 9.9, 'source' => 'mailgun'));
		$this->eq('ham', $r['signal'], 'local scan overrides a webhook provider spam flag too');
		// ...but a provider payload carrying no raw message cannot be re-scanned,
		// so its own flag is all there is.
		$r = $this->resolveWithScan($LOCAL_HAM, '',
			array('result' => 'spam', 'score' => 9.9, 'source' => 'mailgun'));
		$this->eq('spam', $r['signal'], 'no raw to scan → the provider flag stands');

		// Scanner missing or down: the message keeps whatever arrived with it and
		// stores normally. Nothing is held, bounced or retried.
		$r = $this->resolveWithScan(null, $spamRaw);
		$this->eq('spam', $r['signal'], 'scanner down → the upstream verdict stands');
		$r = $this->resolveWithScan(null, $hamBody);
		$this->eq('none', $r['signal'], 'scanner down on an unflagged message → none');

		// An empty raw is nothing to scan; do not spend a request on it.
		$router = new ScriptedScanRouter();
		$router->scan_result = $LOCAL_SPAM;
		$m = new ReflectionMethod('InboundEmailRouter', 'resolveContentSpam');
		$m->setAccessible(true);
		$m->invoke($router, '', null);
		check($router->scan_calls === 0, 'empty raw → the scanner is not called',
			'scan_calls = ' . $router->scan_calls);

		// Colocated: the milter already ran this exact scan, so ingest does not
		// repeat it even with learning on.
		$this->setUpstreamScanned(false);
		$router = new ScriptedScanRouter();
		$router->scan_result = $LOCAL_SPAM;
		$m->invoke($router, $hamBody, null);
		check($router->scan_calls === 0,
			'colocated + learning on → no ingest re-scan (the milter already scored it)',
			'scan_calls = ' . $router->scan_calls);

		// No scanner on the box: the posture still says "should", but nothing is
		// attempted — a webhook-only deployment must not spend a failed request
		// and an error_log line on every message it receives.
		$this->setUpstreamScanned(true);
		MailboxSpamPolicy::overrideScannerAvailable(false);
		$router = new ScriptedScanRouter();
		$router->scan_result = $LOCAL_SPAM;
		$m->invoke($router, $hamBody, null);
		check($router->scan_calls === 0, 'no scanner running → the scanner is not called',
			'scan_calls = ' . $router->scan_calls);
		check(MailboxSpamPolicy::scanAtIngest() === true,
			'...though the posture still reads "scan" — presence is observed, not policy');

		// Filing off short-circuits the whole feature, scanning included.
		$this->setGate(false);
		MailboxSpamPolicy::overrideScannerAvailable(true);
		check(MailboxSpamPolicy::scanAtIngest() === false,
			'filing off → no ingest scan (no verdict is recorded, so none could matter)');
		$this->setGate(true);
		MailboxSpamPolicy::overrideScannerAvailable(null);

		// --- reading an rspamd /checkv2 response ---
		section('interpretScanResponse');
		$r = $this->interpret('{"score":12.4,"required_score":6.0,"action":"add header"}');
		$this->eq('spam', $r['signal'], 'action=add header → spam');
		$this->eq(12.4, $r['score'], 'score read from the response');
		$this->eq('spam', $this->interpret('{"score":30,"action":"reject"}')['signal'],
			'action=reject → spam (an operator may have re-enabled it)');
		$this->eq('spam', $this->interpret('{"score":8,"action":"rewrite subject"}')['signal'],
			'action=rewrite subject → spam');
		$this->eq('ham', $this->interpret('{"score":0.4,"required_score":6.0,"action":"no action"}')['signal'],
			'action=no action → ham (an assertion the X-Spam header never makes)');
		$this->eq('ham', $this->interpret('{"score":2.0,"action":"greylist"}')['signal'],
			'action=greylist → ham (not a spam disposition here)');
		// Score fallback for a response without an action.
		$this->eq('spam', $this->interpret('{"score":7.5,"required_score":6.0}')['signal'],
			'no action + score at or over required → spam');
		$this->eq('ham', $this->interpret('{"score":5.9,"required_score":6.0}')['signal'],
			'no action + score under required → ham');
		$this->eq('spam', $this->interpret('{"score":6.0,"required_score":6.0}')['signal'],
			'no action + score exactly at required → spam');
		// Unreadable bodies must yield null so the caller falls back, never a guess.
		$this->eq(null, $this->interpret('not json at all'), 'garbage body → null');
		$this->eq(null, $this->interpret('{"error":"scan failed"}'), 'no score in the body → null');
		$this->eq(null, $this->interpret('{"score":"high"}'), 'non-numeric score → null');
		$this->eq(null, $this->interpret(''), 'empty body → null');

		$this->setLearning(false);
		$this->setUpstreamScanned(false);

		// --- what the plugin manifest declares ---
		// The factory defaults ARE the behavior on a fresh deployment, so they
		// are worth asserting: a default that quietly reverts to 0 would file
		// spam straight into the inbox on every new install.
		section('declared settings');
		$manifest = json_decode((string)file_get_contents(
			PathHelper::getAbsolutePath('plugins/mailbox/plugin.json')), true);
		$declared = array();
		foreach (($manifest['settings'] ?? array()) as $s) {
			$declared[(string)($s['name'] ?? '')] = (string)($s['default'] ?? '');
		}
		$this->eq('1', $declared['mailbox_spam_filtering_enabled'] ?? null,
			'mailbox_spam_filtering_enabled ships on');
		$this->eq('0', $declared['mailbox_spam_learning_enabled'] ?? null,
			'mailbox_spam_learning_enabled ships off (it costs a scanner)');
		check(!array_key_exists('mailbox_content_spam_filtering_enabled', $declared),
			'the conflated content-scanner setting is gone from the manifest');
		$this->eq('http://127.0.0.1:11334',
			$declared['mailbox_rspamd_controller_url'] ?? null,
			'the controller endpoint stays loopback');

		// The health entry must point at the script that can actually fix it.
		$prov = array();
		foreach (($manifest['provisioners'] ?? array()) as $p) {
			$prov[(string)($p['key'] ?? '')] = $p;
		}
		check(isset($prov['content_spam_scanner']), 'the scanner health entry exists');
		$this->eq('provisioning/provision_spam_scanner.sh',
			$prov['content_spam_scanner']['script'] ?? null,
			'its fix command is the standalone scanner provisioner');
	}
}

$test = new SpamFilteringTest();
$test->run();
harness_finish();
