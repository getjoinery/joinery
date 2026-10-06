<?php
/**
 * server_manager/moved_site_check — where the old machine of a switch-over's
 * domain goes, beside its site on the node page (specs/site_copy.md WP14).
 *
 * Folds finished checks onto the row, files new ones when the stored answers
 * are stale or the operator asks (force) — the host's (has the domain left the
 * old container?) and the new server's (does the domain reach it?) — and
 * returns the label the page shows. The page calls it on open and polls it while a check runs.
 * Superadmin only (floor 10).
 *
 * @version 1.1.0 - also asks whether the domain reaches the new server (MovedSiteCheck 1.1)
 * @version 1.0.0
 */

function moved_site_check_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();

	$node_id = isset($input['node_id']) ? (int)$input['node_id'] : 0;
	try {
		$node = $node_id ? new ManagedNode($node_id, TRUE) : null;
	} catch (Exception $e) {
		$node = null;
	}
	if (!$node || !$node->key) {
		return LogicResult::render(['ok' => false, 'message' => 'Node not found']);
	}
	if (!MovedSiteCheck::applies($node)) {
		return LogicResult::render(['ok' => false,
			'message' => 'This is not the old container of a switch-over, so there is no moved domain to check.']);
	}

	$checking = MovedSiteCheck::settle($node);
	$refusal = '';
	if (!$checking && (!empty($input['force']) || MovedSiteCheck::is_stale($node))) {
		try {
			MovedSiteCheck::start($node, $session->get_user_id());
			$checking = true;
		} catch (Exception $e) {
			$refusal = $e->getMessage();
		}
	}

	return LogicResult::render([
		'ok'       => true,
		'checking' => $checking,
		'state'    => (string)$node->get('mgn_moved_check_state'),
		'html'     => MovedSiteCheck::label_html($node, $checking, $session->get_timezone(), $refusal),
	]);
}

function moved_site_check_logic_descriptor(): array {
	return [
		'description' => 'Show, and when stale ask again, whether the old machine of a switch-over\'s domain has left it and reaches the new server.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => ['min_user_permission' => 10],
		'input'       => [
			'node_id' => ['type' => 'int', 'required' => true, 'label' => 'Node ID'],
			'force'   => ['type' => 'bool', 'required' => false, 'label' => 'Check now even if the last answer is recent'],
		],
	];
}
