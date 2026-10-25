<?php
/**
 * Logic for adding or editing a machine API key.
 *
 * A new key either reaches everything its owner can do, at the permission
 * chosen, or only what is chosen here: actions by name, and models by verb
 * through the record endpoints (docs/api.md § Scoped machine keys). A key's
 * scope is fixed when it is made; changing it means a new key.
 *
 * @version 1.2 - an action's level is read through Access::allows(), so class:verb levels count
 * @version 1.1 - scoped keys can be made here; a new key starts Active; saving an edit no longer
 *   hands the key to the admin who saved it (the owner is set on create only)
 */
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_api_key_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	if (isset($input['edit_primary_key_value'])) {
		$api_key = new ApiKey($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['apk_api_key_id'])) {
		$api_key = new ApiKey($input['apk_api_key_id'], TRUE);
	}
	else {
		// A key is added to be used: the form starts at Active, not at the
		// first option of the dropdown.
		$api_key = new ApiKey(NULL);
		$api_key->set('apk_is_active', 1);
	}

	// A scope is fixed for the key's life: what was handed out is what it
	// can do. A feature that mints its own keys also owns their owner and
	// address restriction, and this form would overwrite them.
	if ($api_key->key && $api_key->is_scoped()) {
		return LogicResult::error('This key is limited to ' . $api_key->scope_label()
			. '. A key\'s scope is fixed when it is made, so it cannot be edited here: make a new key and delete this one.');
	}

	// Only a new key can be scoped, so only a new key needs what is on offer.
	$choices = $api_key->key ? array('actions' => array(), 'action_capabilities' => array(), 'records' => array())
		: admin_api_key_scope_choices((int)$session->get_permission());
	$page_vars = array(
		'api_key'  => $api_key,
		'session'  => $session,
		'choices'  => $choices,
	);

	if(LibraryFunctions::isFormSubmission()){
		// A refused save re-renders the form with what was chosen.
		$page_vars['input'] = $input;
		$is_new = !$api_key->key;
		$scoped = $is_new && ($input['reach'] ?? 'all') === 'scoped';

		// A typo in the address restriction is a key that silently stops
		// working, so a bad entry refuses the save and names itself.
		if (array_key_exists('apk_ip_restriction', $input)) {
			$entries = array_values(array_filter(array_map('trim', explode(',', (string)$input['apk_ip_restriction'])), 'strlen'));
			foreach ($entries as $entry) {
				if ($problem = IpAddress::entryProblem($entry)) {
					return LogicResult::error('Allowed IP addresses: ' . $problem . '. Nothing was saved.', $page_vars);
				}
			}
			$input['apk_ip_restriction'] = $entries ? implode(',', $entries) : null;
		}

		$editable_fields = array('apk_name', 'apk_is_active', 'apk_ip_restriction');
		if (!$scoped) {
			$editable_fields[] = 'apk_permission';
		}
		// A field the post leaves out keeps its value (a new key's Active
		// default, an edited key's stored setting) rather than being blanked.
		foreach($editable_fields as $field) {
			if (array_key_exists($field, $input)) {
				$api_key->set($field, $input[$field]);
			}
		}

		// Process datetime fields using FormWriter V2 helper
		$start_time = FormWriterV2Base::process_datetimeinput($input, 'apk_start_time', true);
		if($start_time !== NULL){
			$api_key->set('apk_start_time', $start_time);
		}

		$expires_time = FormWriterV2Base::process_datetimeinput($input, 'apk_expires_time', true);
		if($expires_time !== NULL){
			$api_key->set('apk_expires_time', $expires_time);
		}

		if ($scoped) {
			$entries = admin_api_key_scope_from_input($input, $choices);
			if (empty($entries)) {
				return LogicResult::error('Choose at least one thing this key may do.', $page_vars);
			}
			$api_key->set('apk_type', ApiKey::TYPE_MACHINE);
			$api_key->set('apk_scope', implode(',', $entries));
			$api_key->set('apk_permission', ApiKey::permission_for_scope($entries, $choices['action_capabilities']));
		}

		// Only a new key gets its owner and its keys. The owner is whose
		// account the key acts as; an edit must not hand it to whoever saved.
		if($is_new){
			$api_key->set('apk_usr_user_id', $session->get_user_id());
			$public_key = 'public_'.LibraryFunctions::random_string(16);
			$secret_key = 'secret_'.LibraryFunctions::random_string(16);
			$api_key->set('apk_public_key', $public_key);
			$api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_key));
		}

		try {
			$api_key->prepare();
			$api_key->save();
		} catch (SystemBaseException $e) {
			// A malformed scope, a permission outside 1-4, a missing field.
			return LogicResult::error($e->getMessage(), $page_vars);
		}
		$api_key->load();

		// The plain secret is shown once, on the key's page.
		if ($is_new) {
			$_SESSION['new_api_secret'] = $secret_key;
			$_SESSION['new_api_key_id'] = $api_key->key;
		}

		return LogicResult::redirect('/admin/admin_api_key?apk_api_key_id='. $api_key->key);
	}

	return LogicResult::render($page_vars);
}

