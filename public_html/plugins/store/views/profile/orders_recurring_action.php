<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('orders_recurring_action_logic.php', 'logic', 'system', null, 'store', false));

	$page_vars = process_logic(orders_recurring_action_logic(array_merge($_GET, $_POST, $params ?? [])));

?>
