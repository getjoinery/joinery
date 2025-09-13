<?php
	// SessionControl is now guaranteed available - line removed
	// LibraryFunctions is now guaranteed available - line removed
	// PathHelper is now guaranteed available - line removed
PathHelper::requireOnce('includes/ThemeHelper.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$session = SessionControl::get_instance();
	$session->logout();

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Log Out'
		),
	NULL);

	echo PublicPage::BeginPage('You are now logged out');
		
	echo PublicPage::BeginPanel();
	?>

	<p>You can visit the <a href="/">home page</a> or <a href="/login">log in again</a>.</p>

	<?php
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));

?>
