<?php
/** @joinery-admin level=10 */
// Content packs: upload a pack, read what it would change, tick and apply.
// specs/content_pack_feature.md.
// @version 1.0

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_content_packs_logic.php'));

$page_vars = process_logic(admin_content_packs_logic(array_merge($_GET, $_POST)));

$session = $page_vars['session'];
$apply = $page_vars['apply'];
$plan = $page_vars['plan'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'system-content-packs',
	'page_title' => 'Content Packs',
	'readable_title' => 'Content Packs',
	'breadcrumbs' => $apply
		? array('Content Packs' => '/admin/admin_content_packs', htmlspecialchars((string)$apply->get('cpa_pack_name')) => '')
		: array('Content Packs' => ''),
	'session' => $session,
));

$e = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };

$state_badge = function ($state) {
	$badges = array(
		ContentPack::STATE_NEW => '<span class="badge bg-primary">new</span>',
		ContentPack::STATE_CHANGE => '<span class="badge bg-info">change</span>',
		ContentPack::STATE_SAME => '<span class="badge bg-secondary">same</span>',
		ContentPack::STATE_EDITED => '<span class="badge bg-warning">edited here</span>',
	);
	return $badges[$state] ?? '';
};

// A value in a table cell: short ones whole, long ones folded with a diff.
$show_value = function ($current, $new, $which) use ($e) {
	$value = $which === 'current' ? $current : $new;
	if ($value === null) {
		return '<em>empty</em>';
	}
	$long = strlen($value) > 160 || strpos($value, "\n") !== false;
	if (!$long) {
		return '<code>' . $e($value) . '</code>';
	}
	$out = '<details><summary>' . $e(mb_substr(str_replace("\n", ' ', $value), 0, 80)) . '&hellip;</summary>';
	if ($which === 'new' && $current !== null) {
		$out .= '<pre class="small" style="white-space:pre-wrap;max-height:28em;overflow:auto">';
		foreach (ContentPack::line_diff($current, $new) as $pair) {
			list($op, $line) = $pair;
			$style = $op === '+' ? 'background:rgba(40,167,69,.15)' : ($op === '-' ? 'background:rgba(220,53,69,.15)' : '');
			$out .= '<span style="' . $style . '">' . $e($op . ' ' . $line) . "</span>\n";
		}
		$out .= '</pre>';
	} else {
		$out .= '<pre class="small" style="white-space:pre-wrap;max-height:28em;overflow:auto">' . $e($value) . '</pre>';
	}
	return $out . '</details>';
};

if (!$apply) {
	// ------------------------------------------------------------------
	// Upload, and the history
	// ------------------------------------------------------------------
	echo '<div class="row"><div class="col-12">';
	echo '<p>A content pack is a zip of <code>pack.json</code> and the text files it names. It says what some records '
		. 'and settings should hold. Checking it changes nothing: you see every field it would change, side by side '
		. 'with what this site holds, and tick what to apply.</p>';

	$formwriter = $page->getFormWriter('content_pack_upload', array('enctype' => 'multipart/form-data'));
	$formwriter->begin_form();
	$formwriter->hiddeninput('action', '', array('value' => 'check', 'id' => 'upload_action'));
	$formwriter->fileinput('pack', 'Content pack (.zip)', array('accept' => '.zip', 'required' => true,
		'helptext' => 'At most 2 MB.'));
	$formwriter->submitbutton('check_submit', 'Check');
	$formwriter->end_form();
	echo '</div></div>';

	$headers = array('Pack', 'Version', 'Status', 'Checked', 'Applied');
	$page->tableheader($headers, array('title' => 'Checked and applied'));
	foreach ($page_vars['history'] as $row) {
		$page->disprow(array(
			'<a href="/admin/admin_content_packs?cpa_id=' . (int)$row['apply_id'] . '">' . $e($row['pack']) . '</a>',
			$e($row['version']),
			$e($row['status']),
			$e(LibraryFunctions::convert_time($row['checked'], 'UTC', $session->get_timezone())),
			$row['applied'] ? $e(LibraryFunctions::convert_time($row['applied'], 'UTC', $session->get_timezone())) : '',
		));
	}
	$page->endtable();
	$page->admin_footer();
	return;
}

// ----------------------------------------------------------------------
// One upload: its plan, or what it did
// ----------------------------------------------------------------------
$status = (string)$apply->get('cpa_status');
$checked = ($status === ContentPackApply::STATUS_CHECKED);

echo '<div class="row"><div class="col-12">';
echo '<p><strong>' . $e($apply->get('cpa_pack_name')) . '</strong>'
	. ($apply->get('cpa_pack_version') !== '' ? ' version ' . $e($apply->get('cpa_pack_version')) : '')
	. ' &middot; ' . $e($status)
	. ' &middot; file sha256 <code>' . $e(substr((string)$apply->get('cpa_file_sha256'), 0, 16)) . '</code></p>';

if ($page_vars['plan_error']) {
	echo '<div class="alert alert-danger">This pack is refused on the site as it is now: ' . $e($page_vars['plan_error']) . '</div>';
}
if ($status === ContentPackApply::STATUS_FAILED) {
	echo '<div class="alert alert-danger">Nothing was applied. ' . $e($apply->get('cpa_error')) . '</div>';
}

if ($plan) {
	if (!empty($plan['description'])) {
		echo '<p>' . $e($plan['description']) . '</p>';
	}
	if (!empty($plan['notes'])) {
		echo '<div class="alert alert-info" style="white-space:pre-wrap">' . $e($plan['notes']) . '</div>';
	}
}

