<?php
/**
 * SurveyRequirement
 *
 * Auto-activating requirement for events with evt_survey_display = 'required_before_purchase'.
 * Renders all survey questions on the product page.
 * Config: {"survey_id": N, "event_id": N}
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));
require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

class SurveyRequirement extends AbstractProductRequirement {

    const LABEL = 'Survey';

    public function getFormGroup() { return 'questions'; }

    /** @var array Cached SurveyQuestion objects */
    private $survey_questions = null;

    private function getSurveyQuestions() {
        if ($this->survey_questions === null) {
            $this->survey_questions = array();
            if (!empty($this->config['survey_id'])) {
                $sq = new MultiSurveyQuestion(
                    array('survey_id' => $this->config['survey_id'], 'deleted' => false),
                    array('srq_order' => 'ASC')
                );
                $sq->load();
                foreach ($sq as $survey_question) {
                    $question_id = $survey_question->get('srq_qst_question_id');
                    $question = new Question($question_id, true);
                    $this->survey_questions[] = $question;
                }
            }
        }
        return $this->survey_questions;
    }

    public function render_fields($formwriter, $product, $existing_data = []) {
        $questions = $this->getSurveyQuestions();
        foreach ($questions as $question) {
            $field_name = 'survey_q_' . $question->key;
            $value = isset($existing_data[$field_name]) ? $existing_data[$field_name] : null;
            $question->output_question($formwriter, $value);
        }
    }

    public function validate($post_data, $product) {
        $errors = array();
        $questions = $this->getSurveyQuestions();
        foreach ($questions as $question) {
            $field_name = 'survey_q_' . $question->key;
            $answer = isset($post_data[$field_name]) ? $post_data[$field_name] : '';

            if ($question->get('qst_type') == Question::TYPE_CONFIRMATION) {
                if (empty($answer)) {
                    $errors[] = 'You must agree to: ' . $question->get('qst_question');
                }
                continue;
            }

            if ($question->get('qst_is_required') && ($answer === '' || $answer === null)) {
                $errors[] = 'Please answer: ' . $question->get('qst_question');
                continue;
            }

            if ($answer !== '' && $answer !== null) {
                $result = $question->validate_answers($answer);
                if ($result !== 'valid') {
                    $errors[] = $result;
                }
            }
        }
        return $errors;
    }

    public function process($post_data, $product, $order_detail, $user) {
        $data_array = array();
        $display_array = array();
        $questions = $this->getSurveyQuestions();

        foreach ($questions as $question) {
            $field_name = 'survey_q_' . $question->key;
            $answer = isset($post_data[$field_name]) ? $post_data[$field_name] : '';
            $readable = $question->get_answer_readable($answer, false);

            $data_array[$field_name] = array(
                'name' => $field_name,
                'question_id' => $question->key,
                'question' => $question->get('qst_question'),
                'answer' => $readable,
                'survey_id' => $this->config['survey_id'],
            );

            $display_array[$question->get('qst_question')] = $readable;
        }

        return array($data_array, $display_array);
    }

    public function post_purchase($data, $order_item, $user, $order) {
        $questions = $this->getSurveyQuestions();
        $survey_id = $this->config['survey_id'];

        foreach ($questions as $question) {
            $field_name = 'survey_q_' . $question->key;
            if (isset($data[$field_name]) && is_array($data[$field_name])) {
                $answer_data = $data[$field_name];
                $survey_answer = new SurveyAnswer(NULL);
                $survey_answer->set('sva_svy_survey_id', $survey_id);
                $survey_answer->set('sva_qst_question_id', $question->key);
                $survey_answer->set('sva_usr_user_id', $user ? $user->key : null);
                $survey_answer->set('sva_answer', $answer_data['answer']);
                $survey_answer->save();
            }
        }

        // Mark survey completed on event registrant if applicable
        if (!empty($this->config['event_id']) && $user) {
            require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
            $registrant = EventRegistrant::check_if_registrant_exists($user->key, $this->config['event_id']);
            if ($registrant) {
                $registrant->set('evr_survey_completed', true);
                $registrant->save();
            }
        }
    }
}

// Register with the requirement system
AbstractProductRequirement::register('SurveyRequirement', __FILE__);
