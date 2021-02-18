<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/content_versions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if (isset($_REQUEST['pst_post_id'])) {
		$post = new Post($_REQUEST['pst_post_id'], TRUE);
	} else {
		$post = new Post(NULL);
	}

	if($_POST){
		
		$editable_fields = array('pst_body', 'pst_title', 'pst_is_published', 'pst_link', 'pst_short_description');

		foreach($editable_fields as $field) {
			$post->set($field, $_REQUEST[$field]);
		}
		
		if($_REQUEST['pst_is_published']){
			if(!$post->get('pst_published_time')){
				$post->set('pst_published_time', 'NOW()');
			}
		}	
		else {
			$post->set('pst_published_time', NULL);
		}
		
		if(!$post->key){
			$post->set('pst_usr_user_id',$session->get_user_id());
		}	
				
		
		$post->prepare();
		$post->save();
		$post->load();

		$tags_array = explode(',',$_REQUEST['tags']);
		$post->save_tags($tags_array);


		LibraryFunctions::redirect('/admin/admin_post?pst_post_id='. $post->key);
		exit;		
	}

	$title = $post->get('pst_title');
	$content = $post->get('pst_body');
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($_GET['cnv_content_version_id']){
		$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 26,
		'breadcrumbs' => array(
			'Posts'=>'/admin/admin_posts', 
			'Edit Post' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "Edit Post";
	$page->begin_box($pageoptions);
	
	

	echo '<div uk-grid>
    <div class="uk-width-2-3@m"><div style="padding: 20px">';

	// Editing an existing email
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['pst_description']['required']['value'] = 'true';
	$validation_rules['pst_description']['minlength']['value'] = 10;
	$validation_rules['pst_subject']['required']['value'] = 'true';
	$validation_rules['pst_subject']['minlength']['value'] = 10;
	if($_SESSION['permission'] == 10){
		$validation_rules['pst_link']['required']['value'] = 'true';
	}	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_post_edit');

	$tags = '';
	if($post->key){
		echo $formwriter->hiddeninput('pst_post_id', $post->key);
		echo $formwriter->hiddeninput('action', 'edit');
		
		$post_tags = $post->get_tags();
		$tags = implode(', ', $post_tags);
	}
	
	echo $formwriter->textinput('Post title', 'pst_title', NULL, 100, $title, '', 255, '');	
	
	echo $formwriter->textinput('Short description (optional)', 'pst_short_description', NULL, 100, $post->get('pst_short_description'), '', 255, '');	
	
	echo $formwriter->textinput('Tags (optional, separate with comma)', 'tags', NULL, 100, $tags, '', 255, '');	
	
	if($_SESSION['permission'] == 10){
		echo $formwriter->textinput('Link (if standalone page, no spaces)', 'pst_link', NULL, 100, $post->get('pst_link'), '', 255, '');	
	}	
	
	
	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Published", "pst_is_published", "ctrlHolder", $optionvals, $post->get('pst_is_published'), '', FALSE);
	

	echo $formwriter->textbox('Post content', 'pst_body', 'ctrlHolder', 5, 80, $content, '', 'yes');


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	echo '	</div>
	</div>
	<div class="uk-width-1-3@m"><div style="padding: 20px">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_POST, 'foreign_key_id' => $post->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array(FALSE, $session);

	if(count($optionvals)){
		$formwriter = new FormWriterMaster('form_load_version');
		echo $formwriter->begin_form('form_load_version', 'GET', '/admin/admin_post_edit');
		echo $formwriter->hiddeninput('pst_post_id', $post->key);
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
