<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_product_requirement_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/product_requirements_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	// Load or create product requirement
	// CRITICAL: Check edit_primary_key_value (form submission first), fallback to GET
	if (isset($post_vars['edit_primary_key_value'])) {
		$product_requirement = new ProductRequirement($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['prq_product_requirement_id'])) {
		$product_requirement = new ProductRequirement($get_vars['prq_product_requirement_id'], TRUE);
	} else {
		$product_requirement = new ProductRequirement(NULL);
	}

	// Process POST actions
	// CRITICAL: Check for POST submission
	if ($post_vars) {
		$editable_fields = array('prq_title', 'prq_text', 'prq_link', 'prq_is_default_checked', 'prq_is_required', 'prq_fil_file_id', 'prq_qst_question_id');

		foreach($editable_fields as $field) {
			$product_requirement->set($field, $post_vars[$field]);
		}

		$product_requirement->prepare();
		$product_requirement->save();

		return LogicResult::redirect('/admin/admin_product_requirements?prq_product_requirement_id='. $product_requirement->key);
	}

	// Load data for display
	$options = [];
	if ($product_requirement->key) {
		$options['title'] = 'Product Requirement Edit';
		$breadcrumb = 'Product Requirement Edit';
	} else {
		$options['title'] = 'New Product Requirement';
		$breadcrumb = 'New Product Requirement';
	}

	// Load questions for dropdown
	$questions = new MultiQuestion(
		array('deleted'=>false),
		array('question_id'=>'DESC'),
		NULL,
		NULL);
	$questions->load();

	// Load files for dropdown
	$files = new MultiFile(
		array('deleted'=>false, 'past'=>false),
		array('file_id'=>'DESC'),
		NULL,
		NULL);
	$files->load();

	// Return page variables for rendering
	return LogicResult::render(array(
		'product_requirement' => $product_requirement,
		'pageoptions' => $options,
		'breadcrumb' => $breadcrumb,
		'questions' => $questions,
		'files' => $files,
		'session' => $session,
	));
}

?>
