<?php
/** @joinery-admin class=content */
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/content_versions_class.php'));

	$session = SessionControl::get_instance();

	if (!empty($_REQUEST['itm_item_id'])) {
		$item = new Item($_REQUEST['itm_item_id'], TRUE);
		$item->assert_found();
	} else {
		$item = new Item(NULL);
	}

	if($_POST){

		// The item's own columns (the form once posted a post's field names).
		foreach (array('itm_name', 'itm_description', 'itm_body') as $field) {
			if (array_key_exists($field, $_POST)) {
				$item->set($field, $_POST[$field]);
			}
		}
		if (trim((string)$item->get('itm_name')) === '') {
			throw new SystemDisplayableError('An item needs a name.');
		}

		if(!$item->get('itm_link') || Access::permits(10)){
			if(!empty($_POST['itm_link'])){
				$item->set('itm_link', $item->create_url($_POST['itm_link']));
			}
			else{
				$item->set('itm_link', $item->create_url($item->get('itm_name')));
			}
		}

		if(!empty($_POST['itm_is_published'])){
			if(!$item->get('itm_published_time')){
				$item->set('itm_published_time', 'NOW()');
			}
		}
		else {
			$item->set('itm_published_time', NULL);
		}

		if(!$item->key){
			$item->set('itm_usr_user_id',$session->get_user_id());
		}

		$item->prepare();
		$item->save();
		$item->load();

/*
		if($_REQUEST['tags']){
			//PROCESS THE TAGS
			$tags_array = explode(',',$_REQUEST['tags']);
			$tags_array = array_filter($tags_array);
			foreach ($tags_array as $key=>$tag){
				$tags_array[$key] = preg_replace("/[^A-Za-z0-9 -_]/", '', trim($tag));
			}
			Group::AddMemberBulkByName($item->key, $tags_array, 'item_tag');

			//$item->save_tags($tags_array);
		}
*/
		LibraryFunctions::redirect('/plugins/items/admin/admin_item?itm_item_id='. $item->key);
		exit;
	}

	$title = $item->get('itm_name');
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if(!empty($_GET['cvn_content_version_id'])){
		ContentVersion::show_in_record($_GET['cvn_content_version_id'], $item, ContentVersion::TYPE_ITEM, 'itm_body', 'itm_name');
	}
	$content = $item->get('itm_body');

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'blog-items',
		'breadcrumbs' => array(
			'Items'=>'/plugins/items/admin/admin_items',
			'Edit Item' => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = "Edit Item";
	$page->begin_box($pageoptions);

	echo '<div class="row">
    <div class="col-md-8">
      <div class="p-3">';

	// Editing an existing item
	$formwriter = $page->getFormWriter('form1', [
		'action' => '/plugins/items/admin/admin_item_edit'
	]);


	$formwriter->begin_form();

	$tags = '';
	if($item->key){
		$formwriter->hiddeninput('itm_item_id', '', ['value' => $item->key]);
		$formwriter->hiddeninput('action', '', ['value' => 'edit']);

		//$item_tags = Group::get_groups_for_member($item->key, 'item_tag', false, 'names');

		//$tags = implode(', ', $item_tags);
	}

	$formwriter->textinput('itm_name', 'Item name', [
		'value' => $title,
		'maxlength' => 255
	]);

	$formwriter->textinput('itm_description', 'Short description (optional)', [
		'value' => $item->get('itm_description'),
		'maxlength' => 255
	]);

	//echo $formwriter->textinput('Tags (optional, separate with comma)', 'tags', NULL, 100, $tags, '', 255, '');

	if(!$item->get('itm_link') || Access::permits(10)){
		$formwriter->textinput('itm_link', 'Link (only letters, numbers, and dashes) '.$settings->get_setting('webDir').'/blog/', [
			'value' => $item->get('itm_link'),
			'maxlength' => 255
		]);
	}

	$optionvals = array(0=>"No", 1=>"Yes");
	$formwriter->dropinput("itm_is_published", "Published", [
		'options' => $optionvals,
		'value' => $item->get('itm_published_time') ? 1 : 0
	]);

	$formwriter->textbox('itm_body', 'Item content', [
		'rows' => 5,
		'cols' => 80,
		'value' => $content,
		'htmlmode' => 'yes'
	]);

	$formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);
	$formwriter->end_form();

	echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_ITEM, 'foreign_key_id' => $item->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();

	$optionvals = $content_versions->get_dropdown_array($session, FALSE);

	if(count($optionvals)){
		$formwriter = $page->getFormWriter('form_load_version', [
			'action' => '/plugins/items/admin/admin_item_edit',
			'method' => 'GET'
		]);
		$formwriter->begin_form();
		$formwriter->hiddeninput('itm_item_id', '', ['value' => $item->key]);
		$formwriter->dropinput("cvn_content_version_id", "Load another version", [
			'options' => $optionvals
		]);
		$formwriter->submitbutton('btn_submit', 'Load', ['class' => 'btn btn-primary']);
		$formwriter->end_form();
	}
	else{
		echo 'No saved versions.';
	}

	echo '	</div>
	</div>
</div>	';
	$page->end_box();

	$page->admin_footer();

?>
