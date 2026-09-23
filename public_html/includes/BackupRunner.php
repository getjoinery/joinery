<?php
/**
 * BackupRunner — this site backing itself up.
 *
 * No agent, no SSH, no management node. A site with a configured target and a
 * proven recovery key produces an encrypted archive, uploads it, records what
 * happened, and deletes what it no longer needs to keep. server_manager drives
 * OTHER machines; it is not involved in a site backing up itself, and a site
 * running server_manager backs itself up through exactly this path — no site's
 * recovery may depend on a management node being alive, including the
 * management node's own.
 *
 * The order of operations is the design:
 *
 *   1. record the run as 'running' BEFORE doing anything, so a run killed
 *      mid-flight leaves evidence rather than vanishing
 *   2. mint the envelope, so the engine has a key to encrypt with
 *   3. run the engine
 *   4. seal the envelope to the finished archive, shred the plaintext key
 *   5. upload archive + envelope
 *   6. only once the upload is confirmed, enforce retention
 *
 * Retention runs last and only after a confirmed upload, because retention
 * deletes backups. A run that failed to upload must not be the run that decides
 * an older backup is now surplus.
 *
 * A site can be backed up by more than one party — see BackupProfile. Every run
 * belongs to exactly one, and the profile decides the working directory, the
 * lock, the snapshot, the bucket path, the recipient and which history rows the
 * run may look at. The manager profile does NOT prune the bucket: the credential
 * it is handed cannot delete, and pruning that shelf belongs to the party that
 * owns it. Local disk is a separate question with a separate answer: every
 * profile sweeps its own working directory by age, because the machine holding
 * the files is the only one that can.
 *
 * @version 1.21 - retention keeps days of history (backup_retention_days), not a count of restore
 *                points: surplus() keeps every point started inside the window and the newest one
 *                started before it. A manager-profile run carries the site's own window and removes
 *                the records of runs outside it (the management node deletes the objects); keep_days()
 *                is public for run_backup.php's BACKUP_KEEP_DAYS line
 * @version 1.20 - a chain never spans a code-tree swap: the files engine names the tree its snapshot
 *                describes (SNAR.tree) and the tree now on disk, and a difference — or no record —
 *                starts a new chain (tree_changed); an engine that finds the tree swapped after the
 *                decision fails the run rather than file a full inside the chain. The manifest
 *                records why its chain started.
 * @version 1.19 - pre-flight headroom (specs/disk_headroom_and_unit_diagnosis.md §5): a chain or
 *                standalone run refuses before it writes anything when this disk cannot hold what the
 *                run lands locally, naming both figures; the refusal is a recorded failure
 * @version 1.18.4 - a successful run's result carries `level` and `bytes` (the files artifact's level
 *                  and size) as numbers; run_backup.php prints them as BACKUP_LEVEL / BACKUP_BYTES
 * @version 1.18.3 - fail() reconnects once and retries when recording the failure throws: the
 *                  failure that ended the run (a full disk, a restarted PostgreSQL) is often the
 *                  one that killed the connection, and a row left `running` is never noticed
 * @version 1.18.2 - the run's message names the epoch envelopes only a retired recovery key opens, so a
 *                   management node's job result and the node's own history say so
 * @version 1.18.1 - a run that examined the epoch envelopes records the ones only a retired recovery
 *                   key opens (objects/retired-epochs.json) for Recovery Readiness
 * @version 1.18 - the manager profile carries offloaded files too (specs/implemented/backup_offloaded_files.md
 *                 § Rollout): plan_manager() reads the three request fields a management node
 *                 running the object store sends — objects, objects_index_url,
 *                 epoch_envelope_urls — and a run whose request carried objects writes the
 *                 profile's enabled marker; epoch envelopes arriving by link are re-sealed after
 *                 a recovery-key rotation the same way the site profile re-seals its own.
 * @version 1.17 - offloaded files are part of the backup (specs/implemented/backup_offloaded_files.md): a run
 *                 with files in it reads what its backup storage holds, stores every cloud blob backup storage
 *                 lacks (one at a time, inside OBJECT_STORE_BUDGET_*), excludes every cloud blob's
 *                 local paths from the archive, writes the objects index as an artifact of the
 *                 run, records the held set, and releases the local bytes every enabled profile
 *                 holds. Site retention deletes the objects only pruned runs named.
 * @version 1.16 - the database dump streams too (backup_database.sh --archive -), in chain mode as
 *                 db-{seq}.sql.gz.enc and in database-only mode as the standalone artifact with its
 *                 envelope sidecar; the upload completes only when pg_dump exited 0. Nothing a run
 *                 makes but the metadata artifact and a sidecar is ever on this disk
 * @version 1.15 - a standalone whole-site archive streams too (backup_project.sh --archive -): the
 *                 object is named up front so the envelope is minted for it, and no staging copy
 *                 of the tree is made. upload() passes an already-streamed artifact through
 * @version 1.14 - the files archive streams from tar straight into the bucket (S3Signer::put_stream)
 *                 and never lands on disk: stream_engine() hands the engine's stdout to the signer
 *                 with completion deferred, reads the engine's report after the bytes, and
 *                 completes the upload only when tar and openssl succeeded and at least 64 bytes
 *                 went up. A streamed artifact carries its bucket key and no path; a failed run
 *                 deletes the streamed object where the credential can
 * @version 1.13 - sweep_local removes verify working directories older than a day, and the
 *                 backup locks are acquirable by a verify (take_locks / release_locks) so a
 *                 verify never reads a chain a run is writing
 * @version 1.12 - current_chain() reads only chain runs, so a standalone whole-site run in the same
 *                 profile no longer makes the next run forget the open chain and take a fresh full
 * @version 1.11 - a full backup a tenth the size of the previous full is recorded with a WARNING
 *                 in its message and returned as one (the task message, the BACKUP_WARNING line a
 *                 management node reads). The run is real, so it is kept; what must not happen is
 *                 a backup of nothing counting as a backup: a week of 32-byte "successes" on the
 *                 dev box (2026-09-05) was visible only as a number nobody was reading
 * @version 1.10 - sweep_local also removes staged chain restores nobody came back for. A
 *                 staged-and-unapproved chain left the whole chain on the node, plus the
 *                 recovered chain.key beside it, with nothing to remove either
 * @version 1.9 - sweep_local expires pre-restore dumps of both generations. Nothing writes them
 *                any more, but machines that restored before that decision still carry one, and
 *                each is a full copy of a database the sweep did not recognise
 * @version 1.8 - every uploaded artifact is recorded in the node-side integrity ledger, so a
 *                restore can tell this machine's own archive from bytes a management node
 *                chose; an artifact that could not be recorded is reported, not swallowed
 * @version 1.7 - the local sweep reaches inside chain directories, so a site running
 *                incrementals ages its local copies out instead of keeping every archive
 *                it has ever made. manifest.json and the snapshot are never swept
 * @version 1.6 - a rotated recovery key ends the current chain: the next run starts a fresh chain
 *                sealed to the new key instead of extending one only the old key opens
 * @version 1.6 - a failed chain run removes the empty chain directory it created;
 *                one husk per failed run had been accumulating in backup storage forever
 * @version 1.5 - a manager-profile run seals to THIS machine's own proven recovery key, read
 *                locally. A run carrying key material is refused, and a machine with no proven
 *                key of its own refuses to back up rather than sealing to a key it was handed
 * @version 1.4 - a failed chain run also deletes its own artifacts and restores the
 *                pre-run manifest: the chain is being abandoned, so its half-made run
 *                must not strand gigabytes on disk until chain retention gets there,
 *                and the local manifest must never describe a run the bucket lacks
 * @version 1.3 - the meta artifact carries shape.json, so a restore knows what machine the
 *                backup came off and can reconcile it to the one it is landing on
 * @version 1.2 - profiles: a run is one party's backup end to end (paths, lock, snapshot,
 *                bucket segment, recipient, history), plus a machine-wide mutex so two
 *                parties never archive the same tree at once
 * @version 1.1 - a failed chain run clears the snapshot so the next run starts a fresh
 *                chain (the snapshot advances DURING the files engine, so carrying it
 *                past a failure silently corrupts the chain); a lost site key degrades
 *                to a new chain instead of failing until the age threshold; retention
 *                passes each only ever touch their own family (cloud retention skips
 *                chain rows, so it can never delete a chain's full out from under its
 *                incrementals) and both families are pruned on every run; delete-local
 *                is honoured in chain mode; concurrent runs are excluded with a lock
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupLedger.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));

class BackupRunnerException extends Exception {}

/**
 * A backup destination that exists only for the length of one run.
 *
 * The manager profile is handed its bucket and credentials by whoever triggered
 * the run; they are never stored here. That a node holds no credential which
 * could reach another site's backup storage is a security property, so this is
 * deliberately NOT a BackupTarget model: there is no save(), no table and no
 * persistence path to forget to avoid. It carries only the surface a run reads.
 */
class EphemeralBackupDestination {

	/** No database row, so no id. History rows record the name instead. */
	public $key = null;

	private $fields;
	private $credentials;

	public function __construct(array $fields, array $credentials) {
		$this->fields = $fields;
		$this->credentials = $credentials;
	}

	public function get($field) {
		return $this->fields[$field] ?? null;
	}

	public function get_credentials() {
		return $this->credentials;
	}
}

class BackupRunner {

	/**
	 * Last-resort working directory, used only if the site root cannot be
	 * resolved. The real default is computed — see output_dir().
	 */
	const OUTPUT_DIR = '/backups';

	/** Engine wall-clock ceiling. A large site's first full run is not quick. */
	const ENGINE_TIMEOUT = 10800;

	/**
	 * Incrementals one full will carry before the next run starts a new chain.
	 * A ceiling regardless of the day interval: every incremental is another
	 * archive a restore has to download and apply in order, and the failure of
	 * any one of them invalidates every run after it.
	 */
	const MAX_INCREMENTALS = 30;

	/**
	 * One budget covers a run's whole object store step — local originals
	 * and catch-up from the file store alike: 2 GB or 20 minutes, whichever
	 * comes first. Bytes transferred, not bytes held: the step holds one
	 * object's ciphertext at a time. What the budget leaves is indexed
	 * `stored: false` and taken next run. Constants, not settings: a manager
	 * run after a week of failed runs, or a first run on a big site, needs a
	 * ceiling inside a nightly task that has one, and nobody should be able
	 * to remove it.
	 */
	const OBJECT_STORE_BUDGET_BYTES = 2147483648;
	const OBJECT_STORE_BUDGET_SECONDS = 1200;

	/**
	 * Run one backup end to end. Returns a scheduled-task result array.
	 *
	 * Never throws: a backup task that dies takes the scheduler's other work
	 * with it and reports nothing. Every failure is recorded on the history row
	 * and reported as a status instead.
	 */
	public static function run(array $config = array()) {
		@set_time_limit(0);

		try {
			$plan = self::plan($config);
		} catch (BackupRunnerException $e) {
			// Not configured is not a failure. A site that has never set up
			// backups should say so quietly, not raise an error every hour.
			return array('status' => 'skipped', 'message' => $e->getMessage());
		}

		// Two locks, for two different problems.
		//
		// The machine lock is about the box: two profiles archiving the same tree
		// at once is twice the I/O for no extra safety, and on a shared host it is
		// somebody else's I/O too. Whoever gets there second waits for its next
		// tick rather than competing.
		//
		// The profile lock is about correctness: two runs of ONE profile share the
		// snapshot file, the chain manifest and the dump diff-and-rename, none of
		// which survive a race. The scheduled task and the admin page's "run now"
		// can overlap this way.
		//
		// Both are best effort in the same sense: if the directory cannot be
		// created yet, the run proceeds and fails further down with a recorded
		// history row, which is worth more than an unrecorded refusal here.
		$machine_lock = self::acquire_lock(BackupProfile::machine_lock_path($plan['base_dir']), $plan['base_dir']);
		if ($machine_lock === false) {
			return array('status' => 'skipped',
				'message' => 'Another backup is already running on this machine; not starting a second.');
		}

		$lock = self::acquire_lock($plan['output_dir'] . '/.jy_backup.lock', $plan['output_dir']);
		if ($lock === false) {
			self::release_lock($machine_lock);
			return array('status' => 'skipped',
				'message' => 'Another ' . $plan['profile'] . '-profile backup run is already in progress; '
					. 'not starting a second.');
		}

		$history = new BackupHistory(NULL);
		$history->set('bkh_type', $plan['type']);
		$history->set('bkh_outcome', 'running');
		$history->set('bkh_slug', $plan['slug']);
		$history->set('bkh_profile', $plan['profile']);
		$history->set('bkh_recovery_fpr', $plan['recovery_fpr']);
		$history->set('bkh_encrypted', $plan['encrypt']);
		if ($plan['target']) {
			// An ephemeral target has no id. The name is denormalised onto the row
			// either way, which is what the history has to be able to say.
			if ($plan['target']->key) {
				$history->set('bkh_bkt_backup_target_id', $plan['target']->key);
			}
			$history->set('bkh_target_name', $plan['target']->get('bkt_name'));
		}
		$history->save();

		try {
			$result = self::execute($plan, $history);
		} catch (\Throwable $e) {
			self::fail($history, $e->getMessage());
			return array('status' => 'error', 'message' => 'Backup failed: ' . $e->getMessage());
		} finally {
			self::release_lock($lock);
			self::release_lock($machine_lock);
		}

		return $result;
	}

