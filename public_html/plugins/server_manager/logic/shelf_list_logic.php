<?php
/**
 * shelf_list - what is in backup storage under a prefix of the tenant's own.
 *
 * (specs/services_phase2_platform.md §3). The plane lists with its own
 * credential and answers from inside the tenant's prefix only; keys come
 * back relative to it. What the site's verify and rehearsal use to find a
 * chain before asking for get URLs.
 *
 * @version 1.0
 */
function shelf_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	try {
		$row = ShelfBroker::tenantFor($user_id, intval($session->get_api_key_id()));
		$data = ShelfBroker::listPrefix($row, (string)($input['prefix'] ?? ''));
	} catch (ShelfBrokerException $e) {
		return LogicResult::error($e->getMessage());
	} catch (S3SignerException $e) {
		return LogicResult::error('Backup storage could not be listed: ' . $e->getMessage());
	}
	return LogicResult::render($data);
}

function shelf_list_logic_descriptor(): array {
	return array(
		'description'      => 'List the objects under a prefix of this site\'s own backup storage. Keys are relative to the site\'s prefix.',
		'requires_session' => true,
		'mutates'          => false,
		'input'            => array(
			'prefix' => array('type' => 'string', 'required' => false, 'label' => 'Prefix inside the site\'s backup storage (blank for all)'),
		),
	);
}
?>
