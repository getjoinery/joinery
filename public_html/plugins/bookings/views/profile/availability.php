<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/bookings/logic/availability_logic.php'));

$page_vars = process_logic(availability_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$session = SessionControl::get_instance();

// --- option lists ---
$day_options = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
$time_options = [];
for ($m = 0; $m < 24 * 60; $m += 30) {
    $h = intdiv($m, 60); $min = $m % 60;
    $val = sprintf('%02d:%02d:00', $h, $min);
    $ampm = $h < 12 ? 'AM' : 'PM';
    $h12 = $h % 12; if ($h12 === 0) { $h12 = 12; }
    $time_options[$val] = sprintf('%d:%02d %s', $h12, $min, $ampm);
}
$tz_options = [];
foreach (DateTimeZone::listIdentifiers() as $tz) { $tz_options[$tz] = $tz; }

/** Render a <select> of times; $selected is an HH:MM:SS value. */
function av_time_select($name, $selected, $time_options) {
    $h = '<select name="' . htmlspecialchars($name) . '">';
    $h .= '<option value="">--</option>';
    foreach ($time_options as $val => $label) {
        $sel = (substr((string)$selected, 0, 8) === $val) ? ' selected' : '';
        $h .= '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    return $h . '</select>';
}
function av_day_select($name, $selected, $day_options) {
    $h = '<select name="' . htmlspecialchars($name) . '">';
    foreach ($day_options as $val => $label) {
        $sel = ((string)$selected === (string)$val) ? ' selected' : '';
        $h .= '<option value="' . $val . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    return $h . '</select>';
}

$page = new PublicPage();
$hoptions = array(
    'is_valid_page' => $is_valid_page ?? true,
    'title' => 'Availability',
    'breadcrumbs' => array('My Profile' => '/profile', 'Availability' => ''),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Availability', $hoptions);
?>
<style>
.av-wrap { max-width: 760px; margin: 1.5rem auto; }
.av-wrap h2 { margin-top: 2rem; }
.av-row { display: flex; gap: .5rem; align-items: center; margin-bottom: .5rem; flex-wrap: wrap; }
.av-row select, .av-row input[type=date] { padding: .35rem; }
.av-remove { background: #c0392b; color: #fff; border: none; border-radius: 4px; padding: .35rem .6rem; cursor: pointer; }
.av-add { background: #2d7d46; color: #fff; border: none; border-radius: 4px; padding: .45rem .8rem; cursor: pointer; margin-top: .25rem; }
.av-saved { background: #e6f7ec; border: 1px solid #2d7d46; padding: .6rem .9rem; border-radius: 4px; margin-bottom: 1rem; }
.av-error { background: #fdecea; border: 1px solid #c0392b; padding: .6rem .9rem; border-radius: 4px; margin-bottom: 1rem; }
.av-muted { color: #666; }
.av-ovr-times.is-unavailable { opacity: .4; pointer-events: none; }
</style>

<div class="av-wrap">
<?php if (!empty($saved)): ?>
    <div class="av-saved">Availability saved.</div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="av-error"><?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?></div>
<?php endif; ?>

<?php
$formwriter = $page->getFormWriter('form1', ['action' => '/profile/bookings/availability']);
$formwriter->begin_form();
$formwriter->hiddeninput('save_availability', '', ['value' => '1']);
$formwriter->dropinput('sch_timezone', 'Timezone', [
    'options' => $tz_options,
    'value' => $schedule->get('sch_timezone'),
]);
?>

    <h2>Weekly hours</h2>
    <p class="av-muted">Add the times you're available each week. Overnight spans are entered as two windows on adjacent days.</p>
    <div id="av-windows">
<?php
$i = 0;
foreach ($windows as $w) {
    echo '<div class="av-row">';
    echo av_day_select("win_day[$i]", $w->get('scw_day_of_week'), $day_options);
    echo av_time_select("win_start[$i]", $w->get('scw_start_time'), $time_options);
    echo '<span>to</span>';
    echo av_time_select("win_end[$i]", $w->get('scw_end_time'), $time_options);
    echo '<button type="button" class="av-remove" onclick="this.parentNode.remove()">Remove</button>';
    echo '</div>';
    $i++;
}
?>
    </div>
    <button type="button" class="av-add" onclick="avAddWindow()">+ Add hours</button>

    <h2>Date overrides</h2>
    <p class="av-muted">Override a specific date — set different hours, or mark it fully unavailable. Overrides replace your weekly hours for that date.</p>
    <div id="av-overrides">
<?php
$j = 0;
foreach ($overrides as $o) {
    $is_unavail = (!$o->get('sco_start_time') && !$o->get('sco_end_time'));
    echo '<div class="av-row">';
    echo '<input type="date" name="ovr_date[' . $j . ']" value="' . htmlspecialchars(substr($o->get('sco_date'), 0, 10)) . '">';
    echo '<label><input type="checkbox" name="ovr_unavailable[' . $j . ']" value="1" onchange="avToggleOvr(this)"' . ($is_unavail ? ' checked' : '') . '> Unavailable</label>';
    echo '<span class="av-ovr-times' . ($is_unavail ? ' is-unavailable' : '') . '">';
    echo av_time_select("ovr_start[$j]", $o->get('sco_start_time'), $time_options);
    echo ' <span>to</span> ';
    echo av_time_select("ovr_end[$j]", $o->get('sco_end_time'), $time_options);
    echo '</span>';
    echo '<button type="button" class="av-remove" onclick="this.parentNode.remove()">Remove</button>';
    echo '</div>';
    $j++;
}
?>
    </div>
    <button type="button" class="av-add" onclick="avAddOverride()">+ Add override</button>

    <div style="margin-top:1.5rem;">
<?php $formwriter->submitbutton('btn_submit', 'Save availability'); ?>
    </div>
<?php $formwriter->end_form(); ?>

    <h2>Preview</h2>
    <p class="av-muted">Your open availability (green) against what's already on your calendar. Updates when you save.</p>
<?php
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
echo ComponentRenderer::render(null, 'calendar_grid', [
    'view' => 'week',
    'feed_url' => '/ajax/availability_preview',
]);
?>
</div>

<script>
// New rows use a high index base so they never collide with the server-rendered
// indices; parallel field arrays stay aligned by their explicit [n] keys.
var avIdx = 1000;
var AV_TIME_OPTIONS = <?php echo json_encode($time_options); ?>;
var AV_DAY_OPTIONS = <?php echo json_encode($day_options); ?>;

function avTimeSelect(name) {
    var h = '<select name="' + name + '"><option value="">--</option>';
    for (var v in AV_TIME_OPTIONS) { h += '<option value="' + v + '">' + AV_TIME_OPTIONS[v] + '</option>'; }
    return h + '</select>';
}
function avDaySelect(name) {
    var h = '<select name="' + name + '">';
    for (var v in AV_DAY_OPTIONS) { h += '<option value="' + v + '">' + AV_DAY_OPTIONS[v] + '</option>'; }
    return h + '</select>';
}
function avAddWindow() {
    var n = avIdx++;
    var div = document.createElement('div');
    div.className = 'av-row';
    div.innerHTML = avDaySelect('win_day[' + n + ']') + avTimeSelect('win_start[' + n + ']') +
        '<span>to</span>' + avTimeSelect('win_end[' + n + ']') +
        '<button type="button" class="av-remove" onclick="this.parentNode.remove()">Remove</button>';
    document.getElementById('av-windows').appendChild(div);
}
function avAddOverride() {
    var n = avIdx++;
    var div = document.createElement('div');
    div.className = 'av-row';
    div.innerHTML = '<input type="date" name="ovr_date[' + n + ']">' +
        '<label><input type="checkbox" name="ovr_unavailable[' + n + ']" value="1" onchange="avToggleOvr(this)"> Unavailable</label>' +
        '<span class="av-ovr-times">' + avTimeSelect('ovr_start[' + n + ']') + ' <span>to</span> ' + avTimeSelect('ovr_end[' + n + ']') + '</span>' +
        '<button type="button" class="av-remove" onclick="this.parentNode.remove()">Remove</button>';
    document.getElementById('av-overrides').appendChild(div);
}
function avToggleOvr(cb) {
    var times = cb.closest('.av-row').querySelector('.av-ovr-times');
    if (times) { times.classList.toggle('is-unavailable', cb.checked); }
}
</script>

<?php
echo PublicPage::EndPage();
$page->public_footer(array('track' => TRUE));
?>
