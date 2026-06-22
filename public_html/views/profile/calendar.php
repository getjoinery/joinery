<?php
/**
 * Personal calendar — the home surface where a subject's events, bookings, and
 * native entries appear on one timeline. Click an empty day or an existing
 * native entry chip to open the quick-entry popover. "More options" opens the
 * full form (shown below the grid when ?edit_entry or ?d are in the URL).
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('logic/calendar_logic.php'));

$page_vars = process_logic(calendar_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$tz = $timezone;
$is_edit = (bool)$entry->key;

// Show the full form section when navigating via "More options" or a direct edit link.
$show_full_form = $is_edit || isset($_GET['d']);

// Pre-fill values for the full form (UTC -> owner-local).
$e_date  = $is_edit ? LibraryFunctions::convert_time($entry->get('cal_start_utc'), 'UTC', $tz, 'Y-m-d') : (isset($_GET['d']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d']) ? $_GET['d'] : '');
$e_start = $is_edit ? LibraryFunctions::convert_time($entry->get('cal_start_utc'), 'UTC', $tz, 'H:i:s') : '';
$e_end   = $is_edit ? LibraryFunctions::convert_time($entry->get('cal_end_utc'), 'UTC', $tz, 'H:i:s') : '';

$time_options = ['' => '--'];
for ($m = 0; $m < 24 * 60; $m += 30) {
    $h = intdiv($m, 60); $min = $m % 60;
    $val = sprintf('%02d:%02d:00', $h, $min);
    $ampm = $h < 12 ? 'AM' : 'PM'; $h12 = $h % 12; if ($h12 === 0) { $h12 = 12; }
    $time_options[$val] = sprintf('%d:%02d %s', $h12, $min, $ampm);
}

$grid_initial = $e_date ?: (isset($_GET['d']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d']) ? $_GET['d'] : gmdate('Y-m-d'));

$page = new PublicPage();
$hoptions = array('is_valid_page' => true, 'title' => 'My Calendar', 'breadcrumbs' => array('My Profile' => '/profile', 'Calendar' => ''));
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('My Calendar', $hoptions);
?>
<style>
.cal-wrap { max-width: 980px; margin: 1rem auto; }
.cal-note { background: #e6f7ec; border: 1px solid #2d7d46; padding: .5rem .8rem; border-radius: 4px; margin-bottom: 1rem; }
.cal-error { background: #fdecea; border: 1px solid #c0392b; padding: .5rem .8rem; border-radius: 4px; margin-bottom: 1rem; }

/* Full form section (more-options / edit) */
.cal-full-form { border: 1px solid #e2e2e2; border-radius: 6px; padding: 1rem 1.25rem; margin-top: 1.5rem; background: #fafafa; }
.cal-full-form h2 { margin-top: 0; }
.cal-times.is-allday { opacity: .4; pointer-events: none; }
.cal-tz { color: #666; font-size: .85rem; }

/* Floating popover */
.cal-popup {
    position: fixed;
    z-index: 9000;
    width: 340px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 8px 28px rgba(0,0,0,.18), 0 2px 8px rgba(0,0,0,.10);
    display: none;
}
.cal-popup-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .6rem .8rem .4rem;
    border-bottom: 1px solid #eee;
}
.cal-popup-title { font-weight: 600; font-size: .9rem; color: #444; }
.cal-popup-close {
    background: none; border: none; cursor: pointer;
    font-size: 1.2rem; color: #888; padding: .1rem .3rem; border-radius: 4px;
}
.cal-popup-close:hover { background: #f0f0f0; color: #333; }
/* Body scrolls when the popup content is tall (e.g. time fields revealed after parsing) */
.cal-popup-body { padding: .5rem .8rem; overflow-y: auto; max-height: calc(100vh - 190px); }
.cal-popup-body .form-group { margin-bottom: .6rem; }
.cal-popup-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .6rem .8rem .8rem;
    border-top: 1px solid #eee;
    gap: .4rem;
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
<?php if (!empty($saved)): ?><div class="cal-note">Entry saved.</div><?php endif; ?>
<?php if (!empty($deleted)): ?><div class="cal-note">Entry deleted.</div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="cal-error"><?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?></div><?php endif; ?>

<?php
echo ComponentRenderer::render(null, 'calendar_grid', [
    'view'         => 'month',
    'feed_url'     => '/ajax/calendar_feed',
    'initial_date' => $grid_initial,
]);
?>

<?php if ($show_full_form): ?>
    <div class="cal-full-form" id="cal-full-form">
        <h2><?php echo $is_edit ? 'Edit entry' : 'Add an entry'; ?></h2>
        <p class="cal-tz">Times are in your timezone (<?php echo htmlspecialchars($tz); ?>).</p>
<?php
$formwriter = $page->getFormWriter('form1', ['action' => '/profile/calendar']);
$formwriter->begin_form();
$formwriter->hiddeninput('save_entry', '', ['value' => '1']);
if ($is_edit) { $formwriter->hiddeninput('entry_id', '', ['value' => $entry->key]); }
$formwriter->textinput('entry_title', 'Title', ['value' => $is_edit ? $entry->get('cal_title') : '', 'placeholder' => 'e.g. Dentist, Focus time']);
$formwriter->dateinput('entry_date', 'Date', ['value' => $e_date]);
$formwriter->checkboxinput('entry_all_day', 'All day', ['value' => $is_edit ? (bool)$entry->get('cal_all_day') : false]);
?>
        <div class="cal-times<?php echo ($is_edit && $entry->get('cal_all_day')) ? ' is-allday' : ''; ?>" id="cal-times">
<?php
$formwriter->dropinput('entry_start', 'Start', ['options' => $time_options, 'value' => $e_start]);
$formwriter->dropinput('entry_end', 'End', ['options' => $time_options, 'value' => $e_end]);
?>
        </div>
<?php
$formwriter->checkboxinput('entry_blocks', 'Block this time (removes it from your booking availability)', ['value' => $is_edit ? (bool)$entry->get('cal_blocks_availability') : true]);
$formwriter->submitbutton('btn_save', $is_edit ? 'Save changes' : 'Add entry');
$formwriter->end_form();

if ($is_edit) {
    $delform = $page->getFormWriter('delform', ['action' => '/profile/calendar']);
    $delform->begin_form();
    $delform->hiddeninput('delete_entry', '', ['value' => '1']);
    $delform->hiddeninput('entry_id', '', ['value' => $entry->key]);
    $delform->submitbutton('btn_delete', 'Delete entry');
    $delform->end_form();
}
?>
    </div>
<?php endif; ?>
</div>

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
$popwriter->hiddeninput('action', '', ['value' => 'save']);
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
    var popup  = document.getElementById('cal-popup');
    var titleEl = document.getElementById('cal-popup-title');
    var errEl   = document.getElementById('cal-popup-error');
    var deleteBtn = document.getElementById('cal-popup-delete');
    var moreBtn   = document.getElementById('cal-popup-more');
    var closeBtn  = document.getElementById('cal-popup-close');
    var form    = document.getElementById('cal-pop-form');
    var timesEl = document.getElementById('cal-pop-times');
    var allDayEl = form ? form.querySelector('[name="entry_all_day"]') : null;

    // joinery-validate.js intercepts the submit event and calls form.submit() after validation,
    // which would trigger a native page navigation that races against our AJAX fetch.
    // Override form.submit to a no-op so only our AJAX submit listener handles the data.
    if (form) { form.submit = function(){}; }
    var USER_TZ  = <?php echo json_encode($tz); ?>;

    function parseUTCDate(s){
        return s ? new Date(String(s).replace(' ', 'T') + 'Z') : null;
    }

    // Convert a UTC Date object to a {date: 'Y-m-d', time: 'H:i:s'} in the user's session timezone.
    function utcToUserTz(d){
        var fmt = new Intl.DateTimeFormat('en-CA', {
            timeZone: USER_TZ,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false
        });
        var parts = fmt.formatToParts(d), p = {};
        parts.forEach(function(x){ p[x.type] = x.value; });
        var h = p.hour === '24' ? '00' : p.hour;
        return { date: p.year + '-' + p.month + '-' + p.day, time: h + ':' + p.minute + ':' + p.second };
    }

    function positionPopup(targetRect){
        popup.style.visibility = 'hidden';
        popup.style.display = 'block';
        var pw = popup.offsetWidth, ph = popup.offsetHeight;
        var vpW = window.innerWidth, vpH = window.innerHeight;
        var left = targetRect.left;
        var top  = targetRect.bottom + 8;
        if (left + pw > vpW - 8) left = vpW - pw - 8;
        if (left < 8) left = 8;
        if (top + ph > vpH - 8) top = Math.max(8, targetRect.top - ph - 8);
        if (top < 8) top = 8;
        popup.style.left = left + 'px';
        popup.style.top  = top  + 'px';
        popup.style.visibility = 'visible';
    }

    function syncAllDayToggle(){
        if (!allDayEl || !timesEl) return;
        timesEl.classList.toggle('is-allday', allDayEl.checked);
    }

    function setField(name, value){
        var el = form ? form.querySelector('[name="' + name + '"]') : null;
        if (!el) return;
        if (el.type === 'checkbox') {
            el.checked = !!value;
        } else {
            el.value = value;
        }
    }

    function getField(name){
        var el = form ? form.querySelector('[name="' + name + '"]') : null;
        if (!el) return '';
        if (el.type === 'checkbox') return el.checked ? '1' : '';
        return el.value;
    }

    function showError(msg){
        errEl.textContent = msg;
        errEl.style.display = 'block';
    }

    function clearError(){
        errEl.textContent = '';
        errEl.style.display = 'none';
    }

    function openPopup(opts, targetRect){
        // opts: { isEdit, entryId, date, title, allDay, startTime, endTime, blocksAvail }
        clearError();
        titleEl.textContent = opts.isEdit ? 'Edit entry' : 'New entry';
        setField('action', 'save');
        setField('entry_id', opts.entryId || '');
        setField('entry_title', opts.title || '');
        setField('entry_date', opts.date || '');
        setField('entry_all_day', opts.allDay !== false);
        setField('entry_start', opts.startTime || '');
        setField('entry_end',   opts.endTime   || '');
        setField('entry_blocks', opts.blocksAvail !== false);
        syncAllDayToggle();
        deleteBtn.style.display = opts.isEdit ? '' : 'none';
        positionPopup(targetRect);
        var titleInput = form ? form.querySelector('[name="entry_title"]') : null;
        if (titleInput) { titleInput.focus(); titleInput.select(); }
    }

    function closePopup(){
        popup.style.display = 'none';
        clearError();
    }

    if (!popup || !form) return;

    // All-day toggle
    if (allDayEl) {
        allDayEl.addEventListener('change', syncAllDayToggle);
    }

    // Close button
    closeBtn.addEventListener('click', closePopup);

    // Click outside to close
    document.addEventListener('mousedown', function(ev){
        if (popup.style.display === 'block' && !popup.contains(ev.target)) {
            closePopup();
        }
    });

    // ESC to close
    document.addEventListener('keydown', function(ev){
        if (ev.key === 'Escape') closePopup();
    });

    // Form submit — AJAX save
    form.addEventListener('submit', function(ev){
        ev.preventDefault();
        // Parse time prefix from title at save time.
        var parsed = titleInputEl ? parseTimePrefix(titleInputEl.value.trim()) : null;
        if (parsed) applyParsedTime(parsed);
        clearError();
        var saveBtn = form.querySelector('.cal-popup-save');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

        var data = new FormData(form);
        data.set('action', 'save');
        if (parsed) {
            data.set('entry_title', parsed.title);
            data.set('entry_start', pad2(parsed.h) + ':' + pad2(parsed.min));
            data.set('entry_end',   pad2(parsed.h + 1 < 24 ? parsed.h + 1 : 23) + ':' + pad2(parsed.h + 1 < 24 ? parsed.min : 59));
            data.delete('entry_all_day');
        }

        fetch('/ajax/calendar_entry_quick_save', {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
            if (j.ok) {
                closePopup();
                window.dispatchEvent(new Event('calendarentrychanged'));
            } else {
                showError(j.error || 'Save failed. Please try again.');
            }
        })
        .catch(function(){
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
            showError('Network error. Please try again.');
        });
    });

    // Delete button — AJAX delete
    deleteBtn.addEventListener('click', function(){
        var eid = getField('entry_id');
        if (!eid) return;
        JoineryModal.confirm('Delete this entry?', function(){
            var data = new FormData();
            data.set('action', 'delete');
            data.set('entry_id', eid);
            fetch('/ajax/calendar_entry_quick_save', {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (j.ok) {
                    closePopup();
                    window.dispatchEvent(new Event('calendarentrychanged'));
                } else {
                    showError(j.error || 'Delete failed.');
                }
            })
            .catch(function(){ showError('Network error.'); });
        }, { confirmLabel: 'Delete' });
    });

    // "More options" — navigate to the full form
    moreBtn.addEventListener('click', function(){
        var eid  = getField('entry_id');
        var date = getField('entry_date');
        window.location.href = eid
            ? '/profile/calendar?edit_entry=' + encodeURIComponent(eid)
            : '/profile/calendar?d=' + encodeURIComponent(date);
    });

    // Day cell click → create popover
    document.addEventListener('calendardayclick', function(ev){
        openPopup({
            isEdit: false,
            date: ev.detail.date,
            allDay: true,
            blocksAvail: true
        }, ev.detail.targetRect);
    });

    // Native chip click → edit popover
    document.addEventListener('calendarchipclick', function(ev){
        var it = ev.detail.item || {};
        var entryId = '';
        if (it.url) {
            var m = it.url.match(/edit_entry=(\d+)/);
            if (m) entryId = m[1];
        }
        var startDate = parseUTCDate(it.start);
        var endDate   = parseUTCDate(it.end);
        var startTz = startDate ? utcToUserTz(startDate) : null;
        var endTz   = endDate   ? utcToUserTz(endDate)   : null;
        openPopup({
            isEdit: true,
            entryId: entryId,
            title: it.title || '',
            date: startTz ? startTz.date : '',
            allDay: !!it.all_day,
            startTime: (!it.all_day && startTz) ? startTz.time.slice(0, 5) : '',
            endTime:   (!it.all_day && endTz)   ? endTz.time.slice(0, 5)   : '',
            blocksAvail: it.blocks_availability !== false
        }, ev.detail.targetRect);
    });

    function parseTimePrefix(str) {
        // 12-hour: "3pm", "3:05pm", "3 pm", "3:05 pm" followed by a space + title
        var m = str.match(/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s+(.+)$/i);
        if (m) {
            var h = parseInt(m[1], 10), min = m[2] ? parseInt(m[2], 10) : 0, ampm = m[3].toLowerCase();
            if (ampm === 'pm' && h !== 12) h += 12;
            if (ampm === 'am' && h === 12) h = 0;
            if (h > 23 || min > 59) return null;
            return { h: h, min: min, title: m[4].trim() };
        }
        // 24-hour: "15:05 Standup" (colon required, no am/pm)
        var m2 = str.match(/^([01]?\d|2[0-3]):([0-5]\d)\s+(.+)$/);
        if (m2) {
            return { h: parseInt(m2[1], 10), min: parseInt(m2[2], 10), title: m2[3].trim() };
        }
        return null;
    }

    function pad2(n) { return String(n).padStart(2, '0'); }

    function applyParsedTime(parsed) {
        var endH = parsed.h + 1, endMin = parsed.min;
        if (endH >= 24) { endH = 23; endMin = 59; }
        setField('entry_title', parsed.title);
        setField('entry_start', pad2(parsed.h) + ':' + pad2(parsed.min));
        setField('entry_end',   pad2(endH)     + ':' + pad2(endMin));
        setField('entry_all_day', false);
        syncAllDayToggle();
    }

    var titleInputEl = form ? form.querySelector('[name="entry_title"]') : null;

    // All-day toggle in the full form (if shown)
    var fullAllDay = document.querySelector('[name="entry_all_day"]:not(#cal-pop-form [name="entry_all_day"])');
    var fullTimes  = document.getElementById('cal-times');
    if (fullAllDay && fullTimes) {
        fullAllDay.addEventListener('change', function(){
            fullTimes.classList.toggle('is-allday', fullAllDay.checked);
        });
    }
})();
</script>
<?php
echo PublicPage::EndPage();
$page->public_footer(array('track' => TRUE));
?>
