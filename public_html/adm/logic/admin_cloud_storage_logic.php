<?php
/**
 * Cloud Storage Admin Logic
 *
 * Drives the storage admin page. Save = test + persist + activate. Pause
 * and "Disable and Pull Files Back to Local" are the only other actions.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_cloud_storage_logic($get, $post) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
	require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageS3Driver.php'));
	require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$settings = Globalvars::get_instance();

	$test_results = null;
	$errors = array();

	if ($post && isset($post['action'])) {
		$action = $post['action'];

		if ($action === 'save') {
			$opts = array(
				'endpoint'        => trim($post['cloud_storage_endpoint'] ?? ''),
				'region'          => trim($post['cloud_storage_region'] ?? ''),
				'bucket'          => trim($post['cloud_storage_bucket'] ?? ''),
				'access_key'      => trim($post['cloud_storage_access_key'] ?? ''),
				'secret_key'      => trim($post['cloud_storage_secret_key'] ?? ''),
				'public_base_url' => trim($post['cloud_storage_public_base_url'] ?? ''),
			);

			// Required-field check before touching the network.
			$required = ['endpoint', 'bucket', 'access_key', 'secret_key'];
			foreach ($required as $field) {
				if ($opts[$field] === '') {
					$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
				}
			}

			if (empty($errors)) {
				$test_results = _cloud_storage_test_connection($opts);

				if ($test_results['ok']) {
					_cloud_storage_persist_settings($opts, $session);
					_cloud_storage_activate_sync_task();
					$session->save_message(new DisplayMessage(
						'Cloud storage enabled. Migration of existing public files will start on the next cron tick.',
						'Saved', '/\/admin\/admin_cloud_storage/',
						DisplayMessage::MESSAGE_ANNOUNCEMENT,
						DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
					return LogicResult::redirect('/admin/admin_cloud_storage');
				}
				// Test failed — don't persist; render diagnostic.
			}
		}
		elseif ($action === 'pause') {
			_cloud_storage_set_enabled(false, $session);
			_cloud_storage_deactivate_task('CloudStorageSync');
			$session->save_message(new DisplayMessage(
				'Cloud storage paused. Existing cloud-stored files continue to serve from the bucket.',
				'Paused', '/\/admin\/admin_cloud_storage/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
		elseif ($action === 'disable_and_pull') {
			_cloud_storage_set_enabled(false, $session);
			_cloud_storage_deactivate_task('CloudStorageSync');
			_cloud_storage_activate_task('CloudStorageReverseSync');
			$session->save_message(new DisplayMessage(
				'Pull-back started. Bucket-stored files will be returned to local disk over the next several cron ticks.',
				'Pull-back queued', '/\/admin\/admin_cloud_storage/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
		elseif ($action === 'retry_stuck' && isset($post['fil_file_id'])) {
			$dbconnector = DbConnector::get_instance();
			$dblink = $dbconnector->get_db_link();
			$q = $dblink->prepare("UPDATE fil_files SET fil_sync_failed_count = 0 WHERE fil_file_id = ?");
			$q->execute([(int)$post['fil_file_id']]);
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
	}

	// On a failed save, repopulate from POST so the admin doesn't lose what
	// they just typed. (We only reach this branch when test_results is set,
	// i.e. the request was a POST that didn't redirect.)
	$pick = function($key) use ($post, $settings) {
		if (isset($post[$key])) return $post[$key];
		return $settings->get_setting($key);
	};

	// Build the page context.
	$page_data = array(
		'session'         => $session,
		'settings_values' => array(
			'endpoint'        => $pick('cloud_storage_endpoint'),
			'region'          => $pick('cloud_storage_region'),
			'bucket'          => $pick('cloud_storage_bucket'),
			'access_key'      => $pick('cloud_storage_access_key'),
			'secret_key'      => $pick('cloud_storage_secret_key'),
			'public_base_url' => $pick('cloud_storage_public_base_url'),
		),
		'enabled'        => (bool)$settings->get_setting('cloud_storage_enabled'),
		'errors'         => $errors,
		'test_results'   => $test_results,
		'health'         => _cloud_storage_health($settings),
		'display_messages' => $session->get_messages('/admin/admin_cloud_storage'),
	);
	$session->clear_clearable_messages();

	return LogicResult::render($page_data);
}

/**
 * Three-step Test Connection: HeadBucket → PUT+HEAD scratch probe → DELETE scratch.
 *
 * Returns:
 *   ['ok' => bool,
 *    'steps' => [ ['label'=>str, 'status'=>'pass'|'fail'|'warn'|'skip', 'message'=>str, 'raw'=>?str], ... ]]
 */
