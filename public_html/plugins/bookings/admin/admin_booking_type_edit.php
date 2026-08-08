<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/logic/admin_booking_type_edit_logic.php'));

$page_vars = process_logic(admin_booking_type_edit_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$title = $type->key ? 'Edit Booking Type' : 'New Booking Type';
$page->admin_header(array(
	'menu-id' => 'booking-types',
	'breadcrumbs' => array('Booking Types' => '/plugins/bookings/admin/admin_booking_types', $title => ''),
	'session' => $session,
));

$page->begin_box(array('title' => $title));

if (!empty($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// This page turns transactional email on (confirmations, reminders), so it
// surfaces the site-wide send verdict beside the switch — the standing rule
// from docs/email_system.md § Machine sender.
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
$booking_send_blocker = EmailSender::transactionalSendBlocker();
if ($booking_send_blocker !== null) {
	echo '<div class="alert alert-danger"><strong>Booking emails cannot send right now.</strong> '
		. htmlspecialchars($booking_send_blocker)
		. ' Confirmations and reminders will be refused until this is fixed — the mailbox Setup tab\'s '
		. 'Automated mail identity card walks the fix.</div>';
}

$formwriter = $page->getFormWriter('form1', array(
	'model' => $type,
	'edit_primary_key_value' => $type->key,
));
$formwriter->begin_form();

// Bulk fields from the shared descriptor.
$formwriter->fromDescriptor(admin_booking_type_edit_logic_descriptor());

// Host picker (hand-added — descriptor has no user-picker type).
$formwriter->dropinput('bkt_usr_user_id', 'Host', array(
	'options' => $host_options,
	'value' => $type->get('bkt_usr_user_id'),
	'helptext' => 'Availability comes from this user\'s schedule (/profile/bookings/availability).',
));

// Intake survey (hand-added select of surveys).
$formwriter->dropinput('bkt_svy_survey_id', 'Intake survey', array(
	'options' => $survey_options,
	'value' => $type->get('bkt_svy_survey_id'),
));

// Location — the detail box is shown only when a mode is chosen (visibility_rules,
// not a hand-rolled toggle).
$formwriter->dropinput('bkt_location_mode', 'Location type', array(
	'options' => array('none' => 'None', 'in_person' => 'In person', 'phone' => 'Phone', 'video' => 'Video', 'custom' => 'Custom'),
	'value' => $type->get('bkt_location_mode') ?: 'none',
	'visibility_rules' => array(
		'none' => array('hide' => array('bkt_location_details')),
		'default' => array('show' => array('bkt_location_details')),
	),
));
$formwriter->textbox('bkt_location_details', 'Location details', array(
	'value' => $type->get('bkt_location_details'),
	'placeholder' => 'Address, dial-in number, meeting link, or instructions',
));

$formwriter->submitbutton('btn_submit', 'Save booking type');
$formwriter->end_form();

$page->end_box();
$page->admin_footer();