if ($status === ContentPackApply::STATUS_APPLIED) {
	$written = json_decode((string)$apply->get('cpa_result'), true) ?: array();
	echo '<h3>What it wrote</h3>';
	if (!$written) {
		echo '<p>Nothing needed writing.</p>';
	}
	foreach ($written as $row) {
		$page->tableheader(array('Field', 'Before', 'After'), array('title' => $row['label'] . ($row['created'] ? ' (created)' : '')));
		foreach ($row['after'] as $field => $after) {
			$before = $row['before'][$field] ?? null;
			$page->disprow(array('<code>' . $e($field) . '</code>',
				$row['created'] ? '' : $show_value($before, $after, 'current'), $show_value($before, $after, 'new')));
		}
		$page->endtable();
	}
	echo '<p><a href="/admin/admin_content_packs">Back to content packs</a></p>';
	echo '</div></div>';
	$page->admin_footer();
	return;
}

if (!$plan || !$checked) {
	echo '<p><a href="/admin/admin_content_packs">Back to content packs</a></p></div></div>';
	$page->admin_footer();
	return;
}

echo '<p>Ticked lines are applied, in one go. <strong>Edited here</strong> means this site holds a value no pack put '
	. 'there, usually an edit made in the admin: it starts unticked so it is not overwritten unless you mean to. '
	. 'Lines marked <em>same</em> are already right.</p>';

$formwriter = $page->getFormWriter('content_pack_apply');
$formwriter->begin_form();
$formwriter->hiddeninput('action', '', array('value' => 'apply', 'id' => 'apply_action'));
$formwriter->hiddeninput('cpa_id', '', array('value' => (int)$apply->key));
$formwriter->hiddeninput('plan_hash', '', array('value' => $plan['hash']));

$anything = false;
foreach ($plan['items'] as $item) {
	$options = array();
	$checked_ticks = array();
	// Table titles are plain text (the table escapes them); notes go above.
	$notes = array();
	if (!empty($item['needs'])) {
		$notes[] = 'Applies only when the new record it points at is created.';
	}

	if ($item['kind'] === 'record') {
		if ($item['key'] === null) {
			$notes[] = 'Not on this site yet: it will be created.';
		}
		if ($item['via'] === 'match_also') {
			$notes[] = 'Found by an earlier name or link; applying gives it the pack\'s.';
		}
		if ($notes) {
			echo '<p class="mb-1"><em>' . $e(implode(' ', $notes)) . '</em></p>';
		}
		$page->tableheader(array('Field', 'State', 'On this site', 'In the pack'), array('title' => $item['label']));
		foreach ($item['lines'] as $line) {
			$label = '<code>' . $e($line['field']) . '</code>' . ($line['part'] === 'on_create' ? ' <em>(only when created)</em>' : '');
			$page->disprow(array($label, $state_badge($line['state']),
				$item['key'] === null ? '' : $show_value($line['current'], $line['new'], 'current'),
				$show_value($line['current'], $line['new'], 'new')));
			if (!empty($line['tick'])) {
				$options[$line['tick']] = $line['field'] . ' (' . ($line['state'] === ContentPack::STATE_EDITED ? 'edited here' : $line['state']) . ')';
				if (!empty($line['default'])) {
					$checked_ticks[] = $line['tick'];
				}
			}
		}
		$page->endtable();
		if ($item['key'] === null) {
			$options[$item['tick']] = 'Create this ' . $item['model'];
			$checked_ticks[] = $item['tick'];
		}
	} elseif ($item['kind'] === 'unlisted') {
		if (!$item['records']) {
			continue;
		}
		if ($notes) {
			echo '<p class="mb-1"><em>' . $e(implode(' ', $notes)) . '</em></p>';
		}
		$page->tableheader(array('Row', 'Field', 'On this site', 'In the pack'), array('title' => $item['label']));
		foreach ($item['records'] as $record) {
			foreach ($record['lines'] as $line) {
				$page->disprow(array($e($record['label']), '<code>' . $e($line['field']) . '</code>',
					$show_value($line['current'], $line['new'], 'current'), $show_value($line['current'], $line['new'], 'new')));
			}
			$options[$record['tick']] = 'Change ' . $record['label'];
			$checked_ticks[] = $record['tick'];
		}
		$page->endtable();
	} elseif ($item['kind'] === 'setting') {
		if ($notes) {
			echo '<p class="mb-1"><em>' . $e(implode(' ', $notes)) . '</em></p>';
		}
		$page->tableheader(array('Setting', 'State', 'On this site', 'In the pack'), array('title' => $item['label']));
		$page->disprow(array('<code>' . $e($item['name']) . '</code>', $state_badge($item['state']),
			$show_value($item['current'], $item['new'], 'current'), $show_value($item['current'], $item['new'], 'new')));
		$page->endtable();
		if (!empty($item['tick'])) {
			$options[$item['tick']] = $item['name'];
			if (!empty($item['default'])) {
				$checked_ticks[] = $item['tick'];
			}
		}
	}

	if ($options) {
		$anything = true;
		$formwriter->checkboxList('ticks', 'Apply', array(
			'options' => $options,
			'checked' => $checked_ticks,
			'id' => 'ticks_' . (int)$item['index'],
		));
	}
}

if ($anything) {
	$formwriter->submitbutton('apply_submit', 'Apply ticked lines');
} else {
	echo '<p><strong>Everything is already as the pack says.</strong> Applying records that, so a later version of the pack can tell its own values from edits made here.</p>';
	$formwriter->submitbutton('apply_submit', 'Record as applied');
}
$formwriter->end_form();
echo '<p><a href="/admin/admin_content_packs">Back to content packs</a></p>';
echo '</div></div>';

$page->admin_footer();
