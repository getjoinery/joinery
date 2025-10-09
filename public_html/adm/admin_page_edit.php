<?php
	
	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/pages_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance(); 

	if (isset($_REQUEST['pag_page_id'])) {
		$page = new Page($_REQUEST['pag_page_id'], TRUE);
	} 
	else {
		$page = new Page(NULL);
	}

	if($_POST){

		$editable_fields = array('pag_title');

		foreach($editable_fields as $field) {
			$page->set($field, $_POST[$field]);
		}

		if(!$page->get('pag_link') || $_SESSION['permission'] == 10){
			if($_POST['pag_link']){
				$page->set('pag_link', $page->create_url($_POST['pag_link']));
			}
			else{
				$page->set('pag_link', $page->create_url($event->get('pag_title')));
			}
		}
		
		if($_POST['pag_is_published']){
			if(!$page->get('pag_published_time')){
				$page->set('pag_published_time', 'NOW()');
			}
		}	
		else {
			$page->set('pag_published_time', NULL);
		} 
		
		$page->set('pag_body', $_POST['pag_body']);

		if(!$page->key){
			$page->set('pag_usr_user_id',$session->get_user_id());
		}	

		$page->prepare();
		$page->save();
		$page->load();
		
		LibraryFunctions::redirect('/admin/admin_page?pag_page_id='. $page->key);
		return;
	}

	$title = $page->get('pag_title');
	$content = $page->get('pag_body');

	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($_GET['cnv_content_version_id']){
		$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}

	$paget = new AdminPage();
	$paget->admin_header(	
	array(
		'menu-id'=> 'pages',
		'breadcrumbs' => array(
			'Pages'=>'/admin/admin_pages', 
			'Edit Page' => '',
		),
		'session' => $session,
	)
	);	
	
	if($page->get('pag_published_time')){
		$is_published = 1;
	}
	else{
		$is_published = 0;
	}

	$pageoptions['title'] = "Edit Page";
	$paget->begin_box($pageoptions);

	echo '<div class="row">
    <div class="col-md-8"><div class="p-3">';
	
	// Editing an existing email
	$formwriter = $page->getFormWriter('form1');
	
	$validation_rules = array();
	$validation_rules['pag_title']['required']['value'] = 'true';
	$validation_rules['pag_link']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	

	echo $formwriter->begin_form('form', 'POST', '/admin/admin_page_edit');

	if($page->key){
		echo $formwriter->hiddeninput('pag_page_id', $page->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Page title', 'pag_title', NULL, 100, $title, '', 255, '');		

	if(!$page->get('pag_link') || $_SESSION['permission'] == 10){
		echo $formwriter->textinput('Link (no spaces): ', 'pag_link', NULL, 100, $page->get('pag_link'), '', 255, '', TRUE, $settings->get_setting('webDir').'/page/', 'default');	
	}

	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Published", "pag_is_published", "ctrlHolder", $optionvals, $is_published, '', FALSE);

	echo $formwriter->textbox('Content', 'pag_body', 'ctrlHolder', 5, 80, $content, '', 'yes');	

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	echo '  </div>
    </div>
    <div class="col-md-4"><div class="p-3">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_PAGE, 'foreign_key_id' => $page->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array($session, FALSE);
	
	if(count($optionvals)){

		$formwriter = $page->getFormWriter('form_load_version');

		echo $formwriter->begin_form('form_load_version', 'GET', '/admin/admin_page_edit');
		echo $formwriter->hiddeninput('pag_page_id', $page->key);
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

	$paget->end_box();

	$paget->admin_footer();

?>
