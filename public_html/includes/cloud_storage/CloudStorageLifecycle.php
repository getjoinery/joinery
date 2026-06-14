<?php
/**
 * CloudStorageLifecycle — the one shared admin lifecycle for offload stores.
 *
 * The relocated admin save/test/activate/health helpers, parameterized by
 * store (visibility) and profile. The storage layer holds the per-visibility
 * setting bindings, so the lifecycle resolves them from a visibility string.
 * It owns the two guards this refactor adds:
 *
 *   Guard 1 (binding immutability): the (endpoint, bucket) identity of a store
 *   is immutable while that store holds any 'cloud' row — to switch, disable +
 *   pull back to local first. Access-key rotation (same binding) stays allowed.
 *
 *   Guard 2 (forward/reverse mutual exclusion): activating the forward task
 *   deactivates the reverse task and vice versa, so a row can never ping-pong.
 *
 * testConnection branches on visibility for the read-policy assertion:
 *   public  → anonymous read must WORK.
 *   private → anonymous read must be DENIED (the privacy hard-gate). The probe
 *             is the sole sanctioned url() call on a private store.
 *
 * Store enable/disable is applied per visibility (activateForwardForVisibility
 * etc.): a store's Save lights up offload for EVERY profile of that visibility,
 * resolved from the registry, so consumers are never enumerated by callers.
 *
 * @version 1.1
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

	/** Sum of 'cloud' rows across every profile of a visibility. */
	public static function cloudRowCount(string $visibility): int {
		$dblink = DbConnector::get_instance()->get_db_link();
		$total = 0;
		foreach (StorageProfileRegistry::forVisibility($visibility) as $profile) {
			try {
				$q = $dblink->query(
					"SELECT COUNT(*) AS c FROM {$profile->table()} WHERE {$profile->driverColumn()} = 'cloud'");
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
	// Guard 2 — forward/reverse mutual exclusion, applied per store.
	// ====================================================================
	public static function activateForward(StorageProfile $profile): void {
		self::_activate_task($profile->forwardTaskClass());
		self::_deactivate_task($profile->reverseTaskClass());
	}

	public static function activateReverse(StorageProfile $profile): void {
		self::_activate_task($profile->reverseTaskClass());
		self::_deactivate_task($profile->forwardTaskClass());
	}

	/** Pause: stop new migrations (forward off); existing cloud rows keep serving. */
	public static function deactivate(StorageProfile $profile): void {
		self::_deactivate_task($profile->forwardTaskClass());
	}

	// --------------------------------------------------------------------
	// Store-level activation — drive every profile of a visibility.
	//
	// A store's enable/disable is a property of the STORE, not of one
	// consumer: enabling the private store must start offload for every
	// private-visibility profile (mail today, more later), not just a named
	// one. These resolve the set from the registry so a caller never has to
	// enumerate profiles, and a newly-declared consumer is picked up for free.
	// --------------------------------------------------------------------
	public static function activateForwardForVisibility(string $visibility): void {
		foreach (StorageProfileRegistry::forVisibility($visibility) as $profile) {
			self::activateForward($profile);
		}
	}

	public static function activateReverseForVisibility(string $visibility): void {
		foreach (StorageProfileRegistry::forVisibility($visibility) as $profile) {
			self::activateReverse($profile);
		}
	}

	public static function deactivateForVisibility(string $visibility): void {
		foreach (StorageProfileRegistry::forVisibility($visibility) as $profile) {
			self::deactivate($profile);
		}
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

		// Forward task status.
		$h['sync_task'] = null;
		try {
			$multi = new MultiScheduledTask(['task_class' => $profile->forwardTaskClass(), 'deleted' => false]);
			$multi->load();
			foreach ($multi as $task) {
				$h['sync_task'] = [
					'is_active'    => (bool)$task->get('sct_is_active'),
					'last_run'     => $task->get('sct_last_run_time'),
					'last_status'  => $task->get('sct_last_run_status'),
					'last_message' => $task->get('sct_last_run_message'),
				];
			}
		} catch (Exception $e) { /* table might not exist yet */ }

		// Counts: pending (eligible local) / cloud / stuck / migrated this week.
		$dblink = DbConnector::get_instance()->get_db_link();
		$h['counts'] = ['pending' => 0, 'cloud' => 0, 'stuck' => 0, 'migrated_this_week' => 0];
		$gate = trim($profile->eligibilityWhere());
		$gate_sql = $gate !== '' ? " AND ($gate)" : '';
		$drv = $profile->driverColumn();
		$failed = $profile->failedCountColumn();
		$last_attempt = $profile->lastAttemptColumn();
		try {
			$row = $dblink->query("
				SELECT
				  COUNT(*) FILTER (WHERE ($drv IS NULL OR $drv = 'local')
				                   AND COALESCE($failed, 0) < " . CloudOffloadEngine::FAILED_COUNT_CAP . "$gate_sql) AS pending,
				  COUNT(*) FILTER (WHERE $drv = 'cloud') AS cloud,
				  COUNT(*) FILTER (WHERE COALESCE($failed, 0) >= " . CloudOffloadEngine::FAILED_COUNT_CAP . ") AS stuck,
				  COUNT(*) FILTER (WHERE $drv = 'cloud'
				                   AND $last_attempt > now() - interval '7 days') AS migrated_this_week
				FROM {$profile->table()}")->fetch(PDO::FETCH_ASSOC);
			if ($row) {
				$h['counts'] = array_map('intval', $row);
			}
		} catch (Exception $e) { /* schema might not be in place yet */ }

		// Stuck rows list. The public files store carries a name column for the
		// admin retry UI; a generic store returns id + counters only.
		$h['stuck_rows'] = [];
		if ($h['counts']['stuck'] > 0) {
			try {
				if ($profile->table() === 'fil_files') {
					$q = $dblink->prepare("
						SELECT fil_file_id, fil_name, fil_sync_last_attempt, fil_sync_failed_count
						FROM fil_files
						WHERE COALESCE(fil_sync_failed_count, 0) >= " . CloudOffloadEngine::FAILED_COUNT_CAP . "
						  AND fil_delete_time IS NULL
						ORDER BY fil_sync_last_attempt DESC
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

		// Reverse task status (only shown when active).
		$h['reverse_task'] = null;
		try {
			$multi = new MultiScheduledTask(['task_class' => $profile->reverseTaskClass(), 'deleted' => false]);
			$multi->load();
			foreach ($multi as $task) {
				if ($task->get('sct_is_active')) {
					$h['reverse_task'] = [
						'last_run'     => $task->get('sct_last_run_time'),
						'last_status'  => $task->get('sct_last_run_status'),
						'last_message' => $task->get('sct_last_run_message'),
					];
				}
			}
		} catch (Exception $e) { /* swallow */ }

		return $h;
	}
}
