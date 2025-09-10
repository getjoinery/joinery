<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	ThemeHelper::includeThemeFile('logic/booking_logic.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage.php');
	
	$page_vars = booking_logic($_GET, $_POST);
	$booking_type = $page_vars['booking_type'];
	$client_user = $page_vars['client_user'];

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Book an appointment',
		'description' => 'Book an appointment',
		'banner' => 'Book',
		'submenu' => 'Book',
	);
	$page->public_header($hoptions);
	

	
	echo PublicPage::BeginPage('Book an appointment');
	echo PublicPage::BeginPanel();

					
	echo '<!-- Calendly inline widget begin -->
	<div class="calendly-inline-widget" data-url="'.$booking_type->get('bkt_schedule_link').'?primary_color=69be00&name='.str_replace(' ', '%20', $client_user->display_name()).'&email='.$client_user->get('usr_email').'&salesforce_uuid='.$booking_type->key.'" style="min-width:320px;height:630px;"></div>
	<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js" async></script>
	<!-- Calendly inline widget end -->';

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();	


	$page->public_footer(array('track'=>TRUE));
?>