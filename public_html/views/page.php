<?php
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('page_logic.php'));

	$page_vars = page_logic($_GET, $_POST, $page, $params);
	$page = $page_vars['page'];

	$paget = new PublicPageTW(TRUE);
	$paget->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $page->get('pag_title')
	));
	echo PublicPageTW::BeginPage($page->get('pag_title'));
	echo PublicPageTW::BeginPanel();
	
	echo '<div class="text-lg prose max-w-prose mx-auto">';
	echo '<div>'. $page->get_filled_content() . '</div>';
	echo '</div>';
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$paget->public_footer($foptions=array('track'=>TRUE));
?>

