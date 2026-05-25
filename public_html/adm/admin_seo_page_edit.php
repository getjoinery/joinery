<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_seo_page_edit_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_seo_page_edit_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'settings-seo-pages',
	'breadcrumbs' => array(
		'Settings'  => '',
		'SEO Pages' => '/admin/admin_seo_pages',
		($spm->key ? 'Edit' : 'New') . ' SEO Row' => '',
	),
	'session' => $session,
));

if (!empty($notice)) {
	echo '<div class="alert alert-success" role="alert">' . htmlspecialchars($notice) . '</div>';
}
if (!empty($error)) {
	echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($error) . '</div>';
}

$pageoptions = array('title' => ($spm->key ? 'Edit SEO Row' : 'New SEO Row'));
$page->begin_box($pageoptions);

echo '<p style="margin-bottom: 16px; color: #555;">';
echo 'Each field overrides what the public-page emitter would otherwise infer. Leave a field blank to use the default shown in the placeholder.';
echo '</p>';

if ($entity_edit_link) {
	echo '<p style="margin-bottom: 12px;"><strong>Entity:</strong> <code>' . htmlspecialchars($spm->get('spm_entity_type')) . ' #' . (int)$spm->get('spm_entity_id') . '</code> &mdash; ';
	echo '<a href="' . htmlspecialchars($entity_edit_link) . '">Edit underlying content</a></p>';
}

$formwriter = $page->getFormWriter('form1', array(
	'model'                  => $spm,
	'edit_primary_key_value' => $spm->key,
));

echo $formwriter->begin_form();

if (!$spm->key) {
	$formwriter->textinput('spm_path', 'Path', array(
		'maxlength' => 255,
		'help'      => 'Canonical request path (e.g. "/pricing"). Leading slash required.',
	));
} else {
	echo '<div style="margin-bottom: 16px;"><strong>Path:</strong> <code>' . htmlspecialchars($spm->get('spm_path')) . '</code>';
	if ($spm->get('spm_entity_type')) {
		echo ' <em>(managed by enumeration — auto-updates on slug change)</em>';
	}
	echo '</div>';
	$formwriter->hiddeninput('spm_path_display', '', array('value' => $spm->get('spm_path')));
}

$formwriter->textinput('spm_title', 'Title', array(
	'maxlength'   => 255,
	'placeholder' => $placeholders['spm_title'],
));

$formwriter->textbox('spm_meta_description', 'Meta description', array(
	'rows'        => 3,
	'cols'        => 80,
	'htmlmode'    => 'no',
	'placeholder' => $placeholders['spm_meta_description'],
));

$formwriter->textinput('spm_og_title', 'OG/Twitter title (optional split)', array(
	'maxlength'   => 255,
	'placeholder' => $placeholders['spm_og_title'],
));

$formwriter->textbox('spm_og_description', 'OG/Twitter description (optional split)', array(
	'rows'        => 3,
	'cols'        => 80,
	'htmlmode'    => 'no',
	'placeholder' => $placeholders['spm_og_description'],
));

$formwriter->textinput('spm_preview_image_url', 'Preview image URL', array(
	'maxlength'   => 500,
	'placeholder' => $placeholders['spm_preview_image_url'],
));

$formwriter->textinput('spm_og_type', 'OG type', array(
	'maxlength'   => 50,
	'placeholder' => $placeholders['spm_og_type'],
));

$formwriter->checkboxinput('spm_noindex', 'Noindex this path', array(
	'help' => 'Emits <meta name="robots" content="noindex"> and excludes from sitemap.',
));

$formwriter->submitbutton('btn_submit', 'Save');

if ($spm->key) {
	echo ' ';
	$formwriter->submitbutton('btn_delete', 'Soft-delete', array(
		'class'   => 'btn btn-danger',
		'onclick' => "return confirm('Soft-delete this SEO row?');",
	));
}

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
