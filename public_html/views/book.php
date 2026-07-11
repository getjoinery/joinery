<?php
/**
 * Public booking page (/book/{slug}). Renders the slot picker and the invitee
 * form; on success shows a confirmation. The booking type and host come from the
 * plugin's book_logic.
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/bookings/logic/book_logic.php'));

$page_vars = process_logic(book_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$valid = !isset($is_valid_page) || $is_valid_page;
$title = ($valid && $type) ? $type->get('bkt_name') : 'Booking';
$page->public_header(array('is_valid_page' => $valid, 'title' => $title), NULL);
echo PublicPage::BeginPage($title, array());
?>
<div class="jy-ui jy-book-wrap">
<?php if (!$valid || !$type): ?>
	<h1>Booking not found</h1>
	<p>This booking link isn't available. The link may be inactive or the page may have moved.</p>
<?php elseif (!empty($confirmed_booking)): ?>
	<?php
	$cb = $confirmed_booking;
	$ctz = SessionControl::get_instance()->get_timezone();
	$when = LibraryFunctions::convert_time($cb->get('bkn_start_time'), 'UTC', $ctz, 'l, M j, Y g:i A T');
	$manage = '/booking/manage?token=' . htmlspecialchars($cb->get('bkn_action_token'));
	?>
	<h1>You're booked!</h1>
	<p><strong><?php echo htmlspecialchars($type->get('bkt_name')); ?></strong><br><?php echo htmlspecialchars($when); ?></p>
	<?php if ($type->get('bkt_location_details')): ?><p>Location: <?php echo htmlspecialchars($type->get('bkt_location_details')); ?></p><?php endif; ?>
	<p>A confirmation email is on its way. Need a change? <a href="<?php echo $manage; ?>">Cancel or reschedule</a>.</p>
<?php else: ?>
	<h1><?php echo htmlspecialchars($type->get('bkt_name')); ?></h1>
	<p class="jy-book-meta">with <?php echo htmlspecialchars($host->display_name()); ?>
		· <?php echo (int)$type->get('bkt_duration_minutes'); ?> min</p>
	<?php if ($type->get('bkt_description_plain')): ?>
		<p><?php echo nl2br(htmlspecialchars($type->get('bkt_description_plain'))); ?></p>
	<?php endif; ?>
	<?php if (!empty($errors)): ?>
		<div class="jy-book-errors">
			<?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?>
		</div>
	<?php endif; ?>

	<?php
	$formwriter = $page->getFormWriter('bookform', ['action' => '/book/' . $type->get('bkt_slug')]);
	$formwriter->begin_form();
	$formwriter->hiddeninput('book_submit', '', ['value' => '1']);
	$formwriter->hiddeninput('invitee_timezone', '', ['value' => '']);

	// Slot picker writes the chosen UTC slot into the hidden 'slot_start' field.
	echo ComponentRenderer::render(null, 'slot_picker', [
		'slots_url' => '/api/v1/action/bookings/booking_slots?slug=' . rawurlencode($type->get('bkt_slug')),
		'field_name' => 'slot_start',
	]);

	echo '<h3 class="jy-book-sectionhead">Your details</h3>';
	$formwriter->textinput('invitee_name', 'Name', ['value' => $old['name'] ?? '', 'required' => true]);
	$formwriter->textinput('invitee_email', 'Email', ['value' => $old['email'] ?? '', 'required' => true, 'validation' => 'email']);
	$formwriter->textbox('invitee_notes', 'Anything you\'d like to share?', ['value' => $old['notes'] ?? '']);

	// Intake survey questions (rendered inline via Question::output_question).
	if ($type->get('bkt_svy_survey_id')) {
		require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
		require_once(PathHelper::getIncludePath('data/questions_class.php'));
		$sq = new MultiSurveyQuestion(['survey_id' => $type->get('bkt_svy_survey_id'), 'deleted' => false]);
		$sq->load();
		if (count($sq)) {
			echo '<h3 class="jy-book-sectionhead">A few questions</h3>';
			foreach ($sq as $row) {
				$q = new Question($row->get('srq_qst_question_id'), TRUE);
				if ($q->key) { $q->output_question($formwriter); }
			}
		}
	}

	$formwriter->submitbutton('btn_book', 'Confirm booking');
	$formwriter->end_form();
	?>
	<script>
	// Auto-detect the invitee's timezone for storage (display already follows the picker's tz).
	(function(){
		var tz = (Intl.DateTimeFormat().resolvedOptions().timeZone) || 'UTC';
		var f = document.querySelector('[name="invitee_timezone"]');
		if (f) { f.value = tz; }
	})();
	</script>
<?php endif; ?>
</div>
<?php
echo PublicPage::EndPage();
$page->public_footer(array('track' => TRUE));
?>
