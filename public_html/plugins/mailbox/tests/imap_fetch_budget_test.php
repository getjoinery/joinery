<?php
/** @joinery-test
 * name: imap_fetch_budget
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A fetch cycle says where its clock went, and an interactive one stops when
 * its time is up (specs/implemented/mailbox_refresh_budget.md).
 *
 * The live failure this pins: one Refresh click on jeremytunnell held the
 * browser for 145 seconds, the proxy cut the connection at 100, and no log on
 * the node could say which of the cycle's phases took the time. So: the ledger
 * is asserted on the result, the account status and the run record; the
 * deadline is asserted at both places it can stop the walk — between messages
 * (the single-folder poll) and between folders (the tracked-folder poll and the
 * sync pull) — with the cursor untouched and the leftovers counted as deferred,
 * never as seen. No live server: an in-memory client stands in for IMAP.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapSyncer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapFetch.php'));

class BudgetFetchData {
	public function getFlags() { return array(); }
	public function getImapDate() { return new Horde_Imap_Client_DateTime(gmdate('Y-m-d H:i:s')); }
}

class BudgetFetchResults implements IteratorAggregate, ArrayAccess {
	private $rows;
	public function __construct(array $rows) { $this->rows = $rows; }
	public function getIterator(): Iterator { return new ArrayIterator($this->rows); }
	public function ids() { return array_keys($this->rows); }
	public function offsetExists($offset): bool { return isset($this->rows[$offset]); }
	#[\ReturnTypeWillChange]
	public function offsetGet($offset) { return $this->rows[$offset] ?? null; }
	public function offsetSet($offset, $value): void { $this->rows[$offset] = $value; }
	public function offsetUnset($offset): void { unset($this->rows[$offset]); }
}

/** An in-memory server: folders carry uids; every fetch is recorded. */
class BudgetImapClient implements ImapClient {
	public $folders = array();  // name => ['uidvalidity'=>, 'highestmodseq'=>, 'uids'=>[...]]
	public $fetches = array();

