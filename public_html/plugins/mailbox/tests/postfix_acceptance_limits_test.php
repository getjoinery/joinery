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
 * Run:  php plugins/mailbox/tests/postfix_acceptance_limits_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');

harness_boot();

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

harness_finish();
