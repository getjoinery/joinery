<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>

function <?= $plural ?>_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('<?= $base ?>data/<?= $entity_snake ?>_class.php'));

	$session = SessionControl::get_instance();
<?php if ($public_permission !== null): ?>
	$session->check_permission(<?= $public_permission ?>);
<?php endif; ?>

	$numperpage = 12;
	$offset = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$offsetdisp = $offset ? $offset + 1 : 1;
	$sort = LibraryFunctions::fetch_variable_local($input, 'sort', 'id');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'DESC');

	$searches = array();
<?php if ($soft_delete): ?>
	$searches['deleted'] = FALSE;
<?php endif; ?>

	$<?= $plural ?> = new <?= $multi ?>($searches, array($sort=>$sdirection), $numperpage, $offset);
	$numrecords = $<?= $plural ?>->count_all();
	$<?= $plural ?>->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['<?= $plural ?>'] = $<?= $plural ?>;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;
	$page_vars['offset'] = $offset;
	$page_vars['offsetdisp'] = $offsetdisp;
	$page_vars['pager'] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=>$numperpage));

	return LogicResult::render($page_vars);
}
<?= $C ?>
