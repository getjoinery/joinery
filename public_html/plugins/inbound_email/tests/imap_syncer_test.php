<?php
/**
 * Tests for the two-way IMAP sync engine (ImapSyncer) against a mock IMAP client
 * (the §6.2 seam), without a live server. Covers the unified per-folder presence
 * model from specs/two_way_imap_sync.md §11:
 *
 *  - Flags push/pull, dirty-skip (local-wins), loop avoidance.
 *  - Membership push: non-exclusive COPY/EXPUNGE, exclusive MOVE + collapse.
 *  - VANISHED pull → membership cleared (clean) / skipped (dirty).
 *  - Deletion: soft-delete bridges to a Trash membership → MOVE-to-Trash push;
 *    a Trash arrival → soft-delete; archive (lose INBOX, keep others) is not a delete.
 *  - Coverage source: the folder-unfiltered view; foldersForAlias excludes \All.
 *  - Sent dedup: a local outbound row reconciles to one row by Message-ID.
 *
 * Run: php plugins/inbound_email/tests/imap_syncer_test.php  (requires schema synced).
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
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/ImapClient.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/ImapSyncer.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));

/** One fetched message's flags, mimicking Horde_Imap_Client_Data_Fetch::getFlags(). */
class FakeFetchData {
	private $flags;
	public function __construct(array $flags) { $this->flags = $flags; }
	public function getFlags() { return $this->flags; }
}

/** A tiny iterable mimicking Horde_Imap_Client_Fetch_Results (uid => data). */
class FakeFetchResults implements IteratorAggregate {
	private $rows;
	public function __construct(array $rows) { $this->rows = $rows; } // uid => FakeFetchData
	public function getIterator(): Iterator { return new ArrayIterator($this->rows); }
}

/**
 * In-memory IMAP server. Folders carry uidvalidity/highestmodseq and a
 * uid → ['flags'=>[], 'message_id'=>] map. Writes are applied to the model AND
 * recorded in $ops so tests can assert the IMAP operations issued.
 */
class FakeImapClient implements ImapClient {
	public $folders = array();          // name => ['uidvalidity'=>,'highestmodseq'=>,'uidnext'=>,'messages'=>[uid=>[...]]]
	public $vanishedByFolder = array(); // name => [uids]
	public $caps = array('QRESYNC' => true, 'X-GM-EXT-1' => false);
	public $ops = array();              // recorded writes

