<?php
/** @joinery-test
 * name: survey_answer
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Standalone surveys: whether an answer is accepted, and what gets stored.
 *
 * Two layers are covered. Question::validate_answers() is the rule engine —
 * it decides whether one submitted value satisfies the rules an admin attached
 * to a question. survey_logic() is the submission path — it decides which
 * questions get validated at all, and turns accepted answers into rows.
 *
 * The property that matters most is that a required question cannot be
 * satisfied by silence. The browser enforces required-ness, so the only way to
 * skip a question is to post without its field; if the server validates only
 * the fields that arrived, an omitted required question is indistinguishable
 * from a complete survey and the submitter is sent to the finish page. That is
 * a server-side gap that no amount of client validation closes.
 *
 * The rule checks are equally load-bearing because both the integer and
 * decimal rules were, until they were fixed, unsatisfiable: integer compared a
 * posted string with is_integer() (always false for form input), and decimal
 * tested an undefined variable. A question carrying either rule could not be
 * answered by anyone. Both sides are pinned here against the client-side rules
 * output_js_validation() advertises, so server and browser cannot drift into
 * accepting different sets of values.
 *
 * Sections: the rule engine; required-ness across the submission path; what
 * gets persisted; re-submission; and answer rendering.
 *
 * Run: php tests/functional/surveys/survey_answer_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/surveys_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/question_options_class.php'));
require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));

if (session_id() === '') @session_start();

$db = DbConnector::get_instance()->get_db_link();

// --- Fixture builders -------------------------------------------------------

/** A question of the given type, with optional serialized validation rules. */
function sa_make_question($text, $type = Question::TYPE_SHORT_TEXT, $rules = array()) {
	$q = new Question(NULL);
	$q->set('qst_question', $text);
	$q->set('qst_type', $type);
	if (!empty($rules)) {
		$q->set('qst_validate', serialize($rules));
	}
	$q->save();
	$q->load();
	harness_register_row('qst_questions', 'qst_question_id', $q->key);
	return $q;
}

function sa_make_option($question, $label, $value) {
	$o = new QuestionOption(NULL);
	$o->set('qop_qst_question_id', $question->key);
	$o->set('qop_question_option_label', $label);
	$o->set('qop_question_option_value', $value);
	$o->save();
	$o->load();
	harness_register_row('qop_question_options', 'qop_question_option_id', $o->key);
	return $o;
}

function sa_make_survey($name) {
	$s = new Survey(NULL);
	$s->set('svy_name', 'HarnessTest ' . $name . ' ' . bin2hex(random_bytes(3)));
	$s->save();
	$s->load();
	harness_register_row('svy_surveys', 'svy_survey_id', $s->key);
	return $s;
}

function sa_attach($survey, $question, $order = 1) {
	$sq = new SurveyQuestion(NULL);
	$sq->set('srq_svy_survey_id', $survey->key);
	$sq->set('srq_qst_question_id', $question->key);
	$sq->set('srq_order', $order);
	$sq->save();
	$sq->load();
	harness_register_row('srq_survey_questions', 'srq_survey_question_id', $sq->key);
	return $sq;
}

/** Rows actually written for one user's pass at one survey. */
function sa_answers($survey_id, $user_id) {
	global $db;
	$q = $db->prepare('SELECT sva_qst_question_id, sva_answer FROM sva_survey_answers
		WHERE sva_svy_survey_id = ? AND sva_usr_user_id = ? ORDER BY sva_qst_question_id');
	$q->execute([$survey_id, $user_id]);
	$out = array();
	foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$out[$row['sva_qst_question_id']] = $row['sva_answer'];
	}
	return $out;
}

$user = make_user('survey_answer');

// ============================================================================
section('The rule engine: what one answer must satisfy');
// ============================================================================

// Rules are stored serialized on the question, and every rule is keyed by
// presence rather than value — array_key_exists, not truthiness — so a rule
// that is present at all is in force.

$q_required = sa_make_question('Required probe', Question::TYPE_SHORT_TEXT, array('required' => 1));
check($q_required->validate_answers('') !== 'valid',
	'required rule refuses an empty string');
check($q_required->validate_answers(NULL) !== 'valid',
	'required rule refuses a missing answer (NULL)');
check($q_required->validate_answers(array()) !== 'valid',
	'required rule refuses an empty set of checkbox selections');
check($q_required->validate_answers('0') === 'valid',
	'required rule accepts "0", which is a real answer and not an absence');
check($q_required->validate_answers('yes') === 'valid',
	'required rule accepts ordinary text');