function _cloud_storage_test_connection(array $opts) {
	$steps = array();
	$ok = true;

	// Step 1: HeadBucket.
	try {
		$driver = CloudStorageDriverFactory::fromOptions($opts);
		$ping = $driver->ping();
		if ($ping['ok']) {
			$steps[] = array(
				'label' => 'Reach + authenticate',
				'status' => 'pass',
				'message' => 'Reached and authenticated (' . htmlspecialchars($opts['endpoint']) . ')',
			);
		} else {
			$ok = false;
			$steps[] = array(
				'label' => 'Reach + authenticate',
				'status' => 'fail',
				'message' => 'HeadBucket failed.',
				'raw' => $ping['message'],
			);
			$steps[] = array('label' => 'Write + read public', 'status' => 'skip', 'message' => 'skipped (prior step failed)');
			$steps[] = array('label' => 'Delete', 'status' => 'skip', 'message' => 'skipped (prior step failed)');
			return array('ok' => false, 'steps' => $steps);
		}
	} catch (Exception $e) {
		return array(
			'ok' => false,
			'steps' => array(
				array('label' => 'Reach + authenticate', 'status' => 'fail', 'message' => 'Driver could not be constructed.', 'raw' => $e->getMessage()),
				array('label' => 'Write + read public', 'status' => 'skip', 'message' => 'skipped (prior step failed)'),
				array('label' => 'Delete', 'status' => 'skip', 'message' => 'skipped (prior step failed)'),
			),
		);
	}

	// Step 2: PUT scratch probe + HEAD via public URL.
	$probe_name = '_joinery_probe-' . bin2hex(random_bytes(4)) . '.txt';
	$probe_local = sys_get_temp_dir() . '/' . $probe_name;
	file_put_contents($probe_local, "joinery-cloud-storage-test\n");

	$probe_pushed = false;
	try {
		$driver->put($probe_local, $probe_name, 'text/plain');
		$probe_pushed = true;
	} catch (Exception $e) {
		$ok = false;
		$steps[] = array(
			'label' => 'Write + read public',
			'status' => 'fail',
			'message' => 'PUT to bucket failed.',
			'raw' => $e->getMessage(),
		);
		@unlink($probe_local);
		$steps[] = array('label' => 'Delete', 'status' => 'skip', 'message' => 'skipped (prior step failed)');
		return array('ok' => false, 'steps' => $steps);
	}

	// HEAD via public URL — both verifies public read AND inspects CDN markers.
	$probe_url = $driver->getPublicBaseUrl() . '/' . CloudStorageS3Driver::getPathPrefix() . '/' . $probe_name;
	$inspection = CloudStorageS3Driver::inspectPublicUrl($probe_url);

	if (!$inspection['reachable']) {
		$ok = false;
		$steps[] = array(
			'label' => 'Write + read public',
			'status' => 'fail',
			'message' => 'Public read of probe failed (HEAD did not return a response).',
			'raw' => $probe_url,
		);
	} else {
		// reachable means HEAD got headers, but check first-line for status.
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
			$steps[] = array(
				'label' => 'Write + read public',
				'status' => 'pass',
				'message' => 'Public read OK' . $detail,
				'cdn' => $inspection['cdn'],
				'raw_provider' => $inspection['raw_provider'],
			);
		} elseif (preg_match('/\b(401|403)\b/', $status_line, $code_m)) {
			// PUT succeeded but the bucket denies anonymous reads (401 from B2,
			// 403 from AWS S3). Treat as a warning, not a fail: credentials
			// clearly work; the admin may intend to enable public-read later
			// or front the bucket with a CDN that authenticates differently.
			// Files migrated to this bucket will return the same code to
			// end-users until the policy is fixed.
			$steps[] = array(
				'label' => 'Write + read public',
				'status' => 'warn',
				'message' => 'PUT OK; public read returned ' . $code_m[1] . '. Bucket appears to be private. Files served via the bucket URL will ' . $code_m[1] . ' to users until the bucket policy allows GetObject (or a CDN/proxy fronts the bucket).',
				'raw' => $probe_url,
			);
		} else {
			$ok = false;
			$steps[] = array(
				'label' => 'Write + read public',
				'status' => 'fail',
				'message' => 'Public read returned: ' . $status_line . '. Check the public base URL.',
				'raw' => $probe_url,
			);
		}
	}

	// Step 3: DELETE scratch probe.
	if ($probe_pushed) {
		try {
			$driver->delete($probe_name);
			$steps[] = array('label' => 'Delete', 'status' => 'pass', 'message' => 'Scratch probe deleted.');
		} catch (Exception $e) {
			$steps[] = array(
				'label' => 'Delete',
				'status' => 'warn',
				'message' => 'Credentials lack delete permission. permanent_delete and permission flips will fail until fixed.',
				'raw' => $e->getMessage(),
			);
			// Warn but don't fail the test overall — read/write succeeded.
		}
	}

	@unlink($probe_local);
	return array('ok' => $ok, 'steps' => $steps);
}

