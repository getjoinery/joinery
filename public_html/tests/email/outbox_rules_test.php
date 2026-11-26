<?php
/** @joinery-test
 * name: outbox_rules
 * tier: db
 * env: any
 * needs: []
 * covers: [includes/EmailSender.php, includes/EmailMessage.php]
 */
/**
 * What the outbox keeps stays system mail (specs/email_outbox.md §8, §6b):
 *
 *  - every file outside the tests that makes an EmailSender is listed here,
 *    either as recorded system mail or as one that marks its message
 *    unrecorded (or sends through an injected transport, which is never
 *    recorded), so a new sender is a reviewed choice;
 *  - no email template's subject has a placeholder named like a secret
 *    (*code*, *token*, *password*, *link*, *url*): subjects are kept for the
 *    retention window;
 *  - nothing outside migrations/ and specs/ names the debug email log.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = rtrim(PathHelper::getIncludePath(''), '/');

/** Recorded: system mail, kept on the outbox. */
$recorded = array(
	'data/queued_emails_class.php'                           => 'the task sending an outbox row',
	'includes/Activation.php'                                 => 'sign-in and verification emails',
	'includes/Notify.php'                                     => 'notification emails (queue())',
	'includes/SandboxRelay.php'                               => 'a plugin\'s mail, sent through the site',
	'plugins/bookings/logic/booking_manage_logic.php'         => 'booking cancelled notices',
	'plugins/bookings/logic/book_logic.php'                   => 'booking confirmations',
	'plugins/bookings/tasks/BookingEmailsTask.php'            => 'booking reminders and follow-ups',
	'plugins/email_lists/includes/EmailListSubscriptions.php' => 'confirm your subscription',
	'plugins/joinery_ai/includes/CostGuard.php'               => 'a cost alert to the operator',
	'plugins/mailbox/includes/DeliverabilityReportIngest.php' => 'a deliverability report to the operator',
	'plugins/mailbox/includes/ForwardConfirmation.php'        => 'confirm a forwarding address',
	'plugins/server_manager/includes/InstanceTransfers.php'   => 'transfer emails to a buyer',
	'plugins/server_manager/includes/JobResultProcessor.php'  => 'your site is ready',
	'utils/email_send_test.php'                               => 'an operator\'s test send',
);
/** Left out: each marks its message unrecorded, or sends through an injected transport. */
$unrecorded = array(
	'data/direct_messages_class.php'                     => 'unrecorded(',
	'plugins/email_lists/includes/CampaignSender.php'    => 'unrecorded(',
	'plugins/joinery_ai/includes/RecipeRunner.php'       => 'unrecorded(',
	'plugins/mailbox/includes/InboundEmailRouter.php'    => 'unrecorded(',
	'plugins/mailbox/includes/MailTestProbe.php'         => 'unrecorded(',
	'plugins/mailbox/includes/InboundEmailHealth.php'    => '$result->transport',
	'plugins/mailbox/includes/MailboxSender.php'         => '$transport',
);

section('Every sender is a reviewed choice (§8)');
$found = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	$path = $file->getPathname();
	$rel = ltrim(substr($path, strlen($root)), '/');
	if (substr($rel, -4) !== '.php' || preg_match('#(^|/)(tests|vendor|specs|node_modules)/#', $rel)) {
		continue;
	}
	if (strpos((string)@file_get_contents($path), 'new EmailSender') !== false) {
		$found[] = $rel;
	}
}
sort($found);
$listed = array_merge(array_keys($recorded), array_keys($unrecorded));
sort($listed);
check(array_diff($found, $listed) === array(), 'every file that makes an EmailSender is listed here',
	implode(', ', array_diff($found, $listed)));
check(array_diff($listed, $found) === array(), 'and every file listed still does', implode(', ', array_diff($listed, $found)));
foreach ($unrecorded as $rel => $mark) {
	check(strpos((string)@file_get_contents($root . '/' . $rel), $mark) !== false, $rel . ' leaves its mail out (' . $mark . ')');
}

section('No template subject names a secret (§8)');
$secret = '~\*[a-z_]*(code|token|password|link|url)[a-z_]*\*~i';
$q = DbConnector::get_instance()->get_db_link()->query('SELECT emt_name, emt_subject FROM emt_email_templates WHERE emt_delete_time IS NULL');
$bad = array();
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
	if (preg_match($secret, (string)$t['emt_subject'])) {
		$bad[] = $t['emt_name'] . ': ' . $t['emt_subject'];
	}
}
check($bad === array(), 'no stored template\'s subject', implode('; ', $bad));
$shipped = array();
foreach (glob($root . '/plugins/*/migrations/migrations.php') ?: array() as $m) {
	// Seeded subjects: the second value of each emt_email_templates insert row.
	if (preg_match_all("~array\\('([a-z0-9_]+)',\\s*'([^']*\\*[^']*)'~", (string)file_get_contents($m), $mm, PREG_SET_ORDER)) {
		foreach ($mm as $hit) {
			if (preg_match($secret, $hit[2])) {
				$shipped[] = $hit[1] . ': ' . $hit[2];
			}
		}
	}
}
check($shipped === array(), 'no seeded template\'s subject', implode('; ', $shipped));

section('The debug email log is gone (E1)');
$named = array();
foreach ($it as $file) {
	$path = $file->getPathname();
	$rel = ltrim(substr($path, strlen($root)), '/');
	if (!preg_match('/\.(php|json|md|js)$/', $rel) || preg_match('#(^|/)(migrations|specs|vendor|node_modules)/#', $rel)
			|| in_array($rel, array('tests/email/outbox_rules_test.php', 'tests/email/outbox_test.php'), true)) {
		continue;
	}
	$src = (string)@file_get_contents($path);
	foreach (array('DebugEmailLog', 'logEmailDebug', 'email_debug_mode') as $word) {
		if (strpos($src, $word) !== false) {
			$named[] = $rel . ' (' . $word . ')';
		}
	}
}
check($named === array(), 'nothing outside migrations/ and specs/ names it', implode(', ', $named));

harness_finish();
