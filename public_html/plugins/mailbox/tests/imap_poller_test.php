<?php
/** @joinery-test
 * name: imap_poller
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Tests for the IMAP ingest store path + poller task, without a live IMAP server.
 *
 * The live connect/fetch is wrapped behind Horde and exercised by the manual
 * end-to-end checklist (real Gmail/Microsoft consent — see the plugin docs). What
 * is unit-testable here is the platform side:
 *
 *  - InboundEmailRouter::storeExtracted writes the bodies + locator columns, leaves
 *    iem_raw_message empty, and marks the row reference-backed.
 *  - Dedup (UNIQUE message-id + recipient) makes a re-store a no-op.
 *  - A large RFC822.SIZE with a tiny body is still stored (size never gates ingest).
 *  - PollImapAccounts.run() polls only due/enabled accounts, skips uncredentialed
 *    ones, and reports a summary without throwing (one bad account ≠ failed run).
 *  - Run-record accounting: failures roll up by reason, stored + duplicate + failed
 *    reconcile against the UIDs walked, and a shortfall marks the run unsuccessful.
 *
 * Run: php plugins/mailbox/tests/imap_poller_test.php  (requires schema synced).
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/PollImapAccounts.php'));

class ImapPollerTest {
	private $db;
	private $suffix;
	private $domain_id;
	private $alias;
	private $account_ids = array();

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		return check((bool)$c, $l);
	}

	function run() {
		section('IMAP poller / store-path tests');
		try {
			$this->setUp();
			$this->testReferenceBackedStore();
			$this->testDedup();
			$this->testLargeSizeStillStored();
			$this->testPollerSummary();
			$this->testRunRecordAccounting();
			$this->testRunRecordWrite();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('pol', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'poll-test-' . $this->suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->set('ied_is_imap_source', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$a = new InboundEmailAlias(NULL);
		$a->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$a->set('iea_alias', 'in' . $this->suffix);
		$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$a->set('iea_is_enabled', true);
		$a->prepare(); $a->save();
		$this->alias = $a;

		$this->out('  fixtures ready (suffix ' . $this->suffix . ')');
	}

	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'poll-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			if ($dids) {
				$in = implode(',', array_map('intval', $dids));
				$aids = $this->db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
					WHERE iea_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
				if ($aids) {
					$ain = implode(',', array_map('intval', $aids));
					$this->db->exec("DELETE FROM iia_inbound_imap_accounts WHERE iia_iea_inbound_email_alias_id IN ($ain)");
				}
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id IN ($in)");
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in)");
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id IN ($in)");
			}
		} catch (\Throwable $e) {}
	}

	private function domain(): InboundEmailDomain {
		return new InboundEmailDomain($this->domain_id, TRUE);
	}

	private function msg($mid, $extra = array()): array {
		return array_merge(array(
			'sender' => 'Sender <from@example.test>',
			'subject' => 'Hello ' . $mid,
			'body_plain' => 'plain body ' . $mid,
			'body_html' => '<p>html body ' . $mid . '</p>',
			'message_id_header' => $mid,
			'headers' => array('message-id' => $mid),
			'size_bytes' => 1234,
			'imap_account_id' => 999,
			'imap_uid' => 10,
			'imap_uidvalidity' => 555,
			'imap_folder' => 'INBOX',
		), $extra);
	}

	private function testReferenceBackedStore() {
		$router = new InboundEmailRouter();
		$recipient = strtolower($this->alias->get_full_address());
		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');

		$mid = '<ref-' . $this->suffix . '@x>';
		$res = $router->storeExtracted($this->msg($mid), $this->alias, $this->domain(), $recipient, $auth);
		$this->ok(!$res['dedup'] && $res['message'] !== null, 'storeExtracted stores a new message');

		$row = $this->db->query("SELECT * FROM iem_inbound_email_messages
			WHERE iem_inbound_email_message_id = " . intval($res['message']->key))->fetch(PDO::FETCH_ASSOC);
		$this->ok($row['iem_raw_message'] === '' || $row['iem_raw_message'] === null, 'iem_raw_message left empty (reference-backed)');
		$this->ok($row['iem_body_plain'] === 'plain body ' . $mid, 'body_plain stored');
		$this->ok(intval($row['iem_iia_inbound_imap_account_id']) === 999, 'imap account id locator stored');
		$this->ok(intval($row['iem_imap_uid']) === 10, 'imap uid locator stored');
		$this->ok(intval($row['iem_imap_uidvalidity']) === 555, 'imap uidvalidity locator stored');
		$this->ok($row['iem_imap_folder'] === 'INBOX', 'imap folder locator stored');
	}

	private function testDedup() {
		$router = new InboundEmailRouter();
		$recipient = strtolower($this->alias->get_full_address());
		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');

		$mid = '<dup-' . $this->suffix . '@x>';
		$first = $router->storeExtracted($this->msg($mid), $this->alias, $this->domain(), $recipient, $auth);
		$this->ok(!$first['dedup'], 'first store is not a dedup');

		$second = $router->storeExtracted($this->msg($mid), $this->alias, $this->domain(), $recipient, $auth);
		$this->ok($second['dedup'], 're-store of same message-id+recipient is a dedup (no new row)');

		$count = $this->db->query("SELECT COUNT(*) FROM iem_inbound_email_messages
			WHERE iem_message_id_header = " . $this->db->quote($mid)
			. " AND iem_recipient = " . $this->db->quote($recipient))->fetchColumn();
		$this->ok(intval($count) === 1, 'dedup leaves exactly one row');
	}

	private function testLargeSizeStillStored() {
		$router = new InboundEmailRouter();
		$recipient = strtolower($this->alias->get_full_address());
		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');

		// 25 MB reported size, tiny body — must still be ingested (size never gates).
		$mid = '<big-' . $this->suffix . '@x>';
		$res = $router->storeExtracted(
			$this->msg($mid, array('size_bytes' => 25 * 1024 * 1024, 'body_plain' => 'one line')),
			$this->alias, $this->domain(), $recipient, $auth
		);
		$this->ok(!$res['dedup'] && $res['message'] !== null, 'large-size message with tiny body is still stored');
		$this->ok(intval($res['message']->get('iem_size_bytes')) === 25 * 1024 * 1024, 'RFC822.SIZE recorded for display');
	}

	private function testPollerSummary() {
		$task = new PollImapAccounts();

		// CRITICAL: every run here is scoped to this fixture's alias. Without the
		// scope the task loads and CLAIMS every enabled+due account on the box —
		// opening live IMAP connections to real Gmail/Microsoft accounts, stamping
		// their poll cursors, and racing the real cron — which made this db-tier
		// test behave like a live one. The alias scope keeps it hermetic.
		$scope = array('polling_enabled' => true, 'max_per_account' => 5, 'alias_id' => $this->alias->key);

		// No due accounts for this fixture's alias yet (none created): the scoped
		// run still succeeds with nothing to do.
		$result = $task->run($scope);
		$this->ok(is_array($result) && ($result['status'] === 'success' || $result['status'] === 'skipped'),
			'poller run returns a status array without throwing');

		// Disabled-by-config short-circuits to skipped.
		$skipped = $task->run(array('polling_enabled' => false, 'alias_id' => $this->alias->key));
		$this->ok($skipped['status'] === 'skipped', 'polling_enabled=false → skipped');

		// An enabled, due, but UNCREDENTIALED account is skipped (status recorded),
		// and the run still succeeds — one bad account never fails the run.
		$acct = new InboundImapAccount(NULL);
		$acct->set('iia_label', 'Uncredentialed ' . $this->suffix);
		$acct->set('iia_provider_key', 'imap_generic');
		$acct->set('iia_imap_host', 'imap.invalid.test');
		$acct->set('iia_iea_inbound_email_alias_id', $this->alias->key);
		$acct->set('iia_username', 'nobody@example.test');
		$acct->set('iia_is_enabled', true);
		$acct->prepare(); $acct->save();
		$this->account_ids[] = intval($acct->key);

		$result2 = $task->run($scope);
		$this->ok($result2['status'] === 'success', 'run with an uncredentialed account still succeeds');

		$reloaded = new InboundImapAccount($acct->key, TRUE);
		$this->ok($reloaded->get('iia_last_poll_time') !== null, 'uncredentialed account was claimed (poll time stamped)');
		$this->ok(stripos((string)$reloaded->get('iia_last_status'), 'credential') !== false,
			'uncredentialed account status explains why it was skipped');

		// One fetch per mailbox at a time: while a rival connection holds the
		// account's fetch lock (a Fetch now mid-run, from poll()'s perspective),
		// a second poll refuses fast — before any IMAP connection — with the
		// busy exception, not a generic error.
		section('Fetch lock');
		$settings = Globalvars::get_instance();
		$rival = new PDO('pgsql:host=localhost port=5432 dbname=' . $settings->get_setting('dbname')
			. ' user=' . $settings->get_setting('dbusername')
			. ' password=' . $settings->get_setting('dbpassword'));
		$take = $rival->prepare('SELECT pg_try_advisory_lock(?, ?)');
		$take->execute(array(ImapIngestor::FETCH_LOCK_CLASS, intval($acct->key)));
		$this->ok((bool)$take->fetchColumn(), 'rival connection takes the fetch lock');
		$busy = false;
		try {
			(new ImapIngestor(new InboundImapAccount($acct->key, TRUE)))->poll(5);
		} catch (ImapFetchBusyException $e) {
			$busy = true;
		} catch (Throwable $e) {
			// any other failure mode means the guard did not fire first
		}
		$this->ok($busy, 'poll() refuses while another fetch holds the lock');
		$rival->prepare('SELECT pg_advisory_unlock(?, ?)')
			->execute(array(ImapIngestor::FETCH_LOCK_CLASS, intval($acct->key)));
		$rival = null;
	}

	/**
	 * summarizeRun() is the pure accounting stage — no DB, no IMAP. It is what turns
	 * a pile of counters into the one line a human reads, so it gets the direct test.
	 */
	private function testRunRecordAccounting() {
		// A clean run: everything walked is accounted for.
		$s = ImapIngestor::summarizeRun(array('seen' => 10, 'stored' => 8, 'dedup' => 2,
			'failed' => 0, 'failed_detail' => array()));
		$this->ok($s['success'] === true, 'fully reconciled run is successful');
		$this->ok($s['unaccounted'] === 0, 'clean run has nothing unaccounted');
		$this->ok(strpos($s['note'], 'seen 10, stored 8, duplicates 2, out of scope 0, failed 0.') !== false,
			'note carries the counts');

		// Repeated failures collapse to one line with a count.
		$detail = array();
		for ($i = 0; $i < 3; $i++) {
			$detail[] = array('uid' => 100 + $i, 'folder' => 'INBOX', 'reason' => 'Body decode failed.');
		}
		$detail[] = array('uid' => 200, 'folder' => 'Sent', 'reason' => 'Envelope missing.');
		$s = ImapIngestor::summarizeRun(array('seen' => 6, 'stored' => 2, 'dedup' => 0,
			'failed' => 4, 'failed_detail' => $detail));
		$this->ok($s['failed_reasons']['Body decode failed.'] === 3, 'identical reasons roll up to one entry');
		$this->ok(count($s['failed_reasons']) === 2, 'distinct reasons stay distinct');
		$this->ok($s['success'] === false, 'a run with failures is not successful');
		$this->ok(strpos($s['note'], 'x3: Body decode failed.') !== false, 'note lists the rolled-up reason');

		// The tripwire: messages walked but neither stored, deduped, nor reported.
		$s = ImapIngestor::summarizeRun(array('seen' => 10, 'stored' => 4, 'dedup' => 1,
			'failed' => 0, 'failed_detail' => array()));
		$this->ok($s['unaccounted'] === 5, 'silent shortfall is counted');
		$this->ok($s['success'] === false, 'a silent shortfall marks the run unsuccessful');
		$this->ok(strpos($s['note'], 'unaccounted 5') !== false, 'note names the shortfall');
	}

	/**
	 * recordRun() writes the durable row — and stays quiet on an idle poll, or a
	 * mailbox polled every few minutes would bury real runs under no-op rows.
	 */
	private function testRunRecordWrite() {
		$acct = new InboundImapAccount(NULL);
		$acct->set('iia_label', 'RunRecord ' . $this->suffix);
		$acct->set('iia_provider_key', 'imap_generic');
		$acct->set('iia_imap_host', 'imap.invalid.test');
		$acct->set('iia_iea_inbound_email_alias_id', $this->alias->key);
		$acct->set('iia_username', 'runrecord@example.test');
		$acct->set('iia_is_enabled', true);
		$acct->prepare(); $acct->save();
		$this->account_ids[] = intval($acct->key);

		$record = new ReflectionMethod('ImapIngestor', 'recordRun');
		$ingestor = new ImapIngestor($acct);

		$before = $this->runRecordCount($acct->key);

		// Idle poll: nothing happened, so nothing is written.
		$record->invoke($ingestor, array('seen' => 0, 'stored' => 0, 'dedup' => 0,
			'failed' => 0, 'failed_detail' => array()));
		$this->ok($this->runRecordCount($acct->key) === $before, 'idle poll writes no run record');

		// A poll that stored mail writes one successful row.
		$record->invoke($ingestor, array('seen' => 3, 'stored' => 3, 'dedup' => 0,
			'failed' => 0, 'failed_detail' => array()));
		$this->ok($this->runRecordCount($acct->key) === $before + 1, 'a poll that stored mail writes a run record');

		$row = $this->db->query("SELECT * FROM evl_event_logs
			WHERE evl_event = " . $this->db->quote(ImapIngestor::RUN_EVENT) . "
			  AND evl_note LIKE " . $this->db->quote('%account ' . intval($acct->key) . ' %') . "
			ORDER BY evl_event_log_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
		$this->ok($row && $this->truthy($row['evl_was_success']), 'clean run is recorded as successful');

		// A poll that lost messages writes an unsuccessful row naming the reason.
		$record->invoke($ingestor, array('seen' => 2, 'stored' => 1, 'dedup' => 0, 'failed' => 1,
			'failed_detail' => array(array('uid' => 7, 'folder' => 'INBOX', 'reason' => 'Fetch timed out.'))));
		$row = $this->db->query("SELECT * FROM evl_event_logs
			WHERE evl_event = " . $this->db->quote(ImapIngestor::RUN_EVENT) . "
			  AND evl_note LIKE " . $this->db->quote('%account ' . intval($acct->key) . ' %') . "
			ORDER BY evl_event_log_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
		$this->ok($row && !$this->truthy($row['evl_was_success']), 'a run with failures is recorded as unsuccessful');
		$this->ok($row && strpos($row['evl_note'], 'Fetch timed out.') !== false,
			'the failure reason survives into the stored note');
	}

	private function runRecordCount($accountId): int {
		return intval($this->db->query("SELECT COUNT(*) FROM evl_event_logs
			WHERE evl_event = " . $this->db->quote(ImapIngestor::RUN_EVENT) . "
			  AND evl_note LIKE " . $this->db->quote('%account ' . intval($accountId) . ' %'))->fetchColumn());
	}

	private function truthy($v): bool {
		return ($v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true');
	}

	private function tearDown() {
		try {
			foreach ($this->account_ids as $aid) {
				$this->db->exec("DELETE FROM evl_event_logs
					WHERE evl_event = " . $this->db->quote(ImapIngestor::RUN_EVENT) . "
					  AND evl_note LIKE " . $this->db->quote('%account ' . intval($aid) . ' %'));
			}
			if ($this->domain_id) {
				$aids = $this->db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
					WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id))->fetchAll(PDO::FETCH_COLUMN);
				if ($aids) {
					$ain = implode(',', array_map('intval', $aids));
					$this->db->exec("DELETE FROM iia_inbound_imap_accounts WHERE iia_iea_inbound_email_alias_id IN ($ain)");
				}
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
		} catch (\Throwable $e) {}
	}
}

$test = new ImapPollerTest();
$test->run();
harness_finish();
