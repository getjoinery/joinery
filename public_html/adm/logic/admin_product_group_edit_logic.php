<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_product_group_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/product_groups_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(7);
	$session->set_return();

	// Load or create product group
	// CRITICAL: Check edit_primary_key_value (form submission first), fallback to GET
	if (isset($post_vars['edit_primary_key_value'])) {
		$product_group = new ProductGroup($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['prg_product_group_id'])) {
		$product_group = new ProductGroup($get_vars['prg_product_group_id'], TRUE);
	} else {
		$product_group = new ProductGroup(NULL);
	}

	// Process POST actions
	// CRITICAL: Check for POST submission
	if ($post_vars) {
		$editable_fields = array('prg_max_items', 'prg_error', 'prg_name', 'prg_description', 'prg_subtitle', 'prg_type');

		foreach($editable_fields as $field) {
			$product_group->set($field, $post_vars[$field]);
		}

		$product_group->prepare();
		$product_group->save();

		return LogicResult::redirect('/admin/admin_product_groups');
	}

	// Load data for display
	$options = [];
	if ($product_group->key) {
		$options['title'] = 'Edit Product Group';
	} else {
		$options['title'] = 'New Product Group';
	}

	// Return page variables for rendering
	return LogicResult::render(array(
		'product_group' => $product_group,
		'pageoptions' => $options,
		'session' => $session,
	));
}

?>
