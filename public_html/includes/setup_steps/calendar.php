<?php
/**
 * Setup wizard step: Calendar (specs/setup_wizard.md § Step 6).
 * The same IcsImporter path the calendar page uses, plus the reminder/summary
 * preferences forwarded to the calendar_settings action. Included by
 * views/setup.php with $page, $page_vars, $viewer, $next_key in scope.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/calendar_preference_class.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

$setup_cal_pref = CalendarPreference::get_for((int)$viewer->key);
$setup_cal_blocker = EmailSender::transactionalSendBlocker();
$setup_cal_summary = $page_vars['calendar_import_summary'] ?? null;
?>

<?php if (is_array($setup_cal_summary)) { ?>
<?php if (!empty($setup_cal_summary['error'])) { ?>
	<div class="jy-alert jy-alert-error"><?php echo htmlspecialchars($setup_cal_summary['error']); ?></div>
<?php } else { ?>
	<div class="jy-callout jy-callout-info">
		<div class="jy-callout-title">Import finished</div>
		<p>
			<?php echo (int)($setup_cal_summary['created'] ?? 0); ?> event<?php echo (int)($setup_cal_summary['created'] ?? 0) === 1 ? '' : 's'; ?> imported<?php
			if (!empty($setup_cal_summary['skipped_duplicate'])) { echo ', ' . (int)$setup_cal_summary['skipped_duplicate'] . ' duplicates skipped'; }
			if (!empty($setup_cal_summary['failed'])) { echo ', ' . count($setup_cal_summary['failed']) . ' failed'; }
			?>.
		</p>
	</div>
<?php } ?>
<?php } ?>

	<div class="jy-fieldset">
		<h4>Bring an existing calendar</h4>
<?php
$setup_cal_form = $page->getFormWriter('setup-cal-import', array(
	'action' => '/setup',
	'method' => 'POST',
	'enctype' => 'multipart/form-data',
));
$setup_cal_form->begin_form();
$setup_cal_form->hiddeninput('action', '', array('value' => 'calendar_import'));
$setup_cal_form->hiddeninput('step', '', array('value' => 'calendar'));
echo $setup_cal_form->fileinput('ics_file', 'Calendar file (.ics)', array('accept' => '.ics,text/calendar'));
echo $setup_cal_form->submitbutton('btn_cal_import', 'Import', array('class' => 'btn btn-secondary'));
$setup_cal_form->end_form();
?>
	</div>

	<div class="jy-fieldset jy-mt-3">
		<h4>Reminders and summaries</h4>
<?php if ($setup_cal_blocker !== null) { ?>
		<div class="jy-alert jy-alert-info">Email reminders need sending set up first (the "Sending email" step). You can still choose here — they start working once sending does.</div>
<?php } ?>
<?php
$setup_cal_prefs_form = $page->getFormWriter('setup-cal-prefs', array('action' => '/setup', 'method' => 'POST'));
$setup_cal_prefs_form->begin_form();
$setup_cal_prefs_form->hiddeninput('action', '', array('value' => 'calendar_prefs'));
$setup_cal_prefs_form->hiddeninput('step', '', array('value' => 'calendar'));
echo $setup_cal_prefs_form->dropinput('summary_frequency', 'Email me a summary', array(
	'options' => array('none' => 'Never', 'daily' => 'Every morning', 'weekly' => 'Weekly'),
	'value' => (string)$setup_cal_pref->get('cpr_summary_frequency') ?: 'none',
));
echo $setup_cal_prefs_form->dropinput('summary_hour', 'Send it at', array(
	'options' => array('6' => '6 AM', '7' => '7 AM', '8' => '8 AM', '9' => '9 AM'),
	'value' => (string)((int)$setup_cal_pref->get('cpr_summary_hour') ?: 7),
));
echo $setup_cal_prefs_form->dropinput('reminder_default_minutes', 'Default event reminder', array(
	'options' => array('0' => 'Off', '60' => '1 hour before', '30' => '30 minutes before', '15' => '15 minutes before', '5' => '5 minutes before'),
	'value' => (string)(int)$setup_cal_pref->get('cpr_reminder_default_minutes'),
));
echo $setup_cal_prefs_form->submitbutton('btn_cal_prefs', 'Save and continue', array('class' => 'btn btn-primary'));
$setup_cal_prefs_form->end_form();
?>
		<p class="jy-muted">"Never" and "Off" are fine answers — saving records your choice either way.</p>
	</div>