/**
 * What a scoped key can be given on this site: the actions a machine key can
 * call, with the capability each gates on, and the models the record
 * endpoints expose for each verb. The key acts as the admin making it, so an
 * action needing more than their own permission is left off: it would refuse
 * the key every time.
 *
 * @return array{actions: array<string,string>, action_capabilities: array<string,?string>, records: array<string,string[]>}
 */
function admin_api_key_scope_choices(int $owner_permission): array {
	$actions = array();
	$capabilities = array();
	foreach (ApiLogicEndpoint::actionCatalog() as $name => $entry) {
		if (!ApiLogicEndpoint::machineKeyCanCall($entry['meta'])) {
			continue;
		}
		if (!Access::allows($entry['meta']['auth']['min_user_permission'] ?? 0, $owner_permission)) {
			continue;
		}
		$description = trim((string)($entry['meta']['description'] ?? ''));
		$actions[$name] = $description !== '' ? $name . ' — ' . $description : $name;
		$capabilities[$name] = ApiLogicEndpoint::actionCapability($entry['meta']);
	}

	$records = array('read' => array(), 'create' => array(), 'update' => array(), 'delete' => array());
	foreach (LibraryFunctions::discover_model_classes(array('include_plugins' => true, 'plugin_status' => 'active')) as $class) {
		if ($class::$api_readable) {
			$records['read'][] = $class;
		}
		if ($class::$api_writable) {
			$records['create'][] = $class;
			$records['update'][] = $class;
			$records['delete'][] = $class;
		}
	}
	foreach ($records as $verb => $classes) {
		sort($records[$verb]);
	}

	return array('actions' => $actions, 'action_capabilities' => $capabilities, 'records' => $records);
}

/**
 * The scope entries the form asked for, keeping only what is on offer: an
 * action a machine key can call, a model the record endpoints expose for
 * that verb.
 *
 * @return string[]
 */
function admin_api_key_scope_from_input(array $input, array $choices): array {
	$entries = array();
	$chosen = isset($input['scope_actions']) && is_array($input['scope_actions']) ? $input['scope_actions'] : array();
	foreach ($chosen as $action) {
		if (is_string($action) && isset($choices['actions'][$action])) {
			$entries[] = $action;
		}
	}
	foreach (ApiKey::RECORD_VERBS as $verb) {
		$chosen = isset($input['scope_' . $verb]) && is_array($input['scope_' . $verb]) ? $input['scope_' . $verb] : array();
		foreach ($chosen as $model) {
			if (is_string($model) && in_array($model, $choices['records'][$verb], true)) {
				$entries[] = $model . ':' . $verb;
			}
		}
	}
	return array_values(array_unique($entries));
}

?>
