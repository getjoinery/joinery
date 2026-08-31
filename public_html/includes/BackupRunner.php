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
 *                one husk per failed run had been accumulating on the shelf forever
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
require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

class BackupRunnerException extends Exception {}

/**
 * A backup destination that exists only for the length of one run.
 *
 * The manager profile is handed its bucket and credentials by whoever triggered
 * the run; they are never stored here. That a node holds no credential which
 * could reach another site's shelf is a security property, so this is
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
				$history->set('bkh_bkt_target_id', $plan['target']->key);
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
		$type = (string)($config['backup_type'] ?? self::setting('backup_type'));
		if ($type !== 'database') { $type = 'project'; }

		$target_id = (int)self::setting('backup_target_id');
		$target = null;
		if ($target_id) {
			try {
				$candidate = new BackupTarget($target_id, TRUE);
				if ($candidate->key && $candidate->get('bkt_enabled') && !$candidate->get('bkt_delete_time')) {
					$target = $candidate;
				}
			} catch (\Throwable $e) {
				throw new BackupRunnerException('The configured backup target could not be loaded.');
			}
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
			'keep_cloud'   => max(1, (int)self::setting('backup_retention_count')),
			'keep_local'   => max(0, (int)self::setting('backup_local_retention_days')),
			'delete_local' => (string)self::setting('backup_delete_local_after_upload') === '1',
			// This site prunes its own shelf. It holds the credentials, and the
			// backups being counted are its own.
			'prunes_cloud' => true,
		);
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
	 * whole database on somebody else's shelf is the outcome that refusal exists
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
			// Cloud pruning is not this machine's decision. The credential it
			// was handed cannot delete, and the shelf being counted belongs to
			// whoever triggered the run — retention runs there, with a
			// delete-capable credential that never comes here. This plan carries
			// no keep count at all: the flag is the whole answer, and there is
			// no second number for it to disagree with.
			'prunes_cloud' => false,
		);
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
		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'deleted' => false, 'slug' => $plan['slug'], 'type' => 'project',
			      'profile' => $plan['profile']),
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

	private static function chain_dir(array $plan, $chain_id) {
		return rtrim($plan['output_dir'], '/') . '/' . $chain_id;
	}

	private static function execute_chain(array $plan, BackupHistory $history) {
		$dir = $plan['output_dir'];
		self::ensure_dir($dir);

		$snar = self::snar_path($plan);
		list($chain_id, $manifest) = self::current_chain($plan);

		$reason = BackupChain::should_start_new($manifest, is_file($snar) && filesize($snar) > 0,
			$plan['full_days'], $plan['max_inc'], null, (string)$plan['recovery_fpr']);
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

		if ($reason !== '') {
			// A new chain gets a new data key and a clean snapshot. Reusing the
			// previous chain's key would mean one compromised key opened both,
			// and reusing its snapshot would produce an "incremental" whose
			// full lives in a chain retention may already have deleted.
			$chain_id = BackupChain::new_chain_id();
			$mint     = BackupEnvelope::mint($chain_id, $plan['recipients']);
			$data_key = $mint['data_key'];
			$manifest = BackupChain::start($chain_id, $plan['slug'], $mint['envelope']);
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
		try {
			try {
				$artifacts['files'] = self::run_files_engine($plan, $chain_d, $seq, $snar, $key_file);
				$artifacts['db']    = self::run_db_engine($plan, $chain_d, $seq, $key_file);
				$meta = self::build_meta($plan, $chain_d, $seq, $key_file);
				if ($meta) { $artifacts['meta'] = $meta; }
			} finally {
				self::shred($key_file);
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
			self::discard_failed_run($chain_d, $seq, $artifacts, $manifest_path, $manifest_pre);
			throw $e;
		}

		$history->set('bkh_chain_id', $chain_id);
		$history->set('bkh_chain_seq', $seq);
		$history->set_artifacts($uploaded);
		$history->set('bkh_upload_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_outcome', 'success');
		$history->set('bkh_finish_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_message', ($level === 0 ? 'Full' : 'Incremental') . ' run ' . $seq . ' of ' . $chain_id
			. ($reason !== '' ? ' (new chain: ' . $reason . ')' : ''));
		$history->save();

		if ($plan['delete_local']) {
			// A machine that asked for its disk back gets it in chain mode too.
			// The chain stays extendable from just the manifest and the
			// snapshot; the uploaded artifacts need no local copy.
			foreach ($artifacts as $a) { @unlink($a['path']); }
		}

		// Both retention families run on every backup, so a site switched
		// between modes still ages its old backups out. Each pass only ever
		// sees its own kind: cloud retention skips chain rows entirely, so it
		// can never delete a chain's full out from under its incrementals.
		$pruned = self::enforce_chain_retention($plan) + self::enforce_cloud_retention($plan);
		$swept  = self::sweep_local($plan);

		$msg = ($level === 0 ? 'Full backup' : 'Incremental backup') . ' (' . self::human($artifacts['files']['bytes']) . ' of files)'
			. ' in ' . $chain_id . ' to ' . $plan['target']->get('bkt_name');
		if ($pruned) { $msg .= "; pruned {$pruned} old backup" . ($pruned === 1 ? '' : 's'); }
		if ($swept)  { $msg .= "; swept {$swept} local file" . ($swept === 1 ? '' : 's'); }

		return array('status' => 'success', 'message' => $msg);
	}

	/**
	 * Remove everything a failed chain run left behind. The artifacts collected
	 * so far are deleted by their recorded paths, then every artifact name this
	 * sequence number could have produced is deleted by name — an engine can
	 * write its file and throw before the artifact is recorded. The manifest is
	 * restored to its pre-run state ($manifest_before), or deleted when the run
	 * was starting a brand-new chain and there was no pre-run state to restore.
	 *
	 * Every step is best-effort: this runs on the failure path, and the failure
	 * being reported must stay the real one.
	 */
	private static function discard_failed_run($chain_d, $seq, array $artifacts, $manifest_path, $manifest_before) {
		foreach ($artifacts as $a) {
			if (!empty($a['path'])) { @unlink($a['path']); }
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
	 * A failed run must not leave an empty chain directory on the shelf.
	 * rmdir refuses a directory with anything in it, so this can only ever
	 * remove a husk — a run that failed before producing an artifact. Left
	 * alone they accumulate one per failed run (159 were standing on the dev
	 * shelf when this was written) and make the shelf unreadable.
	 */
	private static function remove_empty_chain_dir($chain_d) {
		@rmdir($chain_d);
	}

	/** Archive the file tree, incrementally when the chain is being extended. */
	private static function run_files_engine(array $plan, $chain_d, $seq, $snar, $key_file) {
		$tools = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';
		$name  = 'files-' . str_pad((string)(int)$seq, 4, '0', STR_PAD_LEFT);

		$cmd = 'bash ' . escapeshellarg($tools . '/backup_files.sh')
			. ' ' . escapeshellarg($plan['project'])
			. ' --project-dir ' . escapeshellarg(PathHelper::getSiteRoot())
			. ' --output-dir ' . escapeshellarg($chain_d)
			. ' --name ' . escapeshellarg($name)
			. ' --snar ' . escapeshellarg($snar)
			. ' --key-file ' . escapeshellarg($key_file);

		foreach (self::extra_excludes() as $x) {
			$cmd .= ' --exclude ' . escapeshellarg($x);
		}

		$out = array(); $rc = 0;
		exec($cmd . ' 2>&1', $out, $rc);
		$output = implode("\n", $out);
		if ($rc !== 0) {
			throw new BackupRunnerException('The files engine exited ' . $rc . '. ' . self::tail($output));
		}

		$fields = self::parse_kv($output);
		if (empty($fields['ARCHIVE']) || !is_file($fields['ARCHIVE'])) {
			throw new BackupRunnerException('The files engine reported no archive. ' . self::tail($output));
		}

		return array(
			'name'   => basename($fields['ARCHIVE']),
			'path'   => $fields['ARCHIVE'],
			'bytes'  => (int)($fields['BYTES'] ?? filesize($fields['ARCHIVE'])),
			'sha256' => (string)($fields['SHA256'] ?? hash_file('sha256', $fields['ARCHIVE'])),
			'level'  => (int)($fields['LEVEL'] ?? 1),
			'kind'   => 'files',
		);
	}

	/** Dump the database in full, as its own artifact, on every run. */
	private static function run_db_engine(array $plan, $chain_d, $seq, $key_file) {
		$tools = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';
		$db    = self::database_name();

		$before = glob($chain_d . '/*.sql.gz.enc') ?: array();
		$before = array_flip($before);

		$cmd = 'cd ' . escapeshellarg($chain_d)
			. ' && bash ' . escapeshellarg($tools . '/backup_database.sh')
			. ' --non-interactive --key-file ' . escapeshellarg($key_file)
			. ' ' . escapeshellarg($db);

		$out = array(); $rc = 0;
		exec($cmd . ' 2>&1', $out, $rc);
		if ($rc !== 0) {
			throw new BackupRunnerException('The database engine exited ' . $rc . '. ' . self::tail(implode("\n", $out)));
		}

		$made = null;
		foreach (glob($chain_d . '/*.sql.gz.enc') ?: array() as $p) {
			if (!isset($before[$p])) { $made = $p; break; }
		}
		if ($made === null) {
			throw new BackupRunnerException('The database engine produced no dump.');
		}

		// The engine names dumps after the database and a timestamp; the chain
		// wants them positional, so a restore can find run N's dump without
		// parsing dates.
		$target = $chain_d . '/' . BackupChain::artifact_name('db', $seq);
		if (!@rename($made, $target)) {
			throw new BackupRunnerException('Could not place the database dump at ' . basename($target) . '.');
		}

		return array(
			'name'   => basename($target),
			'path'   => $target,
			'bytes'  => (int)filesize($target),
			'sha256' => hash_file('sha256', $target),
			'kind'   => 'db',
		);
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

	/** Upload every artifact of a run plus the rewritten manifest. */
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
	 */
	public static function enforce_chain_retention(array $plan) {
		if (empty($plan['prunes_cloud'])) {
			return 0;
		}
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

		$surplus = self::surplus(array_keys($chains), $plan['keep_cloud']);
		if (!$surplus) {
			return 0;
		}

		$target = $plan['target'];
		$creds  = $target->get_credentials();
		$bucket = trim((string)$target->get('bkt_bucket'));

		$pruned = 0;
		foreach ($surplus as $cid) {
			try {
				foreach ($chains[$cid] as $row) {
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
		return $pruned;
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

		// Anything already sitting here is from an earlier run; remember it so
		// the artifact this run produced is identified by being NEW, not by
		// being newest. A clock skew or a same-second file would otherwise let
		// the run upload someone else's archive under its own envelope.
		$before = array_flip(BackupNaming::list_dir($dir));

		$mint     = BackupEnvelope::mint('pending', $plan['recipients']);
		$key_file = $dir . '/.jy_selfbackup_' . getmypid() . '.key';
		$sidecar  = $dir . '/.jy_selfbackup_' . getmypid() . '.keys.json';

		self::write_private($key_file, $mint['data_key']);
		BackupEnvelope::write_sidecar($sidecar, $mint['envelope']);

		try {
			$output = self::run_engine($plan, $key_file);
		} finally {
			self::shred($key_file);
		}

		$archive = self::produced_archive($dir, $before);
		if ($archive === null) {
			@unlink($sidecar);
			throw new BackupRunnerException(
				'The backup engine finished but produced no archive. Engine output: ' . self::tail($output));
		}

		// The envelope now belongs to a specific file and is named for it.
		$final_sidecar = $archive . BackupEnvelope::SIDECAR_SUFFIX;
		$envelope = BackupEnvelope::read_sidecar($sidecar);
		$envelope['artifact'] = basename($archive);
		BackupEnvelope::write_sidecar($final_sidecar, $envelope);
		@unlink($sidecar);

		$artifacts = array(
			array('name' => basename($archive), 'path' => $archive,
			      'bytes' => (int)@filesize($archive), 'kind' => 'archive'),
			array('name' => basename($final_sidecar), 'path' => $final_sidecar,
			      'bytes' => (int)@filesize($final_sidecar), 'kind' => 'envelope'),
		);

		$uploaded = self::upload($plan, $artifacts);
		$history->set_artifacts($uploaded);
		$history->set('bkh_upload_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_outcome', 'success');
		$history->set('bkh_finish_time', gmdate('Y-m-d H:i:s'));
		$history->set('bkh_message', 'Backed up ' . basename($archive));
		$history->save();

		if ($plan['delete_local']) {
			foreach ($artifacts as $a) { @unlink($a['path']); }
		}

		// Only now, with this run safely offsite, is it sound to delete anything.
		// Chains are pruned here too, so a site switched from chain mode to full
		// still ages its old chains out — whole, via the chain-atomic pass.
		$pruned = self::enforce_cloud_retention($plan) + self::enforce_chain_retention($plan);
		$swept  = self::sweep_local($plan);

		$msg = 'Backed up ' . basename($archive) . ' (' . self::human((int)@filesize($archive) ?: $artifacts[0]['bytes']) . ')'
			. ' to ' . $plan['target']->get('bkt_name');
		if ($pruned) { $msg .= "; pruned {$pruned} old restore point" . ($pruned === 1 ? '' : 's'); }
		if ($swept)  { $msg .= "; swept {$swept} local file" . ($swept === 1 ? '' : 's'); }

		return array('status' => 'success', 'message' => $msg);
	}

	/** Shell the engine that ships with every install. */
	private static function run_engine(array $plan, $key_file) {
		$tools = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';

		if ($plan['type'] === 'database') {
			$db = self::database_name();
			$cmd = 'cd ' . escapeshellarg($plan['output_dir'])
				. ' && bash ' . escapeshellarg($tools . '/backup_database.sh')
				. ' --non-interactive --key-file ' . escapeshellarg($key_file)
				. ' ' . escapeshellarg($db);
		} else {
			$cmd = 'bash ' . escapeshellarg($tools . '/backup_project.sh')
				. ' ' . escapeshellarg($plan['project'])
				. ' --non-interactive --output-dir ' . escapeshellarg($plan['output_dir'])
				. ' --key-file ' . escapeshellarg($key_file);
		}

		$out = array();
		$rc = 0;
		exec($cmd . ' 2>&1', $out, $rc);
		$output = implode("\n", $out);

		if ($rc !== 0) {
			throw new BackupRunnerException('The backup engine exited ' . $rc . '. ' . self::tail($output));
		}
		return $output;
	}

	/**
	 * The archive this run created: a backup artifact that was not here before.
	 * Identified by being new rather than newest, so a stale file with a strange
	 * mtime can never be mistaken for this run's output and shipped under this
	 * run's envelope.
	 */
	private static function produced_archive($dir, array $before) {
		foreach (BackupNaming::list_dir($dir) as $path) {
			if (!isset($before[$path])) {
				return $path;
			}
		}
		return null;
	}

	private static function upload(array $plan, array $artifacts, $sub = '') {
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

		// The profile segment is what stops two parties' backups landing in one
		// pile: without it a listing cannot tell whose backup is whose, and
		// neither party's retention can reason about the shelf it is responsible
		// for.
		$base_key = $prefix . '/' . $plan['slug'] . '/' . BackupProfile::path_segment($plan['profile']) . '/';

		$out = array();
		$unledgered = array();
		foreach ($artifacts as $a) {
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

		// Said out loud rather than logged quietly. An artifact that reached the
		// bucket but was not recorded here is a real backup that cannot be
		// restored over the agent channel — the node refuses an archive it has
		// no record of making — and the operator should learn that now rather
		// than during a restore. The usual cause is a backup run by an
		// unprivileged user: the ledger is root-owned on purpose.
		if ($unledgered) {
			error_log('BackupRunner: uploaded but NOT recorded in the integrity ledger ('
				. BackupLedger::dir() . ' is not writable by ' . self::whoami() . '): '
				. implode(', ', $unledgered)
				. ' — these archives cannot be restored over the agent channel.');
		}

		return $out;
	}

	// -------------------------------------------------------------- retention

	/**
	 * Keep the newest N restore points offsite; delete the objects belonging to
	 * anything older, oldest first.
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
	public static function enforce_cloud_retention(array $plan) {
		if (empty($plan['prunes_cloud'])) {
			return 0;
		}
		$keep = $plan['keep_cloud'];

		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'offsite' => true, 'deleted' => false, 'slug' => $plan['slug'],
			      'chained' => false, 'profile' => $plan['profile']),
			array('bkh_start_time' => 'DESC'), 500, 0);
		$rows->load();

		$all = array();
		foreach ($rows as $r) { $all[] = $r; }

		$surplus = self::surplus($all, $keep);
		if (!$surplus) {
			return 0;
		}

		$target = $plan['target'];
		$creds  = $target->get_credentials();
		$bucket = trim((string)$target->get('bkt_bucket'));

		$pruned = 0;
		foreach ($surplus as $old) {
			try {
				foreach ($old->object_keys() as $key) {
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
		return $pruned;
	}

	/**
	 * Which restore points are surplus, given a newest-first list and how many
	 * to keep. Pure, and separated from the deleting because this is the
	 * decision that can lose data: it has to be checkable without a bucket, and
	 * "keep at least one, always" has to be true even when the caller passes
	 * nonsense.
	 */
	public static function surplus(array $newest_first, $keep) {
		$keep = max(1, (int)$keep);
		if (count($newest_first) <= $keep) {
			return array();
		}
		return array_slice($newest_first, $keep);
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
	 * the shelf belongs to the management node and the credential it is handed
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
		$days = $plan['keep_local'];
		if ($days <= 0) {
			return 0;
		}
		$cutoff = time() - ($days * 86400);
		$dir = $plan['output_dir'];

		$candidates = BackupNaming::list_dir($dir);
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
		}

		$swept = self::sweep_staged_restores($plan, $cutoff);
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

	// --------------------------------------------------------------- internals

	private static function fail(BackupHistory $history, $message) {
		try {
			$history->set('bkh_outcome', 'failed');
			$history->set('bkh_finish_time', gmdate('Y-m-d H:i:s'));
			$history->set('bkh_message', substr((string)$message, 0, 4000));
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
