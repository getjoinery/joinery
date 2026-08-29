<?php
/**
 * server_manager/backup_actions — backup browser actions.
 *
 * Input: action ∈ {refresh_list, delete_file, upload_file, list_status} + node_id
 * (+ target/local_path/cloud_path for delete_file, local_path for upload_file,
 * job_id for list_status). refresh_list, delete_file and upload_file create jobs;
 * list_status returns the cached backup list. Superadmin only (floor 10).
 *
 * @version 1.3.0 - upload_file refuses to send an encrypted archive offsite without the key
 *                  that opens it: the envelope travels with it, or the upload is blocked
 *                  with an override. The key is minted per artifact and stored nowhere else.
 * @version 1.2.0 - upload_file: push a local-only backup to the node's cloud target, for a
 *                  backup stranded on the node by a transient upload failure.
 * @version 1.1.0 - cloud deletes run management-node-side via TargetBackups (no agent,
 *                  real success/failure); local deletes still run as a node job.
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Does the node's own last scan show the envelope beside this archive?
 *
 * TRUE / FALSE once list_backups reports has_envelope; NULL when the node has
 * not re-scanned since that field existed. NULL routes through PAIR, which is
 * the safe default: attempting costs one 2KB transfer, and if the envelope is
 * not there the attempt says so — which is the fact the operator needs. Reading
 * a stale silence as "absent" would block uploads that are fine.
 */
function backup_actions_envelope_on_node($node, $filename) {
	if (!class_exists('MultiManagementJob')) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
	}
	$latest = new MultiManagementJob(
		['node_id' => $node->key, 'job_type' => 'list_backups', 'status' => 'completed', 'deleted' => false],
		['mjb_id' => 'DESC'], 1);
	foreach ($latest as $job) {
		$result = $job->get('mjb_result');
		if (is_string($result)) $result = json_decode($result, true);
		if (!is_array($result) || empty($result['files'])) return null;
		foreach ($result['files'] as $f) {
			if (($f['filename'] ?? '') !== $filename) continue;
			// Absent key means this scan predates the field, which is not an answer.
			return array_key_exists('has_envelope', $f) ? (bool)$f['has_envelope'] : null;
		}
	}
	return null;
}

