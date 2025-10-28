<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_product_requirement_edit_logic.php'));

$page_vars = process_logic(admin_product_requirement_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'product-requirements',
	'page_title' => 'Product Requirements',
	'readable_title' => 'Product Requirements',
	'breadcrumbs' => array(
		'Products'=>'/admin/admin_products',
		$breadcrumb => '',
	),
	'session' => $session,
)
);

$page->begin_box($pageoptions);

// FormWriter V2 with model and edit_primary_key_value
$formwriter = $page->getFormWriter('form1', 'v2', [
	'model' => $product_requirement,
	'edit_primary_key_value' => $product_requirement->key
]);

$formwriter->begin_form();

$formwriter->textinput('prq_title', 'Name for this requirement', [
	'validation' => ['required' => true, 'maxlength' => 255]
]);

$optionvals = $questions->get_dropdown_array();
$formwriter->dropinput('prq_qst_question_id', 'Question', [
	'options' => $optionvals,
	'validation' => ['required' => true],
	'empty_option' => '-- Select --'
]);

$formwriter->textinput('prq_link', 'Link (optional):', [
	'validation' => ['maxlength' => 255]
]);

$optionvals = $files->get_file_dropdown_array();
$formwriter->dropinput('prq_fil_file_id', 'Attach a file (optional)', [
	'options' => $optionvals,
	'empty_option' => '-- None --'
]);

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
