<?php
/**
 * Host "my bookings" — the host's upcoming bookings with a host-side cancel.
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/bookings/logic/my_bookings_logic.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

$page_vars = process_logic(my_bookings_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);
$tz = $session->get_timezone();

$page = new PublicPage();
$page->public_header(array('is_valid_page' => true, 'title' => 'My Bookings',
	'breadcrumbs' => array('My Profile' => '/profile', 'My Bookings' => '')), NULL);
echo PublicPage::BeginPage('My Bookings', array());
?>
<div class="jy-ui">
<div class="bkn-wrap">
<?php if (!empty($canceled)): ?>
	<div class="bkn-saved">Booking canceled. The invitee has been notified.</div>
<?php endif; ?>
<?php if (!count($bookings)): ?>
	<p>You have no upcoming bookings.</p>
<?php else: ?>
	<?php foreach ($bookings as $b):
		$client = $b->get('bkn_usr_user_id_client') ? new User($b->get('bkn_usr_user_id_client'), TRUE) : new User(NULL);
		$bt = $b->get('bkn_bkt_booking_type_id') ? new BookingType($b->get('bkn_bkt_booking_type_id'), TRUE) : new BookingType(NULL);
		$when = LibraryFunctions::convert_time($b->get('bkn_start_time'), 'UTC', $tz, 'l, M j, Y g:i A T');
	?>
		<div class="bkn-card">
			<strong><?php echo htmlspecialchars($bt->get('bkt_name') ?: 'Booking'); ?></strong> · <?php echo htmlspecialchars($when); ?><br>
			<span class="bkn-with">with <?php echo htmlspecialchars($client->display_name()); ?></span>
			<form method="post" class="bkn-cancel-form">
				<input type="hidden" name="bkn_booking_id" value="<?php echo $b->key; ?>">
				<label class="bkn-reason-label">Reason (optional)<br><input type="text" name="cancel_reason"></label>
				<button type="submit" name="cancel_booking" value="1" class="bkn-cancel-btn">Cancel</button>
			</form>
		</div>
	<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
<?php
echo PublicPage::EndPage();
$page->public_footer(array('track' => TRUE));
?>
