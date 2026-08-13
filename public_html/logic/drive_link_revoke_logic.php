<?php

/**
 * drive_link_revoke — revoke a share link the current user created. Owner only.
 */

function drive_link_revoke_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/file_share_links_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$link_id = (int)($input['link_id'] ?? 0);
	$link = new FileShareLink($link_id, true);
	if (!$link->key) {
		return LogicResult::error('Share link not found.');
	}
	if ((int)$link->get('fsl_usr_user_id') !== $user_id && (int)$session->get_permission() < 5) {
		return LogicResult::error('You did not create this link.');
	}

	$link->revoke();
	return LogicResult::render(array('ok' => true));
}

function drive_link_revoke_logic_descriptor(): array {
	return array(
		'description'      => 'Revoke a Drive share link (creator only).',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'link_id' => array('type' => 'int', 'required' => true, 'label' => 'Share link id'),
		),
	);
}
?>
