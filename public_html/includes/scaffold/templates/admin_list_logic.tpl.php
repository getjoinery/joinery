<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>

function admin_<?= $plural ?>_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));

	// Who may open the page is the view's @joinery-admin header.
	$session = SessionControl::get_instance();
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($input, 'sort', 'id');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'DESC');

	$search_criteria = array();
<?php if ($soft_delete): ?>
	if (!Access::permits(<?= $entity ?>::access_level('soft_delete'), $session)) {
		$search_criteria['deleted'] = false;
	}
<?php endif; ?>

	$<?= $plural ?> = new <?= $multi ?>($search_criteria, array($sort=>$sdirection), $numperpage, $offset);
	$numrecords = $<?= $plural ?>->count_all();
	$<?= $plural ?>->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['<?= $plural ?>'] = $<?= $plural ?>;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;

	return LogicResult::render($page_vars);
}
<?= $C ?>
