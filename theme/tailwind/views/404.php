<?php
	// LibraryFunctions is now guaranteed available - line removed
	// PathHelper is now guaranteed available - line removed
PathHelper::requireOnce('includes/ThemeHelper.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Page not found', 
		'is_404' => 1,
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Page not found');
	echo PublicPage::BeginPanel();
	?>

	<h2>This page may have moved or is no longer available</h2>

	<?php
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE, 'is_404'=> 1));
?>