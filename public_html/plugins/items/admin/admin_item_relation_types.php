<?php
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('plugins/items/data/item_relation_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(7);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'items-list',
		'page_title' => 'Item Relation Types',
		'readable_title' => 'Item Relation Types',
		'breadcrumbs' => array(
			'Items'=>'/plugins/items/admin/admin_items',
			'Item Relation Types' => '',
		),
		'session' => $session,
	)
	);

	$relation_types = new MultiItemRelationType();
	$relation_types->load();

	$headers = array('Relation Type Name');
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => 'Item Relation Types'
	);
	$page->tableheader($headers, $box_vars);

	foreach($relation_types as $relation_type) {
		$rowvalues=array();
		array_push($rowvalues, $relation_type->get('itt_name'));
		$page->disprow($rowvalues);
	}

	$page->endtable();

	$page->admin_footer();

?>
