<?php
/** @joinery-test
 * name: mail_send_outcomes
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * MailSendOutcome: how each domain's mail left (signed here, unsigned here,
 * handed on), counted by EmailSender from the transport's note.
 *
 *  - A transport that signs: one row, outcome signed, with its selector and d=;
 *    a second send adds to the same row.
 *  - A signer that meant to sign and could not: outcome unsigned with the
 *    compiled reason.
 *  - A transport that leaves no note (an API provider): handed on, path api,
 *    the provider's key.
 *  - The app-built raw relay path (RawRelayComposeTransport) signing with a
 *    real key through a stub API relay: signed, path relay_compose.
 *  - A refused send is not counted; a dry run is not counted; a test-mode
 *    send is counted with test_mode set; a reserved example domain is never
 *    counted.
 *  - No row holds an address.
 *  - A counting failure (an aborted database transaction) does not fail the
 *    send.
 *  - summaryForDomain(): the row shape, ISO UTC times, newest first.
 *
 * Run: php tests/email/mail_send_outcomes_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/RawRelayComposeTransport.php'));

/** A transport that answers as told and leaves the note it is told to. */
class MsoStubTransport implements EmailServiceProvider {
	public $accept = true;
	public $note = 'none'; // none | signed | unsigned
	public static function getKey(): string { return 'mso_stub'; }
	public static function getLabel(): string { return 'Stub transport (test)'; }
	public static function getSpfMechanism(string $domain): string { return ''; }
	public static function validateConfiguration(): array { return array('valid' => true, 'errors' => array()); }
	public function send(EmailMessage $message): bool {
		if ($this->note === 'signed') {
			MailSendOutcome::noteSigned(MailSendOutcome::PATH_SMTP, 'mailk7',
				MailIdentityGuard::domainOf((string)$message->getFrom()), 'mso_stub:localhost');
		} elseif ($this->note === 'unsigned') {
			MailSendOutcome::noteUnsignedReason(MailSendOutcome::REASON_KEY_MISSING);
			MailSendOutcome::notePath(MailSendOutcome::PATH_SMTP, 'mso_stub:localhost');
		}
		return $this->accept;
	}
	public function sendBatch(EmailMessage $message, array $recipients): array {
		return array('success' => true, 'failed_recipients' => array());
	}
}

/** An API raw-message relay that accepts every destination. */
class MsoStubRelay implements EmailServiceProvider, ApiSubmissionRelay {
	public $raw = '';
	public static function getKey(): string { return 'mso_relay'; }
	public static function getLabel(): string { return 'Stub relay (test)'; }
	public static function getSpfMechanism(string $domain): string { return ''; }
	public static function validateConfiguration(): array { return array('valid' => true, 'errors' => array()); }
	public function send(EmailMessage $message): bool { return true; }
	public function sendBatch(EmailMessage $message, array $recipients): array {
		return array('success' => true, 'failed_recipients' => array());
	}
	public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
		$this->raw = $raw_mime;
		return array_fill_keys($destinations, true);
	}
}

$suffix = bin2hex(random_bytes(4));
$domain = 'mso-' . $suffix . '.joinerytest.com';
$other  = 'mso2-' . $suffix . '.joinerytest.com';

harness_defer(function () use ($domain, $other) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'DELETE FROM mso_mail_send_outcomes WHERE mso_domain IN (:a, :b)');
	$q->execute(array(':a' => $domain, ':b' => $other));
});

function mso_message(string $from_domain): EmailMessage {
	$m = new EmailMessage();
	$m->to('someone@example.com')->from('hello@' . $from_domain, 'Site')->subject('mso test')->text('body');
	return $m;
}

function mso_rows(string $domain): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT * FROM mso_mail_send_outcomes WHERE mso_domain = :d ORDER BY mso_mail_send_outcome_id');
	$q->execute(array(':d' => $domain));
	return $q->fetchAll(PDO::FETCH_ASSOC);
}

function mso_row(string $domain, string $outcome): ?array {
	foreach (mso_rows($domain) as $r) {
		if ($r['mso_outcome'] === $outcome) { return $r; }
	}
	return null;
}

harness_set_setting_mem('email_dry_run', '0');
harness_set_setting_mem('email_test_mode', '0');

$sender = new EmailSender();
$stub = new MsoStubTransport();

// ---------------------------------------------------------------------------
section('Signed, unsigned and handed on');

$stub->note = 'signed';
check($sender->send(mso_message($domain), false, $stub) === true, 'a signing transport\'s send succeeds');
$r = mso_row($domain, 'signed');
check($r !== null && $r['mso_selector'] === 'mailk7' && $r['mso_d'] === $domain && $r['mso_path'] === 'smtp'
	&& (int)$r['mso_count'] === 1, 'it is counted signed, with its selector and d=', var_export($r, true));
$sender->send(mso_message($domain), false, $stub);
$r = mso_row($domain, 'signed');
check((int)$r['mso_count'] === 2 && count(mso_rows($domain)) === 1, 'a second send adds to the same row');

