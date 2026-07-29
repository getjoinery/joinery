<?php
/**
 * MailArchiveImporter - the engine behind one import run.
 *
 * Three phases, each resumable, none of which ever holds the archive in memory:
 *
 * SCAN walks the source once and writes one mie_ entry per message. It stores no
 * mail. When it finishes the run stops at `scanned` and waits for the user, who
 * has now been shown exact counts rather than an estimate.
 *
 * IMPORT works the entries in bounded batches, oldest first. Per entry: read the
 * bytes back at their locator, resolve who the message was for, store it,
 * reproduce the source's labels and read state, record the outcome on the entry.
 *
 * UNDO permanently deletes every message the run created, and only those.
 *
 * Almost none of the storing is new code. Live delivery already parses bodies,
 * splits attachments into private Files, computes thread keys, seals content to
 * the owner's vault, and treats a unique violation as a successful dedup — so the
 * importer points the existing store path at a different source of bytes and
 * inherits all of it. What it adds is the accounting.
 *
 * Two properties of the existing schema carry the design. Dedup is keyed on the
 * message id, so re-running an import over the same archive stores nothing new
 * and resume-after-a-crash costs at most a batch. And a message's mailbox
 * (iem_iea_inbound_email_alias_id) is independent of the address it records
 * (iem_recipient), so mail can be filed where the user wants it while still
 * saying honestly where it was delivered.
 *
 * See specs/mail_archive_import.md.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mail_import_run_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mail_import_entry_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailRunRecord.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReaderRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailImportIdentity.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

class MailArchiveImporter {

	/** evl_event value for the run record. */
	const RUN_EVENT = 'mail_archive_import';

	/** Entries written to the database per INSERT while scanning. */
	const ENTRY_INSERT_CHUNK = 500;

	/** Imported mail carries no verdict this deployment can vouch for. */
	const IMPORT_AUTH = array('dkim' => 'unverified', 'spf' => 'unverified',
		'dmarc' => 'unverified', 'source' => 'none');

	/** @var MailImportRun */
	private $run;
	/** @var InboundEmailAlias|null */
	private $alias = null;
	/** @var InboundEmailDomain|null */
	private $domain = null;
	/** @var InboundEmailRouter|null */
	private $router = null;
	/** @var MailArchiveReader|null */
	private $reader = null;
	/** @var string|null */
	private $path = null;

	public function __construct(MailImportRun $run) {
		$this->run = $run;
	}

	public function run(): MailImportRun {
		return $this->run;
	}

	// ------------------------------------------------------------------ source

	/**
	 * Where this run may expand things it cannot read in place, and nothing more:
	 * the path is NAMED here but deliberately not created.
	 *
	 * Most formats never need it — an mbox is seeked directly and a zip of saved
	 * messages is read through the stream wrapper — so creating it up front left an
	 * empty directory behind for every import that had no use for one. The two
	 * readers that do expand (a zip holding an mbox, a tar) create it at the moment
	 * they write, which means a directory existing is now evidence that something
	 * was actually put in it.
	 */
	public function workDir(): string {
		$dir = (string)$this->run->get('mir_work_dir');
		if ($dir === '') {
			$dir = sys_get_temp_dir() . '/joinery-mail-import-' . intval($this->run->key);
			$this->run->writeColumns(array('mir_work_dir' => $dir));
		}
		return $dir;
	}

	/**
	 * Where the archive's bytes are, and the reader that understands them.
	 *
	 * A Drive file whose folder is encrypted is refused rather than hidden: its
	 * plaintext exists only in the browser, so the server genuinely cannot read it,
	 * and a user who cannot find their archive is worse off than one told why.
	 */
	private function open(): void {
		if ($this->reader !== null) {
			return;
		}
		$file = new File(intval($this->run->get('mir_fil_file_id')), TRUE);
		if (!$file->key) {
			throw new RuntimeException('The archive file is no longer available.');
		}
		if ($file->get('fil_encrypted')) {
			throw new RuntimeException(self::ENCRYPTED_FILE_REASON);
		}

		$path = $file->get_filesystem_path();
		if (!is_file($path)) {
			throw new RuntimeException('The archive file could not be found on this server.');
		}

		$name = (string)($this->run->get('mir_source_name') ?: $file->get('fil_title') ?: $file->get('fil_name'));

		// A run that already knows its format keeps it, so a re-detect on a later
		// pass can never silently switch readers mid-import and invalidate every
		// locator already written.
		$key = (string)$this->run->get('mir_format');
		$reader = $key !== '' ? MailArchiveReaderRegistry::fromKey($key) : MailArchiveReaderRegistry::detect($path, $name);
		if ($reader === null) {
			throw new RuntimeException('That file is not a mail archive this platform can read. '
				. 'Supported: ' . implode(', ', MailArchiveReaderRegistry::acceptedExtensions()) . '.');
		}
		$refusal = $reader->refusal();
		if ($refusal !== null) {
			throw new RuntimeException($refusal);
		}

		// The name the person uploaded, not the one on disk — the file store appends
		// a uniquifier, and a folder named after it is unreadable noise.
		$reader->setSourceName($name);

		$this->reader = $reader;
		$this->path = $reader->prepare($path, $this->workDir());
	}

	const ENCRYPTED_FILE_REASON = 'That file is in an encrypted folder, so only your browser can read it — '
		. 'the server cannot. Put a copy in an unencrypted folder and import that.';

	/** The mailbox this run files into, and the domain that governs its protection. */
	private function target(): array {
		if ($this->alias === null) {
			$this->alias = new InboundEmailAlias(intval($this->run->get('mir_iea_inbound_email_alias_id')), TRUE);
			if (!$this->alias->key) {
				throw new RuntimeException('The target mailbox no longer exists.');
			}
			$this->domain = new InboundEmailDomain(intval($this->alias->get('iea_ied_inbound_email_domain_id')), TRUE);
			if (!$this->domain->key) {
				throw new RuntimeException('The target mailbox has no domain.');
			}
		}
		return array($this->alias, $this->domain);
	}

	private function router(): InboundEmailRouter {
		if ($this->router === null) {
			$this->router = new InboundEmailRouter();
		}
		return $this->router;
	}

	// -------------------------------------------------------------------- scan

	/**
	 * Walk more of the archive, writing entries, until the deadline or the batch
	 * limit. Returns ['found'=>int, 'done'=>bool].
	 *
	 * Nothing is stored here and nothing is decided: the scan's only job is to make
	 * the archive's contents queryable, so that the choice the user is asked to
	 * make is backed by exact numbers.
	 */
	public function scanBatch(float $deadline, int $limit): array {
		$this->open();
		list($alias, ) = $this->target();

		$state = $this->run->scanState();
		$state['limit'] = $limit;
		$own = $this->run->ownAddresses();
		$fallback = strtolower((string)$alias->get_full_address());

		$buffer = array();
		$found = 0;

		$emit = function (array $descriptor) use (&$buffer, &$found, $own, $fallback) {
			// Direction is settled now, while the headers are in hand — the import
			// pass would otherwise re-derive it for every message it stores.
			$descriptor['direction'] = MailImportIdentity::direction(
				$descriptor['headers'], $own, $descriptor['source_folder'], $descriptor['labels']);
			unset($descriptor['headers']); // not persisted; re-read from the bytes
			$buffer[] = $descriptor;
			$found++;
			if (count($buffer) >= self::ENTRY_INSERT_CHUNK) {
				MailImportEntry::insertBatch(intval($this->run->key), $buffer);
				$buffer = array();
			}
		};

		$newState = $this->reader->scan($this->path, $emit, $state, $deadline);

		if ($buffer) {
			MailImportEntry::insertBatch(intval($this->run->key), $buffer);
		}

		$this->run->writeColumns(array(
			'mir_scan_state'    => json_encode($newState),
			'mir_total_entries' => intval($this->run->get('mir_total_entries')) + $found,
		));

		return array('found' => $found, 'done' => !empty($newState['done']),
			'nested' => (array)($newState['nested'] ?? array()));
	}

	/**
	 * The scanned screen's numbers: how many messages sit in each source folder,
	 * and which of them are spam or trash. Straight aggregation — nothing loads
	 * half a million models to count them.
	 */
	public function preview(): array {
		$rows = MailImportEntry::folderCounts(intval($this->run->key));
		$folders = array();
		$spam = 0; $trash = 0; $total = 0;
		foreach ($rows as $row) {
			$total += $row['count'];
			if ($row['class'] === MailImportEntry::CLASS_SPAM)  { $spam  += $row['count']; continue; }
			if ($row['class'] === MailImportEntry::CLASS_TRASH) { $trash += $row['count']; continue; }
			$name = $row['folder'] !== '' ? $row['folder'] : 'Imported';
			$folders[$name] = ($folders[$name] ?? 0) + $row['count'];
		}
		arsort($folders);
		return array('folders' => $folders, 'spam' => $spam, 'trash' => $trash, 'total' => $total);
	}

	/**
	 * Record what the user ticked and move the run to importing. Everything not
	 * selected is marked skipped in one statement, so it is accounted for rather
	 * than merely absent — and the reconciliation at the end can tell the
	 * difference.
	 */
	public function applySelection(array $folders, bool $includeSpam, bool $includeTrash): int {
		$sel = $this->run->selection();
		$sel['folders'] = array_values($folders);
		$sel['include_spam'] = $includeSpam;
		$sel['include_trash'] = $includeTrash;
		$this->run->writeColumns(array('mir_selection' => json_encode($sel)));

		$skipped = MailImportEntry::applySelection(intval($this->run->key), $folders, $includeSpam, $includeTrash);
		if ($skipped > 0) {
			MailImportRun::addCounts(intval($this->run->key),
				array('mir_skipped' => $skipped, 'mir_processed' => $skipped));
		}
		$this->run->moveTo(MailImportRun::STATE_IMPORTING);
		return $skipped;
	}

	// ------------------------------------------------------------------ import

	/**
	 * Store the next batch of pending entries. Returns the batch's counters.
	 *
	 * A failure is recorded on its entry and never aborts the batch — one
	 * unreadable message must not cost the other thirty-five thousand.
	 */
	public function importBatch(int $limit): array {
		$this->open();
		list($alias, $domain) = $this->target();

		$entries = new MultiMailImportEntry(
			array('run_id' => intval($this->run->key), 'state' => MailImportEntry::STATE_PENDING),
			array('mie_ordinal' => 'ASC'),
			$limit
		);
		$entries->load();

		$counts = array('seen' => 0, 'stored' => 0, 'dedup' => 0, 'failed' => 0, 'skipped' => 0);
		$detail = array();

		foreach ($entries as $entry) {
			$counts['seen']++;
			try {
				$outcome = $this->importEntry($entry, $alias, $domain);
				$counts[$outcome]++;
			} catch (Throwable $e) {
				$counts['failed']++;
				$reason = $e->getMessage();
				$detail[] = array('locator' => (string)$entry->get('mie_locator'),
					'folder' => (string)$entry->get('mie_source_folder'), 'reason' => $reason);
				MailImportEntry::recordOutcome(intval($entry->key), MailImportEntry::STATE_FAILED, $reason);
			}
		}

		MailImportRun::addCounts(intval($this->run->key), array(
			'mir_processed' => $counts['seen'],
			'mir_stored'    => $counts['stored'],
			'mir_dedup'     => $counts['dedup'],
			'mir_failed'    => $counts['failed'],
		));
		// addCounts advances the row directly, so this copy is now behind it.
		// Re-read, or anything reporting on the run — the task's own summary
		// included — describes it as it was before this batch.
		$this->run->load();

		if ($counts['seen'] > 0) {
			$this->record($counts, $detail);
		}

		$counts['exhausted'] = ($counts['seen'] < $limit);
		return $counts;
	}

	/**
	 * One message, end to end. Returns the bucket it landed in: stored, dedup or
	 * skipped.
	 */
	private function importEntry(MailImportEntry $entry, $alias, $domain): string {
		$raw = $this->reader->read($this->path, (string)$entry->get('mie_locator'));
		if (trim($raw) === '') {
			throw new RuntimeException('The archive holds no content at this position.');
		}

		$headers = MailArchiveReader::parseHeaders(MailArchiveReader::headerBlock($raw));
		$messageId = MailArchiveReader::messageId($headers, $raw);

		// A message with no Message-ID gets a stable synthetic one written INTO the
		// stored copy, so the row and its raw agree and threading still works. It is
		// derived from the bytes, so the same message scanned again produces the
		// same id and still dedups.
		if (MailArchiveReader::header($headers, 'message-id') === '') {
			$raw = 'Message-ID: ' . $messageId . "\r\n" . $raw;
			$headers['message-id'] = $messageId;
		}

		$direction = (string)$entry->get('mie_direction') ?: 'inbound';
		$own = $this->run->ownAddresses();
		$recipient = MailImportIdentity::deliveryAddress($headers, $own,
			strtolower((string)$alias->get_full_address()), $direction);

		// Already here? The unique constraint would catch a true repeat, but its key
		// includes the recipient, and a composed row's recipient is sealed content on
		// a protected mailbox — so it cannot be matched on a second run. Asking
		// whether this mailbox already holds this message is both stronger and
		// always answerable.
		$existing = self::existingMessageId($messageId, intval($alias->key), $direction);
		if ($existing !== null) {
			MailImportEntry::recordOutcome(intval($entry->key), MailImportEntry::STATE_DEDUP,
				'Already in this mailbox.', $existing);
			return 'dedup';
		}

		$parsed = $this->router()->parseEmail($raw);
		$parsed['headers']['message-id'] = $messageId;

		$class = (string)$entry->get('mie_class');
		$folder = (string)$entry->get('mie_source_folder');

		$result = $this->router()->storeMessage($raw, $parsed, $alias, $domain, $recipient,
			self::IMPORT_AUTH,
			// Spam is the source's own verdict, fed through the platform's classifier
			// rather than written onto the row, so the deployment's spam setting still
			// governs whether a verdict is recorded at all.
			array('signal' => $class === MailImportEntry::CLASS_SPAM ? 'spam' : 'none', 'score' => null),
			array(
				'run_filters'   => false,
				'import_run_id' => intval($this->run->key),
				'direction'     => $direction,
				'received_time' => MailImportIdentity::receivedTime($headers),
				'is_read'       => (bool)$entry->get('mie_is_read'),
				'is_starred'    => (bool)$entry->get('mie_is_starred'),
				'is_archived'   => self::isArchivedFolder($folder),
			)
		);

		if (!empty($result['dedup']) || empty($result['message'])) {
			// The store's own dedup, which is mailbox-agnostic: the platform holds a
			// message once. Reached when the same message was already brought in under
			// another address or into another mailbox.
			MailImportEntry::recordOutcome(intval($entry->key), MailImportEntry::STATE_DEDUP,
				'Already stored on this site.');
			return 'dedup';
		}

		$messageRowId = intval($result['message']->key);
		$this->applyLabels($messageRowId, $entry, $folder);

		// The platform models Trash as a soft delete, so a message the source had in
		// the bin arrives in the bin. A targeted update — storeMessage seals content
		// behind the model's back and a full save() here would blank it.
		if ($class === MailImportEntry::CLASS_TRASH) {
			InboundEmailMessage::updateColumns($messageRowId, array('iem_delete_time' => gmdate('Y-m-d H:i:s')));
		}

		MailImportEntry::recordOutcome(intval($entry->key), MailImportEntry::STATE_STORED, null, $messageRowId);
		return 'stored';
	}

	/**
	 * Reproduce the source's filing. A folder becomes a label of the same name, so
	 * "Receipts" in the old account is "Receipts" here; the standard buckets are
	 * columns on the message and are handled by the store, not by a label.
	 */
	private function applyLabels(int $messageId, MailImportEntry $entry, string $folder): void {
		$names = $entry->labels();
		if ($folder !== '' && !self::isStandardFolder($folder)) {
			$names[] = $folder;
		}
		foreach (array_unique($names) as $name) {
			if (trim($name) === '' || self::isStandardFolder($name)) {
				continue;
			}
			try {
				$label = InboundEmailLabel::findOrCreate($name);
				if ($label !== null) {
					InboundLabelMember::apply($messageId, intval($label->key));
				}
			} catch (Throwable $e) {
				// A label is filing, not content. Losing one must not cost the message.
				error_log('MailArchiveImporter: could not apply label ' . $name
					. ' to message ' . $messageId . ' — ' . $e->getMessage());
			}
		}
	}

	/** Buckets the platform already models as columns, so never labels. */
	public static function isStandardFolder(string $name): bool {
		return in_array(strtolower(trim($name)), array('inbox', 'sent', 'sent mail', 'sent items',
			'spam', 'junk', 'trash', 'deleted items', 'drafts', 'starred', 'all mail',
			'archived', 'archive', 'imported'), true);
	}

	/** A folder that means "not in the inbox" — the source had it filed away. */
	public static function isArchivedFolder(string $name): bool {
		return in_array(strtolower(trim($name)), array('all mail', 'archived', 'archive'), true);
	}

	/**
	 * The id of a message this mailbox already holds with this message id and
	 * direction, or null. Deleted rows count: re-importing must not resurrect mail
	 * the user has since thrown away.
	 */
	public static function existingMessageId(string $messageIdHeader, int $aliasId, string $direction): ?int {
		if ($messageIdHeader === '') {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			WHERE iem_message_id_header = ? AND iem_iea_inbound_email_alias_id = ? AND iem_direction = ?
			LIMIT 1');
		$stmt->execute(array(substr($messageIdHeader, 0, 255), $aliasId, $direction));
		$id = $stmt->fetchColumn();
		return $id === false ? null : intval($id);
	}

	// -------------------------------------------------------------- run record

	/**
	 * One evl_event_logs row per batch that did something, with failures rolled up
	 * by reason. The reconciliation tripwire applies: everything seen must land in
	 * exactly one bucket, and a shortfall marks the batch unsuccessful and names
	 * itself, because a message that vanishes without a reason is the failure a
	 * counter alone hides.
	 */
	private function record(array $counts, array $detail): void {
		$counts['failed_detail'] = $detail;
		$subject = 'run ' . $this->run->key . ' (' . ($this->run->get('mir_source_name') ?: 'archive') . ')';
		$summary = MailRunRecord::summarize($counts, $subject, MailRunRecord::DIMENSIONS_IMPORT);

		MailRunRecord::write(self::RUN_EVENT, $summary, $detail,
			function (array $f): string {
				return 'failed at ' . $f['locator'] . ' in ' . ($f['folder'] !== '' ? $f['folder'] : 'the archive')
					. ' — ' . $f['reason'];
			},
			intval($this->run->get('mir_usr_user_id')));
	}

	// --------------------------------------------------------------- finishing

	/** Everything imported, or nothing left that can be. */
	public function finish(): void {
		$this->cleanup();
		$this->run->moveTo(MailImportRun::STATE_DONE);
	}

	public function fail(string $reason): void {
		$this->cleanup();
		$this->run->moveTo(MailImportRun::STATE_FAILED, substr($reason, 0, 4000));
	}

	/**
	 * Discard the run's source archive, reclaiming its bytes.
	 *
	 * Only ever deletes a file the IMPORTER created. A Drive-picked archive belongs
	 * to the user — they put it there, it counts against their quota, and they may
	 * well want it after the import — so it is detached from the run and left
	 * entirely alone. The origin tag is what tells the two apart, which is the
	 * reason uploads are tagged in the first place.
	 *
	 * Refuses while the run is still live: the archive is the only copy of what is
	 * being imported, and deleting it mid-run would strand the run with no way to
	 * finish and no way to retry.
	 *
	 * Returns ['ok'=>bool, 'freed'=>int bytes, 'message'=>string].
	 */
	public function discardArchive(): array {
		if (!$this->run->isFinished()) {
			return array('ok' => false, 'freed' => 0,
				'message' => 'The archive is still being imported.');
		}

		$file_id = intval($this->run->get('mir_fil_file_id'));
		if ($file_id <= 0) {
			$this->cleanup();
			return array('ok' => true, 'freed' => 0, 'message' => 'The archive was already discarded.');
		}

		$freed = 0;
		try {
			$file = new File($file_id, TRUE);
			if ($file->key) {
				$source = (string)$file->get('fil_source');
				if ($source !== File::SOURCE_MAIL_IMPORT_ARCHIVE) {
					// Somebody's own file, picked from their Drive. Let go of it
					// without touching it.
					$this->run->writeColumns(array('mir_fil_file_id' => null));
					$this->cleanup();
					return array('ok' => true, 'freed' => 0,
						'message' => 'That archive is your own file, so it was left where it is.');
				}
				$freed = $file->size_bytes();
				$file->permanent_delete();
			}
		} catch (Throwable $e) {
			return array('ok' => false, 'freed' => 0,
				'message' => 'The archive could not be removed: ' . $e->getMessage());
		}

		// Drop the reference before the working area, so a failure half way through
		// never leaves the run pointing at bytes that are gone.
		$this->run->writeColumns(array('mir_fil_file_id' => null));
		$this->cleanup();

		return array('ok' => true, 'freed' => $freed,
			'message' => 'Archive discarded' . ($freed > 0 ? ', freeing ' . self::formatBytes($freed) : '') . '.');
	}

	/** Bytes as something a person reads without counting digits. */
	public static function formatBytes(int $bytes): string {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$i = 0;
		$n = max(0, $bytes);
		while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
		return ($i === 0 ? (int)$n : round($n, 1)) . ' ' . $units[$i];
	}

	/** Remove the working area. The source file itself is not ours to delete. */
	public function cleanup(): void {
		$dir = (string)$this->run->get('mir_work_dir');
		if ($dir === '' || !is_dir($dir) || strpos($dir, 'joinery-mail-import-') === false) {
			return;
		}
		try {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($items as $item) {
				$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
			}
			@rmdir($dir);
		} catch (Throwable $e) {
			error_log('MailArchiveImporter: could not clear the working area ' . $dir . ' — ' . $e->getMessage());
		}
	}

	// -------------------------------------------------------------------- undo

	/**
	 * Reverse the run: permanently delete every message carrying its id, and only
	 * those. Mail that deduped against something already present was never tagged,
	 * so it is untouched — as is anything that arrived afterwards.
	 *
	 * Deleting goes through the message model's own permanent delete, so attachment
	 * Files, manifest rows, label memberships and stored raw objects all go with
	 * it rather than being orphaned.
	 *
	 * The run keeps its entries and moves to `undone`, so the report of what
	 * happened survives the reversal.
	 */
	public function undo(): array {
		$runId = intval($this->run->key);
		$db = DbConnector::get_instance()->get_db_link();

		$stmt = $db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			WHERE iem_mir_mail_import_run_id = ?');
		$stmt->execute(array($runId));
		$ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

		// Which labels this run touched, captured BEFORE the memberships go, so the
		// empty ones can be tidied afterwards.
		$labelStmt = $db->prepare('SELECT DISTINCT ilm_ilb_inbound_email_label_id
			FROM ilm_inbound_label_members
			WHERE ilm_iem_inbound_email_message_id IN (
				SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				WHERE iem_mir_mail_import_run_id = ?)');
		$labelStmt->execute(array($runId));
		$labelIds = array_map('intval', $labelStmt->fetchAll(PDO::FETCH_COLUMN, 0));

		// Memberships only find the labels that stuck. A label created for a message
		// that then failed to store, or whose membership could not be written, holds
		// nothing and would otherwise survive undo forever as an empty folder.
		foreach ($this->labelNamesNamedBy($runId) as $name) {
			$label = InboundEmailLabel::getByName($name);
			if ($label !== null && $label->key) {
				$labelIds[] = intval($label->key);
			}
		}
		$labelIds = array_values(array_unique($labelIds));

		$removed = 0; $failed = 0;
		foreach ($ids as $id) {
			try {
				$message = new InboundEmailMessage(intval($id), TRUE);
				if ($message->key) {
					$message->permanent_delete();
					$removed++;
				}
			} catch (Throwable $e) {
				$failed++;
				error_log('MailArchiveImporter: undo could not remove message ' . $id . ' — ' . $e->getMessage());
			}
		}

		$labelsRemoved = $this->removeEmptyLabels($labelIds);

		$this->cleanup();
		$this->run->moveTo(MailImportRun::STATE_UNDONE);

		MailRunRecord::write(self::RUN_EVENT . '_undo', array(
			'note'    => 'run ' . $runId . ': removed ' . $removed . ' message(s), '
				. $labelsRemoved . ' now-empty label(s); ' . $failed . ' could not be removed.',
			'success' => ($failed === 0),
		), array(), null, intval($this->run->get('mir_usr_user_id')));

		return array('removed' => $removed, 'failed' => $failed, 'labels_removed' => $labelsRemoved);
	}

	/**
	 * Every label name this run's entries would have produced — their own labels,
	 * plus any source folder that is not one of the standard buckets. Read from the
	 * entries rather than from what was written, so a label whose message never
	 * made it is still accounted for.
	 *
	 * @return string[]
	 */
	private function labelNamesNamedBy(int $runId): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT DISTINCT mie_labels, mie_source_folder
			FROM mie_mail_import_entries WHERE mie_mir_mail_import_run_id = ?');
		$stmt->execute(array($runId));

		$names = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$candidates = preg_split('/\r\n|\r|\n/', (string)$row['mie_labels']) ?: array();
			$candidates[] = (string)$row['mie_source_folder'];
			foreach ($candidates as $name) {
				$name = trim($name);
				if ($name !== '' && !self::isStandardFolder($name)) {
					$names[$name] = true;
				}
			}
		}
		return array_keys($names);
	}

	/**
	 * Drop labels the import created that now hold nothing. A label that existed
	 * beforehand, or that still holds mail from elsewhere, is left alone — undo
	 * reverses the import, not the user's filing.
	 */
	private function removeEmptyLabels(array $labelIds): int {
		$removed = 0;
		$db = DbConnector::get_instance()->get_db_link();
		foreach ($labelIds as $labelId) {
			if ($labelId <= 0) {
				continue;
			}
			$stmt = $db->prepare('SELECT 1 FROM ilm_inbound_label_members
				WHERE ilm_ilb_inbound_email_label_id = ? LIMIT 1');
			$stmt->execute(array($labelId));
			if ($stmt->fetchColumn() !== false) {
				continue; // still in use
			}
			try {
				$label = new InboundEmailLabel($labelId, TRUE);
				if ($label->key) {
					$label->softDelete();
					$removed++;
				}
			} catch (Throwable $e) {
				error_log('MailArchiveImporter: could not remove empty label ' . $labelId . ' — ' . $e->getMessage());
			}
		}
		return $removed;
	}
}
?>
