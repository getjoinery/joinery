<?php
/**
 * reaction_status — whether the signed-in user has reacted to an entity, and
 * the entity's total reaction count. Read-only, logged-in only.
 *
 * @version 1.0.0
 */

function reaction_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('data/reactions_class.php'));

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$entity_type = isset($input['entity_type']) ? (string) $input['entity_type'] : '';
	$entity_id   = isset($input['entity_id']) ? (int) $input['entity_id'] : 0;

	if (!$entity_type || !$entity_id) {
		return LogicResult::error('Missing entity_type or entity_id');
	}
	if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $entity_type)) {
		return LogicResult::error('Invalid entity type');
	}

	try {
		$reacted = Reaction::has_reacted($user_id, $entity_type, $entity_id);
		$count   = Reaction::get_count($entity_type, $entity_id);
	} catch (Exception $e) {
		return LogicResult::error('Status check failed: ' . $e->getMessage());
	}

	return LogicResult::render(['reacted' => $reacted, 'count' => $count]);
}

function reaction_status_logic_descriptor(): array {
	return [
		'description' => 'Whether the signed-in user has reacted to an entity, plus its reaction count.',
		'mutates'     => false,
		'auth'        => [
			'capability' => 'read',
		],
		'input'       => [
			'entity_type' => ['type' => 'string', 'required' => true, 'max_length' => 50, 'label' => 'Entity type'],
			'entity_id'   => ['type' => 'int',    'required' => true, 'label' => 'Entity ID'],
		],
	];
}
?>
