<?php
/** @joinery-test
 * name: send_report
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * EmailSender::lastSendReport() (specs/mailbox_message_timeline.md A3) and the
 * carrier-status arithmetic behind DeliveryEventSource (A4).
 *
 *  - An injected transport that accepts: the report names it and carries the
 *    receipt it offered (SendReceiptSource).
 *  - One that refuses: the report carries an error and no receipt.
 *  - One that throws: the exception's text is the error.
 *  - Joinery Direct delivering some recipients: they are listed, and the
 *    transport still gets the rest.
 *  - Direct delivering all: no transport is named — nothing else ran.
 *  - A dry-run send reports nothing beyond the reset (no transport touched).
 *  - MailgunProvider::worstStatus(): each recipient's latest event stands for
 *    that recipient; the worst across recipients stands for the message.
 *  - DebugEmailLog (B1): the columns the sender writes are the columns the
 *    class declares.
 *
 * Run: php tests/run.php safe --filter=send_report
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/MailgunProvider.php'));
require_once(PathHelper::getIncludePath('data/debug_email_logs_class.php'));

/** A transport that does what it is told and can repeat a receipt. */
class SendReportStubTransport implements EmailServiceProvider, SendReceiptSource {
	public $mode = 'accept'; // accept | refuse | throw
	public $sent_to = array();
	public static function getKey(): string { return 'stub_transport'; }
	public static function getLabel(): string { return 'Stub transport (test)'; }
	public static function getSpfMechanism(string $domain): string { return ''; }
	public static function getSettingsFields(): array { return array(); }
	public static function validateConfiguration(): array { return array('valid' => true, 'errors' => array()); }
	public function send(EmailMessage $message): bool {
		$this->sent_to = array_column($message->getRecipients(), 'email');
		if ($this->mode === 'throw') { throw new RuntimeException('stub exploded'); }
		return $this->mode === 'accept';
	}
	public function sendBatch(EmailMessage $message, array $recipients): array {
		return array('success' => true, 'failed_recipients' => array());
	}
	public function lastSendReceipt(): ?array {
		return array('id' => 'stub-queue-1', 'response' => '250 2.0.0 Ok: queued as stub-queue-1');
	}
}

function send_report_message(array $to = array('one@example.com')): EmailMessage {
	$m = new EmailMessage();
	foreach ($to as $addr) { $m->to($addr); }
	$m->from('hello@site.example', 'Site')->subject('report test')->text('body');
	return $m;
}

try {
	harness_set_setting_mem('email_dry_run', '0');
	harness_set_setting_mem('email_test_mode', '0');
	harness_set_setting_mem('defaultemail', 'hello@site.example');
	harness_set_setting_mem('defaultemailname', 'Site');

	section('Injected transport');
	$sender = new EmailSender();
	$stub = new SendReportStubTransport();

	ok('an accepted send returns true', $sender->send(send_report_message(), false, $stub) === true);
	$r = $sender->lastSendReport();
	ok('the report names the transport', $r['transport'] === 'stub_transport');
	ok('and carries its receipt', ($r['receipt']['id'] ?? null) === 'stub-queue-1' && strpos((string)$r['receipt']['response'], '250') === 0);
	ok('no error on success', $r['error'] === null);
	ok('nothing was Direct-delivered', $r['direct_delivered'] === array());

	$stub->mode = 'refuse';
	ok('a refused send returns false', $sender->send(send_report_message(), false, $stub) === false);
	$r = $sender->lastSendReport();
	ok('the report carries an error', is_string($r['error']) && $r['error'] !== '');
	ok('and no receipt', $r['receipt'] === null);

	$stub->mode = 'throw';
	ok('a throwing transport is a false send', $sender->send(send_report_message(), false, $stub) === false);
	ok('with the exception as the error', $sender->lastSendReport()['error'] === 'stub exploded');

	section('Joinery Direct first');
	$stub->mode = 'accept';
	EmailSender::registerDirectAttempt(function (EmailMessage $m) {
		return array('delivered' => array('direct@other.example'), 'remaining' => array('one@example.com'));
	});
	$stub->sent_to = array();
	ok('partial Direct send succeeds', $sender->send(send_report_message(array('direct@other.example', 'one@example.com')), false, $stub) === true);
	$r = $sender->lastSendReport();
	ok('Direct-delivered recipients are listed', $r['direct_delivered'] === array('direct@other.example'));
	ok('the transport took the rest', $r['transport'] === 'stub_transport' && $stub->sent_to === array('one@example.com'));

	EmailSender::registerDirectAttempt(function (EmailMessage $m) {
		return array('delivered' => array_column($m->getRecipients(), 'email'), 'remaining' => array());
	});
	$stub->sent_to = array('untouched');
	ok('all-Direct send succeeds', $sender->send(send_report_message(), false, $stub) === true);
	$r = $sender->lastSendReport();
	ok('no transport is named when Direct delivered everyone', $r['transport'] === null && $stub->sent_to === array('untouched'));
	EmailSender::registerDirectAttempt(function () { return array('delivered' => array(), 'remaining' => array()); });

	section('Dry run');
	harness_set_setting_mem('email_dry_run', '1');
	$sender->send(send_report_message(), false, $stub);
	$r = $sender->lastSendReport();
	ok('a dry run touches no transport and reports none', $r['transport'] === null && $r['receipt'] === null && $r['error'] === null);
	harness_set_setting_mem('email_dry_run', '0');

	section('Carrier status arithmetic');
	$ev = function ($t, $event, $who) { return array('time' => $t, 'event' => $event, 'recipient' => $who, 'detail' => ''); };
	ok('no events → unknown', MailgunProvider::worstStatus(array()) === 'unknown');
	ok('accepted then delivered → delivered', MailgunProvider::worstStatus(array(
		$ev('2026-09-16 10:00:00', 'accepted', 'a@x'), $ev('2026-09-16 10:00:02', 'delivered', 'a@x'))) === 'delivered');
	ok('deferred then delivered → delivered (a retry that landed is not a deferral)', MailgunProvider::worstStatus(array(
		$ev('2026-09-16 10:00:00', 'deferred', 'a@x'), $ev('2026-09-16 10:30:00', 'delivered', 'a@x'))) === 'delivered');
	ok('one delivered, one failed → failed for the message', MailgunProvider::worstStatus(array(
		$ev('2026-09-16 10:00:00', 'delivered', 'a@x'), $ev('2026-09-16 10:00:00', 'failed', 'b@x'))) === 'failed');
	ok('one delivered, one still deferred → deferred', MailgunProvider::worstStatus(array(
		$ev('2026-09-16 10:00:00', 'delivered', 'a@x'), $ev('2026-09-16 10:00:00', 'deferred', 'b@x'))) === 'deferred');
	ok('an event the map does not know counts as unknown, not as a failure', MailgunProvider::worstStatus(array(
		$ev('2026-09-16 10:00:00', 'opened', 'a@x'))) === 'unknown');

	section('B1: the debug log declares what the sender writes');
	$sender_src = file_get_contents(PathHelper::getIncludePath('includes/EmailSender.php'));
	preg_match_all('/\$log->set\(\'(del_[a-z_]+)\'/', $sender_src, $mm);
	$written = array_unique($mm[1]);
	ok('the sender writes at least the message and service', in_array('del_message', $written, true) && in_array('del_service', $written, true));
	$undeclared = array_diff($written, array_keys(DebugEmailLog::$field_specifications));
	ok('every column the sender writes is declared on DebugEmailLog', $undeclared === array(), implode(', ', $undeclared));

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
?>