function _cloud_storage_persist_settings(array $opts, $session) {
	$user_id = $session->get_user_id();
	$map = array(
		'cloud_storage_endpoint'        => $opts['endpoint'],
		'cloud_storage_region'          => $opts['region'],
		'cloud_storage_bucket'          => $opts['bucket'],
		'cloud_storage_access_key'      => $opts['access_key'],
		'cloud_storage_secret_key'      => $opts['secret_key'],
		'cloud_storage_public_base_url' => $opts['public_base_url'],
		'cloud_storage_enabled'         => '1',
	);
	$multi = new MultiSetting(array(), null, null, null, null);
	$multi->load();
	$existing = array();
	foreach ($multi as $row) {
		$existing[$row->get('stg_name')] = $row;
	}
	foreach ($map as $name => $value) {
		if (isset($existing[$name])) {
			$existing[$name]->set('stg_value', $value);
			$existing[$name]->set('stg_update_time', 'NOW()');
			$existing[$name]->set('stg_usr_user_id', $user_id);
			$existing[$name]->prepare();
			$existing[$name]->save();
		}
	}
	// Bust cached default driver so subsequent calls pick up new creds.
	CloudStorageDriverFactory::reset();
}

function _cloud_storage_set_enabled(bool $enabled, $session) {
	$user_id = $session->get_user_id();
	$multi = new MultiSetting(array(), null, null, null, null);
	$multi->load();
	foreach ($multi as $row) {
		if ($row->get('stg_name') === 'cloud_storage_enabled') {
			$row->set('stg_value', $enabled ? '1' : '0');
			$row->set('stg_update_time', 'NOW()');
			$row->set('stg_usr_user_id', $user_id);
			$row->prepare();
			$row->save();
			break;
		}
	}
	CloudStorageDriverFactory::reset();
}

function _cloud_storage_activate_sync_task() {
	_cloud_storage_activate_task('CloudStorageSync');
}

