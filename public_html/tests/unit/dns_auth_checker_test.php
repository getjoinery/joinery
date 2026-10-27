<?php
/** @joinery-test
 * name: dns_auth_checker
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Unit test for DnsAuthChecker.
 *
 * Runs with no real network: a fake backend is installed via
 * DnsResolver::setBackend(). This is the deterministic counterpart to the
 * live-DNS integration test in tests/email/email_auth_dns_test.php —
 * it makes DnsAuthChecker's SPF/DKIM/DMARC logic testable offline.
 * Run:  php tests/unit/dns_auth_checker_test.php
 *
 * @version 1.1 - the DKIM alignment mode, signaturesMatch(), and explain()'s plain reading
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('includes/DnsAuthChecker.php'));
require_once(__DIR__ . '/../lib/dns_fixtures.php'); // FakeDnsBackend

// A DKIM TXT value with a public key long enough to satisfy isDKIMRecord().
$dkim_txt = 'v=DKIM1; k=rsa; p=' . str_repeat('A', 200);

DnsResolver::setBackend(new FakeDnsBackend([
    // ── SPF ──
    'pass.example.com|' . DNS_TXT      => [['txt' => 'v=spf1 include:_spf.example.com -all']],
    'weak.example.com|' . DNS_TXT      => [['txt' => 'v=spf1 +all']],
    'neutral.example.com|' . DNS_TXT   => [['txt' => 'v=spf1 ?all']],
    'multi.example.com|' . DNS_TXT     => [['txt' => 'v=spf1 -all'], ['txt' => 'v=spf1 ~all']],
    'notxt.example.com|' . DNS_TXT     => [],
    'hastxt.example.com|' . DNS_TXT    => [['txt' => 'google-site-verification=abc']],
    'broken.example.com|' . DNS_TXT    => false, // simulated resolver failure

    // ── DKIM ──
    'mail._domainkey.dkimok.example.com|' . DNS_TXT      => [['txt' => $dkim_txt]],
    'mail._domainkey.dkimcname.example.com|' . DNS_CNAME => [['target' => 'k.dkimhost.example.net']],
    'k.dkimhost.example.net|' . DNS_TXT                  => [['txt' => $dkim_txt]],

    // ── DMARC ──
    '_dmarc.reject.example.com|' . DNS_TXT      => [['txt' => 'v=DMARC1; p=reject; rua=mailto:r@example.com']],
    '_dmarc.monitor.example.com|' . DNS_TXT     => [['txt' => 'v=DMARC1; p=none']],

    // ── A whole site, signed by its service under a key no wordlist holds ──
    'site.example.com|' . DNS_TXT                     => [['txt' => 'v=spf1 include:mailgun.org -all']],
    's123._domainkey.site.example.com|' . DNS_CNAME   => [['target' => 'k.dkimhost.example.net']],
    '_dmarc.site.example.com|' . DNS_TXT              => [['txt' => 'v=DMARC1; p=quarantine; adkim=s; aspf=s']],
    'loose.example.com|' . DNS_TXT                    => [['txt' => 'v=spf1 -all']],
    's123._domainkey.loose.example.com|' . DNS_CNAME  => [['target' => 'k.dkimhost.example.net']],
    '_dmarc.loose.example.com|' . DNS_TXT             => [['txt' => 'v=DMARC1; p=none']],
]));

// ── checkSPF ─────────────────────────────────────────────────────────────
ok('checkSPF pass on -all',        DnsAuthChecker::checkSPF('pass.example.com')['status'] === 'pass');
ok('checkSPF warn on +all',        DnsAuthChecker::checkSPF('weak.example.com')['status'] === 'warn');
ok('checkSPF warn on ?all',        DnsAuthChecker::checkSPF('neutral.example.com')['status'] === 'warn');
ok('checkSPF warn on multiple',    DnsAuthChecker::checkSPF('multi.example.com')['status'] === 'warn');
ok('checkSPF fail when no TXT',    DnsAuthChecker::checkSPF('notxt.example.com')['status'] === 'fail');
ok('checkSPF fail when TXT but no SPF', DnsAuthChecker::checkSPF('hastxt.example.com')['detail'] === 'No SPF record found');
ok('checkSPF fail-open: resolver failure reads as no record',
      DnsAuthChecker::checkSPF('broken.example.com')['status'] === 'fail');

// ── checkDKIM ────────────────────────────────────────────────────────────
$dkim_ok = DnsAuthChecker::checkDKIM('dkimok.example.com', ['mail']);
ok('checkDKIM pass via TXT',        $dkim_ok['status'] === 'pass' && $dkim_ok['selector'] === 'mail');
$dkim_cname = DnsAuthChecker::checkDKIM('dkimcname.example.com', ['mail']);
ok('checkDKIM pass via CNAME',      $dkim_cname['status'] === 'pass');
ok('checkDKIM fail when absent',    DnsAuthChecker::checkDKIM('dkimnone.example.com', ['mail'])['status'] === 'fail');

// ── checkDMARC ───────────────────────────────────────────────────────────
ok('checkDMARC pass on p=reject',   DnsAuthChecker::checkDMARC('reject.example.com')['status'] === 'pass');
$dmarc_mon = DnsAuthChecker::checkDMARC('monitor.example.com');
ok('checkDMARC warn on p=none',     $dmarc_mon['status'] === 'warn' && $dmarc_mon['policy'] === 'none');
ok('checkDMARC fail when absent',   DnsAuthChecker::checkDMARC('dmarcmissing.example.com')['status'] === 'fail');

// ── quickCheck rolls all three up ────────────────────────────────────────
$quick = DnsAuthChecker::quickCheck('pass.example.com', ['mail']);
ok('quickCheck returns spf/dkim/dmarc keys',
      isset($quick['spf'], $quick['dkim'], $quick['dmarc']));

// ── The DKIM alignment mode ──────────────────────────────────────────────
ok('checkDMARC reads adkim=s',      DnsAuthChecker::checkDMARC('site.example.com')['adkim'] === 's');
ok('checkDMARC defaults adkim to r', DnsAuthChecker::checkDMARC('reject.example.com')['adkim'] === 'r');

// ── Does the signature count for the From address? ───────────────────────
ok('match: the same domain, strict',        DnsAuthChecker::signaturesMatch('getjoinery.com', 'getjoinery.com', 's'));
ok('no match: a subdomain under strict (B169)', !DnsAuthChecker::signaturesMatch('mail.getjoinery.com', 'getjoinery.com', 's'));
ok('match: a subdomain under relaxed',      DnsAuthChecker::signaturesMatch('mail.getjoinery.com', 'getjoinery.com', 'r'));
ok('no match: another organisation',        !DnsAuthChecker::signaturesMatch('mailgun.org', 'getjoinery.com', 'r'));
ok('organisational domain under a country code', DnsAuthChecker::organisationalDomain('mail.shop.co.uk') === 'shop.co.uk');
ok('organisational domain of a plain name', DnsAuthChecker::organisationalDomain('a.b.example.com') === 'example.com');

// ── explain(): the plain reading ─────────────────────────────────────────
$site = DnsAuthChecker::quickCheck('site.example.com', ['s123']);
$good = DnsAuthChecker::explain($site, 'site.example.com', 'SMTP2GO', ['s123'], 'site.example.com');
$titles = array_column($good['rows'], 'title');
ok('a signed, matching, strict site reads Yes', $good['verdict']['tone'] === 'success' && strpos($good['verdict']['text'], 'Yes.') === 0);
ok('  four rows, in order', $titles === ['Signature', 'Matches your address', 'Approved senders', 'Forgery policy']);
ok('  the signature is credited to the service', strpos($good['rows'][0]['text'], 'signed by SMTP2GO') !== false);
ok('  every row keeps its protocol name and record', $good['rows'][0]['tech'] === 'DKIM'
    && strpos($good['rows'][0]['raw'], 's123._domainkey.site.example.com') !== false
    && $good['rows'][2]['raw'] === 'v=spf1 include:mailgun.org -all');
ok('  hard fail reads as refusing everyone else', strpos($good['rows'][2]['text'], 'refuse mail from anywhere else') !== false);

$b169 = DnsAuthChecker::explain($site, 'site.example.com', 'Mailgun', ['s123'], 'mail.site.example.com');
ok('signed as a subdomain under a strict, enforcing policy reads No (B169)',
    $b169['verdict']['tone'] === 'danger' && strpos($b169['verdict']['text'], 'No.') === 0);
ok('  the match row says why and what to do', $b169['rows'][1]['tone'] === 'danger'
    && strpos($b169['rows'][1]['text'], 'match exactly') !== false
    && strpos($b169['rows'][1]['text'], 'Set up site.example.com itself') !== false);

$loose = DnsAuthChecker::explain(DnsAuthChecker::quickCheck('loose.example.com', ['s123']), 'loose.example.com', 'Mailgun', ['s123'], 'mail.other.example.net');
ok('a mismatch under p=none warns, it does not fail', $loose['verdict']['tone'] === 'warning' && $loose['rows'][1]['tone'] === 'warning');

$unknown = DnsAuthChecker::explain($site, 'site.example.com', '', [], null);
ok('no service to ask: no match row, and the key is not credited to anyone',
    !in_array('Matches your address', array_column($unknown['rows'], 'title'), true)
    && strpos($unknown['rows'][0]['text'], 'A signing key is published') === 0);

$unsigned = DnsAuthChecker::explain(DnsAuthChecker::quickCheck('reject.example.com', ['mail']), 'reject.example.com', 'SMTP2GO');
ok('no key found reads Not yet, naming the service', $unsigned['verdict']['tone'] === 'danger'
    && strpos($unsigned['rows'][0]['text'], 'SMTP2GO lists the record') !== false);

$nobody = DnsAuthChecker::explain(DnsAuthChecker::quickCheck('reject.example.com', ['mail']), 'reject.example.com');
ok('no key found and no service asked reads Could not tell, not Not yet',
    $nobody['verdict']['tone'] === 'warning' && strpos($nobody['verdict']['text'], 'Could not tell.') === 0
    && $nobody['rows'][0]['tone'] === 'warning');

$html = DnsAuthChecker::explanationHtml($b169);
ok('the HTML escapes and wraps in one block', strpos($html, '<div><p class="text-danger">') === 0
    && strpos($html, 'DKIM d=mail.site.example.com') !== false);

DnsResolver::clearBackend();

harness_finish();
