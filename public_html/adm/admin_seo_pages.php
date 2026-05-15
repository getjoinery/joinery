<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_seo_pages_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_seo_pages_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'settings-seo-pages',
	'breadcrumbs' => array(
		'Settings'  => '',
		'SEO Pages' => '',
	),
	'session' => $session,
));

if (!empty($notice)) {
	echo '<div class="alert alert-success" role="alert">' . htmlspecialchars($notice) . '</div>';
}
if (!empty($error)) {
	echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($error) . '</div>';
}

if ($view === 'orphans') {
	echo '<div style="margin-bottom: 12px;"><a class="btn btn-sm btn-secondary" href="/admin/admin_seo_pages">&larr; Back to SEO pages</a></div>';

	$page->begin_box(array(
		'title' => 'Orphan SEO rows',
	));
	echo '<p style="margin-bottom: 12px; color: #555;">';
	echo 'Rows that the bounded auto-cleanup pass deliberately leaves alone: static-path rows whose path no longer routes, and rows tagged with entity types outside the core enumeration loop. Review and soft-delete individually.';
	echo '</p>';

	if (empty($orphans)) {
		echo '<p><em>No orphan rows detected.</em></p>';
	} else {
		echo '<table class="styled-table"><thead><tr><th>Path</th><th>Entity Type</th><th>Reason</th><th>Action</th></tr></thead><tbody>';
		foreach ($orphans as $orphan) {
			echo '<tr>';
			echo '<td><code>' . htmlspecialchars($orphan['path']) . '</code></td>';
			echo '<td>' . htmlspecialchars($orphan['entity_type'] ?? 'static') . '</td>';
			echo '<td>' . htmlspecialchars($orphan['reason']) . '</td>';
			echo '<td>';
			echo '<form method="POST" action="/admin/admin_seo_pages" style="display:inline;">';
			echo '<input type="hidden" name="action" value="soft_delete">';
			echo '<input type="hidden" name="spm_id" value="' . (int)$orphan['id'] . '">';
			echo '<button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'Soft-delete this row?\');">Soft-delete</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	$page->end_box();
	$page->admin_footer();
	return;
}

// Action toolbar
echo '<div style="margin-bottom: 12px; display:flex; gap:8px; flex-wrap:wrap;">';
echo '<form method="POST" action="/admin/admin_seo_pages" style="display:inline;">';
echo '<input type="hidden" name="action" value="scan_now">';
echo '<button type="submit" class="btn btn-sm btn-primary" onclick="return confirm(\'Run full enumeration scan now? Idempotent.\');">Scan now</button>';
echo '</form>';
echo '<a class="btn btn-sm btn-secondary" href="/admin/admin_seo_pages?view=orphans">Find orphans</a>';
echo '<a class="btn btn-sm btn-secondary" href="/admin/admin_seo_page_edit">+ Add path</a>';
echo '</div>';

// Filter / search bar
echo '<form method="get" action="/admin/admin_seo_pages" style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
echo '<label>Filter: <select name="filter">';
$filter_opts = array('all'=>'All', 'overrides'=>'Has overrides', 'noindex'=>'Noindex only', 'static'=>'Static paths only');
foreach ($filter_opts as $k => $v) {
	$sel = ($filter === $k) ? ' selected' : '';
	echo '<option value="' . htmlspecialchars($k) . '"' . $sel . '>' . htmlspecialchars($v) . '</option>';
}
echo '</select></label>';
echo '<label>Search path: <input type="text" name="searchterm" value="' . htmlspecialchars($searchterm) . '" size="30"></label>';
echo '<button type="submit" class="btn btn-sm btn-secondary">Apply</button>';
echo '</form>';

$headers = array('Path', 'Entity', 'Title', 'Meta description', 'Noindex', 'Modified', 'Actions');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$page->tableheader($headers, array('title' => 'SEO Pages (' . $numrecords . ')'), $pager);

foreach ($rows as $row) {
	$rowvalues = array();

	$edit_url = '/admin/admin_seo_page_edit?spm_id=' . (int)$row->key;
	$rowvalues[] = '<a href="' . $edit_url . '"><code>' . htmlspecialchars($row->get('spm_path')) . '</code></a>';

	$ent_type = $row->get('spm_entity_type');
	$ent_id   = $row->get('spm_entity_id');
	$ent_disp = $ent_type
		? htmlspecialchars($ent_type) . ($ent_id ? ' #' . (int)$ent_id : '')
		: '<em>static</em>';
	$rowvalues[] = $ent_disp;

	$title = $row->get('spm_title');
	if ($title) {
		$rowvalues[] = htmlspecialchars(mb_substr($title, 0, 60));
	} else {
		$rowvalues[] = '<em style="color:#888;">default</em>';
	}

	$desc = $row->get('spm_meta_description');
	if ($desc) {
		$rowvalues[] = htmlspecialchars(mb_substr($desc, 0, 80));
	} else {
		$rowvalues[] = '<em style="color:#888;">default</em>';
	}

	$rowvalues[] = $row->get('spm_noindex') ? '<span style="color:#a00;">noindex</span>' : '';

	$mtime = $row->get('spm_modify_time') ?: $row->get('spm_create_time');
	$rowvalues[] = $mtime
		? htmlspecialchars(LibraryFunctions::convert_time($mtime, 'UTC', $session->get_timezone(), 'M j, Y'))
		: '';

	$rowvalues[] = '<a class="btn btn-sm btn-secondary" href="' . $edit_url . '">Edit</a>';

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
