<?php
/** @joinery-test
 * name: descriptor_validator_base
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Type coercion at the API boundary.
 *
 * `DescriptorValidator::coerce()` turns a request body into typed values before
 * an action's logic runs, so it decides what "3" means, what an absent optional
 * field looks like to the logic, and which requests are refused outright. The
 * companion suite `descriptor_validator_pipeline_test.php` covers the additions
 * made for pipeline verdicts — enum, min/max, max_length, nested arrays. This
 * one covers the base: the scalar types themselves, required-ness, defaults,
 * and what happens to fields nobody declared.
 *
 * Two behaviours here are load-bearing and easy to get wrong in opposite
 * directions. Coercion is deliberately narrow — '3' becomes 3 but '3.7' is
 * refused for an int field rather than silently truncated to 3, because a
 * quantity arriving as 3.7 is a caller bug and rounding it hides that. And an
 * undeclared field is dropped rather than passed through, so a descriptor is a
 * whitelist: adding a parameter to a request cannot reach the logic behind it.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/descriptor_validator_base_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DescriptorValidator.php'));

/** Coerce $input against a one-field schema and report success or refusal. */
function dv($spec, $input, $field = 'f') {
	$descriptor = array('input' => array($field => $spec));
	try {
		return array('ok' => true, 'out' => DescriptorValidator::coerce($descriptor, $input));
	} catch (InvalidArgumentException $e) {
		return array('ok' => false, 'message' => $e->getMessage());
	}
}
/** The coerced value of field f, or a marker when it is absent. */
function dv_val($result, $field = 'f') {
	if (!$result['ok']) { return '<<refused>>'; }
	return array_key_exists($field, $result['out']) ? $result['out'][$field] : '<<absent>>';
}


section('Integers');

$int = array('type' => 'int');
check(dv_val(dv($int, array('f' => 5))) === 5, 'An int stays an int');
check(dv_val(dv($int, array('f' => '5'))) === 5, 'A numeric string becomes an int');
check(dv_val(dv($int, array('f' => '-5'))) === -5, 'A negative numeric string becomes an int');
check(dv_val(dv($int, array('f' => '0'))) === 0, 'Zero as a string becomes int zero');

// Narrowness is the point: a value that is not an integer is refused rather
// than truncated, because a quantity of 3.7 means the caller is confused and
// silently shipping 3 hides it behind a plausible order.
check(dv($int, array('f' => '3.7'))['ok'] === false, 'A decimal string is refused, not truncated');
check(dv($int, array('f' => 3.7))['ok'] === false, 'A float is refused for an int field');
check(dv($int, array('f' => 'abc'))['ok'] === false, 'Non-numeric text is refused');
check(dv($int, array('f' => true))['ok'] === false, 'A boolean is not an integer');

// 'integer' is the same type under a second name; both spellings appear in
// descriptors across the codebase.
check(dv_val(dv(array('type' => 'integer'), array('f' => '7'))) === 7, 'integer is a synonym for int');


section('Floats and numbers');

$float = array('type' => 'float');
check(dv_val(dv($float, array('f' => 3.5))) === 3.5, 'A float stays a float');
check(dv_val(dv($float, array('f' => '3.5'))) === 3.5, 'A numeric string becomes a float');
check(dv_val(dv($float, array('f' => 3))) === 3.0, 'An int widens to a float');
check(is_float(dv_val(dv($float, array('f' => 3)))), 'and is genuinely a float, not left an int');
check(dv($float, array('f' => 'abc'))['ok'] === false, 'Non-numeric text is refused');
check(dv_val(dv(array('type' => 'number'), array('f' => '1.25'))) === 1.25, 'number is a synonym for float');


section('Booleans');

$bool = array('type' => 'bool');
check(dv_val(dv($bool, array('f' => true))) === true, 'true stays true');
check(dv_val(dv($bool, array('f' => false))) === false, 'false stays false');
check(dv_val(dv($bool, array('f' => '1'))) === true, 'The string 1 is true');
check(dv_val(dv($bool, array('f' => 'true'))) === true, 'The string true is true');
check(dv_val(dv($bool, array('f' => 'on'))) === true, 'A checked HTML checkbox value is true');
check(dv_val(dv($bool, array('f' => '0'))) === false, 'The string 0 is false');
check(dv_val(dv($bool, array('f' => 'false'))) === false, 'The string false is false');
check(dv_val(dv($bool, array('f' => 'off'))) === false, 'off is false');

// Anything outside that vocabulary is refused rather than cast. PHP would call
// 'no' truthy, which is the opposite of what a caller sending it means — this
// is the single place where a permissive cast would invert an intent.
check(dv($bool, array('f' => 'no'))['ok'] === false, 'An unrecognised word is refused, not cast as truthy');
check(dv($bool, array('f' => 'yes'))['ok'] === false, 'yes is not in the accepted vocabulary either');
check(dv($bool, array('f' => 2))['ok'] === false, 'An arbitrary number is not a boolean');


section('Strings and formats');

check(dv_val(dv(array('type' => 'string'), array('f' => 'hello'))) === 'hello', 'A string passes through');
check(dv_val(dv(array('type' => 'string'), array('f' => 42))) === '42', 'A scalar is stringified');
check(dv_val(dv(array(), array('f' => 'x'))) === 'x', 'A spec with no type defaults to string');

