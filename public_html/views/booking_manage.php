<?php
/**
 * Invitee booking management (/booking/manage?token=...). Cancel or reschedule,
 * token-gated, no login. Renders the slot_picker for reschedule.
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/bookings/logic/booking_manage_logic.php'));

$page_vars = process_logic(booking_manage_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$valid = (!isset($is_valid_page) || $is_valid_page) && !empty($booking);
$page = new PublicPage();
$page->public_header(array('is_valid_page' => $valid, 'title' => 'Manage booking'), NULL);
echo PublicPage::BeginPage('Manage booking', array());

$tz = (!empty($booking) && $booking->get('bkn_invitee_timezone')) ? $booking->get('bkn_invitee_timezone') : SessionControl::get_instance()->get_timezone();
?>
<div class="jy-ui jy-bookmgr-wrap">
<?php if (!$valid): ?>
	<h1>Booking not found</h1>
	<p>This management link isn't valid. It may have already been used or the link may be incomplete.</p>
<?php else:
	$when = LibraryFunctions::convert_time($booking->get('bkn_start_time'), 'UTC', $tz, 'l, M j, Y g:i A T');
?>
	<h1><?php echo htmlspecialchars($type->get('bkt_name')); ?></h1>
	<p class="jy-bookmgr-meta"><?php echo htmlspecialchars($when); ?> · with <?php echo htmlspecialchars($host->display_name()); ?></p>

	<?php if (!empty($canceled) || (int)$booking->get('bkn_status') === Booking::BOOKING_STATUS_CANCELED): ?>
		<div class="jy-bookmgr-notice">This booking is canceled.
			<?php if ($type->get('bkt_slug')): ?> <a href="/book/<?php echo htmlspecialchars($type->get('bkt_slug')); ?>">Book a new time</a>.<?php endif; ?>
		</div>
	<?php elseif (!empty($rescheduled)): ?>
		<div class="jy-bookmgr-notice-ok">Your booking was rescheduled to <?php echo htmlspecialchars($when); ?>.</div>
	<?php else: ?>
		<?php if (!empty($errors)): ?>
			<div class="jy-bookmgr-notice is-spaced">
				<?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?>
			</div>
		<?php endif; ?>
		<?php if ($type->get('bkt_cancellation_policy_text')): ?>
			<p class="jy-bookmgr-policy"><?php echo nl2br(htmlspecialchars($type->get('bkt_cancellation_policy_text'))); ?></p>
		<?php endif; ?>

		<?php if (!empty($within_notice)): ?>
			<p class="jy-bookmgr-warn">This booking is too close to its start time to change online. Please contact the host directly.</p>
		<?php else: ?>
			<h2>Reschedule</h2>
			<?php
			$rf = $page->getFormWriter('rescheduleform', ['action' => '/booking/manage?token=' . urlencode($booking->get('bkn_action_token'))]);
			$rf->begin_form();
			$rf->hiddeninput('reschedule_booking', '', ['value' => '1']);
			$rf->hiddeninput('token', '', ['value' => $booking->get('bkn_action_token')]);
			echo ComponentRenderer::render(null, 'slot_picker', [
				'slots_url' => '/ajax/booking_slots?slug=' . rawurlencode($type->get('bkt_slug')),
				'field_name' => 'slot_start',
			]);
			$rf->submitbutton('btn_resched', 'Reschedule to selected time');
			$rf->end_form();
			?>

			<h2 class="jy-bookmgr-h2">Cancel</h2>
			<?php
			$cf = $page->getFormWriter('cancelform', ['action' => '/booking/manage?token=' . urlencode($booking->get('bkn_action_token'))]);
			$cf->begin_form();
			$cf->hiddeninput('cancel_booking', '', ['value' => '1']);
			$cf->hiddeninput('token', '', ['value' => $booking->get('bkn_action_token')]);
			$cf->textbox('cancel_reason', 'Reason (optional)', []);
			$cf->submitbutton('btn_cancel', 'Cancel this booking');
			$cf->end_form();
			?>
		<?php endif; ?>
	<?php endif; ?>
<?php endif; ?>
</div>
<?php
echo PublicPage::EndPage();
$page->public_footer(array('track' => TRUE));
?>
