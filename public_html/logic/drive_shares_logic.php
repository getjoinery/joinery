<?php

/**
 * drive_shares — current sharing state for a Drive entity (grants + share links),
 * for the share dialog. Owner (or staff) only. Read-only.
 */

function drive_shares_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));
	require_once(PathHelper::getIncludePath('data/file_share_links_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

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
	// Sharing is managed by the owner (or staff).
	if (!DriveHelper::owns($entity_type, $entity, $user_id) && (int)$session->get_permission() < 5) {
		return LogicResult::error('Only the owner can manage sharing.');
	}

	$grants = array();
	$g = new MultiFileAccessGrant(array('entity_type' => $entity_type, 'entity_id' => $entity_id));
	$g->load();
	foreach ($g as $row) {
		$gu = new User((int)$row->get('fga_usr_user_id'), true);
		$grants[] = array(
			'user_id' => (int)$row->get('fga_usr_user_id'),
			'name'    => $gu->key ? trim($gu->get('usr_first_name') . ' ' . $gu->get('usr_last_name')) : '',
			'email'   => $gu->key ? $gu->get('usr_email') : '',
			'role'    => $row->get('fga_role'),
		);
	}

	$links = array();
	$l = new MultiFileShareLink(array('entity_type' => $entity_type, 'entity_id' => $entity_id), array('fsl_create_time' => 'DESC'));
	$l->load();
	foreach ($l as $row) {
		$links[] = array(
			'link_id'      => (int)$row->key,
			'expires_time' => $row->get('fsl_expires_time'),
			'revoked'      => $row->get('fsl_revoked_time') !== null && $row->get('fsl_revoked_time') !== '',
			'has_password' => $row->requires_password(),
			'access_count' => (int)$row->get('fsl_access_count'),
			'live'         => $row->is_live(),
		);
	}

	return LogicResult::render(array(
		'ok'                 => true,
		'grants'             => $grants,
		'links'              => $links,
		'share_links_enabled'=> (bool)SubscriptionTier::getUserFeature($user_id, 'drive_share_links', false),
	));
}

function drive_shares_logic_descriptor(): array {
	return array(
		'description'      => 'List the grants and share links on a Drive file or folder (owner only).',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
		),
	);
}
?>
