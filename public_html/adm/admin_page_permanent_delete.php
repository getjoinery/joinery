<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_page_permanent_delete_logic.php'));

$page_vars = process_logic(admin_page_permanent_delete_logic($_GET, $_POST));
extract($page_vars);

$page_obj = new AdminPage();
$page_obj->admin_header(
	array(
		'menu-id' => 'pages',
		'page_title' => 'Page',
		'readable_title' => 'Permanently Delete Page',
		'breadcrumbs' => array(
			'Pages' => '/admin/admin_pages',
			'Delete ' . $page->get('pag_title') => '',
		),
		'session' => $session,
	)
);

$pageoptions['title'] = 'Permanently Delete: ' . $page->get('pag_title');
$page_obj->begin_box($pageoptions);

$formwriter = $page_obj->getFormWriter('form1');
echo $formwriter->begin_form();

echo '<fieldset><h4>Confirm Permanent Delete</h4>';
echo '<div class="fields full">';

echo '<p><strong>Warning:</strong> Permanently deleting <strong>' . htmlspecialchars($page->get('pag_title')) . '</strong> cannot be undone.</p>';

if (!empty($will_delete)) {
	echo '<p>The following components are used only by this page and will also be deleted:</p>';
	echo '<ul>';
	foreach ($will_delete as $component) {
		$label = $component->get('pac_title') ?: $component->get('pac_location_name');
		echo '<li>' . htmlspecialchars($label) . '</li>';
	}
	echo '</ul>';
}

if (!empty($will_keep)) {
	echo '<p>The following components are shared with other pages and will be kept:</p>';
	echo '<ul>';
	foreach ($will_keep as $entry) {
		$label = $entry['component']->get('pac_title') ?: $entry['component']->get('pac_location_name');
		$page_links = array_map(function($ctx) {
			return '<a href="' . htmlspecialchars($ctx['url']) . '">' . htmlspecialchars($ctx['label']) . '</a>';
		}, $entry['pages']);
		echo '<li>' . htmlspecialchars($label) . ' — also on: ' . implode(', ', $page_links) . '</li>';
	}
	echo '</ul>';
}

$formwriter->hiddeninput('confirm', '', ['value' => 1]);
$formwriter->hiddeninput('pag_page_id', '', ['value' => $pag_page_id]);

$formwriter->submitbutton('btn_submit', 'Permanently Delete');

echo '</div>';
echo '</fieldset>';
echo $formwriter->end_form();

$page_obj->end_box();
$page_obj->admin_footer();
?>
