<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>

require_once(PathHelper::getIncludePath('<?= $base ?>adm/logic/admin_<?= $plural ?>_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_<?= $plural ?>_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header([
	'menu-id' => '<?= str_replace('_', '-', $plural) ?>',
	'breadcrumbs' => array(
		'<?= $title_plural ?>' => '',
	),
	'session' => $session,
]);

$headers = array(<?php
	$hs = array();
	foreach ($display_fields as $df) { $hs[] = ScaffoldGenerator::phpScalar($df['label']); }
	if ($soft_delete) { $hs[] = "'Deleted'"; }
	echo implode(', ', $hs);
?>);
<?php if ($surface_on['admin_edit']): ?>
$altlinks = array('New <?= $title ?>' => '/admin/admin_<?= $entity_snake ?>_edit');
<?php else: ?>
$altlinks = array();
<?php endif; ?>
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=>$numperpage));
$table_options = array('altlinks' => $altlinks, 'title' => '<?= $title_plural ?>');
$page->tableheader($headers, $table_options, $pager);

foreach ($<?= $plural ?> as $<?= $entity_snake ?>) {
	$rowvalues = array();
<?php foreach ($display_fields as $i => $df): ?>
<?php if ($i === 0 && $surface_on['admin_edit']): ?>
	array_push($rowvalues, '<a href="/admin/admin_<?= $entity_snake ?>_edit?<?= $pkey ?>=' . $<?= $entity_snake ?>->key . '">' . htmlspecialchars((string)$<?= $entity_snake ?>->get('<?= $df['col'] ?>')) . '</a>');
<?php else: ?>
	array_push($rowvalues, htmlspecialchars((string)$<?= $entity_snake ?>->get('<?= $df['col'] ?>')));
<?php endif; ?>
<?php endforeach; ?>
<?php if ($soft_delete): ?>
	array_push($rowvalues, $<?= $entity_snake ?>->get('<?= $delete_col ?>') ? 'Deleted' : 'Active');
<?php endif; ?>
	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
<?= $C ?>
