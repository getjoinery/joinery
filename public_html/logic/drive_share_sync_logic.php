<?php

/**
 * drive_share_sync — reconcile the full set of member grants on a Drive entity.
 * Owner (or staff) only. `grants` maps a grantee to a role; a grantee key may be
 * a numeric user id (the canonical contract) or an email address, which is
 * resolved to a user server-side (unknown emails are skipped). Newly-granted
 * users get a notification.
 */

function drive_share_sync_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));
	require_once(PathHelper::getIncludePath('data/notification_preferences_class.php'));
	require_once(PathHelper::getIncludePath('data/file_changes_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$entity_type = (string)($input['entity_type'] ?? '');
	$entity_id   = (int)($input['entity_id'] ?? 0);
	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.');
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.');
	}
	// Share is owner-only (staff may manage on a member's behalf).
	if (!DriveHelper::owns($entity_type, $entity, $user_id) && (int)$session->get_permission() < 5) {
		return LogicResult::error('Only the owner can share this item.');
	}

	// Private content is owner-only in v1. The key is wrapped to ONE vault, so a
	// grantee would hold access to a file that answers 423 forever — a clear
	// refusal beats a share that looks granted and never opens. The shape to
	// build later is already in place: fkg_file_key_grants models per-user
	// wrapped keys, and server custody can re-wrap the key to each grantee inside
	// the owner's window. That is a feature with its own spec, not a patch here.
	// The EFFECTIVE level, so a file whose folder has just gone Private is
	// refused from the moment of the promise rather than from the moment the
	// converting batch reaches its bytes.
	require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
	$entity_level = ($entity_type === DriveHelper::ENTITY_FOLDER)
		? DriveHelper::folder_level($entity)
		: DriveHelper::effective_file_level($entity);
	if ($entity_level === ProtectionLevel::PRIVATE_) {
		return LogicResult::error('Private items can\'t be shared with other members yet — they open only with your own key.');
	}

	// Resolve grants: keys may be user ids or emails. Unresolvable entries are
	// reported back as `skipped` — never silently dropped.
	$raw = isset($input['grants']) && is_array($input['grants']) ? $input['grants'] : array();
	$desired = array();
	$skipped = array();
	foreach ($raw as $key => $role) {
		$role = ($role === 'editor') ? 'editor' : 'viewer';
		if (is_numeric($key)) {
			$uid = (int)$key;
		} else {
			$u = User::GetByEmail(trim((string)$key));
			$uid = ($u && $u->key) ? (int)$u->key : 0;
		}
		if ($uid > 0) {
			$desired[$uid] = $role;
		} else {
			$skipped[] = (string)$key;
		}
	}

	$owner_id = DriveHelper::owner_id_of($entity_type, $entity);
	// The owner never holds a grant row on their own entity; don't count one.
	unset($desired[$owner_id]);
	$newly = FileAccessGrant::sync_for_entity($entity_type, $entity_id, $desired, $owner_id);

	FileChange::record(FileChange::KIND_GRANT_CHANGED, $entity_type, $entity_id, $owner_id, $user_id);

	// Notify newly-granted users (honoring their preference; absent row = on).
	$entity_name = ($entity_type === DriveHelper::ENTITY_FOLDER) ? $entity->get('fol_name') : $entity->get('fil_title');
	$actor = new User($user_id, true);
	$actor_name = $actor->key ? trim($actor->get('usr_first_name') . ' ' . $actor->get('usr_last_name')) : 'Someone';
	foreach ($newly as $grantee_id) {
		$pref = NotificationPreference::get_for($grantee_id, 'drive_share');
		if ($pref !== null && !$pref->get('ntp_subscribed')) {
			continue;
		}
		Notification::create_notification(
			$grantee_id,
			'drive_share',
			'A file was shared with you',
			$actor_name . ' shared "' . $entity_name . '" with you.',
			'/drive?shared=1',
			$user_id
		);
	}

	return LogicResult::render(array(
		'ok'            => true,
		'granted_count' => count($desired),
		'skipped'       => $skipped,
	));
}

function drive_share_sync_logic_descriptor(): array {
	return array(
		'description'      => 'Reconcile the member grants on a Drive file or folder (owner only). Body carries `grants`: a JSON object mapping each grantee (user id or email) to a role (viewer|editor). It is a map rather than a list, so it is validated in the logic (not the descriptor schema) and passes through the boundary untouched.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
		),
	);
}
?>
