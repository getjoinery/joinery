<?php
/** @joinery-test
 * name: checkout_survey
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Post-purchase confirmation survey — who may answer it, and how many times.
 *
 * The confirmation page posts survey_id and event_id from the browser, so
 * neither is trustworthy on its own. Two properties have to hold: the caller
 * must hold a registration for the event that asks the survey, and the survey
 * must be the one that event actually attached — otherwise any signed-in user
 * can post answers into any survey on the platform, polluting a question set
 * they were never given. Answers are attributed to the poster, so this is data
 * pollution rather than impersonation, but a survey nobody can trust is a
 * survey nobody can use.
 *
 * The second property is idempotence. The page submits by fetch, so a retry, a
 * double-click, or a replayed request must not append a second full set of
 * answers to the same registration.
 *
 * Sections: the registration requirement; the survey-belongs-to-event binding;
 * the replay guard; and the answers actually written.
 *
 * Run: php plugins/event_manager/tests/checkout_survey_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
require_once(PathHelper::getIncludePath('data/surveys_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

if (session_id() === '') @session_start();

$db = DbConnector::get_instance()->get_db_link();

/** A survey with one free-text question. Returns [survey, question]. */
function cs_make_survey($name) {
	$survey = new Survey(NULL);
	$survey->set('svy_name', 'HarnessTest ' . $name . ' ' . bin2hex(random_bytes(3)));
	$survey->save();
	$survey->load();
	harness_register_row('svy_surveys', 'svy_survey_id', $survey->key);

	$question = new Question(NULL);
	$question->set('qst_question', 'How was it?');
	$question->set('qst_type', Question::TYPE_SHORT_TEXT);
	$question->save();
	$question->load();
	harness_register_row('qst_questions', 'qst_question_id', $question->key);

	$link = new SurveyQuestion(NULL);
	$link->set('srq_svy_survey_id', $survey->key);
	$link->set('srq_qst_question_id', $question->key);
	$link->set('srq_order', 1);
	$link->save();
	$link->load();
	harness_register_row('srq_survey_questions', 'srq_survey_question_id', $link->key);

	return array($survey, $question);
}

/** An event that asks $survey_id of its registrants. */
function cs_make_event($name, $survey_id) {
	$event = new Event(NULL);
	$event->set('evt_name', 'HarnessTest ' . $name . ' ' . bin2hex(random_bytes(3)));
	$event->set('evt_start_time', gmdate('Y-m-d H:i:s', time() + 86400));
	$event->set('evt_end_time', gmdate('Y-m-d H:i:s', time() + 90000));
	$event->set('evt_status', Event::STATUS_ACTIVE);
	$event->set('evt_svy_survey_id', (int)$survey_id);
	$event->save();
	$event->load();
	harness_register_row('evt_events', 'evt_event_id', $event->key);
	return $event;
}

function cs_seat($event, $user) {
	$reg = new EventRegistrant(NULL);
	$reg->set('evr_evt_event_id', $event->key);
	$reg->set('evr_usr_user_id', $user->key);
	$reg->save();
	$reg->load();
	harness_register_row('evr_event_registrants', 'evr_event_registrant_id', $reg->key);
	return $reg;
}

/** Submit as $user. */
function cs_submit($user, $survey_id, $event_id, $question_id, $answer) {
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $user->key;
	$_SESSION['loggedin'] = true;
	return harness_call_logic(
		'plugins/event_manager/logic/checkout_submit_survey_logic.php',
		'checkout_submit_survey_logic',
		array(
			'survey_id' => $survey_id,
			'event_id'  => $event_id,
			'question_' . $question_id => $answer,
		), 'POST');
}

/** How many answers exist for this survey+user. */
function cs_answer_count($survey_id, $user_id) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT COUNT(*) FROM sva_survey_answers
		WHERE sva_svy_survey_id = ? AND sva_usr_user_id = ?");
	$q->execute(array($survey_id, $user_id));
	return (int)$q->fetchColumn();
}

list($survey, $question) = cs_make_survey('ConfirmSurvey');
$event = cs_make_event('SurveyEvent', $survey->key);

$buyer = make_user('CsBuyer');
$stranger = make_user('CsStranger');
cs_seat($event, $buyer);

harness_defer(function () use ($survey) {
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare("DELETE FROM sva_survey_answers WHERE sva_svy_survey_id = ?")->execute(array($survey->key));
	} catch (\Throwable $e) {
		echo "  WARNING: could not clean survey answers: " . $e->getMessage() . "\n";
	}
});

// ---------------------------------------------------------------------------
section('The registration requirement');

// The core case: someone who never bought the event posting into its survey.
$res = cs_submit($stranger, $survey->key, $event->key, $question->key, 'stranger answer');
check($res->error !== null, 'a user with no registration for the event is refused',
	'error: ' . var_export($res->error, true));
