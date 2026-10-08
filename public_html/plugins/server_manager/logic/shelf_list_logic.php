<?php
/**
 * shelf_list - what is in backup storage under a prefix of the tenant's own.
 *
 * (specs/services_phase2_platform.md §3). The plane lists with its own
 * credential and answers from inside the tenant's storage spaces only: the
 * one new backups go to and any it was moved away from that still hold
 * backups. Each object comes back with its space_id and its key relative to
 * that space. What the site's verify and rehearsal use to find a chain
 * before asking for get URLs.
 *
 * @version 1.1 - every live storage space is listed; each object names its space (specs/storage_targets.md WP4)
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
		'description'      => 'List the objects under a prefix of this site\'s own backup storage, in every storage space it still holds backups in. Each object names its space_id; keys are relative to that space.',
		'requires_session' => true,
		'mutates'          => false,
		'input'            => array(
			'prefix' => array('type' => 'string', 'required' => false, 'label' => 'Prefix inside the site\'s backup storage (blank for all)'),
		),
	);
}
?>
