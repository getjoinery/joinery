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

	// Only process form submission if this is a POST request
	// GET requests (from version loading) should skip form processing
	if($_POST && !isset($_GET['cnv_content_version_id'])){

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
	
	// Prepare override values for form
	$override_values = [
		'pag_title' => $title,
		'pag_body' => $content,
		'pag_is_published' => $is_published
	];

	$formwriter = $paget->getFormWriter('form1', [
		'model' => $page,
		'values' => $override_values,
		'edit_primary_key_value' => $page->key
	]);

	$formwriter->begin_form();

	$formwriter->textinput('pag_title', 'Page title', [
		'validation' => ['required' => true]
	]);

	if(!$page->get('pag_link') || $_SESSION['permission'] == 10){
		$formwriter->textinput('pag_link', 'Link (no spaces)', [
			'prepend' => $settings->get_setting('webDir').'/page/',
			'validation' => ['required' => true]
		]);
	}

	$formwriter->dropinput('pag_is_published', 'Published', [
		'options' => ['No' => 0, 'Yes' => 1]
	]);

	$formwriter->textbox('pag_body', 'Content', [
		'validation' => ['required' => true, 'minlength' => 10],
		'htmlmode' => 'yes'
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

	echo '  </div>
    </div>
    <div class="col-md-4"><div class="p-3">';

	// Only show version history if this is an existing page (has a key)
	if($page->key){
		$content_versions = new MultiContentVersion(
			array('type'=>ContentVersion::TYPE_PAGE, 'foreign_key_id' => $page->key),
			array('create_time' => 'DESC'),		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET
		$content_versions->load();

		$optionvals = $content_versions->get_dropdown_array($session, FALSE);

		if(count($optionvals)){

			$formwriter = $paget->getFormWriter('form_load_version', [
				'action' => '/admin/admin_page_edit',
				'method' => 'GET'
			]);

			$formwriter->begin_form();
			$formwriter->hiddeninput('pag_page_id', ['value' => $page->key]);
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

	$paget->end_box();

	$paget->admin_footer();

?>
