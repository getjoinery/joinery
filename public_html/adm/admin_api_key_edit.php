<?php
/**
 * Admin: add or edit a machine API key. See adm/logic/admin_api_key_edit_logic.php.
 *
 * @version 1.1 - a new key can be limited to chosen actions and records
 */

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_api_key_edit_logic.php'));

	$page_vars = process_logic(admin_api_key_edit_logic(array_merge($_GET, $_POST)));
	extract($page_vars);
	$input = $page_vars['input'] ?? array();
	$is_new = !$api_key->key;
	$title = $is_new ? 'Add API key' : 'Edit API key';

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'api_keys',
		'page_title' => $title,
		'readable_title' => $title,
		'breadcrumbs' => array(
			'API Keys'=>'/admin/admin_api_keys',
			$title => '',
		),
		'session' => $session,
	)
	);

	$page->begin_box(array('title' => $title));

	$formwriter = $page->getFormWriter('form1', [
		'model' => $api_key,
		'edit_primary_key_value' => $api_key->key
	]);

	echo $formwriter->begin_form();

	$formwriter->textinput('apk_name', 'Key name');

	$formwriter->dropinput('apk_is_active', 'Active', [
		'options' => [0 => 'No', 1 => 'Yes']
	]);

	$scope_fields = array('scope_actions', 'scope_read', 'scope_create', 'scope_update', 'scope_delete');
	if ($is_new) {
		$formwriter->radioinput('reach', 'What it can do', array(
			'options' => array(
				'all'    => 'Everything its owner can do, at the permission below',
				'scoped' => 'Only the actions and records chosen below',
			),
			'value' => ($input['reach'] ?? 'all') === 'scoped' ? 'scoped' : 'all',
			'visibility_rules' => array(
				'all'    => array('show' => array('apk_permission'), 'hide' => $scope_fields),
				'scoped' => array('show' => $scope_fields, 'hide' => array('apk_permission')),
			),
		));
	}

	$formwriter->dropinput('apk_permission', 'Permission', [
		'options' => [
			1 => 'Read only',
			2 => 'Write only',
			3 => 'Read + Write',
			4 => 'Read + Write + Delete',
		]
	]);

	if ($is_new) {
		$checked = function ($name) use ($input) {
			return isset($input[$name]) && is_array($input[$name]) ? $input[$name] : array();
		};
		$formwriter->checkboxList('scope_actions', 'Actions it may call', array(
			'options' => $choices['actions'],
			'checked' => $checked('scope_actions'),
		));
		$verb_labels = array(
			'read'   => 'Records it may read',
			'create' => 'Records it may create',
			'update' => 'Records it may update',
			'delete' => 'Records it may delete',
		);
		foreach ($verb_labels as $verb => $label) {
			$formwriter->checkboxList('scope_' . $verb, $label, array(
				'options' => array_combine($choices['records'][$verb], $choices['records'][$verb]),
				'checked' => $checked('scope_' . $verb),
			));
		}
	}

	$formwriter->textinput('apk_ip_restriction', 'Allowed IP addresses (comma separated) (optional)');

	$formwriter->datetimeinput('apk_start_time', 'Key start time (optional)');

	$formwriter->datetimeinput('apk_expires_time', 'Key expires time (optional)');

	$formwriter->submitbutton('btn_submit', $is_new ? 'Add key' : 'Save');

	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
