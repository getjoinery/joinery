<?php
	// @version 1.1 - a POST must carry the subscription_cancel form's token

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('orders_recurring_action_logic.php', 'logic', 'system', null, 'store', false));

	// The Cancel buttons post the subscription_cancel form's token; a POST
	// without it did not come from one of them.
	if (LibraryFunctions::isFormSubmission()) {
		$cancel_form = new FormWriterV2HTML5('subscription_cancel');
		if (!$cancel_form->validateCSRF($_POST)) {
			throw new SystemDisplayableError('This page had expired. Open your subscriptions again and press Cancel.');
		}
	}

	$page_vars = process_logic(orders_recurring_action_logic(array_merge($_GET, $_POST, $params ?? [])));

?>
