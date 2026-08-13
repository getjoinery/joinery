<?php

/**
 * drive_changes — the sync change feed. Given a cursor (the last fch_file_change_id
 * the client saw, 0 for a cold start), returns the changes after it that the
 * caller may see — their own entities, plus entities shared to them — with the
 * next cursor. A cursor that points before the retained window returns
 * {reset: true} so the client re-lists from scratch.
 *
 * This plus the upload actions is the complete server contract for sync clients.
 */

if (!defined('DRIVE_CHANGES_BATCH')) {
	define('DRIVE_CHANGES_BATCH', 500);
}

function drive_changes_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_changes_class.php'));
	require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$cursor = (int)($input['cursor'] ?? 0);
	if ($cursor < 0) { $cursor = 0; }

	$dblink = DbConnector::get_instance()->get_db_link();

	// Liveness, for free, and BEFORE the reset branch below returns. A sync
	// client polls this constantly, so the poll itself is the check-in — no
	// separate heartbeat call, and no client that can forget to send one. It is
	// stamped here rather than at the end because a device whose cursor has
	// fallen outside the retained window is exactly the one whose owner needs to
	// see it is alive: it did reach the server, it just has to re-list. Recording
	// only the happy path would show that device as having stopped days ago.
	_drive_changes_stamp_device($cursor);

	// Reset when the cursor cannot be proven contiguous with the retained
	// window: it points before the earliest retained row, or the log is empty
	// (MIN is NULL after a purge) so nothing can vouch for the gap. Either way
	// the client may have missed changes and must re-list.
	if ($cursor > 0) {
		$min_id = $dblink->query("SELECT MIN(fch_file_change_id) FROM fch_file_changes")->fetchColumn();
		if ($min_id === null || $cursor + 1 < (int)$min_id) {
			$max_id = (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id), 0) FROM fch_file_changes")->fetchColumn();
			return LogicResult::render(array('ok' => true, 'reset' => true, 'changes' => array(), 'next_cursor' => $max_id));
		}
	}

	// Visibility: own changes plus changes on entities shared to me.
	$file_ids = FileAccessGrant::entity_ids_for_user($user_id, DriveHelper::ENTITY_FILE);
	$folder_ids = FileAccessGrant::entity_ids_for_user($user_id, DriveHelper::ENTITY_FOLDER);
	$file_in = DriveHelper::int_in_list($file_ids);
	$folder_in = DriveHelper::int_in_list($folder_ids);

	$sql = "SELECT fch_file_change_id, fch_entity_type, fch_entity_id, fch_change_kind,
	               fch_usr_user_id, fch_source_usr_user_id, fch_create_time
	          FROM fch_file_changes
	         WHERE fch_file_change_id > :cursor
	           AND (fch_usr_user_id = :me
	                OR (fch_entity_type = 'file'   AND fch_entity_id IN ($file_in))
	                OR (fch_entity_type = 'folder' AND fch_entity_id IN ($folder_in)))
	         ORDER BY fch_file_change_id ASC
	         LIMIT " . (int)DRIVE_CHANGES_BATCH;
	$q = $dblink->prepare($sql);
	$q->bindValue(':cursor', $cursor, PDO::PARAM_INT);
	$q->bindValue(':me', $user_id, PDO::PARAM_INT);
	$q->execute();

	$changes = array();
	$next = $cursor;
	foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$next = (int)$row['fch_file_change_id'];
		$changes[] = array(
			'id'          => (int)$row['fch_file_change_id'],
			'entity_type' => $row['fch_entity_type'],
			'entity_id'   => (int)$row['fch_entity_id'],
			'kind'        => $row['fch_change_kind'],
			'owner_id'    => (int)$row['fch_usr_user_id'],
			'actor_id'    => $row['fch_source_usr_user_id'] !== null ? (int)$row['fch_source_usr_user_id'] : null,
			'time'        => $row['fch_create_time'],
		);
	}

	return LogicResult::render(array(
		'ok'          => true,
		'changes'     => $changes,
		'next_cursor' => $next,
	));
}

/**
 * Stamp the calling device's check-in, when the caller is a linked device at
 * all (a browser or a machine key is not). Never allowed to disturb the
 * response it rode in on.
 */
function _drive_changes_stamp_device($cursor) {
	try {
		$session = SessionControl::get_instance();
		if (!method_exists($session, 'get_api_key_id')) {
			return;
		}
		$api_key_id = (int)$session->get_api_key_id();
		if ($api_key_id <= 0) {
			return;
		}
		require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));
		$device = SyncDevice::for_api_key($api_key_id);
		if ($device) {
			$device->touch_seen($cursor);
		}
	} catch (Exception $e) {
		error_log('drive_changes device stamp failed: ' . $e->getMessage());
	}
}

function drive_changes_logic_descriptor(): array {
	return array(
		'description'      => 'Drive sync change feed: changes after a cursor, visible to the caller, with the next cursor (or reset).',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'cursor' => array('type' => 'int', 'required' => false, 'min' => 0, 'label' => 'Last seen change id (0 for a cold start)'),
		),
	);
}
?>
