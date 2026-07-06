<?php
/**
 * Tests for the two-way IMAP sync engine (ImapSyncer) against a mock IMAP client
 * (the §6.2 seam), without a live server. Covers the dedicated-label model from
 * specs/inbound_email_labels.md (custom labels are ilm_ rows; standard state is columns):
 *
 *  - Flags push/pull, dirty-skip (local-wins), loop avoidance.
 *  - Custom-label push: non-exclusive COPY/EXPUNGE, exclusive MOVE + collapse.
 *  - VANISHED pull → membership cleared (clean) / skipped (dirty).
 *  - Deletion is the column: a local soft-delete pushes a COPY/MOVE to Trash; the
 *    locator follows so it is not re-pushed.
 *  - Label rail: custom labels are navigable; special-use (INBOX) and the \All
 *    coverage view are not (they are columns / coverage).
 *
 * Run: php plugins/mailbox/tests/imap_syncer_test.php  (requires schema synced).
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapSyncer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));

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
			$this->testSoftDeletePushesToTrash();
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
			$this->db->exec("DELETE FROM ilm_inbound_label_members WHERE ilm_iem_inbound_email_message_id IN ($min)");
		}
		if ($accIds) {
			$ain = implode(',', array_map('intval', $accIds));
			// Drop the labels these test folders were bound to (and their memberships) so
			// test labels never leak into a real mailbox's label rail.
			$lids = $this->db->query("SELECT iif_ilb_inbound_email_label_id FROM iif_inbound_imap_folders
				WHERE iif_iia_inbound_imap_account_id IN ($ain) AND iif_ilb_inbound_email_label_id IS NOT NULL")
				->fetchAll(PDO::FETCH_COLUMN);
			if ($lids) {
				$lin = implode(',', array_map('intval', $lids));
				$this->db->exec("DELETE FROM ilm_inbound_label_members WHERE ilm_ilb_inbound_email_label_id IN ($lin)");
				$this->db->exec("DELETE FROM ilb_inbound_email_labels WHERE ilb_inbound_email_label_id IN ($lin)");
			}
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

	// ── membership seeding / assertions (dedicated ilm_ model) ────────────────
	// Truth (present_local) and the IMAP shadow (present_base + UID + binding) are the
	// one ilm_ row. These helpers express the (local, base) element on that single row
	// and read it back. Only custom-label folders carry rows; ensureLabel binds them.

	/** The custom-label id this folder binds to, or null for special-use/coverage. */
	private function labelId(InboundImapFolder $folder): ?int {
		return $folder->labelId() ?? $folder->ensureLabel();
	}

	/** Seed a (message, label) element: present_local for $local, present_base for $base. */
	private function seed(int $msgId, InboundImapFolder $folder, bool $local, bool $base,
			?int $uid = null, ?int $uidv = null): void {
		$labelId = $this->labelId($folder);
		if ($labelId === null) { return; } // not a label folder
		$folderId = intval($folder->key);

		if ($base) {
			InboundLabelMember::setBaseline($msgId, $labelId, $folderId, true, $uid, $uidv);
			if (!$local) {
				// base true, local false: a dirty remove.
				$row = InboundLabelMember::find($msgId, $labelId);
				$row->set('ilm_present_local', false);
				$row->prepare(); $row->save();
			}
		} elseif ($local) {
			// base false, local true: a dirty add (no synced shadow UID yet).
			$row = InboundLabelMember::find($msgId, $labelId);
			if (!$row) {
				$row = new InboundLabelMember(NULL);
				$row->set('ilm_iem_inbound_email_message_id', $msgId);
				$row->set('ilm_ilb_inbound_email_label_id', $labelId);
			}
			$row->set('ilm_present_local', true);
			$row->set('ilm_present_base', false);
			$row->set('ilm_iif_inbound_imap_folder_id', $folderId);
			$row->prepare(); $row->save();
		}
		// base false, local false: clean-absent → no row.
	}

	/** True iff the message carries the folder's label (present_local). */
	private function isMember(int $msgId, InboundImapFolder $folder): bool {
		$lid = $this->labelId($folder);
		return $lid ? InboundLabelMember::isMember($msgId, $lid) : false;
	}

	/** The ilm_ membership row for the element, or null. */
	private function shadow(int $msgId, InboundImapFolder $folder): ?InboundLabelMember {
		$lid = $this->labelId($folder);
		return $lid ? InboundLabelMember::find($msgId, $lid) : null;
	}

	/** Dirty iff present_local diverges from present_base on the row. */
	private function isDirtyEl(int $msgId, InboundImapFolder $folder): bool {
		$s = $this->shadow($msgId, $folder);
		if (!$s) { return false; }
		return (bool)$s->get('ilm_present_local') !== (bool)$s->get('ilm_present_base');
	}

	/** Clean-present: present_local and present_base both set. */
	private function isPresentClean(int $msgId, InboundImapFolder $folder): bool {
		$s = $this->shadow($msgId, $folder);
		return $s && (bool)$s->get('ilm_present_local') && (bool)$s->get('ilm_present_base');
	}

	/** Clean-absent: no row, or a row with neither bit set. */
	private function isAbsent(int $msgId, InboundImapFolder $folder): bool {
		$lid = $this->labelId($folder);
		if (!$lid) { return true; }
		$s = InboundLabelMember::find($msgId, $lid);
		return $s === null || (!(bool)$s->get('ilm_present_local') && !(bool)$s->get('ilm_present_base'));
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
		$this->seed(intval($msg->key), $label, true, false); // dirty add

		$this->syncer($client)->push(50);

		$this->ok($client->opCount('create') === 1, 'push CREATEs the locally-made folder on the source');
		$this->ok(isset($client->folders['Work']), 'the folder now exists on the server');
		$reloaded = new InboundImapFolder($label->key, TRUE);
		$this->ok(!$reloaded->isPendingRemoteCreate(), 'pending_remote_create cleared after CREATE');
		$this->ok($client->opCount('copy') === 1, 'membership then COPYs the message into the new folder');
		$this->ok($this->isPresentClean(intval($msg->key), $label), 'new-folder membership shadow advanced to clean');
	}

	private function testMembershipPushNonExclusiveCopy() {
		$client = new FakeImapClient();
		$inbox = $this->makeFolder('INBOX', 'inbox');
		$work = $this->makeFolder('Work', 'custom');
		$msg = $this->makeMessage('INBOX', 20);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'uidnext' => 21,
			'messages' => array(20 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));
		$client->folders['Work'] = array('uidvalidity' => 1, 'uidnext' => 1, 'messages' => array());

		// The message's locator is INBOX (from the row); a local add of the Work label.
		$this->seed(intval($msg->key), $work, true, false); // dirty add

		$this->syncer($client)->push(50);
		$this->ok($client->opCount('copy') === 1, 'non-exclusive local add → COPY (label add)');
		$this->ok($this->isPresentClean(intval($msg->key), $work), 'Work membership shadow advanced to clean after COPY');
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

		// Local removal of the Work label (base true, local false) with the folder UID.
		$this->seed(intval($msg->key), $work, false, true, 5, 1);

		$this->syncer($client)->push(50);
		$this->ok($client->opCount('expunge') === 1, 'non-exclusive local remove → EXPUNGE (label remove)');
		$this->ok($this->isAbsent(intval($msg->key), $work),
			'removed membership dropped (clean-absent)');
	}

	private function testMembershipPushExclusiveMove() {
		$client = new FakeImapClient();
		$exclusiveAccount = new InboundImapAccount($this->account->key, TRUE);
		$exclusiveAccount->set('iia_folders_exclusive', true);
		$exclusiveAccount->prepare(); $exclusiveAccount->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);

		$projects = $this->makeFolder('Projects', 'custom');
		$msg = $this->makeMessage('INBOX', 30); // locator in INBOX
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'uidnext' => 31,
			'messages' => array(30 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));
		$client->folders['Projects'] = array('uidvalidity' => 1, 'uidnext' => 1, 'messages' => array());

		$this->seed(intval($msg->key), $projects, true, false); // added to Projects (a custom label)

		$this->syncer($client)->push(50);
		$this->ok($client->opCount('move') === 1, 'exclusive label add → MOVE (relocation)');
		$this->ok($this->isPresentClean(intval($msg->key), $projects), 'destination membership clean after MOVE');
		$reloaded = $this->reload($msg);
		$this->ok($reloaded->get('iem_imap_folder') === 'Projects', 'locator follows the MOVE to Projects');

		// restore non-exclusive for any later tests
		$this->account->set('iia_folders_exclusive', false);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	// ── vanished ─────────────────────────────────────────────────────────────

	private function testVanishedClearsCleanMembership() {
		$client = new FakeImapClient();
		$work = $this->makeFolder('Work', 'custom');
		$work2 = $this->makeFolder('Work2', 'custom');
		$msg = $this->makeMessage('Work', 40); // locator in Work
		$client->folders['Work'] = array('uidvalidity' => 1, 'highestmodseq' => 9, 'messages' => array());
		$client->folders['Work2'] = array('uidvalidity' => 1, 'highestmodseq' => 9, 'messages' => array());

		$this->seed(intval($msg->key), $work, true, true, 40, 1);
		$this->seed(intval($msg->key), $work2, true, true, 50, 1);
		$client->vanishedByFolder['Work'] = array(40); // Work copy vanished remotely

		$this->syncer($client)->pull();
		$this->ok($this->isAbsent(intval($msg->key), $work),
			'VANISHED clears a clean membership');
		$this->ok($this->isMember(intval($msg->key), $work2),
			'other membership untouched by the vanish');
		$this->ok($this->reload($msg)->get('iem_imap_folder') === 'Work2',
			'locator re-pointed off the vanished folder to a surviving one');
	}

	private function testVanishedSkipsDirty() {
		$client = new FakeImapClient();
		$work = $this->makeFolder('Work2', 'custom');
		$msg = $this->makeMessage('Work2', 41);
		$client->folders['Work2'] = array('uidvalidity' => 1, 'highestmodseq' => 9, 'messages' => array());
		// Dirty element (a local add not yet pushed) — pull must not clobber it. A
		// not-yet-synced add has no shadow UID, so VANISHED can't even target it.
		$this->seed(intval($msg->key), $work, true, false, 41, 1);
		$client->vanishedByFolder['Work2'] = array(41);

		$this->syncer($client)->pull();
		$this->ok($this->isMember(intval($msg->key), $work) && $this->isDirtyEl(intval($msg->key), $work),
			'VANISHED skips a dirty element (local-wins)');
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
		$this->seed(intval($msg->key), $work, true, true, 80, 1);
		$client->folders['WorkDiff'] = array('uidvalidity' => 1, 'highestmodseq' => 9,
			'messages' => array(81 => array('flags' => array(), 'message_id' => '<other@x>')));

		$this->syncer($client)->pull();
		$this->ok($this->isAbsent(intval($msg->key), $work),
			'CONDSTORE-only fallback: UID-set diff detects the vanished membership (no QRESYNC)');

		// Restore the fast path for the remaining tests.
		$this->account->set('iia_supports_qresync', true);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	// ── deletion ─────────────────────────────────────────────────────────────

	private function testSoftDeletePushesToTrash() {
		$client = new FakeImapClient();
		$this->account->set('iia_sync_deletes', true);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);

		$this->makeFolder('INBOX', 'inbox');
		$this->makeFolder('Trash', 'trash');
		$msg = $this->makeMessage('INBOX', 70);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'uidnext' => 71,
			'messages' => array(70 => array('flags' => array(), 'message_id' => $msg->get('iem_message_id_header'))));
		$client->folders['Trash'] = array('uidvalidity' => 1, 'uidnext' => 1, 'messages' => array());

		// Local soft-delete via the service (all-access viewer): column is the truth.
		$viewer = $this->allAccessViewer();
		$service = new MailboxService($viewer);
		$service->softDelete(array(intval($msg->key)));
		$this->ok($this->reload($msg)->get('iem_delete_time') !== null, 'soft-delete sets the iem_delete_time column');

		$this->syncer($client)->push(50);
		// Column-driven pushTrash relocates the source into Trash: a COPY on a
		// non-exclusive feed (Gmail treats a Trash copy as removal from every label), a
		// MOVE on an exclusive one.
		$toTrash = array_filter($client->ops, function ($o) {
			return in_array($o['op'], array('copy', 'move'), true) && ($o['dest'] ?? '') === 'Trash';
		});
		$this->ok(count($toTrash) === 1, 'delete pushes the source message into Trash (COPY/MOVE)');
		$this->ok(isset($client->folders['Trash']['messages']) && count($client->folders['Trash']['messages']) === 1,
			'the source message now sits in Trash');
		$this->ok($this->reload($msg)->get('iem_imap_folder') === 'Trash',
			'locator follows to Trash (the already-trashed shadow, so it is not re-pushed)');

		$this->account->set('iia_sync_deletes', false);
		$this->account->prepare(); $this->account->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	// ── coverage / folders ───────────────────────────────────────────────────

	private function testFoldersExcludeCoverage() {
		$this->makeFolder('INBOX', 'inbox');
		$this->makeFolder('Work', 'custom');
		$this->makeFolder('[Gmail]/All Mail', 'all');
		$viewer = $this->allAccessViewer();
		$data = (new MailboxService($viewer))->listMailboxes();
		$found = null;
		foreach (($data['mailboxes'] ?? array()) as $m) {
			if (intval($m['alias_id']) === intval($this->alias->key)) { $found = $m; }
		}
		$names = $found ? array_map(function ($f) { return $f['name']; }, $found['folders']) : array();
		$this->ok($found !== null, 'mailbox appears in the switcher');
		$this->ok(in_array('Work', $names, true), 'a custom-label folder is a navigable label');
		$this->ok(!in_array('INBOX', $names, true),
			'INBOX (special-use) is not a label — it is the column-driven default view');
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
