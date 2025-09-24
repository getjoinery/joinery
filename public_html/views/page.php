<?php
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('page_logic.php', 'logic'));

	$page_vars = page_logic($_GET, $_POST, $page, $params);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	$page = $page_vars['page'];

	$paget = new PublicPage();
	$paget->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $page->get('pag_title')
	));
	echo PublicPage::BeginPage($page->get('pag_title'));
	echo PublicPage::BeginPanel();
	
	echo '<div>'. $page->get_filled_content() . '</div>';
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$paget->public_footer($foptions=array('track'=>TRUE));
?>

