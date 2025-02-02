<?php
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('location_logic.php'));

	$page_vars = location_logic($_GET, $_POST, $location, $params);
	$location = $page_vars['location'];

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $location->get('pag_title')
	));
	echo PublicPage::BeginPage($location->get('loc_name'));
	echo PublicPage::BeginPanel();
	
	echo '<div class="text-lg prose max-w-prose mx-auto">';
	echo '<div>'. $location->get('loc_description') . '</div>';
	echo '</div>';
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

