<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/pages_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'page_content_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	
	
	$search_criteria = array();

	$page_contents = new MultiPageContent(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);		
	$page_contents->load();

	

	foreach ($page_contents as $page_content){
		
		if($page_content->get('pac_link')){
			$page = new Page(NULL);

			$page->set('pag_title', $page_content->get('pac_location_name'));
			$page->set('pag_link', $page_content->get('pac_link'));
			$page->set('pag_body', $page_content->get('pac_body'));
			$page->set('pag_delete_time', $page_content->get('pac_delete_time'));
			$page->set('pag_published_time', $page_content->get('pac_published_time'));
			$page->set('pag_usr_user_id',$page_content->get('pac_usr_user_id'));
			

			$page->prepare();
			try{
				$page->save();
				echo 'Adding '. $page_content->get('pac_location_name'). '<br>';
				$page->load();
				
				$page_content->set('pac_pag_page_id', $page->key);
				$page_content->save();
			} 
			catch (Exception $e){
				echo 'Skipping '. $page_content->get('pac_location_name'). '<br>';
			}		
		}
		else{
			echo 'Skipping '. $page_content->get('pac_location_name'). '. No link.<br>';
		}
		
		
	}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare("UPDATE amu_admin_menus SET amu_defaultpage='admin_pages', amu_menudisplay='Pages' WHERE amu_defaultpage='admin_page_contents'");
	
		
		$success = $q->execute();


?>


