<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);
$session->set_return();

$numperpage = 30;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$sort = LibraryFunctions::fetch_variable('sort', 'booking_type_id', 0, '');
$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

$types = new MultiBookingType(array('deleted' => false), array($sort => $sdirection), $numperpage, $offset);
$numrecords = $types->count_all();
$types->load();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'booking-types',
	'breadcrumbs' => array('Booking Types' => ''),
	'session' => $session,
));

echo '<p><a class="btn btn-primary" href="/plugins/bookings/admin/admin_booking_type_edit">+ New booking type</a></p>';

$headers = array('Name', 'Host', 'Slug', 'Status');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$page->tableheader($headers, array('title' => 'Booking Types'), $pager);

foreach ($types as $type) {
	$host = '—';
	if ($type->get('bkt_usr_user_id')) {
		$h = new User($type->get('bkt_usr_user_id'), TRUE);
		if ($h->key) { $host = htmlspecialchars($h->display_name()); }
	}
	$status = $type->is_active() ? 'Active' : 'Inactive';
	$slug = $type->get('bkt_slug') ? '/book/' . htmlspecialchars($type->get('bkt_slug')) : '—';

	$rowvalues = array();
	$rowvalues[] = '<a href="/plugins/bookings/admin/admin_booking_type_edit?bkt_booking_type_id=' . $type->key . '">' . htmlspecialchars($type->get('bkt_name')) . '</a>';
	$rowvalues[] = $host;
	$rowvalues[] = $slug;
	$rowvalues[] = $status;
	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