check(cs_answer_count($survey->key, $stranger->key) === 0,
	'the refused submission wrote no answers',
	'answers: ' . cs_answer_count($survey->key, $stranger->key));

// Anonymous.
$_SESSION = array();
$res = harness_call_logic(
	'plugins/event_manager/logic/checkout_submit_survey_logic.php',
	'checkout_submit_survey_logic',
	array('survey_id' => $survey->key, 'event_id' => $event->key), 'POST');
check($res->error !== null, 'an anonymous caller is refused',
	'error: ' . var_export($res->error, true));

// Both identifiers are required — neither alone establishes anything.
$res = cs_submit($buyer, $survey->key, 0, $question->key, 'no event');
check($res->error !== null, 'a submission with no event_id is refused',
	'error: ' . var_export($res->error, true));

$res = cs_submit($buyer, 0, $event->key, $question->key, 'no survey');
check($res->error !== null, 'a submission with no survey_id is refused',
	'error: ' . var_export($res->error, true));

// ---------------------------------------------------------------------------
section('The survey belongs to the event');

// A registration for event A must not authorize answering an unrelated survey.
// Without the binding, holding any registration anywhere opens every survey.
list($other_survey, $other_question) = cs_make_survey('UnrelatedSurvey');

$res = cs_submit($buyer, $other_survey->key, $event->key, $other_question->key, 'wrong survey');
check($res->error !== null,
	'a survey not attached to the event is refused even for a real registrant',
	'error: ' . var_export($res->error, true));
check(cs_answer_count($other_survey->key, $buyer->key) === 0,
	'no answers landed in the unrelated survey',
	'answers: ' . cs_answer_count($other_survey->key, $buyer->key));

// A nonexistent event cannot be used to reach a survey.
$res = cs_submit($buyer, $survey->key, 999999999, $question->key, 'ghost event');
check($res->error !== null, 'a reference to a missing event is refused',
	'error: ' . var_export($res->error, true));

// ---------------------------------------------------------------------------
section('Replay guard');

check(cs_answer_count($survey->key, $buyer->key) === 0,
	'the registrant starts with no answers');

$res = cs_submit($buyer, $survey->key, $event->key, $question->key, 'It was good');
check($res->error === null, 'the registrant may answer their own event survey',
	'error: ' . var_export($res->error, true));
check(cs_answer_count($survey->key, $buyer->key) === 1,
	'one answer was written',
	'answers: ' . cs_answer_count($survey->key, $buyer->key));

$registrant = EventRegistrant::check_if_registrant_exists($buyer->key, $event->key);
check($registrant && $registrant->get('evr_survey_completed'),
	'the registration is marked survey-complete');

// The page posts by fetch; a retry or double-click must not append a second set.
$res = cs_submit($buyer, $survey->key, $event->key, $question->key, 'It was good again');
check($res->error === null,
	'a resubmission reports success rather than an error the page cannot act on',
	'error: ' . var_export($res->error, true));
check(cs_answer_count($survey->key, $buyer->key) === 1,
	'a resubmission does not append a second answer',
	'answers: ' . cs_answer_count($survey->key, $buyer->key));

for ($i = 0; $i < 4; $i++) {
	cs_submit($buyer, $survey->key, $event->key, $question->key, 'spam ' . $i);
}
check(cs_answer_count($survey->key, $buyer->key) === 1,
	'repeated replays still leave exactly one answer',
	'answers: ' . cs_answer_count($survey->key, $buyer->key));

// ---------------------------------------------------------------------------
section('Answers written');

$q = $db->prepare("SELECT sva_answer, sva_qst_question_id, sva_usr_user_id FROM sva_survey_answers
	WHERE sva_svy_survey_id = ? AND sva_usr_user_id = ?");
$q->execute(array($survey->key, $buyer->key));
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

check(count($rows) === 1, 'exactly one answer row exists', 'rows: ' . count($rows));
if ($rows) {
	check((int)$rows[0]['sva_qst_question_id'] === (int)$question->key,
		'the answer is attributed to the right question');
	check((int)$rows[0]['sva_usr_user_id'] === (int)$buyer->key,
		'the answer is attributed to the person who submitted it');
	check(strpos((string)$rows[0]['sva_answer'], 'It was good') !== false,
		'the first answer is the one stored, not a later replay',
		'stored: ' . var_export($rows[0]['sva_answer'], true));
}

// A second registrant answers independently of the first.
$buyer2 = make_user('CsBuyer2');
cs_seat($event, $buyer2);
$res = cs_submit($buyer2, $survey->key, $event->key, $question->key, 'Different view');
check($res->error === null, 'a second registrant may answer',
	'error: ' . var_export($res->error, true));
check(cs_answer_count($survey->key, $buyer2->key) === 1,
	'the second registrant gets their own answer row');
check(cs_answer_count($survey->key, $buyer->key) === 1,
	'the first registrant\'s answer is untouched');

$_SESSION = array();
harness_finish();
