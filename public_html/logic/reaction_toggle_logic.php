<?php
/**
 * reaction_toggle — toggle the signed-in user's reaction on an entity.
 *
 * Mutating, logged-in only. Returns {action, count} (action: 'reacted' |
 * 'unreacted'). entity_type / reaction_type are format-validated.
 *
 * @version 1.0.0
 */

function reaction_toggle_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('data/reactions_class.php'));

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$entity_type   = isset($input['entity_type']) ? (string) $input['entity_type'] : '';
	$entity_id     = isset($input['entity_id']) ? (int) $input['entity_id'] : 0;
	$reaction_type = isset($input['reaction_type']) ? (string) $input['reaction_type'] : 'like';

	if (!$entity_type || !$entity_id) {
		return LogicResult::error('Missing entity_type or entity_id');
	}
	if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $entity_type)) {
		return LogicResult::error('Invalid entity type');
	}
	if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $reaction_type)) {
		return LogicResult::error('Invalid reaction type');
	}

	try {
		$result = Reaction::toggle($user_id, $entity_type, $entity_id, $reaction_type);
		$count  = Reaction::get_count($entity_type, $entity_id);
	} catch (Exception $e) {
		return LogicResult::error('Toggle failed: ' . $e->getMessage());
	}

	return LogicResult::render(['action' => $result['action'], 'count' => $count]);
}

function reaction_toggle_logic_descriptor(): array {
	return [
		'description' => 'Toggle the signed-in user\'s reaction on an entity.',
		'mutates'     => true,
		'input'       => [
			'entity_type'   => ['type' => 'string', 'required' => true, 'max_length' => 50, 'label' => 'Entity type'],
			'entity_id'     => ['type' => 'int',    'required' => true, 'label' => 'Entity ID'],
			'reaction_type' => ['type' => 'string', 'required' => false, 'max_length' => 20, 'label' => 'Reaction type'],
		],
	];
}
?>
