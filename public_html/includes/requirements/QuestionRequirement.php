<?php
/**
 * QuestionRequirement
 *
 * Tier 1 requirement: wraps the existing Question system.
 * Config: {"question_id": N}
 *
 * Delegates rendering and validation to the Question model.
 * Supports all question types including the new confirmation type.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/requirements/AbstractProductRequirement.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));

class QuestionRequirement extends AbstractProductRequirement {

    const LABEL = 'Question';

    public function getFormGroup() { return 'questions'; }

    /** @var Question|null Cached Question object */
    private $question = null;

    /**
     * Get the Question object for this requirement.
     * @return Question
     * @throws Exception if question_id not set or question not found
     */
    private function getQuestion() {
        if ($this->question === null) {
            if (empty($this->config['question_id'])) {
                throw new Exception('QuestionRequirement: question_id not set in config');
            }
            $this->question = new Question($this->config['question_id'], true);
        }
        return $this->question;
    }

    /**
     * Get label from the underlying Question.
     */
    public function get_label() {
        return $this->getQuestion()->get('qst_question');
    }

    /**
     * Render the question form fields.
     */
    public function render_fields($formwriter, $product, $existing_data = []) {
        $question = $this->getQuestion();
        $field_name = 'question_' . $question->key;
        $value = isset($existing_data[$field_name]) ? $existing_data[$field_name] : null;

        // Get append text from config if provided
        $append_text = isset($this->config['append_text']) ? $this->config['append_text'] : null;

        $question->output_question($formwriter, $value, $append_text);
    }

    /**
     * Validate the question answer.
     */
    public function validate($post_data, $product) {
        $question = $this->getQuestion();
        $field_name = 'question_' . $question->key;
        $answer = isset($post_data[$field_name]) ? $post_data[$field_name] : '';

        // For confirmation type, check required
        if ($question->get('qst_type') == Question::TYPE_CONFIRMATION) {
            if (empty($answer)) {
                return ['You must agree to: ' . $question->get('qst_question')];
            }
            return [];
        }

        // For other types, use the Question's built-in validation
        // Also check qst_is_required
        if ($question->get('qst_is_required') && ($answer === '' || $answer === null)) {
            return ['You did not answer this question: ' . $question->get('qst_question')];
        }

        $result = $question->validate_answers($answer);
        if ($result !== 'valid') {
            return [$result];
        }

        return [];
    }

    /**
     * Process the question answer — return data/display arrays.
     */
    public function process($post_data, $product, $order_detail, $user) {
        $question = $this->getQuestion();
        $field_name = 'question_' . $question->key;
        $answer = isset($post_data[$field_name]) ? $post_data[$field_name] : '';

        $readable = $question->get_answer_readable($answer, false);

        $data_array = [
            $field_name => [
                'name' => $field_name,
                'question_id' => $question->key,
                'question' => $question->get('qst_question'),
                'answer' => $readable,
            ],
        ];

        $display_array = [
            $question->get('qst_question') => $readable,
        ];

        return [$data_array, $display_array];
    }

    /**
     * Return validation info for client-side validation.
     */
    public function get_validation_info() {
        $question = $this->getQuestion();
        $validation_rules = [];
        $validation_rules = $question->output_js_validation($validation_rules);
        return $validation_rules ?: null;
    }
}
