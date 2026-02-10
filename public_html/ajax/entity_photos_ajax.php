<?php
/**
 * Entity Photos AJAX Endpoint
 *
 * Manages entity photo associations (upload, delete, reorder, update_caption).
 * Does not load or know about entity models — only manages eph_entity_photos rows.
 *
 * @version 1.0.0
 * @see /specs/pictures_refactor_spec.md
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

$session = SessionControl::get_instance();

// Require login
if (!$session->is_logged_in()) {
	http_response_code(403);
	echo json_encode(['error' => 'Permission denied']);
	exit;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$entity_type = isset($_POST['entity_type']) ? trim($_POST['entity_type']) : '';
$entity_id = isset($_POST['entity_id']) ? intval($_POST['entity_id']) : 0;

if (!$entity_type || !$entity_id) {
	http_response_code(400);
	echo json_encode(['error' => 'entity_type and entity_id are required']);
	exit;
}

/**
 * Check if current user has permission to manage this photo
 * Admin (perm >= 5), file owner, or self-service entity owner
 */
function check_photo_permission($session, $file_id = null, $entity_type = null, $entity_id = null) {
	// Admin always allowed
	if ($session->get_permission() >= 5) {
		return true;
	}
	// File owner check (for delete, update_caption)
	if ($file_id) {
		$file = new File($file_id, TRUE);
		if ($file->get('fil_usr_user_id') == $session->get_user_id()) {
			return true;
		}
	}
	// Self-service: user can manage photos on their own entity
	if ($entity_type === 'user' && $entity_id == $session->get_user_id()) {
		return true;
	}
	return false;
}

