<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
	if (isset($_POST['edit_primary_key_value'])) {
		$file = new File($_POST['edit_primary_key_value'], TRUE);
	} elseif (isset($_GET['fil_file_id'])) {
		$file = new File($_GET['fil_file_id'], TRUE);
	} else {
		echo 'Must pass a file';
		exit();
	}

	if($_POST){

		if($_POST['fil_description']){
				$_POST['fil_description'] = $_POST['fil_description'];
		}

		if($_POST['fil_min_permission'] === NULL || $_POST['fil_min_permission'] === ''){
			$file->set('fil_min_permission', NULL);
		}
		else{
			$file->set('fil_min_permission', $_POST['fil_min_permission']);
		}

		if($_POST['fil_grp_group_id'] === NULL || $_POST['fil_grp_group_id'] === ''){
			$file->set('fil_grp_group_id', NULL);
		}
		else{
			$file->set('fil_grp_group_id', $_POST['fil_grp_group_id']);
		}

		// Access gate: value is "" (ungated) or "{provider}:{ref}".
		$access_gate = $_POST['access_gate'] ?? '';
		if($access_gate === ''){
			$file->set('fil_access_provider', NULL);
			$file->set('fil_access_ref', NULL);
		}
		else{
			list($gate_provider, $gate_ref) = array_pad(explode(':', $access_gate, 2), 2, NULL);
			$file->set('fil_access_provider', $gate_provider);
			$file->set('fil_access_ref', ($gate_ref === NULL || $gate_ref === '') ? NULL : (int)$gate_ref);
		}

		$editable_fields = array('fil_description', 'fil_title','fil_gal_gallery_id');

		foreach($editable_fields as $field) {
			$file->set($field, $_POST[$field]);
		}

		$file->prepare();
		$file->save();
		$file->load();

		LibraryFunctions::redirect('/admin/admin_file?fil_file_id='.$file->key);
		return;
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'files-parent',
		'page_title' => 'File Edit',
		'readable_title' => 'File Edit',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	$pageoptions['title'] = 'File Edit: '.$file->get('fil_title');
	$page->begin_box($pageoptions);

	// Editing an existing file
	$formwriter = $page->getFormWriter('form1', [
		'model' => $file,
		'edit_primary_key_value' => $file->key
	]);
	$formwriter->begin_form();

	$formwriter->textinput('fil_title', 'File title');

	$formwriter->textbox('fil_description', 'File Description', [
		'htmlmode' => 'no'
	]);

	$optionvals = array(null => 'Public (anyone)', 0=>'Any logged in user (0)', 5=>'Assistant (5)', 8=>'Admin (8)', 10 => 'Master Admin (10)');
	$formwriter->dropinput("fil_min_permission", "Permission level can access", [
		'options' => $optionvals
	]);

	$groups = new MultiGroup(
		array('category'=>'user'),  //SEARCH
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$groups->load();

	$optionvals1[NULL] = 'All';
	$optionvals2 = $groups->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	$formwriter->dropinput("fil_grp_group_id", "Group can access", [
		'options' => $optionvals
	]);

	// Access gate picker: "All" plus, per registered gate provider, each of
	// its references. Value encodes "{provider}:{ref}".
	require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
	$gate_options = ['' => 'All'];
	foreach(AccessGateRegistry::all() as $gate){
		foreach($gate->options() as $ref => $ref_label){
			$gate_options[$gate->key().':'.$ref] = $gate->label().': '.$ref_label;
		}
	}
	$current_gate = $file->get('fil_access_provider') ? $file->get('fil_access_provider').':'.$file->get('fil_access_ref') : '';
	$formwriter->dropinput("access_gate", "Access restricted to", [
		'options' => $gate_options,
		'value'   => $current_gate
	]);

	// Tier Gating
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	$tier_options = ['' => 'No tier required'];
	$all_tiers = MultiSubscriptionTier::GetAllActive();
	foreach ($all_tiers as $tier) {
		$tier_options[$tier->get('sbt_tier_level')] = htmlspecialchars($tier->get('sbt_display_name')) . ' (Level ' . $tier->get('sbt_tier_level') . ')';
	}
	$formwriter->dropinput('fil_tier_min_level', 'Minimum Tier Required', [
		'options' => $tier_options,
		'helptext' => 'Restrict this file to users with this subscription tier or higher'
	]);

	if($file->is_image()){
	/*
		echo $formwriter->checkboxinput("Include this image in the gallery", "fil_gal_gallery_id", "checkbox", "left", $file->get('fil_gal_gallery_id'), 1, "");
		*/
	}

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

$page->end_box();
	$page->admin_footer();

?>
