<?php
/** @joinery-test
 * name: sender_display_name
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What ingest stores in iem_sender.
 *
 * The reader shows who mail is from and hides the address, so a stored sender of
 * hello@fireworks.ai leaves the recipient reading a local part. Ingest therefore
 * keeps the From display name beside the address as "Name" <addr>, and
 * senderDisplayString() owns that decision for both the immediate-store path and
 * the deferred parse a sealed mailbox uses.
 *
 * The contracts under guard:
 *   - the name survives ingest, RFC 2047 encoded words included
 *   - the address is always still extractable by the consumers that need it:
 *     MailboxContacts::parseAddress() (contact harvest, sender context) and a
 *     From filter criterion, which matches by substring
 *   - the name is hostile input. It arrives over SMTP from whoever sent the mail,
 *     so quotes, angle brackets and CR/LF are stripped: a name may never forge a
 *     second address or fold into another header
 *   - the ADDRESS is never what gets truncated to fit the column — losing bytes
 *     off an address turns a real sender into an unreplyable one
 *
 * The reader's half of the feature (which label is shown when there is no name)
 * is covered by sender_name_gate.sh.
 *
 * Run: php plugins/mailbox/tests/sender_display_name_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));

class SenderDisplayNameTest {

	private $router;
	private $method;
	private $parse;

	private function out($msg) {
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . $msg . "\n";
	}
	private function ok($cond, $label) { return check((bool)$cond, $label); }
	private function eq($expected, $actual, $label) {
		return check($expected === $actual, $label, 'expected ' . var_export($expected, true)
			. ', got ' . var_export($actual, true));
	}

	/** The stored sender for a raw message, through the real parse. */
	private function stored(string $raw): string {
		$parsed = $this->parse->invoke($this->router, $raw);
		return $this->format($parsed);
	}

	/** The stored sender for an already-parsed From, as the IMAP and relay paths supply it. */
	private function format(array $parsed): string {
		return (string)$this->method->invoke($this->router, $parsed);
	}

	private function msg(string $from_header): string {
		return "From: " . $from_header . "\r\nTo: me@example.test\r\nSubject: hi\r\n\r\nbody\r\n";
	}

	function run() {
		$this->router = new InboundEmailRouter();
		$this->method = new ReflectionMethod('InboundEmailRouter', 'senderDisplayString');
		$this->parse = new ReflectionMethod('InboundEmailRouter', 'parseEmail');

		$this->testKeepsTheName();
		$this->testAddressStaysUsable();
		$this->testHostileNames();
		$this->testTruncation();
	}

	private function testKeepsTheName() {
		$this->out('-- the display name survives ingest --');

		$this->eq('"Fireworks" <hello@fireworks.ai>',
			$this->stored($this->msg('Fireworks <hello@fireworks.ai>')),
			'a named From keeps its name');

		$this->eq('"Fireworks Team" <hello@fireworks.ai>',
			$this->stored($this->msg('"Fireworks Team" <hello@fireworks.ai>')),
			'an already-quoted name is stored once, not double-quoted');

		// Non-ASCII names arrive as encoded words; stored raw they would read as
		// =?UTF-8?B?...?= in the folder list.
		$encoded = '=?UTF-8?B?' . base64_encode('Zoë Müller') . '?= <zoe@example.test>';
		$this->eq('"Zoë Müller" <zoe@example.test>', $this->stored($this->msg($encoded)),
			'an RFC 2047 encoded name is decoded');

		$this->eq('hello@fireworks.ai', $this->stored($this->msg('hello@fireworks.ai')),
			'a bare address stores as the bare address');

		$this->eq('hello@fireworks.ai', $this->stored($this->msg('<hello@fireworks.ai>')),
			'angle brackets with no name store as the bare address');

		// Bulk senders routinely set the display name to the address itself.
		$this->eq('hello@fireworks.ai',
			$this->stored($this->msg('hello@fireworks.ai <hello@fireworks.ai>')),
			'a name equal to the address adds nothing and is dropped');

		$this->eq('', $this->stored($this->msg('')), 'an empty From stores empty');
	}

	private function testAddressStaysUsable() {
		$this->out('-- the address is still extractable by every consumer --');

		$stored = $this->stored($this->msg('Fireworks <hello@fireworks.ai>'));

		// Contact harvest and sender context both go through parseAddress().
		$parsed = MailboxContacts::parseAddress($stored);
		$this->ok(is_array($parsed), 'parseAddress accepts the stored form');
		if (is_array($parsed)) {
			$this->eq('hello@fireworks.ai', $parsed[0], 'parseAddress recovers the address');
			$this->eq('Fireworks', $parsed[1], 'parseAddress recovers the name');
		}

		// A From filter criterion matches by substring, so a rule written against
		// the address (or its domain) still fires now that a name precedes it.
		$haystack = mb_strtolower($stored);
		$this->ok(mb_strpos($haystack, 'hello@fireworks.ai') !== false,
			'a From filter term on the address still matches');
		$this->ok(mb_strpos($haystack, 'fireworks.ai') !== false,
			'a From filter term on the domain still matches');

		// The reply builder pulls the address out of the same shape.
		$this->ok(preg_match('/<([^>]+)>/', $stored, $m) === 1 && $m[1] === 'hello@fireworks.ai',
			'the reply builder can extract the address');
	}

	private function testHostileNames() {
		$this->out('-- the name is untrusted input --');

		// A quoted display name may legally contain angle brackets, so the addr-spec
		// is the LAST one. Reading the first hands the sender their address of choice
		// for iem_sender, the reply address, the contact lookup, filter matching and
		// the SRS envelope — while auth results still describe the real domain.
		$spoof_header = $this->msg('"Support <billing@paypal.com>" <thief@evil.example>');
		$spoof_parsed = $this->parse->invoke($this->router, $spoof_header);
		$this->eq('thief@evil.example', $spoof_parsed['from_email'],
			'from_email is the last angle-addr, not the first');
		$plain_parsed = $this->parse->invoke($this->router, $this->msg('Fireworks <hello@fireworks.ai>'));
		$this->eq('hello@fireworks.ai', $plain_parsed['from_email'],
			'an ordinary named From is unaffected');

		$spoof = $this->stored($spoof_header);
		$this->eq(1, preg_match_all('/</', $spoof), 'a name cannot smuggle in a second address');
		$parsed = MailboxContacts::parseAddress($spoof);
		$this->eq('thief@evil.example', is_array($parsed) ? $parsed[0] : null,
			'the real address is the one recovered from a spoofing name');

		// Header folding. Over the raw-message path the header parser splits a CR/LF
		// before the formatter ever sees it, so this is checked at the formatter
		// itself: the value also arrives pre-parsed from the IMAP and relay paths,
		// and a name that can carry a line break can forge a header wherever the
		// string is later written out.
		$folded = $this->format(array(
			'from' => "\"Ok\r\nBcc: leak@evil.example\" <a@b.test>",
			'from_email' => 'a@b.test',
		));
		$this->ok(strpos($folded, "\r") === false && strpos($folded, "\n") === false,
			'CR/LF is stripped out of the name');
		$this->eq('a@b.test', is_array(MailboxContacts::parseAddress($folded))
			? MailboxContacts::parseAddress($folded)[0] : null,
			'the address survives a folding name');
		// The raw path never reaches the formatter with a break in the first place.
		$raw_folded = $this->stored($this->msg("\"Ok\r\nBcc: leak@evil.example\" <a@b.test>"));
		$this->ok(strpos($raw_folded, "\n") === false,
			'a folded From header cannot produce a multi-line stored sender');

		// A malformed name full of quotes has no unambiguous reading, so the name is
		// dropped rather than guessed at — but the quoting that IS stored is always
		// balanced, or every consumer downstream would mis-parse the address.
		$quoted = $this->stored($this->msg('"He said "hi"" <a@b.test>'));
		$this->ok(in_array(preg_match_all('/"/', $quoted), array(0, 2), true),
			'stored quoting is balanced: one quoted name, or none at all');
		$this->eq('a@b.test', is_array(MailboxContacts::parseAddress($quoted))
			? MailboxContacts::parseAddress($quoted)[0] : null,
			'the address survives a name full of quotes');

		$tabbed = $this->stored($this->msg("\"A\tB\" <a@b.test>"));
		$this->eq('"A B" <a@b.test>', $tabbed, 'tabs and runs of whitespace collapse to one space');
	}

	private function testTruncation() {
		$this->out('-- the column limit costs name bytes, never address bytes --');

		$long = str_repeat('Ridiculously Long Sender Name ', 40); // ~1200 chars
		$stored = $this->stored($this->msg('"' . $long . '" <hello@fireworks.ai>'));
		$this->ok(strlen($stored) <= 500, 'the stored sender fits the 500-byte column');
		$parsed = MailboxContacts::parseAddress($stored);
		$this->eq('hello@fireworks.ai', is_array($parsed) ? $parsed[0] : null,
			'the address is intact after the name is truncated');

		// A multibyte name must not be cut mid-character — an invalid UTF-8 byte
		// sequence would break the JSON the reader is served.
		$mb = str_repeat('日本語のとても長い名前', 60);
		$stored_mb = $this->stored($this->msg(
			'=?UTF-8?B?' . base64_encode($mb) . '?= <hello@fireworks.ai>'));
		$this->ok(strlen($stored_mb) <= 500, 'a multibyte name also fits the column');
		$this->ok(mb_check_encoding($stored_mb, 'UTF-8'), 'the truncated name is still valid UTF-8');
		$this->ok(json_encode(array('sender' => $stored_mb)) !== false,
			'the truncated sender is JSON-encodable for the reader');
		$parsed_mb = MailboxContacts::parseAddress($stored_mb);
		$this->eq('hello@fireworks.ai', is_array($parsed_mb) ? $parsed_mb[0] : null,
			'the address is intact after a multibyte truncation');

		// An address long enough to leave no room for a name keeps the address whole.
		$long_addr = str_repeat('a', 480) . '@example.test';
		$stored_addr = $this->stored($this->msg('Someone <' . $long_addr . '>'));
		$this->eq(substr($long_addr, 0, 500), $stored_addr,
			'no room for a name → the address alone, uncorrupted');
	}
}

$test = new SenderDisplayNameTest();
$test->run();
harness_finish();