function backup_actions_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

	$session = SessionControl::get_instance();

	$action  = isset($input['action']) ? (string) $input['action'] : '';
	$node_id = isset($input['node_id']) ? (int) $input['node_id'] : 0;

	if (!$node_id) {
		return LogicResult::render(['success' => false, 'message' => 'Missing node_id']);
	}

	try {
		$node = new ManagedNode($node_id, TRUE);
	} catch (Exception $e) {
		return LogicResult::render(['success' => false, 'message' => 'Node not found']);
	}

	if ($action === 'refresh_list') {
		// A paired node reads its own backup directory as a primitive; an
		// unpaired one still gets steps. createFromBuild stores either.
		$built = JobCommandBuilder::build_list_backups($node);
		$job = ManagementJob::createFromBuild($node->key, 'list_backups', $built, null, $session->get_user_id());
		return LogicResult::render(['success' => true, 'job_id' => $job->key]);
	}

	if ($action === 'delete_file') {
		$target     = isset($input['target']) ? (string) $input['target'] : 'local';
		$local_path = isset($input['local_path']) ? (string) $input['local_path'] : '';
		$cloud_path = isset($input['cloud_path']) ? (string) $input['cloud_path'] : '';

		if (!$local_path && !$cloud_path) {
			return LogicResult::render(['success' => false, 'message' => 'No file path provided']);
		}
		// Validate local_path is within /backups/ to prevent arbitrary file deletion.
		if ($local_path && !preg_match('#^/backups/[^/]+$#', $local_path)) {
			return LogicResult::render(['success' => false, 'message' => 'Invalid local path']);
		}

		$want_cloud = ($target === 'cloud' || $target === 'both') && $cloud_path !== '';
		$want_local = ($target === 'local' || $target === 'both') && $local_path !== '';

		// The cloud copy is deleted straight from the management node via S3Signer. It
		// needs no live node and no agent, and it reports a real success/failure —
		// unlike a node job, whose cloud-delete step can only unseal the target
		// credentials on agent >= 0.4.0 and otherwise no-ops while the job still
		// reports "completed" (the step is continue_on_error).
		if ($want_cloud) {
			require_once(PathHelper::getIncludePath('includes/TargetBackups.php'));
			require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
			$target_id = (int) $node->get('mgn_bkt_backup_target_id');
			if (!$target_id) {
				return LogicResult::render(['success' => false, 'message' => 'This node has no cloud backup target configured.']);
			}
			try {
				$tgt = new BackupTarget($target_id, TRUE);
				TargetBackups::delete_object($tgt, $cloud_path);
			} catch (Exception $e) {
				return LogicResult::render(['success' => false, 'message' => 'Cloud delete failed: ' . $e->getMessage()]);
			}
		}

		// The local copy lives on the node's disk, so its removal must run there —
		// a plain rm over SSH with no sealed credentials, which any agent can run.
		if ($want_local) {
			$params = [
				'target'     => 'local',
				'local_path' => $local_path,
				'cloud_path' => '',
				'filename'   => basename($local_path),
			];
			$built = JobCommandBuilder::build_delete_backup($node, $params);
			$job = ManagementJob::createFromBuild($node->key, 'delete_backup', $built, $params, $session->get_user_id());
			return LogicResult::render(['success' => true, 'job_id' => $job->key]);
		}

		// Cloud-only delete: already done, synchronously.
		return LogicResult::render(['success' => true]);
	}

	if ($action === 'upload_file') {
		$local_path = isset($input['local_path']) ? (string) $input['local_path'] : '';

		// The path is operator-supplied, so it must be a plain file directly under
		// /backups — no traversal, no subdirectories. Only the basename crosses to
		// a paired node, which resolves it inside its own compiled-in backup
		// directory; this guard also covers the unpaired path, where the value
		// still reaches a shell.
		if (!preg_match('#^/backups/[^/]+$#', $local_path)) {
			return LogicResult::render(['success' => false, 'message' => 'Invalid local path']);
		}

		// An encrypted archive is two files: the archive and the .keys.json
		// envelope holding its data key. The key is minted PER ARTIFACT and
		// stored nowhere else — bkh_backup_history records that a run was
		// encrypted and which recovery key it used, never the sealed key itself.
		// So an archive pushed offsite without its envelope is not a weaker
		// backup, it is a cloud copy nobody can ever open, and it reports as a
		// success. Decide that before the upload, not after.
		require_once(PathHelper::getIncludePath('includes/BackupPairing.php'));
		require_once(PathHelper::getIncludePath('data/backup_target_class.php'));

		$filename  = basename($local_path);
		$target_id = (int) $node->get('mgn_bkt_backup_target_id');
		$verdict   = ['verdict' => BackupPairing::PROCEED, 'message' => ''];

		if ($target_id) {
			try {
				$tgt = new BackupTarget($target_id, TRUE);
				// The node's own last scan says whether the envelope is on disk.
				// Read from the cached list, NOT from BackupListHelper: that one
				// also makes a live cloud listing capped at 500 objects, and a
				// truncated listing reports a stored envelope as absent — turning
				// a safety check into a false alarm that blocks good uploads.
				$verdict = BackupPairing::upload_verdict(
					$tgt, (string)$node->get('mgn_slug'), $filename,
					backup_actions_envelope_on_node($node, $filename));
			} catch (Exception $e) {
				// Unreadable target is its own fact and must not read as
				// "no envelope offsite" — verdict() carries checked=false through.
				$verdict = ['verdict' => BackupPairing::PAIR,
				            'message' => 'The cloud target could not be read, so the key file is being sent with the archive.'];
			}
		}

		// Blocked means the key exists nowhere we can find — on the node or in
		// the bucket. The override exists because the operator may know better
		// (a key held elsewhere), but it must be a deliberate second act.
		if ($verdict['verdict'] === BackupPairing::BLOCKED && empty($input['confirm_unrecoverable'])) {
			return LogicResult::render([
				'success' => false, 'blocked' => true, 'message' => $verdict['message'],
			]);
		}

		try {
			$params = ['filename' => $filename];
			if ($verdict['verdict'] === BackupPairing::PAIR) {
				$params['include_envelope'] = true;
			}
			$built = JobCommandBuilder::build_upload_backup($node, $params);
		} catch (Exception $e) {
			return LogicResult::render(['success' => false, 'message' => $e->getMessage()]);
		}

		$job = ManagementJob::createFromBuild($node->key, 'upload_backup', $built, $params, $session->get_user_id());
		// The message says, in what it costs them, whether the key went too.
		return LogicResult::render([
			'success' => true, 'job_id' => $job->key, 'message' => $verdict['message'],
		]);
	}

	if ($action === 'list_status') {
		$job_id = isset($input['job_id']) ? (int) $input['job_id'] : 0;

		if ($job_id) {
			try {
				$job = new ManagementJob($job_id, TRUE);
			} catch (Exception $e) {
				return LogicResult::render(['success' => false, 'message' => 'Job not found']);
			}
			$status = $job->get('mjb_status');
			if ($status === 'completed' && !$job->get('mjb_result')) {
				JobResultProcessor::process($job);
				$node->load(); // refresh cached data
			}
			if ($status !== 'pending' && $status !== 'running') {
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
				$bl = BackupListHelper::get_for_node($node);
				return LogicResult::render([
					'success'     => true,
					'status'      => 'complete',
					// The job's own terminal status, kept distinct from 'complete'
					// (which only means "no longer running"). A caller waiting on a
					// job that does work — an upload — has to be able to tell a
					// finished job from a successful one.
					'job_status'  => $status,
					'backup_list' => ['files' => $bl['files']],
					'last_scan'   => $bl['last_scan'],
					'cloud_error' => $bl['cloud_error'],
				]);
			}
			return LogicResult::render(['success' => true, 'status' => $status]);
		}

		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
		$bl = BackupListHelper::get_for_node($node);
		return LogicResult::render([
			'success'     => true,
			'status'      => 'cached',
			'backup_list' => ['files' => $bl['files']],
			'last_scan'   => $bl['last_scan'],
			'cloud_error' => $bl['cloud_error'],
		]);
	}

	return LogicResult::render(['success' => false, 'message' => 'Unknown action']);
}

function backup_actions_logic_descriptor(): array {
	return [
		'description' => 'Backup browser actions (refresh_list / delete_file / upload_file / list_status) for a managed node.',
		'mutates'     => true,
		'requires_session'        => true,
		'auth'        => ['min_user_permission' => 10],
		'input'       => [
			'action'     => ['type' => 'string', 'required' => false, 'enum' => ['refresh_list', 'delete_file', 'upload_file', 'list_status'], 'label' => 'Action'],
			'node_id'    => ['type' => 'int',    'required' => false, 'label' => 'Node ID'],
			'job_id'     => ['type' => 'int',    'required' => false, 'label' => 'Job ID (list_status)'],
			'target'     => ['type' => 'string', 'required' => false, 'label' => 'Delete target'],
			'local_path' => ['type' => 'string', 'required' => false, 'label' => 'Local path'],
			'cloud_path' => ['type' => 'string', 'required' => false, 'label' => 'Cloud path'],
		],
	];
}
?>
