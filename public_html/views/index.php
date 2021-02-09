<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');

	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('');

	echo '<h2>Welcome to your new site</h2>';

	echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
