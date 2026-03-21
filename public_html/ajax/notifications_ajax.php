<?php
/**
 * Notifications AJAX endpoint
 * Actions: mark_read, mark_all_read, get_count
 *
 * @version 1.0
 */
header('Content-Type: application/json');

try {
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));
} catch (Exception $e) {
	echo json_encode(array('success' => false, 'message' => 'Failed to load dependencies: ' . $e->getMessage()));
	exit;
}

$session = SessionControl::get_instance();
if (!$session->get_user_id()) {
	echo json_encode(array('success' => false, 'message' => 'Not logged in'));
	exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
	case 'mark_read':
		$ntf_id = (int)(isset($_POST['notification_id']) ? $_POST['notification_id'] : 0);
		if (!$ntf_id) {
			echo json_encode(array('success' => false, 'message' => 'No notification ID'));
			exit;
		}
		try {
			$ntf = new Notification($ntf_id, TRUE);
			if ($ntf->get('ntf_usr_user_id') != $session->get_user_id()) {
				echo json_encode(array('success' => false, 'message' => 'Permission denied'));
				exit;
			}
			$ntf->set('ntf_is_read', true);
			$ntf->set('ntf_read_time', gmdate('Y-m-d H:i:s'));
			$ntf->save();
			$_SESSION['notification_unread_count'] = null;
			echo json_encode(array('success' => true));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Notification not found'));
		}
		break;

	case 'mark_all_read':
		try {
			$dbconnector = DbConnector::get_instance();
			$dblink = $dbconnector->get_db_link();
			$sql = "UPDATE ntf_notifications SET ntf_is_read = true, ntf_read_time = NOW()
					WHERE ntf_usr_user_id = ? AND ntf_is_read = false AND ntf_delete_time IS NULL";
			$q = $dblink->prepare($sql);
			$q->execute([$session->get_user_id()]);
			$_SESSION['notification_unread_count'] = null;
			echo json_encode(array('success' => true, 'updated' => $q->rowCount()));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Failed to mark all read: ' . $e->getMessage()));
		}
		break;

	case 'get_count':
		try {
			$unread = Notification::get_unread_count($session->get_user_id());
			$_SESSION['notification_unread_count'] = $unread;
			echo json_encode(array('success' => true, 'unread_count' => $unread));
		} catch (Exception $e) {
			echo json_encode(array('success' => false, 'message' => 'Failed to get count: ' . $e->getMessage()));
		}
		break;

	default:
		echo json_encode(array('success' => false, 'message' => 'Invalid action'));
		break;
}
