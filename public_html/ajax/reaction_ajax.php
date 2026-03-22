<?php
/**
 * Reaction system AJAX endpoint
 * Actions: toggle (POST), status (GET), count (GET)
 *
 * @version 1.0
 * @see /specs/implemented/reaction_system_spec.md
 */
header('Content-Type: application/json');

try {
	require_once(PathHelper::getIncludePath('data/reactions_class.php'));
} catch (Exception $e) {
	echo json_encode(array('success' => false, 'message' => 'Failed to load dependencies: ' . $e->getMessage()));
	exit;
}

$session = SessionControl::get_instance();
if (!$session->get_user_id()) {
	echo json_encode(array('success' => false, 'message' => 'Not logged in'));
	exit;
}

$user_id = $session->get_user_id();

// Support both GET and POST params
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$entity_type = isset($_POST['entity_type']) ? $_POST['entity_type'] : (isset($_GET['entity_type']) ? $_GET['entity_type'] : '');
$entity_id = (int)(isset($_POST['entity_id']) ? $_POST['entity_id'] : (isset($_GET['entity_id']) ? $_GET['entity_id'] : 0));

// Validate entity_type -- alphanumeric and underscores only
if ($entity_type && !preg_match('/^[a-z][a-z0-9_]{0,49}$/', $entity_type)) {
	echo json_encode(array('success' => false, 'message' => 'Invalid entity type'));
	exit;
}

switch ($action) {
	case 'toggle':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'message' => 'Toggle requires POST'));
			exit;
		}
		if (!$entity_type || !$entity_id) {
			echo json_encode(array('success' => false, 'message' => 'Missing entity_type or entity_id'));
			exit;
		}
		$reaction_type = isset($_POST['reaction_type']) ? $_POST['reaction_type'] : 'like';
		// Validate reaction_type
		if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $reaction_type)) {
			echo json_encode(array('success' => false, 'message' => 'Invalid reaction type'));
			exit;
		}
		try {
			$result = Reaction::toggle($user_id, $entity_type, $entity_id, $reaction_type);
			$count = Reaction::get_count($entity_type, $entity_id);
			echo json_encode(array('success' => true, 'action' => $result['action'], 'count' => $count));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Toggle failed: ' . $e->getMessage()));
		}
		break;

	case 'status':
		if (!$entity_type || !$entity_id) {
			echo json_encode(array('success' => false, 'message' => 'Missing entity_type or entity_id'));
			exit;
		}
		try {
			$reacted = Reaction::has_reacted($user_id, $entity_type, $entity_id);
			$count = Reaction::get_count($entity_type, $entity_id);
			echo json_encode(array('success' => true, 'reacted' => $reacted, 'count' => $count));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Status check failed: ' . $e->getMessage()));
		}
		break;

	case 'count':
		if (!$entity_type || !$entity_id) {
			echo json_encode(array('success' => false, 'message' => 'Missing entity_type or entity_id'));
			exit;
		}
		try {
			$count = Reaction::get_count($entity_type, $entity_id);
			echo json_encode(array('success' => true, 'count' => $count));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Count failed: ' . $e->getMessage()));
		}
		break;

	default:
		echo json_encode(array('success' => false, 'message' => 'Invalid action'));
		break;
}
