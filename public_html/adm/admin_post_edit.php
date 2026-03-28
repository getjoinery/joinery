<?php
	
	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/posts_class.php'));
	require_once(PathHelper::getIncludePath('/data/groups_class.php'));
	require_once(PathHelper::getIncludePath('/data/content_versions_class.php'));
	require_once(PathHelper::getIncludePath('/data/files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	// CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
	if (isset($_POST['edit_primary_key_value'])) {
		$post = new Post($_POST['edit_primary_key_value'], TRUE);
	} elseif (isset($_GET['pst_post_id'])) {
		$post = new Post($_GET['pst_post_id'], TRUE);
	} else {
		$post = new Post(NULL);
	}

	if($_POST){
		
		$editable_fields = array('pst_body', 'pst_title', 'pst_is_published', 'pst_short_description', 'pst_is_on_homepage','pst_is_pinned');

		foreach($editable_fields as $field) {
			$post->set($field, $_POST[$field]);
		}

		if($_POST['pst_fil_file_id']){
			$post->set('pst_fil_file_id', (int)$_POST['pst_fil_file_id']);
		}
		else if(empty($_POST['pst_fil_file_id'])){
			$post->set('pst_fil_file_id', NULL);
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

	// Editing an existing post
	// Prepare override values for form
	$override_values = [
		'pst_title' => $title,
		'pst_body' => $content
	];

	if($post->key){
		$post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
		$tags = implode(', ', $post_tags);
		$override_values['tags'] = $tags;
		$pst_is_on_homepage = $post->get('pst_is_on_homepage');
	}
	else{
		$pst_is_on_homepage = 1;
		$override_values['pst_is_on_homepage'] = $pst_is_on_homepage;
	}

	$files = new MultiFile(
		array('deleted'=>false, 'picture'=>true),
		array('file_id' => 'DESC'),
		NULL, NULL);
	$files->load();

	$formwriter = $page->getFormWriter('form1', [
		'model' => $post,
		'values' => $override_values,
		'edit_primary_key_value' => $post->key
	]);

	$formwriter->begin_form();

	$formwriter->textinput('pst_title', 'Post title', [
		'validation' => ['required' => true, 'minlength' => 10]
	]);

	$optionvals = $files->get_image_dropdown_array();
	$formwriter->imageinput('pst_fil_file_id', 'Main image', [
		'options' => $optionvals
	]);

	$formwriter->textinput('pst_short_description', 'Short description (optional)');

	$formwriter->textinput('tags', 'Tags (optional, separate with comma)');

	if(!$post->get('pst_link') || $_SESSION['permission'] == 10){
		$formwriter->textinput('pst_link', 'Link (only letters, numbers, and dashes)', [
			'prepend' => $settings->get_setting('webDir').'/blog/'
		]);
	}

	$formwriter->dropinput("pst_is_published", "Published", [
		'options' => [0=>"No", 1=>"Yes"]
	]);

	$formwriter->dropinput("pst_is_pinned", "Pinned", [
		'options' => [0=>"No", 1=>"Yes"]
	]);

	$formwriter->dropinput("pst_is_on_homepage", "Is listed and searchable?", [
		'options' => [1=>"Yes", 0=>"No"]
	]);

	$formwriter->textbox('pst_body', 'Post content', [
		'validation' => ['required' => true, 'minlength' => 10],
		'htmlmode' => 'yes'
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

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
	
	$optionvals = $content_versions->get_dropdown_array($session, FALSE);

	if(count($optionvals)){
		$formwriter = $page->getFormWriter('form_load_version', [
			'action' => '/admin/admin_post_edit'
		]);

		$formwriter->begin_form();
		$formwriter->hiddeninput('pst_post_id', '', ['value' => $post->key]);
		$formwriter->dropinput("cnv_content_version_id", "Load another version", [
			'options' => $optionvals
		]);
		$formwriter->submitbutton('btn_load', 'Load');
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
