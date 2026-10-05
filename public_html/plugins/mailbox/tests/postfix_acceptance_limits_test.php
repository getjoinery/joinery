<?php
/** @joinery-test
 * name: mailbox_postfix_acceptance_limits
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Two things Postfix must be told on every machine that receives mail, because
 * its own defaults are wrong for us and nothing fails loudly when they are left.
 *
 * Size. Postfix refuses a message over 10,240,000 bytes unless told otherwise,
 * while the router stores and the relay sealer seals up to 25 MiB. A sender of
 * a 15 MB message was refused by a limit nobody chose. The limit Postfix is
 * given has to be the cap the code behind it enforces: larger, and Postfix
 * accepts mail the handler then refuses after the sender has gone.
 *
 * Line endings. A Postfix that lets a bare line feed end a message can be made
 * to accept a second message, with a forged sender, hidden inside the first
 * (SMTP smuggling, CVE-2023-51764). smtpd_forbid_bare_newline closes it and is
 * off by default before Postfix 3.9.
 *
 * Blocklist answers. `reject_rbl_client zen.spamhaus.org` refuses a sender on
 * ANY answer, and Spamhaus answers 127.255.255.x to a resolver it will not
 * serve. A box in that state would refuse every sender. Each list has to be
 * followed by the answers that mean "listed", and a box Spamhaus is not
 * answering has to say so, because with the filter nothing else would.
 *
 * Run:  php plugins/mailbox/tests/postfix_acceptance_limits_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');

harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));

$dir     = __DIR__ . '/../';
$site    = (string)@file_get_contents($dir . 'provisioning/install_email.sh');
$relay   = (string)@file_get_contents($dir . 'provisioning/provision_relay.sh');
$router  = (string)@file_get_contents($dir . 'includes/InboundEmailRouter.php');
$sealer  = (string)@file_get_contents($dir . 'provisioning/relay-sealer/main.go');

section('The size Postfix accepts is the size the code behind it keeps');

check($site !== '' && $relay !== '' && $router !== '' && $sealer !== '', 'the four sources are readable');

$router_cap = preg_match('/strlen\(\$raw_email\)\s*>\s*(\d+)\s*\*\s*1024\s*\*\s*1024/', $router, $m) === 1 ? (int)$m[1] * 1024 * 1024 : 0;
$sealer_cap = preg_match('/maxMessageMiB\s*=\s*(\d+)/', $sealer, $m) === 1 ? (int)$m[1] * 1024 * 1024 : 0;
check($router_cap > 0, 'the router states its size cap', (string)$router_cap);
check($sealer_cap > 0, 'the relay sealer states its size cap', (string)$sealer_cap);

$site_limit  = preg_match('/^postconf -e "message_size_limit = (\d+)"/m', $site, $m) === 1 ? (int)$m[1] : 0;
$relay_limit = preg_match('/^postconf_set "message_size_limit" "(\d+)"/m', $relay, $m) === 1 ? (int)$m[1] : 0;
check($site_limit === $router_cap, 'a site box tells Postfix the router\'s cap', $site_limit . ' vs ' . $router_cap);
check($relay_limit === $sealer_cap, 'a relay tells Postfix the sealer\'s cap', $relay_limit . ' vs ' . $sealer_cap);

section('A bare line feed cannot end a message');

check(preg_match('/^\s*postconf -e "smtpd_forbid_bare_newline = yes"/m', $site) === 1,
    'a site box turns the smuggling guard on');
check(preg_match('/^\s*postconf_set "smtpd_forbid_bare_newline" "yes"/m', $relay) === 1,
    'a relay turns the smuggling guard on');

section('A sender is refused only on a real Spamhaus listing');

$restrictions = array(
	'a site box' => preg_match('/^\s*postconf -e "smtpd_recipient_restrictions = ([^"]*)"/m', $site, $m) === 1 ? $m[1] : '',
	'a relay'    => preg_match('/^postconf_set "smtpd_recipient_restrictions" "([^"]*)"/m', $relay, $m) === 1 ? $m[1] : '',
);
foreach ($restrictions as $who => $line) {
	check($line !== '', $who . ' sets its recipient restrictions');
	check(strpos($line, 'reject_rbl_client zen.spamhaus.org=127.0.0.[2..11],') !== false,
		$who . ' refuses a connecting address only on a zen listing');
	check(strpos($line, 'reject_rhsbl_helo dbl.spamhaus.org=127.0.1.[2..99],') !== false,
		$who . ' refuses a HELO name only on a dbl listing');
	check(strpos($line, 'reject_rhsbl_sender dbl.spamhaus.org=127.0.1.[2..99],') !== false,
		$who . ' refuses a sender domain only on a dbl listing');
	check(preg_match('/spamhaus\.org(?!=)/', $line) === 0,
		$who . ' names no Spamhaus list without its answer filter', $line);
	check(strpos($line, 'spamhaus') < strpos($line, 'check_recipient_access'),
		$who . ' asks the blocklist before the recipient lookup');
}

section('A box Spamhaus is not answering says so');

$listed = InboundEmailSetupCheck::spamhausResult(array('127.0.0.10', '127.0.0.2', '127.0.0.4'));
check($listed['id'] === 'host.spamhaus' && $listed['status'] === InboundEmailSetupCheck::PASS,
	'the test entry answering 127.0.0.2 passes');
foreach (array(
	'a declined resolver' => array('127.255.255.254'),
	'an empty answer'     => array(),
	'a failed lookup'     => null,
) as $what => $answer) {
	$row = InboundEmailSetupCheck::spamhausResult($answer);
	check($row['status'] === InboundEmailSetupCheck::WARN && $row['summary'] === 'Spamhaus is not answering this box.',
		$what . ' warns that Spamhaus is not answering', $row['status'] . ': ' . $row['summary']);
	check($row['severity'] === InboundEmailSetupCheck::RECOMMENDED,
		$what . ' is a warning, never a required failure');
}
foreach (array('answering' => InboundEmailSetupCheck::PASS, 'refused' => InboundEmailSetupCheck::WARN,
	'no_answer' => InboundEmailSetupCheck::WARN) as $answer => $want) {
	$row = InboundEmailSetupCheck::relaySpamhausResult($answer, 'mx.example.test');
	check($row !== null && $row['id'] === 'host.relay_spamhaus' && $row['status'] === $want,
		'a relay answering "' . $answer . '" is shown as ' . $want, $row['summary'] ?? '');
	check(strpos((string)@file_get_contents($dir . 'provisioning/relay-sealer/relay_apply.go'), '= "' . $answer . '"') !== false,
		'and "' . $answer . '" is a word the relay says');
}
check(InboundEmailSetupCheck::relaySpamhausResult('', 'mx.example.test') === null,
	'a relay too old to say gets no row');
check(preg_match('/const spamhausTestName = "' . preg_quote(InboundEmailSetupCheck::SPAMHAUS_TEST_NAME, '/') . '"/',
	(string)@file_get_contents($dir . 'provisioning/relay-sealer/relay_apply.go')) === 1,
	'a relay asks the same test entry');

harness_finish();
