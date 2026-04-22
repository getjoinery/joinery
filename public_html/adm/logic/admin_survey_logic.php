<?php
/**
 * Logic for admin_survey.php
 * Handles survey display and question management
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/surveys_class.php'));
require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));

function admin_survey_logic($get, $post) {
    // Permission check
    $session = SessionControl::get_instance();
    $session->check_permission(5);
    $session->set_return();

    // Handle POST actions
    if ($post) {
        if (isset($post['action'])) {
            switch ($post['action']) {
                case 'addquestion':
                    $survey_question = new SurveyQuestion(NULL);
                    $survey_question->set('srq_svy_survey_id', $post['svy_survey_id']);
                    $survey_question->set('srq_qst_question_id', $post['qst_question_id']);
                    $survey_question->prepare();
                    $survey_question->save();
                    return LogicResult::redirect('/admin/admin_survey?svy_survey_id=' . $post['svy_survey_id']);
                    break;

                case 'removequestion':
                    $survey_question = new SurveyQuestion($post['srq_survey_question_id'], TRUE);
                    $survey_question->authenticate_write([
                        'current_user_id' => $session->get_user_id(),
                        'current_user_permission' => $session->get_permission()
                    ]);
                    $survey_question->permanent_delete();
                    return LogicResult::redirect('/admin/admin_survey?svy_survey_id=' . $post['svy_survey_id']);
                    break;

                case 'removesurvey':
                    $survey = new Survey($post['svy_survey_id'], TRUE);
                    $survey->authenticate_write([
                        'current_user_id' => $session->get_user_id(),
                        'current_user_permission' => $session->get_permission()
                    ]);
                    $survey->permanent_delete();
                    return LogicResult::redirect('/admin/admin_surveys');
                    break;
            }
        }
    }

    // Handle GET actions
    if (isset($get['action'])) {
        $svy_survey_id = $get['svy_survey_id'] ?? 0;
        $survey = new Survey($svy_survey_id, TRUE);

        switch ($get['action']) {
            case 'delete':
                $survey->authenticate_write([
                    'current_user_id' => $session->get_user_id(),
                    'current_user_permission' => $session->get_permission()
                ]);
                $survey->soft_delete();
                return LogicResult::redirect('/admin/admin_surveys');
                break;

            case 'undelete':
                $survey->authenticate_write([
                    'current_user_id' => $session->get_user_id(),
                    'current_user_permission' => $session->get_permission()
                ]);
                $survey->undelete();
                return LogicResult::redirect('/admin/admin_surveys');
                break;
        }
    }

    // Load survey data
    $svy_survey_id = LibraryFunctions::fetch_variable('svy_survey_id', 0, 0, NULL);
    $survey = new Survey($svy_survey_id, TRUE);

    // Pagination
    $numperpage = 30;
    $offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
    $sort = LibraryFunctions::fetch_variable('sort', 'survey_question_id', 0, '');
    $sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

    // Load survey questions (only if we have a valid survey)
    if ($survey->key) {
        $survey_questions = new MultiSurveyQuestion(
            array('survey_id' => $survey->key),
            array($sort => $sdirection),
            $numperpage,
            $offset,
            'AND'
        );
        $numrecords = $survey_questions->count_all();
        $survey_questions->load();
    } else {
        $survey_questions = new MultiSurveyQuestion(array());
        $numrecords = 0;
    }

    // Load all available questions for dropdown
    $questions = new MultiQuestion(
        array('deleted' => false),
        NULL,
        NULL,
        NULL
    );
    $questions->load();
    $numquestions = $questions->count_all();

    // Return data for view
    return LogicResult::render([
        'survey' => $survey,
        'survey_questions' => $survey_questions,
        'questions' => $questions,
        'numquestions' => $numquestions,
        'numrecords' => $numrecords,
        'numperpage' => $numperpage,
        'offset' => $offset,
        'sort' => $sort,
        'sdirection' => $sdirection,
        'session' => $session
    ]);
}
?>