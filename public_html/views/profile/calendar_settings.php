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
<script>
(function(){
    // ----- Protection level -----
    // A card click changes the level at once (ProtectionLevelChange: the
    // promise flips first), with a passkey confirmation when the account has
    // a second factor, then the entries converge in bounded batches. Lowering
    // needs the vault window; the one unlock ceremony opens it and the change
    // runs again.
    var levelForm = document.getElementById('cal-level-form');
    var levelAlert = document.getElementById('cal-level-alert');
    var current = <?php echo json_encode($page_vars['protection_level']); ?>;
    var levelRemaining = <?php echo (int)($page_vars['level_remaining'] ?? 0); ?>;
    function levelNote(msg, kind) {
        levelAlert.hidden = !msg;
        levelAlert.className = 'alert alert-' + (kind || 'info');
        levelAlert.textContent = msg || '';
    }
    function setRadios(level) {
        if (!levelForm) { return; }
        Array.prototype.forEach.call(levelForm.querySelectorAll('[name="protection_level"]'), function(r){ r.checked = (r.value === level); });
    }
    function converge(remaining, done) {
        if (remaining <= 0) { done(0); return; }
        joineryApi.post('calendar_level_batch', {}).then(function(d){
            var left = (d && d.remaining) | 0;
            if (left > 0 && ((d && d.converted) | 0) > 0) { converge(left, done); return; }
            done(left);
        }).catch(function(err){
            var left = (err && err.data && err.data.remaining) | 0;
            if (err && err.data && err.data.locked) {
                levelNote('Unlock your vault to finish converting your calendar.', 'warning');
            }
            done(left || remaining);
        });
    }
    function changeLevel(level, retried) {
        levelNote('Changing your calendar’s protection…', 'info');
        var call = function(){ return joineryApi.post('calendar_level_change', { level: level }); };
        var run = window.JoineryPasskeys && window.JoineryPasskeys.withStepUp
            ? window.JoineryPasskeys.withStepUp(call, location.pathname + location.search)
            : call();
        run.then(function(d){
            current = (d && d.level) || level;
            setRadios(current);
            var remaining = (d && d.remaining) | 0;
            if (d && d.locked && remaining > 0) {
                levelNote('Your calendar is ' + current.charAt(0).toUpperCase() + current.slice(1)
                    + '. Unlock your vault to finish converting ' + remaining + ' entr' + (remaining === 1 ? 'y' : 'ies') + '.', 'warning');
                return;
            }
            if (remaining > 0) { levelNote('Converting ' + remaining + ' entr' + (remaining === 1 ? 'y' : 'ies') + '…', 'info'); }
            converge(remaining, function(left){
                if (left > 0) {
                    if (levelAlert.hidden) {
                        levelNote(left + ' entr' + (left === 1 ? 'y' : 'ies') + ' could not be converted yet. They are tried again the next time you are here with your vault unlocked.', 'warning');
                    }
                    return;
                }
                levelNote('Your calendar is now ' + current.charAt(0).toUpperCase() + current.slice(1) + '.', 'success');
            });
        }).catch(function(err){
            var msg = (err && err.message) || 'The change could not be made.';
            // Lowering needs the window: one unlock, then the same change again.
            if (!retried && window.JoineryVaultLock && /unlock your vault/i.test(msg)) {
                JoineryVaultLock.unlock().then(function(ok){
                    if (ok) { changeLevel(level, true); } else { setRadios(current); levelNote(msg, 'danger'); }
                });
                return;
            }
            setRadios(current);
            levelNote(msg, 'danger');
        });
    }
    if (levelForm) {
        Array.prototype.forEach.call(levelForm.querySelectorAll('[name="protection_level"]'), function(radio){
            radio.addEventListener('change', function(){
                if (radio.checked && radio.value !== current) { changeLevel(radio.value, false); }
            });
        });
        // A change that stopped part-way (the tab closed) carries on here.
        if (levelRemaining > 0) {
            levelNote('Finishing an earlier protection change — ' + levelRemaining + ' entr' + (levelRemaining === 1 ? 'y' : 'ies') + ' left…', 'info');
            converge(levelRemaining, function(left){
                if (left === 0) { levelNote('Your calendar is now ' + current.charAt(0).toUpperCase() + current.slice(1) + '.', 'success'); }
            });
        }
    }

    // ----- Email preferences -----
    var form  = document.getElementById('cal-settings-form');
    var alertBox = document.getElementById('cal-settings-alert');
    if (!form) { return; }

    function note(msg, ok) {
        alertBox.hidden = false;
        alertBox.className = ok ? 'alert alert-success' : 'alert alert-danger';
        alertBox.textContent = msg;
    }

    form.addEventListener('submit', function(ev){
        ev.preventDefault();
        var btn = form.querySelector('[type="submit"]');
        if (btn) { btn.disabled = true; }
        joineryApi.post('calendar_settings', {
            action:                   'save',
            summary_frequency:        (form.querySelector('[name="summary_frequency"]') || {}).value || 'none',
            summary_hour:             (form.querySelector('[name="summary_hour"]') || {}).value || '7',
            reminder_default_minutes: (form.querySelector('[name="reminder_default_minutes"]') || {}).value || '0'
        })
        .then(function(){
            if (btn) { btn.disabled = false; }
            note('Your calendar preferences have been saved.', true);
        })
        .catch(function(err){
            if (btn) { btn.disabled = false; }
            note((err && err.message) || 'Save failed. Please try again.', false);
        });
    });
})();
</script>
<?php
$page->public_footer();
?>
