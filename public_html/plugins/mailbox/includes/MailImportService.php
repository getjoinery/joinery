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
 * @version 1.0
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
	 * Files in the caller's Drive that could be an archive.
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
		$files = new MultiFile(array('user_id' => $userId, 'deleted' => false),
			array('fil_create_time' => 'DESC'), 500);
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
			$encrypted = (bool)$file->get('fil_encrypted');
			$out[] = array(
				'id'        => intval($file->key),
				'name'      => $name,
				'size'      => intval($file->get('fil_size')),
				'encrypted' => $encrypted,
				'reason'    => $encrypted ? MailArchiveImporter::ENCRYPTED_FILE_REASON : '',
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
		if (intval($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new RuntimeException('The upload did not complete. A very large archive may exceed '
				. 'this server\'s upload limit — if so, add it to your files first and pick it from there.');
		}

		$file = File::createFromUpload(
			(string)$upload['tmp_name'],
			basename((string)($upload['name'] ?? 'archive')),
			(string)($upload['type'] ?? 'application/octet-stream'),
			$this->viewer->getUserId(),
			// Private: an archive of somebody's entire mail history must never be
			// reachable by URL.
			array('fil_private' => true)
		);
		if (!$file || !$file->key) {
			throw new RuntimeException('The archive could not be saved on the server.');
		}
		return $file;
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
		if ($file->get('fil_encrypted')) {
			throw new RuntimeException(MailArchiveImporter::ENCRYPTED_FILE_REASON);
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
		$run->set('mir_bytes_total', intval($file->get('fil_size')));
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
		);
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
