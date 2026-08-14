<?php
/** @joinery-test
 * name: print_sanitizer
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Tests for MailboxHtmlSanitizer::sanitizeForPrint() — the only path that
 * renders RECEIVED (attacker-controlled) mail inside a document of ours, the
 * print sheet. Everywhere else received HTML stays behind a sandboxed iframe;
 * a printout cannot, because a browser prints only the visible slice of a
 * scrollable frame.
 *
 * So this allowlist carries real weight, and these cases are the contract:
 * layout survives (tables, alignment attributes, inline styles) while anything
 * that executes, fetches, or escapes the attribute does not. The print sheet
 * also runs under a script-forbidding CSP — that is the second layer, not an
 * excuse for a hole here.
 *
 * Pure function, no DB.
 *
 * Run: php plugins/mailbox/tests/print_sanitizer_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));

$print = function ($html) { return MailboxHtmlSanitizer::sanitizeForPrint($html); };

// ── Layout survives ──────────────────────────────────────────────────────────
section('An email still looks like the email');

$table = '<table width="600" bgcolor="#ffffff" cellpadding="8"><tr><td align="center" '
	. 'style="font-size:18px;color:#111;padding:12px">Hello</td></tr></table>';
$out = $print($table);
check(strpos($out, '<table') !== false, 'table survives', $out);
check(strpos($out, 'bgcolor="#ffffff"') !== false, 'bgcolor survives');
check(strpos($out, 'align="center"') !== false, 'align survives');
check(strpos($out, 'cellpadding="8"') !== false, 'cellpadding survives');
check(strpos($out, 'font-size:18px') !== false, 'inline font-size survives');
check(strpos($out, 'color:#111') !== false, 'inline color survives');

$out = $print('<td colspan="2" rowspan="3">x</td>');
check(strpos($out, 'colspan="2"') !== false && strpos($out, 'rowspan="3"') !== false,
	'colspan/rowspan survive', $out);

$out = $print('<font color="red" face="Arial" size="4">big</font>');
check(strpos($out, 'color="red"') !== false && strpos($out, 'face="Arial"') !== false,
	'<font> attributes survive', $out);

$out = $print('<h1>Head</h1><ul><li>one</li></ul><blockquote>quoted</blockquote><hr>');
foreach (array('<h1>', '<ul>', '<li>', '<blockquote>', '<hr') as $tag) {
	check(strpos($out, $tag) !== false, 'structural tag survives: ' . $tag, $out);
}

$out = $print('<img src="https://example.com/logo.png" alt="Logo" width="120">');
check(strpos($out, 'https://example.com/logo.png') !== false, 'http(s) image survives', $out);
check(strpos($out, 'alt="Logo"') !== false && strpos($out, 'width="120"') !== false,
	'image alt and width survive');

$out = $print('<a href="https://example.com/x">click</a>');
check(strpos($out, 'href="https://example.com/x"') !== false, 'link href survives', $out);
check(strpos($out, 'rel="noopener noreferrer nofollow"') !== false, 'link rel is forced', $out);

// ── Nothing that acts survives ───────────────────────────────────────────────
section('Nothing that executes, fetches or escapes survives');

$out = $print('<p>before</p><script>alert(1)</script><p>after</p>');
check(strpos($out, 'alert') === false && strpos($out, '<script') === false,
	'script dropped with its contents', $out);
check(strpos($out, 'before') !== false && strpos($out, 'after') !== false,
	'text around a dropped script is kept');

$out = $print('<style>body{color:red}</style><p>hi</p>');
check(strpos($out, 'color:red') === false && strpos($out, '<style') === false,
	'<style> block dropped with its CSS', $out);

$out = $print('<div onclick="steal()" onmouseover="x()">text</div>');
check(strpos($out, 'onclick') === false && strpos($out, 'onmouseover') === false,
	'every event handler stripped', $out);
check(strpos($out, 'text') !== false, 'the element itself is kept');

$out = $print('<iframe src="https://evil.example/"></iframe><p>ok</p>');
check(strpos($out, '<iframe') === false && strpos($out, 'evil.example') === false,
	'iframe dropped with its contents', $out);

$out = $print('<form action="https://evil.example/"><input name="p" type="password"></form>');
check(strpos($out, '<form') === false && strpos($out, '<input') === false,
	'form and its fields dropped', $out);

$out = $print('<a href="javascript:alert(1)">x</a>');
check(strpos($out, 'javascript:') === false, 'javascript: href refused', $out);

$out = $print('<a href="https://ok.example/">x</a>');
check(strpos($out, 'target=') === false,
	'no target on a printed link — paper has no tabs', $out);

// ── The style attribute is a filter, not a pass-through ──────────────────────
section('Style declarations that reach the network are dropped');

$out = $print('<div style="color:#333;background-image:url(https://tracker.example/p.gif)">x</div>');
check(strpos($out, 'tracker.example') === false, 'url() value dropped', $out);
check(strpos($out, 'color:#333') !== false, 'the safe declaration beside it is kept', $out);

$out = $print('<div style="background:url(&quot;https://tracker.example/p.gif&quot;)">x</div>');
check(strpos($out, 'tracker.example') === false, 'quoted url() dropped too', $out);

$out = $print('<div style="width:expression(alert(1))">x</div>');
check(strpos($out, 'expression') === false, 'expression() dropped', $out);

$out = $print('<div style="position:fixed;top:0;color:red">x</div>');
check(strpos($out, 'position') === false, 'unlisted property (position) dropped', $out);
check(strpos($out, 'color:red') !== false, 'listed property beside it kept');

// ── Images: only what we can vouch for ───────────────────────────────────────
section('Images resolve to http(s) or they do not print');

$out = $print('<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">');
check(strpos($out, 'data:') === false, 'data: image dropped', $out);

$out = $print('<img src="cid:unresolved@example">');
check(strpos($out, 'cid:') === false, 'unresolved cid: image dropped', $out);

$out = $print('<p>text</p><img src="data:image/png;base64,AAA">');
check(strpos($out, 'text') !== false, 'dropping an image leaves the rest of the message', $out);

// ── Shape ────────────────────────────────────────────────────────────────────
section('Empty in, empty out');

check($print('') === '', 'empty string');
check($print('   ') === '', 'whitespace only');
check($print('<script>alert(1)</script>') === '', 'a message that was only script');

$out = $print('<html><head><title>T</title></head><body><p>body text</p></body></html>');
check(strpos($out, '<html') === false && strpos($out, '<body') === false,
	'no document wrapper in the output', $out);
check(strpos($out, 'T') === false || strpos($out, '<title') === false, 'title dropped', $out);
check(strpos($out, 'body text') !== false, 'the body content is what comes back');

harness_finish();
?>
