<?php
/**
 * Unit test for EmailSecurityDigest (specs/joinery_ai_email_security_scan.md
 * §1): section presence, whitespace-obfuscation annotation, URL extraction
 * (dedup + cap), and size caps, against a fixture raw message built in-memory
 * (no DB writes — InboundEmailMessage fields are set directly, never saved).
 *
 * Runs offline except for one read-only settings lookup (the configured
 * inbound_email_mail_hostname, used to build a trusted Authentication-Results
 * line in the fixture so the DKIM-domain extraction has something to find).
 *
 * Run: php tests/unit/email_security_digest_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/EmailSecurityDigest.php'));

$tests = 0;
$failures = 0;
function check($label, $condition) {
    global $tests, $failures;
    $tests++;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$condition) { $GLOBALS['failures']++; }
}

$settings = Globalvars::get_instance();
$authserv_id = (string)$settings->get_setting('inbound_email_mail_hostname');
if ($authserv_id === '') { $authserv_id = 'devmail.getjoinery.com'; } // fallback if unset on this box

// --- Build an obfuscated-subject phishing fixture ---------------------------

// >200 invisible/whitespace characters inside the Subject header — the
// classic context-survival case the digest builder exists for (real sample
// scored 0/10 raw, 10/10 as a digest, per the spec's validation table).
$zwsp = "\xE2\x80\x8B"; // U+200B zero-width space
$obfuscated_subject = 'Security Alert' . str_repeat($zwsp, 400) . 'Sign in required';

// 25 distinct URLs so the digest's cap (20) + "(+N more)" marker are exercised.
$urls = [];
for ($i = 1; $i <= 25; $i++) {
    $urls[] = "http://tracker{$i}.example.com/click?id={$i}";
}
$redirect_url = 'https://accounts.google.com/o/oauth2/continue?continue=http://evil-phish.example/login';

// A long run of spaces (>3) plus enough body text to exceed the 4096-char cap.
$body_lines = [];
$body_lines[] = 'Your account has unusual activity.' . str_repeat(' ', 50) . 'Please review immediately.';
$body_lines[] = 'Sign in here: ' . $redirect_url;
foreach ($urls as $u) { $body_lines[] = 'See also: ' . $u; }
$body_lines[] = str_repeat('Padding line to exceed the body size cap. ', 150);
$fixture_body = implode("\n", $body_lines);

$fixture_raw = "From: \"Google Security\" <no-reply@accounts.google.com>\r\n"
    . "Reply-To: attacker@sites.google.com\r\n"
    . "Return-Path: <bounce@mailgun.example.com>\r\n"
    . "To: victim@example.com\r\n"
    . "Date: Mon, 06 Jul 2026 10:00:00 +0000\r\n"
    . "Subject: $obfuscated_subject\r\n"
    . "Authentication-Results: $authserv_id;\r\n"
    . "  dkim=pass header.d=google.com header.i=@google.com;\r\n"
    . "  spf=pass smtp.mailfrom=google.com;\r\n"
    . "  dmarc=pass\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n"
    . "\r\n"
    . $fixture_body;

$msg = new InboundEmailMessage(NULL);
$msg->set('iem_raw_storage_driver', 'inline');
$msg->set('iem_raw_message', $fixture_raw);
$msg->set('iem_sender', 'no-reply@accounts.google.com');
$msg->set('iem_recipient', 'victim@example.com');
$msg->set('iem_subject', 'Security Alert Sign in required'); // decoded fallback, unused here (raw present)
$msg->set('iem_body_plain', $fixture_body);
$msg->set('iem_body_html', '');
$msg->set('iem_spf_result', 'pass');
$msg->set('iem_dkim_result', 'pass');
$msg->set('iem_dmarc_result', 'pass');
$msg->set('iem_received_time', '2026-07-06 10:00:05');

$digest = EmailSecurityDigest::build($msg);

// --- Section presence ---------------------------------------------------
check('starts with the fixed digest header', str_starts_with($digest, '=== EMAIL DIGEST ==='));
check('FROM section present with decoded display name', strpos($digest, 'FROM: "Google Security" <no-reply@accounts.google.com>') !== false);
check('REPLY-TO section present', strpos($digest, 'REPLY-TO: attacker@sites.google.com') !== false);
check('RETURN-PATH section present', strpos($digest, 'RETURN-PATH: bounce@mailgun.example.com') !== false);
check('TO section present', strpos($digest, 'TO: victim@example.com') !== false);
check('AUTHENTICATION line present with spf/dkim/dmarc', strpos($digest, 'AUTHENTICATION: spf=pass dkim=pass') !== false);
check('AUTHENTICATION line carries the DKIM signing domain', strpos($digest, '(d=google.com)') !== false);
check('SUBJECT section present', strpos($digest, 'SUBJECT (decoded') !== false);
check('URLS FOUND section present', strpos($digest, 'URLS FOUND (') !== false);
check('BODY section present', strpos($digest, 'BODY (text/plain, decoded') !== false);

// --- Whitespace obfuscation annotation -----------------------------------
if (preg_match('/SUBJECT \(decoded; preprocessor removed (\d+) invisible\/whitespace characters\):/', $digest, $m)) {
    check('subject whitespace annotation count exceeds the 200 threshold', (int)$m[1] > 200);
} else {
    check('subject whitespace annotation present', false);
}
check('obfuscated subject text is collapsed (no long zero-width run survives)',
    strpos($digest, $zwsp . $zwsp . $zwsp) === false);
check('subject content survives collapsing (both halves still present)',
    strpos($digest, 'Security Alert') !== false && strpos($digest, 'Sign in required') !== false);

// --- URL extraction: dedup, order, cap -----------------------------------
check('the redirect URL is extracted', strpos($digest, $redirect_url) !== false);
check('first tracker URL (order of appearance) is present', strpos($digest, 'http://tracker1.example.com/click?id=1') !== false);
check('URLS FOUND count reflects the true total (26: redirect + 25 trackers)',
    strpos($digest, 'URLS FOUND (26):') !== false);
check('overflow beyond the 20-URL cap is marked, not silently dropped',
    strpos($digest, '(+6 more)') !== false);
$listed_url_lines = preg_match_all('/^\d+\. /m', $digest);
check('exactly 20 URLs are listed (the cap)', $listed_url_lines === 20);

// --- Size cap -------------------------------------------------------------
if (preg_match('/\[truncated, (\d+) characters total\]/', $digest, $m)) {
    check('body truncation marker reports a total larger than the 4096-char cap', (int)$m[1] > 4096);
} else {
    check('body truncation marker present (fixture body exceeds the 4KB cap)', false);
}

echo "\n$tests checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