$stub->note = 'unsigned';
$sender->send(mso_message($domain), false, $stub);
$r = mso_row($domain, 'unsigned');
check($r !== null && $r['mso_reason'] === 'key_missing' && $r['mso_selector'] === '',
	'a signer that could not sign leaves it unsigned, with the reason', var_export($r, true));

$stub->note = 'none';
$sender->send(mso_message($domain), false, $stub);
$r = mso_row($domain, 'handed_on');
check($r !== null && $r['mso_path'] === 'api' && $r['mso_provider'] === 'mso_stub',
	'a transport that leaves no note hands the message on, named', var_export($r, true));

$stub->note = 'signed';
$stub->accept = false;
$before = (int)mso_row($domain, 'signed')['mso_count'];
$sender->send(mso_message($domain), false, $stub);
check((int)mso_row($domain, 'signed')['mso_count'] === $before, 'a refused send is not counted');
$stub->accept = true;

// ---------------------------------------------------------------------------
section('The raw relay path signs with a real key');

$res = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$pem = '';
openssl_pkey_export($res, $pem);
// Load the consumer bootstraps first (the mailbox plugin registers its own
// signer there), so the stand-in below is the one that answers.
MailIdentityGuard::resolveDkimSigner('bootstrap.invalid');
MailIdentityGuard::registerDkimSigner(function (string $d) use ($other, $pem) {
	return $d === $other ? array('domain' => $other, 'selector' => 'relaysel', 'private_string' => $pem) : null;
});
$relay = new MsoStubRelay();
check($sender->send(mso_message($other), false, new RawRelayComposeTransport('', $relay)) === true,
	'a relay-compose send succeeds');
check(strpos($relay->raw, 'DKIM-Signature:') !== false && strpos($relay->raw, 's=relaysel') !== false,
	'the relayed message carries the signature');
$r = mso_row($other, 'signed');
check($r !== null && $r['mso_path'] === 'relay_compose' && $r['mso_selector'] === 'relaysel'
	&& $r['mso_provider'] === 'mso_relay', 'and is counted signed on the relay_compose path', var_export($r, true));

// ---------------------------------------------------------------------------
section('Dry run, test mode, reserved names');

$count_rows = function () use ($domain) {
	$n = 0;
	foreach (mso_rows($domain) as $r) { $n += (int)$r['mso_count']; }
	return $n;
};
$before = $count_rows();
harness_set_setting_mem('email_dry_run', '1');
$sender->send(mso_message($domain), false, $stub);
harness_set_setting_mem('email_dry_run', '0');
check($count_rows() === $before, 'a dry run is not counted');

harness_set_setting_mem('email_test_mode', '1');
harness_set_setting_mem('email_test_recipient', 'trap@example.com');
$sender->send(mso_message($domain), false, $stub);
harness_set_setting_mem('email_test_mode', '0');
$test_rows = array_filter(mso_rows($domain), function ($r) { return $r['mso_test_mode'] === true || $r['mso_test_mode'] === 't'; });
check(count($test_rows) === 1, 'a test-mode send is counted on its own row, marked test mode');

$sender->send(mso_message('shop.example.com'), false, $stub);
check(!mso_rows('shop.example.com') && MailSendOutcome::isReservedDomain('a.b.test')
	&& !MailSendOutcome::isReservedDomain('example.company.com'), 'a reserved example name is never counted');

// ---------------------------------------------------------------------------
section('No address, and counting never costs a send');

$addresses = 0;
foreach (array_merge(mso_rows($domain), mso_rows($other)) as $r) {
	foreach ($r as $v) {
		if (is_string($v) && strpos($v, '@') !== false) { $addresses++; }
	}
}
check($addresses === 0, 'no stored value is an address');

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
try { $db->exec('SELECT 1/0'); } catch (\Throwable $e) { /* the transaction is now aborted */ }
$sent = $sender->send(mso_message($domain), false, $stub);
$db->rollBack();
check($sent === true, 'a send whose count cannot be written still succeeds');

// ---------------------------------------------------------------------------
section('summaryForDomain()');

$summary = MailSendOutcome::summaryForDomain(strtoupper($domain));
$keys = array('path', 'outcome', 'selector', 'd', 'reason', 'provider', 'test_mode', 'count', 'first_time', 'last_time');
check($summary && array_keys($summary[0]) === $keys, 'each row has the documented fields', var_export($summary[0] ?? null, true));
check((bool)preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $summary[0]['last_time']), 'times are ISO 8601 UTC');
$ordered = true;
for ($i = 1; $i < count($summary); $i++) {
	if (strcmp($summary[$i - 1]['last_time'], $summary[$i]['last_time']) < 0) { $ordered = false; }
}
check($ordered && count($summary) <= MailSendOutcome::SUMMARY_CAP, 'newest first, capped');
check(MailSendOutcome::summaryForDomain('') === array(), 'a blank domain has no summary');

harness_finish();
