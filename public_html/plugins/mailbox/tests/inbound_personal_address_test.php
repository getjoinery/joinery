<?php
/** @joinery-test
 * name: inbound_personal_address
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Personal addresses: a secret address that stands for "this person, about this thing",
 * and the sender check a handler makes beside it.
 *
 *   - one live address per (handler, person, thing); a new one after a revoke;
 *   - resolve() refuses revoked, malformed and other-handler tokens;
 *   - the address uses the domain given, or the registry's default, and is null where
 *     the handler has no alias;
 *   - senderIs() needs the sender to be the member (account or verified recovery email,
 *     any case) AND the message to be attested by the sending domain;
 *   - deleting a person removes their addresses.
 *
 * Run: php tests/run.php test-db --filter=inbound_personal_address
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));

class IpaTestHandler implements InboundAddressHandler {
	public function handle(InboundEmailMessage $msg, ?string $tag): bool { return true; }
}

$db = DbConnector::get_instance()->get_db_link();
$suffix = getmypid() . '-' . random_int(1000, 9999);
$like = 'ipa-%-' . $suffix . '.example';

harness_defer(function () use ($db, $like, $suffix) {
	$db->exec("DELETE FROM ipa_inbound_personal_addresses WHERE ipa_handler LIKE 'ipa" . preg_replace('/\D/', '', $suffix) . "%'");
	mailbox_purge_domains($like, 'ipa-%-' . $suffix . '@example.test');
});

function ipa_domain(string $kind, string $suffix): InboundEmailDomain {
	$d = new InboundEmailDomain(NULL);
	$d->set('ied_domain', 'ipa-' . $kind . '-' . $suffix . '.example');
	$d->set('ied_is_enabled', true);
	$d->set('ied_reject_unmatched', true);
	$d->save();
	return $d;
}

$key = 'ipa' . preg_replace('/\D/', '', $suffix);
$declared = new ReflectionProperty(InboundHandlerRegistry::class, 'declared');
$declared->setValue(null, array(
	$key => array('class' => 'IpaTestHandler', 'label' => 'Test', 'plugin' => 'test'),
	$key . 'b' => array('class' => 'IpaTestHandler', 'label' => 'Test B', 'plugin' => 'test'),
));
harness_set_setting_mem('mailbox_receive_mode', 'direct');
harness_set_setting_mem('mailbox_mail_hostname', 'mx.ipa-' . $suffix . '.example');

$d1 = ipa_domain('one', $suffix);
$d2 = ipa_domain('two', $suffix);
$d3 = ipa_domain('three', $suffix);   // no handler alias here
InboundHandlerRegistry::ensureAlias($key, $d1);
InboundHandlerRegistry::ensureAlias($key, $d2);
InboundHandlerRegistry::ensureAlias($key . 'b', $d1);

$alice = mailbox_make_user('ipa-a-' . $suffix . '@example.test', 1, 'Alice');
$bob = mailbox_make_user('ipa-b-' . $suffix . '@example.test', 1, 'Bob');

section('One live address per person and thing');

$a1 = InboundPersonalAddress::addressFor($key, $alice, 'board', 7);
check($a1 !== null && preg_match('/^' . $key . '\+([a-z2-7]{26})@' . preg_quote($d1->get('ied_domain'), '/') . '$/', $a1, $m) === 1,
	'the address is handler+token@domain, a 26-character base32 token, on the default domain', (string)$a1);
$token = $m[1] ?? '';
check(InboundPersonalAddress::addressFor($key, $alice, 'board', 7) === $a1, 'asking again returns the same address');
$a_other_ref = InboundPersonalAddress::addressFor($key, $alice, 'board', 8);
$a_other_user = InboundPersonalAddress::addressFor($key, $bob, 'board', 7);
$a_other_type = InboundPersonalAddress::addressFor($key, $alice, 'card', 7);
check(count(array_unique(array($a1, $a_other_ref, $a_other_user, $a_other_type))) === 4,
	'another thing, person or kind of thing gets its own address');
$a_d2 = InboundPersonalAddress::addressFor($key, $alice, 'board', 7, intval($d2->key));
check($a_d2 === $key . '+' . $token . '@' . $d2->get('ied_domain'),
	'a given domain is used, and the token is the same: it is not tied to a domain', (string)$a_d2);
check(InboundPersonalAddress::addressFor($key, $alice, 'board', 7, intval($d3->key)) === null,
	'null on a domain where the handler has no alias');
check(InboundPersonalAddress::addressFor('nobody', $alice, 'board', 7) === null, 'null for a handler with no alias anywhere');
$n = (int)$db->query("SELECT COUNT(*) FROM ipa_inbound_personal_addresses WHERE ipa_handler = '" . $key . "' AND ipa_usr_user_id = " . $alice . " AND ipa_ref_type = 'board' AND ipa_ref_id = 7 AND ipa_delete_time IS NULL")->fetchColumn();
check($n === 1, 'and one live row for it');

section('resolve()');

$row = InboundPersonalAddress::resolve($key, $token);
check($row && intval($row->get('ipa_usr_user_id')) === $alice && $row->get('ipa_ref_type') === 'board' && intval($row->get('ipa_ref_id')) === 7,
	'finds the person and the thing');
check(InboundPersonalAddress::resolve($key, strtoupper($token)) !== null, 'in any case (the tag a router hands over is lowercase, but a typed one need not be)');
check(InboundPersonalAddress::resolve($key . 'b', $token) === null, 'another handler\'s token does not resolve');
check(InboundPersonalAddress::resolve($key, substr($token, 1)) === null && InboundPersonalAddress::resolve($key, null) === null
	&& InboundPersonalAddress::resolve($key, 'a b' . substr($token, 3)) === null && InboundPersonalAddress::resolve($key, '') === null,
	'a short, empty or malformed tag does not resolve');
check(InboundPersonalAddress::resolve($key, str_repeat('a', 26)) === null, 'an unknown token does not resolve');

section('Revoking');

InboundPersonalAddress::revoke($key, $alice, 'board', 7);
check(InboundPersonalAddress::resolve($key, $token) === null, 'a revoked address no longer resolves');
$a1b = InboundPersonalAddress::addressFor($key, $alice, 'board', 7);
check($a1b !== null && $a1b !== $a1, 'asking again after a revoke gives a new address');
check(InboundPersonalAddress::resolve($key, $token) === null, 'and the old one stays dead');
$kept = (int)$db->query("SELECT COUNT(*) FROM ipa_inbound_personal_addresses WHERE ipa_token = '" . $token . "' AND ipa_delete_time IS NOT NULL")->fetchColumn();
check($kept === 1, 'the revoked row is kept (soft-deleted) for the retention window');

InboundPersonalAddress::addressFor($key, $bob, 'board', 8);
preg_match('/\+([a-z2-7]{26})@/', (string)InboundPersonalAddress::addressFor($key, $bob, 'board', 8), $bm);
InboundPersonalAddress::revokeAllFor($key, 'board', 8);
check(InboundPersonalAddress::resolve($key, $bm[1]) === null, 'revokeAllFor revokes every person\'s address for the thing');
check(InboundPersonalAddress::resolve($key, substr((string)$a1b, strpos((string)$a1b, '+') + 1, 26)) !== null,
	'and leaves other things alone');

section('senderIs(): the sender is the member, and the sending domain vouches for it');

$router = new InboundEmailRouter();
$to = $key . '@' . $d1->get('ied_domain');
$n_msg = 0;
$store = function (string $from, ?array $auth, string $extra_headers = '') use ($router, $to, $db, &$n_msg): InboundEmailMessage {
	$n_msg++;
	$raw = "Return-Path: <" . $from . ">\r\n" . $extra_headers . "From: Sender <" . $from . ">\r\nTo: <" . $to . ">\r\n"
		. "Subject: m" . $n_msg . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@x.example>\r\n"
		. "Date: " . gmdate('D, d M Y H:i:s') . " +0000\r\n\r\nbody\r\n";
	$router->processEmail($raw, $to, $auth);
	$q = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_subject = ? ORDER BY 1 DESC LIMIT 1");
	$q->execute(array('m' . $n_msg));
	return new InboundEmailMessage(intval($q->fetchColumn()), TRUE);
};
$pass = array('source' => 'mailgun', 'dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'pass');
$alice_user = new User($alice, TRUE);
$alice_mail = 'ipa-a-' . $suffix . '@example.test';

check(InboundPersonalAddress::senderIs($store($alice_mail, $pass), $alice_user), 'the member\'s own address with a DMARC pass');
check(InboundPersonalAddress::senderIs($store(strtoupper($alice_mail), $pass), $alice_user), 'in any case');
check(!InboundPersonalAddress::senderIs($store('ipa-x-' . $suffix . '@example.test', $pass), $alice_user), 'someone else\'s address is not the member');

$db->prepare("UPDATE usr_users SET usr_recovery_email = ? WHERE usr_user_id = ?")->execute(array('alice.home@example.org', $alice));
$alice_user = new User($alice, TRUE);
check(!InboundPersonalAddress::senderIs($store('alice.home@example.org', $pass), $alice_user), 'an unverified recovery email is not the member');
$db->prepare("UPDATE usr_users SET usr_recovery_email_verified_time = now() WHERE usr_user_id = ?")->execute(array($alice));
$alice_user = new User($alice, TRUE);
check(InboundPersonalAddress::senderIs($store('Alice.Home@Example.org', $pass), $alice_user), 'a verified recovery email is');

$none = array('source' => 'mailgun', 'dkim' => 'none', 'spf' => 'none', 'dmarc' => 'none');
check(!InboundPersonalAddress::senderIs($store($alice_mail, $none), $alice_user), 'no authentication at all is not attested');
check(!InboundPersonalAddress::senderIs($store($alice_mail, array('source' => 'none', 'dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified')), $alice_user),
	'unverified is not attested');

// Providers that report no DMARC verdict: an aligned DKIM or SPF pass is enough, read from the headers.
$no_dmarc = array('source' => 'mailgun', 'dkim' => 'pass', 'spf' => 'none', 'dmarc' => 'none');
check(InboundPersonalAddress::senderIs($store($alice_mail, $no_dmarc, "DKIM-Signature: v=1; a=rsa-sha256; d=example.test; s=sel; h=from; bh=x; b=y\r\n"), $alice_user),
	'DKIM pass signed by the From domain (no DMARC verdict)');
check(!InboundPersonalAddress::senderIs($store($alice_mail, $no_dmarc, "DKIM-Signature: v=1; a=rsa-sha256; d=other.example; s=sel; h=from; bh=x; b=y\r\n"), $alice_user),
	'DKIM pass signed by a different domain is not aligned');
$spf_only = array('source' => 'mailgun', 'dkim' => 'none', 'spf' => 'pass', 'dmarc' => 'none');
check(InboundPersonalAddress::senderIs($store($alice_mail, $spf_only), $alice_user),
	'SPF pass with the Return-Path in the From domain (no DMARC verdict)');
$msg_rp = "Return-Path: <bounce@elsewhere.example>\r\n";
$raw_rp = $msg_rp . "From: Sender <" . $alice_mail . ">\r\nTo: <" . $to . ">\r\nSubject: rp\r\nMessage-ID: <" . bin2hex(random_bytes(6)) . "@x.example>\r\n\r\nbody\r\n";
$router->processEmail($raw_rp, $to, $spf_only);
$q = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_subject = 'rp'");
$q->execute();
check(!InboundPersonalAddress::senderIs(new InboundEmailMessage(intval($q->fetchColumn()), TRUE), $alice_user),
	'SPF pass for another domain\'s Return-Path is not aligned');

// Our own milter's Authentication-Results line decides which domain passed: a forged
// DKIM-Signature naming the From domain beside a valid one for another domain proves nothing.
$host = 'mx.ipa-' . $suffix . '.example';
$ar_other = "Authentication-Results: " . $host . "; dkim=pass header.d=attacker.example; spf=none; dmarc=none\r\n";
$forged = "DKIM-Signature: v=1; a=rsa-sha256; d=example.test; s=sel; h=from; bh=x; b=forged\r\n";
check(!InboundPersonalAddress::senderIs($store($alice_mail, null, $ar_other . $forged), $alice_user),
	'a milter line saying the pass was for another domain beats a forged signature header');
$ar_own = "Authentication-Results: " . $host . "; dkim=pass header.d=example.test; spf=none; dmarc=none\r\n";
check(InboundPersonalAddress::senderIs($store($alice_mail, null, $ar_own), $alice_user),
	'a milter line saying the pass was for the From domain is attested');
$ar_dmarc = "Authentication-Results: " . $host . "; dkim=pass header.d=example.test; spf=pass smtp.mailfrom=example.test; dmarc=pass\r\n";
check(InboundPersonalAddress::senderIs($store($alice_mail, null, $ar_dmarc), $alice_user), 'and a milter DMARC pass');
check(!InboundPersonalAddress::senderIs($store($alice_mail, null, "Authentication-Results: elsewhere.example; dmarc=pass\r\n"), $alice_user),
	'a pass claimed by some other server\'s line is nobody\'s verdict');

section('Account state and deleting a person');

check(InboundPersonalAddress::userIsActive($alice_user), 'an ordinary account is active');
$db->exec("UPDATE usr_users SET usr_is_disabled = true WHERE usr_user_id = " . $bob);
check(!InboundPersonalAddress::userIsActive(new User($bob, TRUE)), 'a disabled account is not');
$db->exec("UPDATE usr_users SET usr_is_disabled = false, usr_delete_time = now() WHERE usr_user_id = " . $bob);
check(!InboundPersonalAddress::userIsActive(new User($bob, TRUE)), 'a soft-deleted account is not');

InboundPersonalAddress::addressFor($key, $bob, 'board', 99);
$before = (int)$db->query("SELECT COUNT(*) FROM ipa_inbound_personal_addresses WHERE ipa_usr_user_id = " . $bob)->fetchColumn();
$db->exec("DELETE FROM usr_users WHERE usr_user_id = " . $bob);
$after = (int)$db->query("SELECT COUNT(*) FROM ipa_inbound_personal_addresses WHERE ipa_usr_user_id = " . $bob)->fetchColumn();
check($before > 0 && $after === 0, 'deleting the person removes their addresses');

harness_finish();
