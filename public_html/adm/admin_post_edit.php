<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/AdminPage.php');
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/posts_class.php');
	PathHelper::requireOnce('/data/groups_class.php');
	PathHelper::requireOnce('/data/content_versions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if (isset($_REQUEST['pst_post_id'])) {
		$post = new Post($_REQUEST['pst_post_id'], TRUE);
	} else {
		$post = new Post(NULL);
	}

	if($_POST){
		
		$editable_fields = array('pst_body', 'pst_title', 'pst_is_published', 'pst_short_description', 'pst_is_on_homepage','pst_is_pinned');

		foreach($editable_fields as $field) {
			$post->set($field, $_POST[$field]);
		}

		if(!$post->get('pst_link') || $_SESSION['permission'] == 10){
			if($_POST['pst_link']){
				$post->set('pst_link', $post->create_url($_POST['pst_link']));
			}
			else{
				$post->set('pst_link', $post->create_url($event->get('pst_title')));
			}
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

		if($_REQUEST['tags']){
			//PROCESS THE TAGS
			$tags_array = explode(',',$_REQUEST['tags']);
			$tags_array = array_filter($tags_array);
			foreach ($tags_array as $key=>$tag){
				$tags_array[$key] = preg_replace("/[^A-Za-z0-9 -_]/", '', trim($tag));
			}
			Group::AddMemberBulkByName($post->key, $tags_array, 'post_tag');
			
			//$post->save_tags($tags_array);
		}

		LibraryFunctions::redirect('/admin/admin_post?pst_post_id='. $post->key);
		return;		
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
		'menu-id'=> 'blog-posts',
		'breadcrumbs' => array(
			'Posts'=>'/admin/admin_posts', 
			'Edit Post' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "Edit Post";
	$page->begin_box($pageoptions);
	
	

	echo '<div class="row">
    <div class="col-md-8">
      <div class="p-3">';

	// Editing an existing email
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
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
		
		$post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');

		$tags = implode(', ', $post_tags);
		$pst_is_on_homepage = $post->get('pst_is_on_homepage');
	}
	else{
		$pst_is_on_homepage = 1;
	}
	
	echo $formwriter->textinput('Post title', 'pst_title', NULL, 100, $title, '', 255, '');	
	
	echo $formwriter->textinput('Short description (optional)', 'pst_short_description', NULL, 100, $post->get('pst_short_description'), '', 255, '');	
	
	echo $formwriter->textinput('Tags (optional, separate with comma)', 'tags', NULL, 100, $tags, '', 255, '');	
	
	if(!$post->get('pst_link') || $_SESSION['permission'] == 10){
		echo $formwriter->textinput('Link (only letters, numbers, and dashes) '.$settings->get_setting('webDir').'/blog/', 'pst_link', NULL, 100, $post->get('pst_link'), '', 255, '');	
	}	
	
	
	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Published", "pst_is_published", "ctrlHolder", $optionvals, $post->get('pst_is_published'), '', FALSE);

	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Pinned", "pst_is_pinned", "ctrlHolder", $optionvals, $post->get('pst_is_pinned'), '', FALSE);
	
	$optionvals = array("Yes"=>1, "No"=>0);
	echo $formwriter->dropinput("Is listed and searchable?", "pst_is_on_homepage", "ctrlHolder", $optionvals, $pst_is_on_homepage, '', FALSE);

	
	echo $formwriter->textbox('Post content', 'pst_body', 'ctrlHolder', 5, 80, $content, '', 'yes');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_POST, 'foreign_key_id' => $post->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array(FALSE, $session);

	if(count($optionvals)){
		$formwriter = LibraryFunctions::get_formwriter_object('form_load_version', 'admin');

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
