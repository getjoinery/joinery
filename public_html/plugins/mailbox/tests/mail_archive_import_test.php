<?php
/** @joinery-test
 * name: mail_archive_import
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A mail archive import end to end, against a real mailbox.
 *
 * The reader layer is proved separately and without a database
 * (mail_archive_readers_test.php). What this covers is everything that only
 * exists once mail is actually stored:
 *
 *  - the scan writes one entry per message, with the right folder and class, and
 *    stores no mail at all
 *  - the selection leaves Spam out and accounts for it as skipped, not as missing
 *  - importing files mail into the chosen mailbox while recording the address it
 *    was really delivered to, carries read/starred state, reproduces labels, and
 *    files sent mail as outbound
 *  - re-importing the same archive stores nothing new — the property that makes
 *    resume, retry and "did I already do this" all safe
 *  - INBOUND FILTERS DO NOT RUN on imported mail, which is the one behaviour a
 *    reader test could never catch and the one most likely to regress
 *  - undo removes exactly what the run created and nothing else
 *  - a corrupt entry fails on its own and the rest of the batch still lands
 *
 * Run: php tests/run.php db --filter=mail_archive_import
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveImporter.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

class MailArchiveImportTest {

	private $db;
	private $suffix;
	private $domain_id;
	private $alias;
	private $file_ids = array();
	private $run_ids = array();
	private $fixtures;

	function __construct() {
		$this->db = DbConnector::get_instance()->get_db_link();
		$this->fixtures = __DIR__ . '/fixtures/import';
	}

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }

	function run() {
		section('Mail archive import, end to end');
		try {
			$this->setUp();
			$this->testTaskDrivesTheRun();
			$this->testScanFindsEverythingAndStoresNothing();
			$this->testSelectionSkipsSpam();
			$this->testImportStoresAndFiles();
			$this->testFiltersDoNotRun();
			$this->testReimportDedups();
			$this->testCrossMailboxDedupIsNotAFailure();
			$this->testCorruptEntryFailsAlone();
			$this->testUndoRemovesOnlyWhatItCreated();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	// ------------------------------------------------------------------ setup

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('mai', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'import-test-' . $this->suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$alias = new InboundEmailAlias(NULL);
		$alias->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$alias->set('iea_alias', 'box' . $this->suffix);
		$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$alias->set('iea_is_enabled', true);
		$alias->prepare();
		$alias->save();
		$alias->load();
		$this->alias = $alias;

		$this->out('  fixtures ready (suffix ' . $this->suffix . ')');
	}

	/** A run over a copy of the named fixture, owned by the system user. */
	private function makeRun(string $fixture, string $displayName): MailImportRun {
		$bytes = file_get_contents($this->fixtures . '/' . $fixture);
		$file = File::createFromBytes($bytes, $this->suffix . '-' . $displayName,
			'application/octet-stream', User::USER_SYSTEM, array('fil_private' => true));
		$this->file_ids[] = intval($file->key);

		$run = new MailImportRun(NULL);
		$run->set('mir_iea_inbound_email_alias_id', intval($this->alias->key));
		$run->set('mir_usr_user_id', User::USER_SYSTEM);
		$run->set('mir_fil_file_id', intval($file->key));
		$run->set('mir_source_name', $displayName);
		$run->set('mir_state', MailImportRun::STATE_QUEUED);
		$run->set('mir_own_addresses', "me@example.test\nold@example.test");
		$run->prepare();
		$run->save();
		$run->load();
		$this->run_ids[] = intval($run->key);
		return $run;
	}

	/** Scan a run to completion, however many passes it takes. */
	private function scanToEnd(MailArchiveImporter $importer): array {
		$last = array('done' => false);
		for ($i = 0; $i < 20 && empty($last['done']); $i++) {
			$last = $importer->scanBatch(microtime(true) + 20, 5000);
		}
		return $last;
	}

	/** Import a run to completion. */
	private function importToEnd(MailArchiveImporter $importer): array {
		$totals = array('stored' => 0, 'dedup' => 0, 'failed' => 0, 'seen' => 0);
		for ($i = 0; $i < 50; $i++) {
			$batch = $importer->importBatch(50);
			foreach (array('stored', 'dedup', 'failed', 'seen') as $k) {
				$totals[$k] += intval($batch[$k]);
			}
			if (!empty($batch['exhausted'])) {
				break;
			}
		}
		return $totals;
	}

	private function messages(array $filters = array()): MultiInboundEmailMessage {
		$m = new MultiInboundEmailMessage(array_merge(
			array('alias_id' => intval($this->alias->key)), $filters));
		$m->load();
		return $m;
	}

	private function messageCount(): int {
		$stmt = $this->db->prepare('SELECT COUNT(*) FROM iem_inbound_email_messages
			WHERE iem_iea_inbound_email_alias_id = ?');
		$stmt->execute(array(intval($this->alias->key)));
		return intval($stmt->fetchColumn());
	}

	// ------------------------------------------------------------------ tests

	private $mainRun = null;

	/**
	 * The scheduled task, not the importer underneath it. What is actually at risk
	 * here is the claim: a conditional UPDATE that has to pick exactly one run,
	 * stamp it, and hand it back — and a run that stops at `scanned` rather than
	 * charging on into storing mail nobody asked for.
	 */
	private function testTaskDrivesTheRun() {
		require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/RunMailImports.php'));

		$run = $this->makeRun('takeout.mbox', 'task-driven.mbox');
		$task = new RunMailImports();

		// Enough passes for the claim to reach this run even if another is queued.
		for ($i = 0; $i < 8; $i++) {
			$result = $task->run(array());
			check(is_array($result) && isset($result['status']),
				'task: pass ' . ($i + 1) . ' returned a task result');
			$run->load();
			if ((string)$run->get('mir_state') === MailImportRun::STATE_SCANNED) {
				break;
			}
		}

		check((string)$run->get('mir_state') === MailImportRun::STATE_SCANNED,
			'task: the run scanned and then STOPPED, waiting for the user to choose',
			(string)$run->get('mir_state'));
		check(intval($run->get('mir_total_entries')) === 3,
			'task: the scan found every message', (string)$run->get('mir_total_entries'));
		check($this->messageCount() === 0,
			'task: no mail was stored before anybody chose anything', (string)$this->messageCount());

		// The claim must be HANDED BACK at the end of a pass. It exists to stop two
		// passes overlapping, not to space passes out — a run that stayed claimed
		// could only advance once per stale window, so a large import would crawl.
		check($run->get('mir_claim_time') === null,
			'task: a completed pass releases its claim, so the next pass can continue the run',
			var_export($run->get('mir_claim_time'), true));

		// The cap counts runs UNDERWAY, never runs merely waiting. Counting queued
		// runs would mean that once more were queued than the cap allowed, none of
		// them could ever start.
		check(MailImportRun::inFlightCount() === 0,
			'task: a run waiting on the user does not count against the concurrency cap',
			(string)MailImportRun::inFlightCount());

		$queued = $this->makeRun('takeout.mbox', 'queued-behind.mbox');
		check(MailImportRun::inFlightCount() === 0,
			'task: a queued run does not count against the cap either, so it can still start',
			(string)MailImportRun::inFlightCount());
		$queued->moveTo(MailImportRun::STATE_DONE);

		// Release it so it cannot compete with the runs the later tests create.
		$run->moveTo(MailImportRun::STATE_DONE);
	}

	private function testScanFindsEverythingAndStoresNothing() {
		$before = $this->messageCount();
		$run = $this->makeRun('takeout.mbox', 'takeout.mbox');
		$this->mainRun = $run;

		$importer = new MailArchiveImporter($run);
		$result = $this->scanToEnd($importer);
		$run->load();

		check(!empty($result['done']), 'scan: the walk finished');
		check(intval($run->get('mir_total_entries')) === 3,
			'scan: one entry per message in the archive', 'got ' . $run->get('mir_total_entries'));
		check($this->messageCount() === $before,
			'scan: no mail is stored while scanning — that is the import phase\'s job');

		$counts = MailImportEntry::folderCounts(intval($run->key));
		$byClass = array();
		foreach ($counts as $row) {
			$byClass[$row['class']] = ($byClass[$row['class']] ?? 0) + $row['count'];
		}
		check(($byClass['spam'] ?? 0) === 1, 'scan: the spam-labelled message is classified as spam',
			json_encode($byClass));
		check(($byClass['normal'] ?? 0) === 2, 'scan: the rest are normal', json_encode($byClass));

		// Direction is settled at scan, while the headers are already in hand.
		$sent = new MultiMailImportEntry(array('run_id' => intval($run->key), 'state' => 'pending'));
		$sent->load();
		$outbound = 0;
		foreach ($sent as $entry) {
			if ((string)$entry->get('mie_direction') === 'outbound') { $outbound++; }
		}
		check($outbound === 1, 'scan: the message in Sent is recorded as outbound', 'got ' . $outbound);
	}

	private function testSelectionSkipsSpam() {
		$run = $this->mainRun;
		$run->moveTo(MailImportRun::STATE_SCANNED);

		$importer = new MailArchiveImporter($run);
		$preview = $importer->preview();
		check($preview['spam'] === 1, 'preview: the spam count is shown separately',
			json_encode($preview));
		check($preview['total'] === 3, 'preview: every message found is counted');

		// Take everything except spam, which is how the screen arrives by default.
		$skipped = $importer->applySelection(array('*'), false, false);
		$run->load();

		check($skipped === 1, 'selection: the spam message is skipped', 'got ' . $skipped);
		check((string)$run->get('mir_state') === MailImportRun::STATE_IMPORTING,
			'selection: confirming releases the run to import');
		check(intval($run->get('mir_skipped')) === 1,
			'selection: a skipped message is ACCOUNTED for, not merely absent');
		check(intval($run->get('mir_processed')) === 1,
			'selection: skipping counts as processed, so the reconciliation adds up');
	}

	private function testImportStoresAndFiles() {
		$run = $this->mainRun;
		$importer = new MailArchiveImporter($run);
		$totals = $this->importToEnd($importer);
		$run->load();

		check($totals['stored'] === 2, 'import: both selected messages are stored',
			json_encode($totals));
		check(intval($run->get('mir_stored')) === 2, 'import: the run counter agrees');

		// stored + dedup + skipped + failed must equal what the scan found.
		$accounted = intval($run->get('mir_stored')) + intval($run->get('mir_dedup'))
			+ intval($run->get('mir_skipped')) + intval($run->get('mir_failed'));
		check($accounted === intval($run->get('mir_total_entries')),
			'import: every message the scan found is accounted for',
			$accounted . ' of ' . $run->get('mir_total_entries'));

		$stored = $this->messages();
		check(count($stored) === 2, 'import: the mailbox holds exactly the imported mail',
			'got ' . count($stored));

		$byId = array();
		foreach ($stored as $m) {
			$byId[(string)$m->get('iem_message_id_header')] = $m;
		}

		$receipt = $byId['<takeout-one@example.test>'] ?? null;
		check($receipt !== null, 'import: the received message is there');
		if ($receipt) {
			check(intval($receipt->get('iem_iea_inbound_email_alias_id')) === intval($this->alias->key),
				'import: it is filed in the chosen mailbox');
			// Filing and delivery address are independent, which is the point.
			check((string)$receipt->get('iem_recipient') === 'me@example.test',
				'import: it records the address it was really delivered to, not the mailbox address',
				(string)$receipt->get('iem_recipient'));
			check((string)$receipt->get('iem_direction') === 'inbound', 'import: recorded as inbound');
			check(!$this->truthy($receipt->get('iem_is_read')),
				'import: the Unread label carried across');
			check(substr((string)$receipt->get('iem_received_time'), 0, 10) === '2015-01-05',
				'import: it sorts by its own Date header, not the import clock',
				(string)$receipt->get('iem_received_time'));
			check(strpos((string)$receipt->get('iem_body_plain'), 'Thanks for your order') !== false,
				'import: the body was parsed and stored');
			check(intval($receipt->get('iem_mir_mail_import_run_id')) === intval($run->key),
				'import: the row is tagged with its run, which is what makes undo possible');

			// A source folder that is not one of the platform's own buckets becomes
			// a label of the same name.
			$label = InboundEmailLabel::getByName('Receipts');
			check($label !== null && InboundLabelMember::isMember(intval($receipt->key), intval($label->key)),
				'import: the source label is reproduced');
		}

		$sent = $byId['<takeout-two@example.test>'] ?? null;
		check($sent !== null, 'import: the sent message is there');
		if ($sent) {
			check((string)$sent->get('iem_direction') === 'outbound',
				'import: mail from the user is filed as sent');
			check((string)$sent->get('iem_recipient') === 'friend@elsewhere.test',
				'import: sent mail records its recipient',
				(string)$sent->get('iem_recipient'));
			check($this->truthy($sent->get('iem_is_starred')), 'import: the Starred label carried across');
		}

		$run->load();
		check((string)$run->get('mir_state') === MailImportRun::STATE_IMPORTING
			|| (string)$run->get('mir_state') === MailImportRun::STATE_DONE,
			'import: the run is still governed by its state machine');
	}

	private function testFiltersDoNotRun() {
		// A filter that would catch everything, of the kind that fires a visible
		// side effect. Imported mail must pass it by: the archive already reflects
		// whatever filtering its source applied, and firing live rules on years-old
		// mail would act on messages nobody just received.
		$filter = new InboundEmailFilter(NULL);
		$filter->set('fil_ied_inbound_email_domain_id', $this->domain_id);
		$filter->set('fil_iea_inbound_email_alias_id', intval($this->alias->key));
		$filter->set('fil_name', 'catch everything ' . $this->suffix);
		$filter->set('fil_is_enabled', true);
		$filter->set('fil_match_subject', 'a');   // matches any subject containing "a"
		$filter->set('fil_action_archive', true);
		$filter->set('fil_action_mark_spam', true);
		$filter->save();
		$filter->load();

		$run = $this->makeRun('proton/user@example.test/aaa.eml', 'statement.eml');
		$importer = new MailArchiveImporter($run);
		$this->scanToEnd($importer);
		$run->load();
		$run->moveTo(MailImportRun::STATE_SCANNED);
		$importer->applySelection(array('*'), true, true);
		$this->importToEnd($importer);

		$stmt = $this->db->prepare('SELECT iem_is_archived, iem_spam_verdict FROM iem_inbound_email_messages
			WHERE iem_message_id_header = ? AND iem_iea_inbound_email_alias_id = ?');
		$stmt->execute(array('<proton-aaa@example.test>', intval($this->alias->key)));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		check($row !== false, 'filters: the imported message stored');
		if ($row !== false) {
			check(!$this->truthy($row['iem_is_archived']),
				'filters: the archive action did NOT fire on imported mail');
			check((string)$row['iem_spam_verdict'] !== 'spam',
				'filters: the mark-spam action did NOT fire on imported mail',
				(string)$row['iem_spam_verdict']);
		}

		try { $filter->permanent_delete(); } catch (\Throwable $e) {}
	}

	private function testReimportDedups() {
		$before = $this->messageCount();

		// The same archive again, as a brand new run — exactly what happens when
		// somebody imports twice by accident, or a run is retried after a crash.
		$run = $this->makeRun('takeout.mbox', 'takeout-again.mbox');
		$importer = new MailArchiveImporter($run);
		$this->scanToEnd($importer);
		$run->load();
		$run->moveTo(MailImportRun::STATE_SCANNED);
		$importer->applySelection(array('*'), false, false);
		$totals = $this->importToEnd($importer);
		$run->load();

		check($totals['stored'] === 0, 'reimport: nothing new is stored', json_encode($totals));
		check($totals['dedup'] === 2, 'reimport: both messages are recognised as already here',
			json_encode($totals));
		check($this->messageCount() === $before,
			'reimport: the mailbox is unchanged', $this->messageCount() . ' vs ' . $before);

		// The second run must not have tagged anything, or undoing IT would remove
		// mail the FIRST run brought in.
		$stmt = $this->db->prepare('SELECT COUNT(*) FROM iem_inbound_email_messages
			WHERE iem_mir_mail_import_run_id = ?');
		$stmt->execute(array(intval($run->key)));
		check(intval($stmt->fetchColumn()) === 0,
			'reimport: a deduped message is never tagged, so undo cannot remove mail it did not create');
	}

	/**
	 * The platform's dedup key has no mailbox in it — a message is stored once,
	 * site-wide. So importing the same archive into a SECOND mailbox meets mail
	 * that is already there, and that has to read as a duplicate rather than as a
	 * failure. It surfaces through the model's own pre-validation rather than the
	 * database constraint, which is the path that used to be unhandled.
	 */
	private function testCrossMailboxDedupIsNotAFailure() {
		$second = new InboundEmailAlias(NULL);
		$second->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$second->set('iea_alias', 'other' . $this->suffix);
		$second->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$second->set('iea_is_enabled', true);
		$second->prepare();
		$second->save();
		$second->load();

		$run = $this->makeRun('takeout.mbox', 'second-mailbox.mbox');
		$run->writeColumns(array('mir_iea_inbound_email_alias_id' => intval($second->key)));
		$run->load();

		$importer = new MailArchiveImporter($run);
		$this->scanToEnd($importer);
		$run->load();
		$run->moveTo(MailImportRun::STATE_SCANNED);
		$importer->applySelection(array('*'), false, false);
		$totals = $this->importToEnd($importer);

		check($totals['failed'] === 0,
			'cross-mailbox: mail already on the site is not reported as a failure',
			json_encode($totals));
		check($totals['dedup'] === 2,
			'cross-mailbox: it is reported as a duplicate instead', json_encode($totals));

		$stmt = $this->db->prepare("SELECT mie_reason FROM mie_mail_import_entries
			WHERE mie_mir_mail_import_run_id = ? AND mie_state = 'dedup' LIMIT 1");
		$stmt->execute(array(intval($run->key)));
		check(stripos((string)$stmt->fetchColumn(), 'already') !== false,
			'cross-mailbox: the entry says so in words the user can act on');

		try { $second->permanent_delete(); } catch (\Throwable $e) {}
	}

	private function testCorruptEntryFailsAlone() {
		$run = $this->makeRun('truncated.mbox', 'damaged.mbox');
		$importer = new MailArchiveImporter($run);
		$this->scanToEnd($importer);
		$run->load();

		// Point one entry at a position the archive does not have, leaving the real
		// one alone. One unreadable message must not cost the rest of the batch —
		// which is the whole reason failures are recorded per entry.
		$this->db->prepare("INSERT INTO mie_mail_import_entries
			(mie_mir_mail_import_run_id, mie_locator, mie_ordinal, mie_source_folder,
			 mie_direction, mie_class, mie_state)
			VALUES (?, 'not-a-position', 99, 'Inbox', 'inbound', 'normal', 'pending')")
			->execute(array(intval($run->key)));
		$run->writeColumns(array('mir_total_entries' => intval($run->get('mir_total_entries')) + 1));

		$run->moveTo(MailImportRun::STATE_SCANNED);
		$importer->applySelection(array('*'), true, true);
		$totals = $this->importToEnd($importer);
		$run->load();

		check($totals['failed'] === 1, 'failure: the unreadable entry failed', json_encode($totals));
		check($totals['stored'] === 1, 'failure: the readable entry still stored', json_encode($totals));

		$stmt = $this->db->prepare("SELECT mie_reason FROM mie_mail_import_entries
			WHERE mie_mir_mail_import_run_id = ? AND mie_state = 'failed'");
		$stmt->execute(array(intval($run->key)));
		$reason = (string)$stmt->fetchColumn();
		check($reason !== '', 'failure: the entry records WHY it failed, not just that it did', $reason);
	}

	private function testUndoRemovesOnlyWhatItCreated() {
		$run = $this->mainRun;
		$run->load();

		// Something the import did not create, standing right beside its mail.
		$bystander = new InboundEmailMessage(NULL);
		$bystander->set('iem_ied_inbound_email_domain_id', $this->domain_id);
		$bystander->set('iem_iea_inbound_email_alias_id', intval($this->alias->key));
		$bystander->set('iem_sender', 'someone@elsewhere.test');
		$bystander->set('iem_recipient', (string)$this->alias->get_full_address());
		$bystander->set('iem_subject', 'Arrived normally');
		$bystander->set('iem_message_id_header', '<bystander-' . $this->suffix . '@example.test>');
		$bystander->save();
		$bystander->load();

		$stmt = $this->db->prepare('SELECT COUNT(*) FROM iem_inbound_email_messages
			WHERE iem_mir_mail_import_run_id = ?');
		$stmt->execute(array(intval($run->key)));
		$tagged = intval($stmt->fetchColumn());
		check($tagged === 2, 'undo: the run created two messages going in', 'got ' . $tagged);

		$importer = new MailArchiveImporter($run);
		$result = $importer->undo();
		$run->load();

		check($result['removed'] === 2, 'undo: it removed exactly what the run created',
			json_encode($result));
		check($result['failed'] === 0, 'undo: nothing resisted removal');
		check((string)$run->get('mir_state') === MailImportRun::STATE_UNDONE,
			'undo: the run is marked reversed');

		$stmt->execute(array(intval($run->key)));
		check(intval($stmt->fetchColumn()) === 0, 'undo: no tagged message survives');

		$survivor = new InboundEmailMessage(intval($bystander->key), TRUE);
		check($survivor->key && (string)$survivor->get('iem_subject') === 'Arrived normally',
			'undo: mail that arrived normally is untouched');

		// The run keeps its entries, so the report of what happened outlives the
		// reversal.
		$entries = new MultiMailImportEntry(array('run_id' => intval($run->key)));
		$entries->load();
		check(count($entries) === 3, 'undo: the run keeps its record of what it did',
			'got ' . count($entries));

		try { $bystander->permanent_delete(); } catch (\Throwable $e) {}
	}

	private function truthy($v): bool {
		return ($v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true');
	}

	// --------------------------------------------------------------- teardown

	/**
	 * The fixture messages carry FIXED Message-IDs, and the platform's dedup key
	 * has no mailbox in it — a message is stored once, site-wide. So a leftover
	 * copy from any earlier run (or from someone importing the same fixture by
	 * hand) would dedup this run's mail and the assertions would be measuring the
	 * leftovers. Clear them by id, not just by this test's own domain.
	 */
	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'import-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			foreach ($dids as $did) {
				$this->purgeDomain(intval($did));
			}
			$this->purgeFixtureMessages();
		} catch (\Throwable $e) {}
	}

	/** Every message any import of these fixtures could have created, anywhere. */
	private function purgeFixtureMessages(): void {
		$ids = array('<takeout-one@example.test>', '<takeout-two@example.test>',
			'<takeout-three@example.test>', '<truncated-one@example.test>',
			'<proton-aaa@example.test>', '<proton-bbb@example.test>',
			'<apple-one@example.test>', '<maildir-one@example.test>');
		$in = implode(',', array_map(array($this->db, 'quote'), $ids));

		$this->db->exec("DELETE FROM ilm_inbound_label_members WHERE ilm_iem_inbound_email_message_id IN
			(SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_message_id_header IN ($in))");
		$this->db->exec("UPDATE mie_mail_import_entries SET mie_iem_inbound_email_message_id = NULL
			WHERE mie_iem_inbound_email_message_id IN
			(SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_message_id_header IN ($in))");
		$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_message_id_header IN ($in)");
		// Synthesized ids for the fixture with no Message-ID of its own.
		$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_message_id_header LIKE '%@import.invalid'");
	}

	private function purgeDomain(int $domainId): void {
		$aids = $this->db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
			WHERE iea_ied_inbound_email_domain_id = " . $domainId)->fetchAll(PDO::FETCH_COLUMN);
		if ($aids) {
			$ain = implode(',', array_map('intval', $aids));
			$this->db->exec("DELETE FROM mie_mail_import_entries WHERE mie_mir_mail_import_run_id IN
				(SELECT mir_mail_import_run_id FROM mir_mail_import_runs
				 WHERE mir_iea_inbound_email_alias_id IN ($ain))");
			$this->db->exec("DELETE FROM mir_mail_import_runs WHERE mir_iea_inbound_email_alias_id IN ($ain)");
			$this->db->exec("DELETE FROM ilm_inbound_label_members WHERE ilm_iem_inbound_email_message_id IN
				(SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				 WHERE iem_iea_inbound_email_alias_id IN ($ain))");
			$this->db->exec("DELETE FROM fil_inbound_email_filters WHERE fil_iea_inbound_email_alias_id IN ($ain)");
		}
		$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . $domainId);
		$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . $domainId);
		$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . $domainId);
	}

	private function tearDown() {
		try {
			$this->db->exec("DELETE FROM evl_event_logs WHERE evl_event LIKE '"
				. MailArchiveImporter::RUN_EVENT . "%' AND evl_note LIKE '%takeout%'");

			foreach ($this->run_ids as $rid) {
				$this->db->exec('DELETE FROM mie_mail_import_entries WHERE mie_mir_mail_import_run_id = ' . intval($rid));
				$this->db->exec('DELETE FROM mir_mail_import_runs WHERE mir_mail_import_run_id = ' . intval($rid));
			}
			if ($this->domain_id) {
				$this->purgeDomain(intval($this->domain_id));
			}
			$this->purgeFixtureMessages();
			foreach ($this->file_ids as $fid) {
				try {
					$file = new File(intval($fid), TRUE);
					if ($file->key) { $file->permanent_delete(); }
				} catch (\Throwable $e) {}
			}
			// Labels this import invented, so a re-run starts from the same place.
			foreach (array('Receipts', 'Finance') as $name) {
				$label = InboundEmailLabel::getByName($name);
				if ($label !== null) {
					$stmt = $this->db->prepare('SELECT 1 FROM ilm_inbound_label_members
						WHERE ilm_ilb_inbound_email_label_id = ? LIMIT 1');
					$stmt->execute(array(intval($label->key)));
					if ($stmt->fetchColumn() === false) {
						$this->db->exec('DELETE FROM ilb_inbound_email_labels
							WHERE ilb_inbound_email_label_id = ' . intval($label->key));
					}
				}
			}
		} catch (\Throwable $e) {}
	}
}

$test = new MailArchiveImportTest();
$test->run();
harness_finish();
