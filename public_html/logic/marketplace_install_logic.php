<?php

function marketplace_install_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Superadmin permission required.');
	}

	$type = (string)($input['type'] ?? '');
	if ($type !== 'plugin' && $type !== 'theme') {
		return LogicResult::error('Type must be plugin or theme.');
	}

	try {
		$installed_name = MarketplaceClient::install($type, $input['name'] ?? '');
	} catch (Exception $e) {
		return LogicResult::error('Install failed: ' . $e->getMessage());
	}

	return LogicResult::render(array(
		'installed' => $installed_name,
		'type' => $type,
	));
}

function marketplace_install_logic_descriptor(): array {
	return array(
		'description'      => 'Download a theme or plugin archive from the configured upgrade source and install it (files on disk, synced and ready to activate). Superadmin only.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => array(
			'capability'          => 'write',
			'min_user_permission' => 10,
		),
		'input'            => array(
			'type' => array('type' => 'string', 'required' => true, 'enum' => array('plugin', 'theme'), 'label' => 'Extension type'),
			'name' => array('type' => 'string', 'required' => true, 'label' => 'Directory name as listed in the catalog'),
		),
	);
}
?>
