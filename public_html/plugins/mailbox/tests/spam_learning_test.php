<?php
/** @joinery-test
 * name: spam_learning
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * LearnSpamFeedback gating (specs/mailbox_spam_filtering_simplification.md D3/D8).
 *
 * The corpus is a deployment-wide asset, so the task's only question is whether
 * this deployment learns — not which path a message arrived by. Two behaviors
 * are load-bearing and easy to regress:
 *
 *  1. A webhook-sourced correction is NEVER written off. The task used to mark
 *     the whole diverged set handled the moment it saw a webhook provider, on
 *     the reasoning that such a deployment had no local rspamd. That stopped
 *     being true: a learning deployment scans webhook mail itself at ingest, so
 *     those corrections belong in its corpus like any other. If the branch ever
 *     comes back, this test fails — the row would come back reconciled.
 *
 *  2. A scanner that is missing or down defers; it never destroys work. Rows
 *     stay diverged and are taught on a later pass, which is also what lets the
 *     corpus rebuild itself after a wipe.
 *
 * The controller is pointed at a closed port so "unreachable" is a fact of the
 * test, not of the host: the assertions hold whether or not rspamd is installed
 * on the machine running them.
 *
 * Run: php tests/run.php db --filter=spam_learning
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/LearnSpamFeedback.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

/** A port nothing listens on, so controllerReachable() is deterministically false. */
const DEAD_CONTROLLER = 'http://127.0.0.1:11399';

class SpamLearningTest {

	private $db;
	private $message_id = 0;

	function __construct() {
		$this->db = DbConnector::get_instance()->get_db_link();
	}

	/** A stored message whose verdict diverges from what was last taught. */
	private function makeDivergedWebhookMessage(): void {
		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'spam-learn-test.example');
		$domain->set('ied_is_enabled', false);
		$domain->save();
		$domain_id = intval($domain->key);
		harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', $domain_id);

		// Inserted directly: the point is a row in the diverged state, and going
		// through ingest would drag in aliases, vaults and filters that have
		// nothing to do with what is being tested.
		$q = $this->db->prepare(
			"INSERT INTO iem_inbound_email_messages
			 (iem_ied_inbound_email_domain_id, iem_sender, iem_recipient, iem_subject,
			  iem_raw_message, iem_auth_source, iem_spam_verdict, iem_learned_verdict)
			 VALUES (?, ?, ?, ?, ?, 'mailgun', ?, NULL)
			 RETURNING iem_inbound_email_message_id");
		$q->execute(array($domain_id, 'sender@spam-learn-test.example',
			'user@spam-learn-test.example', 'diverged fixture',
			"From: sender@spam-learn-test.example\nSubject: diverged fixture\n\nbody",
			InboundEmailMessage::SPAM_VERDICT_SPAM));
		$this->message_id = (int)$q->fetchColumn();
		harness_register_model('InboundEmailMessage', $this->message_id);
	}

	/** What the row's learned marker currently says (null = never taught). */
	private function learnedVerdict() {
		$q = $this->db->prepare(
			'SELECT iem_learned_verdict FROM iem_inbound_email_messages
			  WHERE iem_inbound_email_message_id = ?');
		$q->execute(array($this->message_id));
		return $q->fetchColumn();
	}

	function run() {
		$task = new LearnSpamFeedback();

		section('learning switch');
		harness_set_setting_mem('mailbox_spam_filtering_enabled', '1');
		harness_set_setting_mem('mailbox_spam_learning_enabled', '0');
		harness_set_setting_mem('mailbox_provider', 'postfix');
		MailboxSpamPolicy::reset();
		$r = $task->run(array());
		check(($r['status'] ?? '') === 'skipped', 'learning off → the task is a no-op',
			'status = ' . ($r['status'] ?? '(none)'));

		// Filing off clamps learning off, whatever the learning row says — so the
		// task must not run against a deployment that files nothing.
		harness_set_setting_mem('mailbox_spam_filtering_enabled', '0');
		harness_set_setting_mem('mailbox_spam_learning_enabled', '1');
		MailboxSpamPolicy::reset();
		$r = $task->run(array());
		check(($r['status'] ?? '') === 'skipped',
			'filing off clamps learning off → still a no-op',
			'status = ' . ($r['status'] ?? '(none)'));

		section('scanner unreachable');
		$this->makeDivergedWebhookMessage();
		check($this->learnedVerdict() === null, 'fixture starts diverged (never taught)');

		harness_set_setting_mem('mailbox_spam_filtering_enabled', '1');
		harness_set_setting_mem('mailbox_spam_learning_enabled', '1');
		harness_set_setting_mem('mailbox_rspamd_controller_url', DEAD_CONTROLLER);
		MailboxSpamPolicy::reset();

		$r = $task->run(array());
		check(($r['status'] ?? '') === 'skipped',
			'scanner unreachable → skipped, not an error',
			'status = ' . ($r['status'] ?? '(none)'));
		check(strpos((string)($r['message'] ?? ''), DEAD_CONTROLLER) !== false,
			'the skip message names the endpoint that is not answering',
			'message = ' . (string)($r['message'] ?? ''));
		check($this->learnedVerdict() === null,
			'the diverged row is untouched, so it is taught on a later pass');

		section('webhook-sourced corrections are not written off');
		// The deleted branch fired on the PROVIDER, before the controller was ever
		// consulted. Re-running under a webhook provider must therefore still
		// leave the row diverged.
		harness_set_setting_mem('mailbox_provider', 'mailgun');
		MailboxSpamPolicy::reset();
		$r = $task->run(array());
		check(($r['status'] ?? '') === 'skipped',
			'webhook provider does not short-circuit the run',
			'status = ' . ($r['status'] ?? '(none)'));
		check($this->learnedVerdict() === null,
			'a webhook-sourced correction is NOT marked handled — it waits to be taught');
		check(!method_exists('LearnSpamFeedback', 'markAllHandled'),
			'the mark-everything-handled shortcut is gone from the class');
	}
}

$test = new SpamLearningTest();
$test->run();
harness_finish();
