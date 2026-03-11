<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('booking_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = process_logic(booking_logic($_GET, $_POST));
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

	/*
	echo '<!-- Calendly inline widget begin -->
	<div class="calendly-inline-widget" data-url="'.htmlspecialchars($booking_type->get('bkt_schedule_link')).'?primary_color=69be00&name='.str_replace(' ', '%20', htmlspecialchars($client_user->display_name())).'&email='.htmlspecialchars($client_user->get('usr_email')).'&salesforce_uuid='.htmlspecialchars($booking_type->key).'" style="min-width:320px;height:630px;"></div>
	<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js" async></script>
	<!-- Calendly inline widget end -->';
	*/

	echo '<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">';
	echo '<div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">';
	echo '<p class="text-blue-700">Booking functionality is temporarily disabled while we review our calendar integration.</p>';
	echo '</div>';
	echo '</div>';

	echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
