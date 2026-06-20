<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('<?= $base ?>adm/logic/admin_<?= $entity_snake ?>_edit_logic.php'));

$page_vars = process_logic(admin_<?= $entity_snake ?>_edit_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header([
	'menu-id' => '<?= str_replace('_', '-', $plural) ?>',
	'breadcrumbs' => array(
		'<?= $title_plural ?>' => '/admin/admin_<?= $plural ?>',
		'Edit <?= $title ?>' => '',
	),
	'session' => $session,
]);

$pageoptions = array('title' => 'Edit <?= $title ?>');
$page->begin_box($pageoptions);

$formwriter = $page->getFormWriter('form1', [
	'model' => $<?= $entity_snake ?>,
	'edit_primary_key_value' => $<?= $entity_snake ?>->key
]);

$formwriter->begin_form();
$formwriter->fromDescriptor(admin_<?= $entity_snake ?>_edit_logic_descriptor());
// TODO: hand-add fields with no descriptor type (uploads, rich text, custom widgets) here.
$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();
$page->admin_footer();
<?= $C ?>
