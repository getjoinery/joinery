<?php
/**
 * MailImportService - everything the member page and the admin page both need.
 *
 * The two surfaces differ only in which mailboxes they offer. A member imports
 * into a mailbox they hold a grant on; an operator can import into any of them,
 * because setting a mailbox up for somebody else is operator work. Every other
 * decision — who may see a run, which files can be picked, what the progress
 * looks like — is identical, and lives here so the two pages cannot drift apart.
 *
 * Permission is decided here rather than in a view: a view that forgets a check
 * shows too much, whereas a service that owns the check cannot be bypassed by
 * calling the API directly.
 *
 * See specs/mail_archive_import.md § 10.
 *
 * @version 1.2
 * @changelog 1.2 - describe() carries an `attention` block for a finished run:
 *   the reconciliation tripwire and the duplicates whose recorded reason says
 *   they may not be in this mailbox (specs/mail_import_loss_proof.md).
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mail_import_run_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mail_import_entry_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReaderRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveImporter.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class MailImportService {

	/** Operators can import into any mailbox; members only into their own. */
	const OPERATOR_PERMISSION = 5;

	/** Runs shown in the member's history. Older ones stay in the table. */
	const HISTORY_LIMIT = 25;

	/** @var MailboxViewer */
	private $viewer;

	public function __construct(MailboxViewer $viewer) {
		$this->viewer = $viewer;
	}

	public static function fromSession(SessionControl $session): MailImportService {
		return new self(MailboxViewer::fromSession($session));
	}

	public function isOperator(): bool {
		return $this->viewer->getPermission() >= self::OPERATOR_PERMISSION;
	}

	/** May this caller import into, and see runs for, this mailbox? */
	public function canTarget(int $aliasId): bool {
		if ($aliasId <= 0) {
			return false;
		}
		return $this->isOperator() || $this->viewer->canAccess($aliasId);
	}

	/**
	 * The mailboxes this caller may import into, as id => address. An operator
	 * gets every live mailbox; everyone else gets the ones they hold a grant on.
	 *
	 * @return array<int,string>
	 */
	public function targetableAliases(): array {
		$aliases = $this->isOperator()
			? new MultiInboundEmailAlias(array('deleted' => false), array('iea_alias' => 'ASC'))
			: null;

		$out = array();
		if ($aliases !== null) {
			$aliases->load();
			foreach ($aliases as $alias) {
				$out[intval($alias->key)] = (string)$alias->get_full_address();
			}
			return $out;
		}

		foreach ($this->viewer->accessibleAliasIds() as $id) {
			try {
				$alias = new InboundEmailAlias(intval($id), TRUE);
				if ($alias->key) {
					$out[intval($alias->key)] = (string)$alias->get_full_address();
				}
			} catch (Throwable $e) {
				continue;
			}
		}
		asort($out);
		return $out;
	}

	/**
	 * Files in the caller's Drive that could be an archive, plus archives they
	 * already uploaded here.
	 *
	 * Scoped by origin, not just by owner: a user's account holds files from every
	 * subsystem — chat uploads, avatars, a sealed search index — and none of those
	 * are things anyone means when they say "pick my archive". Prior import
	 * archives are included so an interrupted run can be restarted against the same
	 * file instead of re-uploading gigabytes.
	 *
	 * Encrypted files are LISTED, not hidden, and carry the reason they cannot be
	 * used. A file in an encrypted folder is decryptable only in the browser, so
	 * the server genuinely cannot read it — and a user who cannot find their
	 * archive is worse off than one who is told why it is unavailable.
	 *
	 * @return array<int,array{id:int,name:string,size:int,encrypted:bool,reason:string}>
	 */
	public function pickableFiles(): array {
		$userId = $this->viewer->getUserId();
		if ($userId <= 0) {
			return array();
		}
		$files = new MultiFile(array(
			'user_id' => $userId,
			'deleted' => false,
			'sources' => array(File::SOURCE_DRIVE, File::SOURCE_MAIL_IMPORT_ARCHIVE),
		), array('fil_create_time' => 'DESC'), 500);
		$files->load();

		$extensions = MailArchiveReaderRegistry::acceptedExtensions();
		$out = array();
		foreach ($files as $file) {
			$name = (string)($file->get('fil_title') ?: $file->get('fil_name'));
			$lower = strtolower($name);
			$matches = false;
			foreach ($extensions as $ext) {
				if (substr($lower, -strlen($ext)) === $ext) {
					$matches = true;
					break;
				}
			}
			if (!$matches) {
				continue;
			}
			// Either kind of protected file is unreadable to an import: Fortress
			// because only the browser holds the key, Private because the job runs
			// with no unlock window. Both are listed with the reason rather than
			// hidden, so the picker explains itself.
			$encrypted = $file->is_encrypted();
			$sealed    = $file->is_sealed();
			$reason = $encrypted ? MailArchiveImporter::ENCRYPTED_FILE_REASON
				: ($sealed ? MailArchiveImporter::SEALED_FILE_REASON : '');
			$out[] = array(
				'id'        => intval($file->key),
				'name'      => $name,
				'size'      => $file->plain_size_bytes(),
				'encrypted' => ($encrypted || $sealed),
				'reason'    => $reason,
			);
		}
		return $out;
	}

	/**
	 * Persist an uploaded archive as a private file owned by the caller.
	 *
	 * The upload becomes the user's own file the moment it lands, which is what
	 * makes "upload one now" and "pick one I already have" the same path from here
	 * on — and means an interrupted import resumes against the same file rather
	 * than needing a re-upload.
	 *
	 * @param array $upload one entry of $_FILES
	 */
	public function storeUpload(array $upload): File {
		$error = intval($upload['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($error !== UPLOAD_ERR_OK) {
			throw new RuntimeException(self::uploadErrorMessage($error, $upload));
		}

		$file = File::createFromUpload(
			(string)$upload['tmp_name'],
			basename((string)($upload['name'] ?? 'archive')),
			(string)($upload['type'] ?? 'application/octet-stream'),
			$this->viewer->getUserId(),
			array(
				// Private: an archive of somebody's entire mail history must never be
				// reachable by URL.
				'fil_private' => true,
				// Tagged as an import archive rather than left unspecified, and
				// deliberately NOT as a Drive item: it is working material for one run,
				// so it should not turn up in the member's Drive listing or count
				// against their quota.
				'fil_source'  => File::SOURCE_MAIL_IMPORT_ARCHIVE,
			)
		);
		if (!$file || !$file->key) {
			throw new RuntimeException('The archive could not be saved on the server.');
		}
		return $file;
	}

	/**
	 * Is the scheduled task that actually performs imports switched on?
	 *
	 * Nothing here runs without it: a run is queued by the web request and moved
	 * forward only by RunMailImports. A task discovered but never activated leaves
	 * every import sitting at "Waiting to start" indefinitely, which looks exactly
	 * like a broken feature and gives the user nothing to act on.
	 *
	 * Returns null when it is running, or the reason it is not.
	 */
	public static function schedulerWarning(): ?string {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->prepare("SELECT sct_is_active FROM sct_scheduled_tasks
				WHERE sct_task_class = 'RunMailImports' AND sct_delete_time IS NULL LIMIT 1");
			$stmt->execute();
			$active = $stmt->fetchColumn();

			if ($active === false) {
				return 'Imports are not running: the Import mail archives task has never been '
					. 'activated. An administrator can switch it on under Scheduled Tasks. '
					. 'Anything started now waits until then — nothing is lost.';
			}
			if (!$active || $active === 'f') {
				return 'Imports are paused: the Import mail archives task is switched off. '
					. 'An administrator can re-enable it under Scheduled Tasks. Anything '
					. 'started now waits until then — nothing is lost.';
			}
		} catch (Throwable $e) {
			// Not being able to answer is not evidence of a problem; say nothing
			// rather than warn about a fault of our own.
			return null;
		}
		return null;
	}

	/**
	 * What went wrong with an upload, in terms the person can act on.
	 *
	 * The size cases are the ones that matter here, because a real mail archive is
	 * routinely larger than a single web request can carry. That is not a limit of
	 * the importer — it will happily grind through fifty gigabytes — so the message
	 * points at the route that has no such ceiling rather than implying the archive
	 * is unusable.
	 */
	private static function uploadErrorMessage(int $error, array $upload): string {
		require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mail_import_start_logic.php'));

		switch ($error) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				// The file was rejected before it landed, so its own size is reported
				// as zero; the request length is the closest honest figure we have.
				$attempted = intval($upload['size'] ?? 0) ?: intval($_SERVER['CONTENT_LENGTH'] ?? 0);
				return mail_import_too_large_message($attempted);

			case UPLOAD_ERR_PARTIAL:
				return 'The upload was cut off before it finished. Try again, or add the file to '
					. 'your Drive first — Drive resumes where it left off instead of starting over.';

			case UPLOAD_ERR_NO_FILE:
				return 'No file was received. Choose an archive and try again.';

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return 'The server could not save the upload. This is a server problem, not '
					. 'something wrong with your archive — please report it.';

			case UPLOAD_ERR_EXTENSION:
				return 'The upload was blocked by a server extension. Please report it.';
		}
		return 'The upload did not complete. Add the file to your Drive and pick it from there instead.';
	}

	/**
	 * Start a run. It does no work here — it is queued, and the scheduled task
	 * picks it up. That is deliberate: a 50GB archive cannot be scanned inside the
	 * request that asked for it, and a user who closes the tab must not lose the
	 * import.
	 *
	 * @param string[] $ownAddresses the addresses the user says were theirs
	 */
	public function startRun(int $aliasId, int $fileId, array $ownAddresses, string $sourceName = ''): MailImportRun {
		if (!$this->canTarget($aliasId)) {
			throw new RuntimeException('You do not have access to that mailbox.');
		}

		$file = new File($fileId, TRUE);
		if (!$file->key) {
			throw new RuntimeException('That file could not be found.');
		}
		// A Drive file the caller does not own is not theirs to import; an operator
		// still cannot reach into someone else's Drive, only into any mailbox.
		if (intval($file->get('fil_usr_user_id')) !== $this->viewer->getUserId() && !$this->isOperator()) {
			throw new RuntimeException('That file is not yours.');
		}
		if ($file->is_encrypted()) {
			throw new RuntimeException(MailArchiveImporter::ENCRYPTED_FILE_REASON);
		}
		if ($file->is_sealed()) {
			throw new RuntimeException(MailArchiveImporter::SEALED_FILE_REASON);
		}

		$name = $sourceName !== '' ? $sourceName
			: (string)($file->get('fil_title') ?: $file->get('fil_name'));

		// Detect the format now, while the user is here to be told about it. A .pst
		// is refused with the IMAP redirect rather than queueing a run that would
		// only fail on the next cron pass.
		$path = $file->get_filesystem_path();
		$reader = is_file($path) ? MailArchiveReaderRegistry::detect($path, $name) : null;
		if ($reader === null) {
			throw new RuntimeException('That file is not a mail archive this platform can read. Supported: '
				. implode(', ', MailArchiveReaderRegistry::acceptedExtensions()) . '.');
		}
		$refusal = $reader->refusal();
		if ($refusal !== null) {
			throw new RuntimeException($refusal);
		}

		$run = new MailImportRun(NULL);
		$run->set('mir_iea_inbound_email_alias_id', $aliasId);
		$run->set('mir_usr_user_id', $this->viewer->getUserId());
		$run->set('mir_fil_file_id', intval($file->key));
		$run->set('mir_source_name', substr($name, 0, 500));
		$run->set('mir_format', $reader::key());
		$run->set('mir_state', MailImportRun::STATE_QUEUED);
		$run->set('mir_own_addresses', implode("\n", MailImportRun::parseAddressList(implode("\n", $ownAddresses))));
		$run->set('mir_bytes_total', $file->size_bytes());
		$run->prepare();
		$run->save();
		$run->load();
		return $run;
	}

	/** One run the caller is allowed to see, or null. */
	public function loadRun(int $runId): ?MailImportRun {
		if ($runId <= 0) {
			return null;
		}
		$run = new MailImportRun($runId, TRUE);
		if (!$run->key) {
			return null;
		}
		if (!$this->canTarget(intval($run->get('mir_iea_inbound_email_alias_id')))) {
			return null;
		}
		return $run;
	}

	/**
	 * The caller's run history, newest first. An operator sees every run for the
	 * mailbox; a member sees runs for the mailboxes they hold.
	 *
	 * @return array<int,array>
	 */
	public function history(?int $aliasId = null): array {
		$filters = array('deleted' => false);
		if ($aliasId !== null && $aliasId > 0) {
			if (!$this->canTarget($aliasId)) {
				return array();
			}
			$filters['alias_id'] = $aliasId;
		} elseif (!$this->isOperator()) {
			$ids = $this->viewer->accessibleAliasIds();
			if (!$ids) {
				return array();
			}
			$filters['alias_ids'] = $ids;
		}

		$runs = new MultiMailImportRun($filters,
			array('mir_mail_import_run_id' => 'DESC'), self::HISTORY_LIMIT);
		$runs->load();

		$out = array();
		foreach ($runs as $run) {
			$out[] = self::describe($run);
		}
		return $out;
	}

	/**
	 * One run as the page shows it. Deliberately free of anything that needs the
	 * archive to be readable, so a run whose source has gone still reports.
	 */
	public static function describe(MailImportRun $run): array {
		$state = (string)$run->get('mir_state');
		return array(
			'id'          => intval($run->key),
			'alias_id'    => intval($run->get('mir_iea_inbound_email_alias_id')),
			'source'      => (string)$run->get('mir_source_name'),
			'format'      => (string)$run->get('mir_format'),
			'state'       => $state,
			'state_label' => self::stateLabel($state),
			'total'       => intval($run->get('mir_total_entries')),
			'processed'   => intval($run->get('mir_processed')),
			'stored'      => intval($run->get('mir_stored')),
			'dedup'       => intval($run->get('mir_dedup')),
			'skipped'     => intval($run->get('mir_skipped')),
			'failed'      => intval($run->get('mir_failed')),
			'percent'     => $run->percent(),
			'error'       => (string)$run->get('mir_error'),
			'created'     => (string)$run->get('mir_create_time'),
			'finished'    => (string)$run->get('mir_finish_time'),
			'can_undo'    => ($state === MailImportRun::STATE_DONE && intval($run->get('mir_stored')) > 0),
			'can_choose'  => ($state === MailImportRun::STATE_SCANNED),
			// Only offered when the run is over AND still holds an archive — there
			// is nothing to reclaim once it has been discarded or swept.
			'can_discard' => ($run->isFinished() && intval($run->get('mir_fil_file_id')) > 0),
			// The numbers that mean "look here" (specs/mail_import_loss_proof.md).
			// Only for a finished run: mid-run they are just work not done yet.
			'attention'   => $run->isFinished() ? self::attention($run) : array(),
		);
	}

	/**
	 * What on this run wants a human's eye.
	 *
	 * Two things qualify. UNACCOUNTED is the reconciliation tripwire: every entry
	 * should have landed in exactly one bucket, so a shortfall means messages went
	 * missing with nothing reporting it. FLAGGED counts the duplicates whose own
	 * recorded reason says they may not be in this mailbox at all — a copy that
	 * collided with another mailbox's, one that could not be identified, or one
	 * whose stored copy lists no attachments.
	 *
	 * Counts only. The detail belongs to reconcile_mail_import.php, which can name
	 * every message; a table cell that tried would be unreadable.
	 */
	private static function attention(MailImportRun $run): array {
		$out = array();

		$processed = intval($run->get('mir_processed'));
		$accounted = intval($run->get('mir_stored')) + intval($run->get('mir_dedup'))
			+ intval($run->get('mir_skipped')) + intval($run->get('mir_failed'));
		if ($processed !== $accounted) {
			$out['unaccounted'] = $processed - $accounted;
		}

		$flagged = 0;
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$clauses = array();
			$params = array(intval($run->key));
			foreach (MailImportEntry::SUSPICIOUS_REASONS as $prefix) {
				$clauses[] = 'mie_reason LIKE ?';
				$params[] = $prefix . '%';
			}
			$stmt = $db->prepare('SELECT COUNT(*) FROM mie_mail_import_entries
				WHERE mie_mir_mail_import_run_id = ? AND (' . implode(' OR ', $clauses) . ')');
			$stmt->execute($params);
			$flagged = intval($stmt->fetchColumn());
		} catch (\Throwable $e) {
			// A panel that cannot count is not a reason to fail the page.
			error_log('MailImportService::attention: ' . $e->getMessage());
		}
		if ($flagged > 0) {
			$out['flagged'] = $flagged;
		}

		return $out;
	}

	/** What each state means, in words the user asked their question in. */
	public static function stateLabel(string $state): string {
		$labels = array(
			MailImportRun::STATE_QUEUED    => 'Waiting to start',
			MailImportRun::STATE_SCANNING  => 'Reading the archive',
			MailImportRun::STATE_SCANNED   => 'Ready — choose what to bring',
			MailImportRun::STATE_IMPORTING => 'Importing',
			MailImportRun::STATE_DONE      => 'Finished',
			MailImportRun::STATE_FAILED    => 'Failed',
			MailImportRun::STATE_UNDONE    => 'Reversed',
		);
		return $labels[$state] ?? $state;
	}

	/**
	 * The caller's import that is still going, or null when they can start one.
	 *
	 * One import at a time, per person. An archive import is the heaviest thing a
	 * member can ask this platform to do, and two of them at once means two sets of
	 * progress numbers, two folder questions, and no way to tell which answer
	 * belongs to which — while the second one waits behind the first anyway.
	 *
	 * Scoped to runs this person started, not to the mailbox: an operator setting
	 * up somebody else's mailbox is still the person doing the importing, and two
	 * grantees of a shared mailbox are two people.
	 *
	 * `scanned` counts as still going. The run has stopped to ask a question and
	 * resumes on the answer, so it holds the slot until it is answered.
	 */
	public function activeRun(): ?array {
		$userId = $this->viewer->getUserId();
		if ($userId <= 0) {
			return null;
		}
		$runs = new MultiMailImportRun(array(
			'user_id' => $userId,
			'states'  => MailImportRun::UNFINISHED_STATES,
			'deleted' => false,
		), array('mir_mail_import_run_id' => 'DESC'), 1);
		$runs->load();
		foreach ($runs as $run) {
			return self::describe($run);
		}
		return null;
	}

	/**
	 * What this person chose the last time they imported.
	 *
	 * Importing is rarely one archive: a Gmail Takeout arrives split across several
	 * files, and a provider migration means the same mailbox and the same list of
	 * addresses over and over. Re-typing them on every visit is the sort of small
	 * repeated cost that makes a job feel long, so the last run's answers come back
	 * as the starting point.
	 *
	 * The mailbox comes back only if the caller can still import into it — a grant
	 * withdrawn since is not silently re-offered.
	 *
	 * @return array{alias_id:int,own_addresses:string}|null
	 */
	public function lastChoices(): ?array {
		$userId = $this->viewer->getUserId();
		if ($userId <= 0) {
			return null;
		}
		$runs = new MultiMailImportRun(array(
			'user_id' => $userId,
			'deleted' => false,
		), array('mir_mail_import_run_id' => 'DESC'), 1);
		$runs->load();
		foreach ($runs as $run) {
			$aliasId = intval($run->get('mir_iea_inbound_email_alias_id'));
			return array(
				'alias_id'      => $this->canTarget($aliasId) ? $aliasId : 0,
				'own_addresses' => trim((string)$run->get('mir_own_addresses')),
			);
		}
		return null;
	}

	/**
	 * The addresses to pre-fill the identity step with: the mailbox's own address
	 * plus whatever the account already knows about. The user edits from there,
	 * because only they know which addresses were theirs at the old provider.
	 *
	 * @return string[]
	 */
	public function suggestedAddresses(int $aliasId): array {
		$out = array();

		try {
			$alias = new InboundEmailAlias($aliasId, TRUE);
			if ($alias->key) {
				$out[] = strtolower((string)$alias->get_full_address());
			}
		} catch (Throwable $e) {
			// A missing mailbox is the caller's problem to report, not ours.
		}

		$userId = $this->viewer->getUserId();
		if ($userId > 0) {
			try {
				$user = new User($userId, TRUE);
				$email = strtolower(trim((string)$user->get('usr_email')));
				if ($email !== '') {
					$out[] = $email;
				}
			} catch (Throwable $e) {
				// Same.
			}
		}

		return array_values(array_unique(array_filter($out, 'strlen')));
	}
}
?>
