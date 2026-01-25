<?php
	
	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/page_contents_class.php'));
	require_once(PathHelper::getIncludePath('/data/content_versions_class.php'));
	require_once(PathHelper::getIncludePath('/data/pages_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance(); 

	if (isset($_REQUEST['pac_page_content_id'])) {
		$page_content = new PageContent($_REQUEST['pac_page_content_id'], TRUE);
	}
	else {
		$page_content = new PageContent(NULL);
	}

	// Only process form submission if this is a POST request
	// GET requests (from version loading) should skip form processing
	if($_POST && !isset($_GET['cnv_content_version_id'])){
		
		$page_content->set('pac_body', $_POST['pac_body']);

		$editable_fields = array('pac_title', 'pac_is_published', 'pac_location_name', 'pac_pag_page_id');

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
	
	// Prepare override values for form
	$override_values = [
		'pac_body' => $content,
		'pac_link' => $page_link
	];

	$formwriter = $page->getFormWriter('form1', [
		'model' => $page_content,
		'values' => $override_values,
		'edit_primary_key_value' => $page_content->key
	]);
	// Note: $page here is the AdminPage object, $page_content is the PageContent model

	$formwriter->begin_form();

	// Include the primary key as hidden field when editing existing record
	if ($page_content->key) {
		$formwriter->hiddeninput('pac_page_content_id', ['value' => $page_content->key]);
	}

	$formwriter->textinput('pac_location_name', 'Name for this content', [
		'validation' => ['required' => true]
	]);

	$pages = new MultiPage();
	$pages->load();
	$optionvals = $pages->get_dropdown_array();

	$formwriter->dropinput('pac_pag_page_id', 'Page', [
		'options' => $optionvals,
		'validation' => ['required' => true]
	]);

	if(!$page_content->get('pac_link') || $_SESSION['permission'] == 10){
		$formwriter->textinput('pac_link', 'Content slug (no spaces)', [
			'validation' => ['required' => true]
		]);
	}

	$formwriter->dropinput('pac_is_published', 'Published', [
		'options' => [0 => 'No', 1 => 'Yes']
	]);

	$formwriter->textbox('pac_body', 'Content', [
		'validation' => ['required' => true, 'minlength' => 10],
		'htmlmode' => 'yes'
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

	echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

	// Show version history if this is an existing page_content (has a key)
	if($page_content->key){
		$content_versions = new MultiContentVersion(
			array('type'=>ContentVersion::TYPE_PAGE_CONTENT, 'foreign_key_id' => $page_content->key),
			array('create_time' => 'DESC'),		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET
		$content_versions->load();

		$optionvals = $content_versions->get_dropdown_array($session, FALSE);

		if(count($optionvals) > 0){

			$formwriter = $page->getFormWriter('form_load_version', [
				'action' => '/admin/admin_page_content_edit',
				'method' => 'GET'
			]);

			$formwriter->begin_form();
			$formwriter->hiddeninput('pac_page_content_id', ['value' => $page_content->key]);
			$formwriter->dropinput('cnv_content_version_id', 'Load another version', [
				'options' => $optionvals
			]);
			$formwriter->submitbutton('btn_load', 'Load');
			$formwriter->end_form();
		}
		else{
			echo 'No saved versions.';
		}
	}

	echo '	</div>
	</div>
</div>	';

	$page->end_box();

	$page->admin_footer();

?>
