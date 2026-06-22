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
<div style="max-width: 820px; margin: 1.5rem auto;">
<?php if (!empty($canceled)): ?>
	<div style="background:#e6f7ec;border:1px solid #2d7d46;padding:.6rem .9rem;border-radius:4px;margin-bottom:1rem;">Booking canceled. The invitee has been notified.</div>
<?php endif; ?>
<?php if (!count($bookings)): ?>
	<p>You have no upcoming bookings.</p>
<?php else: ?>
	<?php foreach ($bookings as $b):
		$client = $b->get('bkn_usr_user_id_client') ? new User($b->get('bkn_usr_user_id_client'), TRUE) : new User(NULL);
		$bt = $b->get('bkn_bkt_booking_type_id') ? new BookingType($b->get('bkn_bkt_booking_type_id'), TRUE) : new BookingType(NULL);
		$when = LibraryFunctions::convert_time($b->get('bkn_start_time'), 'UTC', $tz, 'l, M j, Y g:i A T');
	?>
		<div style="border:1px solid #e2e2e2;border-radius:6px;padding:1rem;margin-bottom:1rem;">
			<strong><?php echo htmlspecialchars($bt->get('bkt_name') ?: 'Booking'); ?></strong> · <?php echo htmlspecialchars($when); ?><br>
			<span style="color:#555;">with <?php echo htmlspecialchars($client->display_name()); ?></span>
			<form method="post" style="margin-top:.6rem;display:flex;gap:.4rem;align-items:flex-end;">
				<input type="hidden" name="bkn_booking_id" value="<?php echo $b->key; ?>">
				<label style="font-size:.85rem;">Reason (optional)<br><input type="text" name="cancel_reason"></label>
				<button type="submit" name="cancel_booking" value="1" style="background:#c0392b;color:#fff;border:none;border-radius:4px;padding:.45rem .8rem;cursor:pointer;">Cancel</button>
			</form>
		</div>
	<?php endforeach; ?>
<?php endif; ?>
</div>
<?php
echo PublicPage::EndPage();
$page->public_footer(array('track' => TRUE));
?>
