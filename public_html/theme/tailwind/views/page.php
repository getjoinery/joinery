<?php
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('page_logic.php', 'logic'));

	$page_vars = process_logic(page_logic($_GET, $_POST, $page, $params));
	$page = $page_vars['page'];

	require_once(PathHelper::getIncludePath('data/abt_tests_class.php'));
	AbTest::apply_variant($page);

	if ($template = $page->get('pag_template')) {
		$template_path = PathHelper::getThemeFilePath($template . '.php', 'views', 'system', NULL, NULL, false, false);
		if ($template_path) {
			require($template_path);
			return;
		}
	}

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