	public function status(string $mailbox, int $flags): array {
		$f = $this->folders[$mailbox] ?? array();
		$uids = $f['uids'] ?? array();
		return array('uidvalidity' => $f['uidvalidity'] ?? 1, 'highestmodseq' => $f['highestmodseq'] ?? 1,
			'uidnext' => ($uids ? max($uids) : 0) + 1, 'messages' => count($uids));
	}
	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array()) {
		$this->fetches[] = $mailbox;
		$spec = (string)($options['ids'] ?? '');
		list($from, $to) = strpos($spec, ':') !== false ? explode(':', $spec, 2) : array(0, PHP_INT_MAX);
		$rows = array();
		foreach (($this->folders[$mailbox]['uids'] ?? array()) as $uid) {
			if ($uid >= intval($from) && $uid <= intval($to)) { $rows[$uid] = new BudgetFetchData(); }
		}
		return new BudgetFetchResults($rows);
	}
	public function search(string $mailbox, $query = null, array $options = array()): array { return array(); }
	public function store(string $mailbox, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function copy(string $source, string $dest, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function expunge(string $mailbox, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function append(string $mailbox, array $data, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function vanished(string $mailbox, int $modseq, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function listMailboxes($pattern, int $mode = Horde_Imap_Client::MBOX_ALL, array $options = array()): array { return array(); }
	public function createMailbox(string $mailbox): void {}
	public function queryCapability(string $capability): bool { return $capability === 'CONDSTORE'; }
	public function logout(): void {}
}

class ImapFetchBudgetTest {
	private $db; private $suffix; private $domain_id; private $alias; private $account;

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	function run() {
		$this->testDescribeTiming();
		$this->setUp();
		try {
			$this->testUnboundedPollCarriesTiming();
			$this->testDeadlineStopsBetweenMessages();
			$this->testDeadlineStopsBetweenFolders();
			$this->testDeadlineStopsPull();
			$this->testFetchReportsBudgetAndLeavesDue();
		} finally {
			$this->purge();
		}
	}

	// ── pure ───────────────────────────────────────────────────────────────

	private function testDescribeTiming() {
		section('describeTiming: one line a person reads');
		$timing = array('connect' => 0.51, 'pull' => 1.14, 'push' => 0.06,
			'folders' => array('INBOX' => array('seek' => 0.3, 'fetch' => 1.2, 'store' => 0.6),
			                   'Sent' => array('seek' => 0.1)));
		$line = ImapIngestor::describeTiming($timing, 4.2);
		check($line === 'took 4.2s: connect 0.5s, pull 1.1s, INBOX 2.1s (seek 0.3s, fetch 1.2s, store 0.6s), Sent 0.1s, push 0.1s',
			'phases in cycle order, folders summed with their detail, push last', $line);
		$short = ImapIngestor::describeTiming($timing, 4.2, false);
		check(strpos($short, '(') === false && strpos($short, 'INBOX 2.1s') !== false,
			'the status-column form keeps folder totals and drops the detail', $short);
		check(ImapIngestor::describeTiming(array(), 0.04) === 'took 0.0s',
			'an empty ledger is just the total');
	}

	// ── fixtures ───────────────────────────────────────────────────────────

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('bud', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'budget-test-' . $this->suffix . '.example');
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

		$acc = new InboundImapAccount(NULL);
		$acc->set('iia_label', 'Budget ' . $this->suffix);
		$acc->set('iia_provider_key', 'imap_generic');
		$acc->set('iia_imap_host', 'imap.test');
		$acc->set('iia_iea_inbound_email_alias_id', $this->alias->key);
		$acc->set('iia_username', 'me@budget-test.example');
		$acc->set('iia_is_enabled', true);
		$acc->set('iia_supports_condstore', true);
		$acc->set('iia_sync_mode', 'off');
		$acc->set('iia_import_scope', InboundImapAccount::SCOPE_FULL);
		$acc->prepare(); $acc->save();
		$this->account = new InboundImapAccount($acc->key, TRUE);
	}

	private function reloadAccount(): void {
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	private function setSyncMode(string $mode): void {
		$this->account->set('iia_sync_mode', $mode);
		$this->account->prepare(); $this->account->save();
		$this->reloadAccount();
	}

	private function makeFolder(string $name, ?string $role, ?int $modseq): InboundImapFolder {
		$f = InboundImapFolder::upsert(intval($this->account->key), $name, $role, true);
		$f->set('iif_uidvalidity', 1);
		$f->set('iif_last_seen_uid', 0);
		$f->set('iif_last_sync_modseq', $modseq);
		$f->prepare(); $f->save();
		return new InboundImapFolder($f->key, TRUE);
	}

	private function cursor(string $name): ?int {
		$q = $this->db->prepare('SELECT iif_last_seen_uid FROM iif_inbound_imap_folders
			WHERE iif_iia_inbound_imap_account_id = ? AND iif_name = ?');
		$q->execute(array($this->account->key, $name));
		$v = $q->fetchColumn();
		return $v === false || $v === null ? null : intval($v);
	}

	private function client(): BudgetImapClient {
		$c = new BudgetImapClient();
		$c->folders['INBOX'] = array('uidvalidity' => 1, 'highestmodseq' => 9, 'uids' => array(1, 2, 3));
		$c->folders['Sent']  = array('uidvalidity' => 1, 'highestmodseq' => 9, 'uids' => array(1, 2));
		return $c;
	}

	private function status(): string {
		$q = $this->db->prepare('SELECT iia_last_status FROM iia_inbound_imap_accounts WHERE iia_inbound_imap_account_id = ?');
		$q->execute(array($this->account->key));
		return (string)$q->fetchColumn();
	}

	// ── the ledger ─────────────────────────────────────────────────────────

	private function testUnboundedPollCarriesTiming() {
		section('an unbounded poll walks everything and carries its ledger');
		$client = $this->client();
		$ingestor = new ImapIngestor($this->account, $client);
		$res = $ingestor->poll(50);

		check(intval($res['seen']) === 3, 'every message was walked', 'seen=' . $res['seen']);
		check(intval($res['deferred'] ?? 0) === 0 && $ingestor->budgetExhausted() === false,
			'nothing deferred without a deadline');
		$t = $res['timing'] ?? array();
		check(isset($t['folders']['INBOX']['seek']) && isset($t['folders']['INBOX']['fetch'])
			&& isset($t['folders']['INBOX']['store']),
			'the folder is lapped as seek / fetch / store', json_encode($t));
		// The stand-in fetch data has no structure, so every message fails to
		// ingest — and a poll with failures leaves a run record.
		$q = $this->db->query("SELECT evl_note FROM evl_event_logs WHERE evl_event = 'mailbox_imap_ingest'
			ORDER BY evl_event_log_id DESC LIMIT 1");
		$note = (string)$q->fetchColumn();
		check(strpos($note, 'account ' . $this->account->key) !== false && strpos($note, 'took ') !== false
			&& strpos($note, 'INBOX') !== false,
			'the run record names where the clock went', $note);
	}

	// ── the deadline ───────────────────────────────────────────────────────

	private function testDeadlineStopsBetweenMessages() {
		section('a spent deadline stops the single-folder walk before its first message');
		$client = $this->client();
		$ingestor = new ImapIngestor($this->account, $client);
		$ingestor->setDeadline(microtime(true) - 1);
		$res = $ingestor->poll(50);

		check(intval($res['seen']) === 0, 'nothing was walked', 'seen=' . $res['seen']);
		check(intval($res['deferred']) === 3, 'the three left are counted as deferred', 'deferred=' . $res['deferred']);
		check($ingestor->budgetExhausted() === true, 'and the cycle says the budget stopped it');
		check($this->cursor('INBOX') === 0, 'the cursor stays below the first message not walked',
			'cursor=' . var_export($this->cursor('INBOX'), true));
		check(strpos((string)$res['status'], '3 deferred (time budget)') !== false,
			'the status says so in words', $res['status']);
		check(count($client->fetches) === 1, 'one window fetch, no body fetches', count($client->fetches));
	}

	private function testDeadlineStopsBetweenFolders() {
		section('a spent deadline defers whole folders on a sync-enabled feed');
		$this->setSyncMode('pull');
		$this->makeFolder('INBOX', InboundImapFolder::ROLE_INBOX, 5);
		$this->makeFolder('Sent', InboundImapFolder::ROLE_SENT, 5);
		$client = $this->client();
		$ingestor = new ImapIngestor($this->account, $client);
		$ingestor->setDeadline(microtime(true) - 1);
		$res = $ingestor->poll(50);

		check(intval($res['deferred_folders']) === 2 && intval($res['seen']) === 0,
			'both folders deferred whole, nothing walked', json_encode(array($res['deferred_folders'], $res['seen'])));
		check(strpos((string)$res['status'], 'Ingested 0 of 2 folder(s)') !== false
			&& strpos((string)$res['status'], 'Sent: deferred (time budget)') !== false,
			'the status counts them and names them', $res['status']);
		check(count($client->fetches) === 0, 'no folder was touched on the server');
	}

	private function testDeadlineStopsPull() {
		section('a spent deadline defers the flag pull too');
		$client = $this->client();
		$ingestor = new ImapIngestor($this->account, $client);
		$ingestor->setDeadline(microtime(true) - 1);
		$syncer = new ImapSyncer($this->account, $ingestor);
		$res = $syncer->pull();

		check(intval($res['deferred']) === 2 && intval($res['folders']) === 0,
			'both tracked folders deferred, none reconciled', json_encode($res));
		check(count($client->fetches) === 0, 'no FLAGS fetch was issued');
		check(isset($ingestor->timing()['pull']), 'the pull still laps its (tiny) time');

		$ingestor2 = new ImapIngestor($this->account, $this->client());
		$res2 = (new ImapSyncer($this->account, $ingestor2))->pull();
		check(intval($res2['folders']) === 2 && intval($res2['deferred']) === 0,
			'without a deadline the same pull reconciles both', json_encode($res2));
	}

	// ── the cycle ──────────────────────────────────────────────────────────

	private function testFetchReportsBudgetAndLeavesDue() {
		section('ImapFetch::run reports the budget and can leave the account due');
		$this->setSyncMode('off');
		$res = ImapFetch::run($this->account, 50, microtime(true) - 1, $this->client());

		check(!empty($res['budget_exhausted']), 'the result says the budget stopped the cycle');
		check(isset($res['took']) && is_float($res['took']) && is_array($res['timing']),
			'and carries its elapsed time and ledger');
		$status = $this->status();
		check(strpos($status, 'deferred (time budget)') !== false && strpos($status, ' · took ') !== false,
			'iia_last_status carries the counts and the ledger', $status);

		$this->reloadAccount();
		check($this->account->get('iia_last_poll_time') !== null, 'the cycle stamped the poll time');
		ImapFetch::leaveDue($this->account);
		$this->reloadAccount();
		check($this->account->get('iia_last_poll_time') === null,
			'leaveDue clears it, so the poller takes the account at its next tick');
		$due = new MultiInboundImapAccount(array('due' => true, 'enabled' => true, 'deleted' => false,
			'alias_id' => intval($this->alias->key)));
		$due->load();
		check(count($due) === 1, 'and the poller\'s due filter now selects it', count($due));

		$res2 = ImapFetch::run($this->account, 50, null, $this->client());
		check(empty($res2['budget_exhausted']) && intval($res2['seen']) === 3,
			'an unbounded run of the same account walks everything', json_encode(array($res2['seen'] ?? null)));
	}

	// ── cleanup ────────────────────────────────────────────────────────────

	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'budget-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			foreach ($dids as $did) { $this->purgeDomain(intval($did)); }
		} catch (\Throwable $e) {}
	}

	private function purge() {
		if ($this->domain_id) { $this->purgeDomain($this->domain_id); }
	}

	private function purgeDomain(int $did) {
		$aids = $this->db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
			WHERE iea_ied_inbound_email_domain_id = " . $did)->fetchAll(PDO::FETCH_COLUMN);
		$ain = $aids ? implode(',', array_map('intval', $aids)) : 'NULL';
		$this->db->exec("DELETE FROM isp_inbound_imap_seed_proofs WHERE isp_iia_inbound_imap_account_id IN
			(SELECT iia_inbound_imap_account_id FROM iia_inbound_imap_accounts WHERE iia_iea_inbound_email_alias_id IN ($ain))");
		$this->db->exec("DELETE FROM iif_inbound_imap_folders WHERE iif_iia_inbound_imap_account_id IN
			(SELECT iia_inbound_imap_account_id FROM iia_inbound_imap_accounts WHERE iia_iea_inbound_email_alias_id IN ($ain))");
		$this->db->exec("DELETE FROM iia_inbound_imap_accounts WHERE iia_iea_inbound_email_alias_id IN ($ain)");
		$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . $did);
		$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . $did);
		$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . $did);
	}
}

(new ImapFetchBudgetTest())->run();
harness_finish();
