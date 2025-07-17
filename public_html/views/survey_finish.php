<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	PathHelper::requireOnce('/data/surveys_class.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));

	$survey_id = LibraryFunctions::decode(LibraryFunctions::fetch_variable('survey_id', NULL, 0, 'Survey id is required'));

	$survey = new Survey($survey_id, TRUE);

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Survey Finished'
	));
	echo PublicPage::BeginPage($survey->get('svy_name'));
	echo PublicPage::BeginPanel();

	echo '<h3>Survey Complete</h3>';
	echo '<p>You have successfully finished the survey "'.$survey->get('svy_name').'".</p>';
  
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

