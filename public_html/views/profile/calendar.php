<?php
/**
 * Personal calendar — the home surface where a subject's events, bookings, and
 * native entries appear on one timeline.
 *
 * Clicking a native entry chip VIEWS it: a popover with the title, when, where,
 * link and notes, and an edit (pencil) and delete (trash) icon. Clicking an
 * empty day opens the quick-create popover. Editing happens in a modal: the
 * pencil (and "More options" on the create popover) loads this page with the
 * entry in the URL (?edit_entry, ?d, or /entry/{id}/occurrence/{date}) and the
 * full form opens in its own <dialog> straight away; the grid returns to the
 * month the entry is in (?m).
 *
 * The form dialog is page-owned (#cal-form-dialog), not the kit's JoineryModal:
 * the kit dialog is a singleton whose content is replaced on every open, and it
 * has to stay free for the questions asked ON TOP of the form — the recurring
 * save/delete scope choice and the delete confirm. Nested native dialogs stack.
 *
 * The inline script runs at parse time, before the deferred kit script that
 * defines JoineryModal, so nothing here may call JoineryModal at load — only
 * from event handlers.
 *
 * Importing an .ics lives in the Actions menu, and is additionally offered as a
 * first-run prompt until the calendar holds an entry of its own. The menu's other
 * item downloads the member's own entries as one .ics (calendar_export): the file
 * comes back in the API response and is saved as a blob, so nothing is written on
 * the server. A Private calendar with the window closed runs the unlock first.
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('logic/calendar_logic.php'));

$page_vars = process_logic(calendar_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$tz = $timezone;

// Is this a virtual occurrence edit? (set by logic when arriving via /entry/{id}/occurrence/{date})
$is_occurrence = !empty($is_occurrence);

// For display: use parent_entry when in occurrence mode, otherwise entry.
$display_entry = ($is_occurrence && $parent_entry) ? $parent_entry : $entry;
$is_edit = (bool)$display_entry->key;

// The full form shows for "More options" (?d), an edit link, an occurrence
// URL, and a save that came back with errors (so what was typed is kept).
$posted         = isset($_POST['save_entry']);
$show_full_form = $is_edit || isset($_GET['d']) || $posted;

// The zone the form's wall-clock values are in: what was posted, else the
// entry's own zone, else the viewer's. An entry written for another zone is
// edited in that zone, so its times read the way they were entered.
if ($posted) {
    $edit_tz = (string)($entry_timezone ?? $tz);
} elseif ($is_edit) {
    $edit_tz = $display_entry->get('cal_timezone') ?: $tz;
} else {
    $edit_tz = $tz;
}

// Pre-fill values from local times (wall-clock in $edit_tz, the zone the form submits in).
if ($posted) {
    $e_date  = (string)($_POST['entry_date']  ?? '');
    $e_start = (string)($_POST['entry_start'] ?? '');
    $e_end   = (string)($_POST['entry_end']   ?? '');
} elseif ($is_edit) {
    // In occurrence mode, shift the pre-fill date to the occurrence_date.
    if ($is_occurrence && $occurrence_date) {
        $e_date  = $occurrence_date;
        // Times from parent's wall-clock (H:i:s portion of cal_start_local).
        $ls = $display_entry->get('cal_start_local') ?: $display_entry->get('cal_start_utc');
        $le = $display_entry->get('cal_end_local')   ?: $display_entry->get('cal_end_utc');
        $e_start = $ls ? substr($ls, 11) : '';
        $e_end   = $le ? substr($le, 11) : '';
    } else {
        $e_date  = LibraryFunctions::convert_time($display_entry->get('cal_start_utc'), 'UTC', $edit_tz, 'Y-m-d');
        $e_start = LibraryFunctions::convert_time($display_entry->get('cal_start_utc'), 'UTC', $edit_tz, 'H:i:s');
        $e_end   = LibraryFunctions::convert_time($display_entry->get('cal_end_utc'),   'UTC', $edit_tz, 'H:i:s');
    }
} else {
    $e_date  = (isset($_GET['d']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d'])) ? $_GET['d'] : '';
    $e_start = '';
    $e_end   = '';
}

$time_options = ['' => '--'];
for ($m = 0; $m < 24 * 60; $m += 30) {
    $h = intdiv($m, 60); $min = $m % 60;
    $val = sprintf('%02d:%02d:00', $h, $min);
    $ampm = $h < 12 ? 'AM' : 'PM'; $h12 = $h % 12; if ($h12 === 0) { $h12 = 12; }
    $time_options[$val] = sprintf('%d:%02d %s', $h12, $min, $ampm);
}

// The month the grid opens on: the entry being edited, else ?m (where a save,
// delete or cancel sends the reader back to), else today.
$grid_initial = $e_date ?: ((isset($_GET['m']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['m'])) ? $_GET['m'] : gmdate('Y-m-d'));

// Recurrence current values (for pre-filling the recurrence section on edit).
$cur_rec_type   = $is_edit ? $display_entry->get('cal_recurrence_type')         : null;
$cur_rec_int    = $is_edit ? ((int)($display_entry->get('cal_recurrence_interval') ?: 1)) : 1;
$cur_rec_days   = $is_edit ? $display_entry->get('cal_recurrence_days_of_week') : null;
$cur_rec_week   = $is_edit ? $display_entry->get('cal_recurrence_week_of_month') : null;
$cur_rec_end    = $is_edit ? $display_entry->get('cal_recurrence_end_date')      : null;
$cur_rec_desc   = ($is_edit && $display_entry->is_recurring_parent()) ? $display_entry->get_recurrence_description() : '';

$page = new PublicPage();
$hoptions = ['is_valid_page' => true, 'title' => 'My Calendar', 'breadcrumbs' => ['My Profile' => '/profile', 'Calendar' => '']];
$page->public_header($hoptions, NULL);
$hoptions['app'] = true;
// The calendar's protection level, as a chip linking to where it is chosen
// (docs/calendar.md § Protection level).
$level_chip = '<a class="jy-ui cal-level-chip is-' . htmlspecialchars($protection_level) . '" href="/profile/calendar_settings"'
    . ' title="Calendar protection: ' . htmlspecialchars(ProtectionLevel::label($protection_level)) . '. Change it in calendar settings.">'
    . htmlspecialchars(ProtectionLevelPicker::summary($protection_level)) . '</a>';
$hoptions['header_action'] = $level_chip . '<details class="jy-ui jy-actions-dropdown">'
    . '<summary class="btn btn-secondary">Actions</summary>'
    . '<div class="jy-actions-menu"><button type="button" data-cal-import>Import from another calendar (.ics)&hellip;</button>'
    . '<button type="button" data-cal-export>Download my calendar (.ics)</button></div>'
    . '</details>';
echo PublicPage::BeginPage('My Calendar', $hoptions);
?>
<div class="jy-ui cal-wrap">
<div class="cal-error" id="cal-export-error" hidden></div>
<?php if (!empty($saved)):  ?><div class="cal-note">Entry saved.</div><?php endif; ?>
<?php if (!empty($deleted)): ?><div class="cal-note">Entry deleted.</div><?php endif; ?>
<?php if (!empty($errors) && !$show_full_form):  ?><div class="cal-error"><?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?></div><?php endif; ?>

<?php if (!empty($import_summary)): ?>
    <?php if (!empty($import_summary['error'])): ?>
        <div class="cal-error"><?php echo htmlspecialchars($import_summary['error']); ?></div>
    <?php else: $s = $import_summary; ?>
        <div class="cal-note">
            Imported <?php echo (int)$s['created']; ?> <?php echo ((int)$s['created'] === 1 ? 'entry' : 'entries'); ?>.
            <?php if (!empty($s['imported_as_single'])) { echo ' ' . (int)$s['imported_as_single'] . ' event(s) with advanced repeat rules were added as single events.'; } ?>
            <?php if (!empty($s['skipped_duplicate'])) { echo ' ' . (int)$s['skipped_duplicate'] . ' already imported.'; } ?>
            <?php if (!empty($s['capped'])) { echo ' ' . (int)$s['capped'] . ' not processed (file too large).'; } ?>
            <?php if (!empty($s['failed'])) { echo ' ' . count($s['failed']) . ' could not be read.'; } ?>
            <?php if (!empty($s['failed_reasons'])): ?>
                <ul class="cal-note-detail">
                <?php foreach ($s['failed_reasons'] as $reason => $count): ?>
                    <li><?php echo htmlspecialchars($reason); ?><?php if ($count > 1) { echo ' (' . (int)$count . ' events)'; } ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($s['warnings'])): ?><br><small><?php echo htmlspecialchars(implode(' ', array_unique($s['warnings']))); ?></small><?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (empty($has_own_entries)): ?>
<div class="cal-firstrun">
    <div>
        <strong>No entries of your own yet</strong>
        <span>Click any day to add one, or bring an existing calendar over.</span>
    </div>
    <button type="button" class="btn btn-secondary" data-cal-import>Import a calendar</button>
</div>
<?php endif; ?>

<!-- Import form: rendered once, opened in JoineryModal from either trigger above. -->
<div id="cal-import-content" class="cal-import-form" hidden>
    <h3 class="cal-scope-heading">Import from another calendar</h3>
<?php
$impform = $page->getFormWriter('cal-import-form', ['action' => '/profile/calendar', 'enctype' => 'multipart/form-data']);
$impform->begin_form();
$impform->hiddeninput('import_entries', '', ['value' => '1']);
$impform->fileinput('ics_file', 'Calendar file', ['accept' => '.ics,text/calendar', 'required' => true]);
$impform->submitbutton('btn_import', 'Import');
$impform->end_form();
?>
</div>

<?php
echo ComponentRenderer::render(null, 'calendar_grid', [
    'view'         => 'month',
    'feed_url'     => '/api/v1/action/calendar_feed',
    'initial_date' => $grid_initial,
    'timezone'     => $tz,
]);
?>

<?php if ($show_full_form): ?>
    <dialog id="cal-form-dialog" class="jy-ui cal-form-dialog" aria-labelledby="cal-form-title">
    <div class="cal-full-form" id="cal-full-form">
        <h2 id="cal-form-title">
        <?php
            if ($is_occurrence) {
                echo 'Edit occurrence — ' . htmlspecialchars($occurrence_date);
            } elseif ($is_edit) {
                echo 'Edit entry';
            } else {
                echo 'Add an entry';
            }
        ?>
        </h2>
        <?php if ($is_edit && $cur_rec_desc): ?>
            <p class="cal-rec-desc is-static"><?php echo htmlspecialchars($cur_rec_desc); ?></p>
        <?php endif; ?>
        <?php if (!empty($errors)):  ?><div class="cal-error"><?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?></div><?php endif; ?>
<?php if (!empty($entry_locked)): ?>
        <!-- A Private calendar's entry with the vault window closed: the form
             cannot show its content, so the unlock is offered instead. -->
        <p class="cal-locked-note">This entry is encrypted and your vault is locked. Unlock to see and edit it.</p>
        <div class="cal-form-actions">
            <button type="button" class="btn btn-primary" id="cal-form-unlock">Unlock</button>
            <button type="button" class="btn btn-secondary" id="cal-form-cancel">Cancel</button>
        </div>
<?php else: ?>
<?php
$formwriter = $page->getFormWriter('form1', ['action' => '/profile/calendar']);
$formwriter->begin_form();
$formwriter->hiddeninput('save_entry', '', ['value' => '1']);
if ($is_edit)       { $formwriter->hiddeninput('entry_id',        '', ['value' => $display_entry->key]); }
if ($is_occurrence) { $formwriter->hiddeninput('occurrence_date', '', ['value' => $occurrence_date]); }
// scope: an occurrence edit asks at save time (the dialog below fills this
// in); a direct edit of the parent always means the whole series.
if ($is_occurrence) {
    $formwriter->hiddeninput('scope', '', ['value' => '', 'id' => 'cal-scope-field']);
} elseif ($is_edit && $display_entry->is_recurring_parent()) {
    $formwriter->hiddeninput('scope', '', ['value' => 'all']);
}
$formwriter->textinput('entry_title', 'Title', ['value' => $posted ? (string)($_POST['entry_title'] ?? '') : ($is_edit ? $display_entry->get('cal_title') : ''), 'placeholder' => 'e.g. Dentist, Focus time']);
$formwriter->dateinput('entry_date', 'Date', ['value' => $e_date]);
$formwriter->checkboxinput('entry_all_day', 'All day', [
    'value' => $posted ? !empty($_POST['entry_all_day']) : ($is_edit ? (bool)$display_entry->get('cal_all_day') : false),
    'visibility_rules' => [
        'checked'   => ['hide' => ['entry_start', 'entry_end', 'entry_timezone']],
        'unchecked' => ['show' => ['entry_start', 'entry_end', 'entry_timezone']],
    ],
]);
$formwriter->dropinput('entry_start', 'Start', ['options' => $time_options, 'value' => $e_start]);
$formwriter->dropinput('entry_end',   'End',   ['options' => $time_options, 'value' => $e_end]);
// The zone the times above are in. Defaults to the viewer's own; an entry
// made for a call in another city is entered in that city's zone and the
// calendar shows it at the viewer's local time.
$tz_options = Address::get_timezone_drop_array();
if (!isset($tz_options[$edit_tz])) { $tz_options = [$edit_tz => $edit_tz] + $tz_options; }
$formwriter->dropinput('entry_timezone', 'Time zone', [
    'options'  => $tz_options,
    'value'    => $edit_tz,
    'helptext' => ($edit_tz === $tz) ? 'Your time zone.' : 'Shown on your calendar in your own time zone (' . $tz . ').',
]);
$formwriter->checkboxinput('entry_blocks', 'Block this time (removes it from your booking availability)', ['value' => $posted ? !empty($_POST['entry_blocks']) : ($is_edit ? (bool)$display_entry->get('cal_blocks_availability') : false)]);

// Details (specs/calendar_entry_details.md): where, the one link that gets
// you in, and plain-text notes. A submitted value is what was saved, so a
// failed save keeps what was typed.
$formwriter->textinput('entry_location', 'Location', [
    'value'       => $_POST['entry_location'] ?? ($is_edit ? (string)$display_entry->get('cal_location') : ''),
    'placeholder' => 'e.g. Room 4B, Zoom, 12 Main St',
    'maxlength'   => 255,
]);
$formwriter->textinput('entry_link', 'Link', [
    'value'       => $_POST['entry_link'] ?? ($is_edit ? (string)$display_entry->get('cal_link') : ''),
    'placeholder' => 'https://… (meeting, tickets, confirmation)',
    'helptext'    => 'Shown in your reminder email as a link.',
]);
$formwriter->textbox('entry_notes', 'Notes', [
    'value'       => $_POST['entry_notes'] ?? ($is_edit ? (string)$display_entry->get('cal_notes') : ''),
    'rows'        => 4,
    'placeholder' => 'Confirmation number, dial-in, what to bring…',
]);

// Reminder override. '' = inherit the member's default (set on
// /profile/calendar_settings); the first option's label shows what that
// default currently is, so the choice is legible without leaving the form.
$lead_labels = [60 => '1 hour before', 30 => '30 minutes before', 15 => '15 minutes before', 5 => '5 minutes before'];
$default_label = ($reminder_default_minutes > 0 && isset($lead_labels[$reminder_default_minutes]))
    ? 'Use my default (' . strtolower($lead_labels[$reminder_default_minutes]) . ')'
    : 'Use my default (no reminder)';
$cur_reminder = $posted ? ($_POST['entry_reminder'] ?? null) : ($is_edit ? $display_entry->get('cal_reminder_minutes') : null);
$formwriter->dropinput('entry_reminder', 'Reminder', [
    'options' => ['' => $default_label, '0' => 'No reminder', '60' => '1 hour before', '30' => '30 minutes before', '15' => '15 minutes before', '5' => '5 minutes before'],
    'value'   => ($cur_reminder === null || $cur_reminder === '') ? '' : (string)(int)$cur_reminder,
]);
?>
        <?php
        // ── Recurrence: real FormWriter inputs, show/hide driven entirely by
        // declarative visibility_rules (no hand-rolled toggle JS). Thin
        // structural wrappers give each trigger a clean, non-overlapping target.
        //
        // Pre-fill: what was just posted (a save that came back with an
        // error keeps the repeat rule that was typed), else the stored values.
        $rec_count_val = 10;
        if ($posted) {
            $repeats_val  = !empty($_POST['entry_repeats']);
            $rec_freq_val = in_array($_POST['rec_frequency'] ?? '', ['daily', 'weekly', 'monthly', 'yearly'], true) ? $_POST['rec_frequency'] : 'weekly';
            $cur_rec_int  = max(1, (int)($_POST['rec_interval'] ?? 1));
            $monthly_mode = (($_POST['rec_monthly_mode'] ?? 'day') === 'week') ? 'week' : 'day';
            $rec_week_val = (int)($_POST['rec_week'] ?? 1);
            $ends_val     = in_array($_POST['rec_ends'] ?? '', ['never', 'date', 'count'], true) ? $_POST['rec_ends'] : 'never';
            $cur_rec_end  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['rec_end_date'] ?? '')) ? $_POST['rec_end_date'] : null;
            $rec_count_val = max(1, (int)($_POST['rec_count'] ?? 10));
        } else {
            $repeats_val  = (bool)$cur_rec_type;
            $rec_freq_val = $cur_rec_type ?: 'weekly';
            $monthly_mode = ($cur_rec_week !== null) ? 'week' : 'day';
            $rec_week_val = ($cur_rec_week !== null) ? (int)$cur_rec_week : 1;
            $ends_val     = $cur_rec_end ? 'date' : 'never';
        }

        // Weekly day picker: posted list, else saved list for weekly entries, else the entry date's DOW.
        if ($posted) {
            $weekly_checked = isset($_POST['rec_days']) && is_array($_POST['rec_days']) ? array_map('intval', $_POST['rec_days']) : [];
        } elseif ($cur_rec_type === 'weekly' && $cur_rec_days !== null && $cur_rec_days !== '') {
            $weekly_checked = array_map('intval', explode(',', $cur_rec_days));
        } elseif ($e_date) {
            $weekly_checked = [(int)date('w', strtotime($e_date))];
        } else {
            $weekly_checked = [];
        }

        // Monthly weekday picker: posted, else stored single DOW digit, else the entry date's DOW.
        if ($posted && isset($_POST['rec_dow']) && $_POST['rec_dow'] !== '') {
            $monthly_dow = (int)$_POST['rec_dow'];
        } elseif ($cur_rec_type === 'monthly' && $cur_rec_week !== null && $cur_rec_days !== null && $cur_rec_days !== '') {
            $monthly_dow = (int)$cur_rec_days;
        } elseif ($e_date) {
            $monthly_dow = (int)date('w', strtotime($e_date));
        } else {
            $monthly_dow = 1;
        }

        $day_short = [0=>'Sun', 1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat'];
        $day_full  = [0=>'Sunday', 1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday'];

        $formwriter->checkboxinput('entry_repeats', 'Repeats', [
            'value' => $repeats_val,
            'visibility_rules' => [
                'checked'   => ['show' => ['rec_section']],
                'unchecked' => ['hide' => ['rec_section']],
            ],
        ]);
        ?>
        <div id="rec_section" class="cal-recurrence-section">
        <?php
        $formwriter->dropinput('rec_frequency', 'Repeat', [
            'options' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'],
            'value'   => $rec_freq_val,
            'visibility_rules' => [
                'daily'   => ['hide' => ['rec_weekly_group', 'rec_monthly_group']],
                'weekly'  => ['show' => ['rec_weekly_group'], 'hide' => ['rec_monthly_group']],
                'monthly' => ['show' => ['rec_monthly_group'], 'hide' => ['rec_weekly_group']],
                'yearly'  => ['hide' => ['rec_weekly_group', 'rec_monthly_group']],
            ],
        ]);
        $formwriter->numberinput('rec_interval', 'Repeat every', [
            'value' => $cur_rec_int, 'min' => 1, 'max' => 99,
            'helptext' => 'Number of days/weeks/months/years between occurrences.',
        ]);
        ?>
            <div id="rec_weekly_group">
            <?php
            $formwriter->checkboxList('rec_days', 'On these days', [
                'type'    => 'checkbox',
                'options' => $day_short,
                'checked' => $weekly_checked,
            ]);
            ?>
            </div>
            <div id="rec_monthly_group">
            <?php
            $formwriter->radioinput('rec_monthly_mode', 'Monthly pattern', [
                'options' => ['day' => 'On the same day of the month', 'week' => 'On a specific weekday'],
                'value'   => $monthly_mode,
                'visibility_rules' => [
                    'day'  => ['hide' => ['rec_week', 'rec_dow']],
                    'week' => ['show' => ['rec_week', 'rec_dow']],
                ],
            ]);
            $formwriter->dropinput('rec_week', 'Week', [
                'options' => [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', -1 => 'Last'],
                'value'   => $rec_week_val,
            ]);
            $formwriter->dropinput('rec_dow', 'Weekday', [
                'options' => $day_full,
                'value'   => $monthly_dow,
            ]);
            ?>
            </div>
            <?php
            $formwriter->radioinput('rec_ends', 'Ends', [
                'options' => ['never' => 'Never', 'date' => 'On date', 'count' => 'After N occurrences'],
                'value'   => $ends_val,
                'visibility_rules' => [
                    'never' => ['hide' => ['rec_end_date', 'rec_count']],
                    'date'  => ['show' => ['rec_end_date'], 'hide' => ['rec_count']],
                    'count' => ['show' => ['rec_count'], 'hide' => ['rec_end_date']],
                ],
            ]);
            $formwriter->dateinput('rec_end_date', 'End date', ['value' => $cur_rec_end ?: '']);
            $formwriter->numberinput('rec_count', 'Number of occurrences', ['value' => $rec_count_val, 'min' => 1, 'max' => 999]);
            ?>
        </div><!-- #rec_section -->
<?php
$formwriter->end_form();

if ($is_edit) {
    // Delete form: hidden fields only; its button sits in the shared action
    // row below (form="delform"). A standalone entry confirms through the
    // kit; a recurring one asks which occurrences (the "Delete…" dialog).
    $del_opts = ['action' => '/profile/calendar'];
    if (!$display_entry->is_recurring_parent() && !$is_occurrence) {
        $del_opts['data-jy-confirm'] = 'Delete this entry?';
    }
    $delform = $page->getFormWriter('delform', $del_opts);
    $delform->begin_form();
    $delform->hiddeninput('delete_entry', '', ['value' => '1']);
    $delform->hiddeninput('entry_id',     '', ['value' => $display_entry->key]);
    if ($is_occurrence) {
        $delform->hiddeninput('occurrence_date', '', ['value' => $occurrence_date]);
    }
    if ($display_entry->is_recurring_parent() || $is_occurrence) {
        // filled by the delete-scope dialog before the form is submitted
        $delform->hiddeninput('scope', '', ['value' => 'all', 'id' => 'del-scope-field']);
    }
    $delform->end_form();
}
$is_recurring_delete = $is_edit && ($display_entry->is_recurring_parent() || $is_occurrence);
?>
        <!-- One action row for both forms: buttons target their form by id. -->
        <div class="cal-form-actions">
            <button type="submit" form="form1" name="btn_save" class="btn btn-primary"><?php echo $is_edit ? 'Save changes' : 'Add entry'; ?></button>
            <button type="button" class="btn btn-secondary" id="cal-form-cancel">Cancel</button>
            <?php if ($is_edit): ?>
            <button type="submit" form="delform" name="btn_delete" class="btn btn-danger-soft cal-form-delete"<?php echo $is_recurring_delete ? ' id="btn-delete-rec"' : ''; ?>><?php echo $is_recurring_delete ? 'Delete…' : 'Delete entry'; ?></button>
            <?php endif; ?>
        </div>
<?php endif; // $entry_locked ?>
    </div><!-- .cal-full-form -->
    </dialog>
<?php endif; // $show_full_form ?>
</div><!-- .cal-wrap -->

<!-- Popover (fixed overlay; JS positions it near the clicked cell or chip) -->
<div id="cal-popup" class="jy-ui cal-popup" role="dialog" aria-modal="true" aria-label="Calendar entry">
    <div class="cal-popup-header">
        <span class="cal-popup-title" id="cal-popup-title">New entry</span>
        <span class="cal-popup-tools">
            <button type="button" class="cal-popup-icon" id="cal-popup-edit" aria-label="Edit" title="Edit" hidden>
                <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M14.85 2.85a1.2 1.2 0 0 1 1.7 0l.6.6a1.2 1.2 0 0 1 0 1.7L7 15.3 3 16l.7-4L14.85 2.85zm-9.6 9.6-.35 2.15 2.15-.35 8.4-8.4-1.8-1.8-8.4 8.4z"/></svg>
            </button>
            <button type="button" class="cal-popup-icon is-danger" id="cal-popup-delete" aria-label="Delete" title="Delete" hidden>
                <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M7 2h6l1 2h3v2H3V4h3l1-2zm-2 6h10l-.8 9.2A1 1 0 0 1 13.2 18H6.8a1 1 0 0 1-1-.8L5 8zm3 2v6h1.5v-6H8zm2.5 0v6H12v-6h-1.5z"/></svg>
            </button>
            <button type="button" class="cal-popup-close" id="cal-popup-close" aria-label="Close">&#215;</button>
        </span>
    </div>
    <div class="cal-popup-error" id="cal-popup-error"></div>
    <!-- View mode: what the entry is. Filled from the feed item; repeats and
         notes arrive from the calendar_entry action a moment later. -->
    <div class="cal-popup-view" id="cal-popup-view" hidden>
        <div class="cal-popup-when" id="cal-popup-when"></div>
        <div class="cal-popup-line" id="cal-popup-zone" hidden></div>
        <div class="cal-popup-line" id="cal-popup-repeats" hidden></div>
        <div class="cal-popup-line" id="cal-popup-location" hidden></div>
        <div class="cal-popup-line" id="cal-popup-link" hidden></div>
        <div class="cal-popup-notes" id="cal-popup-notes" hidden></div>
        <!-- A locked entry (Private calendar, window closed): the time is real,
             the content waits for the unlock. -->
        <div class="cal-popup-locked" id="cal-popup-locked" hidden>
            <p>This entry is encrypted. Unlock to see it.</p>
            <button type="button" class="btn btn-primary btn-sm" id="cal-popup-unlock">Unlock</button>
        </div>
    </div>
<?php
$popwriter = $page->getFormWriter('cal-pop-form', ['action' => '/api/v1/action/calendar_entry_save']);
$popwriter->begin_form();
// Field ids are prefixed: the full form on the same page uses the same
// field names, and two elements sharing an id would leave the full form's
// visibility rules (bound by id) pointing at whichever came first. The
// popover's own JS addresses fields by name.
$popwriter->hiddeninput('action',   '', ['value' => 'save', 'id' => 'pop_action']);
$popwriter->hiddeninput('entry_id', '', ['value' => '', 'id' => 'pop_entry_id']);
?>
    <div class="cal-popup-body">
<?php
$popwriter->textinput('entry_title', 'Title', ['placeholder' => 'Add title', 'id' => 'pop_entry_title']);
$popwriter->dateinput('entry_date', 'Date', ['id' => 'pop_entry_date']);
$popwriter->checkboxinput('entry_all_day', 'All day', ['value' => true, 'id' => 'pop_entry_all_day']);
?>
        <div class="cal-pop-times" id="cal-pop-times">
            <div class="cal-pop-time-row">
                <div class="form-group">
                    <label class="form-label">Start</label>
                    <input type="time" name="entry_start" class="form-control cal-pop-time-input">
                </div>
                <div class="form-group">
                    <label class="form-label">End</label>
                    <input type="time" name="entry_end" class="form-control cal-pop-time-input">
                </div>
            </div>
        </div>
<?php
$popwriter->textinput('entry_location', 'Location', ['placeholder' => 'Add location', 'maxlength' => 255, 'id' => 'pop_entry_location']);
$popwriter->checkboxinput('entry_blocks', 'Block this time (removes from booking availability)', ['value' => false, 'id' => 'pop_entry_blocks']);
?>
    </div>
    <div class="cal-popup-footer">
        <button type="button" class="cal-popup-more" id="cal-popup-more">More options</button>
        <div class="cal-popup-right">
            <button type="submit" class="cal-popup-save">Save</button>
        </div>
    </div>
<?php $popwriter->end_form(); ?>
</div>

<?php echo PageScript::json('calendar-page-config', array(
    'tz'              => $tz,
    'back_url'        => '/profile/calendar' . ($e_date ? '?m=' . $e_date : ''),
    'occurrence_date' => $is_occurrence ? $occurrence_date : '',
    'level_remaining' => (int)($level_remaining ?? 0),
)), PageScript::src('assets/js/calendar-page.js'); ?>
<?php
echo PublicPage::EndPage();
$page->public_footer(['track' => true]);
?>