$q_int = sa_make_question('Integer probe', Question::TYPE_SHORT_TEXT, array('integer' => 1));
check($q_int->validate_answers('5') === 'valid',
	'integer rule accepts a posted digit string',
	'form input is always a string; rejecting it makes the question unanswerable');
check($q_int->validate_answers('0') === 'valid',
	'integer rule accepts "0"');
check($q_int->validate_answers('5.5') !== 'valid',
	'integer rule refuses a fractional value');
check($q_int->validate_answers('abc') !== 'valid',
	'integer rule refuses non-numeric text');
check($q_int->validate_answers('') !== 'valid',
	'integer rule refuses an empty string');

$q_dec = sa_make_question('Decimal probe', Question::TYPE_SHORT_TEXT, array('decimal' => 1));
check($q_dec->validate_answers('1.5') === 'valid',
	'decimal rule accepts a fractional value');
check($q_dec->validate_answers('5') === 'valid',
	'decimal rule accepts a whole number, matching the client-side number rule');
check($q_dec->validate_answers('-2.25') === 'valid',
	'decimal rule accepts a negative value');
check($q_dec->validate_answers('abc') !== 'valid',
	'decimal rule refuses non-numeric text');

// The rule the browser is told to apply and the rule the server applies have
// to be the same rule, or a submission the browser lets through is refused by
// the server (or worse, the reverse).
$js_int = $q_int->output_js_validation(array());
check(isset($js_int['question_' . $q_int->key]['digits']),
	'the integer rule advertises "digits" to the browser',
	'server-side ctype_digit accepts exactly this set');
$js_dec = $q_dec->output_js_validation(array());
check(isset($js_dec['question_' . $q_dec->key]['number']),
	'the decimal rule advertises "number" to the browser',
	'server-side is_numeric accepts exactly this set');

$q_len = sa_make_question('Length probe', Question::TYPE_SHORT_TEXT,
	array('min_length' => 3, 'max_length' => 8));
check($q_len->validate_answers('abcde') === 'valid',
	'a value inside the length bounds is accepted');
check($q_len->validate_answers('ab') !== 'valid',
	'a value under min_length is refused');
check($q_len->validate_answers('abcdefghij') !== 'valid',
	'a value over max_length is refused');

$q_val = sa_make_question('Value probe', Question::TYPE_SHORT_TEXT,
	array('min_value' => 10, 'max_value' => 20));
check($q_val->validate_answers('15') === 'valid',
	'a value inside the numeric bounds is accepted');
check($q_val->validate_answers('9') !== 'valid',
	'a value under min_value is refused');
check($q_val->validate_answers('21') !== 'valid',
	'a value over max_value is refused');

$q_unruled = sa_make_question('No rules probe');
check($q_unruled->validate_answers('anything at all') === 'valid',
	'a question with no rules accepts any answer');
check($q_unruled->validate_answers('') === 'valid',
	'a question with no required rule accepts a blank answer');

// A checkbox-list answer arrives as an array. Every rule below required
// measures a single value, so the array has to be reduced to the form that
// actually gets stored — otherwise strlen() on an array is a fatal error, and
// a length limit on a multi-select question takes the whole page down.
$q_multi_len = sa_make_question('Multi length probe', Question::TYPE_CHECKBOX_LIST,
	array('max_length' => 5));
$multi_short = $q_multi_len->validate_answers(array('a', 'b'));
check($multi_short === 'valid',
	'an array answer within the length limit is accepted, not fataled on',
	'strlen() of an array would be a TypeError');
check($q_multi_len->validate_answers(array('aaa', 'bbb')) !== 'valid',
	'an array answer is measured as its stored comma-joined form',
	'"aaa,bbb" is 7 characters against a limit of 5');

// ============================================================================
section('Required-ness survives the submission path');
// ============================================================================

// The rule engine refusing a blank answer is only half the property. The
// submission path decides which questions reach the rule engine at all, and a
// direct post simply omits the field it does not want to answer.

$survey_req = sa_make_survey('required');
$q_req_a = sa_make_question('Your name', Question::TYPE_SHORT_TEXT, array('required' => 1));
$q_opt_b = sa_make_question('Anything to add?', Question::TYPE_LONG_TEXT);
sa_attach($survey_req, $q_req_a, 1);
sa_attach($survey_req, $q_opt_b, 2);

/** Submit the survey as a signed-in member. */
function sa_signin($user) {
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = $user->get('usr_permission');
}
sa_signin($user);

$encoded_req = LibraryFunctions::encode($survey_req->key);

// Omitted entirely — the shape a direct post takes.
$res_omitted = harness_call_logic('logic/survey_logic.php', 'survey_logic', array(
	'survey_id' => $encoded_req,
), 'POST');