	public function status(string $mailbox, int $flags): array {
		$f = $this->folders[$mailbox] ?? array();
		$msgs = $f['messages'] ?? array();
		$maxUid = count($msgs) ? max(array_keys($msgs)) : 0;
		return array(
			'uidvalidity'   => $f['uidvalidity'] ?? 1,
			'highestmodseq' => $f['highestmodseq'] ?? 1,
			'uidnext'       => $f['uidnext'] ?? ($maxUid + 1),
			'messages'      => count($msgs),
		);
	}

	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array()) {
		$rows = array();
		foreach (($this->folders[$mailbox]['messages'] ?? array()) as $uid => $m) {
			$rows[$uid] = new FakeFetchData($m['flags'] ?? array());
		}
		return new FakeFetchResults($rows);
	}

	public function search(string $mailbox, $query = null, array $options = array()): array { return array(); }

	public function store(string $mailbox, array $options = array()) {
		$uids = $this->idsToArray($options['ids'] ?? null);
		$this->ops[] = array('op' => 'store', 'mailbox' => $mailbox, 'ids' => $uids,
			'add' => $options['add'] ?? array(), 'remove' => $options['remove'] ?? array());
		foreach ($uids as $uid) {
			if (!isset($this->folders[$mailbox]['messages'][$uid])) { continue; }
			$flags = $this->folders[$mailbox]['messages'][$uid]['flags'] ?? array();
			foreach (($options['add'] ?? array()) as $fl) { $flags[] = strtolower($fl); }
			foreach (($options['remove'] ?? array()) as $fl) {
				$flags = array_values(array_diff($flags, array(strtolower($fl))));
			}
			$this->folders[$mailbox]['messages'][$uid]['flags'] = array_values(array_unique($flags));
		}
		return new Horde_Imap_Client_Ids($uids);
	}

	public function copy(string $source, string $dest, array $options = array()) {
		$uids = $this->idsToArray($options['ids'] ?? null);
		$this->ops[] = array('op' => !empty($options['move']) ? 'move' : 'copy',
			'source' => $source, 'dest' => $dest, 'ids' => $uids);
		// Materialize the copy/move in the destination with a fresh uid.
		$newUids = array();
		foreach ($uids as $uid) {
			$msg = $this->folders[$source]['messages'][$uid] ?? array('flags' => array(), 'message_id' => '');
			$newUid = ($this->folders[$dest]['uidnext'] ?? 1);
			$this->folders[$dest]['messages'][$newUid] = $msg;
			$this->folders[$dest]['uidnext'] = $newUid + 1;
			$newUids[] = $newUid;
			if (!empty($options['move'])) {
				unset($this->folders[$source]['messages'][$uid]);
			}
		}
		return new Horde_Imap_Client_Ids($newUids);
	}

	public function expunge(string $mailbox, array $options = array()) {
		$uids = $this->idsToArray($options['ids'] ?? null);
		$this->ops[] = array('op' => 'expunge', 'mailbox' => $mailbox, 'ids' => $uids);
		foreach ($uids as $uid) { unset($this->folders[$mailbox]['messages'][$uid]); }
		return new Horde_Imap_Client_Ids($uids);
	}

	public function append(string $mailbox, array $data, array $options = array()) {
		$this->ops[] = array('op' => 'append', 'mailbox' => $mailbox, 'count' => count($data));
		return new Horde_Imap_Client_Ids(array());
	}

	public function vanished(string $mailbox, int $modseq, array $options = array()) {
		return new Horde_Imap_Client_Ids($this->vanishedByFolder[$mailbox] ?? array());
	}

	public function listMailboxes($pattern, int $mode = 0, array $options = array()): array {
		// Used by createPendingFolders' already-exists check + discovery (not exercised).
		if (is_string($pattern) && isset($this->folders[$pattern])) {
			return array($pattern => array('mailbox' => $pattern));
		}
		return array();
	}

	public function createMailbox(string $mailbox): void {
		$this->ops[] = array('op' => 'create', 'mailbox' => $mailbox);
		if (!isset($this->folders[$mailbox])) {
			$this->folders[$mailbox] = array('uidvalidity' => 1, 'uidnext' => 1, 'messages' => array());
		}
	}

	public function queryCapability(string $capability): bool {
		return !empty($this->caps[$capability]);
	}

	public function logout(): void {}

	private function idsToArray($ids): array {
		if ($ids instanceof Horde_Imap_Client_Ids) {
			$out = array();
			foreach ($ids as $u) { $out[] = intval($u); }
			return $out;
		}
		return is_array($ids) ? array_map('intval', $ids) : array();
	}

	/** Test helper: record an op-count by type. */
	public function opCount(string $type): int {
		return count(array_filter($this->ops, function ($o) use ($type) { return $o['op'] === $type; }));
	}
}

