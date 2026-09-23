<?php
/**
 * CloudStorageLifecycle — the one shared admin lifecycle for the file store.
 *
 * The admin save/test/activate/health helpers over the one store: a private
 * bucket, one binding, every declared profile. It owns the
 * binding-immutability guard:
 *
 *   Guard 1 (binding immutability): the (endpoint, bucket) identity of the
 *   store is immutable while it holds any 'cloud' row — to switch, disable +
 *   pull back to local first. Access-key rotation (same binding) stays allowed.
 *
 * testConnection() is the Save check, in order, storing nothing on a fail:
 * its own bucket and what the key may do (BucketCheck), reach, write, the
 * privacy gate — an anonymous read of the probe must be DENIED; the probe is
 * the sole sanctioned url() call — and delete.
 *
 * Offload is driven by ONE scheduled task (CloudOffloadRun) for the whole
 * platform. The store's direction each tick is its MODE — offload / drain /
 * idle — derived from the enabled latch + draining flag (mode()).
 * runOffloadTick() walks every declared profile (the registry) and dispatches
 * by mode, so a new consumer adds a StorageProfile and zero tasks. There is no
 * forward/reverse mutual-exclusion to enforce: the store has one mode per tick.
 *
 * A profile whose table also holds rows that are not its own (fbb_file_blobs
 * holds public blobs, which never move) scopes the binding-immutability count
 * and the health cloud-side counts to its own rows via its optional
 * reverseEligibilityWhere() ownership gate.
 *
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

		// Step 2: write — a probe object lands.
		$probe_name = '_joinery_probe-' . bin2hex(random_bytes(4)) . '.txt';
		$probe_local = sys_get_temp_dir() . '/' . $probe_name;
		file_put_contents($probe_local, "joinery-cloud-storage-test\n");
		try {
			$driver->put($probe_local, $probe_name, 'text/plain');
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
		$verdict = self::privacyVerdict(BucketCheck::anonymous_status($driver->url($probe_name)));
		if (!$verdict['pass']) {
			$ok = false;
		}
		$steps[] = ['label' => self::STEP_PRIVATE, 'status' => $verdict['pass'] ? 'pass' : 'fail', 'message' => $verdict['message']];

		// Step 4: delete — the probe goes, so permanent delete and retention work.
		try {
			$driver->delete($probe_name);
			$steps[] = ['label' => self::STEP_DELETE, 'status' => 'pass', 'message' => 'The probe object was deleted.'];
		} catch (Exception $e) {
			$steps[] = ['label' => self::STEP_DELETE, 'status' => 'warn',
				'message' => 'The key cannot delete from this bucket. Permanent delete and permission flips will fail until it can.', 'raw' => $e->getMessage()];
		}

		@unlink($probe_local);
		return ['ok' => $ok, 'steps' => $steps];
	}

	/**
	 * The privacy hard-gate verdict from an anonymous read's HTTP status. An
	 * anonymous 2xx means the bytes are world-readable ⇒ the bucket is public
	 * ⇒ gate FAILS. Any non-2xx (401/403/404/connection refused, status 0)
	 * means anonymous read is denied ⇒ gate PASSES. Separated from the network
	 * probe so the privacy-critical decision is unit-testable.
	 */
	public static function privacyVerdict(int $status): array {
		if ($status >= 200 && $status < 300) {
			return ['pass' => false,
				'message' => 'This bucket is publicly readable (an anonymous request got HTTP ' . $status
					. '); it cannot hold private files. Make it private at the provider and save again.'];
		}
		$shown = $status > 0 ? 'HTTP ' . $status : 'connection refused';
		return ['pass' => true, 'message' => 'Nobody can read this bucket without a key (' . $shown . ').'];
	}

	// ====================================================================
	// Guard 1 — binding immutability.
	// ====================================================================
	/**
	 * Reject a Save that changes (endpoint, bucket) while the store holds any
	 * 'cloud' row (summed across every profile). Same binding ⇒ key rotation
	 * allowed. Returns ['ok'=>true] or ['ok'=>false,'message'=>..].
	 */
	public static function assertBindingMutable(array $opts): array {
		$stored = CloudStorageDriverFactory::binding();
		$same_endpoint = trim((string)($opts['endpoint'] ?? '')) === trim((string)$stored['endpoint']);
		$same_bucket   = trim((string)($opts['bucket'] ?? ''))   === trim((string)$stored['bucket']);
		if ($same_endpoint && $same_bucket) {
			return ['ok' => true];
		}
		$cloud_rows = self::cloudRowCount();
		if ($cloud_rows > 0) {
			return ['ok' => false,
				'message' => 'The bucket holds ' . $cloud_rows
					. ' offloaded file(s); pull them back to local before changing the endpoint or bucket.'];
		}
		return ['ok' => true];
	}

	/**
	 * Sum of 'cloud' rows across every profile. A profile whose table also
	 * holds rows that are not its own is scoped to the cloud rows that are, via
	 * the optional reverseEligibilityWhere() ownership gate.
	 */
	public static function cloudRowCount(): int {
		$dblink = DbConnector::get_instance()->get_db_link();
		$total = 0;
		foreach (StorageProfileRegistry::all() as $profile) {
			$own = (method_exists($profile, 'reverseEligibilityWhere'))
				? trim($profile->reverseEligibilityWhere()) : '';
			$own_sql = $own !== '' ? " AND ($own)" : '';
			try {
				$q = $dblink->query(
					"SELECT COUNT(*) AS c FROM {$profile->table()} WHERE {$profile->driverColumn()} = 'cloud'{$own_sql}");
				$total += (int)$q->fetch(PDO::FETCH_ASSOC)['c'];
			} catch (Exception $e) { /* table may not exist yet */ }
		}
		return $total;
	}

	// ====================================================================
	// Persist settings — guard 1 first; latch the enabled flag.
	// ====================================================================
	public static function persistSettings(array $opts, $session): array {
		$mutable = self::assertBindingMutable($opts);
		if (!$mutable['ok']) {
			return ['ok' => false, 'message' => $mutable['message']];
		}
		self::_write_settings(self::_settings_map($opts), $session);
		CloudStorageDriverFactory::reset();
		return ['ok' => true];
	}

	/**
	 * Store a replacement key against the store's existing binding.
	 *
	 * Writes the key and nothing else. The enabled latch and the draining flag
	 * say what the store is doing; replacing a key says nothing about either,
	 * so a paused store stays paused and a drain in progress keeps draining
	 * with the new key. That last case is the reason the key stays editable at
	 * all: the pull-back reads every object out of the bucket with this key, so
	 * a revoked key with no way to replace it would strand the files it was
	 * meant to rescue.
	 *
	 * The binding is the caller's stored one; a key that names a different
	 * endpoint (Backblaze settles the endpoint from the key) is refused here
	 * rather than stored against objects it cannot reach.
	 */
	public static function persistKey(array $opts, $session): array {
		$stored = CloudStorageDriverFactory::binding();
		$endpoint = StorageProvider::host($opts['endpoint'] ?? '');
		$stored_endpoint = StorageProvider::host($stored['endpoint']);
		if ($endpoint !== '' && $stored_endpoint !== '' && $endpoint !== $stored_endpoint) {
			return ['ok' => false,
				'message' => 'This key belongs to ' . $endpoint . ', and the store is on ' . $stored_endpoint
					. '. A key that moves the store is a new store: disable and pull the files back first.'];
		}
		if (trim((string)($opts['bucket'] ?? '')) !== trim((string)$stored['bucket'])) {
			return ['ok' => false, 'message' => 'The bucket cannot change while replacing a key.'];
		}
		self::_write_settings(self::keySettingsMap($opts), $session);
		CloudStorageDriverFactory::reset();
		return ['ok' => true];
	}

	/**
	 * The settings a key replacement writes: the key, and nothing else. Set
	 * against _settings_map(), which a full Save uses and which carries the
	 * enabled latch — the difference between the two maps is the whole of what
	 * "replacing a key changes nothing else" means.
	 */
	public static function keySettingsMap(array $opts): array {
		return [
			'cloud_storage_access_key' => $opts['access_key'] ?? '',
			'cloud_storage_secret_key' => $opts['secret_key'] ?? '',
		];
	}

	/** The setting map a Save writes: the binding, and the enabled latch. */
	private static function _settings_map(array $opts): array {
		return [
			'cloud_storage_provider'   => StorageProvider::normalise($opts['provider'] ?? ''),
			'cloud_storage_endpoint'   => $opts['endpoint'] ?? '',
			'cloud_storage_region'     => $opts['region'] ?? '',
			'cloud_storage_bucket'     => $opts['bucket'] ?? '',
			'cloud_storage_access_key' => $opts['access_key'] ?? '',
			'cloud_storage_secret_key' => $opts['secret_key'] ?? '',
			'cloud_storage_enabled'    => '1',
		];
	}

	/**
	 * Set the enabled latch, with any other settings to write beside it (a
	 * Remove blanks the binding). Used by the pause / disable / remove flows.
	 */
	public static function setEnabled(bool $enabled, $session, array $extra = []): void {
		$map = ['cloud_storage_enabled' => $enabled ? '1' : '0'];
		foreach ($extra as $k => $v) {
			$map[$k] = $v;
		}
		self::_write_settings($map, $session);
		CloudStorageDriverFactory::reset();
	}

	private static function _write_settings(array $map, $session): void {
		$user_id = $session ? $session->get_user_id() : null;
		$multi = new MultiSetting([], null, null, null, null);
		$multi->load();
		$existing = [];
		foreach ($multi as $row) {
			$existing[$row->get('stg_name')] = $row;
		}
		foreach ($map as $name => $value) {
			if (isset($existing[$name])) {
				$existing[$name]->set('stg_value', $value);
				$existing[$name]->set('stg_update_time', 'NOW()');
				if ($user_id !== null) {
					$existing[$name]->set('stg_usr_user_id', $user_id);
				}
				$existing[$name]->prepare();
				$existing[$name]->save();
			}
		}
		// No in-memory settings refresh here: the admin Save redirects, so the
		// next request re-reads settings fresh; the driver cache is busted by the
		// CloudStorageDriverFactory::reset() the callers run after persisting.
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
		if ($s->get_setting('cloud_storage_enabled')) {
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

	/** Begin draining the store back to local (Disable-and-Pull-Back). */
	public static function startDrain($session): void {
		self::_write_settings(['cloud_storage_draining' => '1'], $session);
		CloudStorageDriverFactory::reset();
		self::ensureTickActive();
	}

	/** Stop draining (drain finished, or store re-enabled). */
	public static function stopDrain($session): void {
		self::_write_settings(['cloud_storage_draining' => '0'], $session);
		CloudStorageDriverFactory::reset();
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

		// The store finishes draining when no cloud rows remain across every profile.
		$cloud_rows = self::cloudRowCount();
		if ($mode === 'drain' && $cloud_rows === 0) {
			self::stopDrain(null);
			$mode = 'idle';
		}
		$in_motion = ($mode !== 'idle');

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

		if (!$msgs) {
			$msgs[] = $cloud_rows > 0
				? 'not offloading or draining; ' . number_format($cloud_rows) . ' offloaded file' . ($cloud_rows === 1 ? '' : 's') . ' under the daily check'
				: 'not offloading or draining';
		}
		$out = [
			'status'  => $had_error ? 'error' : 'success',
			'message' => implode('; ', $msgs),
		];
		if (!$in_motion && $cloud_rows === 0) {
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
		$driver = CloudStorageDriverFactory::driverWithFallback();
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
