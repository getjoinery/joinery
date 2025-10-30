<?php
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_survey_edit_logic.php'));

// Process logic
$page_vars = process_logic(admin_survey_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'surveys',
    'breadcrumbs' => [
        'Surveys' => '/admin/admin_surveys',
        'New/Edit Survey' => '',
    ],
    'session' => $session,
]);

$pageoptions['title'] = $survey->key ? "Edit Survey" : "New Survey";
$page->begin_box($pageoptions);

if ($error_message) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>';
}

// Initialize FormWriter V2
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $survey,
    'edit_primary_key_value' => ($survey && $survey->key) ? $survey->key : null
]);

$formwriter->begin_form();

$formwriter->textinput('svy_name', 'Survey name', [
    'validation' => ['required' => true, 'maxlength' => 255]
]);

$formwriter->submitbutton('submit_button', 'Submit');
$formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>