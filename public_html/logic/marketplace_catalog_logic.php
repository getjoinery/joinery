<?php

function marketplace_catalog_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Superadmin permission required.');
	}

	$upgrade_source = MarketplaceClient::source();
	if ($upgrade_source === null) {
		return LogicResult::error('No upgrade source configured. Set the upgrade_source setting to use the marketplace.');
	}

	return LogicResult::render(array(
		'upgrade_source' => $upgrade_source,
		'plugins' => MarketplaceClient::enrich_with_local_status(
			MarketplaceClient::fetch_catalog('plugins'), MarketplaceClient::local_names('plugin'), 'plugin'),
		'themes' => MarketplaceClient::enrich_with_local_status(
			MarketplaceClient::fetch_catalog('themes'), MarketplaceClient::local_names('theme'), 'theme'),
	));
}

function marketplace_catalog_logic_descriptor(): array {
	return array(
		'description'      => 'Themes and plugins available from the configured upgrade source, each marked installed / not_installed locally. Superadmin only.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array(
			'capability'          => 'read',
			'min_user_permission' => 10,
		),
	);
}
?>
