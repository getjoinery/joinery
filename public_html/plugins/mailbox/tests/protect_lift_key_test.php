<?php
/** @joinery-test
 * name: protect_lift_key
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [public_html/plugins/mailbox/provisioning/provision_relay_main.sh]
 */
/**
 * Switching send protection off leaves something signing
 * (specs/send_protection_one_press.md WP2).
 *
 *  - The root helper joinery-dkim-add, as provision_relay_main.sh writes it, run
 *    in a sandbox: it makes a key and a record that match, keeps a key already
 *    there, puts the domain on rspamd's list, and refuses a malformed name.
 *  - mailbox_protect_lift() puts a fresh key back where it was destroyed and
 *    lands on the DNS difference, says the command when it cannot, and leaves an
 *    existing key alone.
 *
 * @version 1.1 - Make a signing key on the Setup row
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));

section('joinery-dkim-add, in a sandbox');

$main = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/provisioning/provision_relay_main.sh'));
check(preg_match("/<<'DKIMADDHELPER'\n(.*?)\nDKIMADDHELPER\n/s", $main, $m) === 1, 'the helper is found in provision_relay_main.sh');
check(strpos($main, 'NOPASSWD: ${DKIM_ADD_HELPER} *') !== false, 'and the web user may run it through sudo');
$sandbox = sys_get_temp_dir() . '/dkim_add_' . bin2hex(random_bytes(4));
mkdir($sandbox . '/bin', 0755, true);
// The helper's own paths moved under the sandbox, and chown a stand-in: the
// test does not run as root. Its own PATH keeps the stand-in first.
$helper = str_replace(array('/etc/rspamd/dkim', 'PATH=/usr/local/sbin'),
	array($sandbox . '/dkim', 'PATH=' . $sandbox . '/bin:/usr/local/sbin'), $m[1] ?? '');
file_put_contents($sandbox . '/helper', $helper . "\n");
file_put_contents($sandbox . '/bin/chown', "#!/bin/bash\necho \"chown \$*\" >> " . escapeshellarg($sandbox . '/calls') . "\nexit 0\n");
chmod($sandbox . '/bin/chown', 0755);
$run = function (string $arg) use ($sandbox): array {
	$out = array();
	$code = 1;
	exec('bash ' . escapeshellarg($sandbox . '/helper') . ' ' . escapeshellarg($arg) . ' 2>&1', $out, $code);
	return array($code, implode("\n", $out));
};

try {
	check(strpos($helper, '/etc/rspamd') === false, 'no path is left outside the sandbox');
	list($code, $out) = $run('Lift-Key.Example');
	check($code === 0 && strpos($out, 'DKIM_ADDED lift-key.example') !== false, 'it makes a key and says so', $out);
	$private = (string)@file_get_contents($sandbox . '/dkim/lift-key.example/mail.private');
	$txt = (string)@file_get_contents($sandbox . '/dkim/lift-key.example/mail.txt');
	$read = new ReflectionMethod(InboundEmailSetupCheck::class, 'readDkimKey');
	$record = (string)$read->invoke(new InboundEmailSetupCheck(), $sandbox . '/dkim/lift-key.example/mail.txt');
	check(strpos($record, 'v=DKIM1; k=rsa; p=') === 0, 'the record file reads as the Setup tab reads it', $record);
	check($private !== '' && MailboxDkimSigner::proveKeyMatchesRecord($private, $record) === null,
		'a signature from the key verifies against its record');
	check(trim((string)@file_get_contents($sandbox . '/dkim/signing.map'))
			=== 'lift-key.example ' . $sandbox . '/dkim/lift-key.example/mail.private',
		'the domain is on the list rspamd signs for');
	check(strpos((string)@file_get_contents($sandbox . '/calls'), ':www-data ' . $sandbox . '/dkim/lift-key.example/') !== false
			|| strpos((string)@file_get_contents($sandbox . '/calls'), ':apache ') !== false,
		'the key is handed to the web server\'s group, which signs with it behind a relay');
	check(substr(sprintf('%o', fileperms($sandbox . '/dkim/lift-key.example/mail.private')), -3) === '640',
		'the key is not world-readable');
	check(count(glob($sandbox . '/dkim/lift-key.example/.*.private.*') ?: array()) === 0, 'no half-made key is left behind');

	list($code, $out) = $run('lift-key.example');
	check($code === 0 && (string)@file_get_contents($sandbox . '/dkim/lift-key.example/mail.private') === $private,
		'a second run keeps the key, whose record may be published', $out);

	foreach (array('', '-x.example', '../etc', 'nodot', 'a/b.example') as $bad) {
		list($code, ) = $run($bad);
		check($code === 2, 'a malformed name is refused: \'' . $bad . '\'');
	}
	check(!is_dir($sandbox . '/etc') && count(glob($sandbox . '/dkim/*', GLOB_ONLYDIR)) === 1, 'and touched nothing');
} finally {
	exec('rm -rf ' . escapeshellarg($sandbox));
}

section('switching off puts a fresh key back');

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'lift-' . bin2hex(random_bytes(3)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
$domain->set('ied_send_lock_requested', true);
$domain->save();
$domain->load();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
$name = (string)$domain->get('ied_domain');

$calls = array();
$lifted = mailbox_protect_lift($domain, array(
	'needs_key' => function () { return true; },
	'provision' => function (string $d) use (&$calls) {
		$calls[] = $d;
		return array('ok' => true, 'message' => 'FRESH KEY MADE.');
	},
));
$reread = new InboundEmailDomain(intval($domain->key), TRUE);
check(!$reread->send_lock_requested() && !$reread->is_protected_identity(), 'send protection is off');
check($calls === array($name), 'a destroyed key is put back, once, for this domain', json_encode($calls));
check(strpos($lifted['message'], 'FRESH KEY MADE.') !== false, 'and the message says so', $lifted['message']);
check($lifted['land_dns'] === true, 'and it lands on the DNS difference, where the new record is');

$domain->set('ied_send_lock_requested', true);
$domain->save();
$calls = array();
$lifted = mailbox_protect_lift($domain, array(
	'needs_key' => function () { return false; },
	'provision' => function (string $d) use (&$calls) { $calls[] = $d; return array('ok' => true, 'message' => ''); },
));
check($calls === array(), 'a key still on disk is left alone');
check($lifted['land_dns'] === false, 'and nothing new needs publishing');

$domain->set('ied_send_lock_requested', true);
$domain->save();
$lifted = mailbox_protect_lift($domain, array(
	'needs_key' => function () { return true; },
	'provision' => function (string $d) { return mailbox_provision_local_signing_key($d); },
));
if (!is_file(InboundEmailSetupCheck::localSigningKeyAddHelper())) {
	check(strpos($lifted['message'], 'provision_dkim.sh ' . $name) !== false,
		'with no helper on the box, the message names the command to run as root', $lifted['message']);
	check($lifted['land_dns'] === false, 'and claims no new record');
} else {
	harness_skip('this box has joinery-dkim-add installed', 'the no-helper message is not reachable here');
}

$refused = mailbox_provision_local_signing_key('not-a-domain-on-this-box.invalid');
check($refused['ok'] === false && strpos($refused['message'], 'No such domain') !== false,
	'a domain this server does not host is refused before any helper runs');

section('Make a signing key, from the Setup row');

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/setup_fix_actions.php'));
class LiftKeyMessages {
	public $said = array();
	public function save_message($m) { $this->said[] = $m; }
}
$said = function (LiftKeyMessages $s): string {
	$m = end($s->said);
	return $m ? (string)$m->message : '';
};
$sess = new LiftKeyMessages();
$domain->set('ied_is_protected_identity', true);
$domain->save();
$r = mailbox_setup_fix_action(array('action' => 'make_local_key', 'domain' => $name), $sess, '/back');
check($r !== null && strpos($said($sess), 'sealed key signs') !== false,
	'a protected domain is refused: its sealed key signs, and a key on disk would be a second way', $said($sess));
$domain->set('ied_is_protected_identity', false);
$domain->save();
$r = mailbox_setup_fix_action(array('action' => 'make_local_key', 'domain' => 'nowhere-' . bin2hex(random_bytes(2)) . '.invalid'), $sess, '/back');
check($r !== null && strpos($said($sess), 'No such domain') !== false, 'a domain this server does not host is refused');
if (!is_file(InboundEmailSetupCheck::localSigningKeyAddHelper())) {
	$r = mailbox_setup_fix_action(array('action' => 'make_local_key', 'domain' => $name), $sess, '/back');
	check($r !== null && strpos($said($sess), 'provision_dkim.sh ' . $name) !== false,
		'with no helper here, an ordinary domain is told the command to run', $said($sess));
	$fix = (new ReflectionMethod(InboundEmailSetupCheck::class, 'dkimFix'))->invoke(new InboundEmailSetupCheck(), $name);
	check(empty($fix['action']) && strpos((string)($fix['command'] ?? ''), 'provision_dkim.sh ' . $name) !== false,
		'and its DKIM row offers the command, not a button that cannot work');
}

section('who needs a key: the box that signs with one');

$checker = new InboundEmailSetupCheck();
$plan = (new ReflectionMethod(InboundEmailSetupCheck::class, 'dkimPlan'))->invoke($checker);
check($checker->signsWithLocalKey() === !empty($plan['local']),
	'the lift asks the same question the DNS plan does, so the key it makes is the key the plan prescribes');

harness_finish();
