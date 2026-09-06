<?php
/**
 * Admin Help Editor - Markdown editor for the files behind the help viewer
 * and the public /documentation page. Origin deployment only; the logic
 * redirects to the viewer everywhere else.
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/DocsScanner.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_help_edit_logic.php'));

$page_vars = process_logic(admin_help_edit_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => null,
	'page_title'     => 'Edit - ' . $doc_title,
	'readable_title' => 'Edit Documentation',
	'breadcrumbs'    => array(
		'Help'     => $view_url,
		$doc_title => $view_url . '?doc=' . $selected_doc,
		'Edit'     => '',
	),
	'session'        => $session,
));

if (!empty($error)) {
	echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($error) . '</div>';
}

if (empty($writable)) {
	echo '<div class="alert alert-warning" role="alert">'
		. 'This file is not writable by the web server, so a save will be refused. Restore its mode with '
		. '<code>chmod 666 ' . htmlspecialchars($relative_path) . '</code> — a git checkout resets it.'
		. '</div>';
}

$page->begin_box(array('title' => 'Edit ' . $doc_title));

echo '<p style="margin-bottom: 16px; color: #555;">'
	. 'Editing <code>' . htmlspecialchars($relative_path) . '</code>. This is a source file that ships with the release, '
	. 'so the change reaches the other sites only once it is committed and published.'
	. '</p>';

$formwriter = $page->getFormWriter('docs_edit', array(
	'values' => array('doc_content' => $content),
));

echo $formwriter->begin_form();

$formwriter->hiddeninput('doc', '', array('value' => $selected_doc));
$formwriter->hiddeninput('content_hash', '', array('value' => $content_hash));

$formwriter->textbox('doc_content', 'Markdown', array(
	'rows'          => 34,
	'cols'          => 100,
	'markdownmode'  => 'yes',
	'markdown_mode' => 'split',
	'helptext'      => 'The whole file. The first H1 becomes the page title, and the first paragraph becomes its meta description on the public site.',
));

$formwriter->submitbutton('btn_submit', 'Save');
echo ' <a class="btn btn-outline-secondary" href="' . htmlspecialchars($view_url . '?doc=' . $selected_doc) . '">Cancel</a>';

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
