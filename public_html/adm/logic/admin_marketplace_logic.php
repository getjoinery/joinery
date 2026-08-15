<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_marketplace_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();

	$session->check_permission(10);
	$session->set_return();

	// Process the install action before loading display data.
	if (($input['action'] ?? '') === 'install') {
		return admin_marketplace_handle_install($input, $session);
	}

	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session'] = $session;

	$upgrade_source = MarketplaceClient::source();
	if ($upgrade_source === null) {
		$page_vars['error'] = 'No upgrade source configured. Set the upgrade_source setting in Admin > Settings to use the marketplace.';
		$page_vars['themes'] = array();
		$page_vars['plugins'] = array();
		return LogicResult::render($page_vars);
	}

	$remote_themes = MarketplaceClient::fetch_catalog('themes');
	$remote_plugins = MarketplaceClient::fetch_catalog('plugins');

	$page_vars['themes'] = MarketplaceClient::enrich_with_local_status(
		$remote_themes, MarketplaceClient::local_names('theme'), 'theme');
	$page_vars['plugins'] = MarketplaceClient::enrich_with_local_status(
		$remote_plugins, MarketplaceClient::local_names('plugin'), 'plugin');
	$page_vars['upgrade_source'] = $upgrade_source;
	$page_vars['catalog_error'] = (empty($remote_themes) && empty($remote_plugins));

	return LogicResult::render($page_vars);
}

/**
 * Install action (POST). The install buttons are single-button action forms
 * sharing the marketplace_install FormWriter token the view emits.
 */
function admin_marketplace_handle_install(array $input, $session): LogicResult {
	$formwriter = new FormWriterV2HTML5('marketplace_install');
	if (!$formwriter->validateCSRF($input)) {
		$session->save_message(new DisplayMessage(
			'Invalid or expired request token. Please try again.',
			'Error',
			NULL,
			DisplayMessage::MESSAGE_ERROR
		));
		return LogicResult::redirect('/admin/admin_marketplace');
	}

	$type = ($input['type'] ?? '') === 'plugin' ? 'plugin' : 'theme';

	try {
		$installed_name = MarketplaceClient::install($type, $input['name'] ?? '');

		$admin_page = $type === 'plugin' ? '/admin/admin_plugins' : '/admin/admin_themes';
		$session->save_message(new DisplayMessage(
			ucfirst($type) . " '" . htmlspecialchars($installed_name) . "' installed successfully. <a href=\"$admin_page\">Go to " . ucfirst($type) . "s</a> to activate it.",
			'Installed',
			NULL,
			DisplayMessage::MESSAGE_ANNOUNCEMENT
		));
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage(
			'Install failed: ' . htmlspecialchars($e->getMessage()),
			'Install Error',
			NULL,
			DisplayMessage::MESSAGE_ERROR
		));
	}

	return LogicResult::redirect('/admin/admin_marketplace');
}
?>
