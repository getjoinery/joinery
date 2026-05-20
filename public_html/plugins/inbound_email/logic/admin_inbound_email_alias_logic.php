<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_inbound_email_alias_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	// Load or create alias
	if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
		$alias = new InboundEmailAlias($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['iea_inbound_email_alias_id'])) {
		$alias = new InboundEmailAlias($input['iea_inbound_email_alias_id'], TRUE);
	} else {
		$alias = new InboundEmailAlias(NULL);
	}

	// Process form submission
	if ($input && isset($input['iea_alias'])) {
		$editable_fields = array('iea_ied_inbound_email_domain_id', 'iea_alias', 'iea_destinations', 'iea_description', 'iea_delivery_mode');
		foreach ($editable_fields as $field) {
			if (isset($input[$field])) {
				$value = $input[$field];
				// People often paste the whole address into the mailbox field;
				// keep only the local part before the @.
				if ($field === 'iea_alias') {
					$at = strpos($value, '@');
					if ($at !== false) {
						$value = substr($value, 0, $at);
					}
				}
				$alias->set($field, $value);
			}
		}

		$alias->set('iea_is_enabled', isset($input['iea_is_enabled']) ? true : false);

		try {
			$alias->prepare();
			$alias->save();
			$alias->load();

			$session->save_message(new DisplayMessage(
				'Alias saved.',
				'Saved',
				'/plugins/inbound_email/admin/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/plugins/inbound_email/admin/admin_inbound_email');
		} catch (InboundEmailAliasException $e) {
			return LogicResult::render(array(
				'alias' => $alias,
				'error' => $e->getMessage(),
				'session' => $session,
				'settings' => $settings,
				'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
			));
		}
	}

	// Load domains for dropdown
	$domains = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
	$domains->load();

	// A new alias defaults to enabled — the common case, so the operator does
	// not have to remember to tick the box.
	if (!$alias->key) {
		$alias->set('iea_is_enabled', true);
	}

	return LogicResult::render(array(
		'alias' => $alias,
		'session' => $session,
		'settings' => $settings,
		'domains' => $domains,
	));
}
?>
