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

	// The tree belongs to root: this queues the install and answers with the
	// request id. The caller polls root_request_status until it is done or
	// failed; a failed request's transcript carries the verdict.
	try {
		$request_id = MarketplaceClient::install($type, $input['name'] ?? '', (int)$session->get_user_id());
	} catch (Exception $e) {
		return LogicResult::error('Install failed: ' . $e->getMessage());
	}

	return LogicResult::render(array(
		'request_id' => $request_id,
		'type'       => $type,
		'name'       => basename((string)($input['name'] ?? '')),
		'status'     => RootRequest::status($request_id),
		'actor'      => RootRequest::actorState(),
	));
}

function marketplace_install_logic_descriptor(): array {
	return array(
		'description'      => 'Ask root to download a theme or plugin archive from the configured upgrade source, verify it against the release key, and install it. Answers with the root request id; poll root_request_status for the outcome and transcript. Superadmin only.',
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
