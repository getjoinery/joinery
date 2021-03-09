<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/urls_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);
		
	if($_REQUEST['url_url_id']){
		$url = new Url($_REQUEST['url_url_id'], TRUE);
	}
	else{
		$url = new Url(NULL);
	}
		
	
	if($_POST){

		$url_incoming = trim($_POST['url_incoming']);
		if(stristr($url_incoming, 'http') || stristr($url_incoming, 'www.')){
		  $parsed_url = parse_url($url_incoming);
		  $url_incoming = $parsed_url['path'];
		}
		if($url_incoming[0] == '/'){
			$url_incoming = substr($url_incoming, 1);
		}
		$url->set('url_incoming', strtolower($url_incoming));

		$url_redirect_url = $_POST['url_redirect_url'];
		if($url_redirect_url[0] != '/'){
			$url_redirect_url = '/'.$url_redirect_url;
		}
		$url->set('url_redirect_url', strtolower($url_redirect_url));		

		$editable_fields = array('url_redirect_file', 'url_type');

		foreach($editable_fields as $field) {
			$url->set($field, $_REQUEST[$field]);
		}

		$url->prepare();
		$url->save();
		$url->load();

		LibraryFunctions::redirect('/admin/admin_url?url_url_id='.$url->key);						
		exit();

	}
	else{
		
		$breadcrumbs = array('Urls'=>'/admin/admin_urls');
		if ($url->key) {
			$breadcrumbs += array('Url '.$url->get('url_title') => '/admin/admin_url?url_url_id='.$url->key);
			$breadcrumbs += array('Url Edit'=>'');
		}
		else{
			$breadcrumbs += array('New Url' => '');
		}
				
		
		$page = new AdminPage();
		$page->admin_header(	
		array(
			'menu-id'=> 3,
			'page_title' => 'Urls',
			'readable_title' => 'Urls',
			'breadcrumbs' => $breadcrumbs,
			'session' => $session,
		)
		);			
		
		$options['title'] = 'Edit Url';
		$page->begin_box($options);

		$formwriter = new FormWriterMaster('form1');
		
		$validation_rules = array();
		$validation_rules['url_incoming']['required']['value'] = 'true'; 
		echo $formwriter->set_validate($validation_rules);				
			
		echo $formwriter->begin_form('form1', 'POST', '/admin/admin_url_edit');

		if($url->key){
			echo $formwriter->hiddeninput('url_url_id', $url->key);
			echo $formwriter->hiddeninput('action', 'edit');
		}
		
		echo $formwriter->textinput('Incoming url', 'url_incoming', NULL, 100, $url->get('url_incoming'), '', 255, '');
		echo $formwriter->textinput('Redirect url', 'url_redirect_url', NULL, 100, $url->get('url_redirect_url'), '', 255, '');
		//echo $formwriter->textinput('Redirect to file', 'url_redirect_file', NULL, 100, $url->get('url_redirect_file'), '', 255, '');
		$optionvals = array("Permanent (301)"=>301, "Temporary (302)"=>302);
		echo $formwriter->dropinput("Redirect type", "url_type", "ctrlHolder", $optionvals, $url->get('url_type'), '', FALSE);
			
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();
		echo $formwriter->end_form();

		$page->end_box();

		$page->admin_footer();
	}

?>