check(empty($res_omitted->redirect),
	'a post omitting a required question is not redirected to the finish page',
	'redirecting would report the survey complete when it is not');
check(!empty($res_omitted->data['invalid_messages']),
	'a post omitting a required question reports why it was refused',
	'the submitter has to be told which question is unanswered');
check(count(sa_answers($survey_req->key, $user->key)) === 0,
	'nothing is stored for a submission that failed validation');

// Present but blank — the shape a form post takes when the browser is bypassed
// with an empty field rather than a missing one.
$res_blank = harness_call_logic('logic/survey_logic.php', 'survey_logic', array(
	'survey_id' => $encoded_req,
	'question_' . $q_req_a->key => '',
), 'POST');
check(empty($res_blank->redirect),
	'a post with the required field present but blank is also refused');
check(count(sa_answers($survey_req->key, $user->key)) === 0,
	'a blank required answer stores nothing');

// Answered — the survey completes, and the optional question left out does not
// block it.
$res_ok = harness_call_logic('logic/survey_logic.php', 'survey_logic', array(
	'survey_id' => $encoded_req,
	'question_' . $q_req_a->key => 'Alex Rivera',
), 'POST');
check(!empty($res_ok->redirect),
	'answering the required question completes the survey');
check(strpos((string)$res_ok->redirect, '/survey_finish') !== false,
	'completion redirects to the finish page');

$stored_req = sa_answers($survey_req->key, $user->key);
check(isset($stored_req[$q_req_a->key]) && $stored_req[$q_req_a->key] === 'Alex Rivera',
	'the answered required question is stored');
check(!isset($stored_req[$q_opt_b->key]),
	'an optional question left unanswered stores no row',
	'an absent answer is not the same as an empty answer');

// ============================================================================
section('What gets persisted');
// ============================================================================

$survey_store = sa_make_survey('storage');
$q_text = sa_make_question('Free text');
$q_list = sa_make_question('Pick some', Question::TYPE_CHECKBOX_LIST);
sa_make_option($q_list, 'Red', 'red');
sa_make_option($q_list, 'Green', 'green');
sa_make_option($q_list, 'Blue', 'blue');
sa_attach($survey_store, $q_text, 1);
sa_attach($survey_store, $q_list, 2);

$encoded_store = LibraryFunctions::encode($survey_store->key);

harness_call_logic('logic/survey_logic.php', 'survey_logic', array(
	'survey_id' => $encoded_store,
	'question_' . $q_text->key => '  <b>bold</b> answer  ',
	'question_' . $q_list->key => array('red', 'blue'),
), 'POST');

$stored = sa_answers($survey_store->key, $user->key);
check(isset($stored[$q_text->key]),
	'a text answer is stored');
check(isset($stored[$q_text->key]) && strpos($stored[$q_text->key], '<b>') === false,
	'markup is stripped from a stored answer',
	'answers are rendered back to admins, so tags must not survive');
check(isset($stored[$q_text->key]) && $stored[$q_text->key] === 'bold answer',
	'surrounding whitespace is trimmed from a stored answer');
check(isset($stored[$q_list->key]) && $stored[$q_list->key] === 'red,blue',
	'a multi-select answer is stored as its comma-joined values');

// ============================================================================
section('Re-submission updates rather than accumulates');
// ============================================================================

// The survey page can be submitted more than once — a correction, a back
// button, a double-click. One user answering one question is one row, enforced
// both by the model and by the unique_with constraint behind it.

$before = count(sa_answers($survey_store->key, $user->key));
harness_call_logic('logic/survey_logic.php', 'survey_logic', array(
	'survey_id' => $encoded_store,
	'question_' . $q_text->key => 'corrected answer',
	'question_' . $q_list->key => array('green'),
), 'POST');
$after = sa_answers($survey_store->key, $user->key);

check(count($after) === $before,
	're-submitting does not add a second set of answers',
	"before=$before after=" . count($after));
check(isset($after[$q_text->key]) && $after[$q_text->key] === 'corrected answer',
	're-submitting replaces the previous text answer');
check(isset($after[$q_list->key]) && $after[$q_list->key] === 'green',
	're-submitting replaces the previous multi-select answer');

// The model-level guard behind that behaviour, exercised directly: a second
// row for the same (survey, question, user) is refused rather than written.
$dup = new SurveyAnswer(NULL);
$dup->set('sva_svy_survey_id', $survey_store->key);
$dup->set('sva_qst_question_id', $q_text->key);
$dup->set('sva_usr_user_id', $user->key);
$dup->set('sva_answer', 'sneaky duplicate');
$dup_refused = false;
try {
	$dup->save();
	harness_register_row('sva_survey_answers', 'sva_survey_answer_id', $dup->key);
} catch (Exception $e) {
	$dup_refused = true;
}
check($dup_refused,
	'a duplicate answer row for the same survey, question and user is refused');
