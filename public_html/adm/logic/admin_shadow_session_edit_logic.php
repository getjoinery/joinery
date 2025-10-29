<?php
/**
 * Logic for admin_shadow_session_edit.php
 * Handles shadow session (product detail) editing
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/product_details_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

function admin_shadow_session_edit_logic($get, $post) {
    // Permission check
    $session = SessionControl::get_instance();
    $session->check_permission(5);

    // Load or create product detail
    if (isset($get['prd_product_detail_id']) || isset($post['edit_primary_key_value'])) {
        $product_detail_id = isset($post['edit_primary_key_value']) ? $post['edit_primary_key_value'] : $get['prd_product_detail_id'];
        try {
            $product_detail = new ProductDetail($product_detail_id, TRUE);
            if (!$product_detail || $product_detail->get('prd_delete_time')) {
                return LogicResult::redirect('/admin/admin_shadow_sessions?error=not_found');
            }
        } catch (Exception $e) {
            return LogicResult::redirect('/admin/admin_shadow_sessions?error=not_found');
        }
    } else {
        $product_detail = new ProductDetail(NULL);
    }

    // Get user for display
    $user = null;
    if ($product_detail->get('prd_usr_user_id')) {
        $user = new User($product_detail->get('prd_usr_user_id'), TRUE);
    }

    // Process POST
    if ($post) {
        try {
            // Define editable fields
            $editable_fields = array('prd_num_used', 'prd_notes');

            // Set fields from POST
            foreach ($editable_fields as $field) {
                if (isset($post[$field])) {
                    $product_detail->set($field, $post[$field]);
                }
            }

            // Save
            $product_detail->prepare();
            $product_detail->save();

            return LogicResult::redirect('/admin/admin_shadow_sessions?success=1');
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }

    // Return data for view
    return LogicResult::render([
        'product_detail' => $product_detail,
        'user' => $user,
        'error_message' => $error_message ?? null,
        'session' => $session
    ]);
}
?>
