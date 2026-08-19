<?php
/**
 * One ownership: who owns what, where it came from, and the actions on it.
 *
 * @version 1.1.0
 */
function admin_ownership_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$ownership_id = $input['own_ownership_id'] ?? NULL;
	$ownership = new Ownership($ownership_id, TRUE);
	// A soft-deleted row confers nothing and the list never shows it; a stale
	// URL to one must not render it as an active ownership.
	if (!$ownership->key || !$ownership->get('own_tag') || $ownership->get('own_delete_time')) {
		return LogicResult::error('That ownership no longer exists.');
	}

	$self = '/plugins/store/admin/admin_ownership_edit?own_ownership_id=' . $ownership->key;

	if (LibraryFunctions::isFormSubmission() && !empty($input['action'])) {
		if ($input['action'] == 'revoke' || $input['action'] == 'unrevoke') {
			$ownership->assert_can_write($session);
			$ownership->set('own_revoked_time', $input['action'] == 'revoke' ? gmdate('Y-m-d H:i:s') : NULL);
			$ownership->save();
			return LogicResult::redirect($self);
		}
	}

	$owner = new User($ownership->get('own_usr_user_id'), TRUE);

	// Which products this ownership covers, so the operator can see what the
	// buyer is exempt from without reading tags off the product list.
	$covered_criteria = ($ownership->get('own_tag') === Ownership::TAG_ALL)
		? array('has_ownership_tag' => TRUE)
		: array('ownership_tag' => $ownership->get('own_tag'));
	$covered_products = new MultiProduct($covered_criteria, array('pro_name' => 'ASC'));
	$covered_products->load();

	return LogicResult::render(array(
		'session' => $session,
		'ownership' => $ownership,
		'owner' => $owner,
		'covered_products' => $covered_products,
	));
}
?>
