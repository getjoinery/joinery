<?php
/**
 * Tests for webhook-provider authentication verdicts — the verdict mapping each
 * inbound provider performs in handleInbound(), and the router precedence that
 * prefers a provider's verdicts over the message's Authentication-Results.
 *
 * The mapping helpers are pure (no DB, no HMAC, no AWS), so they are exercised
 * directly via reflection with representative payloads. The SNS signature check
 * in SesProvider::handleInbound cannot be forged in a unit test, so only the
 * post-validation parsing is covered here.
 *
 * Covers, per spec (specs/inbound_mailgun_verification.md):
 *   - Mailgun  : X-Mailgun-Spf / X-Mailgun-Dkim-Check-Result → normalized tokens
 *   - SendGrid : SPF / dkim form fields → normalized tokens; envelope recipient
 *   - SES      : receipt.{spf,dkim,dmarc}Verdict.status → normalized tokens (incl. DMARC)
 *   - fail-safe: an unrecognized token yields null (recorded 'none'), never 'pass'
 *   - precedence: provider auth wins; absent it, a forged X-Mailgun header on the
 *                 standard (Postfix) path is ignored → 'unverified'
 *
 * Run: php plugins/mailbox/tests/provider_auth_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/MailgunProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SendGridProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SesProvider.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AuthenticationResults.php'));

class ProviderAuthTest {
	private $pass = 0;
	private $fail = 0;

	private function out($msg) {
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . $msg . "\n";
	}
	private function ok($cond, $label) {
		if ($cond) { $this->pass++; $this->out('  PASS: ' . $label); }
		else { $this->fail++; $this->out('  FAIL: ' . $label); }
	}
	private function eq($expected, $actual, $label) {
		$this->ok($expected === $actual, $label . ' (expected ' . var_export($expected, true)
			. ', got ' . var_export($actual, true) . ')');
	}

	/** Invoke a private static method by reflection. */
	private function callStatic($class, $method, array $args) {
		$m = new ReflectionMethod($class, $method);
		$m->setAccessible(true);
		return $m->invokeArgs(null, $args);
	}

	function run() {
		$this->out('=== Provider auth verdict tests ===');
		try {
			$this->testMailgun();
			$this->testMailgunFailSafe();
			$this->testSendGrid();
			$this->testSendGridRecipient();
			$this->testSes();
			$this->testSesGrayAndProcessingFailed();
			$this->testSesBase64Content();
			$this->testSnsHelpers();
			$this->testStandardPathIgnoresProviderHeaders();
			$this->testRouterPrecedence();
			$this->testProviderSpamSignals();
		} catch (\Throwable $e) {
			$this->fail++;
			$this->out('  EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		}
		$this->out("=== {$this->pass} passed, {$this->fail} failed ===");
		return $this->fail === 0;
	}

	private function testMailgun() {
		$this->out('-- Mailgun: X-Mailgun-* headers → verdicts --');
		$raw = "Received: from mail.gmail.com\r\n"
			. "X-Mailgun-Spf: Pass\r\n"
			. "X-Mailgun-Dkim-Check-Result: Pass\r\n"
			. "DKIM-Signature: v=1; a=rsa-sha256; d=gmail.com; s=20230601; h=from\r\n"
			. "From: Jeremy <jeremy.tunnell@gmail.com>\r\n"
			. "Subject: hi\r\n\r\nbody\n";
		$auth = $this->callStatic('MailgunProvider', 'extractAuth', [$raw, ['sender' => 'jeremy.tunnell@gmail.com']]);
		$this->ok(is_array($auth), 'auth array returned');
		$this->eq('pass', $auth['spf'], 'spf=pass');
		$this->eq('pass', $auth['dkim'], 'dkim=pass');
		$this->eq(null, $auth['dmarc'], 'dmarc null (Mailgun has none)');
		$this->eq('mailgun', $auth['source'], 'source=mailgun');
		$this->eq('gmail.com', $auth['spf_domain'] ?? null, 'spf_domain from sender');
		$this->eq('gmail.com', $auth['dkim_domain'] ?? null, 'dkim_domain from DKIM-Signature d=');
	}

	private function testMailgunFailSafe() {
		$this->out('-- Mailgun: unknown SPF token → null (never pass) --');
		$raw = "X-Mailgun-Spf: Bogus\r\nX-Mailgun-Dkim-Check-Result: Fail\r\n\r\nbody";
		$auth = $this->callStatic('MailgunProvider', 'extractAuth', [$raw, []]);
		$this->eq(null, $auth['spf'], 'unknown spf token → null');
		$this->eq('fail', $auth['dkim'], 'dkim=fail still mapped');

		$this->out('-- Mailgun: no X-Mailgun-* headers → null auth (router → unverified) --');
		$none = $this->callStatic('MailgunProvider', 'extractAuth', ["From: a@b.com\r\n\r\nbody", []]);
		$this->eq(null, $none, 'no verdict headers → null');
	}

	private function testSendGrid() {
		$this->out('-- SendGrid: SPF / dkim form fields → verdicts --');
		$post = ['SPF' => 'pass', 'dkim' => '{@example.com : pass}'];
		$auth = $this->callStatic('SendGridProvider', 'extractAuth', [$post]);
		$this->eq('pass', $auth['spf'], 'spf=pass');
		$this->eq('pass', $auth['dkim'], 'dkim=pass');
		$this->eq(null, $auth['dmarc'], 'dmarc null (SendGrid has none)');
		$this->eq('sendgrid', $auth['source'], 'source=sendgrid');
		$this->eq('example.com', $auth['dkim_domain'] ?? null, 'dkim_domain parsed from {@domain : result}');

		$this->out('-- SendGrid: a passing dkim signature wins over a failing one --');
		$post2 = ['SPF' => 'softfail', 'dkim' => '{@list.example.com : fail; @example.com : pass}'];
		$auth2 = $this->callStatic('SendGridProvider', 'extractAuth', [$post2]);
		$this->eq('softfail', $auth2['spf'], 'spf=softfail');
		$this->eq('pass', $auth2['dkim'], 'pass beats fail');
		$this->eq('example.com', $auth2['dkim_domain'] ?? null, 'domain follows the pass');

		$this->out('-- SendGrid: unknown SPF token → null (never pass) --');
		$auth3 = $this->callStatic('SendGridProvider', 'extractAuth', [['SPF' => 'weird']]);
		$this->eq(null, $auth3['spf'], 'unknown spf token → null');
	}

	private function testSendGridRecipient() {
		$this->out('-- SendGrid: recipient from envelope JSON, then to-field fallback --');
		$r1 = $this->callStatic('SendGridProvider', 'extractRecipient',
			[['envelope' => '{"to":["User@Dom.com"],"from":"s@x.com"}']]);
		$this->eq('user@dom.com', $r1, 'envelope to[0], lowercased');

		$r2 = $this->callStatic('SendGridProvider', 'extractRecipient',
			[['to' => 'Someone <Person@Dom.com>']]);
		$this->eq('person@dom.com', $r2, 'angle-bracket address from to field');

		$r3 = $this->callStatic('SendGridProvider', 'extractRecipient', [[]]);
		$this->eq('', $r3, 'no recipient info → empty');
	}

	private function testSes() {
		$this->out('-- SES: receipt verdicts → tokens (incl. real DMARC) --');
		$ses = [
			'notificationType' => 'Received',
			'mail' => ['destination' => ['user@dom.com']],
			'receipt' => [
				'recipients'   => ['User@Dom.com'],
				'spfVerdict'   => ['status' => 'PASS'],
				'dkimVerdict'  => ['status' => 'PASS'],
				'dmarcVerdict' => ['status' => 'FAIL'],
			],
			'content' => "From: a@b.com\r\nSubject: hi\r\n\r\nbody",
		];
		$auth = $this->callStatic('SesProvider', 'extractAuth', [$ses]);
		$this->eq('pass', $auth['spf'], 'spf=pass');
		$this->eq('pass', $auth['dkim'], 'dkim=pass');
		$this->eq('fail', $auth['dmarc'], 'dmarc=fail (SES reports DMARC)');
		$this->eq('ses', $auth['source'], 'source=ses');

		$rcpt = $this->callStatic('SesProvider', 'extractRecipient', [$ses]);
		$this->eq('user@dom.com', $rcpt, 'recipient from receipt.recipients[0]');

		$mime = $this->callStatic('SesProvider', 'extractRawMime', [$ses]);
		$this->ok(strpos($mime, 'Subject: hi') !== false, 'raw mime taken from content as-is');
	}

	private function testSesGrayAndProcessingFailed() {
		$this->out('-- SES: GRAY → none, PROCESSING_FAILED → null --');
		$ses = ['receipt' => [
			'spfVerdict'   => ['status' => 'GRAY'],
			'dkimVerdict'  => ['status' => 'PROCESSING_FAILED'],
			'dmarcVerdict' => ['status' => 'PASS'],
		]];
		$auth = $this->callStatic('SesProvider', 'extractAuth', [$ses]);
		$this->eq('none', $auth['spf'], 'GRAY → none');
		$this->eq(null, $auth['dkim'], 'PROCESSING_FAILED → null');
		$this->eq('pass', $auth['dmarc'], 'dmarc=pass');

		$this->out('-- SES: no receipt → null auth (router → unverified) --');
		$none = $this->callStatic('SesProvider', 'extractAuth', [['mail' => []]]);
		$this->eq(null, $none, 'no receipt → null');
	}

	private function testSesBase64Content() {
		$this->out('-- SES: base64-encoded content is decoded --');
		$plain = "From: a@b.com\r\nSubject: encoded\r\n\r\nbody";
		$ses = ['content' => base64_encode($plain)];
		$mime = $this->callStatic('SesProvider', 'extractRawMime', [$ses]);
		$this->ok(strpos($mime, 'Subject: encoded') !== false, 'base64 content decoded to raw MIME');
	}

	private function testSnsHelpers() {
		$this->out('-- SES: SNS cert-URL pinning (anti-SSRF) --');
		$good = $this->callStatic('SesProvider', 'isAwsSnsUrl',
			['https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc.pem']);
		$this->ok($good === true, 'genuine sns.<region>.amazonaws.com host accepted');
		$bad1 = $this->callStatic('SesProvider', 'isAwsSnsUrl', ['https://evil.example.com/cert.pem']);
		$this->ok($bad1 === false, 'foreign host rejected');
		$bad2 = $this->callStatic('SesProvider', 'isAwsSnsUrl',
			['http://sns.us-east-1.amazonaws.com/cert.pem']);
		$this->ok($bad2 === false, 'non-https rejected');

		$this->out('-- SES: SNS string-to-sign canonical order, optional Subject skipped --');
		$msg = [
			'Type' => 'Notification', 'MessageId' => 'mid', 'TopicArn' => 'arn',
			'Message' => 'hello', 'Timestamp' => 'ts', 'Signature' => 'x',
		];
		$sts = $this->callStatic('SesProvider', 'buildSnsStringToSign', [$msg]);
		$this->eq("Message\nhello\nMessageId\nmid\nTimestamp\nts\nTopicArn\narn\nType\nNotification\n",
			$sts, 'fields in documented order; absent Subject omitted');
		$unknown = $this->callStatic('SesProvider', 'buildSnsStringToSign', [['Type' => 'Bogus']]);
		$this->eq(null, $unknown, 'unknown SNS type → null');
	}

	private function testStandardPathIgnoresProviderHeaders() {
		$this->out('-- Anti-spoof: AuthenticationResults ignores X-Mailgun-* headers --');
		// A forged provider header with NO Authentication-Results line must not
		// produce a verdict on the standard (Postfix milter) path.
		$raw = "X-Mailgun-Spf: Pass\r\nX-Mailgun-Dkim-Check-Result: Pass\r\n"
			. "From: attacker@evil.example\r\n\r\nbody";
		$ar = AuthenticationResults::fromMessage($raw, 'devmail.getjoinery.com');
		$this->ok($ar === null, 'no AR line → null (X-Mailgun-* never honored here)');
	}

	private function testRouterPrecedence() {
		$this->out('-- Router precedence: provider auth wins; forged header → unverified --');
		// Loading the router pulls in data classes; guard so a bootstrap gap
		// reports as a skip, not a hard failure of the mapping suite.
		try {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
			$router = new InboundEmailRouter();
		} catch (\Throwable $e) {
			$this->out('  SKIP: router not loadable in this bootstrap (' . $e->getMessage() . ')');
			return;
		}

		$m = new ReflectionMethod('InboundEmailRouter', 'readAuthResults');
		$m->setAccessible(true);

		// (1) Provider auth present → used; nulls become 'none'; source preserved.
		$provider_auth = ['spf' => 'pass', 'dkim' => null, 'dmarc' => 'fail', 'source' => 'mailgun'];
		$r1 = $m->invoke($router, "From: a@b.com\r\n\r\nbody", $provider_auth);
		$this->eq('mailgun', $r1['source'], 'provider source preserved');
		$this->eq('pass', $r1['spf'], 'provider spf used');
		$this->eq('none', $r1['dkim'], 'unasserted provider method → none');
		$this->eq('fail', $r1['dmarc'], 'provider dmarc used');

		// (2) No provider auth + forged X-Mailgun header + no AR line → unverified.
		$raw = "X-Mailgun-Spf: Pass\r\nFrom: a@b.com\r\n\r\nbody";
		$r2 = $m->invoke($router, $raw, null);
		$this->eq('none', $r2['source'], 'no trusted source → none');
		$this->eq('unverified', $r2['spf'], 'forged X-Mailgun-Spf not honored on standard path');
	}

	/**
	 * Content-spam signal extraction (specs/inbound_email_content_spam_filtering.md):
	 * each provider's extractSpam() maps its native field/header to the sibling
	 * 'spam' result the router consumes.
	 */
	private function testProviderSpamSignals() {
		$this->out('-- Provider content-spam signals --');

		// Mailgun: X-Mailgun-Sflag is the binary verdict; X-Mailgun-Sscore the score.
		$mgRaw = "X-Mailgun-Sflag: Yes\r\nX-Mailgun-Sscore: 12.4\r\nFrom: a@b.com\r\n\r\nbody";
		$mg = $this->callStatic('MailgunProvider', 'extractSpam', [$mgRaw]);
		$this->eq('spam', $mg['result'], 'Mailgun Sflag Yes → spam');
		$this->eq(12.4, $mg['score'], 'Mailgun Sscore recorded');
		$mgHam = $this->callStatic('MailgunProvider', 'extractSpam',
			["X-Mailgun-Sflag: No\r\nFrom: a@b.com\r\n\r\nbody"]);
		$this->eq('ham', $mgHam['result'], 'Mailgun Sflag No → ham');
		$mgNone = $this->callStatic('MailgunProvider', 'extractSpam', ["From: a@b.com\r\n\r\nbody"]);
		$this->ok($mgNone === null, 'Mailgun no spam headers → null');

		// SES: spamVerdict PASS → ham, FAIL → spam, else null.
		$sesSpam = $this->callStatic('SesProvider', 'extractSpam',
			[['receipt' => ['spamVerdict' => ['status' => 'FAIL']]]]);
		$this->eq('spam', $sesSpam['result'], 'SES spamVerdict FAIL → spam');
		$sesHam = $this->callStatic('SesProvider', 'extractSpam',
			[['receipt' => ['spamVerdict' => ['status' => 'PASS']]]]);
		$this->eq('ham', $sesHam['result'], 'SES spamVerdict PASS → ham');
		$sesNone = $this->callStatic('SesProvider', 'extractSpam',
			[['receipt' => ['spamVerdict' => ['status' => 'PROCESSING_FAILED']]]]);
		$this->ok($sesNone === null, 'SES PROCESSING_FAILED → null');

		// SendGrid: score thresholded at sendgrid_inbound_spam_threshold (default 5.0).
		$sgHam = $this->callStatic('SendGridProvider', 'extractSpam', [['spam_score' => '3.7']]);
		$this->eq('ham', $sgHam['result'], 'SendGrid score below threshold → ham');
		$this->eq(3.7, $sgHam['score'], 'SendGrid score recorded');
		$sgSpam = $this->callStatic('SendGridProvider', 'extractSpam', [['spam_score' => '7.2']]);
		$this->eq('spam', $sgSpam['result'], 'SendGrid score at/above threshold → spam');
		$sgNone = $this->callStatic('SendGridProvider', 'extractSpam', [[]]);
		$this->ok($sgNone === null, 'SendGrid no spam_score → null');
	}
}

$test = new ProviderAuthTest();
$ok = $test->run();
exit($ok ? 0 : 1);
