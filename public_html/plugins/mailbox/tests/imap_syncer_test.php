<?php
/** @joinery-test
 * name: imap_syncer
 * tier: db
 * env: dev-only
 * needs: []
 */
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
 *  - Import scope: widening "Existing mail" clears the per-folder cursor (the one
 *    the ingester reads) so the next poll re-seeds; future-only seeds to the head,
 *    full history starts at zero, and a day window bisects INTERNALDATE to land on
 *    the boundary between mail inside the window and mail older than it.
 *
 * Run: php plugins/mailbox/tests/imap_syncer_test.php  (requires schema synced).
 *
 * @version 2.2
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
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

/**
 * One fetched message, mimicking the Horde_Imap_Client_Data_Fetch accessors the
 * ingest and sync paths use: flags for the sync pull, INTERNALDATE for the
 * date-boundary seek. Horde returns a DateTime subclass from getImapDate(), so
 * this does too.
 */
class FakeFetchData {
	private $flags;
	private $date;
	public function __construct(array $flags, ?string $dateUtc = null) {
		$this->flags = $flags;
		$this->date = $dateUtc;
	}
	public function getFlags() { return $this->flags; }
	public function getImapDate() {
		return new Horde_Imap_Client_DateTime($this->date ?? gmdate('Y-m-d H:i:s'));
	}
}

/**
 * A tiny iterable mimicking Horde_Imap_Client_Fetch_Results (uid => data). The
 * ingest walk reads it by uid (ids() then offset access), the sync pull iterates it.
 */
class FakeFetchResults implements IteratorAggregate, ArrayAccess {
	private $rows;
	public function __construct(array $rows) { $this->rows = $rows; } // uid => FakeFetchData
	public function getIterator(): Iterator { return new ArrayIterator($this->rows); }
	public function ids() { return array_keys($this->rows); }
	public function offsetExists($offset): bool { return isset($this->rows[$offset]); }
	#[\ReturnTypeWillChange]
	public function offsetGet($offset) { return $this->rows[$offset] ?? null; }
	public function offsetSet($offset, $value): void { $this->rows[$offset] = $value; }
	public function offsetUnset($offset): void { unset($this->rows[$offset]); }
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

