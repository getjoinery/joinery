<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_agent_file_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/agent_files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$agent_file_id = isset($input['agf_agent_file_id']) ? $input['agf_agent_file_id'] : NULL;

	if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
		$agent_file = new AgentFile((int)$input['edit_primary_key_value'], TRUE);
	} elseif ($agent_file_id) {
		$agent_file = new AgentFile((int)$agent_file_id, TRUE);
	} else {
		$agent_file = new AgentFile(NULL);
	}

	if ($input) {
		if (isset($input['btn_delete']) && $agent_file->key) {
			$agent_file->soft_delete();
			return LogicResult::redirect('/admin/admin_agent_files');
		}

		$agent_file->set('agf_name', trim($input['agf_name'] ?? ''));
		$agent_file->set('agf_content', $input['agf_content'] ?? '');

		$raw_targets = $input['agf_target_filenames'] ?? '';
		$lines = preg_split("/\r?\n/", $raw_targets);
		$target_list = array();
		foreach ($lines as $line) {
			$trimmed = trim($line);
			if ($trimmed !== '') {
				$target_list[] = $trimmed;
			}
		}
		$agent_file->set('agf_target_filenames', $target_list);

		$agent_file->save();

		if (isset($input['btn_save_and_write'])) {
			try {
				$agent_file->write_to_disk();
				return LogicResult::redirect('/admin/admin_agent_files?written=' . $agent_file->key);
			} catch (AgentFileDriftException $e) {
				// On-disk edits would be lost — the list page prompts for confirmation.
				return LogicResult::redirect('/admin/admin_agent_files?confirm_overwrite=' . $agent_file->key);
			}
		}

		return LogicResult::redirect('/admin/admin_agent_file_edit?agf_agent_file_id=' . $agent_file->key);
	}

	$page_vars = array(
		'session'    => $session,
		'agent_file' => $agent_file,
		'error'      => isset($input['error']) ? $input['error'] : null,
	);

	return LogicResult::render($page_vars);
}
?>