	/**
	 * Take the two backup locks for something that is not a backup run — a
	 * verify reading a chain a run could be writing. Returns the handles, or
	 * FALSE when either is held. release_locks() gives them back.
	 *
	 * The same two locks, for the same two reasons: the machine lock keeps a
	 * verify's download and decrypt off a box already archiving itself, and the
	 * profile lock keeps it from reading a manifest mid-rewrite. A run that
	 * finds them held skips its tick exactly as it would for another run.
	 */
	public static function take_locks(array $plan) {
		$machine = self::acquire_lock(BackupProfile::machine_lock_path($plan['base_dir']), $plan['base_dir']);
		if ($machine === false) {
			return false;
		}
		$profile = self::acquire_lock($plan['output_dir'] . '/.jy_backup.lock', $plan['output_dir']);
		if ($profile === false) {
			self::release_lock($machine);
			return false;
		}
		return array($machine, $profile);
	}

	public static function release_locks($handles) {
		if (!is_array($handles)) { return; }
		self::release_lock($handles[1] ?? null);
		self::release_lock($handles[0] ?? null);
	}

	/**
	 * Take an exclusive non-blocking lock. Returns the handle, null if the lock
	 * could not be created at all (best effort — proceed), or FALSE if somebody
	 * else holds it (do not proceed).
	 */
	private static function acquire_lock($path, $dir) {
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
			return null;
		}
		$handle = @fopen($path, 'c');
		if (!$handle) {
			return null;
		}
		@chmod($path, 0600);
		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			return false;
		}
		return $handle;
	}

	private static function release_lock($handle) {
		if ($handle) {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	// ------------------------------------------------------------------ plan

	/**
	 * Resolve everything the run needs, or explain what is missing. Done up
	 * front so a misconfiguration is reported before an archive is built.
	 */
	public static function plan(array $config = array()) {
		$profile = BackupProfile::normalize($config['profile'] ?? BackupProfile::SITE);
		return ($profile === BackupProfile::MANAGER)
			? self::plan_manager($config)
			: self::plan_site($config);
	}

	/**
	 * The site's own backup, resolved entirely from its own settings. Depends on
	 * nothing outside this machine, which is the whole point of it.
	 */
	private static function plan_site(array $config) {
		$type = isset($config['backup_type']) ? (string)$config['backup_type'] : self::site_backup_type();
		if ($type !== 'database') { $type = 'project'; }

		try {
			$target = self::site_target();
		} catch (\Throwable $e) {
			throw new BackupRunnerException('The configured backup target could not be loaded.');
		}

		if (!$target) {
			throw new BackupRunnerException(
				'No backup target is configured, so there is nowhere to put a backup. Set one up on the Backups page.');
		}

		// Anything leaving this machine is encrypted. There is no setting for
		// this: an archive carries config/ — the database password, the secret
		// box key — and a switch to send that in the clear is not a feature.
		$encrypt = true;
		if (!BackupRecoveryKey::is_ready()) {
			throw new BackupRunnerException(
				'Backups are configured but the recovery key is not set up, so nothing could ever open them. '
				. 'Finish recovery key setup on the Backups page.');
		}

		$mode = (string)self::setting('backup_mode');
		if ($mode !== 'full') { $mode = 'chain'; }
		// A database-only backup has nothing to be incremental about — the dump
		// is rewritten in full every time either way.
		if ($type === 'database') { $mode = 'full'; }

		$base = self::output_dir();

		return array(
			'profile'      => BackupProfile::SITE,
			'type'         => $type,
			'mode'         => $mode,
			'full_days'    => max(0, (int)self::setting('backup_full_interval_days')),
			'max_inc'      => self::MAX_INCREMENTALS,
			'target'       => $target,
			'encrypt'      => $encrypt,
			'recipients'   => BackupEnvelope::recipients(),
			'recovery_fpr' => BackupRecoveryKey::fingerprint(BackupRecoveryKey::public_key()),
			'slug'         => self::slug(),
			'project'      => basename(PathHelper::getSiteRoot()),
			'base_dir'     => $base,
			'output_dir'   => BackupProfile::output_dir(BackupProfile::SITE, $base),
			'keep_days'    => self::keep_days(),
			'keep_local'   => max(0, (int)self::setting('backup_local_retention_days')),
			'delete_local' => (string)self::setting('backup_delete_local_after_upload') === '1',
			// This site prunes its own backup storage. It holds the credentials, and the
			// backups being counted are its own.
			'prunes_cloud' => true,
			// Offloaded files are stored in this backup storage and indexed by every run
			// with files in it; a database-only backup carries no files and its
			// profile is not enabled for objects. What backup storage holds is read
			// by listing it: this profile holds the credential that can.
			'objects'        => ($type !== 'database'),
			'objects_source' => 'listing',
		);
	}

	/**
	 * The site's own target when one is configured, enabled and undeleted;
	 * null otherwise. Shared by plan_site() and BackupProfile::enabled(), so
	 * "does this site back itself up" has one answer. Throws when the row
	 * cannot be loaded at all.
	 */
	public static function site_target() {
		$target_id = (int)self::setting('backup_target_id');
		if (!$target_id) {
			return null;
		}
		$candidate = new BackupTarget($target_id, TRUE);
		if ($candidate->key && $candidate->get('bkt_enabled') && !$candidate->get('bkt_delete_time')) {
			return $candidate;
		}
		return null;
	}

	/** The configured site backup type: 'project' or 'database'. */
	public static function site_backup_type() {
		return ((string)self::setting('backup_type') === 'database') ? 'database' : 'project';
	}

	/**
	 * A management node's backup of this site: where it goes arrives with the run,
	 * what opens it does not.
	 *
	 * The bucket and its credential are the management node's to supply — they name
	 * a shelf this machine has no other way to reach, and they leave with the
	 * process. The recovery key is different in kind. Sealing to a public key
	 * always appears to succeed: seal to an attacker's and every archive reports
	 * itself encrypted while only the attacker can open it, with nothing on any
	 * machine looking wrong. A key that arrives over a wire is therefore a key
	 * this run cannot trust, whoever sent it.
	 *
	 * So encryption is pinned to THIS site's own proven recovery key, read here,
	 * locally. A run carrying key material is refused outright rather than
	 * quietly ignored — the refusal is how a stale management node or a substituted
	 * key becomes visible instead of becoming the new arrangement.
	 *
	 * A site with no proven key of its own cannot take an encrypted backup for
	 * anybody, and says so. Never silently downgrade: an unencrypted copy of the
	 * whole database in somebody else's backup storage is the outcome that refusal exists
	 * to prevent.
	 */
	private static function plan_manager(array $config) {
		$m = isset($config['manager']) && is_array($config['manager']) ? $config['manager'] : array();

		// Refused, not ignored. Reading past a supplied key would leave a management
		// node believing it chose who can open these archives.
		foreach (array('recovery_public_key', 'recovery_private_key', 'recovery_fpr', 'recipients') as $forbidden) {
			if (isset($m[$forbidden])) {
				throw new BackupRunnerException(
					'This run arrived carrying encryption key material (' . $forbidden . ') and was refused. '
					. 'Backups on this machine seal only to the recovery key this machine holds and has '
					. 'proven; nothing supplies one from outside. Treat a run that carries a key as a '
					. 'management node that is out of date, or as an attempt to substitute the key that '
					. 'opens these backups.');
			}
		}

		$missing = array();
		foreach (array('bucket', 'credentials') as $required) {
			if (empty($m[$required])) { $missing[] = $required; }
		}
		if ($missing) {
			throw new BackupRunnerException(
				'A manager-profile backup needs ' . implode(', ', $missing) . ' supplied with the run.');
		}

		$type = ((string)($m['type'] ?? 'project') === 'database') ? 'database' : 'project';
		$mode = ((string)($m['mode'] ?? 'chain') === 'full') ? 'full' : 'chain';
		if ($type === 'database') { $mode = 'full'; }

		$credentials = is_array($m['credentials'])
			? $m['credentials']
			: (json_decode((string)$m['credentials'], true) ?: array());
		if (!$credentials) {
			throw new BackupRunnerException('The credentials supplied with this run could not be read.');
		}

		$target = new EphemeralBackupDestination(array(
			'bkt_name'        => (string)($m['target_name'] ?? 'management node storage'),
			'bkt_provider'    => (string)($m['provider'] ?? 's3'),
			'bkt_bucket'      => (string)$m['bucket'],
			'bkt_path_prefix' => (string)($m['path_prefix'] ?? 'joinery-backups'),
			'bkt_enabled'     => true,
		), $credentials);

		// The one place this run's encryption is decided, and it is a local read.
		// BackupRecoveryKey::public_key() throws when the key is unset or has
		// never been proven; the message is rewritten here because the operator
		// reading it is a management node's, and the fix is on this machine.
		try {
			$recipients = BackupEnvelope::recipients();
		} catch (BackupRecoveryKeyException $e) {
			throw new BackupRunnerException(
				'This machine has no proven recovery key of its own, so no backup taken here can be '
				. 'encrypted and none will run. Set one up at Admin -> System -> Backups on THIS site '
				. '(' . BackupRecoveryKey::SETUP_URL . ') and open the verification challenge with it. '
				. 'No management node can supply this for you. (' . $e->getMessage() . ')');
		}
		$base = self::output_dir();
		$objects = ($type !== 'database') && !empty($m['objects']);

		return array(
			'profile'      => BackupProfile::MANAGER,
			'type'         => $type,
			'mode'         => $mode,
			'full_days'    => max(0, (int)($m['full_interval_days'] ?? 7)),
			'max_inc'      => self::MAX_INCREMENTALS,
			'target'       => $target,
			'encrypt'      => true,
			'recipients'   => $recipients,
			'recovery_fpr' => BackupRecoveryKey::fingerprint(BackupRecoveryKey::public_key()),
			'slug'         => self::validate_slug($m['slug'] ?? self::slug()),
			'project'      => basename(PathHelper::getSiteRoot()),
			'base_dir'     => $base,
			'output_dir'   => BackupProfile::output_dir(BackupProfile::MANAGER, $base),
			'keep_local'   => max(0, (int)($m['keep_local_days'] ?? 7)),
			'delete_local' => !empty($m['delete_local_after_upload']),
			// How long these backups are kept is this site's decision: the run
			// reports the window (BACKUP_KEEP_DAYS) and the management node
			// deletes by it, never below its own minimum. The deleting is not
			// this machine's — the credential it was handed cannot delete, and a
			// site that could erase its own offsite copies would lose them to the
			// first intruder. Retention here removes the records only, by the
			// same rule and window, so this site's list matches what is kept.
			'keep_days'    => self::keep_days(),
			'prunes_cloud' => false,
			// The object store in the manager-profile backup storage is driven by three request
			// fields a management node running that code sends. A request
			// without `objects` — an older management node — stores nothing and
			// holds nothing, so a node upgraded ahead of its management node
			// behaves as it always did. The credential cannot list, so what the
			// shelf holds arrives as the newest index by link, never by listing;
			// a request with no link means backup storage holds nothing yet. Links
			// are https or nothing: a signature is a bearer token.
			'objects'             => $objects,
			'objects_source'      => 'index',
			'objects_index_url'   => ($objects && BackupFetch::is_signed_url($m['objects_index_url'] ?? '')) ? (string)$m['objects_index_url'] : '',
			'epoch_envelope_urls' => $objects ? self::epoch_envelope_urls($m['epoch_envelope_urls'] ?? null) : array(),
		);
	}

	/** The epoch envelope links a request carries: epoch id => https link, anything else dropped. */
	private static function epoch_envelope_urls($raw) {
		$out = array();
		if (!is_array($raw)) {
			return $out;
		}
		foreach ($raw as $epoch => $url) {
			if (preg_match('/^epoch-\d{8}_\d{6}$/', (string)$epoch) && BackupFetch::is_signed_url($url)) {
				$out[(string)$epoch] = (string)$url;
			}
		}
		return $out;
	}

	/**
	 * The prefix segment this site files its backups under. Defaults to the
	 * project directory name, which is the same value server_manager uses as a
	 * node slug — so a site that later joins a fleet keeps one location rather
	 * than starting a second pile beside the first.
	 */
	/**
	 * Where backups are built and swept.
	 *
	 * Left blank it resolves to `backups/` beside public_html — the site's own
	 * directory, next to logs/, uploads/ and cache/, writable by whoever runs
	 * the site and already excluded from published archives. That is the one
	 * location every deployment shape has: a VPS, a container, and a shared host
	 * that will not let you write outside your home directory all have it.
	 *
	 * A filesystem-root path like /backups cannot be the default, however much
	 * it suits a machine you own: nothing the site runs as can create a
	 * directory at /, so it fails on a fresh install until someone intervenes as
	 * root — and a site that cannot write its working directory cannot back up
	 * at all, which is a bad reason to have no backups.
	 *
	 * Still configurable, and still absolute: a relative path would resolve
	 * against whatever directory the scheduler happened to start in.
	 */
	public static function output_dir() {
		$dir = trim((string)self::setting('backup_output_dir'));
		if ($dir === '') { $dir = self::default_output_dir(); }
		if (substr($dir, 0, 1) !== '/') {
			throw new BackupRunnerException('The backup working directory must be an absolute path.');
		}
		return rtrim($dir, '/');
	}

	/**
	 * The computed default: `backups/` in the site root. Falls back to the
	 * constant only if the site root cannot be resolved to an absolute path,
	 * which would mean the install is not laid out the way every deploy path
	 * builds it.
	 */
	public static function default_output_dir() {
		$root = rtrim((string)PathHelper::getSiteRoot(), '/');
		return ($root !== '' && substr($root, 0, 1) === '/')
			? $root . '/backups'
			: self::OUTPUT_DIR;
	}

	/**
	 * Directory names to skip beyond the always-skipped set. Build output and
	 * regenerable caches: large, and a restore rebuilds them anyway.
	 */
	public static function extra_excludes() {
		$raw = (string)self::setting('backup_exclude');
		$out = array();
		foreach (explode(',', $raw) as $name) {
			$name = trim($name);
			// Names, not paths: a value with a slash would silently match
			// nothing, and one with .. has no business in an exclude list.
			if ($name === '' || strpos($name, '/') !== false || strpos($name, '..') !== false) {
				continue;
			}
			$out[] = $name;
		}
		return $out;
	}

	public static function slug() {
		$configured = trim((string)self::setting('backup_path_slug'));
		return self::validate_slug($configured !== '' ? $configured : basename(PathHelper::getSiteRoot()));
	}

	/**
	 * The slug becomes an object key segment and a delete prefix, so it is
	 * checked wherever it comes from — a setting on this site, or a value handed
	 * over with a manager-profile run. Anything outside this set could widen a
	 * retention delete beyond the site it belongs to.
	 */
	public static function validate_slug($slug) {
		$slug = trim((string)$slug);
		if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
			throw new BackupRunnerException(
				'The backup path name may only contain letters, numbers, hyphens and underscores.');
		}
		return $slug;
	}

	// --------------------------------------------------------------- execute

	private static function execute(array $plan, BackupHistory $history) {
		if ($plan['mode'] === 'chain') {
			return self::execute_chain($plan, $history);
		}
		return self::execute_full($plan, $history);
	}

	// ----------------------------------------------------------------- chain

	/** Where this site keeps the chain it is currently extending. */
	private static function snar_path(array $plan) {
		return rtrim($plan['output_dir'], '/') . '/.' . $plan['slug'] . '.snar';
	}

	/**
	 * The chain currently being extended: its id and its local manifest, or
	 * nulls when there is nothing to extend.
	 *
	 * Read from this site's own history rather than from the bucket. Listing
	 * the bucket to decide what to append to would make every backup depend on
	 * the provider being reachable at decision time, and would happily append
	 * to a chain another site had written under the same slug.
	 */
	private static function current_chain(array $plan) {
		// Only chain runs can name the chain to extend. A standalone whole-site
		// run in the same profile (a pre-restore dump, a run under a policy set
		// to full mode) writes a success row with no chain id; reading the
		// newest row regardless made the next run believe there was no chain
		// and take a fresh full (getjoinery, 2026-09-05: a 464 MB full where a
		// 70 MB incremental would have done).
		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'deleted' => false, 'slug' => $plan['slug'], 'type' => 'project',
			      'profile' => $plan['profile'], 'chained' => true),
			array('bkh_start_time' => 'DESC'), 1, 0);
		$rows->load();

		$chain_id = null;
		foreach ($rows as $r) { $chain_id = (string)$r->get('bkh_chain_id'); }
		if (!$chain_id) {
			return array(null, null);
		}

		$manifest_path = self::chain_dir($plan, $chain_id) . '/' . BackupChain::MANIFEST_NAME;
		try {
			return array($chain_id, BackupChain::read($manifest_path));
		} catch (BackupChainException $e) {
			// The manifest is how a chain is extended AND restored. Without a
			// readable one locally, appending would produce runs nothing could
			// stitch together — so start a new chain instead. Same safe
			// degradation as losing the snapshot: one extra full, never a
			// broken backup.
			error_log('BackupRunner: chain manifest unreadable (' . $e->getMessage() . '); starting a new chain.');
			return array(null, null);
		}
	}

	/**
	 * Does the snapshot describe the code tree now on disk? The files engine
	 * writes the identity of the tree it archived beside the snapshot
	 * (SNAR.tree) and is the one place that identity is computed — asked here
	 * with --print-tree-id rather than recomputed, so the decision and the
	 * engine can never disagree about what counts as the same tree. No record
	 * is a no: a chain whose snapshot predates the record cannot show it spans
	 * no swap, and an incremental across a swap cannot be extracted.
	 */
	private static function snapshot_matches_tree(array $plan, $snar) {
		$recorded = is_file($snar . '.tree') ? trim((string)@file_get_contents($snar . '.tree')) : '';
		if ($recorded === '') {
			return false;
		}
		$cmd = 'bash ' . escapeshellarg(PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/backup_files.sh')
			. ' ' . escapeshellarg($plan['project'])
			. ' --project-dir ' . escapeshellarg($plan['project_dir'] ?? PathHelper::getSiteRoot())
			. ' --print-tree-id 2>&1';
		$out = array(); $rc = 0;
		exec($cmd, $out, $rc);
		$current = '';
		foreach ($out as $line) {
			if (strpos($line, 'TREE_ID=') === 0) { $current = trim(substr($line, 8)); }
		}
		if ($rc !== 0 || $current === '') {
			throw new BackupRunnerException('Could not read the identity of the code tree ('
				. trim(implode(' ', array_slice($out, -2))) . ').');
		}
		return hash_equals($recorded, $current);
	}

	private static function chain_dir(array $plan, $chain_id) {
		return rtrim($plan['output_dir'], '/') . '/' . $chain_id;
	}

	private static function execute_chain(array $plan, BackupHistory $history) {
		$dir = $plan['output_dir'];
		self::ensure_dir($dir);

		$snar = self::snar_path($plan);
		list($chain_id, $manifest) = self::current_chain($plan);

		$reason = BackupChain::should_start_new($manifest, is_file($snar) && filesize($snar) > 0,
			$plan['full_days'], $plan['max_inc'], null, (string)$plan['recovery_fpr'],
			self::snapshot_matches_tree($plan, $snar));
		if ($reason === 'recovery_rotated') {
			error_log('BackupRunner: the recovery key changed since chain ' . $chain_id
				. ' started; starting a new chain sealed to the current key.');
		}

		$data_key = null;
		if ($reason === '') {
			// Extending: the key is already sealed into the manifest, and this
			// site can open its own. Unless it cannot — the site key is
			// disposable by design, so a key that was lost or re-minted must
			// degrade to a new chain here, not fail every run until the age
			// threshold happens to start one. (A key that exists but is
			// unreadable or corrupt still fails loudly: minting the new chain's
			// envelope goes through the same site key and raises the same
			// fix-permissions message.)
			try {
				$data_key = BackupEnvelope::open_as_site($manifest['envelope']);
			} catch (\Throwable $e) {
				error_log('BackupRunner: the chain envelope did not open with the site key ('
					. $e->getMessage() . '); starting a new chain.');
				$reason = 'envelope_unopenable';
			}
		}

		// Before anything is minted, unlinked or written: can this disk hold
		// what the run lands here? A refusal is thrown like any other failure,
		// so it is recorded on the history row and reaches the notices.
		$refusal = self::preflight_refusal(
			self::local_need($plan, $snar, $chain_id ? self::chain_dir($plan, $chain_id) . '/' . BackupChain::MANIFEST_NAME : ''),
			self::free_bytes($plan, $dir),
			self::expected_bytes($manifest, $reason !== '' ? 0 : 1));
		if ($refusal !== '') {
			throw new BackupRunnerException($refusal);
		}

		if ($reason !== '') {
			// A new chain gets a new data key and a clean snapshot. Reusing the
			// previous chain's key would mean one compromised key opened both,
			// and reusing its snapshot would produce an "incremental" whose
			// full lives in a chain retention may already have deleted.
			$chain_id = BackupChain::new_chain_id();
			$mint     = BackupEnvelope::mint($chain_id, $plan['recipients']);
			$data_key = $mint['data_key'];
			$manifest = BackupChain::start($chain_id, $plan['slug'], $mint['envelope'], $reason);
			@unlink($snar);
			self::ensure_dir(self::chain_dir($plan, $chain_id));
		}

		$seq      = BackupChain::next_seq($manifest);
		$chain_d  = self::chain_dir($plan, $chain_id);
		self::ensure_dir($chain_d);

		$key_file = $chain_d . '/.run.key';
		self::write_private($key_file, $data_key);

		// The manifest as it stood before this run, so a failure can put it back.
		// A brand-new chain has no before — its manifest file is deleted instead.
		$manifest_pre  = ($reason !== '') ? null : $manifest;
		$manifest_path = $chain_d . '/' . BackupChain::MANIFEST_NAME;

		$artifacts = array();
		$objects = null;
		try {
			try {
				// Offloaded files first, so that at commit backup storage holds
				// everything the archive leaves out: what backup storage holds, the
				// exclude list, the store step.
				$objects = self::begin_objects($plan, $chain_id . '/' . $seq);
				// The files archive streams straight to the bucket: by the time
				// run_files_engine() returns, the object is complete in backup storage
				// and the artifact carries its key. It is never on this disk.
				$artifacts['files'] = self::run_files_engine($plan, $chain_id, $chain_d, $seq, $snar, $key_file,
					$objects ? $objects['exclude'] : '');
				$artifacts['db']    = self::run_db_engine($plan, $chain_id, $chain_d, $seq, $key_file);
				$meta = self::build_meta($plan, $chain_d, $seq, $key_file);
				if ($meta) { $artifacts['meta'] = $meta; }
				if ($objects) {
					$artifacts['objects'] = self::write_index_artifact($plan, $objects,
						$chain_d . '/' . BackupChain::artifact_name('objects', $seq));
				}
			} finally {
				self::shred($key_file);
				if ($objects && $objects['exclude'] !== '') { @unlink($objects['exclude']); }
			}

			$level = ($reason !== '') ? 0 : 1;
			if ($level === 0 && (int)$artifacts['files']['level'] !== 0) {
				// The engine decides level from the snapshot; a disagreement means
				// the snapshot survived a "start a new chain" decision, and this run
				// would be an incremental filed as a full.
				throw new BackupRunnerException(
					'A new chain was started but the files engine produced an incremental. '
					. 'The snapshot at ' . $snar . ' was not cleared.');
			}
			if ($level === 1 && (int)$artifacts['files']['level'] === 0) {
				// The engine found the code tree swapped after this run decided to
				// extend the chain (an upgrade landed mid-backup). A full filed
				// inside the chain is not what the chain describes; failing clears
				// the snapshot, so the next run starts a new chain.
				throw new BackupRunnerException(
					'The code tree changed while this backup ran (an upgrade or a restore), so chain '
					. $chain_id . ' was not extended. The next run starts a new chain.');
			}
			$level = (int)$artifacts['files']['level'];

			$manifest = BackupChain::add_run($manifest, $seq, $level, $artifacts);
			BackupChain::write($manifest, $manifest_path);

			$uploaded = self::upload_chain($plan, $chain_id, $artifacts, $manifest_path);
		} catch (\Throwable $e) {
			// The snapshot advances DURING the files engine, before this run is
			// committed to the manifest and confirmed in the bucket. Carrying it
			// past a failure corrupts the chain twice over: a retry reuses this
			// sequence number and computes its incremental against the advanced
			// snapshot, so the failed attempt's changes end up in no archive at
			// all; and a failed upload leaves the local manifest one run ahead
			// of the bucket, so the next upload publishes a manifest describing
			// artifacts the bucket does not hold. Clearing the snapshot makes
			// the next run start a fresh chain — one extra full, never a
			// silently broken backup.
			@unlink($snar);
			// And since the chain is now abandoned, this run's half-made output
			// is deleted and the manifest put back the way it was. Local artifact
			// deletion otherwise happens only after success, so a failed run of a
			// large site would strand its archives on disk until chain retention
			// finally removed the whole chain — weeks, on a disk that may not
			// have them to spare.
			self::discard_failed_run($chain_d, $seq, $artifacts, $manifest_path, $manifest_pre, $plan);
			throw $e;
		}

		$warning = ($level === 0)
			? self::full_size_warning($plan, (int)$artifacts['files']['bytes'], (int)$history->key)
			: '';

		$history->set('bkh_chain_id', $chain_id);
		$history->set('bkh_chain_seq', $seq);
		$history->set_artifacts($uploaded);
		$history->set('bkh_upload_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_outcome', 'success');
		$history->set('bkh_finish_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_message', ($level === 0 ? 'Full' : 'Incremental') . ' run ' . $seq . ' of ' . $chain_id
			. ($reason !== '' ? ' (new chain: ' . $reason . ')' : '')
			. ($objects ? self::objects_note($objects) : '')
			. ($warning !== '' ? ' — WARNING: ' . $warning : ''));
		$history->save();

		if ($plan['delete_local']) {
			// A machine that asked for its disk back gets it in chain mode too.
			// The chain stays extendable from just the manifest and the
			// snapshot; the uploaded artifacts need no local copy. A streamed
			// artifact has no path: there was never a local copy to remove.
			foreach ($artifacts as $a) {
				if (!empty($a['path'])) { @unlink($a['path']); }
			}
		}

		// The run is committed: record what backup storage now holds, and release
		// the local bytes every enabled profile holds.
		$released = $objects ? self::finish_objects($plan, $objects) : 0;

		// Both retention families run on every backup, so a site switched
		// between modes still ages its old backups out. Each pass only ever
		// sees its own kind: cloud retention skips chain rows entirely, so it
		// can never delete a chain's full out from under its incrementals.
		// Objects are a third family, pruned by what the first two pruned.
		$pruned_indexes = array();
		$pruned = self::enforce_chain_retention($plan, $pruned_indexes) + self::enforce_cloud_retention($plan, $pruned_indexes);
		$objects_pruned = self::enforce_object_retention($plan, $pruned_indexes);
		$swept  = self::sweep_local($plan);

		$msg = ($level === 0 ? 'Full backup' : 'Incremental backup') . ' (' . self::human($artifacts['files']['bytes']) . ' of files)'
			. ' in ' . $chain_id . ' to ' . $plan['target']->get('bkt_name');
		if ($objects) { $msg .= self::objects_message($objects, $released); }
		if ($pruned) { $msg .= "; pruned {$pruned} old backup" . ($pruned === 1 ? '' : 's'); }
		if ($objects_pruned) { $msg .= "; removed {$objects_pruned} offloaded file" . ($objects_pruned === 1 ? '' : 's') . ' no kept backup names'; }
		if ($swept)  { $msg .= "; swept {$swept} local file" . ($swept === 1 ? '' : 's'); }

		$figures = array('level' => $level, 'bytes' => (int)$artifacts['files']['bytes']);
		if ($warning !== '') {
			return array('status' => 'success', 'message' => 'WARNING: ' . $warning . ' — ' . $msg, 'warning' => $warning) + $figures;
		}
		return array('status' => 'success', 'message' => $msg) + $figures;
	}

	// ------------------------------------------------------------ pre-flight

	/**
	 * What a run writes to this disk besides the archive, which streams: the
	 * metadata artifact, the offloaded-files index, the envelope sidecar and
	 * the engine's report files. Generous on purpose — a large site's index is
	 * the biggest of them and still a few megabytes.
	 */
	const PREFLIGHT_LOCAL_OVERHEAD = 67108864;   // 64 MiB

	/** Room kept free on top of the need, so a run never takes the last of the disk. */
	const PREFLIGHT_FLOOR = 1073741824;          // 1 GiB

	/**
	 * Bytes this run lands on local disk. Every archive and dump streams to the
	 * bucket, so what is left is the tar snapshot (rewritten in full by every
	 * chain run, so the current one's size is the estimate), the chain
	 * manifest, and PREFLIGHT_LOCAL_OVERHEAD. A standalone run passes '' for
	 * both paths.
	 */
	public static function local_need(array $plan, $snar_path, $manifest_path): int {
		$need = self::PREFLIGHT_LOCAL_OVERHEAD;
		foreach (array($snar_path, $manifest_path) as $p) {
			if ($p !== '' && is_file($p)) {
				$need += (int)@filesize($p);
			}
		}
		return $need;
	}

	/**
	 * The size the run's files archive is expected to be, for the refusal's
	 * wording: for a full, the newest full in the local chain manifest; for an
	 * incremental, the newest run. 0 when the manifest has nothing to say.
	 */
	public static function expected_bytes(?array $manifest, int $level): int {
		$runs = ($manifest && !empty($manifest['runs'])) ? $manifest['runs'] : array();
		for ($i = count($runs) - 1; $i >= 0; $i--) {
			if ($level === 0 && (int)($runs[$i]['level'] ?? 1) !== 0) {
				continue;
			}
			return (int)($runs[$i]['artifacts']['files']['bytes'] ?? 0);
		}
		return 0;
	}

	/**
	 * Free bytes where the run writes, or null when the filesystem will not
	 * say. `free_bytes` on the plan is the measured figure when a caller has
	 * one already; tests pass it to exercise the refusal.
	 */
	private static function free_bytes(array $plan, $dir) {
		if (isset($plan['free_bytes'])) {
			return (int)$plan['free_bytes'];
		}
		$free = @disk_free_space($dir);
		return ($free === false) ? null : (int)$free;
	}

	/**
	 * The refusal for a run this disk cannot hold, or '' when it can (or when
	 * free space is unknowable — the engine then finds out for itself, as it
	 * did before this check existed). The need is padded by a fifth and
	 * PREFLIGHT_FLOOR is kept free on top, and the message names both figures.
	 * Pure.
	 */
	public static function preflight_refusal(int $local_need, ?int $free, int $expected_bytes): string {
		if ($free === null) {
			return '';
		}
		$required = (int)ceil($local_need * 1.2) + self::PREFLIGHT_FLOOR;
		if ($free >= $required) {
			return '';
		}
		$msg = 'Not started: this run needs about ' . self::human($required) . ' on disk and '
			. self::human($free) . ' is free.';
		if ($expected_bytes > 0) {
			$msg .= ' (The files archive itself, about ' . self::human($expected_bytes)
				. ' last time, streams to backup storage and is not written here.)';
		}
		return $msg;
	}

	/**
	 * A full backup a tenth the size of the previous full, or smaller, is not
	 * proof of a problem — a site can shrink — but it is exactly what a backup
	 * of nothing looks like, and one of those was reported as success every
	 * night for a week before anyone read the number. So the run is kept (the
	 * archive is real) and the size is said out loud, on the run's own record,
	 * on the task message, and on the management node's card for this node.
	 * The comparison is with the previous full only, so a site that really did
	 * shrink is flagged once and then measured against its new size.
	 *
	 * Returns '' when there is nothing to say.
	 */
	public static function full_size_warning(array $plan, int $bytes, int $exclude_history_id): string {
		$previous = self::previous_full_bytes($plan, $exclude_history_id);
		if ($previous <= 0 || $bytes * 10 > $previous) {
			return '';
		}
		$pct = $previous > 0 ? round($bytes * 100 / $previous, $bytes * 100 < $previous ? 2 : 0) : 0;
		return 'this full backup is ' . self::human($bytes) . ' of files against ' . self::human($previous)
			. ' last time (' . $pct . '%). Check the archive before trusting it';
	}

	/** Bytes of the files archive in the most recent successful full of this slug and profile. */
	private static function previous_full_bytes(array $plan, int $exclude_history_id): int {
		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'deleted' => false, 'slug' => $plan['slug'], 'type' => 'project',
			      'profile' => $plan['profile']),
			array('bkh_start_time' => 'DESC'), 20, 0);
		foreach ($rows as $r) {
			if ((int)$r->key === $exclude_history_id) { continue; }
			foreach ($r->artifacts() as $a) {
				if (($a['kind'] ?? '') === 'files' && (int)($a['level'] ?? -1) === 0) {
					return (int)($a['bytes'] ?? 0);
				}
			}
		}
		return 0;
	}

	/**
	 * Remove everything a failed chain run left behind. The artifacts collected
	 * so far are deleted by their recorded paths, then every artifact name this
	 * sequence number could have produced is deleted by name — an engine can
	 * write its file and throw before the artifact is recorded. The manifest is
	 * restored to its pre-run state ($manifest_before), or deleted when the run
	 * was starting a brand-new chain and there was no pre-run state to restore.
	 *
	 * An artifact that streamed to the bucket has no local file to delete; it
	 * has an object, which is deleted where the credential can delete (the
	 * site profile). Under the manager profile's write-only credential the
	 * object stays until its chain is pruned whole — a bounded orphan the
	 * manifest never names, the same one a failed upload_chain() can leave.
	 *
	 * Every step is best-effort: this runs on the failure path, and the failure
	 * being reported must stay the real one.
	 */
	private static function discard_failed_run($chain_d, $seq, array $artifacts, $manifest_path, $manifest_before, ?array $plan = null) {
		foreach ($artifacts as $a) {
			if (!empty($a['path'])) { @unlink($a['path']); }
		}
		if ($plan !== null && !empty($plan['prunes_cloud'])) {
			foreach ($artifacts as $a) {
				if (empty($a['key']) || !empty($a['path'])) { continue; }
				try {
					list($creds, $bucket) = self::destination($plan);
					S3Signer::delete($creds, $bucket, '/' . ltrim($a['key'], '/'));
				} catch (\Throwable $e) {
					error_log('BackupRunner: could not delete ' . $a['key'] . ' after a failed run: ' . $e->getMessage());
				}
			}
		}
		foreach (BackupChain::KINDS as $kind) {
			@unlink($chain_d . '/' . BackupChain::artifact_name($kind, $seq, true));
			@unlink($chain_d . '/' . BackupChain::artifact_name($kind, $seq, false));
		}
		if ($manifest_before === null) {
			@unlink($manifest_path);
			self::remove_empty_chain_dir($chain_d);
			return;
		}
		try {
			BackupChain::write($manifest_before, $manifest_path);
		} catch (\Throwable $e) {
			// A manifest that cannot be put back must not stay one run ahead of
			// the bucket. With it gone (and the snapshot already cleared) the
			// next run starts a fresh chain, which is the safe direction.
			@unlink($manifest_path);
		}
		self::remove_empty_chain_dir($chain_d);
	}

	/**
	 * A failed run must not leave an empty chain directory in backup storage.
	 * rmdir refuses a directory with anything in it, so this can only ever
	 * remove a husk — a run that failed before producing an artifact. Left
	 * alone they accumulate one per failed run (159 were standing on the dev
	 * shelf when this was written) and make backup storage unreadable.
	 */
	private static function remove_empty_chain_dir($chain_d) {
		@rmdir($chain_d);
	}

	/**
	 * Archive the file tree, incrementally when the chain is being extended,
	 * streaming the encrypted archive straight into the bucket.
	 *
	 * The engine's stdout IS the archive (`--archive -`); its verdict on
	 * itself arrives afterwards in the report file (`--report`), because tar's
	 * exit status is known only once its output has closed. The upload is
	 * completed only when the report says the archive is whole: exit 0, tar 0
	 * or 1 (a file changed while being read — normal on a live tree), openssl
	 * 0, and at least 64 bytes sent. An openssl envelope around an empty
	 * stream is 32 bytes; whatever produced fewer than 64 was not tar archiving
	 * this tree, and a backup of nothing is never recorded as a backup.
	 */
	private static function run_files_engine(array $plan, $chain_id, $chain_d, $seq, $snar, $key_file, $exclude_file = '') {
		$tools  = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';
		$name   = BackupChain::artifact_name('files', $seq);
		$report = $chain_d . '/.files-report-' . getmypid();

		$cmd = 'bash ' . escapeshellarg($tools . '/backup_files.sh')
			. ' ' . escapeshellarg($plan['project'])
			. ' --project-dir ' . escapeshellarg($plan['project_dir'] ?? PathHelper::getSiteRoot())
			. ' --archive - --report ' . escapeshellarg($report)
			. ' --snar ' . escapeshellarg($snar)
			. ' --key-file ' . escapeshellarg($key_file);

		foreach (self::extra_excludes() as $x) {
			$cmd .= ' --exclude ' . escapeshellarg($x);
		}
		// Every cloud blob's local paths: the objects index accounts for them,
		// so they never enter an archive — not even while they wait on disk
		// for a shelf.
		if ($exclude_file !== '') {
			$cmd .= ' --exclude-from ' . escapeshellarg($exclude_file);
		}

		$artifact = self::stream_engine($plan, $cmd, $report, $chain_id . '/', $name, 'files',
			function (array $r, $bytes) {
				$tar = (int)($r['TAR_RC'] ?? 2);
				$enc = (int)($r['ENC_RC'] ?? 1);
				if ($enc !== 0) { return 'encrypting the archive failed (openssl exit ' . $enc . ')'; }
				if ($tar !== 0 && $tar !== 1) { return 'the archive failed (tar exit ' . $tar . ')'; }
				if ($bytes < self::MIN_ARCHIVE_BYTES) {
					return 'the archive is ' . $bytes . ' bytes — nothing was archived (tar exit ' . $tar
						. '). Refusing to record an empty backup';
				}
				return '';
			});
		$artifact['level'] = (int)($artifact['report']['LEVEL'] ?? 1);
		unset($artifact['report']);
		return $artifact;
	}

	/**
	 * Fewer bytes than this is not an archive. A gzipped tar holding even one
	 * entry is longer; an openssl envelope around an empty stream is 32 bytes.
	 */
	const MIN_ARCHIVE_BYTES = 64;

	/**
	 * Run an engine in stream mode and put its stdout in the bucket as one
	 * object, with nothing landing on disk.
	 *
	 * The engine's stdout goes to S3Signer::put_stream() with completion
	 * deferred; when the stream closes the process is reaped, its report file
	 * read, and $accept asked whether the archive is whole — it returns '' to
	 * complete the upload, or the reason to refuse it. A refused upload is
	 * aborted, so nothing partial or empty is ever in backup storage, and the
	 * refusal is thrown with the engine's stderr tail. The exit status is
	 * checked before $accept: an engine that exited non-zero is refused whatever
	 * its report says.
	 *
	 * Returns the artifact: name, key, bytes, sha256, kind, and the parsed
	 * report under 'report' for the caller to read LEVEL and the like. No path.
	 *
	 * @param callable $accept function(array $report, int $bytes): string
	 */
	private static function stream_engine(array $plan, $cmd, $report_file, $sub, $name, $kind, callable $accept) {
		list($creds, $bucket, $base_key) = self::destination($plan);
		$key = $base_key . $sub . $name;

		$err_file = tempnam(sys_get_temp_dir(), 'jy_engine_err_');
		@chmod($err_file, 0600);
		@unlink($report_file);

		$descriptors = array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('pipe', 'w'),
			2 => array('file', $err_file, 'w'),
		);
		$proc = @proc_open(array('bash', '-c', $cmd), $descriptors, $pipes);
		if (!is_resource($proc)) {
			@unlink($err_file);
			throw new BackupRunnerException('The ' . $kind . ' engine could not be started.');
		}

		$resp = null;
		$failure = null;
		try {
			$resp = S3Signer::put_stream($creds, $bucket, '/' . ltrim($key, '/'), $pipes[1], 'application/octet-stream', false);
		} catch (\Throwable $e) {
			$failure = $e;
		}
		// Whatever became of the upload, the engine is reaped: closing its
		// stdout ends a producer still writing, and proc_close collects the
		// status. Only then are the report and stderr complete.
		@fclose($pipes[1]);
		$rc = (int)proc_close($proc);
		$stderr = (string)@file_get_contents($err_file);
		@unlink($err_file);
		$report = is_file($report_file) ? self::parse_kv((string)@file_get_contents($report_file)) : array();
		@unlink($report_file);

		if ($failure !== null) {
			throw new BackupRunnerException('Streaming ' . $name . ' to the bucket failed: ' . $failure->getMessage()
				. ($stderr !== '' ? ' | engine: ' . self::tail($stderr) : ''));
		}
		if (empty($resp['pending'])) {
			// A part was refused; put_stream() has already aborted the upload.
			$msg = S3Signer::extract_error($resp['body'] ?? '') ?: ('HTTP ' . (int)($resp['status'] ?? 0));
			throw new BackupRunnerException('Upload of ' . $name . ' failed: ' . $msg
				. ($rc !== 0 ? ' (the ' . $kind . ' engine exited ' . $rc . ')' : ''));
		}

		$bytes = (int)$resp['bytes'];
		if ($rc !== 0) {
			// The report, when there is one, says which stage failed; the exit
			// status alone does not.
			$why = 'the ' . $kind . ' engine exited ' . $rc;
			$reason = $report ? (string)$accept($report, $bytes) : '';
			if ($reason !== '') { $why .= ': ' . $reason; }
		} elseif (!$report) {
			$why = 'the ' . $kind . ' engine wrote no report';
		} else {
			$why = (string)$accept($report, $bytes);
		}
		if ($why !== '') {
			S3Signer::abort_stream($resp['pending']);
			throw new BackupRunnerException(ucfirst($why) . '. ' . self::tail($stderr));
		}

		$final = S3Signer::complete_stream($resp['pending']);
		$status = (int)($final['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			$msg = S3Signer::extract_error($final['body'] ?? '') ?: ('HTTP ' . $status);
			throw new BackupRunnerException('Upload of ' . $name . ' failed: ' . $msg);
		}

		// Recorded from the hash taken as the bytes went up: the ledger's claim
		// — this machine made these bytes — holds exactly as for a file.
		if (!BackupLedger::record_hash($plan['profile'], $sub . $name, (string)$final['sha256'], $bytes, $key)) {
			self::report_unledgered(array($name));
		}

		return array(
			'name'   => $name,
			'key'    => $key,
			'bytes'  => $bytes,
			'sha256' => (string)$final['sha256'],
			'kind'   => $kind,
			'report' => $report,
		);
	}

	/**
	 * Dump the database in full, as its own artifact, on every run — streamed
	 * straight to the bucket as db-{seq}.sql.gz.enc, the object key from the
	 * start. The upload completes only when pg_dump exited 0 and openssl 0: a
	 * dump that failed part-way is never in backup storage.
	 */
	private static function run_db_engine(array $plan, $chain_id, $chain_d, $seq, $key_file) {
		$name   = BackupChain::artifact_name('db', $seq);
		$report = $chain_d . '/.db-report-' . getmypid();
		$cmd = self::database_stream_command($plan, $key_file, $report);
		$artifact = self::stream_engine($plan, $cmd, $report, $chain_id . '/', $name, 'db', array(__CLASS__, 'accept_dump'));
		unset($artifact['report']);
		return $artifact;
	}

	/** The database engine in stream mode: encrypted dump on stdout, verdict in the report. */
	private static function database_stream_command(array $plan, $key_file, $report) {
		$tools = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';
		$db    = $plan['database'] ?? self::database_name();
		return 'bash ' . escapeshellarg($tools . '/backup_database.sh')
			. ' --non-interactive --key-file ' . escapeshellarg($key_file)
			. ' --archive - --report ' . escapeshellarg($report)
			. ' ' . escapeshellarg($db);
	}

	/** The stream_engine() acceptance rule for a dump: pg_dump 0, openssl 0, and more than an empty envelope. */
	public static function accept_dump(array $r, $bytes) {
		$dump = (int)($r['DUMP_RC'] ?? 1);
		$enc  = (int)($r['ENC_RC'] ?? 1);
		if ($dump !== 0) { return 'the database dump failed (pg_dump exit ' . $dump . ')'; }
		if ($enc !== 0) { return 'encrypting the dump failed (openssl exit ' . $enc . ')'; }
		if ($bytes < self::MIN_ARCHIVE_BYTES) {
			return 'the dump is ' . $bytes . ' bytes — nothing was dumped. Refusing to record an empty backup';
		}
		return '';
	}

	/**
	 * The bits a restore needs that are not in the project tree: the site's
	 * SHAPE, the Apache virtualhost, and a note of what this run was. Small, and
	 * rewritten every run rather than made incremental — there is nothing to save.
	 *
	 * The virtualhost travels for reference, not for reinstallation. A restore
	 * always regenerates the serving config from the platform's own templates
	 * and only keeps this copy beside the live file when the two differ, because
	 * a container's internal virtualhost on a plain server is a site with no
	 * HTTPS — which is exactly how a rebuild drill lost its certificate.
	 */
	private static function build_meta(array $plan, $chain_d, $seq, $key_file) {
		$stage = $chain_d . '/.meta-' . getmypid();
		if (!@mkdir($stage . '/apache_config', 0700, true)) {
			return null;
		}

		$vhost_captured = false;
		foreach (array(
			'/etc/apache2/sites-available/' . $plan['project'] . '.conf',
			'/etc/httpd/conf.d/' . $plan['project'] . '.conf',
		) as $vhost) {
			if (is_readable($vhost)) {
				$vhost_captured = @copy($vhost, $stage . '/apache_config/' . basename($vhost));
				break;
			}
		}

		// shape.json: the machine-readable answer to "what was this site running
		// on". A restore reads it to say what it is landing on versus what it came
		// from, and never has to guess. Written by the same script that reads it
		// back on the way in, so the archive path and the chain path cannot
		// describe a site differently.
		$shaper = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/reconcile_site.sh';
		if (is_file($shaper)) {
			$shape_cmd = 'bash ' . escapeshellarg($shaper)
				. ' ' . escapeshellarg($plan['project'])
				. ' --print-shape'
				. ' --site-dir ' . escapeshellarg(PathHelper::getSiteRoot())
				. ' --vhost-captured ' . ($vhost_captured ? 'yes' : 'no')
				. ' --out ' . escapeshellarg($stage . '/shape.json');
			$sh_out = array(); $sh_rc = 0;
			exec($shape_cmd . ' 2>&1', $sh_out, $sh_rc);
			if ($sh_rc !== 0) {
				// A backup without a shape is restorable — the restore treats it as
				// shape-unknown and reconciles against the target anyway — so this
				// is never a reason to fail the run.
				error_log('BackupRunner: could not record the site shape; continuing without it.');
			}
		}

		@file_put_contents($stage . '/backup_info.txt',
			"Project: {$plan['project']}\nSlug: {$plan['slug']}\nRun: {$seq}\n"
			. 'Taken: ' . gmdate('Y-m-d H:i:s') . " UTC\n");

		$target = $chain_d . '/' . BackupChain::artifact_name('meta', $seq);
		$cmd = '( set -o pipefail; tar -czf - -C ' . escapeshellarg($stage) . ' . '
			 . '| openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 -out ' . escapeshellarg($target)
			 . ' ) 3< ' . escapeshellarg($key_file);

		$out = array(); $rc = 0;
		exec('bash -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $rc);
		self::rmtree($stage);

		if ($rc !== 0 || !is_file($target)) {
			error_log('BackupRunner: metadata artifact failed; continuing without it.');
			return null;
		}
		@chmod($target, 0600);

		return array(
			'name'   => basename($target),
			'path'   => $target,
			'bytes'  => (int)filesize($target),
			'sha256' => hash_file('sha256', $target),
			'kind'   => 'meta',
		);
	}

	/** Upload what a run made on disk plus the rewritten manifest; streamed artifacts pass through keyed. */
	private static function upload_chain(array $plan, $chain_id, array $artifacts, $manifest_path) {
		$to_send = array_values($artifacts);
		$to_send[] = array(
			'name'  => BackupChain::MANIFEST_NAME,
			'path'  => $manifest_path,
			'bytes' => (int)filesize($manifest_path),
			'kind'  => 'manifest',
		);
		return self::upload($plan, $to_send, $chain_id . '/');
	}

	/**
	 * Retention over CHAINS, not runs.
	 *
	 * A chain is kept or deleted whole. Deleting the oldest few runs of a chain
	 * would leave incrementals whose full is gone — which is not a smaller
	 * backup, it is no backup, and it would look like a restore point right up
	 * until someone needed it.
	 *
	 * A plan that does not prune the bucket (the manager profile) removes the
	 * records only, by the same rule and window: the management node deletes
	 * the objects, so this site's list must stop naming them. Records removed
	 * that way are not counted as pruned — this machine deleted nothing.
	 */
	public static function enforce_chain_retention(array $plan, ?array &$pruned_indexes = null) {
		if (empty($plan['keep_days'])) {
			return 0;
		}
		$deletes = !empty($plan['prunes_cloud']);
		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'offsite' => true, 'deleted' => false, 'slug' => $plan['slug'],
			      'chained' => true, 'profile' => $plan['profile']),
			array('bkh_start_time' => 'DESC'), 1000, 0);
		$rows->load();

		// Newest chain first, preserving the order rows came back in.
		$chains = array();
		foreach ($rows as $r) {
			$cid = (string)$r->get('bkh_chain_id');
			if ($cid === '') { continue; }
			if (!isset($chains[$cid])) { $chains[$cid] = array(); }
			$chains[$cid][] = $r;
		}

		// A chain started when its oldest run did; rows came back newest first.
		$points = array();
		foreach ($chains as $cid => $chain_rows) {
			$points[] = array('item' => $cid,
				'time' => (int)strtotime(end($chain_rows)->get('bkh_start_time') . ' UTC'));
		}
		$surplus = self::surplus($points, $plan['keep_days'], time());
		if (!$surplus) {
			return 0;
		}

		if ($deletes) {
			$target = $plan['target'];
			$creds  = $target->get_credentials();
			$bucket = trim((string)$target->get('bkt_bucket'));
		}

		$pruned = 0;
		foreach ($surplus as $cid) {
			try {
				// Read what this chain's indexes name BEFORE they go: the object
				// family is pruned by exactly that (enforce_object_retention).
				if ($deletes && $pruned_indexes !== null) {
					$pruned_indexes += self::index_entries_of_rows($plan, $chains[$cid]);
				}
				foreach ($deletes ? $chains[$cid] : array() as $row) {
					foreach ($row->object_keys() as $key) {
						$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
						$status = (int)($resp['status'] ?? 0);
						if (($status < 200 || $status >= 300) && $status !== 404) {
							throw new BackupRunnerException('HTTP ' . $status . ' deleting ' . $key);
						}
					}
				}
				// Only once every object of the chain is gone are its rows
				// marked deleted — a half-deleted chain must keep looking like
				// a chain that still needs deleting, not like one that is done.
				// Pruned as well as deleted so the history shows a cleaned-up
				// chain rather than dropping it (a manual hide sets only delete).
				$now = gmdate('Y-m-d H:i:s');
				foreach ($chains[$cid] as $row) {
					$row->set('bkh_pruned_time', $now);
					$row->set('bkh_delete_time', $now);
					$row->save();
				}
				self::rmtree(self::chain_dir($plan, $cid));
				$pruned++;
			} catch (\Throwable $e) {
				error_log('BackupRunner: chain retention failed for ' . $cid . ': ' . $e->getMessage());
			}
		}
		return $deletes ? $pruned : 0;
	}

	// ------------------------------------------------------------------ full

	private static function execute_full(array $plan, BackupHistory $history) {
		$dir = $plan['output_dir'];
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
			throw new BackupRunnerException("The backup directory {$dir} does not exist and could not be created.");
		}
		if (!is_writable($dir)) {
			throw new BackupRunnerException("The backup directory {$dir} is not writable by " . self::whoami() . '.');
		}

		$refusal = self::preflight_refusal(self::local_need($plan, '', ''), self::free_bytes($plan, $dir), 0);
		if ($refusal !== '') {
			throw new BackupRunnerException($refusal);
		}

		$objects = null;
		if ($plan['type'] === 'project') {
			// The archive is named before it is made (its envelope is minted for
			// the name), so the index that goes with it can be named here too.
			$name     = $plan['project'] . '-' . gmdate('Ymd_His');
			$objects  = self::begin_objects($plan, $name . '.tar.gz.enc');
			try {
				$artifacts = self::stream_standalone_project($plan, $dir, $name, $objects ? $objects['exclude'] : '');
			} finally {
				if ($objects && $objects['exclude'] !== '') { @unlink($objects['exclude']); }
			}
			if ($objects) {
				$artifacts[] = self::write_index_artifact($plan, $objects, $dir . '/' . BackupNaming::index_for_archive($artifacts[0]['name']));
			}
		} else {
			$artifacts = self::stream_standalone_database($plan, $dir);
		}
		$archive_name  = $artifacts[0]['name'];
		$archive_bytes = (int)$artifacts[0]['bytes'];

		$uploaded = self::upload($plan, $artifacts);
		$history->set_artifacts($uploaded);
		$history->set('bkh_upload_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_outcome', 'success');
		$history->set('bkh_finish_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_message', 'Backed up ' . $archive_name . ($objects ? self::objects_note($objects) : ''));
		$history->save();

		if ($plan['delete_local']) {
			// A streamed archive has no local copy; what is left is the envelope
			// sidecar beside where it would have been, and the objects index.
			foreach ($artifacts as $a) {
				if (!empty($a['path'])) { @unlink($a['path']); }
			}
		}

		$released = $objects ? self::finish_objects($plan, $objects) : 0;

		// Only now, with this run safely offsite, is it sound to delete anything.
		// Chains are pruned here too, so a site switched from chain mode to full
		// still ages its old chains out — whole, via the chain-atomic pass.
		$pruned_indexes = array();
		$pruned = self::enforce_cloud_retention($plan, $pruned_indexes) + self::enforce_chain_retention($plan, $pruned_indexes);
		$objects_pruned = self::enforce_object_retention($plan, $pruned_indexes);
		$swept  = self::sweep_local($plan);

		$msg = 'Backed up ' . $archive_name . ' (' . self::human($archive_bytes) . ')'
			. ' to ' . $plan['target']->get('bkt_name');
		if ($objects) { $msg .= self::objects_message($objects, $released); }
		if ($pruned) { $msg .= "; pruned {$pruned} old restore point" . ($pruned === 1 ? '' : 's'); }
		if ($objects_pruned) { $msg .= "; removed {$objects_pruned} offloaded file" . ($objects_pruned === 1 ? '' : 's') . ' no kept backup names'; }
		if ($swept)  { $msg .= "; swept {$swept} local file" . ($swept === 1 ? '' : 's'); }

		// A standalone archive is whole by construction: level 0.
		return array('status' => 'success', 'message' => $msg, 'level' => 0, 'bytes' => $archive_bytes);
	}

	/**
	 * A standalone whole-site archive, streamed: backup_project.sh archives the
	 * staged dump, apache_config/ and shape.json together with the LIVE tree in
	 * one tar, piped through openssl to stdout, and stream_engine() puts it in
	 * the bucket as it flows. No copy of the site is made and no archive lands
	 * on disk; the staging directory holds the compressed dump for the length
	 * of the tar and is removed with it.
	 *
	 * The object is named here, before the engine runs, so the envelope can be
	 * minted for it and its sidecar written beside where the archive would
	 * have been — the sidecar is the one artifact of this run that is a file.
	 *
	 * Returns [archive artifact (streamed: key, no path), envelope artifact (path)].
	 */
	private static function stream_standalone_project(array $plan, $dir, $name = '', $exclude_file = '') {
		$tools  = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';
		if ($name === '') { $name = $plan['project'] . '-' . gmdate('Ymd_His'); }
		$object = $name . '.tar.gz.enc';

		$mint     = BackupEnvelope::mint($object, $plan['recipients']);
		$key_file = $dir . '/.jy_selfbackup_' . getmypid() . '.key';
		$report   = $dir . '/.project-report-' . getmypid();
		self::write_private($key_file, $mint['data_key']);

		$cmd = 'bash ' . escapeshellarg($tools . '/backup_project.sh')
			. ' ' . escapeshellarg($plan['project'])
			. ' --non-interactive --output-dir ' . escapeshellarg($dir)
			. ' --key-file ' . escapeshellarg($key_file)
			. ' --name ' . escapeshellarg($name)
			. ' --archive - --report ' . escapeshellarg($report);
		if (!empty($plan['project_dir'])) {
			$cmd .= ' --project-dir ' . escapeshellarg($plan['project_dir']);
		}
		if ($exclude_file !== '') {
			$cmd .= ' --exclude-from ' . escapeshellarg($exclude_file);
		}

		try {
			$artifact = self::stream_engine($plan, $cmd, $report, '', $object, 'archive',
				function (array $r, $bytes) {
					$tar = (int)($r['TAR_RC'] ?? 2);
					$enc = (int)($r['ENC_RC'] ?? 1);
					if ($enc !== 0) { return 'encrypting the archive failed (openssl exit ' . $enc . ')'; }
					if ($tar !== 0 && $tar !== 1) { return 'the archive failed (tar exit ' . $tar . ')'; }
					if ($bytes < self::MIN_ARCHIVE_BYTES) {
						return 'the archive is ' . $bytes . ' bytes — nothing was archived (tar exit ' . $tar
							. '). Refusing to record an empty backup';
					}
					return '';
				});
		} finally {
			self::shred($key_file);
		}
		unset($artifact['report']);

		$sidecar = $dir . '/' . $object . BackupEnvelope::SIDECAR_SUFFIX;
		BackupEnvelope::write_sidecar($sidecar, $mint['envelope']);

		return array(
			$artifact,
			array('name' => basename($sidecar), 'path' => $sidecar,
			      'bytes' => (int)@filesize($sidecar), 'kind' => 'envelope'),
		);
	}

	/**
	 * A standalone database dump, streamed: named the way the engine names a
	 * dump ({database}-{stamp}.sql.gz.enc), so nothing that reads backup storage can
	 * tell it from one the engine wrote. The envelope is minted for the name up
	 * front and its sidecar is the run's one file.
	 *
	 * Returns [archive artifact (streamed: key, no path), envelope artifact (path)].
	 */
	private static function stream_standalone_database(array $plan, $dir) {
		$db     = $plan['database'] ?? self::database_name();
		$object = $db . '-' . gmdate('Ymd_His') . '.sql.gz.enc';

		$mint     = BackupEnvelope::mint($object, $plan['recipients']);
		$key_file = $dir . '/.jy_selfbackup_' . getmypid() . '.key';
		$report   = $dir . '/.db-report-' . getmypid();
		self::write_private($key_file, $mint['data_key']);

		try {
			$artifact = self::stream_engine($plan, self::database_stream_command($plan, $key_file, $report),
				$report, '', $object, 'archive', array(__CLASS__, 'accept_dump'));
		} finally {
			self::shred($key_file);
		}
		unset($artifact['report']);

		$sidecar = $dir . '/' . $object . BackupEnvelope::SIDECAR_SUFFIX;
		BackupEnvelope::write_sidecar($sidecar, $mint['envelope']);

		return array(
			$artifact,
			array('name' => basename($sidecar), 'path' => $sidecar,
			      'bytes' => (int)@filesize($sidecar), 'kind' => 'envelope'),
		);
	}

	/**
	 * Where a run's objects go: the target's credentials, its bucket, and the
	 * key prefix every artifact of this profile is filed under.
	 *
	 * The profile segment is what stops two parties' backups landing in one
	 * pile: without it a listing cannot tell whose backup is whose, and
	 * neither party's retention can reason about backup storage it is responsible
	 * for.
	 */
	private static function destination(array $plan) {
		$target = $plan['target'];
		$creds  = $target->get_credentials();
		if (empty($creds)) {
			throw new BackupRunnerException('The backup target has no stored credentials.');
		}
		$bucket = trim((string)$target->get('bkt_bucket'));
		if ($bucket === '') {
			throw new BackupRunnerException('The backup target has no bucket configured.');
		}
		$prefix = rtrim(trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups', '/');
		$base_key = $prefix . '/' . $plan['slug'] . '/' . BackupProfile::path_segment($plan['profile']) . '/';
		return array($creds, $bucket, $base_key);
	}

	/**
	 * Upload a run's file artifacts and return every artifact keyed, in order.
	 * An artifact that streamed to the bucket already carries its key and no
	 * path; it is passed through as it is.
	 */
	private static function upload(array $plan, array $artifacts, $sub = '') {
		list($creds, $bucket, $base_key) = self::destination($plan);

		$out = array();
		$unledgered = array();
		foreach ($artifacts as $a) {
			if (!empty($a['key'])) {
				$out[] = $a;
				continue;
			}
			$key = $base_key . $sub . $a['name'];
			$resp = S3Signer::put_file($creds, $bucket, '/' . ltrim($key, '/'), $a['path']);
			$status = (int)($resp['status'] ?? 0);
			if ($status < 200 || $status >= 300) {
				$msg = S3Signer::extract_error($resp['body'] ?? '') ?: ('HTTP ' . $status);
				throw new BackupRunnerException('Upload of ' . $a['name'] . ' failed: ' . $msg);
			}

			// Record what went up, hashed from the file that went up, on the
			// machine that made it. This is the only moment the bytes and the
			// name are together somewhere no management node has ever been, so
			// it is the only moment a record of the pairing is worth anything.
			// The relative name is what a later download will ask for, chain
			// subdirectory included.
			if (!BackupLedger::record($plan['profile'], $sub . $a['name'], $a['path'], $key)) {
				$unledgered[] = $a['name'];
			}

			$a['key'] = $key;
			$out[] = $a;
		}

		if ($unledgered) {
			self::report_unledgered($unledgered);
		}

		return $out;
	}

	/**
	 * Said out loud rather than logged quietly. An artifact that reached the
	 * bucket but was not recorded here is a real backup that cannot be
	 * restored over the agent channel — the node refuses an archive it has
	 * no record of making — and the operator should learn that now rather
	 * than during a restore. The usual cause is a backup run by an
	 * unprivileged user: the ledger is root-owned on purpose.
	 */
	private static function report_unledgered(array $names) {
		error_log('BackupRunner: uploaded but NOT recorded in the integrity ledger ('
			. BackupLedger::dir() . ' is not writable by ' . self::whoami() . '): '
			. implode(', ', $names)
			. ' — these archives cannot be restored over the agent channel.');
	}

	// ---------------------------------------------------------------- objects

	/**
	 * The object-store steps a run takes BEFORE its archive, for a profile
	 * that stores offloaded files (plan['objects']): what backup storage holds, the
	 * archive's exclude list, the store step, and — site profile, after a
	 * recovery-key rotation — the re-seal of older epoch envelopes.
	 *
	 * Returns the run's objects context, or null when this run does not carry
	 * them (a database-only backup; a manager run whose request did not ask).
	 * Anything thrown here fails the run under the existing discard rule;
	 * objects already uploaded stay, content-addressed and correct, and the
	 * next run finds them held.
	 */
	private static function begin_objects(array $plan, $run_label) {
		if (empty($plan['objects'])) {
			return null;
		}
		$objects = BackupObjects::cloud_objects();
		$picture = BackupObjects::held($plan, self::previous_index_key($plan));
		$held    = $picture['held'];

		$project_dir = $plan['project_dir'] ?? PathHelper::getSiteRoot();
		$exclude = BackupObjects::write_exclude_file($plan, $objects, $project_dir);

		$epoch = null;
		$epoch_fn = function () use ($plan, &$epoch) {
			if ($epoch === null) { $epoch = BackupObjects::epoch($plan); }
			return $epoch;
		};
		$store = BackupObjects::store_missing($plan, $epoch_fn, $objects, $held,
			self::OBJECT_STORE_BUDGET_BYTES, self::OBJECT_STORE_BUDGET_SECONDS, $picture['unhashed']);

		// Older epochs sealed to a previous recovery key are re-sealed to the
		// current one: the site profile reads their envelopes off its backup storage,
		// the manager profile reads the ones its request linked.
		$resealed = array('resealed' => array(), 'unopenable' => array());
		$examined = false;
		if (($plan['objects_source'] ?? 'listing') === 'listing') {
			if (!empty($picture['shelf']['envelopes'])) {
				$resealed = BackupObjects::reseal_epochs($plan, BackupObjects::envelopes_site($plan, $picture['shelf']));
			}
			$examined = is_array($picture['shelf'] ?? null);
		} elseif (!empty($plan['epoch_envelope_urls'])) {
			$resealed = BackupObjects::reseal_epochs($plan, BackupObjects::envelopes_from_links($plan, $plan['epoch_envelope_urls']));
			$examined = true;
		}
		// What Recovery Readiness reports: the epochs only a retired recovery
		// key opens, as this run found them. Written whenever the run looked.
		if ($examined) {
			try {
				BackupObjects::write_retired_epochs($plan, $resealed['unopenable']);
			} catch (\Throwable $e) {
				error_log('BackupRunner: could not record the retired epochs: ' . $e->getMessage());
			}
		}

		// A manager run whose request carried the object store: from here the
		// manager profile holds local bytes until its backup storage has them.
		if ($plan['profile'] === BackupProfile::MANAGER) {
			BackupObjects::mark_enabled($plan);
		}

		return array(
			'label'     => (string)$run_label,
			'objects'   => $objects,
			'held'      => $held,
			'held_file' => $picture['file'],
			'stored'    => $store['stored'],
			'store'     => $store,
			'exclude'   => $exclude,
			'epoch'     => $epoch,
			'resealed'  => $resealed,
			'index'     => null,
		);
	}

	/** Step 6: the index, from the enumeration, what was held and what this run stored. */
	private static function write_index_artifact(array $plan, array &$ctx, $path) {
		$index = BackupObjects::build_index($plan, $ctx['label'], $ctx['objects'], $ctx['held'], $ctx['stored']);
		$ctx['index'] = $index;
		return BackupObjects::write_index($index, $path);
	}

	/**
	 * Steps 8 and 9, after the run is committed: held.json from what was held
	 * plus what this run stored, then the release of every cloud blob's local
	 * bytes that every enabled profile now holds. Returns how many were
	 * released. Never fails a run that is already offsite.
	 */
	private static function finish_objects(array $plan, array $ctx) {
		try {
			BackupObjects::write_held($plan, $ctx['stored'] + $ctx['held'], array_keys($ctx['held_file']));
		} catch (\Throwable $e) {
			error_log('BackupRunner: could not record the held set: ' . $e->getMessage());
		}
		try {
			return BackupObjects::release_waiting($ctx['objects'], BackupProfile::enabled(), $plan['base_dir']);
		} catch (\Throwable $e) {
			error_log('BackupRunner: could not release local copies of offloaded files: ' . $e->getMessage());
			return 0;
		}
	}

	/** What the history row says about the run's offloaded files. */
	private static function objects_note(array $ctx) {
		$total  = count($ctx['objects']);
		if ($total === 0) {
			return '';
		}
		$store  = $ctx['store'];
		$stored = count($ctx['stored']);
		$on_shelf = 0;
		foreach (($ctx['index']['objects'] ?? array()) as $e) { if (!empty($e['stored'])) { $on_shelf++; } }
		$note = ' — ' . $on_shelf . ' of ' . $total . ' offloaded file' . ($total === 1 ? '' : 's') . ' in backup storage';
		if ($stored) { $note .= ', ' . $stored . ' copied this run (' . self::human($store['bytes']) . ')'; }
		if (!empty($store['budget_hit'])) { $note .= ', budget reached'; }
		if (!empty($store['failed'])) { $note .= ', ' . $store['failed'] . ' failed'; }
		if (!empty($store['fetch_failed'])) { $note .= ', ' . $store['fetch_failed'] . ' not in the file store'; }
		return $note;
	}

	/** The task message's clause about offloaded files. */
	private static function objects_message(array $ctx, $released) {
		$msg = '';
		$stored = count($ctx['stored']);
		if ($stored) {
			$msg .= "; copied {$stored} offloaded file" . ($stored === 1 ? '' : 's') . ' (' . self::human($ctx['store']['bytes']) . ') to backup storage';
		}
		if (!empty($ctx['store']['budget_hit'])) {
			$left = 0;
			foreach (($ctx['index']['objects'] ?? array()) as $e) { if (empty($e['stored'])) { $left++; } }
			$msg .= "; {$left} still to copy (budget reached)";
		}
		if (!empty($ctx['store']['fetch_failed'])) {
			$msg .= '; ' . $ctx['store']['fetch_failed'] . ' offloaded file' . ($ctx['store']['fetch_failed'] === 1 ? '' : 's')
				. ' could not be read from the file store';
		}
		if ($released) { $msg .= "; released {$released} local cop" . ($released === 1 ? 'y' : 'ies'); }
		if (!empty($ctx['resealed']['resealed'])) { $msg .= '; re-sealed ' . count($ctx['resealed']['resealed']) . ' epoch envelope(s) to the current recovery key'; }
		if (!empty($ctx['resealed']['unopenable'])) {
			$n = count($ctx['resealed']['unopenable']);
			$msg .= "; {$n} epoch envelope" . ($n === 1 ? '' : 's') . ' (' . implode(', ', $ctx['resealed']['unopenable'])
				. ') open' . ($n === 1 ? 's' : '') . ' only with a retired recovery key — keep that key';
		}
		return $msg;
	}

	/**
	 * The bucket key of the newest objects index this profile committed, from
	 * this site's own history — chain runs and standalone fulls alike — or ''
	 * when there is none. Read from history rather than from the local chain
	 * manifest so a machine restored from a backup (backups/ is in no archive)
	 * still finds the hashes its backup storage's objects were recorded with.
	 */
	private static function previous_index_key(array $plan) {
		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'offsite' => true, 'deleted' => false, 'slug' => $plan['slug'],
			      'profile' => $plan['profile']),
			array('bkh_start_time' => 'DESC'), 50, 0);
		foreach ($rows as $r) {
			foreach ($r->artifacts() as $a) {
				if (($a['kind'] ?? '') === 'objects' && !empty($a['key'])) {
					return (string)$a['key'];
				}
			}
		}
		return '';
	}

	/** The stored objects of every objects index these history rows carry, keyed by shelf location (epoch/name). */
	private static function index_entries_of_rows(array $plan, array $rows) {
		if (empty($plan['objects'])) {
			return array();
		}
		$out = array();
		foreach ($rows as $row) {
			foreach ($row->artifacts() as $a) {
				if (($a['kind'] ?? '') !== 'objects' || empty($a['key'])) { continue; }
				$index = BackupObjects::fetch_index_key($plan, (string)$a['key']);
				if ($index !== null) {
					$out += BackupObjects::index_locations($index);
				}
			}
		}
		return $out;
	}

	/**
	 * Site shelf, third retention family: once chains and standalone fulls
	 * have been pruned, every object their indexes named that no retained
	 * run's index names is deleted. Driven by this site's own records, as its
	 * other retention is. Returns objects deleted.
	 */
	public static function enforce_object_retention(array $plan, array $pruned_indexes) {
		if (empty($plan['prunes_cloud']) || empty($plan['objects']) || !$pruned_indexes) {
			return 0;
		}
		try {
			return BackupObjects::prune_site($plan, $pruned_indexes, self::retained_index_keys($plan));
		} catch (\Throwable $e) {
			error_log('BackupRunner: object retention failed: ' . $e->getMessage());
			return 0;
		}
	}

	/**
	 * Bucket keys of the retained runs' indexes: the newest index of each
	 * retained chain first, then every standalone full's, then the older chain
	 * runs — so a live object is usually cleared by the first few reads.
	 */
	private static function retained_index_keys(array $plan) {
		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'offsite' => true, 'deleted' => false, 'slug' => $plan['slug'],
			      'profile' => $plan['profile']),
			array('bkh_start_time' => 'DESC'), 1500, 0);
		$first = array(); $rest = array(); $seen_chain = array();
		foreach ($rows as $r) {
			$key = '';
			foreach ($r->artifacts() as $a) {
				if (($a['kind'] ?? '') === 'objects' && !empty($a['key'])) { $key = (string)$a['key']; }
			}
			if ($key === '') { continue; }
			$cid = (string)$r->get('bkh_chain_id');
			if ($cid === '' || !isset($seen_chain[$cid])) {
				$first[] = $key;
				if ($cid !== '') { $seen_chain[$cid] = true; }
			} else {
				$rest[] = $key;
			}
		}
		return array_merge($first, $rest);
	}

	// -------------------------------------------------------------- retention

	/**
	 * Keep the restore points the retention window needs offsite; delete the
	 * objects belonging to anything older (surplus()).
	 *
	 * Driven by history rather than by a bucket listing, so it can only ever
	 * delete objects this site recorded itself as having written. A bucket
	 * listing would also sweep up another site's objects if two sites were ever
	 * pointed at one slug, and "delete everything under this prefix that looks
	 * old" is not a mistake worth risking.
	 *
	 * STANDALONE backups only. Chain rows are excluded here because deleting
	 * them one row at a time — oldest first — would take a chain's full before
	 * its incrementals, leaving restore points that look fine and restore
	 * nothing. Chains are pruned whole by enforce_chain_retention.
	 */
	public static function enforce_cloud_retention(array $plan, ?array &$pruned_indexes = null) {
		if (empty($plan['keep_days'])) {
			return 0;
		}
		// Records only for a plan that does not prune the bucket, as for chains.
		$deletes = !empty($plan['prunes_cloud']);
		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'offsite' => true, 'deleted' => false, 'slug' => $plan['slug'],
			      'chained' => false, 'profile' => $plan['profile']),
			array('bkh_start_time' => 'DESC'), 500, 0);
		$rows->load();

		$points = array();
		foreach ($rows as $r) {
			$points[] = array('item' => $r, 'time' => (int)strtotime($r->get('bkh_start_time') . ' UTC'));
		}

		$surplus = self::surplus($points, $plan['keep_days'], time());
		if (!$surplus) {
			return 0;
		}

		if ($deletes) {
			$target = $plan['target'];
			$creds  = $target->get_credentials();
			$bucket = trim((string)$target->get('bkt_bucket'));
		}

		$pruned = 0;
		foreach ($surplus as $old) {
			try {
				if ($deletes && $pruned_indexes !== null) {
					$pruned_indexes += self::index_entries_of_rows($plan, array($old));
				}
				foreach ($deletes ? $old->object_keys() : array() as $key) {
					$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
					$status = (int)($resp['status'] ?? 0);
					// 404 is success for our purposes: the object is not there,
					// which is the state we were asking for.
					if (($status < 200 || $status >= 300) && $status !== 404) {
						throw new BackupRunnerException('HTTP ' . $status . ' deleting ' . $key);
					}
				}
				$now = gmdate('Y-m-d H:i:s');
				// Soft-deleted so every "what still exists" query stops counting it,
				// and stamped pruned so the history can still show it as cleaned up
				// rather than let it vanish (a manual hide sets only delete_time).
				$old->set('bkh_pruned_time', $now);
				$old->set('bkh_delete_time', $now);
				$old->save();
				$pruned++;
			} catch (\Throwable $e) {
				// One stubborn object must not stop the sweep, and must not
				// leave a row marked pruned while its objects are still there.
				error_log('BackupRunner: retention failed for history ' . $old->key . ': ' . $e->getMessage());
			}
		}
		return $deletes ? $pruned : 0;
	}

	/**
	 * Which restore points are surplus, given the newest-first points and how
	 * many days of history to keep. Pure, and separated from the deleting
	 * because this is the decision that can lose data: it has to be checkable
	 * without a bucket, and "keep at least one, always" has to be true even when
	 * the caller passes nonsense.
	 *
	 * Each point is `['item' => mixed, 'time' => unix start]`, and the surplus
	 * comes back as the items, newest first. A point that started inside the
	 * window is kept, and so is the newest one that started before it: a
	 * restore to the window's first day replays from that one. The window is
	 * days rather than a count because a count is spent by whatever starts
	 * points — every code-tree swap starts a chain, and a count of chains
	 * shrinks to a few days of history once releases come daily.
	 */
	public static function surplus(array $points, $keep_days, $now) {
		$cutoff = (int)$now - max(1, (int)$keep_days) * 86400;
		$surplus = array();
		// The first point started before the window covers its first day, and
		// when that is the newest point it covers the whole window by itself.
		$covered = false;
		foreach ($points as $p) {
			if ((int)$p['time'] >= $cutoff) { continue; }
			if (!$covered) { $covered = true; continue; }
			$surplus[] = $p['item'];
		}
		return $surplus;
	}

	/**
	 * Prune the local directory by age. Covers the auto_pre_* snapshots restores
	 * leave behind, which is most of what accumulates: nothing else ever deleted
	 * them, and they are the same size as a full backup.
	 *
	 * The local copy is a convenience, not the archive — it lets a restore skip
	 * the download. This window says how long that convenience is worth the disk.
	 *
	 * Chain runs are swept here too, and have to be: they write one directory
	 * down (chain-<id>/files-0003.tar.gz.enc), where a single-level glob never
	 * sees them. The only other code that removes a local chain file is
	 * enforce_chain_retention(), and that returns early unless this machine
	 * prunes the bucket — which a managed node deliberately does not do, since
	 * backup storage belongs to the management node and the credential it is handed
	 * cannot delete. Age those artifacts out only here and a node running
	 * incrementals keeps every archive it has ever made, reporting 'swept 0' on
	 * every run while the disk fills.
	 *
	 * What is NOT swept from a chain directory is the point: manifest.json and
	 * the .snar snapshot are what let the chain be EXTENDED, and both are small.
	 * Without the manifest the next run reads no_chain, without the snapshot it
	 * reads snar_lost, and either way it silently starts a fresh full every
	 * night — expensive, and it looks like nothing is wrong because the backups
	 * still succeed. BackupNaming::list_dir() matches archive suffixes only, so
	 * neither is a candidate; the snapshot is not even in the directory.
	 *
	 * Age is per file, not per chain, so an old chain's early runs go while its
	 * recent runs stay. The emptied directory stays as well: deciding a chain is
	 * finished is enforce_chain_retention()'s job and belongs in one place.
	 *
	 * A window of 0 means never sweep.
	 */
	public static function sweep_local(array $plan) {
		// Temporaries of the object store — one object's ciphertext a budget or
		// an interrupt left behind — are not backups and go by their own age,
		// whatever the window says.
		$swept = BackupObjects::sweep_tmp($plan);

		$days = $plan['keep_local'];
		if ($days <= 0) {
			return $swept;
		}
		$cutoff = time() - ($days * 86400);
		$dir = $plan['output_dir'];

		$candidates = BackupNaming::list_dir($dir);
		// A standalone run's objects index ages out with the archive it names.
		foreach (glob($dir . '/*' . BackupNaming::INDEX_SUFFIX) ?: array() as $p) {
			if (is_file($p)) { $candidates[] = $p; }
		}
		// Pre-restore dumps. NOTHING WRITES THESE ANY MORE — a restore keeps
		// nothing of what it replaces (owner, 2026-08-30; see
		// restore_database.sh stage 2) — but machines that restored before that
		// decision are still carrying them, and each is a full copy of a
		// database. They are not backups and BackupNaming does not know their
		// shape, so they are swept by name until the fleet has aged them out.
		//
		// `auto_pre_*` is the older dashboard-prepended kind;
		// `*-pre-restore.sql.gz[.enc]` is the one the engine briefly wrote.
		foreach (array('/auto_pre_*', '/*-pre-restore.sql.gz', '/*-pre-restore.sql.gz.enc') as $pattern) {
			foreach (glob($dir . $pattern) ?: array() as $p) {
				if (is_file($p)) { $candidates[] = $p; }
			}
		}
		foreach (glob($dir . '/' . BackupChain::DIR_PREFIX . '*', GLOB_ONLYDIR) ?: array() as $chain_d) {
			foreach (BackupNaming::list_dir($chain_d) as $p) { $candidates[] = $p; }
			foreach (glob($chain_d . '/objects-*.json.gz') ?: array() as $p) {
				if (is_file($p)) { $candidates[] = $p; }
			}
		}

		$swept += self::sweep_staged_restores($plan, $cutoff) + self::sweep_verify_work($plan);
		foreach (array_unique($candidates) as $path) {
			if (!is_file($path) || filemtime($path) >= $cutoff) {
				continue;
			}
			// An archive and its envelope go together. Deleting the archive and
			// leaving the envelope accumulates files that look like restore
			// points to anyone reading the directory.
			$sidecar = $path . BackupEnvelope::SIDECAR_SUFFIX;
			if (@unlink($path)) {
				$swept++;
				if (is_file($sidecar)) { @unlink($sidecar); }
			}
		}
		return $swept;
	}

	/**
	 * Prefix of a staged chain restore's working directory, under the backup
	 * BASE. It is `restore_` plus the chain id, and the agent derives the same
	 * name from its own side (primitives/restore_paths.go,
	 * `chainWorkspacePrefix`) — the two have to agree or a staged chain is
	 * invisible to the restore that needs it.
	 */
	const STAGED_RESTORE_PREFIX = 'restore_';

	/**
	 * Remove staged chain restores nobody came back for.
	 *
	 * `stage_chain` downloads a whole chain — the full, every incremental, the
	 * database dump — and recovers the chain data key beside them, so an
	 * operator can approve a restore against artifacts already verified on the
	 * node. Nothing removed them afterwards, and nothing removed them when the
	 * restore was never approved at all: a staged-and-abandoned chain is the
	 * ordinary outcome of a person looking at an approval screen and deciding
	 * not to. On the node this measured 67MB for a small site.
	 *
	 * SWEPT AS A UNIT, by the directory's own age, rather than by folding its
	 * files into the list above. Two reasons: the workspace holds `chain.key`,
	 * a recovered plaintext data key that `BackupNaming` does not recognise and
	 * would therefore have left behind — which is the worse half of the leak —
	 * and removing files one by one would leave an empty directory that still
	 * reads as a staged restore.
	 *
	 * It lives under the backup BASE, not the profile directory: that is where
	 * stage_chain puts it and where restore_chain.sh looks, so it is resolved
	 * here rather than from the plan's own output_dir, which for a
	 * manager-profile run is a level deeper and would never have matched.
	 */
	private static function sweep_staged_restores(array $plan, $cutoff) {
		// From the PLAN, which already carries it — a manager-profile run's
		// output_dir is a level deeper than the base, so resolving it from
		// there would never match.
		$base = (string)($plan['base_dir'] ?? '');
		if ($base === '') {
			try { $base = self::output_dir(); } catch (\Throwable $e) { return 0; }
		}
		$base = rtrim($base, '/');

		$swept = 0;
		$pattern = $base . '/' . self::STAGED_RESTORE_PREFIX . BackupChain::DIR_PREFIX . '*';
		foreach (glob($pattern, GLOB_ONLYDIR) ?: array() as $work) {
			// The directory's own mtime, which the last download moved. A
			// workspace a restore is still using is minutes old; one past the
			// retention window is one nobody came back for.
			$age = @filemtime($work);
			if ($age === false || $age >= $cutoff) {
				continue;
			}
			foreach (glob($work . '/*') ?: array() as $f) {
				if (is_file($f)) { @unlink($f); }
			}
			if (@rmdir($work)) {
				$swept++;
			}
		}
		return $swept;
	}

	/**
	 * How old a verify working directory must be before the sweep takes it. A
	 * verify removes its own directory on every exit path, including a fatal;
	 * this is for the one it could not — a kill, a power loss — and a day is
	 * longer than any verify runs.
	 */
	const VERIFY_WORK_MAX_AGE = 86400;

	/**
	 * Remove verify working directories nobody is using.
	 *
	 * A verify (utils/verify_backup.php) stages the whole set a restore of the
	 * run depends on under `verify-<pid>/` in the profile's directory, and
	 * level 3 replays the tree beneath it. It removes all of that itself when it
	 * finishes, however it finishes — but a process that is killed outright runs
	 * no shutdown handler, and a leftover set is gigabytes on a machine that may
	 * already be tight. So the sweep takes any such directory older than a day,
	 * on every run, regardless of the local retention window: these are not
	 * backups and the window does not apply to them.
	 */
	private static function sweep_verify_work(array $plan) {
		$dir = rtrim((string)($plan['output_dir'] ?? ''), '/');
		if ($dir === '') { return 0; }
		$cutoff = time() - self::VERIFY_WORK_MAX_AGE;
		$swept = 0;
		foreach (glob($dir . '/' . BackupVerifier::WORK_PREFIX . '*', GLOB_ONLYDIR) ?: array() as $work) {
			$age = @filemtime($work);
			if ($age === false || $age >= $cutoff) {
				continue;
			}
			BackupVerifier::remove_tree($work);
			if (!is_dir($work)) { $swept++; }
		}
		return $swept;
	}

	// --------------------------------------------------------------- internals

	/**
	 * Record a run as failed. The failure that ended the run is often the one
	 * that killed the database connection too — a full disk, a PostgreSQL
	 * restart — so a first save that throws gets one fresh connection and one
	 * more try. A row left `running` is invisible to SiteBackupNotice until it
	 * ages out, which is the backstop for a process that wrote nothing at all.
	 */
	private static function fail(BackupHistory $history, $message) {
		$history->set('bkh_outcome', 'failed');
		$history->set('bkh_finish_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_message', substr((string)$message, 0, 4000));
		try {
			$history->save();
			return;
		} catch (\Throwable $e) {
			error_log('BackupRunner: recording the failure failed, reconnecting once: ' . $e->getMessage());
		}
		try {
			DbConnector::get_instance()->reconnect();
			$history->save();
		} catch (\Throwable $e) {
			error_log('BackupRunner: could not record failure: ' . $e->getMessage());
		}
	}

	/**
	 * Read a setting straight from stg_settings, falling back to the settings
	 * singleton (which also serves file-config values).
	 *
	 * Direct because the singleton memoizes non-blank values for the life of the
	 * process, and the Backups page writes these rows with its own SQL: a caller
	 * that saves a target and then asks what the target is — the page, a CLI
	 * run, a test — must see what it just wrote, not what was true at boot.
	 */
	/** backup_retention_days, as settings.json ships it. */
	const DEFAULT_KEEP_DAYS = 28;

	/**
	 * Days of backups kept offsite. A blank setting is the shipped default,
	 * never a one-day window: code can land before its settings are seeded, and
	 * a run in that gap must not prune a month of history down to a day.
	 */
	public static function keep_days() {
		$raw = trim(self::setting('backup_retention_days'));
		return ($raw === '') ? self::DEFAULT_KEEP_DAYS : max(1, (int)$raw);
	}

	private static function setting($name) {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute(array($name));
			$v = $q->fetchColumn();
			if ($v !== false) {
				return (string)$v;
			}
		} catch (\Throwable $e) {
			error_log('BackupRunner: setting read failed for ' . $name . ': ' . $e->getMessage());
		}
		return (string)Globalvars::get_instance()->get_setting($name, true, true);
	}

	/** Create a directory, or say who could not. */
	private static function ensure_dir($dir) {
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
			throw new BackupRunnerException("The directory {$dir} does not exist and could not be created.");
		}
		if (!is_writable($dir)) {
			throw new BackupRunnerException("The directory {$dir} is not writable by " . self::whoami() . '.');
		}
	}

	/** Parse KEY=value lines from an engine's machine-readable output. */
	private static function parse_kv($output) {
		$out = array();
		foreach (explode("\n", (string)$output) as $line) {
			if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $m)) {
				$out[$m[1]] = $m[2];
			}
		}
		return $out;
	}

	/** Remove a directory and its contents. Best effort. */
	private static function rmtree($dir) {
		if (!is_dir($dir)) { return; }
		foreach (scandir($dir) ?: array() as $entry) {
			if ($entry === '.' || $entry === '..') { continue; }
			$path = $dir . '/' . $entry;
			if (is_dir($path) && !is_link($path)) { self::rmtree($path); } else { @unlink($path); }
		}
		@rmdir($dir);
	}

	private static function write_private($path, $contents) {
		$old = umask(0077);
		$ok = @file_put_contents($path, $contents);
		umask($old);
		if ($ok === false) {
			throw new BackupRunnerException('Could not write the run key to ' . $path . '.');
		}
		@chmod($path, 0600);
	}

	/** Destroy the plaintext key. Best effort, but always attempted. */
	private static function shred($path) {
		if (!is_file($path)) { return; }
		if (function_exists('exec')) {
			@exec('shred -u ' . escapeshellarg($path) . ' 2>/dev/null');
		}
		if (is_file($path)) { @unlink($path); }
	}

	private static function database_name() {
		$name = trim((string)self::setting('dbname'));
		if ($name === '') {
			throw new BackupRunnerException('The database name is not configured.');
		}
		return $name;
	}

	private static function whoami() {
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$u = posix_getpwuid(posix_geteuid());
			return $u['name'] ?? 'this user';
		}
		return 'this user';
	}

	/** The end of engine output — where the reason for a failure actually is. */
	private static function tail($output, $lines = 12) {
		$parts = array_slice(explode("\n", trim((string)$output)), -$lines);
		return trim(implode(' | ', $parts));
	}

	public static function human($bytes) {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$i = 0;
		$bytes = (float)$bytes;
		while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
		return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
	}
}
