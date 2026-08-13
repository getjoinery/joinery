<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/urls_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_url_logic.php'));

	$page_vars = process_logic(admin_url_logic(array_merge($_GET, $_POST)));

	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'urls',
		'page_title' => 'Urls',
		'readable_title' => 'Urls',
		'breadcrumbs' => array(
			'Urls'=>'/admin/admin_urls',
			'Url' => '',
		),
		'session' => $session,
	)
	);

	$options['title'] = 'Url';
	$options['altlinks'] = array('Edit'=>'/admin/admin_url_edit?url_url_id='.$url->key);
	if(!$url->get('url_delete_time')){
		$options['altlinks']['Soft Delete'] = array('post' => '/admin/admin_url', 'hidden' => array('action' => 'soft_delete', 'url_url_id' => $url->key));
	}
	else{
		$options['altlinks']['Undelete'] = array('post' => '/admin/admin_url', 'hidden' => array('action' => 'undelete', 'url_url_id' => $url->key));
	}

	if($_SESSION['permission'] >= 8) {
		$options['altlinks'] += array('Permanent Delete' => array('post' => '/admin/admin_url', 'hidden' => array('action' => 'permanent_delete', 'url_url_id' => $url->key)));
	}

	$page->begin_box($options);

	echo '<strong>Created:</strong> '.$url->get_local('url_create_time') .'<br />';

	echo '<br /><strong>Incoming:</strong> <a href="'.$url->get('url_incoming') .'">'.$url->get('url_incoming').'</a><br />';
	echo '<strong>Redirect:</strong> ';

		if($url->get('url_redirect_url')){
			echo '<a href="'.$url->get('url_redirect_url').'">'.$url->get('url_redirect_url').'</a>';
		}
		else{
			echo '<a href="'.$url->get('url_redirect_file').'">'.$url->get('url_redirect_file').'</a>';
		}
	echo '<br />';

	if($url->get('url_type') == 301){
		echo '<strong>Type:</strong> Permanent';
	}
	else if($url->get('url_type') == 302){
		echo '<strong>Type:</strong> Temporary';
	}
	echo '<br />';
	$page->end_box();

	$page->admin_footer();
?>

