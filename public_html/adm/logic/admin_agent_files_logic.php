<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_agent_files_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/agent_files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	// Action: write-to-disk on an existing row
	if (isset($input['action']) && $input['action'] === 'write_to_disk' && !empty($input['agf_agent_file_id'])) {
		// CLAUDE.md and its siblings live in the code tree, which the web user
		// cannot write (specs/read_only_tree.md). The write is queued for root;
		// the drift guard still runs, inside write_to_disk(), so a file edited
		// on disk is refused there exactly as it was refused here.
		$force = !empty($input['force']);
		try {
			$agent_file = new AgentFile((int)$input['agf_agent_file_id'], TRUE);
			// Drift is asked about HERE, before anything is queued. Reading the
			// on-disk copies is a read, and this is where the operator is: a
			// request that came back saying "refused, the file changed" would
			// make them go and find the prompt instead of answering it.
			if (!$force && $agent_file->get_drifted_targets()) {
				return LogicResult::redirect('/admin/admin_agent_files?confirm_overwrite=' . (int)$agent_file->key);
			}
			$id = RootRequest::submit('write_agent_files',
				array('agent_file_id' => (int)$agent_file->key, 'force' => $force),
				(int)$session->get_user_id());
			return LogicResult::redirect('/admin/admin_agent_files?queued=' . urlencode($id));
		} catch (\Throwable $e) {
			return LogicResult::redirect('/admin/admin_agent_files?error=' . urlencode($e->getMessage()));
		}
	}

	// Action: switch to a pending upgrade candidate
	if (isset($input['action']) && $input['action'] === 'switch_to_candidate' && !empty($input['agf_agent_file_id'])) {
		try {
			$active = new AgentFile((int)$input['agf_agent_file_id'], TRUE);
			$active->switch_to_candidate();
			return LogicResult::redirect('/admin/admin_agent_files?switched=' . (int)$input['agf_agent_file_id']);
		} catch (\Throwable $e) {
			return LogicResult::redirect('/admin/admin_agent_files?error=' . urlencode($e->getMessage()));
		}
	}

	$numperpage = 50;
	$offset     = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$sort       = LibraryFunctions::fetch_variable_local($input, 'sort', 'agent_file_id');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'ASC');

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
	if (!empty($input['confirm_overwrite'])) {
		try {
			$candidate = new AgentFile((int)$input['confirm_overwrite'], TRUE);
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
		'written'     => isset($input['written']) ? $input['written'] : null,
		'queued'      => isset($input['queued']) ? (string)$input['queued'] : '',
		'root_actor_notice' => AdminPage::root_actor_notice(),
		'switched'    => isset($input['switched']) ? $input['switched'] : null,
		'error'       => isset($input['error']) ? $input['error'] : null,
		'confirm_row' => $confirm_row,
	);

	return LogicResult::render($page_vars);
}
?>
