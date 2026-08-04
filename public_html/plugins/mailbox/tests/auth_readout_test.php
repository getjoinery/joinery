<?php
/** @joinery-test
 * name: auth_readout
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * InboundEmailMessage::authIsVerified() / authReadout() — the one place a stored
 * iem_auth_source becomes "do these verdicts mean anything, and what do they
 * mean to a person".
 *
 * This exists because of a specific defect: three display surfaces (the Mailbox
 * reader, the admin message page, the send-test tool) each kept their own
 * hardcoded `source === 'milter' || source === 'mailgun'` list. The router had
 * long since added 'relay', 'sendgrid' and 'ses', so relay-fronted deployments
 * showed "Authentication: unverified (no verifying milter)" on every message
 * their relay had fully verified. The first section below is the regression
 * guard: EVERY source the router can write must read as verified, asserted
 * against the router's own list rather than a copy of it.
 *
 * Also covered:
 *   - the four states (verified / failed / partial / unchecked)
 *   - DMARC outranks the SPF+DKIM pair in both directions
 *   - an unchecked message explains WHY, and the reason follows the ingest path
 *     (archive import, IMAP poll, or neither) — the commonest cause is benign
 *   - the readout never leaks a checker name it cannot name
 *
 * Run: php plugins/mailbox/tests/auth_readout_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

class AuthReadoutTest {

	private function eq($expected, $actual, $label) {
		return check($expected === $actual, $label, 'expected ' . var_export($expected, true)
			. ', got ' . var_export($actual, true));
	}

	/** Shorthand: readout for a fully-passing message from $source. */
	private function passing(string $source): array {
		return InboundEmailMessage::authReadout($source, 'pass', 'pass', 'pass');
	}

	function run() {
		// --- The regression guard --------------------------------------------
		// Every source the router writes must count as verified. 'relay' is the
		// one that was missing everywhere; 'sendgrid'/'ses' were missing too.
		section('every router-written source counts as verified');
		foreach (array('milter', 'relay', 'mailgun', 'sendgrid', 'ses') as $src) {
			check(InboundEmailMessage::authIsVerified($src) === true,
				"'$src' is a trusted verifier");
			$this->eq('verified', $this->passing($src)['state'],
				"'$src' + all pass → verified");
			check($this->passing($src)['checked_by'] !== null,
				"'$src' names who checked it");
		}
		// ...and nothing else does.
		foreach (array('none', '', 'imap', 'nonsense', 'MILTER_X') as $src) {
			check(InboundEmailMessage::authIsVerified($src) === false,
				var_export($src, true) . ' is not a trusted verifier');
		}
		// Case and stray whitespace must not decide trust — sources are written by
		// several paths and read back as raw column text.
		check(InboundEmailMessage::authIsVerified('  Relay  ') === true,
			'source matching tolerates case and padding');
		check(InboundEmailMessage::authIsVerified(null) === false,
			'a NULL source is not verified');

		// --- The four states ---------------------------------------------------
		section('states');
		$this->eq('verified', InboundEmailMessage::authReadout('relay', 'pass', 'pass', 'pass')['state'],
			'all pass → verified');
		// DMARC is alignment-based and subsumes the other two, so it decides alone.
		$this->eq('verified', InboundEmailMessage::authReadout('relay', 'fail', 'fail', 'pass')['state'],
			'DMARC pass outranks SPF+DKIM failing → verified');
		$this->eq('failed', InboundEmailMessage::authReadout('relay', 'pass', 'pass', 'fail')['state'],
			'DMARC fail outranks SPF+DKIM passing → failed');
		// No DMARC verdict: fall back to the pair, and only a clean sweep decides.
		$this->eq('verified', InboundEmailMessage::authReadout('mailgun', 'pass', 'pass', 'none')['state'],
			'no DMARC + both pass → verified');
		$this->eq('failed', InboundEmailMessage::authReadout('mailgun', 'fail', 'fail', 'none')['state'],
			'no DMARC + both fail → failed');
		$this->eq('partial', InboundEmailMessage::authReadout('mailgun', 'pass', 'fail', 'none')['state'],
			'no DMARC + one of the pair fails → partial, not failed');
		$this->eq('partial', InboundEmailMessage::authReadout('mailgun', 'fail', 'pass', 'none')['state'],
			'…in either direction');

		// A trusted source that asserted nothing at all is still not a failure —
		// the app never renders a verdict it cannot stand behind.
		$this->eq('partial', InboundEmailMessage::authReadout('relay', 'none', 'none', 'none')['state'],
			'trusted source, nothing asserted → partial, never failed');

		// --- Unchecked explains itself -----------------------------------------
		section('unchecked messages say why');
		$imported = InboundEmailMessage::authReadout('none', 'unverified', 'unverified', 'unverified', 'import');
		$this->eq('unchecked', $imported['state'], 'no trusted source → unchecked');
		$this->eq(null, $imported['checked_by'], 'nothing checked it, so nobody is named');
		check(strpos($imported['detail'], 'archive') !== false,
			'an imported message blames the import, not the mail server',
			'detail: ' . $imported['detail']);

		$polled = InboundEmailMessage::authReadout('none', 'unverified', 'unverified', 'unverified', 'imap');
		check(strpos($polled['detail'], 'another mailbox') !== false,
			'an IMAP-collected message says where it came from',
			'detail: ' . $polled['detail']);

		$neither = InboundEmailMessage::authReadout('none', 'unverified', 'unverified', 'unverified');
		check($neither['detail'] !== $imported['detail'] && $neither['detail'] !== $polled['detail'],
			'a locally-received-but-unchecked message gets its own reason',
			'detail: ' . $neither['detail']);

		// The headline is what a person reads first, so it must never be jargon.
		section('no jargon in the headline');
		$headlines = array(
			$this->passing('relay')['headline'],
			InboundEmailMessage::authReadout('relay', 'pass', 'pass', 'fail')['headline'],
			InboundEmailMessage::authReadout('mailgun', 'pass', 'fail', 'none')['headline'],
			$imported['headline'],
		);
		foreach ($headlines as $h) {
			check(stripos($h, 'milter') === false, 'headline says nothing about milters: ' . $h);
			check(stripos($h, 'SPF') === false && stripos($h, 'DKIM') === false
				&& stripos($h, 'DMARC') === false,
				'headline leads with meaning, not acronyms: ' . $h);
			check(trim($h) !== '', 'headline is never blank');
		}
		// ...while the detail keeps them, so the information is not lost.
		check(strpos($this->passing('relay')['detail'], 'SPF') !== false,
			'the acronyms survive as supporting detail');
	}
}

$test = new AuthReadoutTest();
$test->run();
harness_finish();
