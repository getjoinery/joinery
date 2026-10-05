<?php
/** @joinery-test
 * name: mailbox_mail_stack_packages
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * What the mail stack installs.
 *
 * One program checks mail: rspamd. It writes the SPF, DKIM and DMARC verdicts
 * the router reads, scores content, and signs what the box sends
 * (mail_checking_in_rspamd.md). opendkim and opendmarc did the first and last
 * of those and are not installed. Their package names coming back into either
 * installer would put two verdicts on every message again, and with opendmarc
 * comes its dependency on `dbconfig-mysql | dbconfig-no-thanks`, which apt
 * resolves by installing a MySQL client stack onto a PostgreSQL platform.
 *
 * Run:  php plugins/mailbox/tests/mail_stack_packages_test.php
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');

harness_boot();

$dir = __DIR__ . '/../provisioning/';
$installers = array(
	'a site box' => array('install_email.sh', '/^\s*postconf -e "\$\{list\} = \$\{MILTER_ENTRY\}"/m', 'provision_spam_scanner.sh'),
	'a relay'    => array('provision_relay.sh', '/^postconf_set "smtpd_milters" "inet:localhost:11332"$/m', 'provision_relay.sh'),
);

foreach ($installers as $who => $spec) {
	list($file, $wire_pattern, $wire_file) = $spec;
	$src = (string)@file_get_contents($dir . $file);

	section('What ' . $who . ' installs');

	check($src !== '', $file . ' exists');

	$has_list = preg_match('/^PACKAGES=\(([^)]*)\)/m', $src, $m) === 1;
	check($has_list, $who . ' declares its package list');
	$packages = $has_list ? preg_split('/\s+/', trim($m[1])) : array();

	check(in_array('postfix', $packages, true), $who . ' installs Postfix');
	foreach (array('opendkim', 'opendkim-tools', 'opendmarc', 'dbconfig-no-thanks') as $gone) {
		check(!in_array($gone, $packages, true), $who . ' does not install ' . $gone);
	}
	$mysql_named = array_filter($packages, function ($p) { return stripos($p, 'mysql') !== false; });
	check(empty($mysql_named), $who . ' requests no MySQL package by name',
		empty($mysql_named) ? 'none' : implode(', ', $mysql_named));

	// Nothing outside a comment may install, configure or wire either program.
	// The one function that removes them from a box that has them lives in
	// rspamd_stateless.sh, so the installers themselves name neither.
	$code = preg_replace('/^\s*#.*$/m', '', $src);
	check(preg_match('/opendkim|opendmarc|8891|8893/', $code) === 0,
		$who . ' names neither program outside its comments');
	check(preg_match('/^\s*mail_checkers_retire_old$/m', $src) === 1,
		$who . ' removes both from a box that still has them');

	// rspamd is Postfix's only milter.
	check(preg_match($wire_pattern, (string)@file_get_contents($dir . $wire_file)) === 1,
		$who . ' makes rspamd the only program Postfix hands arriving mail to');
}

section('rspamd arrives without redis');

$scanner = (string)@file_get_contents($dir . 'provision_spam_scanner.sh');
check(strpos($scanner, 'apt-get install -y --no-install-recommends rspamd') !== false,
	'a site box installs rspamd without its recommended redis-server');
check(preg_match('/^CS_PACKAGES=\(rspamd\)$/m', (string)@file_get_contents($dir . 'provision_relay.sh')) === 1,
	'a relay installs rspamd');

harness_finish();
