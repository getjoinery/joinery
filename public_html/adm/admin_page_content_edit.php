<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/AdminPage.php');
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/page_contents_class.php');
	PathHelper::requireOnce('/data/content_versions_class.php');
	PathHelper::requireOnce('/data/pages_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance(); 

	if (isset($_REQUEST['pac_page_content_id'])) {
		$page_content = new PageContent($_REQUEST['pac_page_content_id'], TRUE);
	} 
	else {
		$page_content = new PageContent(NULL);
	}

	
	if($_POST){
		
		$page_content->set('pac_body', $_POST['pac_body']);
		
		
		$editable_fields = array('pac_title', 'pac_is_published', 'pac_location_name');

		foreach($editable_fields as $field) {
			$page_content->set($field, $_POST[$field]);
		}
		
		if(!$page_content->get('pac_link') || $_SESSION['permission'] == 10){
			if($_POST['pac_link']){
				$page_content->set('pac_link', $page_content->create_url($_POST['pac_link']));
			}
			else{
				$page_content->set('pac_link', $page_content->create_url($event->get('pac_title')));
			}
		}
		
		if($_POST['pac_is_published']){
			if(!$page_content->get('pac_published_time')){
				$page_content->set('pac_published_time', 'NOW()');
			}
		}	
		else {
			$page_content->set('pac_published_time', NULL);
		}
		
		if(!$page_content->key){
			$page_content->set('pac_usr_user_id',$session->get_user_id());
		}	
				

		$page_content->prepare();
		$page_content->save();
		$page_content->load();
		
		LibraryFunctions::redirect('/admin/admin_page_content?pac_page_content_id='. $page_content->key);
		return;
	}

	$title = $page_content->get('pac_title');
	$content = $page_content->get('pac_body');
	$page_link = $page_content->get('pac_link');
	if(!$page_link){
		if($_GET['pac_link']){
			$page_link = $_GET['pac_link'];
		}
	}
	
	
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($_GET['cnv_content_version_id']){
		$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'pages',
		'breadcrumbs' => array(
			'Page Contents'=>'/admin/admin_page_contents', 
			'Edit Page Content' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "Edit Page Content";
	$page->begin_box($pageoptions);


	echo '<div class="row">
    <div class="col-md-8">
      <div class="p-3">';
	
	// Editing an existing email
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['pac_body']['required']['value'] = 'true';
	$validation_rules['pac_link']['required']['value'] = 'true';
	$validation_rules['pac_location_name']['required']['value'] = 'true';
	$validation_rules['pac_body']['minlength']['value'] = 10;
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_page_content_edit');

	if($page_content->key){
		echo $formwriter->hiddeninput('pac_page_content_id', $page_content->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Name for this content', 'pac_location_name', NULL, 100, $page_content->get('pac_location_name'), '', 255, '');	
	//echo $formwriter->textinput('Page title (optional)', 'pac_title', NULL, 100, $page_content->get('pac_title'), '', 255, '');		


	$pages = new MultiPage(
		);
	$pages->load();
	$optionvals = $pages->get_dropdown_array();

	echo $formwriter->dropinput('Page', 'pac_pag_page_id', 'ctrlHolder', $optionvals, $page_content->get('pac_pag_page_id'), '', TRUE);

	if(!$page_content->get('pac_link') || $_SESSION['permission'] == 10){
		echo $formwriter->textinput('Content slug (no spaces):', 'pac_link', NULL, 100, $page_link, '', 255, '');	
	}
	
	//echo $formwriter->textinput('Script file (optional)', 'pac_script_filename', NULL, 100, $page_content->get('pac_script_filename'), '', 255, '');
	


	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Published", "pac_is_published", "ctrlHolder", $optionvals, $page_content->get('pac_is_published'), '', FALSE);
	
	
	echo $formwriter->textbox('Content', 'pac_body', 'ctrlHolder', 5, 80, $content, '', 'yes');


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();


	echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_PAGE_CONTENT, 'foreign_key_id' => $page_content->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array($session, FALSE);
	
	if(count($optionvals)){

		$formwriter = LibraryFunctions::get_formwriter_object('form_load_version', 'admin');

		echo $formwriter->begin_form('form_load_version', 'GET', '/admin/admin_page_content_edit');
		echo $formwriter->hiddeninput('pac_page_content_id', $page_content->key);
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
