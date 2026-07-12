<?php

/**
 * drive_link_create — mint a public share link for a Drive file or folder.
 * Owner only (an editor grant does not grant link creation). Tier-gated by
 * drive_share_links. Returns the raw token + URL once.
 */

function drive_link_create_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_share_links_class.php'));
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
	if (!SubscriptionTier::getUserFeature($user_id, 'drive_share_links', false)) {
		return LogicResult::error('Share links are not available on your current plan.');
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
	// Link creation is owner-only.
	if (!DriveHelper::owns($entity_type, $entity, $user_id)) {
		return LogicResult::error('Only the owner can create a share link.');
	}

	// Anonymous encrypted links carry the file key in the URL fragment (never sent
	// to the server) — a single-file mechanism. An encrypted folder holds a
	// distinct key per file, which one fragment can't carry, so folder links are
	// offered only for plaintext folders. (Share encrypted folders to members,
	// who unwrap per-file keys with their own vault.)
	if ($entity_type === DriveHelper::ENTITY_FOLDER && DriveHelper::folder_is_encrypted($entity)) {
		return LogicResult::error('Encrypted folders can\'t use public links. Share them with members instead.');
	}

	$expires_time = null;
	$expires_days = (int)($input['expires_days'] ?? 0);
	if ($expires_days > 0) {
		$expires_time = LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), $expires_days . ' days', 'Y-m-d H:i:s');
	}
	$password = isset($input['password']) ? trim((string)$input['password']) : '';

	$minted = FileShareLink::mint($entity_type, $entity_id, $user_id, $expires_time, $password !== '' ? $password : null);

	return LogicResult::render(array(
		'ok'      => true,
		'link_id' => (int)$minted['link']->key,
		'token'   => $minted['token'],
		'url'     => LibraryFunctions::get_absolute_url('/s/' . $minted['token']),
		'path'    => '/s/' . $minted['token'],
		'expires_time' => $expires_time,
	));
}

function drive_link_create_logic_descriptor(): array {
	return array(
		'description'      => 'Create a public share link for a Drive file or folder (owner only, tier-gated).',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'entity_type'  => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'    => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
			'expires_days' => array('type' => 'int', 'required' => false, 'min' => 0, 'label' => 'Days until the link expires (0 = never)'),
			'password'     => array('type' => 'password', 'required' => false, 'label' => 'Optional password'),
		),
	);
}
?>