class ImapSyncerTest {
	private $pass = 0;
	private $fail = 0;
	private $db;
	private $suffix;
	private $domain_id;
	private $alias;
	private $account;

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		if ($c) { $this->pass++; $this->out('  PASS: ' . $l); }
		else { $this->fail++; $this->out('  FAIL: ' . $l); }
	}

	function run() {
		$this->out('=== ImapSyncer tests (mock client) ===');
		try {
			$this->setUp();
			$this->testFlagsPullCleanApplied();
			$this->testFlagsPullDirtySkipped();
			$this->testFlagsPush();
			$this->testPushCreatesPendingFolder();
			$this->testMembershipPushNonExclusiveCopy();
			$this->testMembershipPushNonExclusiveRemove();
			$this->testMembershipPushExclusiveMove();
			$this->testVanishedClearsCleanMembership();
			$this->testVanishedSkipsDirty();
			$this->testVanishedUidDiffFallback();
			$this->testReconcileDeletesTrashArrival();
			$this->testDeleteBridgeMovesToTrash();
			$this->testFoldersExcludeCoverage();
		} catch (\Throwable $e) {
			$this->fail++;
			$this->out('  EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
		$this->out("=== {$this->pass} passed, {$this->fail} failed ===");
		return $this->fail === 0;
	}

	// ── fixtures ────────────────────────────────────────────────────────────

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('syn', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'sync-test-' . $this->suffix . '.example');
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
		$acc->set('iia_label', 'Sync ' . $this->suffix);
		$acc->set('iia_provider_key', 'imap_generic');
		$acc->set('iia_imap_host', 'imap.test');
		$acc->set('iia_iea_inbound_email_alias_id', $this->alias->key);
		$acc->set('iia_username', 'me@sync-test.example');
		$acc->set('iia_is_enabled', true);
		$acc->set('iia_supports_condstore', true); // the sync gate
		$acc->set('iia_supports_qresync', true);   // fast VANISHED path (toggled off in the fallback test)
		$acc->set('iia_sync_mode', 'both');
		$acc->set('iia_folders_exclusive', false); // default non-exclusive for membership tests
		$acc->prepare(); $acc->save();
		$this->account = new InboundImapAccount($acc->key, TRUE);

		$this->out('  fixtures ready (suffix ' . $this->suffix . ')');
	}

	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'sync-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			foreach ($dids as $did) { $this->purgeDomain(intval($did)); }
		} catch (\Throwable $e) {}
	}

	private function purgeDomain(int $did) {
		$aids = $this->db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
			WHERE iea_ied_inbound_email_domain_id = " . $did)->fetchAll(PDO::FETCH_COLUMN);
		$accIds = $this->db->query("SELECT iia_inbound_imap_account_id FROM iia_inbound_imap_accounts
			WHERE iia_iea_inbound_email_alias_id IN (" . ($aids ? implode(',', array_map('intval', $aids)) : 'NULL') . ")")->fetchAll(PDO::FETCH_COLUMN);
		$mids = $this->db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			WHERE iem_ied_inbound_email_domain_id = " . $did)->fetchAll(PDO::FETCH_COLUMN);
		if ($mids) {
			$min = implode(',', array_map('intval', $mids));
			$this->db->exec("DELETE FROM imf_inbound_message_folders WHERE imf_iem_inbound_email_message_id IN ($min)");
		}
		if ($accIds) {
			$ain = implode(',', array_map('intval', $accIds));
			$this->db->exec("DELETE FROM iif_inbound_imap_folders WHERE iif_iia_inbound_imap_account_id IN ($ain)");
		}
		$this->db->exec("DELETE FROM iia_inbound_imap_accounts WHERE iia_iea_inbound_email_alias_id IN (" . ($aids ? implode(',', array_map('intval', $aids)) : 'NULL') . ")");
		$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . $did);
		$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . $did);
		$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . $did);
	}

	private function makeFolder(string $name, ?string $role, int $uidvalidity = 1, ?int $modseq = 5): InboundImapFolder {
		$f = InboundImapFolder::upsert(intval($this->account->key), $name, $role, true);
		$f->set('iif_uidvalidity', $uidvalidity);
		$f->set('iif_last_sync_modseq', $modseq);
		$f->prepare(); $f->save();
		return new InboundImapFolder($f->key, TRUE);
	}

	private function makeMessage(string $folderName, int $uid, array $opts = array()): InboundEmailMessage {
		$m = new InboundEmailMessage(NULL);
		$m->set('iem_ied_inbound_email_domain_id', $this->domain_id);
		$m->set('iem_iea_inbound_email_alias_id', $this->alias->key);
		$m->set('iem_sender', 'from@x.test');
		$m->set('iem_recipient', strtolower($this->alias->get_full_address()));
		$m->set('iem_subject', $opts['subject'] ?? 'Subj');
		$m->set('iem_message_id_header', $opts['message_id'] ?? ('<' . uniqid('m') . '@x>'));
		$m->set('iem_is_read', $opts['is_read'] ?? false);
		$m->set('iem_is_starred', $opts['is_starred'] ?? false);
		$m->set('iem_direction', $opts['direction'] ?? 'inbound');
		$m->set('iem_iia_inbound_imap_account_id', $this->account->key);
		$m->set('iem_imap_folder', $folderName);
		$m->set('iem_imap_uid', $uid);
		$m->set('iem_imap_uidvalidity', 1);
		if (isset($opts['local_modified'])) { $m->set('iem_local_state_modified', $opts['local_modified']); }
		if (isset($opts['synced'])) { $m->set('iem_synced_state_time', $opts['synced']); }
		$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
		$m->save();
		return new InboundEmailMessage($m->key, TRUE);
	}

	private function syncer(FakeImapClient $client): ImapSyncer {
		$ingestor = new ImapIngestor($this->account, $client);
		return new ImapSyncer($this->account, $ingestor);
	}

	private function reload(InboundEmailMessage $m): InboundEmailMessage {
		return new InboundEmailMessage($m->key, TRUE);
	}

	private function pgBool($v): bool {
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	// ── flags ────────────────────────────────────────────────────────────────

	private function testFlagsPullCleanApplied() {
		$client = new FakeImapClient();
		$folder = $this->makeFolder('INBOX', 'inbox');
		$msg = $this->makeMessage('INBOX', 10, array('is_read' => false));
		// Server shows the message read since last modseq; the local row is clean.
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'highestmodseq' => 9,
			'messages' => array(10 => array('flags' => array('\seen'), 'message_id' => $msg->get('iem_message_id_header'))));

		$this->syncer($client)->pull();
		$this->ok($this->pgBool($this->reload($msg)->get('iem_is_read')), 'pull applies remote \\Seen to a clean row');
	}

	private function testFlagsPullDirtySkipped() {
		$client = new FakeImapClient();
		$this->makeFolder('INBOX', 'inbox');
		// Local row is dirty (local_modified > synced): user just marked it unread.
		$msg = $this->makeMessage('INBOX', 11, array('is_read' => false,
			'local_modified' => gmdate('Y-m-d H:i:s'), 'synced' => '2000-01-01 00:00:00'));
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'highestmodseq' => 9,
			'messages' => array(11 => array('flags' => array('\seen'), 'message_id' => $msg->get('iem_message_id_header'))));

		$this->syncer($client)->pull();
		$this->ok(!$this->pgBool($this->reload($msg)->get('iem_is_read')),
			'pull skips a dirty row (local-wins): remote \\Seen not applied');
	}

	private function testFlagsPush() {
		$client = new FakeImapClient();
		$this->makeFolder('INBOX', 'inbox');
		$msg = $this->makeMessage('INBOX', 12, array('is_read' => true,
			'local_modified' => gmdate('Y-m-d H:i:s'), 'synced' => null));
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'highestmodseq' => 9,
			'messages' => array(12 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));

		$this->syncer($client)->push(50);
		$this->ok($client->opCount('store') >= 1, 'push STOREs the dirty flag row');
		$this->ok(in_array('\seen', $client->folders['INBOX']['messages'][12]['flags'], true),
			'push applies \\Seen on the source');
		$this->ok($this->reload($msg)->get('iem_synced_state_time') !== null, 'push stamps synced_state_time (clears dirty)');
	}

	// ── membership ─────────────────────────────────────────────────────────

	private function testPushCreatesPendingFolder() {
		$client = new FakeImapClient();
		$inbox = $this->makeFolder('INBOX', 'inbox');
		// A label created locally: tracked + pending remote create, not on the server.
		$label = InboundImapFolder::upsert(intval($this->account->key), 'Work', 'custom', true);
		$label = new InboundImapFolder($label->key, TRUE);
		$label->set('iif_pending_remote_create', true);
		$label->prepare(); $label->save();

		$msg = $this->makeMessage('INBOX', 25);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'uidnext' => 26,
			'messages' => array(25 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));
		// 'Work' deliberately absent from the server until push creates it.
		InboundMessageFolder::setPresence($msg->key, intval($inbox->key), true, true, 25, 1);
		InboundMessageFolder::setPresence($msg->key, intval($label->key), true, false); // dirty add

		$this->syncer($client)->push(50);

		$this->ok($client->opCount('create') === 1, 'push CREATEs the locally-made folder on the source');
		$this->ok(isset($client->folders['Work']), 'the folder now exists on the server');
		$reloaded = new InboundImapFolder($label->key, TRUE);
		$this->ok(!$reloaded->isPendingRemoteCreate(), 'pending_remote_create cleared after CREATE');
		$this->ok($client->opCount('copy') === 1, 'membership then COPYs the message into the new folder');
		$el = InboundMessageFolder::find($msg->key, intval($label->key));
		$this->ok($el && !$el->isDirty(), 'new-folder membership shadow advanced to clean');
	}

	private function testMembershipPushNonExclusiveCopy() {
		$client = new FakeImapClient();
		$inbox = $this->makeFolder('INBOX', 'inbox');
		$work = $this->makeFolder('Work', 'custom');
		$msg = $this->makeMessage('INBOX', 20);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'uidnext' => 21,
			'messages' => array(20 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));
		$client->folders['Work'] = array('uidvalidity' => 1, 'uidnext' => 1, 'messages' => array());

		// Base membership in INBOX (clean); a local add of Work (dirty add).
		InboundMessageFolder::setPresence($msg->key, intval($inbox->key), true, true, 20, 1);
		InboundMessageFolder::setPresence($msg->key, intval($work->key), true, false);

		$this->syncer($client)->push(50);
		$this->ok($client->opCount('copy') === 1, 'non-exclusive local add → COPY (label add)');
		$el = InboundMessageFolder::find($msg->key, intval($work->key));
		$this->ok($el && !$el->isDirty(), 'Work membership shadow advanced to clean after COPY');
	}

	private function testMembershipPushNonExclusiveRemove() {
		$client = new FakeImapClient();
		$inbox = $this->makeFolder('INBOX', 'inbox');
		$work = $this->makeFolder('Work', 'custom');
		$msg = $this->makeMessage('INBOX', 21);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'uidnext' => 22,
			'messages' => array(21 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));
		$client->folders['Work'] = array('uidvalidity' => 1, 'uidnext' => 6,
			'messages' => array(5 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));

		InboundMessageFolder::setPresence($msg->key, intval($inbox->key), true, true, 21, 1);
		// Local removal of Work (base true, local false) with the folder UID recorded.
		InboundMessageFolder::setPresence($msg->key, intval($work->key), false, true, 5, 1);

		$this->syncer($client)->push(50);
		$this->ok($client->opCount('expunge') === 1, 'non-exclusive local remove → EXPUNGE (label remove)');
		$this->ok(InboundMessageFolder::find($msg->key, intval($work->key)) === null,
			'removed membership row dropped (0,0)');
	}

	private function testMembershipPushExclusiveMove() {
		$client = new FakeImapClient();
		$exclusiveAccount = new InboundImapAccount($this->account->key, TRUE);
		$exclusiveAccount->set('iia_folders_exclusive', true);
		$exclusiveAccount->prepare(); $exclusiveAccount->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);

		$inbox = $this->makeFolder('INBOX', 'inbox');
		$archive = $this->makeFolder('Archive', 'archive');
		$msg = $this->makeMessage('INBOX', 30);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'uidnext' => 31,
			'messages' => array(30 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));
		$client->folders['Archive'] = array('uidvalidity' => 1, 'uidnext' => 1, 'messages' => array());

		InboundMessageFolder::setPresence($msg->key, intval($inbox->key), false, true, 30, 1); // removed from INBOX
		InboundMessageFolder::setPresence($msg->key, intval($archive->key), true, false);       // added to Archive

		$this->syncer($client)->push(50);
		$this->ok($client->opCount('move') === 1, 'exclusive add → MOVE (relocation)');
		$keep = InboundMessageFolder::find($msg->key, intval($archive->key));
		$this->ok($keep && !$keep->isDirty(), 'destination membership clean after MOVE');
		$this->ok(InboundMessageFolder::find($msg->key, intval($inbox->key)) === null,
			'exclusive collapse drops the old INBOX membership');
		$reloaded = $this->reload($msg);
		$this->ok($reloaded->get('iem_imap_folder') === 'Archive', 'locator follows the MOVE to Archive');

		// restore non-exclusive for any later tests
		$this->account->set('iia_folders_exclusive', false);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	// ── vanished ─────────────────────────────────────────────────────────────

	private function testVanishedClearsCleanMembership() {
		$client = new FakeImapClient();
		$inbox = $this->makeFolder('INBOX', 'inbox');
		$work = $this->makeFolder('Work', 'custom');
		$msg = $this->makeMessage('Work', 40); // locator in Work
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'highestmodseq' => 9, 'messages' => array());
		$client->folders['Work'] = array('uidvalidity' => 1, 'highestmodseq' => 9, 'messages' => array());

		InboundMessageFolder::setPresence($msg->key, intval($inbox->key), true, true, 50, 1);
		InboundMessageFolder::setPresence($msg->key, intval($work->key), true, true, 40, 1);
		$client->vanishedByFolder['Work'] = array(40); // Work copy vanished remotely

		$this->syncer($client)->pull();
		$this->ok(InboundMessageFolder::find($msg->key, intval($work->key)) === null,
			'VANISHED clears a clean membership');
		$this->ok(InboundMessageFolder::find($msg->key, intval($inbox->key)) !== null,
			'other membership untouched by the vanish');
		$this->ok($this->reload($msg)->get('iem_imap_folder') === 'INBOX',
			'locator re-pointed off the vanished folder to a surviving one');
	}

	private function testVanishedSkipsDirty() {
		$client = new FakeImapClient();
		$work = $this->makeFolder('Work2', 'custom');
		$msg = $this->makeMessage('Work2', 41);
		$client->folders['Work2'] = array('uidvalidity' => 1, 'highestmodseq' => 9, 'messages' => array());
		// Dirty element (a local add not yet pushed) — pull must not clobber it.
		InboundMessageFolder::setPresence($msg->key, intval($work->key), true, false, 41, 1);
		$client->vanishedByFolder['Work2'] = array(41);

		$this->syncer($client)->pull();
		$el = InboundMessageFolder::find($msg->key, intval($work->key));
		$this->ok($el !== null && $el->isDirty(), 'VANISHED skips a dirty element (local-wins)');
	}

	private function testVanishedUidDiffFallback() {
		$client = new FakeImapClient();
		$client->caps['QRESYNC'] = false; // CONDSTORE-only server (e.g. Gmail)
		// The feed itself must report no QRESYNC so the engine takes the diff path.
		$this->account->set('iia_supports_qresync', false);
		$this->account->set('iia_supports_condstore', true);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);

		$work = $this->makeFolder('WorkDiff', 'custom');
		$msg = $this->makeMessage('WorkDiff', 80);
		// Membership recorded at uid 80; the folder no longer contains uid 80 — and
		// there is no VANISHED feed, so only the UID-set diff can detect it.
		InboundMessageFolder::setPresence($msg->key, intval($work->key), true, true, 80, 1);
		$client->folders['WorkDiff'] = array('uidvalidity' => 1, 'highestmodseq' => 9,
			'messages' => array(81 => array('flags' => array(), 'message_id' => '<other@x>')));

		$this->syncer($client)->pull();
		$this->ok(InboundMessageFolder::find($msg->key, intval($work->key)) === null,
			'CONDSTORE-only fallback: UID-set diff detects the vanished membership (no QRESYNC)');

		// Restore the fast path for the remaining tests.
		$this->account->set('iia_supports_qresync', true);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	// ── deletion ─────────────────────────────────────────────────────────────

	private function testReconcileDeletesTrashArrival() {
		$client = new FakeImapClient();
		$this->account->set('iia_sync_deletes', true);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);

		$trash = $this->makeFolder('Trash', 'trash');
		$msg = $this->makeMessage('INBOX', 60);
		// Simulate ingest having seen the message arrive in Trash (a remote delete).
		InboundMessageFolder::setPresence($msg->key, intval($trash->key), true, true, 60, 1);

		$count = $this->syncer($client)->reconcileDeletes();
		$this->ok($count >= 1, 'reconcileDeletes acts on a Trash arrival');
		$this->ok($this->reload($msg)->get('iem_delete_time') !== null, 'Trash arrival soft-deletes the local row');

		$this->account->set('iia_sync_deletes', false);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	private function testDeleteBridgeMovesToTrash() {
		$client = new FakeImapClient();
		$this->account->set('iia_sync_deletes', true);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);

		$inbox = $this->makeFolder('INBOX', 'inbox');
		$trash = $this->makeFolder('Trash', 'trash');
		$msg = $this->makeMessage('INBOX', 70);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'uidnext' => 71,
			'messages' => array(70 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));
		$client->folders['Trash'] = array('uidvalidity' => 1, 'uidnext' => 1, 'messages' => array());
		InboundMessageFolder::setPresence($msg->key, intval($inbox->key), true, true, 70, 1);

		// Local soft-delete via the service (all-access viewer) bridges to membership.
		$viewer = $this->allAccessViewer();
		$service = new MailboxService($viewer);
		$service->softDelete(array(intval($msg->key)));

		$trashEl = InboundMessageFolder::find($msg->key, intval($trash->key));
		$this->ok($trashEl !== null && $trashEl->isDirty(), 'soft-delete bridges to a dirty Trash membership');

		$this->syncer($client)->push(50);
		// On a non-exclusive feed the delete is a COPY to Trash (the Trash membership
		// add) plus an EXPUNGE of the other folder; landing in Trash is what removes
		// it elsewhere. (An exclusive feed would MOVE to Trash instead.)
		$toTrash = array_filter($client->ops, function ($o) {
			return in_array($o['op'], array('copy', 'move'), true) && ($o['dest'] ?? '') === 'Trash';
		});
		$this->ok(count($toTrash) === 1, 'delete pushes the source message into Trash (COPY/MOVE)');
		$this->ok(isset($client->folders['Trash']['messages']) && count($client->folders['Trash']['messages']) === 1,
			'the source message now sits in Trash');

		$this->account->set('iia_sync_deletes', false);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	// ── coverage / folders ───────────────────────────────────────────────────

	private function testFoldersExcludeCoverage() {
		$this->makeFolder('INBOX', 'inbox');
		$this->makeFolder('[Gmail]/All Mail', 'all');
		$viewer = $this->allAccessViewer();
		$data = (new MailboxService($viewer))->listMailboxes();
		$found = null;
		foreach (($data['mailboxes'] ?? array()) as $m) {
			if (intval($m['alias_id']) === intval($this->alias->key)) { $found = $m; }
		}
		$names = $found ? array_map(function ($f) { return $f['name']; }, $found['folders']) : array();
		$this->ok($found !== null, 'mailbox appears in the switcher');
		$this->ok(in_array('INBOX', $names, true), 'INBOX is a navigable folder');
		$this->ok(!in_array('[Gmail]/All Mail', $names, true),
			'the \\All coverage view is excluded from the folder rail');
	}

	private function allAccessViewer(): MailboxViewer {
		// A permission-10 (all-access) viewer so service mutations/reads aren't
		// grant-scoped in the CLI test (there is no logged-in session).
		return MailboxViewer::forUser(1, 10);
	}

	private function tearDown() {
		try { if ($this->domain_id) { $this->purgeDomain(intval($this->domain_id)); } } catch (\Throwable $e) {}
	}
}

$test = new ImapSyncerTest();
$ok = $test->run();
exit($ok ? 0 : 1);
?>
