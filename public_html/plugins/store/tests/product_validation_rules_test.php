<?php
/** @joinery-test
 * name: product_validation_rules
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The client-side validation rules a product form emits, assembled from every
 * requirement's validation info (Product::assemble_validation_rules).
 *
 * specs/post_release_fleet_defects.md B4.8: a Question requirement's
 * ['value' => x] entry set its rule and then fell through to the positional
 * assignments, which overwrote it with an undefined $value when the Question
 * came first and with the previous entry's leftovers otherwise.
 *
 * Run: php plugins/store/tests/product_validation_rules_test.php
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('Product')) {
	harness_skip('the store plugin is not active here', 'Product does not resolve');
	harness_finish();
	exit;
}

$warnings = array();
set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });

section('A Question requirement that comes first keeps its rule');

$question = array('q_12' => array('required' => array('value' => 'true', 'message' => 'Answer this')));
$positional = array('ticket_name' => array('required' => array('true', 'Name is required')));
list($rules, $messages, $containers) = Product::assemble_validation_rules(array($question, $positional));
check($warnings === array(), 'no warning', implode('; ', $warnings));
check(($rules['q_12']['required'] ?? null) === 'true', 'the Question rule is the value it declared', json_encode($rules));
check(($messages['q_12']['required'] ?? null) === 'Answer this', 'with its message');
check(!isset($containers['q_12']), 'and no error container (the Question shape declares none)');
check(($rules['ticket_name']['required'] ?? null) === 'true' && ($containers['ticket_name'] ?? null) === 'ticket_name_container',
	'the positional entry after it is untouched');

section('A Question requirement after a positional entry keeps its own rule, not the leftovers');

$positional3 = array('shirt_size' => array('minlength' => array('2', 'Two characters', 'size_box')));
$question2 = array('q_7' => array('maxlength' => array('value' => '40')));
list($rules, $messages, $containers) = Product::assemble_validation_rules(array($positional3, $question2));
check(($rules['q_7']['maxlength'] ?? null) === '40', 'the Question rule is its own', json_encode($rules));
check(!isset($messages['q_7']['maxlength']), 'no message was borrowed from the entry before');
check(!isset($containers['q_7']), 'no container was borrowed either');
check(($containers['shirt_size'] ?? null) === 'size_box', 'the three-element positional shape keeps its container');

section('A bare value');

list($rules, $messages, $containers) = Product::assemble_validation_rules(array(array('f' => array('required' => 'true'))));
check(($rules['f']['required'] ?? null) === 'true' && $messages === array() && $containers === array(), 'a bare value is a rule and nothing else');

restore_error_handler();
harness_finish();
