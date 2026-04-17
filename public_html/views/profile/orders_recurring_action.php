<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('orders_recurring_action_logic.php', 'logic'));

	$page_vars = process_logic(orders_recurring_action_logic($_GET, $_POST));

?>
