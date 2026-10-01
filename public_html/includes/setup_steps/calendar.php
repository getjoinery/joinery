<?php
/**
 * Setup wizard step: Calendar (specs/setup_wizard.md § Step 6).
 * The protection level (the shared picker's two cards, offered above the
 * import when the viewer holds a vault, so imported entries land sealed), the
 * same IcsImporter path the calendar page uses, and the reminder/summary
 * preferences forwarded to the calendar_settings action. Included by
 * views/setup.php with $page, $page_vars, $viewer, $next_key in scope.
 *
 * @version 1.1 - the protection level, first, when the viewer holds a vault
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/calendar_preferences_class.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/ProtectionLevelPicker.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarLevel.php'));

$setup_cal_pref = CalendarPreference::get_for((int)$viewer->key);
$setup_cal_blocker = EmailSender::transactionalSendBlocker();
$setup_cal_summary = $page_vars['calendar_import_summary'] ?? null;
$setup_cal_has_vault = CalendarLevel::ownerHasVault((int)$viewer->key);
$setup_cal_level_saved = !empty($page_vars['calendar_level_saved']);
?>

	<div class="jy-fieldset">
		<h4>How protected is your calendar?</h4>
<?php if ($setup_cal_has_vault) { ?>
<?php if ($setup_cal_level_saved) { ?>
		<div class="jy-callout jy-callout-info"><div class="jy-callout-title">Saved</div><p>Your calendar is <?php echo htmlspecialchars(ProtectionLevel::label($setup_cal_pref->protection_level())); ?>. Entries you import below land that way.</p></div>
<?php } ?>
<?php
$setup_cal_level_form = $page->getFormWriter('setup-cal-level', array('action' => '/setup', 'method' => 'POST'));
$setup_cal_level_form->begin_form();
$setup_cal_level_form->hiddeninput('action', '', array('value' => 'calendar_level'));
$setup_cal_level_form->hiddeninput('step', '', array('value' => 'calendar'));
ProtectionLevelPicker::render($setup_cal_level_form, 'protection_level', array(
	'service'  => ProtectionLevelPicker::SERVICE_CALENDAR,
	'levels'   => CalendarLevel::LEVELS,
	'value'    => $setup_cal_pref->protection_level(),
	'label'    => 'Protection level',
	'helptext' => 'Choose before importing, so the entries land with this protection. Events and bookings shown on your calendar keep the protection of the place they were made.',
));
echo $setup_cal_level_form->submitbutton('btn_cal_level', 'Save protection level', array('class' => 'btn btn-secondary'));
$setup_cal_level_form->end_form();
?>
<?php } else { ?>
		<p class="jy-muted">A private calendar needs your personal encryption key. Set one up in <a href="/profile/security">your security settings</a>, then choose a level in <a href="/profile/calendar_settings">calendar settings</a>.</p>
<?php } ?>
	</div>


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

	<div class="jy-fieldset jy-mt-3">
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
