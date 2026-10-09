<?php
/**
 * CloudStorageLifecycle — the one shared admin lifecycle for the file store.
 *
 * The file store is a target row (bkt_purpose 'files'), sealed like every
 * target, chosen by file_store_target_id. Offloaded rows record the store and
 * key they went to, so there may be older stores still serving the files whose
 * records name them; Move files carries those across. A store's location is
 * fixed once a file is in it (BackupTarget::location_refusal()); its key may
 * change.
 *
 * testConnection() is the Save check, in order, storing nothing on a fail:
 * its own bucket and what the key may do (BucketCheck), reach, write, the
 * privacy gate — an anonymous read of the probe must be DENIED; the probe is
 * the sole sanctioned url() call — and delete.
 *
 * Offload is driven by ONE scheduled task (CloudOffloadRun) for the whole
 * platform. The store's direction each tick is its MODE — offload / drain /
 * idle — derived from the enabled latch + draining flag (mode()), with a
 * Move files batch beside it while a move is running. runOffloadTick() walks
 * every declared profile (the registry) and dispatches by mode, so a new
 * consumer adds a StorageProfile and zero tasks. There is no forward/reverse
 * mutual-exclusion to enforce: the store has one mode per tick.
 *
 * A profile whose table also holds rows that are not its own (fbb_file_blobs
 * holds public blobs, which never move) scopes the cloud-row counts and the
 * health cloud-side counts to its own rows via its optional
 * reverseEligibilityWhere() ownership gate.
 *
 * @version 3.2 - a bucket that locks every new object by default fails the delete step: no deleted file could go (F8)
 * @version 3.1 - the tick finishes what earlier deletes only hid in each file store's folder
 *                (HiddenVersionSweep; specs/storage_targets.md S28), and stays active while a sweep has work left
 * @version 3.0 - the file store is a target row (specs/storage_targets.md WP6): saveStore(), removeStore(),
 *                stores(), cloudRowCount() per store, Move files (startMove(), moveState(), stopMove(),
 *                a batch per tick); settings are written through Setting::put(), which refuses a name
 *                nothing declares; the binding settings, the binding guard and persistKey() are gone

 * @version 2.3 - privacyVerdict() carries a step status: an anonymous request with no answer is a
 *                warning that says the check could not run, not a pass
 * @version 2.2 - persistKey() stores a replacement key alone, leaving the enabled latch and the
 *                draining flag as they were; health() pings through driverWithFallback(), so a paused
 *                or draining store reports a key that stopped working
 * @version 2.1 - health() tells a record with no bytes on this server (missing, missing_rows) apart from a
 *                push that failed five times (stuck, stuck_rows with the last error)
 * @version 2.0 - one private store (specs/implemented/cloud_storage_private_only.md): testConnection() takes only
 *                $opts and runs own bucket and key, reach, write, the privacy gate, delete; every
 *                helper loses its visibility argument; _settings_map() writes provider, endpoint,
 *                region, bucket, access key, secret key, enabled
 * @version 1.8 - the public store's Save writes cloud_storage_provider beside the binding
 * @version 1.7 - health() counts carry pending_bytes and cloud_bytes where the profile names a size column
 * @version 1.6 - testConnection() first asks BucketCheck: the bucket is not a backup target's, and on
 *                Backblaze the key reaches this bucket and can list, read, write and delete
 * @version 1.5 - the tick stays active, and the daily file-store check runs, while any offloaded file
 *                exists — a paused store serves the same files as an active one, and a file the bucket
 *                lost is otherwise invisible until a visitor gets a 404. It deactivates only when no
 *                store is in motion and no cloud row remains
 * @version 1.4 - the tick runs the daily file-store check (CloudStoreInventory) while any store is
 *                offloading or draining, a slice per tick; its line joins the tick's message
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfile.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfileRegistry.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventory.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

class CloudStorageLifecycle {

	// ====================================================================
	// The Save check: own bucket and key, reach, write, private, delete.
	// ====================================================================
	const STEP_REACH   = 'Reach';
	const STEP_WRITE   = 'Write';
	const STEP_PRIVATE = 'Private';
	const STEP_DELETE  = 'Delete';

	public static function testConnection(array $opts): array {
		require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageS3Driver.php'));
		$steps = [];
		$skip = function (array &$steps, array $labels) {
			foreach ($labels as $label) {
				$steps[] = ['label' => $label, 'status' => 'skip', 'message' => 'skipped (prior step failed)'];
			}
		};

		// Step 0: its own bucket, and what the key may do. Decided before the
		// network is touched: a bucket that already holds this site's backups
		// is refused outright, and on Backblaze the key states what it can
		// reach and do, so a key pinned elsewhere or one that cannot delete
		// is named now rather than found by the first permanent delete.
		$others = BucketCheck::backup_target_buckets();
		$own = BucketCheck::collision_step((string)($opts['bucket'] ?? ''), (string)($opts['endpoint'] ?? ''), $others, 'files');
		$steps[] = $own;
		if ($own['status'] === 'fail') {
			$skip($steps, [self::STEP_REACH, self::STEP_WRITE, self::STEP_PRIVATE, self::STEP_DELETE]);
			return ['ok' => false, 'steps' => $steps];
		}
		if (BucketCheck::is_b2((string)($opts['endpoint'] ?? ''))) {
			$key_steps = BucketCheck::b2_key_steps(
				['access_key' => (string)($opts['access_key'] ?? ''), 'secret_key' => (string)($opts['secret_key'] ?? '')],
				(string)($opts['bucket'] ?? ''), BucketCheck::B2_FILE_STORE_CAPABILITIES, 'file store key', $others);
			foreach ($key_steps as $step) { $steps[] = $step; }
			if (BucketCheck::failed($key_steps)) {
				$skip($steps, [self::STEP_REACH, self::STEP_WRITE, self::STEP_PRIVATE, self::STEP_DELETE]);
				return ['ok' => false, 'steps' => $steps];
			}
		}

		// Step 1: reach — the key can list the bucket.
		try {
			$driver = CloudStorageDriverFactory::fromOptions($opts);
			$ping = $driver->ping();
			if ($ping['ok']) {
				$steps[] = ['label' => self::STEP_REACH, 'status' => 'pass',
					'message' => 'Reached and authenticated (' . htmlspecialchars($opts['endpoint']) . ')'];
			} else {
				$steps[] = ['label' => self::STEP_REACH, 'status' => 'fail', 'message' => 'The bucket could not be reached with this key.', 'raw' => $ping['message']];
				$skip($steps, [self::STEP_WRITE, self::STEP_PRIVATE, self::STEP_DELETE]);
				return ['ok' => false, 'steps' => $steps];
			}
		} catch (Exception $e) {
			$steps[] = ['label' => self::STEP_REACH, 'status' => 'fail', 'message' => 'Driver could not be constructed.', 'raw' => $e->getMessage()];
			$skip($steps, [self::STEP_WRITE, self::STEP_PRIVATE, self::STEP_DELETE]);
			return ['ok' => false, 'steps' => $steps];
		}

		// Step 2: write — a probe object lands, in the store's folder.
		$probe_name = '_joinery_probe-' . bin2hex(random_bytes(4)) . '.txt';
		$folder = trim((string)($opts['prefix'] ?? ''), '/');
		$probe_key = ($folder !== '' ? $folder . '/' : '') . $probe_name;
		$probe_local = sys_get_temp_dir() . '/' . $probe_name;
		file_put_contents($probe_local, "joinery-cloud-storage-test\n");
		try {
			$driver->put($probe_local, $probe_key, 'text/plain');
			$steps[] = ['label' => self::STEP_WRITE, 'status' => 'pass', 'message' => 'A probe object was written.'];
		} catch (Exception $e) {
			@unlink($probe_local);
			$steps[] = ['label' => self::STEP_WRITE, 'status' => 'fail', 'message' => 'The key cannot write to this bucket.', 'raw' => $e->getMessage()];
			$skip($steps, [self::STEP_PRIVATE, self::STEP_DELETE]);
			return ['ok' => false, 'steps' => $steps];
		}

		// Step 3: private — the gate. The bucket's direct URL for the probe is
		// the exact URL a public bucket would serve; it is fetched anonymously,
		// no credentials, and a 2xx refuses the Save.
		$ok = true;
		$verdict = self::privacyVerdict(BucketCheck::anonymous_status($driver->url($probe_key)));
		if (!$verdict['pass']) {
			$ok = false;
		}
		$steps[] = ['label' => self::STEP_PRIVATE, 'status' => $verdict['status'], 'message' => $verdict['message']];

		// Step 4: delete — the probe goes, so permanent delete and retention work.
		try {
			$driver->delete($probe_key);
			$steps[] = ['label' => self::STEP_DELETE, 'status' => 'pass', 'message' => 'The probe object was deleted.'];
		} catch (Exception $e) {
			if ($e->getCode() === S3Signer::LOCKED) {
				// The bucket locks every new object (a default retention): no
				// member's deleted file could ever be deleted.
				$ok = false;
				$steps[] = ['label' => self::STEP_DELETE, 'status' => 'fail',
					'message' => 'This bucket locks every new object (object lock with a default retention), so a deleted file could not be '
						. 'deleted. A file store needs a bucket without default retention.', 'raw' => $e->getMessage()];
			} else {
				$steps[] = ['label' => self::STEP_DELETE, 'status' => 'warn',
					'message' => 'The key cannot delete from this bucket. Permanent delete and permission flips will fail until it can.', 'raw' => $e->getMessage()];
			}
		}

		@unlink($probe_local);
		return ['ok' => $ok, 'steps' => $steps];
	}

	/**
	 * The privacy hard-gate verdict from an anonymous read's HTTP status. An
	 * anonymous 2xx means the bytes are world-readable ⇒ the bucket is public
	 * ⇒ gate FAILS. Any other answer (401/403/404) means anonymous read is denied
	 * ⇒ gate PASSES. No answer at all (status 0) proves nothing: the gate does
	 * not block on it, and the step is a warning that says so. Separated from the
	 * network probe so the privacy-critical decision is unit-testable.
	 * Returns ['pass' => bool, 'status' => 'pass'|'fail'|'warn', 'message'].
	 */
	public static function privacyVerdict(int $status): array {
		if ($status >= 200 && $status < 300) {
			return ['pass' => false, 'status' => 'fail',
				'message' => 'This bucket is publicly readable (an anonymous request got HTTP ' . $status
					. '); it cannot hold private files. Make it private at the provider and save again.'];
		}
		if ($status === 0) {
			return ['pass' => true, 'status' => 'warn',
				'message' => 'Could not check whether this bucket is private: an anonymous request got no answer. '
					. 'Check at the provider that the bucket is private.'];
		}
		return ['pass' => true, 'status' => 'pass', 'message' => 'Nobody can read this bucket without a key (HTTP ' . $status . ').'];
	}

	// ====================================================================
	// The store as a target row.
	// ====================================================================

	/**
	 * Save a file store target from the page's form: read the fields onto it
	 * (BackupTargetForm::apply(), which refuses a location change once files
	 * are stored there), run the Save check against what was entered, and
	 * only on a pass store it. A store saved as new becomes the one new
	 * offloads go to, and offloading is switched on.
	 *
	 * @return array ['ok' => bool, 'message' => string, 'test_results' => array|null]
	 */
	public static function saveStore(BackupTarget $target, array $input, bool $make_current): array {
		$target->set('bkt_purpose', BackupTarget::PURPOSE_FILES);
		$applied = BackupTargetForm::apply($target, $input + array('bkt_enabled' => '1'), array('files' => true));
		if (!$applied['ok']) {
			return array('ok' => false, 'message' => $applied['message'], 'test_results' => null);
		}
		try {
			$target->prepare();
		} catch (BackupTargetException $e) {
			return array('ok' => false, 'message' => 'Not saved. ' . $e->getMessage(), 'test_results' => null);
		}
		$overlap = $target->file_store_overlap();
		if ($overlap !== '') {
			return array('ok' => false, 'message' => 'Not saved. ' . $overlap, 'test_results' => null);
		}
		$opts = CloudStorageDriverFactory::options($target) + array('prefix' => $target->prefix());
		$test = self::testConnection($opts);
		if (!$test['ok']) {
			return array('ok' => false, 'message' => '', 'test_results' => $test);
		}
		$target->save();
		CloudStorageDriverFactory::reset();
		if ($make_current) {
			Setting::put(BackupTarget::FILE_STORE_SETTING, (string)(int)$target->key);
			self::setEnabled(true);
			self::stopDrain();
			self::ensureTickActive();
		}
		return array('ok' => true, 'message' => $applied['note'], 'test_results' => $test);
	}

	/**
	 * Forget the current file store: only while no file is offloaded to any
	 * store and nothing is on its way back. The target row is deleted with it.
	 *
	 * @return string '' when removed, otherwise why not
	 */
	public static function removeStore(): string {
		$target = CloudStorageDriverFactory::currentTarget();
		if ($target === null) {
			return '';
		}
		// Any offloaded file at all, not only this store's: an older store's
		// files are moved to the current one, so the current one stays until
		// nothing is offloaded anywhere.
		if (self::cloudRowCount() > 0 || self::mode() === 'drain') {
			return 'Offloaded files are still in a file store, or on their way back. Pull them back first.';
		}
		Setting::put(BackupTarget::FILE_STORE_SETTING, '');
		self::setEnabled(false);
		$target->soft_delete();
		CloudStorageDriverFactory::reset();
		return '';
	}

	/**
	 * Sum of 'cloud' rows across every profile, in one store or in all. A
	 * profile whose table also holds rows that are not its own is scoped to
	 * the cloud rows that are, via the optional reverseEligibilityWhere()
	 * ownership gate.
	 */
	public static function cloudRowCount(?int $target_id = null): int {
		$dblink = DbConnector::get_instance()->get_db_link();
		$total = 0;
		foreach (StorageProfileRegistry::all() as $profile) {
			$columns = self::columnsOf($profile->table());
			if ($columns === array()) {
				continue; // the table does not exist yet: it holds nothing
			}
			$own = (method_exists($profile, 'reverseEligibilityWhere'))
				? trim($profile->reverseEligibilityWhere()) : '';
			$own_sql = $own !== '' ? " AND ($own)" : '';
			$params = array();
			// A table that does not record a row's store yet (a plugin whose
			// columns are still to come) cannot say which store its offloaded
			// rows are in, so every one of them counts for every store: a
			// store is never taken for empty on a count that could not look.
			if ($target_id !== null && in_array($profile->targetColumn(), $columns, true)) {
				$own_sql .= " AND {$profile->targetColumn()} = ?";
				$params[] = $target_id;
			}
			$q = $dblink->prepare(
				"SELECT COUNT(*) AS c FROM {$profile->table()} WHERE {$profile->driverColumn()} = 'cloud'{$own_sql}");
			$q->execute($params);
			$total += (int)$q->fetch(PDO::FETCH_ASSOC)['c'];
		}
		return $total;
	}

	/** A table's column names, or [] when the table does not exist. Asked once per process. */
	private static function columnsOf(string $table): array {
		static $seen = array();
		if (!isset($seen[$table])) {
			$q = DbConnector::get_instance()->get_db_link()->prepare(
				"SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?");
			$q->execute(array($table));
			$seen[$table] = $q->fetchAll(PDO::FETCH_COLUMN);
		}
		return $seen[$table];
	}

	/**
	 * Every file store target, current first, then by name; each with how many
	 * files it holds and, for an older store still serving files, whether it
	 * answers: ['ok' => bool, 'message'] or null when not asked (the current
	 * store's answer is health()'s).
	 */
	public static function stores(): array {
		$current = CloudStorageDriverFactory::currentTarget();
		$out = array();
		foreach (new MultiBackupTarget(array('deleted' => false, 'purpose' => BackupTarget::PURPOSE_FILES), array('bkt_name' => 'ASC')) as $t) {
			$is_current = $current !== null && (int)$current->key === (int)$t->key;
			$files = self::cloudRowCount((int)$t->key);
			$answers = null;
			if (!$is_current && $files > 0) {
				$driver = CloudStorageDriverFactory::forTarget((int)$t->key);
				try {
					$answers = $driver ? $driver->ping() : array('ok' => false, 'message' => 'its key cannot be read');
				} catch (Exception $e) {
					$answers = array('ok' => false, 'message' => $e->getMessage());
				}
			}
			$out[] = array('target' => $t, 'current' => $is_current, 'files' => $files, 'answers' => $answers);
		}
		usort($out, function ($a, $b) { return (int)$b['current'] - (int)$a['current']; });
		return $out;
	}

	/**
	 * Set the enabled latch: whether new offloads go to the current store.
	 * Used by the pause / enable / remove flows.
	 */
	public static function setEnabled(bool $enabled): void {
		Setting::put('cloud_storage_enabled', $enabled ? '1' : '0');
	}

	// ====================================================================
	// Move files — an older store's files to the current one.
	// ====================================================================

	const MOVE_SETTING = 'cloud_storage_move';

	/**
	 * Start carrying every file on store $from_id to the current store, in
	 * batches on the offload tick. One move at a time.
	 *
	 * @return string '' when started, otherwise why not
	 */
	public static function startMove(int $from_id): string {
		$to = CloudStorageDriverFactory::currentUnlatched();
		if ($to === null) {
			return 'There is no file store to move the files to.';
		}
		if ($to->target_id === $from_id) {
			return 'These files are already in the current file store.';
		}
		if (self::mode() === 'drain') {
			return 'Files are being pulled back to this server; a move waits until that is done.';
		}
		$total = self::cloudRowCount($from_id);
		if ($total === 0) {
			return 'This file store holds no files.';
		}
		$from = new BackupTarget($from_id, TRUE);
		$current = new BackupTarget($to->target_id, TRUE);
		if ($from->overlaps($current)) {
			return 'These files are in the same bucket and folder as the current file store, so there is nothing to move them to.';
		}
		Setting::put(self::MOVE_SETTING, json_encode(array(
			'from' => $from_id, 'to' => $to->target_id, 'total' => $total, 'started' => gmdate('Y-m-d H:i:s'),
			'last' => '', 'failed' => 0)));
		self::ensureTickActive();
		return '';
	}

	/** The move in progress, or null: from, to, total, started, last (the last batch's words), failed. */
	public static function moveState(): ?array {
		$raw = (string)Globalvars::get_instance()->get_setting(self::MOVE_SETTING);
		$state = $raw !== '' ? json_decode($raw, true) : null;
		return is_array($state) && (int)($state['from'] ?? 0) > 0 ? $state : null;
	}

	/** Stop a move. Files already moved stay moved; the rest stay where they are. */
	public static function stopMove(): void {
		Setting::put(self::MOVE_SETTING, '');
	}

	/**
	 * One tick of the move: a batch of every profile's rows on the old store
	 * to the current one. The move ends itself when the old store holds no
	 * file, or when the current store is no longer the one it moves to.
	 */
	private static function moveTick(): ?string {
		$state = self::moveState();
		if ($state === null) {
			return null;
		}
		$to = CloudStorageDriverFactory::currentUnlatched();
		if ($to === null || $to->target_id !== (int)$state['to']) {
			self::stopMove();
			return 'move stopped: the file store it moved to is no longer the current one';
		}
		$from = (int)$state['from'];
		$words = array();
		$failed = 0;
		foreach (StorageProfileRegistry::all() as $profile) {
			$r = CloudOffloadEngine::moveBatch($profile, $from, $to);
			$failed += (int)($r['failed'] ?? 0);
			if ((int)($r['moved'] ?? 0) > 0 || (int)($r['failed'] ?? 0) > 0) {
				$words[] = get_class($profile) . ': ' . $r['message'];
			}
		}
		$left = self::cloudRowCount($from);
		if ($left === 0) {
			self::stopMove();
			return 'move finished: every file is in the current file store';
		}
		$state['last'] = $words ? implode('; ', $words) : 'nothing moved this tick';
		$state['failed'] = $failed;
		Setting::put(self::MOVE_SETTING, json_encode($state));
		return 'moving files: ' . number_format($left) . ' left' . ($words ? ' (' . $state['last'] . ')' : '');
	}

	// ====================================================================
	// Offload modes + the single offload tick.
	//
	// One scheduled task (CloudOffloadRun) drives every profile. The store's
	// direction for a tick is its MODE, derived from its settings:
	//
	//   offload — store enabled: push eligible local rows up to the bucket.
	//   drain   — store disabled with the draining flag set (Disable-and-Pull):
	//             pull cloud rows back to local until none remain.
	//   idle    — store disabled, not draining (paused / never configured):
	//             do nothing; existing cloud rows keep serving.
	//
	// A row can never ping-pong between local and cloud: the store has exactly
	// one mode per tick, so forward/reverse mutual-exclusion is structural
	// rather than an enforced guard.
	// ====================================================================

	const TICK_TASK = 'CloudOffloadRun';

	/** The store's current offload mode: 'offload' | 'drain' | 'idle'. */
	public static function mode(): string {
		$s = Globalvars::get_instance();
		if ($s->get_setting('cloud_storage_enabled') && CloudStorageDriverFactory::currentTarget() !== null) {
			return 'offload';
		}
		if ($s->get_setting('cloud_storage_draining')) {
			return 'drain';
		}
		return 'idle';
	}

	/** Ensure the single offload tick task exists and is active. */
	public static function ensureTickActive(): void {
		self::_activate_task(self::TICK_TASK);
	}

	/**
	 * Begin draining every offloaded file back to local (Disable-and-Pull-Back),
	 * each from the store its record names. A move in progress stops: the
	 * files are coming home instead.
	 */
	public static function startDrain(): void {
		Setting::put('cloud_storage_draining', '1');
		self::stopMove();
		self::ensureTickActive();
	}

	/** Stop draining (drain finished, or store re-enabled). */
	public static function stopDrain(): void {
		Setting::put('cloud_storage_draining', '0');
	}

	/**
	 * The single offload tick: drive every declared profile by the store's
	 * mode. Offload pushes local→cloud; drain pulls cloud→local and, once the
	 * cloud rows reach zero, clears the draining flag. Self-deactivates when
	 * the store is neither offloading nor draining and no offloaded file
	 * remains, so an idle platform runs nothing.
	 */
	public static function runOffloadTick(): array {
		$msgs = [];
		$had_error = false;
		$mode = self::mode();
		foreach (StorageProfileRegistry::all() as $profile) {
			if ($mode === 'offload') {
				$r = CloudOffloadEngine::syncBatch($profile);
			} elseif ($mode === 'drain') {
				$r = CloudOffloadEngine::reverseBatch($profile);
			} else {
				continue;
			}
			if (($r['status'] ?? '') === 'error') $had_error = true;
			$msgs[] = get_class($profile) . ': ' . ($r['message'] ?? '');
		}

		// Move files: an older store's files to the current one, a batch a
		// tick, whatever the mode but a drain (startDrain() ends a move).
		if ($mode !== 'drain') {
			try {
				$moved = self::moveTick();
				if ($moved !== null) { $msgs[] = $moved; }
			} catch (\Throwable $e) {
				$had_error = true;
				$msgs[] = 'move: ' . $e->getMessage();
			}
		}

		// The store finishes draining when no cloud rows remain across every profile.
		$cloud_rows = self::cloudRowCount();
		if ($mode === 'drain' && $cloud_rows === 0) {
			self::stopDrain();
			$mode = 'idle';
		}
		$in_motion = ($mode !== 'idle') || self::moveState() !== null;

		// While any offloaded file exists, the daily file-store check takes its
		// slice: every offloaded file HEADed once a day, the ones the bucket
		// cannot serve written down for the cloud-storage and Backups pages. A
		// paused store serves the same files as an active one, so the check
		// does not stop with the offloading. Its failure is its own line, never
		// the tick's status: a bucket that will not answer a HEAD is not a
		// reason to stop offloading.
		if ($cloud_rows > 0) {
			try {
				$inv = CloudStoreInventory::tick();
				if (($inv['message'] ?? '') !== '') $msgs[] = $inv['message'];
			} catch (\Throwable $e) {
				$msgs[] = 'file store check: ' . $e->getMessage();
			}
		}

		// What earlier deletes only hid in each file store's folder: a deleted
		// file is gone from its bucket, every version (HiddenVersionSweep, daily
		// per store, within a budget).
		$hidden_left = false;
		foreach (new MultiBackupTarget(array('deleted' => false, 'purpose' => BackupTarget::PURPOSE_FILES)) as $store) {
			try {
				$folder = BackupTarget::normalise_prefix((string)$store->get('bkt_path_prefix')) . '/';
				$swept = HiddenVersionSweep::run($store, $folder, 60);
				$hidden_left = $hidden_left || ($swept !== null && $swept['left'] > 0);
				$line = HiddenVersionSweep::sentence($swept, (string)$store->get('bkt_name'));
				if ($line !== '') { $msgs[] = $line; }
			} catch (\Throwable $e) {
				$msgs[] = 'hidden-version sweep of ' . $store->get('bkt_name') . ': ' . $e->getMessage();
			}
		}

		if (!$msgs) {
			$msgs[] = $cloud_rows > 0
				? 'not offloading or draining; ' . number_format($cloud_rows) . ' offloaded file' . ($cloud_rows === 1 ? '' : 's') . ' under the daily check'
				: 'not offloading or draining';
		}
		$out = [
			'status'  => $had_error ? 'error' : 'success',
			'message' => implode('; ', $msgs),
		];
		if (!$in_motion && $cloud_rows === 0 && !$hidden_left) {
			$out['deactivate'] = true; // nothing to move and nothing to check → scheduler deactivates this task
		}
		return $out;
	}

	private static function _activate_task(string $task_class): void {
		$existing = new MultiScheduledTask(['task_class' => $task_class, 'deleted' => false]);
		$existing->load();
		if ($existing->count_all() > 0) {
			foreach ($existing as $task) {
				$task->set('sct_is_active', true);
				$task->set('sct_frequency', 'every_run');
				$task->save();
			}
			return;
		}
		$json_path = PathHelper::getIncludePath('tasks/' . $task_class . '.json');
		$display_name = $task_class;
		if (file_exists($json_path)) {
			$data = json_decode(file_get_contents($json_path), true);
			if (!empty($data['name'])) $display_name = $data['name'];
		}
		$task = new ScheduledTask(null);
		$task->set('sct_name', $display_name);
		$task->set('sct_task_class', $task_class);
		$task->set('sct_is_active', true);
		$task->set('sct_frequency', 'every_run');
		$task->save();
	}

	private static function _deactivate_task(string $task_class): void {
		$existing = new MultiScheduledTask(['task_class' => $task_class, 'deleted' => false]);
		$existing->load();
		foreach ($existing as $task) {
			$task->set('sct_is_active', false);
			$task->save();
		}
	}

	// ====================================================================
	// Health — generic counts/tasks/driver, parameterized by profile.
	// ====================================================================
	public static function health(StorageProfile $profile): array {
		$settings = Globalvars::get_instance();
		$h = [];

		// Cron heartbeat.
		$last_cron = $settings->get_setting('scheduled_tasks_last_cron_run');
		$cron_ok = false;
		if ($last_cron) {
			try {
				$last = new DateTime($last_cron, new DateTimeZone('UTC'));
				$now = new DateTime('now', new DateTimeZone('UTC'));
				$cron_ok = ($now->getTimestamp() - $last->getTimestamp()) < 1800;
			} catch (Exception $e) { /* leave false */ }
		}
		$h['cron'] = ['ok' => $cron_ok, 'last' => $last_cron];

		// Driver ping. The resolver is the one request-time byte I/O uses: a
		// paused store still serves every offloaded file from the bucket, and a
		// draining one still reads every object back out of it, so a key that
		// stopped working matters just as much off the latch as on it.
		$h['driver'] = null;
		$current = CloudStorageDriverFactory::currentUnlatched();
		$driver = $current ? $current->driver : null;
		if ($driver) {
			try {
				$start = microtime(true);
				$ping = $driver->ping();
				$elapsed_ms = (int)((microtime(true) - $start) * 1000);
				$h['driver'] = ['ok' => $ping['ok'], 'message' => $ping['message'], 'elapsed_ms' => $elapsed_ms];
			} catch (Exception $e) {
				$h['driver'] = ['ok' => false, 'message' => $e->getMessage(), 'elapsed_ms' => 0];
			}
		}

		// Offload task status. One CloudOffloadRun tick drives every profile, so
		// both the sync line and (while draining) the pull-back box read the
		// same task row. reverse_task is populated only while the mode is 'drain'.
		$h['sync_task'] = null;
		$h['reverse_task'] = null;
		$tick = null;
		try {
			$multi = new MultiScheduledTask(['task_class' => self::TICK_TASK, 'deleted' => false]);
			$multi->load();
			foreach ($multi as $task) { $tick = $task; }
		} catch (Exception $e) { /* table might not exist yet */ }
		if ($tick) {
			$status = [
				'is_active'    => (bool)$tick->get('sct_is_active'),
				'last_run'     => $tick->get('sct_last_run_time'),
				'last_status'  => $tick->get('sct_last_run_status'),
				'last_message' => $tick->get('sct_last_run_message'),
			];
			$h['sync_task'] = $status;
			if (self::mode() === 'drain') {
				$h['reverse_task'] = $status;
			}
		}

		// Counts: pending (eligible local) / cloud / stuck / migrated this week.
		$dblink = DbConnector::get_instance()->get_db_link();
		$h['counts'] = ['pending' => 0, 'cloud' => 0, 'stuck' => 0, 'migrated_this_week' => 0, 'pending_bytes' => 0, 'cloud_bytes' => 0];
		$gate = trim($profile->eligibilityWhere());
		$gate_sql = $gate !== '' ? " AND ($gate)" : '';
		// Cloud-side counts are scoped to the profile's own rows when its table
		// also holds rows that are not its own, so the figures are the objects
		// in the bucket.
		$own = (method_exists($profile, 'reverseEligibilityWhere'))
			? trim($profile->reverseEligibilityWhere()) : '';
		$own_sql = $own !== '' ? " AND ($own)" : '';
		$drv = $profile->driverColumn();
		$failed = $profile->failedCountColumn();
		$last_attempt = $profile->lastAttemptColumn();
		$last_error = $profile->lastErrorColumn();
		$missing_sql = "$last_error = " . $dblink->quote(CloudOffloadEngine::MISSING_BYTES);
		// Bytes beside the counts, where the profile names a size column.
		$size = method_exists($profile, 'sizeColumn') ? $profile->sizeColumn() : '0';
		try {
			$row = $dblink->query("
				SELECT
				  COUNT(*) FILTER (WHERE ($drv IS NULL OR $drv = 'local')
				                   AND COALESCE($failed, 0) < " . CloudOffloadEngine::FAILED_COUNT_CAP . "$gate_sql) AS pending,
				  COALESCE(SUM($size) FILTER (WHERE ($drv IS NULL OR $drv = 'local')
				                   AND COALESCE($failed, 0) < " . CloudOffloadEngine::FAILED_COUNT_CAP . "$gate_sql), 0) AS pending_bytes,
				  COUNT(*) FILTER (WHERE $drv = 'cloud'$own_sql) AS cloud,
				  COALESCE(SUM($size) FILTER (WHERE $drv = 'cloud'$own_sql), 0) AS cloud_bytes,
				  COUNT(*) FILTER (WHERE COALESCE($failed, 0) >= " . CloudOffloadEngine::FAILED_COUNT_CAP . " AND NOT ($missing_sql)) AS stuck,
				  COUNT(*) FILTER (WHERE $missing_sql) AS missing,
				  COUNT(*) FILTER (WHERE $drv = 'cloud'$own_sql
				                   AND $last_attempt > now() - interval '7 days') AS migrated_this_week
				FROM {$profile->table()}")->fetch(PDO::FETCH_ASSOC);
			if ($row) {
				$h['counts'] = array_map('intval', $row);
			}
		} catch (Exception $e) { /* schema might not be in place yet */ }

		// Two lists, told apart by the reason. Stuck: a push that failed five
		// times, with its last error, which Retry may cure. Missing: a record
		// with no bytes on this server, which no retry can; permanently
		// deleting the file releases it. The file-blob store carries a
		// stored-name column; a generic store returns id + counters only.
		$h['stuck_rows'] = [];
		$h['missing_rows'] = [];
		$stuck_where = "COALESCE($failed, 0) >= " . CloudOffloadEngine::FAILED_COUNT_CAP . " AND NOT ($missing_sql)";
		foreach (['stuck_rows' => $stuck_where, 'missing_rows' => $missing_sql] as $list => $where) {
			if ($h['counts'][$list === 'stuck_rows' ? 'stuck' : 'missing'] <= 0) continue;
			try {
				if ($profile->table() === 'fbb_file_blobs') {
					$q = $dblink->prepare("
						SELECT fbb_file_blob_id, fbb_stored_name, fbb_sync_last_attempt, fbb_sync_failed_count, fbb_sync_last_error
						FROM fbb_file_blobs
						WHERE $where
						ORDER BY fbb_sync_last_attempt DESC
						LIMIT 25");
				} else {
					$q = $dblink->prepare("
						SELECT {$profile->pkeyColumn()} AS id, $failed AS failed_count, $last_attempt AS last_attempt, $last_error AS last_error
						FROM {$profile->table()}
						WHERE $where
						ORDER BY $last_attempt DESC
						LIMIT 25");
				}
				$q->execute();
				$h[$list] = $q->fetchAll(PDO::FETCH_ASSOC);
			} catch (Exception $e) { /* swallow */ }
		}

		return $h;
	}
}
