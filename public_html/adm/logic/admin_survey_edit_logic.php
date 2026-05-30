<?php
/**
 * Logic for admin_survey_edit.php
 * Handles survey creation and editing
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/surveys_class.php'));

function admin_survey_edit_logic(array $input): LogicResult {
    // Permission check
    $session = SessionControl::get_instance();
    $session->check_permission(10);

    // Load or create survey
    if (isset($input['svy_survey_id']) || isset($input['edit_primary_key_value'])) {
        $survey_id = isset($input['edit_primary_key_value']) ? $input['edit_primary_key_value'] : $input['svy_survey_id'];
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
    if (LibraryFunctions::isFormSubmission()) {
        try {
            $editable_fields = array('svy_name');

            foreach ($editable_fields as $field) {
                if (isset($input[$field])) {
                    $survey->set($field, $input[$field]);
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