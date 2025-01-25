<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_requirements_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'product-requirements',
		'page_title' => 'Product Requirements',
		'readable_title' => 'Product Requirements',
		'breadcrumbs' => array(
			'Products'=>'/admin/products', 
			'Product Requirements' => ''
		),
		'session' => $session,
	)
	);
	
		$numperpage = 30;
		$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
		$sort = LibraryFunctions::fetch_variable('sort', 'product_requirement_id', 0, '');
		$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
		$search_criteria = array();

		$product_requirements = new MultiProductRequirement(
			$search_criteria,
			array($sort=>$sdirection),
			$numperpage,
			$offset,
			'AND'
		);
		$numrecords = $product_requirements->count_all();
		$product_requirements->load();	
			
		$headers = array('Product Requirement', 'Event', 'Active');
		if($_SESSION['permission'] >= 8){
			$altlinks = array('New Product Requirement'=>'/admin/admin_product_requirement_edit');
		}
		else{
			$altlinks = array();
		}
		$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
		$table_options = array(
			//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
			'altlinks' => $altlinks,
			'title' => 'Product Requirements',
			//'search_on' => TRUE
		);
		$page->tableheader($headers, $table_options, $pager);

		foreach($product_requirements as $product_requirement) {
			
			

			$editlink = $product_requirement->get('prq_title').'<a href="/admin/admin_product_requirement_edit?prq_product_requirement_id=' . $product_requirement->key . '">[edit]</a>';

			
			$page->disprow(array(
				$editlink ,

			));

		}

		$page->endtable($pager);		
		


	$page->admin_footer();

?>
