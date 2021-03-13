<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Page not found',
		'is_404' => 1,
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Page not found');
	echo '<div class="section padding-top-20">
			<div class="container">';
	?>

	<h2>This page may have moved or is no longer available</h2>

	<?php
	echo '</div></div>';
	echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE, 'is_404'=> 1));
?>