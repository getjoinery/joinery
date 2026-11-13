<?php
/** @joinery-test
 * name: action_button_confirm
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * AdminPage::action_button()'s confirm text is often data — a sender's
 * display name, a post title. It goes on the button as kit attributes
 * (data-jy-confirm, read by assets/js/base.js), never as an inline handler,
 * so the strict script policy runs it (specs/strict_script_policy.md). An
 * apostrophe, quotes or a script tag in the text must come back from the
 * attribute exactly as written and never reach the markup as a tag.
 *
 * What the browser does with the attributes is pinned in
 * tests/unit/joinery_confirm_test.php.
 *
 * @version 2.0 - kit attributes in place of the inline onclick handler
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

/** The decoded value of one attribute on the rendered button, or null. */
function abc_attr(string $html, string $name) {
    if (!preg_match('/<button\b[^>]*\s' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m)) {
        return null;
    }
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

$hostile = "Remove O'Brien from the \"allowed\" list? </script><script>alert(1)</script>";

section('The confirm text survives an apostrophe, quotes and a script tag');
$html = AdminPage::action_button('Remove', '/x', array('hidden' => array('a' => '1'), 'confirm' => $hostile));
check(stripos($html, ' onclick=') === false, 'no inline handler on the button', $html);
check(strpos($html, '<script') === false, 'no raw script tag reaches the markup');
check(abc_attr($html, 'data-jy-confirm') === $hostile, 'the text comes back from data-jy-confirm exactly', (string)abc_attr($html, 'data-jy-confirm'));
check(abc_attr($html, 'data-jy-submit') === '' && strpos($html, '<button type="button"') !== false,
    'a plain button that submits its own form once confirmed');
check(abc_attr($html, 'data-jy-confirm-typed') === null, 'no typed phrase unless one is asked for');

section('The typed-phrase variant, and the confirm button\'s words and style');
$html2 = AdminPage::action_button('Delete', '/x', array('hidden' => array('a' => '1'),
    'confirm' => "Type the site's name", 'confirm_typed' => "Jeremy's site",
    'confirm_label' => 'Delete "it"', 'confirm_style' => 'primary'));
check(stripos($html2, ' onclick=') === false, 'no inline handler on the typed variant');
check(abc_attr($html2, 'data-jy-confirm-typed') === "Jeremy's site", 'the phrase to type comes back exactly');
check(abc_attr($html2, 'data-jy-confirm-label') === 'Delete "it"' && abc_attr($html2, 'data-jy-confirm-style') === 'primary',
    'the confirm button\'s words and style ride along');

section('With no confirm, it is a plain submit button');
$html3 = AdminPage::action_button('Go', '/x', array('hidden' => array('a' => '1')));
check(strpos($html3, '<button type="submit"') !== false && abc_attr($html3, 'data-jy-confirm') === null, 'no confirm, no kit attributes');

harness_finish();
