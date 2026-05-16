<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_agent_files_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/agent_files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	// Action: write-to-disk on an existing row
	if (isset($post_vars['action']) && $post_vars['action'] === 'write_to_disk' && !empty($post_vars['agf_agent_file_id'])) {
		$force = !empty($post_vars['force']);
		try {
			$agent_file = new AgentFile((int)$post_vars['agf_agent_file_id'], TRUE);
			$agent_file->write_to_disk($force);
			return LogicResult::redirect('/admin/admin_agent_files?written=' . $agent_file->key);
		} catch (AgentFileDriftException $e) {
			// On-disk edits would be lost — bounce to a confirmation prompt.
			return LogicResult::redirect('/admin/admin_agent_files?confirm_overwrite=' . (int)$post_vars['agf_agent_file_id']);
		} catch (\Throwable $e) {
			return LogicResult::redirect('/admin/admin_agent_files?error=' . urlencode($e->getMessage()));
		}
	}

	$numperpage = 50;
	$offset     = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0);
	$sort       = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'agent_file_id');
	$sdirection = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'ASC');

	$search_criteria = array();
	if ($session->get_permission() < 10) {
		$search_criteria['deleted'] = false;
	}

	$agent_files = new MultiAgentFile($search_criteria, array($sort => $sdirection), $numperpage, $offset);
	$numrecords  = $agent_files->count_all();
	$agent_files->load();

	// A drifted write redirects here with ?confirm_overwrite=<id>. Load that
	// row only if it still actually has on-disk drift (the user may have
	// already resolved it), so a stale link just shows the normal list.
	$confirm_row = null;
	if (!empty($get_vars['confirm_overwrite'])) {
		try {
			$candidate = new AgentFile((int)$get_vars['confirm_overwrite'], TRUE);
			if (!empty($candidate->get_drifted_targets())) {
				$confirm_row = $candidate;
			}
		} catch (\Throwable $e) {
			// Row gone or unreadable — fall through to the normal list.
		}
	}

	$page_vars = array(
		'session'     => $session,
		'agent_files' => $agent_files,
		'numrecords'  => $numrecords,
		'numperpage'  => $numperpage,
		'written'     => isset($get_vars['written']) ? $get_vars['written'] : null,
		'error'       => isset($get_vars['error']) ? $get_vars['error'] : null,
		'confirm_row' => $confirm_row,
	);

	return LogicResult::render($page_vars);
}
?>
