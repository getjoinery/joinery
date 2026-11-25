<?php
/** @joinery-admin level=5 */
/**
 * Admin: a sandboxed plugin's rows that stop a site record being deleted, and
 * deleting them. See adm/logic/admin_sandbox_blocks_logic.php. Each view asks
 * its own level there: the links at the Plugins page's, the rows at their
 * model's permanent delete level.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_sandbox_blocks_logic.php'));

$page_vars = process_logic(admin_sandbox_blocks_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];
$plugin = $page_vars['plugin'];
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'system-plugins',
	'page_title'     => $page_vars['title'] . ': rows that block deletes',
	'readable_title' => 'Rows that block deletes',
	'breadcrumbs'    => array('Plugins' => '/admin/admin_plugins', $page_vars['title'] . ': rows that block deletes' => ''),
	'session'        => $session,
));

if ($page_vars['view'] === 'links') {
	$page->begin_box(array('title' => 'What its rows can stop being deleted'));
	echo '<p>A record of the site cannot be permanently deleted while ' . $e($page_vars['title'])
		. ' holds rows linked to it this way. Open a link to see those rows and delete them.</p>';
	if (!$page_vars['links']) {
		echo '<p>None: no rule of this plugin blocks a delete now.</p>';
	} else {
		echo '<ul style="list-style:disc;padding-left:1.5em;margin:0">';
		foreach ($page_vars['links'] as $link) {
			echo '<li><a href="' . $e($link['url']) . '">' . $e(ucfirst(SandboxModelDeclarations::words($link['model'])))
				. ' records, by ' . $e($link['column']) . ' (' . $e($link['source_table']) . ')</a>: '
				. (int)$link['count'] . ' ' . ((int)$link['count'] === 1 ? 'row holds' : 'rows hold') . ' a link now</li>';
		}
		echo '</ul>';
	}
	$page->end_box();
	$page->admin_footer();
	return;
}

$class = $page_vars['class'];
$words = SandboxModelDeclarations::words($page_vars['model']);
$page->begin_box(array('title' => ucfirst($words) . ' records by ' . $page_vars['column']
	. ($page_vars['id'] !== null ? ', pointing at #' . $page_vars['id'] : '')));
echo '<p>These rows of ' . $e($page_vars['title']) . ' hold a link'
	. ($page_vars['id'] !== null ? ' to record #' . (int)$page_vars['id'] : '') . ', deleted ones among them: '
	. 'a block counts every row until it is removed for good. Deleting one permanently applies the plugin\'s own rules '
	. '(rows of its own that depend on it go with it, or stop it).</p>';
echo '<p><a href="' . $e(SandboxBlockingRows::url($plugin)) . '">All of its blocking links</a></p>';
if (!$page_vars['rows']) {
	echo '<p>No rows hold a link now.</p>';
	$page->end_box();
	$page->admin_footer();
	return;
}
if ($page_vars['total'] > count($page_vars['rows'])) {
	echo '<p>' . count($page_vars['rows']) . ' of ' . (int)$page_vars['total'] . ' are shown; delete these to see the rest.</p>';
}
$page->end_box();

// The columns shown: the key, the link, and up to four more of the model's own.
$shown = array($class::$pkey_column, $page_vars['column']);
foreach (array_keys($class::$field_specifications) as $column) {
	if (count($shown) >= 6) {
		break;
	}
	if (!in_array($column, $shown, true) && !$class::is_unreadable_field($column)) {
		$shown[] = $column;
	}
}
$page->tableheader(array_merge(array_map($e, $shown), array('')), array('title' => ''));
foreach ($page_vars['rows'] as $row) {
	$values = array();
	foreach ($shown as $column) {
		$value = $row->get($column);
		$values[] = $e(mb_substr(is_scalar($value) || $value === null ? (string)$value : json_encode($value), 0, 120));
	}
	$values[] = AdminPage::action_button('Delete permanently', $page_vars['here'], array(
		'hidden'  => array('action' => 'delete', 'row' => (string)$row->key),
		'confirm' => 'Delete this row of ' . $page_vars['title'] . ' permanently? It cannot be undone.',
		'class'   => 'btn btn-soft-danger btn-sm',
	));
	$page->disprow($values);
}
$page->endtable();
echo '<p>' . AdminPage::action_button('Delete all of these permanently', $page_vars['here'], array(
	'hidden'  => array('action' => 'delete', 'row' => 'all'),
	'confirm' => 'Delete every row listed here permanently? It cannot be undone.',
	'class'   => 'btn btn-danger',
)) . '</p>';

$page->admin_footer();
