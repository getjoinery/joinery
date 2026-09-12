<?php
/** @joinery-test
 * name: action_button_confirm
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * AdminPage::action_button()'s confirm text is a JavaScript string literal
 * inside an HTML attribute, and the text is often data — a sender's display
 * name, a post title. An apostrophe in it must neither end the literal (the
 * button then does nothing: "missing ) after argument list") nor let the text
 * run as script on an admin page.
 *
 * The test runs the rendered handler through a real JS parser (node) when one
 * is on the box, and checks the encoding directly either way.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$hostile = "Remove O'Brien from the \"allowed\" list? </script><script>alert(1)</script>";

section('The confirm text survives an apostrophe, quotes and a script tag');
$html = AdminPage::action_button('Remove', '/x', array('hidden' => array('a' => '1'), 'confirm' => $hostile));
check(preg_match('/onclick="([^"]*)"/', $html, $m) === 1, 'the button carries an onclick attribute');
$onclick = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
check(strpos($html, '<script') === false, 'no raw script tag reaches the markup');
check(strpos($onclick, 'JoineryModal.confirm(') !== false, 'the handler calls the confirm modal');
check(strpos($onclick, json_encode($hostile, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) !== false,
    'the text is one JSON string literal in the decoded handler');

section('The typed-phrase variant encodes the same way');
$html2 = AdminPage::action_button('Delete', '/x', array('hidden' => array('a' => '1'), 'confirm' => "Type the site's name", 'confirm_typed' => "Jeremy's site"));
check(preg_match('/onclick="([^"]*)"/', $html2, $m2) === 1, 'typed variant has an onclick');
$onclick2 = html_entity_decode($m2[1], ENT_QUOTES, 'UTF-8');
check(strpos($onclick2, 'JoineryModal.confirmTyped(') !== false, 'typed variant calls confirmTyped');

section('A JS parser accepts both handlers');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    check(true, 'node not on this box — parser check skipped');
} else {
    foreach (array('confirm' => $onclick, 'confirmTyped' => $onclick2) as $label => $js) {
        $src = "var JoineryModal={confirm:function(){},confirmTyped:function(){}}; (function(){ var self={closest:function(){return {submit:function(){}}}}; (function(){" . str_replace('this.closest', 'self.closest', $js) . "}).call(self); })();";
        $tmp = tempnam(sys_get_temp_dir(), 'ab');
        file_put_contents($tmp, $src);
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        unlink($tmp);
        check($rc === 0, "{$label} handler parses as JavaScript", implode(' ', $out));
        $out = array();
    }
}

harness_finish();
