<?php
/** @joinery-test
 * name: mail_log_summary
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * What the mail_log_summary agent word compiles from the Postfix journal
 * (maintenance_scripts/sysadmin_tools/mail_log_summary.php,
 * specs/mailbox_diagnosis_words.md WP3), driven with a fixture journal: every
 * counting rule, and that nothing a remote server or Postfix wrote — no IP,
 * hostname, local part, Message-ID or queue id — reaches the answer.
 *
 * @version 1.3 - a blocklist refusal's matched word and client_domain; a hostile greeting
 * @version 1.2 - a mailbox pulled over IMAP is not one of the site's domains
 * @version 1.1 - covers_from from the journal's first entry; expired; a forwarding subdomain is the site's
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$script = PathHelper::getBasePath() . '/maintenance_scripts/sysadmin_tools/mail_log_summary.php';
if (!is_file($script)) {
	$script = dirname(PathHelper::getIncludePath('includes/PathHelper.php'), 3) . '/maintenance_scripts/sysadmin_tools/mail_log_summary.php';
}
define('MLS_FUNCTIONS_ONLY', true);
require($script);

$now = 1764500000;                 // the window's end
$t = function (int $minutes_ago) use ($now) { return (string)(($now - $minutes_ago * 60) * 1000000); };
$entries = array();
$e = function (int $ago, string $ident, string $msg, string $pid = '100') use (&$entries, $t) {
	$entries[] = array('__REALTIME_TIMESTAMP' => $t($ago), 'SYSLOG_IDENTIFIER' => $ident, '_PID' => $pid, 'MESSAGE' => $msg);
};
$hosted = array('home.example.org', 'fwd.home.example.org');

// Oldest first. 1500 min ago: before the 24 h window, inside the 6 h lookback.
$e(1500, 'postfix/smtpd', 'connect from mail-a.google.com[209.85.0.10]', '201');
$e(1500, 'postfix/smtpd', 'AAA111: client=mail-a.google.com[209.85.0.10]', '201');
$e(1500, 'postfix/cleanup', 'AAA111: message-id=<secret-msgid-1@mail.gmail.com>', '300');
$e(1500, 'postfix/qmgr', 'AAA111: from=<alice.secret@gmail.com>, size=4321, nrcpt=1 (queue active)', '400');
$e(1500, 'postfix/pipe', 'AAA111: to=<bob.secret@home.example.org>, relay=joinery, delay=1, delays=0.1/0/0/0.9, dsn=4.3.0, status=deferred (temporary failure)', '500');
$e(1499, 'postfix/smtpd', 'disconnect from mail-a.google.com[209.85.0.10] ehlo=1 quit=1 commands=2', '201');
// ...delivered on retry inside the window: one delivered, its client= line before the window.
$e(600, 'postfix/pipe', 'AAA111: to=<bob.secret@home.example.org>, relay=joinery, delay=54000, delays=0/0/0/1, dsn=2.0.0, status=sent (delivered via joinery service)', '501');
$e(600, 'postfix/qmgr', 'AAA111: removed', '400');

// A three-recipient message, one recipient still deferred at the end.
$e(500, 'postfix/smtpd', 'connect from out.example.net[198.51.100.7]', '202');
$e(500, 'postfix/smtpd', 'BBB222: client=out.example.net[198.51.100.7]', '202');
$e(500, 'postfix/qmgr', 'BBB222: from=<carol@Example.NET>, size=100, nrcpt=3 (queue active)', '400');
$e(499, 'postfix/pipe', 'BBB222: to=<r1@home.example.org>, relay=joinery, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (delivered via joinery service)', '502');
$e(499, 'postfix/pipe', 'BBB222: to=<r2@home.example.org>, relay=joinery, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (delivered via joinery service)', '503');
$e(499, 'postfix/pipe', 'BBB222: to=<r3@home.example.org>, relay=joinery, delay=1, delays=0/0/0/1, dsn=4.3.0, status=deferred (temporary failure)', '504');
$e(499, 'postfix/smtpd', 'disconnect from out.example.net[198.51.100.7]', '202');

// A reused short queue id: the old CCC333 was loopback (rspamd-like), removed; then a real one.
$e(400, 'postfix/smtpd', 'connect from localhost[127.0.0.1]', '203');
$e(400, 'postfix/smtpd', 'CCC333: client=localhost[127.0.0.1]', '203');
$e(400, 'postfix/qmgr', 'CCC333: from=<health@home.example.org>, size=10, nrcpt=1 (queue active)', '400');
$e(400, 'postfix/pipe', 'CCC333: to=<check@home.example.org>, relay=joinery, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (delivered via joinery service)', '505');
$e(400, 'postfix/qmgr', 'CCC333: removed', '400');
$e(300, 'postfix/smtpd', 'connect from mx.example.com[2001:db8::25]', '204');
$e(300, 'postfix/smtpd', 'CCC333: client=mx.example.com[2001:db8::25]', '204');
$e(300, 'postfix/qmgr', 'CCC333: from=<>, size=10, nrcpt=1 (queue active)', '400');
// The delivery handler's exit 67: bounced through the pipe.
$e(299, 'postfix/pipe', 'CCC333: to=<gone@home.example.org>, relay=joinery, delay=1, delays=0/0/0/1, dsn=5.3.0, status=bounced (Command died with status 67)', '506');
$e(299, 'postfix/qmgr', 'CCC333: removed', '400');

// Refusals before a queue id.
$e(200, 'postfix/smtpd', 'connect from unknown[203.0.113.9]', '205');
$e(200, 'postfix/smtpd', 'NOQUEUE: reject: RCPT from unknown[203.0.113.9]: 554 5.7.1 Service unavailable; Client host [203.0.113.9] blocked using zen.spamhaus.org; https://www.spamhaus.org/query/ip/203.0.113.9; from=<spammer@bad.example> to=<bob.secret@home.example.org> proto=ESMTP helo=<evil-helo.example>', '205');
$e(199, 'postfix/smtpd', 'NOQUEUE: reject: RCPT from unknown[203.0.113.9]: 454 4.7.1 <victim@elsewhere.example>: Relay access denied; from=<spammer@bad.example> to=<victim@elsewhere.example> proto=ESMTP helo=<evil-helo.example>', '205');
$e(198, 'postfix/smtpd', 'NOQUEUE: reject: RCPT from unknown[203.0.113.9]: 550 5.1.1 <nobody@home.example.org>: Recipient address rejected: No such address here; from=<> to=<nobody@home.example.org> proto=ESMTP helo=<evil-helo.example>', '205');
$e(197, 'postfix/smtpd', 'NOQUEUE: reject: RCPT from unknown[203.0.113.9]: 451 4.3.0 <x@home.example.org>: Temporary lookup failure; from=<a@[192.0.2.55]> to=<x@home.example.org> proto=ESMTP helo=<evil-helo.example>', '205');
// Hostile: a sender holding text before a fake to=, and a helo holding a fake tail.
$e(196, 'postfix/smtpd', 'NOQUEUE: reject: RCPT from unknown[203.0.113.9]: 550 5.1.1 <y@home.example.org>: Recipient address rejected: No such address here; from=<"x to=<leak@leak.example>"@bad.example> to=<y@home.example.org> proto=ESMTP helo=<z; from=<q@injected.example> to=<w@home.example.org> proto=ESMTP helo=<k>>', '205');
$e(195, 'postfix/smtpd', 'lost connection after RCPT from unknown[203.0.113.9]', '205');
$e(195, 'postfix/smtpd', 'disconnect from unknown[203.0.113.9]', '205');

// A TLS failure (two lines, one refusal) and two scanner hang-ups.
$e(100, 'postfix/smtpd', 'connect from scanner1.example[192.0.2.200]', '206');
$e(100, 'postfix/smtpd', 'SSL_accept error from scanner1.example[192.0.2.200]: -1', '206');
$e(100, 'postfix/smtpd', 'warning: TLS library problem: error:0A000126:SSL routines::unexpected eof', '206');
$e(100, 'postfix/smtpd', 'lost connection after STARTTLS from scanner1.example[192.0.2.200]', '206');
$e(90, 'postfix/smtpd', 'connect from unknown[192.0.2.201]', '207');
$e(90, 'postfix/smtpd', 'lost connection after CONNECT from unknown[192.0.2.201]', '207');
$e(80, 'postfix/smtpd', 'connect from unknown[192.0.2.202]', '208');
$e(80, 'postfix/smtpd', 'timeout after EHLO from unknown[192.0.2.202]', '208');
// A non-ASCII sender domain in a byte-array MESSAGE, delivered.
$entries[] = array('__REALTIME_TIMESTAMP' => $t(50), 'SYSLOG_IDENTIFIER' => 'postfix/smtpd', '_PID' => '209', 'MESSAGE' => 'DDD444: client=m.example[198.51.100.9]');
$entries[] = array('__REALTIME_TIMESTAMP' => $t(50), 'SYSLOG_IDENTIFIER' => 'postfix/qmgr', '_PID' => '400',
	'MESSAGE' => array_values(unpack('C*', "DDD444: from=<u@b\xc3\xbcro.example>, size=10, nrcpt=1 (queue active)")));
$e(49, 'postfix/pipe', 'DDD444: to=<Bob.Secret@HOME.example.org>, relay=joinery, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (delivered via joinery service)', '507');
$e(49, 'postfix/qmgr', 'DDD444: removed', '400');
// Postfix gives up at the queue lifetime: the deferred recipient is expired, not deferred.
$e(40, 'postfix/smtpd', 'connect from relay.example.com[198.51.100.20]', '210');
$e(40, 'postfix/smtpd', 'EEE555: client=relay.example.com[198.51.100.20]', '210');
$e(40, 'postfix/qmgr', 'EEE555: from=<x@example.com>, size=10, nrcpt=1 (queue active)', '400');
$e(39, 'postfix/pipe', 'EEE555: to=<late@home.example.org>, relay=joinery, delay=1, delays=0/0/0/1, dsn=4.3.0, status=deferred (temporary failure)', '508');
$e(30, 'postfix/qmgr', 'EEE555: from=<x@example.com>, status=expired, returned to sender', '400');
$e(30, 'postfix/qmgr', 'EEE555: removed', '400');
// A bounce returning to the forwarding subdomain: its to_domain is the site's.
$e(20, 'postfix/smtpd', 'connect from mx.other.example[198.51.100.21]', '211');
$e(20, 'postfix/smtpd', 'NOQUEUE: reject: RCPT from mx.other.example[198.51.100.21]: 550 5.1.1 <srs0=x@fwd.home.example.org>: Recipient address rejected: No such address here; from=<> to=<srs0=x@fwd.home.example.org> proto=ESMTP helo=<mx.other.example>', '211');
// Something that is not Postfix is not read.
$entries[] = array('__REALTIME_TIMESTAMP' => $t(10), 'SYSLOG_IDENTIFIER' => 'sshd', 'MESSAGE' => 'NOQUEUE: reject: RCPT from x[1.1.1.1]: 554 5.7.1 Service unavailable; Client host [1.1.1.1] blocked using z; from=<a@b.example> to=<c@home.example.org> proto=ESMTP helo=<h>');

$journal_first = $now - 3 * 86400;   // the journal reaches back three days
$out = mls_summarize($entries, 24, $now, $hosted, false, $journal_first);
$json = json_encode($out);

// ---------------------------------------------------------------------------
section('Counts');

check($out['counts']['delivered'] === 4, 'delivered: the retried one, two of three recipients, the non-ASCII sender\'s', json_encode($out['counts']));
check($out['counts']['deferred'] === 1, 'one recipient still deferred at the end');
check($out['counts']['expired'] === 1, 'one recipient given up on at the queue lifetime is expired, not deferred');
check($out['counts']['handler_refused'] === 1, 'the handler\'s exit 67 is handler_refused');
check($out['counts']['refused'] === array('blocklist' => 1, 'relay_denied' => 1, 'unknown_recipient' => 2, 'lookup_failure' => 1, 'tls' => 1, 'other' => 1),
	'one refusal per reject line, reasons from Postfix\'s own text (the hostile line, whose fields do not read, is other); TLS counted once per connection', json_encode($out['counts']['refused']));
check($out['connections'] === 8, 'connections: non-loopback connects inside the window (the loopback and the earlier one not)', (string)$out['connections']);
check($out['dropped_connections'] === 3, 'dropped: the reject-only one and two scanners, not the TLS one', (string)$out['dropped_connections']);
check($out['hours'] === 24 && $out['truncated'] === false, 'hours and truncated carried');
check($out['covers_from'] === gmdate('Y-m-d\TH:i\Z', $now - 24 * 3600) && $out['covers_window'] === true,
	'a journal reaching further than the window: covers_from is the window\'s start, the lookback hours are not coverage', (string)$out['covers_from']);
$rotated = mls_summarize($entries, 24, $now, $hosted, false, $now - 3600);
check($rotated['covers_from'] === gmdate('Y-m-d\TH:i\Z', $now - 3600) && $rotated['covers_window'] === false,
	'a journal that begins an hour ago: covers_from is its first entry, and it does not cover the window');
$unknown = mls_summarize($entries, 24, $now, $hosted);
check($unknown['covers_from'] === null && $unknown['covers_window'] === false, 'an unknown first entry claims no coverage');

// ---------------------------------------------------------------------------
section('Recent rows');

check(count($out['recent']) === 14 && $out['recent'][0]['time'] >= $out['recent'][13]['time'], 'newest first', (string)count($out['recent']));
$by_reason = array();
foreach ($out['recent'] as $r) { if ($r['outcome'] === 'refused') { $by_reason[$r['reason']][] = $r; } }
$bl = $by_reason['blocklist'][0];
check($bl['code'] === '5xx' && $bl['from_domain'] === 'bad.example' && $bl['to_domain'] === 'home.example.org', 'the blocklist row', json_encode($bl));
check($bl['matched'] === 'client' && $bl['client_domain'] === null, 'listed on its address; a sending server Postfix could not name has no client_domain', json_encode($bl));
check(!array_key_exists('matched', $by_reason['relay_denied'][0]) && !array_key_exists('client_domain', $by_reason['relay_denied'][0]),
	'only a blocklist row carries matched and client_domain');
check($out['counts']['blocklist_matched'] === array('client' => 1, 'helo' => 0, 'sender' => 0, 'other' => 0), 'blocklist_matched counts', json_encode($out['counts']['blocklist_matched']));
check($by_reason['relay_denied'][0]['to_domain'] === null && $by_reason['relay_denied'][0]['code'] === '4xx',
	'a relay probe for someone else\'s domain shows a null to_domain');
check($by_reason['lookup_failure'][0]['from_domain'] === null, 'an address-literal sender is null');
check($by_reason['tls'][0]['from_domain'] === null && $by_reason['tls'][0]['to_domain'] === null, 'a TLS row has no domains');
$first = null;
foreach ($out['recent'] as $r) { if ($r['outcome'] === 'delivered') { $first = $first ?? $r; } }
check($first !== null && $first['from_domain'] === null && $first['to_domain'] === 'home.example.org',
	'the non-ASCII sender domain is null; the mixed-case recipient domain is read', json_encode($first));
$other = $by_reason['other'][0];
check($other['from_domain'] === null && $other['to_domain'] === null, 'the hostile line: no fields read, not even the fake tail in its greeting', json_encode($other));
$fwd = $out['recent'][0];
check($fwd['to_domain'] === 'fwd.home.example.org' && $fwd['reason'] === 'unknown_recipient', 'mail to the forwarding subdomain names it', json_encode($fwd));
$froms = array_column($out['recent'], 'from_domain');
check(in_array('gmail.com', $froms, true) && in_array('example.net', $froms, true), 'sender domains read from qmgr\'s from= field, lowercased');

// ---------------------------------------------------------------------------
section('Nothing a remote server or Postfix wrote');

foreach (array('209.85.0.10', '198.51.100.7', '2001:db8::25', '203.0.113.9', '192.0.2.200', '192.0.2.55', 'mail-a.google.com', 'out.example.net',
		'evil-helo', 'scanner1', 'secret', 'msgid', 'AAA111', 'BBB222', 'CCC333', 'DDD444', '4321', 'spamhaus', 'leak', 'injected', 'srs0', '198.51.100.2', '@', 'Command died') as $needle) {
	check(strpos($json, $needle) === false, 'not in the answer: ' . $needle);
}
$allowed = array_merge(array('delivered', 'deferred', 'handler_refused', 'expired', 'refused', '4xx', '5xx'), MLS_REFUSAL_REASONS, MLS_BLOCKLIST_MATCHED, $hosted);
$bad = array();
array_walk_recursive($out, function ($v, $k) use (&$bad, $allowed) {
	if (!is_string($v)) { return; }
	if (in_array($v, $allowed, true) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\dZ$/', $v)) { return; }
	if (($k === 'from_domain' || $k === 'to_domain') && preg_match(MLS_DOMAIN_PATTERN, $v)) { return; }
	$bad[] = $k . '=' . $v;
});
check($bad === array(), 'every string is a compiled word, a time, or a domain field', implode(', ', $bad));

// ---------------------------------------------------------------------------
section('The window');

$short = mls_summarize($entries, 1, $now, $hosted);
check($short['counts']['delivered'] === 1 && $short['counts']['expired'] === 1 && $short['connections'] === 2, 'a one-hour window counts only what fell in it', json_encode($short['counts']));
$empty = mls_summarize(array(), 24, $now, $hosted, false, $now - 7 * 86400);
check($empty['covers_window'] === true && $empty['connections'] === 0 && $empty['counts']['delivered'] === 0,
	'no Postfix lines but a journal reaching back a week: the whole window is covered and nothing came');

// ---------------------------------------------------------------------------
section('Which blocklist check fired, and the sending server\'s domain');

check(mls_registrable('mail1234.smtp2go.com') === 'smtp2go.com' && mls_registrable('Out.Mail.Example.CO.UK.') === 'example.co.uk'
	&& mls_registrable('203-0-113-9.dsl.isp.example.net') === 'example.net' && mls_registrable('unknown') === null
	&& mls_registrable('') === null && mls_registrable('host_name.example') === null && mls_registrable('xn--bcher-kva.example') === 'xn--bcher-kva.example'
	&& mls_registrable('localhost') === null && mls_registrable(null) === null,
	'a verified name is cut to its registrable domain; unknown, bare or non-ASCII names are null');

$bl_entries = array();
$b = function (int $ago, string $msg) use (&$bl_entries, $t) {
	$bl_entries[] = array('__REALTIME_TIMESTAMP' => $t($ago), 'SYSLOG_IDENTIFIER' => 'postfix/smtpd', '_PID' => '700', 'MESSAGE' => $msg);
};
// zen on the client's address, from a server Postfix verified
$b(50, 'NOQUEUE: reject: RCPT from mail1234.smtp2go.com[203.0.113.50]: 554 5.7.1 Service unavailable; Client host [203.0.113.50] blocked using zen.spamhaus.org; https://www.spamhaus.org/query/ip/203.0.113.50; from=<news@sender.example> to=<ian@home.example.org> proto=ESMTP helo=<mail1234.smtp2go.com>');
// dbl on the greeting name
$b(40, 'NOQUEUE: reject: RCPT from out.mail.example.co.uk[198.51.100.30]: 554 5.7.1 <greet-listed.example>: Helo command rejected: Service unavailable; Helo command [greet-listed.example] blocked using dbl.spamhaus.org; from=<a@sender.example> to=<b@home.example.org> proto=ESMTP helo=<greet-listed.example>');
// dbl on the sender's domain, from a server with no verified name
$b(30, 'NOQUEUE: reject: RCPT from unknown[198.51.100.31]: 554 5.7.1 <x@listed-sender.example>: Sender address rejected: Service unavailable; Sender address [listed-sender.example] blocked using dbl.spamhaus.org; from=<x@listed-sender.example> to=<c@home.example.org> proto=ESMTP helo=<h>');
// a class Postfix has that is not one of the three
$b(25, 'NOQUEUE: reject: RCPT from unknown[198.51.100.32]: 554 5.7.1 Service unavailable; Reverse client host [rev.example] blocked using dbl.spamhaus.org; from=<y@sender.example> to=<d@home.example.org> proto=ESMTP helo=<h>');
// hostile greeting: claims to be smtp2go and carries a fake tail; Postfix could not name the server
$b(20, 'NOQUEUE: reject: RCPT from unknown[198.51.100.33]: 554 5.7.1 <forged-smtp2go.com>: Helo command rejected: Service unavailable; Helo command [forged-smtp2go.com] blocked using dbl.spamhaus.org; from=<z@sender.example> to=<e@home.example.org> proto=ESMTP helo=<forged-smtp2go.com; from=<q@injected-tail.example> to=<w@home.example.org> proto=ESMTP helo=<k>');
$hostile_helo = 'NOQUEUE: reject: RCPT from unknown[198.51.100.34]: 554 5.7.1 <spoof.example; from=<q@injected-tail.example> to=<w@home.example.org> proto=ESMTP helo=<k>: Helo command rejected: Service unavailable; Helo command [spoof.example] blocked using dbl.spamhaus.org; from=<v@sender.example> to=<f@home.example.org> proto=ESMTP helo=<spoof.example; from=<q@injected-tail.example> to=<w@home.example.org> proto=ESMTP helo=<k>';
$b(10, $hostile_helo);
$blo = mls_summarize($bl_entries, 24, $now, $hosted);
$rows = array();
foreach ($blo['recent'] as $r) { $rows[$r['time']] = $r; }
$at = function (int $ago) use ($rows, $now) { return $rows[gmdate('Y-m-d\TH:i\Z', $now - $ago * 60)] ?? array(); };
check(($at(50)['matched'] ?? null) === 'client' && ($at(50)['client_domain'] ?? null) === 'smtp2go.com', 'zen on the address: client, from smtp2go.com', json_encode($at(50)));
check(($at(40)['matched'] ?? null) === 'helo' && ($at(40)['client_domain'] ?? null) === 'example.co.uk', 'dbl on the greeting: helo, the verified server under a two-label suffix', json_encode($at(40)));
check(($at(30)['matched'] ?? null) === 'sender' && array_key_exists('client_domain', $at(30)) && $at(30)['client_domain'] === null, 'dbl on the sender: sender, no verified name', json_encode($at(30)));
check(($at(25)['matched'] ?? null) === 'other', 'another class counts as other', json_encode($at(25)));
check(($at(20)['reason'] ?? null) === 'other' && !array_key_exists('client_domain', $at(20)), 'a greeting that claims smtp2go and carries a fake tail: other, no client domain', json_encode($at(20)));
check(($at(10)['reason'] ?? null) === 'other' && array_key_exists('from_domain', $at(10)) && $at(10)['from_domain'] === null && $at(10)['to_domain'] === null,
	'a fake tail at the start of a greeting refusal: no fields read at all', json_encode($at(10)));
check($blo['counts']['refused']['blocklist'] === 4 && $blo['counts']['blocklist_matched'] === array('client' => 1, 'helo' => 1, 'sender' => 1, 'other' => 1)
	&& array_sum($blo['counts']['blocklist_matched']) === $blo['counts']['refused']['blocklist'], 'blocklist_matched adds up to the blocklist count', json_encode($blo['counts']));
$bjson = json_encode($blo);
foreach (array('greet-listed', 'forged', 'spoof', 'injected', 'rev.example', 'mail1234', 'out.mail', '203.0.113', '198.51.100', 'spamhaus', '@') as $needle) {
	check(strpos($bjson, $needle) === false, 'not in the answer: ' . $needle);
}

// ---------------------------------------------------------------------------
section('The site\'s own domains');

$row = function (string $domain, string $fwd, bool $imap) {
	return new class($domain, $fwd, $imap) {
		private $v;
		public function __construct($d, $f, $i) { $this->v = array('ied_domain' => $d, 'fwd' => $f, 'ied_is_imap_source' => $i); }
		public function get($k) { return $this->v[$k] ?? null; }
		public function forwarding_subdomain() { return $this->v['fwd']; }
	};
};
$own = mls_hosted_domains(array(
	$row('Home.Example.org', 'fwd.home.example.org', false),
	$row('gmail.com', 'gmail.com', true),
	$row('home.example.org', 'home.example.org', false),
));
sort($own);
check($own === array('fwd.home.example.org', 'home.example.org'),
	'a domain and its forwarding subdomain are the site\'s, once each; a mailbox pulled over IMAP is not', json_encode($own));
$relay = array(array('__REALTIME_TIMESTAMP' => $t(10), 'SYSLOG_IDENTIFIER' => 'postfix/smtpd', '_PID' => '900',
	'MESSAGE' => 'NOQUEUE: reject: RCPT from unknown[198.51.100.9]: 454 4.7.1 <someone@gmail.com>: Relay access denied; from=<x@home.example.org> to=<someone@gmail.com> proto=ESMTP helo=<h>'));
$r = mls_summarize($relay, 24, $now, $own);
check(isset($r['recent'][0]) && array_key_exists('to_domain', $r['recent'][0]) && $r['recent'][0]['to_domain'] === null, 'so a relay try to gmail.com names no recipient domain', json_encode($r['recent']));

// ---------------------------------------------------------------------------
section('Usage');

$run = function (array $args) use ($script) {
	$proc = proc_open(array_merge(array(PHP_BINARY, $script), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return proc_close($proc);
};
foreach (array('0', '169', '-1', '24h', '1e2') as $bad_hours) {
	check($run(array($bad_hours)) === 2, 'hours ' . $bad_hours . ' is bad usage');
}
check($run(array('24', 'x')) === 2, 'a second argument is bad usage');

harness_finish();
