<?php
/**
 * Admin: review what a sandboxed plugin asks for, and approve it.
 * See adm/logic/admin_sandbox_approve_logic.php.
 *
 * @version 1.1 - lists its admin pages; the button carries the digest of what was shown
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_sandbox_approve_logic.php'));

$page_vars = process_logic(admin_sandbox_approve_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];
$manifest = $page_vars['manifest'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'system-plugins',
	'page_title'     => 'Approve ' . $manifest->title(),
	'readable_title' => 'Approve ' . $manifest->title(),
	'breadcrumbs'    => array('Plugins' => '/admin/admin_plugins', $manifest->title() => ''),
	'session'        => $session,
));

$list = function (array $groups) {
	foreach ($groups as $heading => $lines) {
		echo '<h4 style="margin:1em 0 .3em">' . htmlspecialchars($heading) . '</h4><ul style="list-style:disc;padding-left:1.5em;margin:0">';
		foreach ($lines as $line) {
			echo '<li>' . htmlspecialchars($line) . '</li>';
		}
		echo '</ul>';
	}
};

$page->begin_box(array('title' => $manifest->title() . ' ' . $manifest->version()));
if ($manifest->description() !== '') {
	echo '<p>' . htmlspecialchars($manifest->description()) . '</p>';
}
echo '<p>' . ($manifest->author() !== '' ? 'By ' . htmlspecialchars($manifest->author()) . '. ' : '')
	. 'This is a sandboxed plugin: it runs apart from the site and can reach it only in the ways listed below, '
	. 'and only once an admin approves them. It does not need to be built by Joinery to be safe to run this way.</p>';
if ($page_vars['approved'] && $page_vars['approved_by']) {
	echo '<p>Last approved by ' . htmlspecialchars($page_vars['approved_by']->display_name()) . ' on '
		. htmlspecialchars(LibraryFunctions::convert_time($page_vars['approved_time'], 'UTC', $session->get_timezone(), 'M j, Y g:i A'))
		. '. It acts on the site as that admin, within what is listed.</p>';
}
$page->end_box();

if ($page_vars['problems']) {
	$page->begin_box(array('title' => 'This site cannot run it'));
	echo '<ul style="list-style:disc;padding-left:1.5em;margin:0">';
	foreach ($page_vars['problems'] as $problem) {
		echo '<li>' . htmlspecialchars($problem) . '</li>';
	}
	echo '</ul>';
	$page->end_box();
}

if ($page_vars['new']) {
	$page->begin_box(array('title' => 'New since it was last approved'));
	echo '<p>A newer version asks for more. Until this is approved it keeps only what was approved before.</p>';
	$list($page_vars['new']);
	$page->end_box();
}

$page->begin_box(array('title' => 'What it asks to do'));
if ($page_vars['asked']) {
	$list($page_vars['asked']);
} else {
	echo '<p>Nothing beyond its own records and pages.</p>';
}
$own = array();
if ($page_vars['own_models']) {
	$own['Keep its own records'] = $page_vars['own_models'];
}
if ($page_vars['admin_pages']) {
	$own['Pages for admins, under its menu'] = $page_vars['admin_pages'];
}
if ($page_vars['settings']) {
	$names = array();
	foreach ($page_vars['settings'] as $setting) {
		$names[] = $setting['label'] . (!empty($setting['secret']) ? ' (kept secret)' : '');
	}
	$own['Settings you fill in'] = $names;
}
if ($own) {
	echo '<p style="margin-top:1em">It also has, as its own:</p>';
	$list($own);
}
$page->end_box();

// Approving as yourself moves the plugin's credential to you, which is how
// the approving admin's own account is freed.
$by_me = $page_vars['approved_by'] && (int)$page_vars['approved_by']->key === (int)$session->get_user_id();
if (!$page_vars['problems'] && ($page_vars['needs_approval'] || !$page_vars['row']->is_active() || !$by_me)) {
	if (!$page_vars['row']->is_active()) {
		$label = 'Approve and activate';
	} elseif ($page_vars['needs_approval']) {
		$label = 'Approve';
	} else {
		$label = 'Approve as me';
		echo '<p>It acts as ' . htmlspecialchars($page_vars['approved_by']->display_name()) . ' now; approving it yourself makes it act as you.</p>';
	}
	echo '<p>' . AdminPage::action_button($label, $page_vars['here'], array(
		// What was shown: a plugin replaced since is not approved unseen.
		'hidden' => array('action' => 'approve', 'reviewed' => $page_vars['reviewed']),
		'class'  => 'btn btn-primary',
	)) . ' <a href="/admin/admin_plugins" class="btn btn-soft-default">Not now</a></p>';
}

$page->admin_footer();
