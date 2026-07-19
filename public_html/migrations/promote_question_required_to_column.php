<?php
/**
 * Required-ness of a question lives in the qst_is_required column; the
 * qst_validate blob carries only value rules (integer, decimal, lengths,
 * bounds). Promote any legacy 'required' key out of the blob into the column
 * so every enforcement surface reads one source.
 */
function promote_question_required_to_column() {
    require_once(PathHelper::getIncludePath('data/questions_class.php'));

    $questions = new MultiQuestion(array());
    $questions->load();

    $promoted = 0;
    $cleaned = 0;
    foreach ($questions as $question) {
        $validation = unserialize((string)$question->get('qst_validate')) ?: array();
        if (!array_key_exists('required', $validation)) {
            continue;
        }
        if (!$question->get('qst_is_required')) {
            $question->set('qst_is_required', TRUE);
            $promoted++;
        }
        unset($validation['required']);
        $question->set('qst_validate', serialize($validation));
        $question->save();
        $cleaned++;
    }

    echo "Promoted $promoted question(s) to qst_is_required; cleaned the legacy 'required' key from $cleaned blob(s).\n";
    return true;
}