switch ($action) {

	case 'upload':
		// Handle file upload and create EntityPhoto record
		if (empty($_FILES['file'])) {
			http_response_code(400);
			echo json_encode(['error' => 'No file uploaded']);
			exit;
		}

		// Check permission - admin, file owner, or self-service entity owner
		if (!check_photo_permission($session, null, $entity_type, $entity_id)) {
			http_response_code(403);
			echo json_encode(['error' => 'Permission denied']);
			exit;
		}

		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');

		$uploaded_file = $_FILES['file'];
		$file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($uploaded_file['name']));
		$target_path = $upload_dir . '/' . $file_name;

		if (!move_uploaded_file($uploaded_file['tmp_name'], $target_path)) {
			http_response_code(500);
			echo json_encode(['error' => 'Failed to save uploaded file']);
			exit;
		}

		// Create File record
		$file = new File(NULL);
		$file->set('fil_name', $file_name);
		$file->set('fil_title', pathinfo($uploaded_file['name'], PATHINFO_FILENAME));
		$file->set('fil_type', $uploaded_file['type']);
		$file->set('fil_usr_user_id', $session->get_user_id());
		$file->save();

		// Generate resized versions
		$file->resize();

		// Get next sort order
		$existing = new MultiEntityPhoto(
			['entity_type' => $entity_type, 'entity_id' => $entity_id, 'deleted' => false],
			['eph_sort_order' => 'DESC'],
			1
		);
		$existing->load();
		$next_order = 0;
		if ($existing->count() > 0) {
			$next_order = (int) $existing->get(0)->get('eph_sort_order') + 1;
		}

		// Auto-set as primary if this is the first photo for the entity
		$is_first_photo = ($existing->count() == 0);

		// Create EntityPhoto record
		try {
			$photo = new EntityPhoto(NULL);
			$photo->set('eph_entity_type', $entity_type);
			$photo->set('eph_entity_id', $entity_id);
			$photo->set('eph_fil_file_id', $file->key);
			$photo->set('eph_sort_order', $next_order);
			$photo->save();

			// If first photo, set as primary via entity model (syncs legacy FK column)
			if ($is_first_photo) {
				$entity_class_map = [
					'event' => ['class' => 'Event', 'file' => 'data/events_class.php'],
					'user' => ['class' => 'User', 'file' => 'data/users_class.php'],
					'location' => ['class' => 'Location', 'file' => 'data/locations_class.php'],
					'mailing_list' => ['class' => 'MailingList', 'file' => 'data/mailing_lists_class.php'],
				];
				if (isset($entity_class_map[$entity_type])) {
					$map = $entity_class_map[$entity_type];
					require_once(PathHelper::getIncludePath($map['file']));
					$entity = new $map['class']($entity_id, TRUE);
					if (method_exists($entity, 'set_primary_photo')) {
						$entity->set_primary_photo($photo->key);
					}
				}
			}

			echo json_encode([
				'success' => true,
				'photo' => [
					'photo_id' => $photo->key,
					'file_id' => $file->key,
					'url' => $file->get_url('original'),
					'avatar' => $file->get_url('avatar'),
					'sort_order' => $next_order,
					'caption' => null
				]
			]);
		} catch (DisplayableUserException $e) {
			// Photo limit exceeded or duplicate
			http_response_code(400);
			echo json_encode(['error' => $e->getMessage()]);
		}
		break;

	case 'delete':
		$photo_id = isset($_POST['photo_id']) ? intval($_POST['photo_id']) : 0;
		if (!$photo_id) {
			http_response_code(400);
			echo json_encode(['error' => 'photo_id is required']);
			exit;
		}

		$photo = new EntityPhoto($photo_id, TRUE);
		if (!$photo->get('eph_entity_photo_id')) {
			http_response_code(404);
			echo json_encode(['error' => 'Photo not found']);
			exit;
		}

		// Verify it belongs to the specified entity
		if ($photo->get('eph_entity_type') !== $entity_type || $photo->get('eph_entity_id') != $entity_id) {
			http_response_code(400);
			echo json_encode(['error' => 'Photo does not belong to this entity']);
			exit;
		}

		if (!check_photo_permission($session, $photo->get('eph_fil_file_id'), $entity_type, $entity_id)) {
			http_response_code(403);
			echo json_encode(['error' => 'Permission denied']);
			exit;
		}

		$photo->soft_delete();
		echo json_encode(['success' => true]);
		break;

	case 'reorder':
		$photo_ids = isset($_POST['photo_ids']) ? $_POST['photo_ids'] : [];
		if (!is_array($photo_ids) || empty($photo_ids)) {
			http_response_code(400);
			echo json_encode(['error' => 'photo_ids array is required']);
			exit;
		}

		if (!check_photo_permission($session, null, $entity_type, $entity_id)) {
			http_response_code(403);
			echo json_encode(['error' => 'Permission denied']);
			exit;
		}

		foreach ($photo_ids as $order => $photo_id) {
			$photo = new EntityPhoto(intval($photo_id), TRUE);
			if ($photo->get('eph_entity_type') === $entity_type && $photo->get('eph_entity_id') == $entity_id) {
				$photo->set('eph_sort_order', (int) $order);
				$photo->save();
			}
		}

		echo json_encode(['success' => true]);
		break;

	case 'update_caption':
		$photo_id = isset($_POST['photo_id']) ? intval($_POST['photo_id']) : 0;
		$caption = isset($_POST['caption']) ? trim($_POST['caption']) : '';

		if (!$photo_id) {
			http_response_code(400);
			echo json_encode(['error' => 'photo_id is required']);
			exit;
		}

		$photo = new EntityPhoto($photo_id, TRUE);
		if (!$photo->get('eph_entity_photo_id')) {
			http_response_code(404);
			echo json_encode(['error' => 'Photo not found']);
			exit;
		}

		if ($photo->get('eph_entity_type') !== $entity_type || $photo->get('eph_entity_id') != $entity_id) {
			http_response_code(400);
			echo json_encode(['error' => 'Photo does not belong to this entity']);
			exit;
		}

		if (!check_photo_permission($session, $photo->get('eph_fil_file_id'), $entity_type, $entity_id)) {
			http_response_code(403);
			echo json_encode(['error' => 'Permission denied']);
			exit;
		}

		$photo->set('eph_caption', $caption);
		$photo->save();

		echo json_encode(['success' => true, 'caption' => $caption]);
		break;

	default:
		http_response_code(400);
		echo json_encode(['error' => 'Invalid action. Supported: upload, delete, reorder, update_caption']);
		break;
}
