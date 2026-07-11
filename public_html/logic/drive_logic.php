<?php

/**
 * drive_logic — the /drive page. Renders the shell and hands the first folder
 * listing to the view for first paint; everything after is drive.js calling the
 * drive_* API actions.
 */

function drive_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not available.');
	}
	if (!$session->is_logged_in()) {
		return LogicResult::redirect('/login?return=/drive');
	}

	$user_id = (int)$session->get_user_id();

	// Server-side first paint of the requested folder/view.
	require_once(PathHelper::getIncludePath('logic/drive_list_logic.php'));
	$listing = drive_list_logic(array(
		'folder_id' => isset($input['folder_id']) ? (int)$input['folder_id'] : 0,
		'view'      => isset($input['view']) ? (string)$input['view'] : 'mine',
	));
	$initial = ($listing instanceof LogicResult && $listing->error === null) ? $listing->data : array('items' => array());

	$page_vars = array();
	$page_vars['title']              = 'Drive';
	$page_vars['initial']            = $initial;
	$page_vars['share_links_enabled'] = (bool)SubscriptionTier::getUserFeature($user_id, 'drive_share_links', false);
	$page_vars['max_file_bytes']     = (int)SubscriptionTier::getUserFeature($user_id, 'drive_max_file_bytes', 0);
	$page_vars['quota_bytes']        = (int)SubscriptionTier::getUserFeature($user_id, 'drive_storage_bytes', 0);
	$page_vars['chunk_bytes']        = (int)$settings->get_setting('drive_upload_chunk_bytes');
	$page_vars['session']            = $session;

	return LogicResult::render($page_vars);
}
?>