check(count(sa_answers($survey_store->key, $user->key)) === $before,
	'the refused duplicate left no row behind');

// A different user answering the same question is a different answer, not a
// duplicate — the constraint is scoped to the user, not the question.
$other_user = make_user('survey_answer_other');
$other = new SurveyAnswer(NULL);
$other->set('sva_svy_survey_id', $survey_store->key);
$other->set('sva_qst_question_id', $q_text->key);
$other->set('sva_usr_user_id', $other_user->key);
$other->set('sva_answer', 'a second respondent');
$other->save();
$other->load();
harness_register_row('sva_survey_answers', 'sva_survey_answer_id', $other->key);
check($other->key > 0,
	'a second user may answer the same question');
check(count(sa_answers($survey_store->key, $other_user->key)) === 1,
	"the second user's answers are scoped to that user");

// ============================================================================
section('Rendering an answer back');
// ============================================================================

// Stored answers are option values; an admin reading a report needs the label.

$q_drop = sa_make_question('Favourite colour', Question::TYPE_DROPDOWN);
sa_make_option($q_drop, 'Crimson', 'c1');
sa_make_option($q_drop, 'Cerulean', 'c2');

$readable = $q_drop->get_answer_readable('c1');
check(strpos($readable, 'Crimson') !== false,
	'a stored option value renders with its label');
check($q_drop->get_answer_readable('c9') === 'c9',
	'an option value with no matching option falls back to the raw value',
	'options can be removed after an answer was recorded');

$q_esc = sa_make_question('Escaping probe');
$escaped = $q_esc->get_answer_readable('<script>alert(1)</script>');
check(strpos($escaped, '<script>') === false,
	'a free-text answer is escaped by default');
$raw = $q_esc->get_answer_readable('<script>alert(1)</script>', false);
check(strpos($raw, '<script>') !== false,
	'escaping can be declined explicitly for callers that escape later');

$q_conf = sa_make_question('Terms', Question::TYPE_CONFIRMATION);
check($q_conf->get_answer_readable('1') === 'Yes',
	'a confirmation answer renders as Yes');
check($q_conf->get_answer_readable('') === 'No',
	'an unconfirmed answer renders as No');

$multi_readable = $q_list->get_answer_readable('red,blue');
check(strpos($multi_readable, 'Red') !== false && strpos($multi_readable, 'Blue') !== false,
	'a multi-select answer renders every selected label');
check(strpos($multi_readable, 'Green') === false,
	'a multi-select answer renders only the selected labels');

// The input an admin's rules produce. A recording stand-in for FormWriter
// captures the options the question asks for, which is the contract between a
// question's rules and the field the member actually types into.
class SaRecordingFormWriter {
	public $calls = array();
	public function __call($method, $args) {
		$this->calls[] = array('method' => $method, 'name' => $args[0], 'options' => $args[2] ?? array());
	}
}

$q_capped = sa_make_question('Long answer', Question::TYPE_SHORT_TEXT, array('max_length' => 500));
$fw = new SaRecordingFormWriter();
$q_capped->output_question($fw);
$capped_call = $fw->calls[0] ?? null;
check($capped_call !== null && $capped_call['method'] === 'textinput',
	'a short-text question renders a text input');
check($capped_call !== null && (int)$capped_call['options']['maxlength'] === 500,
	"the input's typing cap follows the question's max_length rule",
	'a cap of 255 would refuse input the server would accept; got '
		. var_export($capped_call['options']['maxlength'] ?? null, true));
check($capped_call !== null && !empty($capped_call['options']['validation']['maxlength']),
	'the same limit is advertised to client-side validation');

$q_uncapped = sa_make_question('Ordinary answer');
$fw2 = new SaRecordingFormWriter();
$q_uncapped->output_question($fw2);
check(isset($fw2->calls[0]) && (int)$fw2->calls[0]['options']['maxlength'] === 255,
	'a question with no max_length rule keeps the default cap');

// The option list a form is built from.
$opts = new MultiQuestionOption(array('question_id' => $q_drop->key));
$opts->load();
$dropdown = $opts->get_dropdown_array();
check(isset($dropdown['c1']) && $dropdown['c1'] === 'Crimson',
	'the form option list maps stored value to displayed label');
check(count($dropdown) === 2,
	'the option list is scoped to its own question',
	'found ' . count($dropdown) . ' options');

harness_finish();
