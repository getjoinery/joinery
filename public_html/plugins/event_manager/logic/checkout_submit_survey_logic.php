<?php
/**
 * Persist a post-purchase confirmation survey (cart confirmation page) and
 * mark the buyer's event registration survey-complete. Logged-in only: the
 * confirmation surveys belong to the purchaser's event registrations.
 *
 * @version 1.0.0
 */

function checkout_submit_survey_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	$survey_id = isset($input['survey_id']) ? intval($input['survey_id']) : 0;
	$event_id = isset($input['event_id']) ? intval($input['event_id']) : 0;

	if (!$survey_id || !$user_id) {
		return LogicResult::error('Not logged in or invalid survey');
	}

	$sq = new MultiSurveyQuestion(
		array('survey_id' => $survey_id, 'deleted' => false),
		array('srq_order' => 'ASC')
	);
	$sq->load();

	foreach ($sq as $survey_question) {
		$question_id = $survey_question->get('srq_qst_question_id');
		$question = new Question($question_id, true);
		// The confirmation page renders fields as confirm_survey_q_{id}; accept
		// the plain question_{id} name too for other embedding contexts.
		// Checkbox-list answers arrive as arrays; an empty array means no box
		// was checked — unanswered, same as ''.
		$answer = isset($input['question_' . $question_id]) ? $input['question_' . $question_id] : '';
		if ($answer === '' || $answer === array()) {
			$answer = isset($input['confirm_survey_q_' . $question_id]) ? $input['confirm_survey_q_' . $question_id] : '';
		}

		if ($answer !== '' && $answer !== array()) {
			$readable = $question->get_answer_readable($answer, false);
			$survey_answer = new SurveyAnswer(NULL);
			$survey_answer->set('sva_svy_survey_id', $survey_id);
			$survey_answer->set('sva_qst_question_id', $question_id);
			$survey_answer->set('sva_usr_user_id', $user_id);
			$survey_answer->set('sva_answer', $readable);
			$survey_answer->save();
		}
	}

	// Mark the survey completed on the event registrant. This action lives in
	// event_manager, so the plugin is active whenever it resolves.
	if ($event_id) {
		require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
		$registrant = EventRegistrant::check_if_registrant_exists($user_id, $event_id);
		if ($registrant) {
			$registrant->set('evr_survey_completed', true);
			$registrant->save();
		}
	}

	return LogicResult::render(array('submitted' => true));
}

function checkout_submit_survey_logic_descriptor(): array {
	return [
		'description'      => 'Submit a post-purchase confirmation survey and mark the event registration survey-complete.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => [
			// Per-question answers arrive as confirm_survey_q_{id} /
			// question_{id} fields; undeclared fields pass through to the logic.
			'survey_id' => ['type' => 'int', 'required' => true, 'label' => 'Survey ID'],
			'event_id'  => ['type' => 'int', 'required' => false, 'label' => 'Event ID'],
		],
	];
}
?>
