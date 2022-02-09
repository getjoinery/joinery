<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPageTW.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');

	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Page not found', 
		'is_404' => 1,
	);
	$page->public_header($hoptions);
	echo PublicPageTW::BeginPage('Page not found');
	echo PublicPageTW::BeginPanel();
	?>

	<h2>This page may have moved or is no longer available</h2>

	<?php
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();

	$page->public_footer(array('track'=>TRUE, 'is_404'=> 1));
?>