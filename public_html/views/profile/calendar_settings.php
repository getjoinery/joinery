<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getIncludePath('logic/calendar_settings_logic.php'));
	require_once(PathHelper::getIncludePath('includes/ProtectionLevelPicker.php'));
	require_once(PathHelper::getIncludePath('includes/calendar/CalendarLevel.php'));

	$page_vars = process_logic(calendar_settings_logic(array_merge($_GET, $_POST)));

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Calendar',
	]);

	// Hour choices for the summary send time, labeled in 12-hour form.
	$hour_options = [];
	for ($h = 0; $h < 24; $h++) {
		$ampm = $h < 12 ? 'AM' : 'PM';
		$h12  = $h % 12;
		if ($h12 === 0) { $h12 = 12; }
		$hour_options[$h] = $h12 . ':00 ' . $ampm;
	}

	$lead_options = [
		0  => 'No reminder',
		60 => '1 hour before',
		30 => '30 minutes before',
		15 => '15 minutes before',
		5  => '5 minutes before',
	];
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Calendar</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li class="active">Calendar</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start(); ?>

            <!-- Protection level (docs/calendar.md § Protection level): the
                 shared picker's two cards; a change goes through the
                 calendar_level_change action and converges the entries. -->
            <div class="jy-panel">
                <div id="cal-level-alert" hidden></div>
                <?php
                $levelwriter = $page->getFormWriter('cal-level-form', [
                    'action' => '/profile/calendar_settings',
                ]);
                $levelwriter->begin_form();
                $level_notes = [];
                $level_disabled = [];
                if (empty($page_vars['has_vault'])) {
                    $level_disabled = [ProtectionLevel::PRIVATE_];
                    $level_notes[ProtectionLevel::PRIVATE_] = ['Needs your encryption vault — set one up in your security settings first.'];
                }
                ProtectionLevelPicker::render($levelwriter, 'protection_level', [
                    'service'         => ProtectionLevelPicker::SERVICE_CALENDAR,
                    'levels'          => CalendarLevel::LEVELS,
                    'value'           => $page_vars['protection_level'],
                    'label'           => 'Protection level',
                    'notes'           => $level_notes,
                    'disabled_values' => $level_disabled,
                    'helptext'        => 'Events and bookings shown on your calendar keep the protection of the place they were made.',
                ]);
                $levelwriter->end_form();
                ?>
            </div>

            <div class="jy-panel jy-form-actions">
                <?php if (!empty($page_vars['send_blocker'])): ?>
                <div class="alert alert-warning">
                    Heads up: this site's automated email is not able to send right now, so summaries and
                    reminders will not arrive until the site admin fixes it. Your choices here are saved and
                    take effect as soon as sending works.
                </div>
                <?php endif; ?>
                <div id="cal-settings-alert" hidden></div>
                <?php
                $formwriter = $page->getFormWriter('cal-settings-form', [
                    'action' => '/profile/calendar_settings',
                ]);
                $formwriter->begin_form();
                $formwriter->dropinput('summary_frequency', 'Summary emails', [
                    'options' => [
                        'none'   => 'No summary',
                        'daily'  => 'Daily',
                        'weekly' => 'Weekly — Mondays',
                    ],
                    'value'    => $page_vars['summary_frequency'],
                    'helptext' => 'An email listing everything on your calendar — for the day, or the week ahead.',
                    'visibility_rules' => [
                        'none'   => ['hide' => ['summary_hour']],
                        'daily'  => ['show' => ['summary_hour']],
                        'weekly' => ['show' => ['summary_hour']],
                    ],
                ]);
                $formwriter->dropinput('summary_hour', 'Send summary at', [
                    'options'  => $hour_options,
                    'value'    => $page_vars['summary_hour'],
                    'helptext' => 'In your timezone.',
                ]);
                $formwriter->dropinput('reminder_default_minutes', 'Event reminders', [
                    'options'  => $lead_options,
                    'value'    => $page_vars['reminder_default_minutes'],
                    'helptext' => 'Applies to new and existing entries unless an entry overrides it.',
                ]);
                $formwriter->submitbutton('btn_save', 'Save Preferences');
                $formwriter->end_form();
                ?>
            </div>
            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<!-- WebAuthn helper: a level change asks the member to confirm it is them. -->
<script src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<?php echo PageScript::json('calendar-settings-config', array(
    'protection_level' => $page_vars['protection_level'],
    'level_remaining'  => (int)($page_vars['level_remaining'] ?? 0),
)), PageScript::src('assets/js/calendar-settings-page.js'); ?>
<?php
$page->public_footer();
?>