check(dv_val(dv(array('type' => 'email'), array('f' => 'a@b.co'))) === 'a@b.co', 'A valid email passes');
check(dv(array('type' => 'email'), array('f' => 'not-an-email'))['ok'] === false, 'An invalid email is refused');

check(dv_val(dv(array('type' => 'date'), array('f' => '2026-07-19'))) === '2026-07-19', 'An ISO date passes');
check(dv(array('type' => 'date'), array('f' => '19/07/2026'))['ok'] === false, 'A non-ISO date is refused');
check(dv(array('type' => 'date'), array('f' => '2026-07-19 05:00:00'))['ok'] === false,
	'A date field refuses a datetime, so the two are not interchangeable');

check(dv_val(dv(array('type' => 'datetime'), array('f' => '2026-07-19 05:00:00'))) === '2026-07-19 05:00:00',
	'A datetime passes through unchanged');
check(dv(array('type' => 'datetime'), array('f' => 'whenever'))['ok'] === false, 'An unparseable datetime is refused');


section('Required, absent, and default');

$required = array('type' => 'string', 'required' => true, 'label' => 'Name');
check(dv($required, array('f' => 'x'))['ok'] === true, 'A required field that is present passes');
check(dv($required, array())['ok'] === false, 'A missing required field is refused');
check(dv($required, array('f' => null))['ok'] === false, 'An explicit null does not satisfy required');

// Empty string counts as absent, so a submitted-but-blank form field cannot
// satisfy a required parameter — the same rule the survey validator applies.
check(dv($required, array('f' => ''))['ok'] === false, 'An empty string does not satisfy required');

$refusal = dv($required, array());
check(strpos($refusal['message'], 'Name') !== false && strpos($refusal['message'], 'f') !== false,
	'The refusal names both the label and the field', $refusal['message']);

// An absent optional field is left out of the result entirely rather than
// being handed over as null, so logic reads it with ?? and can tell "not sent"
// from "sent as empty".
$optional = array('type' => 'string');
check(dv_val(dv($optional, array())) === '<<absent>>', 'An absent optional field is omitted, not nulled');
check(dv_val(dv($optional, array('f' => ''))) === '<<absent>>', 'An empty optional field is omitted too');

// Unless a default is declared, in which case that is what the logic sees.
$defaulted = array('type' => 'int', 'default' => 10);
check(dv_val(dv($defaulted, array())) === 10, 'An absent field falls back to its default');
check(dv_val(dv($defaulted, array('f' => ''))) === 10, 'An empty field falls back to its default');
check(dv_val(dv($defaulted, array('f' => 3))) === 3, 'A supplied value beats the default');

// The default is handed over as declared, without being run through coercion —
// worth knowing, because a default of '10' on an int field reaches the logic
// as a string.
check(dv_val(dv(array('type' => 'int', 'default' => '10'), array())) === '10',
	'A default is passed through as written rather than coerced',
	var_export(dv_val(dv(array('type' => 'int', 'default' => '10'), array())), true));


section('The descriptor is a whitelist');

// A field nobody declared is dropped. This is what makes a descriptor a
// boundary rather than documentation: an extra parameter on the request cannot
// reach the logic behind it, so a caller cannot smuggle in a value the action
// never advertised.
$descriptor = array('input' => array('wanted' => array('type' => 'string')));
$out = DescriptorValidator::coerce($descriptor, array('wanted' => 'yes', 'sneaky' => 'value'));
check($out === array('wanted' => 'yes'), 'An undeclared field is dropped', json_encode($out));
check(!array_key_exists('sneaky', $out), 'and specifically does not reach the logic');

// A malformed spec is skipped rather than crashing the boundary.
$out = DescriptorValidator::coerce(array('input' => array('bad' => 'not-a-spec')), array('bad' => 'x'));
check($out === array(), 'A field whose spec is not an array is skipped');

// A descriptor with no input schema accepts a request but passes nothing on.
check(DescriptorValidator::coerce(array(), array('a' => 1)) === array(),
	'A descriptor with no input schema yields no values');
check(DescriptorValidator::coerce(array('input' => array()), array('a' => 1)) === array(),
	'An empty input schema yields no values');


section('Several fields at once');

$descriptor = array('input' => array(
	'name'     => array('type' => 'string', 'required' => true),
	'quantity' => array('type' => 'int', 'default' => 1),
	'gift'     => array('type' => 'bool'),
));
$out = DescriptorValidator::coerce($descriptor, array('name' => 'Widget', 'quantity' => '3', 'gift' => 'on'));
check($out === array('name' => 'Widget', 'quantity' => 3, 'gift' => true),
	'A whole request coerces field by field', json_encode($out));

// One bad field refuses the whole request — there is no partial coercion for
// the logic to work with.
$refused = false;
try {
	DescriptorValidator::coerce($descriptor, array('name' => 'Widget', 'quantity' => 'lots'));
} catch (InvalidArgumentException $e) {
	$refused = true;
}
check($refused, 'One unusable field refuses the request rather than dropping that field');

harness_finish();
