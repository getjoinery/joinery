<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/surveys_class.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));

	$survey_id = LibraryFunctions::decode(LibraryFunctions::fetch_variable('survey_id', NULL, 0, 'Survey id is required'));

	$survey = new Survey($survey_id, TRUE);

	$page = new PublicPageTW(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Survey Finished'
	));
	echo PublicPageTW::BeginPage($survey->get('svy_name'));
	echo PublicPageTW::BeginPanel();

	echo '<h3>Survey Complete</h3>';
	echo '<p>You have successfully finished the survey "'.$survey->get('svy_name').'".</p>';
  
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

