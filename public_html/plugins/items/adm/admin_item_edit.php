<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/items_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/content_versions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if (isset($_REQUEST['pst_item_id'])) {
		$item = new Item($_REQUEST['itm_item_id'], TRUE);
	} else {
		$item = new Item(NULL);
	}

	if($_POST){
		
		$editable_fields = array('itm_body', 'itm_title', 'itm_is_published', 'itm_description', 'itm_body', 'itm_is_pinned');

		foreach($editable_fields as $field) {
			$item->set($field, $_POST[$field]);
		}

		if(!$item->get('itm_link') || $_SESSION['permission'] == 10){
			if($_POST['itm_link']){
				$item->set('itm_link', $item->create_url($_POST['itm_link']));
			}
			else{
				$item->set('itm_link', $item->create_url($event->get('itm_title')));
			}
		}
		
		if($_REQUEST['itm_is_published']){
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
		LibraryFunctions::redirect('/admin/admin_item?itm_item_id='. $item->key);
		exit;		
	}

	$title = $item->get('itm_name');
	$content = $item->get('itm_body');
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($_GET['cnv_content_version_id']){
		$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'blog-items',
		'breadcrumbs' => array(
			'Items'=>'/admin/admin_items', 
			'Edit Item' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "Edit Item";
	$page->begin_box($pageoptions);
	
	

	echo '<div uk-grid>
    <div class="uk-width-2-3@m"><div style="padding: 20px">';

	// Editing an existing email
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['itm_description']['required']['value'] = 'true';
	$validation_rules['itm_description']['minlength']['value'] = 10;
	$validation_rules['itm_name']['required']['value'] = 'true';
	$validation_rules['itm_name']['minlength']['value'] = 2;
	if($_SESSION['permission'] == 10){
		$validation_rules['itm_link']['required']['value'] = 'true';
	}	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_item_edit');

	$tags = '';
	if($item->key){
		echo $formwriter->hiddeninput('itm_item_id', $item->key);
		echo $formwriter->hiddeninput('action', 'edit');
		
		//$item_tags = Group::get_groups_for_member($item->key, 'item_tag', false, 'names');

		//$tags = implode(', ', $item_tags);
	}

	
	echo $formwriter->textinput('Item name', 'itm_title', NULL, 100, $title, '', 255, '');	
	
	echo $formwriter->textinput('Short description (optional)', 'itm_short_description', NULL, 100, $item->get('itm_short_description'), '', 255, '');	
	
	//echo $formwriter->textinput('Tags (optional, separate with comma)', 'tags', NULL, 100, $tags, '', 255, '');	
	
	if(!$item->get('itm_link') || $_SESSION['permission'] == 10){
		echo $formwriter->textinput('Link (only letters, numbers, and dashes) '.$settings->get_setting('webDir').'/blog/', 'itm_link', NULL, 100, $item->get('itm_link'), '', 255, '');	
	}	
	
	
	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Published", "itm_is_published", "ctrlHolder", $optionvals, $item->get('itm_is_published'), '', FALSE);

	
	echo $formwriter->textbox('Item content', 'itm_body', 'ctrlHolder', 5, 80, $content, '', 'yes');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	echo '	</div>
	</div>
	<div class="uk-width-1-3@m"><div style="padding: 20px">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_POST, 'foreign_key_id' => $item->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array(FALSE, $session);

	if(count($optionvals)){
		$formwriter = LibraryFunctions::get_formwriter_object('form_load_version', 'admin');
		echo $formwriter->begin_form('form_load_version', 'GET', '/admin/admin_item_edit');
		echo $formwriter->hiddeninput('itm_item_id', $item->key);
		echo $formwriter->dropinput("Load another version", "cnv_content_version_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
		echo $formwriter->new_form_button('Load');	
		echo $formwriter->end_form();
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
