<?php
/**
 * Tests for AuthenticationResults — reading SPF/DKIM/DMARC verdicts off a
 * message's Authentication-Results header.
 *
 * Pure parser, no DB. Covers: single multi-method line, oversigned/multi-dkim
 * (a pass wins), two lines merged (opendkim + opendmarc), authserv-id trust
 * (a forged upstream line is ignored), header folding, and the
 * no-trusted-line / empty-authserv-id => null cases.
 *
 * Run: php plugins/mailbox/tests/authentication_results_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AuthenticationResults.php'));

class AuthenticationResultsTest {
	private $pass = 0;
	private $fail = 0;

	const AUTHSERV = 'devmail.getjoinery.com';

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

	/** Assemble a raw message: header lines + blank line + body. */
	private function msg(array $header_lines, $body = "hello body\n") {
		return implode("\r\n", $header_lines) . "\r\n\r\n" . $body;
	}

	function run() {
		$this->out('=== AuthenticationResults tests ===');
		try {
			$this->testSingleLineAllMethods();
			$this->testOversignedMultiDkim();
			$this->testTwoLinesMerged();
			$this->testForgedUpstreamIgnored();
			$this->testNoArLineIsNull();
			$this->testEmptyAuthservIsNull();
			$this->testForeignOnlyIsNull();
			$this->testMethodAbsentIsNull();
		} catch (\Throwable $e) {
			$this->fail++;
			$this->out('  EXCEPTION: ' . $e->getMessage());
		}
		$this->out("=== {$this->pass} passed, {$this->fail} failed ===");
		return $this->fail === 0;
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
		$this->out('-- two AR lines (opendkim + opendmarc), same authserv-id, merged --');
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
$ok = $test->run();
exit($ok ? 0 : 1);
