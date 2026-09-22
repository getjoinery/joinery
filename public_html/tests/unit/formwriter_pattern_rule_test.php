<?php
/** @joinery-test
 * name: formwriter_pattern_rule
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * PURPOSE: Pins how a declared `pattern` validation rule reaches the browser.
 *
 * A rule is one PHP regex used twice — preg_match() on the server and
 * new RegExp() in the browser. PHP wraps it in a delimiter of the author's
 * choosing with flags after it; JavaScript needs the bare source and the flags
 * apart. The conversion once stripped only /.../, so every #...# rule (the
 * Stripe keys, the URL settings) reached the browser with a literal # in the
 * source, could never match, and refused every correct value before the form
 * was sent while the server would have accepted it.
 *
 * What is pinned:
 *   - phpRegexToJs() strips /, #, ~ and bracket-pair delimiters
 *   - flags JavaScript shares (i m s u) carry over; the others are dropped
 *   - a bare pattern (no delimiter) passes through unchanged
 *   - the script an HTML5 form emits carries {source, flags} for the rule,
 *     never the delimiter
 *   - the server-side validator still refuses and accepts the same values
 *
 * Run:  php tests/unit/formwriter_pattern_rule_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

// FormWriter construction starts a session; do it before the harness prints.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

section('phpRegexToJs strips every PHP delimiter');
$cases = array(
    '#^sk_(live|test)_[a-zA-Z0-9]{24,}$#' => array('source' => '^sk_(live|test)_[a-zA-Z0-9]{24,}$', 'flags' => ''),
    '/^abc$/'                             => array('source' => '^abc$', 'flags' => ''),
    '~a/b~'                               => array('source' => 'a/b', 'flags' => ''),
    '{^x$}'                               => array('source' => '^x$', 'flags' => ''),
    '(^y$)'                               => array('source' => '^y$', 'flags' => ''),
);
foreach ($cases as $in => $want) {
    check(FormWriterV2Base::phpRegexToJs($in) === $want, "delimiter stripped: $in", json_encode(FormWriterV2Base::phpRegexToJs($in)));
}

section('flags: shared ones carry, PHP-only ones drop');
check(FormWriterV2Base::phpRegexToJs('#^(/|https?://)#i') === array('source' => '^(/|https?://)', 'flags' => 'i'), 'i carries');
check(FormWriterV2Base::phpRegexToJs('/a/msu') === array('source' => 'a', 'flags' => 'msu'), 'm s u carry');
check(FormWriterV2Base::phpRegexToJs('/a/xDU') === array('source' => 'a', 'flags' => ''), 'x D U dropped');
check(FormWriterV2Base::phpRegexToJs('/a/ii') === array('source' => 'a', 'flags' => 'i'), 'a repeated flag is emitted once');

section('a bare pattern passes through');
check(FormWriterV2Base::phpRegexToJs('^bare$') === '^bare$', 'no delimiter: unchanged');
check(FormWriterV2Base::phpRegexToJs('') === '', 'empty: unchanged');
check(FormWriterV2Base::phpRegexToJs('#unterminated') === '#unterminated', 'no closing delimiter: unchanged');

section('the emitted form script carries {source, flags}');
$fw = new FormWriterV2HTML5('pattern_form', array('csrf' => false));
ob_start();
$fw->begin_form();
$fw->textinput('stripe_key', 'Stripe key', array(
    'validation' => array(
        'pattern'  => '#^sk_(live|test)_[a-zA-Z0-9]{24,}$#i',
        'messages' => array('pattern' => 'Must start with sk_live_ or sk_test_'),
    ),
));
$fw->submitbutton('btn_submit', 'Save');
$fw->end_form();
$html = ob_get_clean();
$expected = json_encode(array('source' => '^sk_(live|test)_[a-zA-Z0-9]{24,}$', 'flags' => 'i'), JSON_UNESCAPED_SLASHES);
check(strpos($html, '"pattern":' . $expected) !== false, 'rules JSON carries the object form', substr($html, max(0, strpos($html, '"pattern"') - 20), 160));
check(strpos($html, '"pattern":"#') === false, 'no delimiter reaches the browser');
check(strpos($html, 'Must start with sk_live_ or sk_test_') !== false, 'the declared message reaches the browser');

section('server-side validation is unchanged');
$v = new FormWriterV2HTML5('pattern_check', array('csrf' => false));
$v->registerValidationField('k', array('pattern' => '#^sk_(live|test)_[a-zA-Z0-9]{24,}$#', 'messages' => array('pattern' => 'bad key')), 'Key');
$v->validate(array('k' => 'sk_test_51' . str_repeat('Ab9', 33)));
check(empty($v->getErrors()), 'a well-formed test secret key passes');
$v2 = new FormWriterV2HTML5('pattern_check2', array('csrf' => false));
$v2->registerValidationField('k', array('pattern' => '#^sk_(live|test)_[a-zA-Z0-9]{24,}$#', 'messages' => array('pattern' => 'bad key')), 'Key');
$v2->validate(array('k' => 'pk_test_' . str_repeat('a', 30)));
check(!empty($v2->getErrors()['k']), 'a publishable key in the secret slot is refused');

harness_finish();
