<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);
$session->set_return();

$status_labels = array(
	Booking::BOOKING_STATUS_CREATED => 'Hold',
	Booking::BOOKING_STATUS_BOOKED => 'Booked',
	Booking::BOOKING_STATUS_COMPLETED => 'Completed',
	Booking::BOOKING_STATUS_CANCELED => 'Canceled',
	Booking::BOOKING_STATUS_NEEDS_ATTENTION => 'Needs attention',
);

$numperpage = 30;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$status_filter = LibraryFunctions::fetch_variable('status', '', 0, '');

$criteria = array('deleted' => false);
if ($status_filter !== '' && is_numeric($status_filter)) {
	$criteria['status'] = (int)$status_filter;
}

$bookings = new MultiBooking($criteria, array('start_time' => 'DESC'), $numperpage, $offset);
$numrecords = $bookings->count_all();
$bookings->load();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'bookings',
	'breadcrumbs' => array('Bookings' => ''),
	'session' => $session,
));

// Status filter (self-documenting control, no explainer prose).
echo '<form method="get" class="bkn-filter-form"><label>Status: <select name="status" onchange="this.form.submit()">';
echo '<option value="">All</option>';
foreach ($status_labels as $val => $label) {
	$sel = ((string)$status_filter === (string)$val) ? ' selected' : '';
	echo '<option value="' . $val . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
}
echo '</select></label></form>';

$headers = array('Invitee', 'Type', 'Host', 'When', 'Status');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$page->tableheader($headers, array('title' => 'Bookings'), $pager);

foreach ($bookings as $booking) {
	$client = $booking->get('bkn_usr_user_id_client') ? new User($booking->get('bkn_usr_user_id_client'), TRUE) : new User(NULL);
	$host = $booking->get('bkn_usr_user_id_booked') ? new User($booking->get('bkn_usr_user_id_booked'), TRUE) : new User(NULL);
	$type_name = '—';
	if ($booking->get('bkn_bkt_booking_type_id')) {
		$bt = new BookingType($booking->get('bkn_bkt_booking_type_id'), TRUE);
		if ($bt->key) { $type_name = htmlspecialchars($bt->get('bkt_name')); }
	}
	$status = $status_labels[(int)$booking->get('bkn_status')] ?? 'Unknown';
	if ($booking->get('bkn_is_no_show')) { $status .= ' · No-show'; }

	$rowvalues = array();
	$rowvalues[] = '<a href="/plugins/bookings/admin/admin_booking?bkn_booking_id=' . $booking->key . '">' . htmlspecialchars($client->display_name()) . '</a>';
	$rowvalues[] = $type_name;
	$rowvalues[] = htmlspecialchars($host->display_name());
	$rowvalues[] = $booking->get_local('bkn_start_time', 'M j, Y g:i A T');
	$rowvalues[] = $status;
	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