	/**
	 * Honours the UID range in $options['ids'] — a real server does, and the
	 * date-boundary seek depends on it (an unfiltered result would make every
	 * probe look like it landed on the oldest message in the folder).
	 */
	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array()) {
		$this->ops[] = array('op' => 'fetch', 'mailbox' => $mailbox,
			'ids' => (string)($options['ids'] ?? ''), 'changedsince' => $options['changedsince'] ?? null);
		$wanted = $this->idsFilter($options['ids'] ?? null);
		$rows = array();
		foreach (($this->folders[$mailbox]['messages'] ?? array()) as $uid => $m) {
			if ($wanted !== null && !$wanted(intval($uid))) { continue; }
			$rows[$uid] = new FakeFetchData($m['flags'] ?? array(), $m['date'] ?? null);
		}
		return new FakeFetchResults($rows);
	}

	/** A uid → bool test for a fetch's ids option, or null for "everything". */
	private function idsFilter($ids): ?callable {
		$spec = (string)$ids;
		if ($spec === '' || strpos($spec, '*') !== false) { return null; }
		if (strpos($spec, ':') !== false) {
			list($from, $to) = explode(':', $spec, 2);
			$from = intval($from); $to = intval($to);
			return function (int $uid) use ($from, $to) { return $uid >= $from && $uid <= $to; };
		}
		$set = array_flip($this->idsToArray($ids));
		return function (int $uid) use ($set) { return isset($set[$uid]); };
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
	private $db;
	private $suffix;
	private $domain_id;
	private $alias;
	private $account;

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		return check((bool)$c, $l);
	}

	function run() {
		section('ImapSyncer tests (mock client)');
		try {
			$this->setUp();
			$this->testFlagsPullCleanApplied();
			$this->testFlagsPullDirtySkipped();
			$this->testZeroCursorRebaselinesWithoutFetch();
			$this->testNoServerModseqSkipsPull();
			$this->testRealCursorNotClobberedByZeroModseq();
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
			$this->testRewindCursorsClearsFolderPosition();
			$this->testRewoundFolderHonoursImportScope();
			$this->testDayWindowSeeksTheBoundary();
			$this->testImportScopeNormalization();
			$this->testEditorScopeChangeRewinds();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
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

	// ── modseq cursor validity (a 0 is "unknown", never a cursor) ────────────
	//
	// The failure being pinned: a stored cursor of 0 used to fall through to
	// reconcileFlags, whose CHANGEDSINCE 0 the fetch layer DROPS — turning the
	// pull into a full-mailbox FLAGS fetch (OOM on a 685k-message folder). And a
	// server that reports no HIGHESTMODSEQ (0) used to have that 0 written as the
	// baseline, manufacturing the same state on every later cycle.

	private function testZeroCursorRebaselinesWithoutFetch() {
		$client = new FakeImapClient();
		$folder = $this->makeFolder('INBOX', 'inbox', 1, 0); // pre-fix baseline: literal 0
		$msg = $this->makeMessage('INBOX', 12, array('is_read' => false));
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'highestmodseq' => 9,
			'messages' => array(12 => array('flags' => array('\seen'), 'message_id' => $msg->get('iem_message_id_header'))));

		$this->syncer($client)->pull();
		$this->ok($client->opCount('fetch') === 0,
			'cursor 0 issues NO fetch (a full-mailbox FLAGS fetch is what CHANGEDSINCE 0 degrades to)');
		$f = new InboundImapFolder($folder->key, TRUE);
		$this->ok(intval($f->get('iif_last_sync_modseq')) === 9,
			'cursor 0 re-baselines to the server\'s real HIGHESTMODSEQ');
		$this->ok(!$this->pgBool($this->reload($msg)->get('iem_is_read')),
			'the re-baseline cycle reconciles nothing (baseline, not sync)');
	}

	private function testNoServerModseqSkipsPull() {
		$client = new FakeImapClient();
		$folder = $this->makeFolder('INBOX', 'inbox', 1, 0);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'highestmodseq' => 0,
			'messages' => array(13 => array('flags' => array('\seen'), 'message_id' => '<x13@x>')));

		$this->syncer($client)->pull();
		$this->ok($client->opCount('fetch') === 0,
			'no server modseq + no cursor: folder skipped, no fetch');
		$f = new InboundImapFolder($folder->key, TRUE);
		$this->ok(intval($f->get('iif_last_sync_modseq')) <= 0,
			'no server modseq: 0 is never written as an established baseline');
	}

	private function testRealCursorNotClobberedByZeroModseq() {
		$client = new FakeImapClient();
		$folder = $this->makeFolder('INBOX', 'inbox', 1, 5);
		$client->folders['INBOX'] = array('uidvalidity' => 1, 'highestmodseq' => 0,
			'messages' => array());

		$this->syncer($client)->pull();
		$f = new InboundImapFolder($folder->key, TRUE);
		$this->ok(intval($f->get('iif_last_sync_modseq')) === 5,
			'a real cursor survives a STATUS that reports no modseq (never overwritten with 0)');
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

	/**
	 * Turning "Import full email history" on has to rewind the per-folder cursor —
	 * the one the ingester reads. Rewinding only the legacy account-level cursor
	 * leaves the folder positioned and the backfill silently never happens.
	 */
	private function testRewindCursorsClearsFolderPosition() {
		$inbox = $this->makeFolder('INBOX', 'inbox', 7, 42);
		$inbox->set('iif_last_seen_uid', 900);
		$inbox->prepare(); $inbox->save();
		$work = $this->makeFolder('Work', 'custom', 7, 42);
		$work->set('iif_last_seen_uid', 55);
		$work->prepare(); $work->save();

		$rewound = InboundImapFolder::rewindCursors(intval($this->account->key));
		$this->ok($rewound >= 2, 'rewindCursors reports every folder of the feed');

		foreach (array('INBOX', 'Work') as $name) {
			$f = $this->folderRow($name);
			$this->ok($f !== null && $f->get('iif_uidvalidity') === null,
				$name . ': UIDVALIDITY cleared so the next poll re-seeds');
			$this->ok($f !== null && $f->get('iif_last_seen_uid') === null,
				$name . ': position cleared so the backfill starts at the oldest message');
			$this->ok($f !== null && $f->get('iif_last_sync_modseq') === null,
				$name . ': sync modseq cleared with the position it belonged to');
		}

		$this->ok(InboundImapFolder::rewindCursors(0) === 0,
			'rewindCursors on no feed is a no-op, not a full-table rewind');
	}

	/**
	 * The consequence of the rewind, at the ingest decision point: a rewound folder
	 * seeds past the existing mail when the feed is future-only, and walks the
	 * window from the oldest message when it is full-history.
	 */
	private function testRewoundFolderHonoursImportScope() {
		$client = new FakeImapClient();
		$client->folders['INBOX'] = array('uidvalidity' => 7, 'uidnext' => 6, 'messages' => array());
		$this->makeFolder('INBOX', 'inbox', 7, 42);

		$this->account->set('iia_sync_mode', InboundImapAccount::SYNC_OFF);
		$this->account->set('iia_imap_folder', 'INBOX');

		// Future-only: seed forward, nothing behind the cursor is ever fetched.
		$res = $this->pollAfterRewind($client, InboundImapAccount::SCOPE_FUTURE);
		$this->ok(strpos((string)$res['status'], 'seeded cursor') !== false,
			'future-only seeds the rewound cursor past the existing mail');
		$this->ok(intval($this->folderRow('INBOX')->get('iif_last_seen_uid')) === 5,
			'future-only leaves the cursor at the mailbox head (no backfill)');

		// Full history: the same rewound folder walks the window instead.
		$res = $this->pollAfterRewind($client, InboundImapAccount::SCOPE_FULL);
		$this->ok(strpos((string)$res['status'], 'seeded cursor') === false,
			'full history does not seed past the existing mail — it walks it');
		$this->ok(intval($this->folderRow('INBOX')->get('iif_uidvalidity')) === 7,
			'the walk re-stamps UIDVALIDITY from the server');

		$this->restoreFixtureAccount();
	}

	/**
	 * The day-bounded scope: the cursor lands on the boundary between mail inside
	 * the window and mail older than it, so the backfill walks recent mail only.
	 * The seek bisects INTERNALDATE over the UID space — no IMAP SEARCH, which is
	 * what made this option unavailable before.
	 */
	private function testDayWindowSeeksTheBoundary() {
		$client = new FakeImapClient();
		$messages = array();
		// UIDs 1-40 are old (ascending age matches ascending UID, as IMAP guarantees),
		// 41-80 are inside a 30-day window. A gap at 21-30 stands in for deletions.
		for ($uid = 1; $uid <= 80; $uid++) {
			if ($uid >= 21 && $uid <= 30) { continue; }
			$age = $uid <= 40 ? (400 - $uid) : (25 - intdiv($uid - 40, 4));
			$messages[$uid] = array(
				'flags' => array(),
				'date'  => LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), '-' . $age . ' days', 'Y-m-d H:i:s'),
			);
		}
		$client->folders['INBOX'] = array('uidvalidity' => 7, 'uidnext' => 81, 'messages' => $messages);
		$this->makeFolder('INBOX', 'inbox', 7, 42);

		$this->account->set('iia_sync_mode', InboundImapAccount::SYNC_OFF);
		$this->account->set('iia_imap_folder', 'INBOX');
		$this->account->set('iia_import_days', 30);
		$this->pollAfterRewind($client, InboundImapAccount::SCOPE_DAYS);

		// The window fetch starts at cursor+1, so the cursor must sit at 40: every
		// message from 41 on is inside 30 days, everything at or below 40 is not.
		$cursor = intval($this->folderRow('INBOX')->get('iif_last_seen_uid'));
		$this->ok($cursor >= 40, 'the seek skips mail older than the window (cursor at or past 40)',
			'cursor=' . $cursor);
		$this->ok($cursor <= 41, 'the seek does not overshoot into the window it should import',
			'cursor=' . $cursor);

		// A window wider than the whole mailbox reaches the very first message.
		$this->account->set('iia_import_days', 3000);
		$this->pollAfterRewind($client, InboundImapAccount::SCOPE_DAYS);
		$this->ok(intval($this->folderRow('INBOX')->get('iif_last_seen_uid')) === 0,
			'a window wider than the mailbox starts at the beginning');

		// A window narrower than the newest message imports nothing old.
		$this->account->set('iia_import_days', 1);
		$res = $this->pollAfterRewind($client, InboundImapAccount::SCOPE_DAYS);
		$this->ok(intval($this->folderRow('INBOX')->get('iif_last_seen_uid')) === 80,
			'a window with nothing in it seeds to the mailbox head');
		$this->ok(intval($res['stored'] ?? 0) === 0, 'and stores nothing');

		$this->account->set('iia_import_days', InboundImapAccount::IMPORT_DAYS_DEFAULT);
		$this->restoreFixtureAccount();
	}

	/** Scope normalization + the "did this change where we read from?" comparison. */
	private function testImportScopeNormalization() {
		$a = new InboundImapAccount(NULL);
		$a->set('iia_import_scope', 'nonsense');
		$this->ok($a->importScope() === InboundImapAccount::SCOPE_FUTURE,
			'an unknown scope reads as future-only, never as a full-archive download');

		$a->set('iia_import_scope', InboundImapAccount::SCOPE_DAYS);
		$a->set('iia_import_days', 0);
		$this->ok($a->importDays() === InboundImapAccount::IMPORT_DAYS_DEFAULT,
			'an empty day window falls back to the default rather than importing nothing');
		$a->set('iia_import_days', 99999);
		$this->ok($a->importDays() === InboundImapAccount::IMPORT_DAYS_MAX, 'the day window is capped');

		$a->set('iia_import_days', 30);
		$this->ok($a->importScopeChanged(InboundImapAccount::SCOPE_FUTURE, 0),
			'future → days is a change (the feed must re-seed)');
		$this->ok($a->importScopeChanged(InboundImapAccount::SCOPE_DAYS, 7),
			'a different day window is a change');
		$this->ok(!$a->importScopeChanged(InboundImapAccount::SCOPE_DAYS, 30),
			'the same window is not a change — saving the page does not restart the import');

		$a->set('iia_import_scope', InboundImapAccount::SCOPE_FULL);
		$this->ok($a->describeImportScope() === 'the full mailbox history',
			'the scope describes itself for the save confirmation');
	}

	/**
	 * The wiring: saving the feed editor with a wider "Existing mail" choice is what
	 * has to rewind the folder cursor. This is the check that fails if the editor
	 * ever again clears only the legacy account-level cursor.
	 */
	private function testEditorScopeChangeRewinds() {
		require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_imap_edit_logic.php'));

		$admin = make_user('imapedit' . $this->suffix, 10);
		$session = SessionControl::get_instance();

		$save = function (array $scopeInput) use ($session, $admin) {
			$session->set_api_user($admin->key);
			try {
				return admin_mailbox_imap_edit_logic(array_merge(array(
					'_submitted'                     => '1',
					'edit_primary_key_value'         => intval($this->account->key),
					'iia_provider_key'               => 'imap_generic',
					'iia_label'                      => $this->account->get('iia_label'),
					'iia_username'                   => $this->account->get('iia_username'),
					'iia_imap_host'                  => 'imap.test',
					'iia_imap_port'                  => 993,
					'iia_imap_encryption'            => 'ssl',
					'iia_imap_folder'                => 'INBOX',
					'iia_poll_interval_seconds'      => 300,
					'iia_iea_inbound_email_alias_id' => intval($this->alias->key),
					'iia_is_enabled'                 => '1',
					'iia_sync_mode'                  => InboundImapAccount::SYNC_OFF,
				), $scopeInput));
			} finally {
				$session->clear_api_user();
			}
		};

		$positionFolder = function (int $uid) {
			$f = $this->makeFolder('INBOX', 'inbox', 7, 42);
			$f->set('iif_last_seen_uid', $uid);
			$f->prepare(); $f->save();
		};

		// future → full rewinds.
		$this->account->set('iia_import_scope', InboundImapAccount::SCOPE_FUTURE);
		$this->account->prepare(); $this->account->save();
		$positionFolder(900);
		$res = $save(array('import_scope' => InboundImapAccount::SCOPE_FULL));
		$this->ok($res->error === null, 'the editor saved the feed', (string)$res->error);
		$saved = new InboundImapAccount($this->account->key, TRUE);
		$this->ok($saved->importScope() === InboundImapAccount::SCOPE_FULL, 'the feed is now full history');
		$this->ok($this->folderRow('INBOX')->get('iif_last_seen_uid') === null,
			'saving the switch rewinds the folder cursor the ingester reads');

		// full → days rewinds too, and carries the window.
		$positionFolder(900);
		$save(array('import_scope' => InboundImapAccount::SCOPE_DAYS, 'iia_import_days' => 45));
		$saved = new InboundImapAccount($this->account->key, TRUE);
		$this->ok($saved->importDays() === 45, 'the day window is saved');
		$this->ok($this->folderRow('INBOX')->get('iif_last_seen_uid') === null,
			'narrowing to a day window re-seeds as well');

		// Re-saving the same choice leaves the feed where it is — no restarted import.
		$positionFolder(900);
		$save(array('import_scope' => InboundImapAccount::SCOPE_DAYS, 'iia_import_days' => 45));
		$this->ok(intval($this->folderRow('INBOX')->get('iif_last_seen_uid')) === 900,
			'saving the page without changing the scope does not restart the import');

		$this->restoreFixtureAccount();
	}

	/** Set the import scope, rewind as the editor does on the switch, then poll once. */
	private function pollAfterRewind(FakeImapClient $client, string $scope): array {
		$this->account->set('iia_import_scope', $scope);
		$this->account->prepare(); $this->account->save();
		InboundImapFolder::rewindCursors(intval($this->account->key));
		$ingestor = new ImapIngestor(new InboundImapAccount($this->account->key, TRUE), $client);
		return $ingestor->poll(50);
	}

	/** Put the shared fixture account back the way the sync tests expect it. */
	private function restoreFixtureAccount() {
		$a = new InboundImapAccount($this->account->key, TRUE);
		$a->set('iia_import_scope', InboundImapAccount::SCOPE_FUTURE);
		$a->set('iia_import_days', InboundImapAccount::IMPORT_DAYS_DEFAULT);
		$a->set('iia_sync_mode', InboundImapAccount::SYNC_BOTH);
		$a->prepare(); $a->save();
		$this->account = new InboundImapAccount($this->account->key, TRUE);
	}

	private function folderRow(string $name): ?InboundImapFolder {
		$rows = new MultiInboundImapFolder(array('account_id' => intval($this->account->key), 'name' => $name));
		$rows->load();
		return count($rows) ? new InboundImapFolder($rows->get(0)->key, TRUE) : null;
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
$test->run();
harness_finish();
