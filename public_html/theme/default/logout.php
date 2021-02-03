<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/integralzen/includes/PublicPage.php');

	$session = SessionControl::get_instance();
	$session->logout();

	$page = new PublicPage();
	$page->public_header(array(
		'title' => 'Log Out',
		'disptitle'=>'Log Out',
		'crumbs'=>array('Home'=>'/', 'Log Out'=>''),				
		'showmap' => FALSE,
		'showheader' => TRUE,
		'sectionstyle' => 'neutral'),
	NULL);

	echo PublicPage::BeginPage();
	?>
	<h2>You are now logged out</h2>

	<p>You can visit the <a href="/">home page</a> or <a href="/login">log in again</a>.</p>

	<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));

?>
