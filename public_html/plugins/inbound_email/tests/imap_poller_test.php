<?php
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
 *
 * Run: php plugins/inbound_email/tests/imap_poller_test.php  (requires schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/tasks/PollImapAccounts.php'));

class ImapPollerTest {
	private $pass = 0;
	private $fail = 0;
	private $db;
	private $suffix;
	private $domain_id;
	private $alias;
	private $account_ids = array();

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		if ($c) { $this->pass++; $this->out('  PASS: ' . $l); }
		else { $this->fail++; $this->out('  FAIL: ' . $l); }
	}

	function run() {
		$this->out('=== IMAP poller / store-path tests ===');
		try {
			$this->setUp();
			$this->testReferenceBackedStore();
			$this->testDedup();
			$this->testLargeSizeStillStored();
			$this->testPollerSummary();
		} catch (\Throwable $e) {
			$this->fail++;
			$this->out('  EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
		$this->out("=== {$this->pass} passed, {$this->fail} failed ===");
		return $this->fail === 0;
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

		// No due accounts for this fixture's alias yet (none created): the global
		// run still succeeds. (Other accounts on the system may exist; we only
		// assert the run does not throw and returns success.)
		$result = $task->run(array('polling_enabled' => true, 'max_per_account' => 5));
		$this->ok(is_array($result) && ($result['status'] === 'success' || $result['status'] === 'skipped'),
			'poller run returns a status array without throwing');

		// Disabled-by-config short-circuits to skipped.
		$skipped = $task->run(array('polling_enabled' => false));
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

		$result2 = $task->run(array('polling_enabled' => true, 'max_per_account' => 5));
		$this->ok($result2['status'] === 'success', 'run with an uncredentialed account still succeeds');

		$reloaded = new InboundImapAccount($acct->key, TRUE);
		$this->ok($reloaded->get('iia_last_poll_time') !== null, 'uncredentialed account was claimed (poll time stamped)');
		$this->ok(stripos((string)$reloaded->get('iia_last_status'), 'credential') !== false,
			'uncredentialed account status explains why it was skipped');
	}

	private function tearDown() {
		try {
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
$ok = $test->run();
exit($ok ? 0 : 1);
?>
