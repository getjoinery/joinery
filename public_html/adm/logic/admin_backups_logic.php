<?php
/**
 * admin_backups_logic — the Backups page.
 *
 * Everything a site needs to back itself up lives here: where backups go, what
 * opens them, how many are kept, and what has actually happened. No fleet, no
 * agent — server_manager is a layer on top of this, not a prerequisite for it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/TargetTester.php'));
require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

function admin_backups_logic($input = array()) {
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$action = (string)($input['action'] ?? '');

	if ($action !== '') {
		$outcome = _admin_backups_handle($action, $input, $session);
		if ($outcome !== null) {
			return LogicResult::redirect($outcome);
		}
	}

	$targets = new MultiBackupTarget(array('deleted' => false), array('bkt_name' => 'ASC'));
	$targets->load();

	$history = new MultiBackupHistory(array('deleted' => false),
		array('bkh_start_time' => 'DESC'), 25, 0);
	$history->load();

	// A plan that cannot be made is the most useful thing this page can say, so
	// the reason is surfaced rather than swallowed.
	$plan = null;
	$plan_problem = '';
	try {
		$plan = BackupRunner::plan();
	} catch (Exception $e) {
		$plan_problem = $e->getMessage();
	}

	return LogicResult::render(array(
		'session'       => $session,
		'settings'      => Globalvars::get_instance(),
		'targets'       => $targets,
		'history'       => $history,
		'recovery'      => BackupRecoveryKey::setup_state(),
		'plan'          => $plan,
		'plan_problem'  => $plan_problem,
		'default_slug'  => basename(PathHelper::getSiteRoot()),
		'task'          => _admin_backups_task_state(),
	));
}

/** Handle a POST. Returns a redirect URL, or null to fall through to render. */
function _admin_backups_handle($action, array $input, $session) {
	$url = '/admin/admin_backups';
	$page_regex = '/admin\/admin_backups/';

	// DisplayMessage lives in the pre-loaded SessionControl.php — never required.
	$say = function ($message, $ok) use ($session, $page_regex) {
		$session->save_message(new DisplayMessage(
			$message,
			$ok ? 'Success' : 'Error',
			$page_regex,
			$ok ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	};

	try {
		switch ($action) {

			case 'save_recovery_key':
				BackupRecoveryKey::set_public_key((string)($input['backup_recovery_public_key'] ?? ''));
				$say('Recovery key saved. Now prove you hold the matching private key.', true);
				return $url . '#recovery-key';

			case 'clear_recovery_key':
				BackupRecoveryKey::clear_public_key();
				$say('Recovery key cleared. Paste a different one to start again.', true);
				return $url . '#recovery-key';

			case 'verify_recovery_key':
				BackupRecoveryKey::record_possession_proof((string)($input['recovery_proof'] ?? ''));
				$say('Verified — the key you hold opens what this site seals.', true);
				return $url . '#recovery-key';

			case 'save_target': {
				$id = (int)($input['bkt_id'] ?? 0);
				$target = $id ? new BackupTarget($id, TRUE) : new BackupTarget(NULL);
				$target->set('bkt_name', trim((string)($input['bkt_name'] ?? '')));
				$target->set('bkt_provider', (string)($input['bkt_provider'] ?? ''));
				$target->set('bkt_bucket', trim((string)($input['bkt_bucket'] ?? '')));
				$target->set('bkt_path_prefix', trim((string)($input['bkt_path_prefix'] ?? '')) ?: 'joinery-backups');

				// A blank secret on an edit means "leave it alone" — the stored
				// value is never rendered back into the form, so an operator
				// changing a bucket name must not have to re-enter the key.
				$access = trim((string)($input['access_key'] ?? ''));
				$secret = trim((string)($input['secret_key'] ?? ''));
				if ($access !== '' || $secret !== '' || !$id) {
					$existing = $id ? ($target->get_credentials() ?: array()) : array();
					$target->set('bkt_credentials', array(
						'access_key' => $access !== '' ? $access : (string)($existing['access_key'] ?? ''),
						'secret_key' => $secret !== '' ? $secret : (string)($existing['secret_key'] ?? ''),
						'region'     => trim((string)($input['region'] ?? ($existing['region'] ?? ''))),
						'endpoint'   => trim((string)($input['endpoint'] ?? ($existing['endpoint'] ?? ''))),
					));
				}
				$target->set('bkt_enabled', !empty($input['bkt_enabled']));
				$target->save();
				$say('Target saved.', true);
				return $url;
			}

			case 'test_target': {
				$target = new BackupTarget((int)($input['bkt_id'] ?? 0), TRUE);
				$test = TargetTester::test($target);
				$say(($test['success'] ? 'Connection OK: ' : 'Connection failed: ') . $test['message'], $test['success']);
				return $url;
			}

			case 'delete_target': {
				$target = new BackupTarget((int)($input['bkt_id'] ?? 0), TRUE);
				// Deleting the target a schedule points at would leave the task
				// skipping every night with a message nobody reads, so say so now.
				if ((int)Globalvars::get_instance()->get_setting('backup_target_id') === (int)$target->key) {
					$say('That target is the one scheduled backups use. Point the schedule somewhere else first.', false);
					return $url;
				}
				$target->soft_delete();
				$say('Target deleted. Backups already in its bucket are untouched.', true);
				return $url;
			}

			case 'save_schedule': {
				// Declared settings are written through SettingsWriter, not by
				// this page: it is what applies the declared validation, honours
				// the vault gate, and refuses names outside the scope it was
				// given. A page writing its own SQL bypasses all three.
				require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));
				require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

				$slug = trim((string)($input['backup_path_slug'] ?? ''));
				if ($slug !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
					$say('The backup folder name may only contain letters, numbers, hyphens and underscores.', false);
					return $url;
				}

				$names = SettingsFieldRenderer::namesFor('backups', 'core');
				$names = array_values(array_diff($names,
					array('backup_recovery_public_key', 'backup_recovery_public_key_proven_fpr')));

				// A retention count of zero would mean "keep nothing", which is
				// not a thing anyone means. Floor it before it is stored.
				if (isset($input['backup_retention_count'])) {
					$input['backup_retention_count'] = (string)max(1, (int)$input['backup_retention_count']);
				}
				if (isset($input['backup_local_retention_days'])) {
					$input['backup_local_retention_days'] = (string)max(0, (int)$input['backup_local_retention_days']);
				}

				$write = SettingsWriter::write($input, array(
					'page'   => 'admin_backups',
					'source' => 'core',
					'names'  => $names,
				));
				if (!empty($write['errors'])) {
					$first = reset($write['errors']);
					$say(is_array($first) ? implode(' ', $first) : (string)$first, false);
					return $url;
				}
				$say('Backup settings saved.', true);
				return $url;
			}

			case 'delete_history': {
				$row = new BackupHistory((int)($input['bkh_id'] ?? 0), TRUE);
				$row->set('bkh_delete_time', gmdate('Y-m-d H:i:s'));
				$row->save();
				$say('Removed from the list. The stored backup itself was not deleted.', true);
				return $url;
			}
		}
	} catch (Exception $e) {
		$say($e->getMessage(), false);
		return $url;
	}

	return null;
}

/**
 * Whether the scheduled task is switched on, and when it last ran. A page that
 * shows a schedule while the task is inactive is describing something that will
 * never happen.
 */
function _admin_backups_task_state() {
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			'SELECT sct_is_active, sct_last_run_time, sct_last_run_status, sct_last_run_message,
			        sct_frequency, sct_schedule_time
			   FROM sct_scheduled_tasks
			  WHERE sct_task_class = ? AND sct_delete_time IS NULL LIMIT 1');
		$q->execute(array('BackupRun'));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ?: array();
	} catch (\Throwable $e) {
		return array();
	}
}
