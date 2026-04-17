<?php
/**
 * Logic for admin_survey_edit.php
 * Handles survey creation and editing
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/surveys_class.php'));

function admin_survey_edit_logic($get, $post) {
    // Permission check
    $session = SessionControl::get_instance();
    $session->check_permission(10);

    // Load or create survey
    if (isset($get['svy_survey_id']) || isset($post['edit_primary_key_value'])) {
        $survey_id = isset($post['edit_primary_key_value']) ? $post['edit_primary_key_value'] : $get['svy_survey_id'];
        try {
            $survey = new Survey($survey_id, TRUE);
            if (!$survey || $survey->get('svy_delete_time')) {
                return LogicResult::redirect('/admin/admin_surveys?error=survey_not_found');
            }
        } catch (Exception $e) {
            return LogicResult::redirect('/admin/admin_surveys?error=survey_not_found');
        }
    } else {
        $survey = new Survey(NULL);
    }

    // Process POST
    if ($post) {
        try {
            $editable_fields = array('svy_name');

            foreach ($editable_fields as $field) {
                if (isset($post[$field])) {
                    $survey->set($field, $post[$field]);
                }
            }

            $survey->prepare();
            $survey->save();
            $survey->load();

            return LogicResult::redirect('/admin/admin_survey?svy_survey_id=' . $survey->key);
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }

    // Return data for view
    return LogicResult::render([
        'survey' => $survey,
        'error_message' => $error_message ?? null,
        'session' => $session
    ]);
}
?>