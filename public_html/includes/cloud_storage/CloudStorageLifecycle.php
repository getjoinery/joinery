<?php
/**
 * CloudStorageLifecycle — the one shared admin lifecycle for offload stores.
 *
 * The relocated admin save/test/activate/health helpers, parameterized by
 * store (visibility) and profile. The storage layer holds the per-visibility
 * setting bindings, so the lifecycle resolves them from a visibility string.
 * It owns the binding-immutability guard:
 *
 *   Guard 1 (binding immutability): the (endpoint, bucket) identity of a store
 *   is immutable while that store holds any 'cloud' row — to switch, disable +
 *   pull back to local first. Access-key rotation (same binding) stays allowed.
 *
 * testConnection branches on visibility for the read-policy assertion:
 *   public  → anonymous read must WORK.
 *   private → anonymous read must be DENIED (the privacy hard-gate). The probe
 *             is the sole sanctioned url() call on a private store.
 *
 * Offload is driven by ONE scheduled task (CloudOffloadRun) for the whole
 * platform. A store's direction each tick is its MODE — offload / drain / idle —
 * derived from the store's enabled latch + draining flag (modeForVisibility()).
 * runOffloadTick() walks every declared profile (the registry) and dispatches
 * by mode, so a new consumer adds a StorageProfile and zero tasks. There is no
 * forward/reverse mutual-exclusion to enforce: a store has one mode per tick.
 *
 * When two profiles share a table (the public and private File profiles share
 * fil_files), the binding-immutability count and health cloud-side counts scope
 * each store to its own rows via the profile's optional reverseEligibilityWhere()
 * ownership gate.
 *
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfile.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfileRegistry.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

class CloudStorageLifecycle {

	// ====================================================================
	// Test connection — branches on visibility for the read-policy assertion.
	// PUT/HEAD/DELETE probe mechanics are shared.
	// ====================================================================
	public static function testConnection(array $opts, string $visibility): array {
		require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageS3Driver.php'));
		$steps = [];
		$ok = true;

		// Step 1: HeadBucket.
		try {
			$driver = CloudStorageDriverFactory::fromOptions($opts);
			$ping = $driver->ping();
			if ($ping['ok']) {
				$steps[] = ['label' => 'Reach + authenticate', 'status' => 'pass',
					'message' => 'Reached and authenticated (' . htmlspecialchars($opts['endpoint']) . ')'];
			} else {
				$steps[] = ['label' => 'Reach + authenticate', 'status' => 'fail', 'message' => 'HeadBucket failed.', 'raw' => $ping['message']];
				$steps[] = ['label' => self::_read_label($visibility), 'status' => 'skip', 'message' => 'skipped (prior step failed)'];
				$steps[] = ['label' => 'Delete', 'status' => 'skip', 'message' => 'skipped (prior step failed)'];
				return ['ok' => false, 'steps' => $steps];
			}
		} catch (Exception $e) {
			return ['ok' => false, 'steps' => [
				['label' => 'Reach + authenticate', 'status' => 'fail', 'message' => 'Driver could not be constructed.', 'raw' => $e->getMessage()],
				['label' => self::_read_label($visibility), 'status' => 'skip', 'message' => 'skipped (prior step failed)'],
				['label' => 'Delete', 'status' => 'skip', 'message' => 'skipped (prior step failed)'],
			]];
		}

		// Step 2: PUT scratch probe.
		$probe_name = '_joinery_probe-' . bin2hex(random_bytes(4)) . '.txt';
		$probe_local = sys_get_temp_dir() . '/' . $probe_name;
		file_put_contents($probe_local, "joinery-cloud-storage-test\n");

		$probe_pushed = false;
		try {
			$driver->put($probe_local, $probe_name, 'text/plain');
			$probe_pushed = true;
		} catch (Exception $e) {
			$steps[] = ['label' => self::_read_label($visibility), 'status' => 'fail', 'message' => 'PUT to bucket failed.', 'raw' => $e->getMessage()];
			@unlink($probe_local);
			$steps[] = ['label' => 'Delete', 'status' => 'skip', 'message' => 'skipped (prior step failed)'];
			return ['ok' => false, 'steps' => $steps];
		}

		// The bucket's direct URL for the probe — the exact URL a misconfigured
		// public bucket would serve, fetched anonymously (no credentials).
		$probe_url = $driver->url($probe_name);

		if ($visibility === 'private') {
			// PRIVACY HARD-GATE: anonymous read must be DENIED.
			$verdict = self::privacyVerdict(self::_anonymous_status($probe_url));
			if (!$verdict['pass']) {
				$ok = false;
			}
			$steps[] = ['label' => 'Verify NOT publicly readable',
				'status' => $verdict['pass'] ? 'pass' : 'fail',
				'message' => $verdict['message'], 'raw' => $probe_url];
		} else {
			// PUBLIC: anonymous read must WORK (CDN markers inspected too).
			$inspection = CloudStorageS3Driver::inspectPublicUrl($probe_url);
			if (!$inspection['reachable']) {
				$ok = false;
				$steps[] = ['label' => 'Write + read public', 'status' => 'fail',
					'message' => 'Public read of probe failed (HEAD did not return a response).', 'raw' => $probe_url];
			} else {
				$head_lines = @get_headers($probe_url);
				$status_line = $head_lines && is_array($head_lines) ? $head_lines[0] : '';
				if (preg_match('/\b(200|204)\b/', $status_line)) {
					if ($inspection['cdn']) {
						$detail = ' — ' . $inspection['cdn'] . ' detected (CDN egress).';
					} elseif ($inspection['raw_provider']) {
						$detail = ' — ' . $inspection['raw_provider'] . ' (egress warning applies).';
					} else {
						$detail = '';
					}
					$steps[] = ['label' => 'Write + read public', 'status' => 'pass', 'message' => 'Public read OK' . $detail,
						'cdn' => $inspection['cdn'], 'raw_provider' => $inspection['raw_provider']];
				} elseif (preg_match('/\b(401|403)\b/', $status_line, $code_m)) {
					$steps[] = ['label' => 'Write + read public', 'status' => 'warn',
						'message' => 'PUT OK; public read returned ' . $code_m[1] . '. Bucket appears to be private. Files served via the bucket URL will ' . $code_m[1] . ' to users until the bucket policy allows GetObject (or a CDN/proxy fronts the bucket).',
						'raw' => $probe_url];
				} else {
					$ok = false;
					$steps[] = ['label' => 'Write + read public', 'status' => 'fail',
						'message' => 'Public read returned: ' . $status_line . '. Check the public base URL.', 'raw' => $probe_url];
				}
			}
		}

		// Step 3: DELETE scratch probe.
		if ($probe_pushed) {
			try {
				$driver->delete($probe_name);
				$steps[] = ['label' => 'Delete', 'status' => 'pass', 'message' => 'Scratch probe deleted.'];
			} catch (Exception $e) {
				$steps[] = ['label' => 'Delete', 'status' => 'warn',
					'message' => 'Credentials lack delete permission. permanent_delete and permission flips will fail until fixed.', 'raw' => $e->getMessage()];
			}
		}

		@unlink($probe_local);
		return ['ok' => $ok, 'steps' => $steps];
	}

	private static function _read_label(string $visibility): string {
		return $visibility === 'private' ? 'Verify NOT publicly readable' : 'Write + read public';
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
				'message' => 'This bucket is publicly readable (anonymous GET returned ' . $status
					. '); it cannot be used for private files. Make it private and re-test.'];
		}
		$shown = $status > 0 ? (string)$status : 'connection refused';
		return ['pass' => true, 'message' => 'Anonymous read denied (' . $shown . '). Bucket is private.'];
	}

	/**
	 * Anonymous HTTP status for a URL — no credentials, no body. Returns the
	 * numeric status, or 0 if the connection could not be made (refused/timeout).
	 */
	private static function _anonymous_status(string $url): int {
		$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
		$lines = @get_headers($url, false, $context);
		if (!$lines || !is_array($lines)) {
			return 0;
		}
		if (preg_match('/\s(\d{3})\s/', ' ' . $lines[0] . ' ', $m)) {
			return (int)$m[1];
		}
		return 0;
	}

	// ====================================================================
	// Guard 1 — binding immutability.
	// ====================================================================
	/**
	 * Reject a Save that changes (endpoint, bucket) for a store that holds any
	 * 'cloud' row (summed across that visibility's profiles). Same binding ⇒
	 * key rotation allowed. Returns ['ok'=>true] or ['ok'=>false,'message'=>..].
	 */
	public static function assertBindingMutable(array $opts, string $visibility): array {
		$stored = CloudStorageDriverFactory::bindingFor($visibility);
		$same_endpoint = trim((string)($opts['endpoint'] ?? '')) === trim((string)$stored['endpoint']);
		$same_bucket   = trim((string)($opts['bucket'] ?? ''))   === trim((string)$stored['bucket']);
		if ($same_endpoint && $same_bucket) {
			return ['ok' => true];
		}
		$cloud_rows = self::cloudRowCount($visibility);
		if ($cloud_rows > 0) {
			return ['ok' => false,
				'message' => 'This ' . $visibility . ' store holds ' . $cloud_rows
					. ' offloaded object(s); pull them back to local before changing the endpoint or bucket.'];
		}
		return ['ok' => true];
	}

	/**
	 * Sum of 'cloud' rows across every profile of a visibility. When several
	 * profiles share a table (the public and private File profiles share
	 * fil_files), each is scoped to the cloud rows physically in ITS bucket via
	 * the optional reverseEligibilityWhere() ownership gate — so guard 1 counts
	 * a store's own offloaded objects, not the other store's.
	 */
	public static function cloudRowCount(string $visibility): int {
		$dblink = DbConnector::get_instance()->get_db_link();
		$total = 0;
		foreach (StorageProfileRegistry::forVisibility($visibility) as $profile) {
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
	// Persist settings — guard 1 first; latch the visibility's enabled flag.
	// ====================================================================
	public static function persistSettings(array $opts, string $visibility, $session): array {
		$mutable = self::assertBindingMutable($opts, $visibility);
		if (!$mutable['ok']) {
			return ['ok' => false, 'message' => $mutable['message']];
		}
		$map = self::_settings_map($visibility, $opts);
		self::_write_settings($map, $session);
		CloudStorageDriverFactory::reset();
		return ['ok' => true];
	}

	/**
	 * The setting map written for a store's Save. Public writes the full
	 * binding + enables; private writes only its bucket + latches its enabled
	 * flag (shared creds are owned by the public Save).
	 */
	private static function _settings_map(string $visibility, array $opts): array {
		if ($visibility === 'private') {
			return [
				'cloud_storage_private_bucket'  => trim((string)($opts['bucket'] ?? '')),
				'cloud_storage_private_enabled' => '1',
			];
		}
		return [
			'cloud_storage_endpoint'        => $opts['endpoint'] ?? '',
			'cloud_storage_region'          => $opts['region'] ?? '',
			'cloud_storage_bucket'          => $opts['bucket'] ?? '',
			'cloud_storage_access_key'      => $opts['access_key'] ?? '',
			'cloud_storage_secret_key'      => $opts['secret_key'] ?? '',
			'cloud_storage_public_base_url' => $opts['public_base_url'] ?? '',
			'cloud_storage_enabled'         => '1',
		];
	}

	/**
	 * Disable a store (pause / clear). Sets the visibility's enabled flag off,
	 * and for a cleared private store also blanks its bucket. Used by the
	 * pause / disable flows.
	 */
	public static function setEnabled(string $visibility, bool $enabled, $session, array $extra = []): void {
		$map = [self::_enabled_setting($visibility) => $enabled ? '1' : '0'];
		foreach ($extra as $k => $v) {
			$map[$k] = $v;
		}
		self::_write_settings($map, $session);
		CloudStorageDriverFactory::reset();
	}

	private static function _enabled_setting(string $visibility): string {
		return $visibility === 'private' ? 'cloud_storage_private_enabled' : 'cloud_storage_enabled';
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
	// One scheduled task (CloudOffloadRun) drives every store. Each store's
	// direction for a tick is its MODE, derived from store-level settings:
	//
	//   offload — store enabled: push eligible local rows up to the bucket.
	//   drain   — store disabled with the draining flag set (Disable-and-Pull):
	//             pull cloud rows back to local until none remain.
	//   idle    — store disabled, not draining (paused / never configured):
	//             do nothing; existing cloud rows keep serving.
	//
	// A row can never ping-pong between local and cloud: a store has exactly one
	// mode per tick, so the old forward/reverse mutual-exclusion is structural
	// now rather than an enforced guard.
	// ====================================================================

	const TICK_TASK = 'CloudOffloadRun';

	/** Setting holding a store's draining flag. */
	private static function _draining_setting(string $visibility): string {
		return $visibility === 'private' ? 'cloud_storage_private_draining' : 'cloud_storage_draining';
	}

	/** A store's current offload mode: 'offload' | 'drain' | 'idle'. */
	public static function modeForVisibility(string $visibility): string {
		$s = Globalvars::get_instance();
		if ($s->get_setting(self::_enabled_setting($visibility))) {
			return 'offload';
		}
		if ($s->get_setting(self::_draining_setting($visibility))) {
			return 'drain';
		}
		return 'idle';
	}

	/** Ensure the single offload tick task exists and is active. */
	public static function ensureTickActive(): void {
		self::_activate_task(self::TICK_TASK);
	}

	/** Begin draining a store back to local (Disable-and-Pull-Back). */
	public static function startDrain(string $visibility, $session): void {
		self::_write_settings([self::_draining_setting($visibility) => '1'], $session);
		CloudStorageDriverFactory::reset();
		self::ensureTickActive();
	}

	/** Stop draining a store (drain finished, or store re-enabled). */
	public static function stopDrain(string $visibility, $session): void {
		self::_write_settings([self::_draining_setting($visibility) => '0'], $session);
		CloudStorageDriverFactory::reset();
	}

	/**
	 * The single offload tick: drive every declared store by its mode. Offload
	 * stores push local→cloud; draining stores pull cloud→local and, once their
	 * cloud rows reach zero, clear their draining flag. Self-deactivates when no
	 * store is offloading or draining, so an idle platform runs nothing.
	 */
	public static function runOffloadTick(): array {
		$msgs = [];
		$had_error = false;
		foreach (StorageProfileRegistry::all() as $profile) {
			$mode = self::modeForVisibility($profile->visibility());
			if ($mode === 'offload') {
				$r = CloudOffloadEngine::syncBatch($profile);
			} elseif ($mode === 'drain') {
				$r = CloudOffloadEngine::reverseBatch($profile);
			} else {
				continue;
			}
			if (($r['status'] ?? '') === 'error') $had_error = true;
			$msgs[] = $profile->visibility() . '/' . get_class($profile) . ': ' . ($r['message'] ?? '');
		}

		// A store finishes draining when no cloud rows remain across its profiles.
		$still_active = false;
		foreach (['public', 'private'] as $visibility) {
			$mode = self::modeForVisibility($visibility);
			if ($mode === 'drain' && self::cloudRowCount($visibility) === 0) {
				self::stopDrain($visibility, null);
				$mode = 'idle';
			}
			if ($mode !== 'idle') $still_active = true;
		}

		$out = [
			'status'  => $had_error ? 'error' : 'success',
			'message' => $msgs ? implode('; ', $msgs) : 'no store offloading or draining',
		];
		if (!$still_active) {
			$out['deactivate'] = true; // nothing to do → scheduler deactivates this task
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

		// Driver ping for this store's visibility (only if usable).
		$h['driver'] = null;
		$driver = CloudStorageDriverFactory::forVisibility($profile->visibility());
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

		// Offload task status. One CloudOffloadRun tick drives every store, so
		// both the sync line and (when this store is draining) the pull-back box
		// read the same task row. reverse_task is populated only while THIS
		// store's mode is 'drain', preserving the admin view's per-store display.
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
			if (self::modeForVisibility($profile->visibility()) === 'drain') {
				$h['reverse_task'] = $status;
			}
		}

		// Counts: pending (eligible local) / cloud / stuck / migrated this week.
		$dblink = DbConnector::get_instance()->get_db_link();
		$h['counts'] = ['pending' => 0, 'cloud' => 0, 'stuck' => 0, 'migrated_this_week' => 0];
		$gate = trim($profile->eligibilityWhere());
		$gate_sql = $gate !== '' ? " AND ($gate)" : '';
		// Cloud-side counts are scoped to this store's own rows when the table is
		// shared (public/private File profiles share fil_files), so a store's
		// health reflects only the objects in its bucket.
		$own = (method_exists($profile, 'reverseEligibilityWhere'))
			? trim($profile->reverseEligibilityWhere()) : '';
		$own_sql = $own !== '' ? " AND ($own)" : '';
		$drv = $profile->driverColumn();
		$failed = $profile->failedCountColumn();
		$last_attempt = $profile->lastAttemptColumn();
		try {
			$row = $dblink->query("
				SELECT
				  COUNT(*) FILTER (WHERE ($drv IS NULL OR $drv = 'local')
				                   AND COALESCE($failed, 0) < " . CloudOffloadEngine::FAILED_COUNT_CAP . "$gate_sql) AS pending,
				  COUNT(*) FILTER (WHERE $drv = 'cloud'$own_sql) AS cloud,
				  COUNT(*) FILTER (WHERE COALESCE($failed, 0) >= " . CloudOffloadEngine::FAILED_COUNT_CAP . ") AS stuck,
				  COUNT(*) FILTER (WHERE $drv = 'cloud'$own_sql
				                   AND $last_attempt > now() - interval '7 days') AS migrated_this_week
				FROM {$profile->table()}")->fetch(PDO::FETCH_ASSOC);
			if ($row) {
				$h['counts'] = array_map('intval', $row);
			}
		} catch (Exception $e) { /* schema might not be in place yet */ }

		// Stuck rows list. The file-blob store carries a stored-name column for the
		// admin retry UI; a generic store returns id + counters only.
		$h['stuck_rows'] = [];
		if ($h['counts']['stuck'] > 0) {
			try {
				if ($profile->table() === 'fbb_file_blobs') {
					$q = $dblink->prepare("
						SELECT fbb_file_blob_id, fbb_stored_name, fbb_sync_last_attempt, fbb_sync_failed_count
						FROM fbb_file_blobs
						WHERE COALESCE(fbb_sync_failed_count, 0) >= " . CloudOffloadEngine::FAILED_COUNT_CAP . "
						ORDER BY fbb_sync_last_attempt DESC
						LIMIT 25");
					$q->execute();
					$h['stuck_rows'] = $q->fetchAll(PDO::FETCH_ASSOC);
				} else {
					$q = $dblink->prepare("
						SELECT {$profile->pkeyColumn()} AS id, $failed AS failed_count, $last_attempt AS last_attempt
						FROM {$profile->table()}
						WHERE COALESCE($failed, 0) >= " . CloudOffloadEngine::FAILED_COUNT_CAP . "
						ORDER BY $last_attempt DESC
						LIMIT 25");
					$q->execute();
					$h['stuck_rows'] = $q->fetchAll(PDO::FETCH_ASSOC);
				}
			} catch (Exception $e) { /* swallow */ }
		}

		return $h;
	}
}
