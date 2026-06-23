<?php
/**
 * Personal calendar — the home surface where a subject's events, bookings, and
 * native entries appear on one timeline. Click an empty day or an existing
 * native entry chip to open the quick-entry popover. "More options" opens the
 * full form (shown below the grid when ?edit_entry or ?d are in the URL, or when
 * arriving via /profile/calendar/entry/{id}/occurrence/{date}).
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('logic/calendar_logic.php'));

$page_vars = process_logic(calendar_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$tz = $timezone;

// Is this a virtual occurrence edit? (set by logic when arriving via /entry/{id}/occurrence/{date})
$is_occurrence   = !empty($is_occurrence);
$show_scope_modal= !empty($show_scope_modal);

// For display: use parent_entry when in occurrence mode, otherwise entry.
$display_entry = ($is_occurrence && $parent_entry) ? $parent_entry : $entry;
$is_edit = (bool)$display_entry->key;

// Show the full form when navigating via "More options", a direct edit link, or occurrence URL.
$show_full_form = $is_edit || isset($_GET['d']);

// Pre-fill values from local times (wall-clock, same timezone the form submits in).
if ($is_edit) {
    // In occurrence mode, shift the pre-fill date to the occurrence_date.
    if ($is_occurrence && $occurrence_date) {
        $e_date  = $occurrence_date;
        // Times from parent's wall-clock (H:i:s portion of cal_start_local).
        $ls = $display_entry->get('cal_start_local') ?: $display_entry->get('cal_start_utc');
        $le = $display_entry->get('cal_end_local')   ?: $display_entry->get('cal_end_utc');
        $e_start = $ls ? substr($ls, 11) : '';
        $e_end   = $le ? substr($le, 11) : '';
    } else {
        $e_date  = LibraryFunctions::convert_time($display_entry->get('cal_start_utc'), 'UTC', $tz, 'Y-m-d');
        $e_start = LibraryFunctions::convert_time($display_entry->get('cal_start_utc'), 'UTC', $tz, 'H:i:s');
        $e_end   = LibraryFunctions::convert_time($display_entry->get('cal_end_utc'),   'UTC', $tz, 'H:i:s');
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

$grid_initial = $e_date ?: (isset($_GET['d']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d']) ? $_GET['d'] : gmdate('Y-m-d'));

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
echo PublicPage::BeginPage('My Calendar', $hoptions);
?>
<style>
.cal-wrap { max-width: 980px; margin: 1rem auto; }
.cal-note { background: #e6f7ec; border: 1px solid #2d7d46; padding: .5rem .8rem; border-radius: 4px; margin-bottom: 1rem; }
.cal-error { background: #fdecea; border: 1px solid #c0392b; padding: .5rem .8rem; border-radius: 4px; margin-bottom: 1rem; }

/* Full form section */
.cal-full-form { border: 1px solid #e2e2e2; border-radius: 6px; padding: 1rem 1.25rem; margin-top: 1.5rem; background: #fafafa; }
.cal-full-form h2 { margin-top: 0; }
.cal-tz { color: #666; font-size: .85rem; }

/* Recurrence section */
.cal-recurrence-section { margin-top: .75rem; border-top: 1px solid #e8e8e8; padding-top: .75rem; }
.cal-rec-desc { font-size: .85rem; color: #555; font-style: italic; margin-top: .25rem; min-height: 1.2em; }

/* Scope modal */
.cal-scope-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    z-index: 8000; display: flex; align-items: center; justify-content: center;
}
.cal-scope-modal {
    background: #fff; border-radius: 8px;
    box-shadow: 0 8px 28px rgba(0,0,0,.2); padding: 1.5rem 1.75rem;
    min-width: 300px; max-width: 420px; width: 90%;
}
.cal-scope-modal h3 { margin: 0 0 1rem; font-size: 1.05rem; }
.cal-scope-options { display: flex; flex-direction: column; gap: .5rem; margin-bottom: 1.25rem; }
.cal-scope-options label { display: flex; align-items: center; gap: .6rem; font-size: .95rem; cursor: pointer; }
.cal-scope-options label small { color: #666; display: block; font-size: .82rem; }
.cal-scope-actions { display: flex; justify-content: flex-end; gap: .5rem; }

/* Floating popover */
.cal-popup {
    position: fixed; z-index: 9000; width: 340px;
    background: #fff; border-radius: 8px;
    box-shadow: 0 8px 28px rgba(0,0,0,.18), 0 2px 8px rgba(0,0,0,.10);
    display: none;
}
.cal-popup-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: .6rem .8rem .4rem; border-bottom: 1px solid #eee;
}
.cal-popup-title { font-weight: 600; font-size: .9rem; color: #444; }
.cal-popup-close {
    background: none; border: none; cursor: pointer;
    font-size: 1.2rem; color: #888; padding: .1rem .3rem; border-radius: 4px;
}
.cal-popup-close:hover { background: #f0f0f0; color: #333; }
.cal-popup-body { padding: .5rem .8rem; overflow-y: auto; max-height: calc(100vh - 190px); }
.cal-popup-body .form-group { margin-bottom: .6rem; }
.cal-popup-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: .6rem .8rem .8rem; border-top: 1px solid #eee; gap: .4rem;
}
.cal-popup-more {
    background: none; border: none; cursor: pointer;
    color: #555; font: inherit; font-size: .85rem; padding: .3rem .5rem;
    border-radius: 4px; text-decoration: none;
}
.cal-popup-more:hover { background: #f0f0f0; }
.cal-popup-right { display: flex; gap: .4rem; margin-left: auto; }
.cal-popup-delete {
    background: none; border: 1px solid #d9534f; color: #d9534f;
    border-radius: 4px; padding: .3rem .7rem; cursor: pointer; font: inherit; font-size: .85rem;
}
.cal-popup-delete:hover { background: #fdecea; }
.cal-popup-save {
    background: #2563eb; color: #fff; border: none;
    border-radius: 4px; padding: .3rem .9rem; cursor: pointer; font: inherit; font-size: .85rem; font-weight: 600;
}
.cal-popup-save:hover { background: #1d4ed8; }
.cal-popup-error {
    background: #fdecea; border: 1px solid #c0392b;
    padding: .3rem .6rem; border-radius: 4px; font-size: .82rem;
    margin: 0 .8rem .4rem; display: none;
}
.cal-pop-times.is-allday { display: none; }
.cal-pop-time-row { display: flex; gap: .6rem; }
.cal-pop-time-row .form-group { flex: 1; }
.cal-pop-time-input { width: 100%; box-sizing: border-box; }
</style>

<div class="cal-wrap">
<?php if (!empty($saved)):  ?><div class="cal-note">Entry saved.</div><?php endif; ?>
<?php if (!empty($deleted)): ?><div class="cal-note">Entry deleted.</div><?php endif; ?>
<?php if (!empty($errors)):  ?><div class="cal-error"><?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?></div><?php endif; ?>

<?php
echo ComponentRenderer::render(null, 'calendar_grid', [
    'view'         => 'month',
    'feed_url'     => '/ajax/calendar_feed',
    'initial_date' => $grid_initial,
]);
?>

<?php if ($show_full_form): ?>
    <?php if ($show_scope_modal): ?>
    <!-- Scope-choice modal (shown before the edit form for recurring occurrences) -->
    <div class="cal-scope-backdrop" id="cal-scope-backdrop">
        <div class="cal-scope-modal">
            <h3>Edit recurring entry</h3>
            <div class="cal-scope-options">
                <label>
                    <input type="radio" name="scope_choice" value="this" checked>
                    <span>This occurrence only
                        <small>Change just <?php echo htmlspecialchars($occurrence_date); ?>; other occurrences stay the same.</small>
                    </span>
                </label>
                <label>
                    <input type="radio" name="scope_choice" value="future">
                    <span>This and future occurrences
                        <small>Start a new series from <?php echo htmlspecialchars($occurrence_date); ?>.</small>
                    </span>
                </label>
                <label>
                    <input type="radio" name="scope_choice" value="all">
                    <span>All occurrences
                        <small>Update every occurrence in this series.</small>
                    </span>
                </label>
            </div>
            <div class="cal-scope-actions">
                <a href="/profile/calendar" style="padding:.4rem .8rem;border-radius:4px;background:#f0f0f0;color:#333;text-decoration:none;font-size:.9rem;">Cancel</a>
                <button type="button" id="cal-scope-ok" style="padding:.4rem .9rem;border-radius:4px;background:#2563eb;color:#fff;border:none;cursor:pointer;font-size:.9rem;font-weight:600;">Edit</button>
            </div>
        </div>
    </div>
    <!-- form is hidden until scope is chosen -->
    <div id="cal-full-form-wrap" style="display:none">
    <?php else: ?>
    <div id="cal-full-form-wrap">
    <?php endif; ?>

    <div class="cal-full-form" id="cal-full-form">
        <h2>
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
            <p class="cal-rec-desc" style="font-style:normal;color:#555;"><?php echo htmlspecialchars($cur_rec_desc); ?></p>
        <?php endif; ?>
        <p class="cal-tz">Times are in your timezone (<?php echo htmlspecialchars($tz); ?>).</p>
<?php
$formwriter = $page->getFormWriter('form1', ['action' => '/profile/calendar']);
$formwriter->begin_form();
$formwriter->hiddeninput('save_entry', '', ['value' => '1']);
if ($is_edit)       { $formwriter->hiddeninput('entry_id',        '', ['value' => $display_entry->key]); }
if ($is_occurrence) { $formwriter->hiddeninput('occurrence_date', '', ['value' => $occurrence_date]); }
// scope is injected by JS after the modal choice (or defaults to 'all' for direct parent edit)
if ($is_occurrence) {
    // Will be filled by scope modal JS
    $formwriter->hiddeninput('scope', '', ['value' => '', 'id' => 'cal-scope-field']);
} elseif ($is_edit && $display_entry->is_recurring_parent()) {
    $formwriter->hiddeninput('scope', '', ['value' => 'all']);
}
$formwriter->textinput('entry_title', 'Title', ['value' => $is_edit ? $display_entry->get('cal_title') : '', 'placeholder' => 'e.g. Dentist, Focus time']);
$formwriter->dateinput('entry_date', 'Date', ['value' => $e_date]);
$formwriter->checkboxinput('entry_all_day', 'All day', [
    'value' => $is_edit ? (bool)$display_entry->get('cal_all_day') : false,
    'visibility_rules' => [
        'checked'   => ['hide' => ['entry_start', 'entry_end']],
        'unchecked' => ['show' => ['entry_start', 'entry_end']],
    ],
]);
$formwriter->dropinput('entry_start', 'Start', ['options' => $time_options, 'value' => $e_start]);
$formwriter->dropinput('entry_end',   'End',   ['options' => $time_options, 'value' => $e_end]);
$formwriter->checkboxinput('entry_blocks', 'Block this time (removes it from your booking availability)', ['value' => $is_edit ? (bool)$display_entry->get('cal_blocks_availability') : true]);
?>
        <?php
        // ── Recurrence: real FormWriter inputs, show/hide driven entirely by
        // declarative visibility_rules (no hand-rolled toggle JS). Thin
        // structural wrappers give each trigger a clean, non-overlapping target.
        //
        // Pre-fill from stored values:
        $rec_freq_val = $cur_rec_type ?: 'weekly';
        $monthly_mode = ($cur_rec_week !== null) ? 'week' : 'day';
        $rec_week_val = ($cur_rec_week !== null) ? (int)$cur_rec_week : 1;
        $ends_val     = $cur_rec_end ? 'date' : 'never';

        // Weekly day picker: saved list for weekly entries, else the entry date's DOW.
        if ($cur_rec_type === 'weekly' && $cur_rec_days !== null && $cur_rec_days !== '') {
            $weekly_checked = array_map('intval', explode(',', $cur_rec_days));
        } elseif ($e_date) {
            $weekly_checked = [(int)date('w', strtotime($e_date))];
        } else {
            $weekly_checked = [];
        }

        // Monthly weekday picker: stored single DOW digit, else the entry date's DOW.
        if ($cur_rec_type === 'monthly' && $cur_rec_week !== null && $cur_rec_days !== null && $cur_rec_days !== '') {
            $monthly_dow = (int)$cur_rec_days;
        } elseif ($e_date) {
            $monthly_dow = (int)date('w', strtotime($e_date));
        } else {
            $monthly_dow = 1;
        }

        $day_short = [0=>'Sun', 1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat'];
        $day_full  = [0=>'Sunday', 1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday'];

        $formwriter->checkboxinput('entry_repeats', 'Repeats', [
            'value' => (bool)$cur_rec_type,
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
            $formwriter->numberinput('rec_count', 'Number of occurrences', ['value' => 10, 'min' => 1, 'max' => 999]);
            ?>
        </div><!-- #rec_section -->
<?php
$formwriter->submitbutton('btn_save', $is_edit ? 'Save changes' : 'Add entry');
$formwriter->end_form();

if ($is_edit) {
    // Delete form (scope-aware for recurring entries)
    $delform = $page->getFormWriter('delform', ['action' => '/profile/calendar']);
    $delform->begin_form();
    $delform->hiddeninput('delete_entry', '', ['value' => '1']);
    $delform->hiddeninput('entry_id',     '', ['value' => $display_entry->key]);
    if ($is_occurrence) {
        $delform->hiddeninput('occurrence_date', '', ['value' => $occurrence_date]);
        // scope injected by the delete scope modal JS
        $delform->hiddeninput('scope', '', ['value' => 'all', 'id' => 'del-scope-field']);
    } elseif ($display_entry->is_recurring_parent()) {
        $delform->hiddeninput('scope', '', ['value' => 'all', 'id' => 'del-scope-field']);
    }
    if ($display_entry->is_recurring_parent() || $is_occurrence) {
        $delform->submitbutton('btn_delete', 'Delete…', ['id' => 'btn-delete-rec']);
    } else {
        $delform->submitbutton('btn_delete', 'Delete entry');
    }
    $delform->end_form();
}
?>
    </div><!-- .cal-full-form -->
    </div><!-- #cal-full-form-wrap -->

    <?php if (($is_edit && $display_entry->is_recurring_parent()) || $is_occurrence): ?>
    <!-- Delete-scope modal for recurring entries -->
    <div id="del-scope-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:8000;align-items:center;justify-content:center;">
        <div class="cal-scope-modal">
            <h3>Delete recurring entry</h3>
            <div class="cal-scope-options">
                <?php if ($is_occurrence): ?>
                <label>
                    <input type="radio" name="del_scope_choice" value="this" checked>
                    <span>This occurrence only</span>
                </label>
                <label>
                    <input type="radio" name="del_scope_choice" value="future">
                    <span>This and future occurrences</span>
                </label>
                <?php endif; ?>
                <label>
                    <input type="radio" name="del_scope_choice" value="all" <?php echo !$is_occurrence ? 'checked' : ''; ?>>
                    <span>All occurrences</span>
                </label>
            </div>
            <div class="cal-scope-actions">
                <button type="button" id="del-scope-cancel" style="padding:.4rem .8rem;border-radius:4px;background:#f0f0f0;color:#333;border:none;cursor:pointer;font-size:.9rem;">Cancel</button>
                <button type="button" id="del-scope-ok" style="padding:.4rem .9rem;border-radius:4px;background:#d9534f;color:#fff;border:none;cursor:pointer;font-size:.9rem;font-weight:600;">Delete</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endif; // $show_full_form ?>
</div><!-- .cal-wrap -->

<!-- Popover (fixed overlay; JS positions it near the clicked cell or chip) -->
<div id="cal-popup" class="cal-popup" role="dialog" aria-modal="true" aria-label="Calendar entry">
    <div class="cal-popup-header">
        <span class="cal-popup-title" id="cal-popup-title">New entry</span>
        <button type="button" class="cal-popup-close" id="cal-popup-close" aria-label="Close">&#215;</button>
    </div>
    <div class="cal-popup-error" id="cal-popup-error"></div>
<?php
$popwriter = $page->getFormWriter('cal-pop-form', ['action' => '/ajax/calendar_entry_quick_save']);
$popwriter->begin_form();
$popwriter->hiddeninput('action',   '', ['value' => 'save']);
$popwriter->hiddeninput('entry_id', '', ['value' => '']);
?>
    <div class="cal-popup-body">
<?php
$popwriter->textinput('entry_title', 'Title', ['placeholder' => 'Add title']);
$popwriter->dateinput('entry_date', 'Date', []);
$popwriter->checkboxinput('entry_all_day', 'All day', ['value' => true]);
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
$popwriter->checkboxinput('entry_blocks', 'Block this time (removes from booking availability)', ['value' => true]);
?>
    </div>
    <div class="cal-popup-footer">
        <button type="button" class="cal-popup-more" id="cal-popup-more">More options</button>
        <div class="cal-popup-right">
            <button type="button" class="cal-popup-delete" id="cal-popup-delete" style="display:none">Delete</button>
            <button type="submit" class="cal-popup-save">Save</button>
        </div>
    </div>
<?php $popwriter->end_form(); ?>
</div>

<script>
(function(){
    var USER_TZ = <?php echo json_encode($tz); ?>;

    // =========================================================================
    // Scope modal (edit)
    // =========================================================================
    var scopeBackdrop = document.getElementById('cal-scope-backdrop');
    var scopeOkBtn    = document.getElementById('cal-scope-ok');
    var scopeField    = document.getElementById('cal-scope-field');
    var formWrap      = document.getElementById('cal-full-form-wrap');

    if (scopeBackdrop && scopeOkBtn && scopeField && formWrap) {
        scopeOkBtn.addEventListener('click', function(){
            var chosen = document.querySelector('input[name="scope_choice"]:checked');
            if (!chosen) return;
            scopeField.value = chosen.value;
            scopeBackdrop.style.display = 'none';
            formWrap.style.display = '';
            // Scroll to the form.
            var form = document.getElementById('cal-full-form');
            if (form) { form.scrollIntoView({behavior: 'smooth', block: 'start'}); }
        });
    }

    // =========================================================================
    // Delete-scope modal (for recurring entries)
    // =========================================================================
    var delBackdrop  = document.getElementById('del-scope-backdrop');
    var delOkBtn     = document.getElementById('del-scope-ok');
    var delCancelBtn = document.getElementById('del-scope-cancel');
    var delScopeField= document.getElementById('del-scope-field');
    var delRecBtn    = document.getElementById('btn-delete-rec');

    if (delRecBtn && delBackdrop) {
        delRecBtn.addEventListener('click', function(ev){
            ev.preventDefault();
            delBackdrop.style.display = 'flex';
        });
        if (delCancelBtn) {
            delCancelBtn.addEventListener('click', function(){
                delBackdrop.style.display = 'none';
            });
        }
        if (delOkBtn && delScopeField) {
            delOkBtn.addEventListener('click', function(){
                var chosen = document.querySelector('input[name="del_scope_choice"]:checked');
                if (!chosen) return;
                delScopeField.value = chosen.value;
                delBackdrop.style.display = 'none';
                // Submit the delete form.
                var delForm = document.getElementById('delform');
                if (delForm) { delForm.submit(); }
            });
        }
    }

    // The full-form all-day toggle and the entire recurrence section are now
    // declarative FormWriter inputs driven by visibility_rules — no toggle JS,
    // and "after N occurrences" is converted to an end date server-side
    // (CalendarEntry::nth_occurrence_date). The popover below is a separate
    // surface and keeps its own lightweight all-day handling.
    // =========================================================================
    // Popover (quick entry)
    // =========================================================================
    var popup    = document.getElementById('cal-popup');
    var titleEl  = document.getElementById('cal-popup-title');
    var errEl    = document.getElementById('cal-popup-error');
    var deleteBtn= document.getElementById('cal-popup-delete');
    var moreBtn  = document.getElementById('cal-popup-more');
    var closeBtn = document.getElementById('cal-popup-close');
    var form     = document.getElementById('cal-pop-form');
    var timesEl  = document.getElementById('cal-pop-times');
    var allDayEl = form ? form.querySelector('[name="entry_all_day"]') : null;

    if (form) { form.submit = function(){}; }

    function parseUTCDate(s){ return s ? new Date(String(s).replace(' ','T')+'Z') : null; }

    function utcToUserTz(d){
        var fmt = new Intl.DateTimeFormat('en-CA', {
            timeZone: USER_TZ,
            year:'numeric',month:'2-digit',day:'2-digit',
            hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false
        });
        var parts = fmt.formatToParts(d), p = {};
        parts.forEach(function(x){ p[x.type] = x.value; });
        var h = p.hour === '24' ? '00' : p.hour;
        return {date:p.year+'-'+p.month+'-'+p.day, time:h+':'+p.minute+':'+p.second};
    }

    function positionPopup(rect){
        popup.style.visibility = 'hidden'; popup.style.display = 'block';
        var pw=popup.offsetWidth, ph=popup.offsetHeight, vpW=window.innerWidth, vpH=window.innerHeight;
        var left=rect.left, top=rect.bottom+8;
        if(left+pw>vpW-8) left=vpW-pw-8; if(left<8) left=8;
        if(top+ph>vpH-8) top=Math.max(8,rect.top-ph-8); if(top<8) top=8;
        popup.style.left=left+'px'; popup.style.top=top+'px'; popup.style.visibility='visible';
    }

    function syncAllDay(){
        if(!allDayEl||!timesEl) return;
        timesEl.classList.toggle('is-allday', allDayEl.checked);
    }

    function setField(name,val){
        var el=form?form.querySelector('[name="'+name+'"]'):null;
        if(!el) return;
        if(el.type==='checkbox'){ el.checked=!!val; } else { el.value=val; }
    }
    function getField(name){
        var el=form?form.querySelector('[name="'+name+'"]'):null;
        if(!el) return '';
        if(el.type==='checkbox') return el.checked?'1':'';
        return el.value;
    }
    function showError(msg){ errEl.textContent=msg; errEl.style.display='block'; }
    function clearError(){ errEl.textContent=''; errEl.style.display='none'; }

    function openPopup(opts, rect){
        clearError();
        titleEl.textContent = opts.isEdit ? 'Edit entry' : 'New entry';
        setField('action', 'save'); setField('entry_id', opts.entryId||'');
        setField('entry_title', opts.title||''); setField('entry_date', opts.date||'');
        setField('entry_all_day', opts.allDay!==false);
        setField('entry_start', opts.startTime||''); setField('entry_end', opts.endTime||'');
        setField('entry_blocks', opts.blocksAvail!==false);
        syncAllDay();
        deleteBtn.style.display = opts.isEdit ? '' : 'none';
        positionPopup(rect);
        var ti = form?form.querySelector('[name="entry_title"]'):null;
        if(ti){ ti.focus(); ti.select(); }
    }

    function closePopup(){ popup.style.display='none'; clearError(); }

    if(!popup||!form) return;

    if(allDayEl) allDayEl.addEventListener('change', syncAllDay);
    closeBtn.addEventListener('click', closePopup);
    document.addEventListener('mousedown', function(ev){
        if(popup.style.display==='block'&&!popup.contains(ev.target)) closePopup();
    });
    document.addEventListener('keydown', function(ev){ if(ev.key==='Escape') closePopup(); });

    form.addEventListener('submit', function(ev){
        ev.preventDefault();
        var parsed = titleInputEl ? parseTimePrefix(titleInputEl.value.trim()) : null;
        if(parsed) applyParsedTime(parsed);
        clearError();
        var saveBtn=form.querySelector('.cal-popup-save');
        if(saveBtn){ saveBtn.disabled=true; saveBtn.textContent='Saving…'; }
        var data=new FormData(form);
        data.set('action','save');
        if(parsed){
            data.set('entry_title',parsed.title);
            data.set('entry_start',pad2(parsed.h)+':'+pad2(parsed.min));
            data.set('entry_end',  pad2(parsed.h+1<24?parsed.h+1:23)+':'+pad2(parsed.h+1<24?parsed.min:59));
            data.delete('entry_all_day');
        }
        fetch('/ajax/calendar_entry_quick_save',{method:'POST',body:data,credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(j){
            if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save';}
            if(j.ok){ closePopup(); window.dispatchEvent(new Event('calendarentrychanged')); }
            else { showError(j.error||'Save failed. Please try again.'); }
        })
        .catch(function(){
            if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save';}
            showError('Network error. Please try again.');
        });
    });

    deleteBtn.addEventListener('click', function(){
        var eid=getField('entry_id');
        if(!eid) return;
        JoineryModal.confirm('Delete this entry?', function(){
            var data=new FormData();
            data.set('action','delete'); data.set('entry_id',eid);
            fetch('/ajax/calendar_entry_quick_save',{method:'POST',body:data,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(j){
                if(j.ok){ closePopup(); window.dispatchEvent(new Event('calendarentrychanged')); }
                else { showError(j.error||'Delete failed.'); }
            })
            .catch(function(){ showError('Network error.'); });
        },{confirmLabel:'Delete'});
    });

    moreBtn.addEventListener('click', function(){
        var eid  = getField('entry_id');
        var date = getField('entry_date');
        window.location.href = eid
            ? '/profile/calendar?edit_entry=' + encodeURIComponent(eid)
            : '/profile/calendar?d=' + encodeURIComponent(date);
    });

    // Day cell click → create popover
    document.addEventListener('calendardayclick', function(ev){
        openPopup({isEdit:false, date:ev.detail.date, allDay:true, blocksAvail:true}, ev.detail.targetRect);
    });

    // Native chip click — recurring occurrences navigate directly; standalone opens popup.
    document.addEventListener('calendarchipclick', function(ev){
        var it = ev.detail.item || {};
        // Detect recurring occurrence URL pattern.
        if (it.url && it.url.match(/\/profile\/calendar\/entry\/\d+\/occurrence\//)) {
            window.location.href = it.url;
            return;
        }
        var entryId = '';
        if(it.url){ var m=it.url.match(/edit_entry=(\d+)/); if(m) entryId=m[1]; }
        var startDate=parseUTCDate(it.start), endDate=parseUTCDate(it.end);
        var startTz=startDate?utcToUserTz(startDate):null, endTz=endDate?utcToUserTz(endDate):null;
        openPopup({
            isEdit:true, entryId:entryId, title:it.title||'',
            date:startTz?startTz.date:'', allDay:!!it.all_day,
            startTime:(!it.all_day&&startTz)?startTz.time.slice(0,5):'',
            endTime:  (!it.all_day&&endTz)  ?endTz.time.slice(0,5)  :'',
            blocksAvail:it.blocks_availability!==false
        }, ev.detail.targetRect);
    });

    function parseTimePrefix(str){
        var m=str.match(/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s+(.+)$/i);
        if(m){ var h=parseInt(m[1],10),min=m[2]?parseInt(m[2],10):0,ap=m[3].toLowerCase();
               if(ap==='pm'&&h!==12)h+=12; if(ap==='am'&&h===12)h=0;
               if(h>23||min>59) return null; return{h:h,min:min,title:m[4].trim()}; }
        var m2=str.match(/^([01]?\d|2[0-3]):([0-5]\d)\s+(.+)$/);
        if(m2) return{h:parseInt(m2[1],10),min:parseInt(m2[2],10),title:m2[3].trim()};
        return null;
    }
    function pad2(n){ return String(n).padStart(2,'0'); }
    function applyParsedTime(parsed){
        var eh=parsed.h+1,em=parsed.min; if(eh>=24){eh=23;em=59;}
        setField('entry_title',parsed.title); setField('entry_start',pad2(parsed.h)+':'+pad2(parsed.min));
        setField('entry_end',  pad2(eh)+':'+pad2(em)); setField('entry_all_day',false); syncAllDay();
    }

    var titleInputEl = form ? form.querySelector('[name="entry_title"]') : null;

})();
</script>
<?php
echo PublicPage::EndPage();
$page->public_footer(['track' => true]);
?>
