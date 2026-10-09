<?php
/** @joinery-test
 * name: authentication_results
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Tests for AuthenticationResults — reading SPF/DKIM/DMARC verdicts off a
 * message's Authentication-Results header.
 *
 * Pure parser, no DB. Covers: single multi-method line, oversigned/multi-dkim
 * (a pass wins), two lines merged, lines exactly as rspamd stamps them, authserv-id trust
 * (a forged upstream line is ignored), header folding, and the
 * no-trusted-line / empty-authserv-id => null cases.
 *
 * Run: php plugins/mailbox/tests/authentication_results_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AuthenticationResults.php'));

class AuthenticationResultsTest {
	const AUTHSERV = 'devmail.getjoinery.com';

	private function out($msg) {
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . $msg . "\n";
	}
	private function ok($cond, $label) {
		return check((bool)$cond, $label);
	}
	private function eq($expected, $actual, $label) {
		$this->ok($expected === $actual, $label . ' (expected ' . var_export($expected, true)
			. ', got ' . var_export($actual, true) . ')');
	}

	/** Assemble a raw message: header lines + blank line + body. */
	private function msg(array $header_lines, $body = "hello body\n") {
		return implode("\r\n", $header_lines) . "\r\n\r\n" . $body;
	}

	function run() {
		section('AuthenticationResults tests');
		try {
			$this->testSingleLineAllMethods();
			$this->testOversignedMultiDkim();
			$this->testTwoLinesMerged();
			$this->testRspamdLines();
			$this->testForgedUpstreamIgnored();
			$this->testNoArLineIsNull();
			$this->testEmptyAuthservIsNull();
			$this->testForeignOnlyIsNull();
			$this->testMethodAbsentIsNull();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage());
		}
	}

	private function testSingleLineAllMethods() {
		$this->out('-- single line, all three methods, folded --');
		$raw = $this->msg([
			'Authentication-Results: ' . self::AUTHSERV . ';',
			"\tdkim=pass header.d=gmail.com header.s=20230601;",
			"\tspf=pass smtp.mailfrom=jeremy.tunnell@gmail.com;",
			"\tdmarc=pass header.from=gmail.com",
			'From: Jeremy <jeremy.tunnell@gmail.com>',
			'Subject: hi',
		]);
		$ar = AuthenticationResults::fromMessage($raw, self::AUTHSERV);
		$this->ok($ar !== null, 'parser returns an object');
		$this->eq('pass', $ar->dkim(), 'dkim=pass');
		$this->eq('pass', $ar->spf(), 'spf=pass');
		$this->eq('pass', $ar->dmarc(), 'dmarc=pass');
		$this->eq('gmail.com', $ar->dkimDomain(), 'dkim header.d');
		$this->eq('jeremy.tunnell@gmail.com', $ar->spfDomain(), 'spf smtp.mailfrom');
	}

	private function testOversignedMultiDkim() {
		$this->out('-- multiple dkim= entries: a pass wins, domain follows the pass --');
		$raw = $this->msg([
			'Authentication-Results: ' . self::AUTHSERV . ';',
			"\tdkim=fail header.d=list.example.com;",
			"\tdkim=pass header.d=gmail.com",
		]);
		$ar = AuthenticationResults::fromMessage($raw, self::AUTHSERV);
		$this->eq('pass', $ar->dkim(), 'pass beats fail');
		$this->eq('gmail.com', $ar->dkimDomain(), 'domain from the passing signature');
	}

	private function testTwoLinesMerged() {
		$this->out('-- two AR lines, same authserv-id, merged --');
		$raw = $this->msg([
			'Authentication-Results: ' . self::AUTHSERV . ';',
			"\tdkim=pass header.d=example.com",
			'Authentication-Results: ' . self::AUTHSERV . ';',
			"\tspf=pass smtp.mailfrom=sender@example.com;",
			"\tdmarc=pass header.from=example.com",
		]);
		$ar = AuthenticationResults::fromMessage($raw, self::AUTHSERV);
		$this->ok($ar !== null, 'object returned');
		$this->eq('pass', $ar->dkim(),  'dkim from line 1');
		$this->eq('pass', $ar->spf(),   'spf from line 2');
		$this->eq('pass', $ar->dmarc(), 'dmarc from line 2');
	}

	/**
	 * Lines exactly as rspamd 3.8.1 stamped them on mail stored on dev: one
	 * line carrying all three verdicts, a comment after spf and after dmarc, a
	 * quoted envelope sender with '+' and '=' in it, and reason="..." on a
	 * failure. A change in rspamd's wording shows up here, not as silently
	 * "unverified" mail.
	 */
	private function testRspamdLines() {
		$this->out('-- rspamd: everything passes, quoted envelope sender, bracketed comments --');
		$raw = $this->msg([
			'Authentication-Results: devmail.getjoinery.com;',
			"\tdkim=pass header.d=mg.dev.getjoinery.com header.s=mx header.b=pgxrQGaj;",
			"\tspf=pass (devmail.getjoinery.com: domain of \"bounce+50a713.b8f8c1a-chat=dev.getjoinery.com@mg.dev.getjoinery.com\" designates 204.220.184.30 as permitted sender) smtp.mailfrom=\"bounce+50a713.b8f8c1a-chat=dev.getjoinery.com@mg.dev.getjoinery.com\";",
			"\tdmarc=pass (policy=none) header.from=dev.getjoinery.com",
			'From: Chat <chat@dev.getjoinery.com>',
		]);
		$ar = AuthenticationResults::fromMessage($raw, 'devmail.getjoinery.com');
		$this->ok($ar !== null, 'rspamd pass line is read');
		$this->eq('pass', $ar->dkim(), 'rspamd dkim=pass');
		$this->eq('pass', $ar->spf(), 'rspamd spf=pass, comment before the property');
		$this->eq('pass', $ar->dmarc(), 'rspamd dmarc=pass, comment before the property');
		$this->eq('mg.dev.getjoinery.com', $ar->dkimDomain(), 'rspamd dkim header.d');

		$this->out('-- rspamd: nothing signed, SPF fails, DMARC fails with a reason --');
		$raw = $this->msg([
			'Authentication-Results: devmail.getjoinery.com;',
			"\tdkim=none;",
			"\tspf=fail (devmail.getjoinery.com: domain of probe@getjoinery.com does not designate 69.164.209.253 as permitted sender) smtp.mailfrom=probe@getjoinery.com;",
			"\tdmarc=fail reason=\"No valid SPF, No valid DKIM\" header.from=getjoinery.com (policy=quarantine)",
			'From: Probe <probe@getjoinery.com>',
		]);
		$ar = AuthenticationResults::fromMessage($raw, 'devmail.getjoinery.com');
		$this->ok($ar !== null, 'rspamd fail line is read');
		$this->eq('none', $ar->dkim(), 'rspamd dkim=none');
		$this->eq('fail', $ar->spf(), 'rspamd spf=fail');
		$this->eq('fail', $ar->dmarc(), 'rspamd dmarc=fail with reason="..." and a trailing comment');
		$this->eq('probe@getjoinery.com', $ar->spfDomain(), 'rspamd spf smtp.mailfrom');
		$this->eq('quarantine', $ar->dmarcPolicy(), 'rspamd dmarc policy is read from (policy=…)');

		$this->out('-- rspamd: a DMARC fail under p=none (Ghost newsletter, ghost.io is a public suffix) --');
		$raw = $this->msg([
			'Authentication-Results: devmail.getjoinery.com;',
			"\tdkim=pass header.d=m.ghost.io header.s=mailgun header.b=lRKDFgHL;",
			"\tspf=pass (devmail.getjoinery.com: domain of \"bounce+8329f7@m.ghost.io\" designates 159.112.253.179 as permitted sender) smtp.mailfrom=\"bounce+8329f7@m.ghost.io\";",
			"\tdmarc=fail reason=\"SPF not aligned (relaxed), DKIM not aligned (relaxed)\" header.from=ghost.io (policy=none)",
			'From: Gil Duran <nerdreich@ghost.io>',
		]);
		$ar = AuthenticationResults::fromMessage($raw, 'devmail.getjoinery.com');
		$this->eq('fail', $ar->dmarc(), 'the verdict is still fail');
		$this->eq('none', $ar->dmarcPolicy(), 'and the policy it came with is none');
		$this->eq(array('spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'fail', 'dmarc_policy' => 'none',
			'dkim_domain' => 'm.ghost.io', 'spf_domain' => 'bounce+8329f7@m.ghost.io'), $ar->toArray(),
			'toArray carries the policy beside the verdicts');

		$this->out('-- rspamd: two signatures on one line, one passes --');
		$raw = $this->msg([
			'Authentication-Results: devmail.getjoinery.com;',
			"\tdkim=fail (\"body hash did not verify\") header.d=list.example.com header.s=s1 header.b=AAAAAAAA;",
			"\tdkim=pass header.d=example.com header.s=mail header.b=BBBBBBBB;",
			"\tspf=pass (devmail.getjoinery.com: domain of bounce@list.example.com designates 203.0.113.9 as permitted sender) smtp.mailfrom=bounce@list.example.com;",
			"\tdmarc=pass (policy=reject) header.from=example.com",
		]);
		$ar = AuthenticationResults::fromMessage($raw, 'devmail.getjoinery.com');
		$this->eq('pass', $ar->dkim(), 'rspamd: a passing signature wins over a failing one');
		$this->eq('example.com', $ar->dkimDomain(), 'rspamd: the domain is the passing signature\'s');

		$this->out('-- a line in our name that rspamd left in place still cannot outvote its own --');
		// rspamd strips every arriving line before stamping (milter_headers.conf,
		// remove = 0), so one message never carries both. If one ever did, the
		// forged pass must not turn rspamd's fail into a pass for SPF or DMARC.
		$raw = $this->msg([
			'Authentication-Results: devmail.getjoinery.com;',
			"\tdkim=none;",
			"\tspf=fail (devmail.getjoinery.com: domain of x@example.com does not designate 203.0.113.9 as permitted sender) smtp.mailfrom=x@example.com;",
			"\tdmarc=fail reason=\"No valid SPF, No valid DKIM\" header.from=example.com (policy=reject)",
			'Authentication-Results: other-host.example.net; spf=pass smtp.mailfrom=x@example.com; dmarc=pass header.from=example.com',
		]);
		$ar = AuthenticationResults::fromMessage($raw, 'devmail.getjoinery.com');
		$this->eq('fail', $ar->spf(), 'a line under another name does not change spf');
		$this->eq('fail', $ar->dmarc(), 'a line under another name does not change dmarc');
	}

	private function testForgedUpstreamIgnored() {
		$this->out('-- forged upstream line (foreign authserv-id) is ignored --');
		$raw = $this->msg([
			'Authentication-Results: evil-relay.example.net; dkim=pass header.d=phish.example',
			'Authentication-Results: ' . self::AUTHSERV . '; dkim=fail header.d=phish.example',
		]);
		$ar = AuthenticationResults::fromMessage($raw, self::AUTHSERV);
		$this->ok($ar !== null, 'object returned (our line matched)');
		$this->eq('fail', $ar->dkim(), 'our fail wins; forged pass ignored');
	}

	private function testNoArLineIsNull() {
		$this->out('-- no Authentication-Results header => null --');
		$raw = $this->msg(['From: a@b.com', 'Subject: nope']);
		$ar = AuthenticationResults::fromMessage($raw, self::AUTHSERV);
		$this->ok($ar === null, 'null when no AR header present');
	}

	private function testEmptyAuthservIsNull() {
		$this->out('-- empty authserv-id (unconfigured) => trust nothing => null --');
		$raw = $this->msg([
			'Authentication-Results: ' . self::AUTHSERV . '; dkim=pass header.d=x.com',
		]);
		$ar = AuthenticationResults::fromMessage($raw, '');
		$this->ok($ar === null, 'null when our authserv-id is empty');
	}

	private function testForeignOnlyIsNull() {
		$this->out('-- only a foreign authserv-id line => null --');
		$raw = $this->msg([
			'Authentication-Results: mx.google.com; dkim=pass header.d=x.com; spf=pass smtp.mailfrom=a@x.com',
		]);
		$ar = AuthenticationResults::fromMessage($raw, self::AUTHSERV);
		$this->ok($ar === null, 'null when no line carries our authserv-id');
	}

	private function testMethodAbsentIsNull() {
		$this->out('-- our line present but a method absent => that getter is null --');
		$raw = $this->msg([
			'Authentication-Results: ' . self::AUTHSERV . '; dkim=pass header.d=x.com',
		]);
		$ar = AuthenticationResults::fromMessage($raw, self::AUTHSERV);
		$this->eq('pass', $ar->dkim(), 'dkim present');
		$this->ok($ar->spf() === null, 'spf null (not asserted)');
		$this->ok($ar->dmarc() === null, 'dmarc null (not asserted)');
	}
}

$test = new AuthenticationResultsTest();
$test->run();
harness_finish();
