<?php
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	// PathHelper is already loaded
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage');

	$session = SessionControl::get_instance();
	$session->logout();

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Log Out'
		),
	NULL);

	echo PublicPage::BeginPage('You are now logged out');
		
	//echo PublicPage::BeginPanel();
	?>

	<p>You can visit the <a href="/">home page</a> or <a href="/login">log in again</a>.</p>

	<?php
	//echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));

?>