function _cloud_storage_activate_task(string $task_class) {
	$existing = new MultiScheduledTask(array('task_class' => $task_class, 'deleted' => false));
	$existing->load();
	if ($existing->count_all() > 0) {
		foreach ($existing as $task) {
			$task->set('sct_is_active', true);
			$task->set('sct_frequency', 'every_run');
			$task->save();
		}
		return;
	}
	// Discover JSON for the name.
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

function _cloud_storage_deactivate_task(string $task_class) {
	$existing = new MultiScheduledTask(array('task_class' => $task_class, 'deleted' => false));
	$existing->load();
	foreach ($existing as $task) {
		$task->set('sct_is_active', false);
		$task->save();
	}
}

function _cloud_storage_health($settings) {
	$h = array();

	// Cron heartbeat.
	$last_cron = $settings->get_setting('scheduled_tasks_last_cron_run');
	$cron_ok = false;
	if ($last_cron) {
		try {
			$last = new DateTime($last_cron, new DateTimeZone('UTC'));
			$now = new DateTime('now', new DateTimeZone('UTC'));
			$cron_ok = ($now->getTimestamp() - $last->getTimestamp()) < 1800;
		} catch (Exception $e) { /* leave $cron_ok=false */ }
	}
	$h['cron'] = array('ok' => $cron_ok, 'last' => $last_cron);

	// Driver ping (only if enabled).
	$h['driver'] = null;
	if ($settings->get_setting('cloud_storage_enabled')) {
		try {
			$d = CloudStorageDriverFactory::default();
			if ($d) {
				$start = microtime(true);
				$ping = $d->ping();
				$elapsed_ms = (int)((microtime(true) - $start) * 1000);
				$h['driver'] = array(
					'ok'         => $ping['ok'],
					'message'    => $ping['message'],
					'elapsed_ms' => $elapsed_ms,
				);
			} else {
				$h['driver'] = array('ok' => false, 'message' => 'Driver not configured.', 'elapsed_ms' => 0);
			}
		} catch (Exception $e) {
			$h['driver'] = array('ok' => false, 'message' => $e->getMessage(), 'elapsed_ms' => 0);
		}
	}

	// Sync task status — read from sct_scheduled_tasks.
	$h['sync_task'] = null;
	try {
		$multi = new MultiScheduledTask(array('task_class' => 'CloudStorageSync', 'deleted' => false));
		$multi->load();
		foreach ($multi as $task) {
			$h['sync_task'] = array(
				'is_active'    => (bool)$task->get('sct_is_active'),
				'last_run'     => $task->get('sct_last_run_time'),
				'last_status'  => $task->get('sct_last_run_status'),
				'last_message' => $task->get('sct_last_run_message'),
			);
		}
	} catch (Exception $e) { /* table might not exist yet */ }

	// File counts.
	$dbconnector = DbConnector::get_instance();
	$dblink = $dbconnector->get_db_link();
	$h['counts'] = array('pending' => 0, 'cloud' => 0, 'stuck' => 0, 'migrated_this_week' => 0);
	try {
		$row = $dblink->query("
			SELECT
			  COUNT(*) FILTER (WHERE (fil_storage_driver IS NULL OR fil_storage_driver = 'local')
			                   AND fil_delete_time IS NULL
			                   AND (fil_min_permission IS NULL OR fil_min_permission = 0)
			                   AND (fil_grp_group_id IS NULL OR fil_grp_group_id = 0)
			                   AND (fil_evt_event_id IS NULL OR fil_evt_event_id = 0)
			                   AND (fil_tier_min_level IS NULL OR fil_tier_min_level = 0)
			                   AND COALESCE(fil_sync_failed_count, 0) < 5) AS pending,
			  COUNT(*) FILTER (WHERE fil_storage_driver = 'cloud') AS cloud,
			  COUNT(*) FILTER (WHERE COALESCE(fil_sync_failed_count, 0) >= 5) AS stuck,
			  COUNT(*) FILTER (WHERE fil_storage_driver = 'cloud'
			                   AND fil_sync_last_attempt > now() - interval '7 days') AS migrated_this_week
			FROM fil_files")->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			$h['counts'] = array_map('intval', $row);
		}
	} catch (Exception $e) { /* schema might not be in place yet */ }

	// Stuck rows list.
	$h['stuck_rows'] = array();
	if ($h['counts']['stuck'] > 0) {
		try {
			$q = $dblink->prepare("
				SELECT fil_file_id, fil_name, fil_sync_last_attempt, fil_sync_failed_count
				FROM fil_files
				WHERE COALESCE(fil_sync_failed_count, 0) >= 5
				  AND fil_delete_time IS NULL
				ORDER BY fil_sync_last_attempt DESC
				LIMIT 25");
			$q->execute();
			$h['stuck_rows'] = $q->fetchAll(PDO::FETCH_ASSOC);
		} catch (Exception $e) { /* swallow */ }
	}

	// Reverse sync task status (only shown when active).
	$h['reverse_task'] = null;
	try {
		$multi = new MultiScheduledTask(array('task_class' => 'CloudStorageReverseSync', 'deleted' => false));
		$multi->load();
		foreach ($multi as $task) {
			if ($task->get('sct_is_active')) {
				$h['reverse_task'] = array(
					'last_run'     => $task->get('sct_last_run_time'),
					'last_status'  => $task->get('sct_last_run_status'),
					'last_message' => $task->get('sct_last_run_message'),
				);
			}
		}
	} catch (Exception $e) { /* swallow */ }

	return $h;
}
