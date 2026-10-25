<?php
/**
 * Read a managed node's backup listing: refresh_list files the look job that
 * lists the node's backups, list_status reads its result. Split from
 * backup_actions (spec declared_permissions WP6) so a level 7 reader of node
 * detail can see a node's backups (Q1: a look job changes nothing on the
 * node); every other backup action stays in backup_actions at 8, and its
 * destructive ones at 10.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/backup_actions_logic.php'));

function backup_list_logic(array $input): LogicResult {
	$action = isset($input['action']) ? (string)$input['action'] : '';
	if (!in_array($action, array('refresh_list', 'list_status'), true)) {
		return LogicResult::render(['success' => false, 'message' => 'Unknown action']);
	}
	return backup_actions_logic($input);
}

function backup_list_logic_descriptor(): array {
	return [
		'description' => 'Read a managed node\'s backup listing: refresh_list asks the node for it (a look job), list_status reads the job\'s answer.',
		'mutates'     => true,   // refresh_list files a job record
		'requires_session' => true,
		'auth'        => ['min_user_permission' => 7],
		'input'       => [
			'action'  => ['type' => 'string', 'required' => true, 'enum' => ['refresh_list', 'list_status'], 'label' => 'Action'],
			'node_id' => ['type' => 'int',    'required' => true, 'label' => 'Node ID'],
			'job_id'  => ['type' => 'int',    'required' => false, 'label' => 'Job ID (list_status)'],
		],
	];
}
?>
