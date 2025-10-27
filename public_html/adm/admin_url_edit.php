<?php
	
	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/urls_class.php'));

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
		if(substr($url_incoming, -1) == '/') {
			$url_incoming = substr($url_incoming, 0, -1);
		}
		$url->set('url_incoming', strtolower($url_incoming));

		$url_redirect_url = $_POST['url_redirect_url'];
		if($url_redirect_url[0] != '/' && !stristr($url_redirect_url, 'http')){
			$url_redirect_url = '/'.$url_redirect_url;
		}
		$url->set('url_redirect_url', strtolower($url_redirect_url));		

		$editable_fields = array('url_redirect_file', 'url_type');

		foreach($editable_fields as $field) {
			$url->set($field, $_POST[$field]);
		}

		$url->prepare();
		$url->save();
		$url->load();

		LibraryFunctions::redirect('/admin/admin_url?url_url_id='.$url->key);						
		return;

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
			'menu-id'=> 'urls',
			'page_title' => 'Urls',
			'readable_title' => 'Urls',
			'breadcrumbs' => $breadcrumbs,
			'session' => $session,
		)
		);			
		
		$options['title'] = 'Edit Url';
		$page->begin_box($options);

		$formwriter = $page->getFormWriter('form1', 'v2', [
			'values' => $url->export_as_array()
		]);

		$formwriter->begin_form();

		if($url->key){
			$formwriter->hiddeninput('url_url_id', ['value' => $url->key]);
			$formwriter->hiddeninput('action', ['value' => 'edit']);
		}

		$formwriter->textinput('url_incoming', 'Incoming url', [
			'validation' => ['required' => true]
		]);
		$formwriter->textinput('url_redirect_url', 'Redirect url');
		//echo $formwriter->textinput('Redirect to file', 'url_redirect_file', NULL, 100, $url->get('url_redirect_file'), '', 255, '');
		$optionvals = array("Permanent (301)"=>301, "Temporary (302)"=>302);
		$formwriter->dropinput("url_type", "Redirect type", [
			'options' => $optionvals
		]);

		$formwriter->submitbutton('btn_submit', 'Submit');
		$formwriter->end_form();

		$page->end_box();

		$page->admin_footer();
	}

?>
