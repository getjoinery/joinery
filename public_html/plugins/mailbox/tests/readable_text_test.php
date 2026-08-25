<?php
/** @joinery-test
 * name: readable_text
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Tests for MailboxHtmlSanitizer::toReadableText() — the words a person would
 * read out of received HTML. Two callers: the list row's preview, and what the
 * sealed search index stores for a message.
 *
 * Pure function, no DB. Covers the ways received mail leaks non-content into
 * both: an embedded stylesheet (whose CSS survives a naive strip_tags), the
 * document head and title, MSO conditional comments, table cells that abut with
 * no whitespace, and the invisible characters senders use to pad a preheader.
 * Also covers the honest-empty case (an image-only message).
 *
 * Run: php plugins/mailbox/tests/readable_text_test.php
 *
 * @version 1.1 - previewText() coverage: entity-laden and zero-width-padded
 *                plain parts
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));

$readable = function ($html) { return MailboxHtmlSanitizer::toReadableText($html); };

// ── Embedded CSS ─────────────────────────────────────────────────────────────
section('Stylesheets and scripts never read as content');

$styled = '<html><head><title>Your Message Subject or Title</title>'
	. '<style type="text/css">a.cta_button{-moz-box-sizing:content-box !important;}'
	. '@media only screen and (min-width:768px){.templateContainer{width:600px !important;}}'
	. '</style></head><body><p>Your order is being processed.</p></body></html>';
$out = $readable($styled);
check($out === 'Your order is being processed.', 'stylesheet, head and title all dropped', $out);

check(strpos($readable($styled), '{') === false, 'no CSS braces survive');

$scripted = '<body><script>var x = {a:1}; alert("hi");</script><div>Real text.</div></body>';
check($readable($scripted) === 'Real text.', 'script contents dropped', $readable($scripted));

// A <style> block inside the body (common in bulk mail) is dropped too.
$body_style = '<body><style>.x{color:red}</style><div>After the style.</div></body>';
check($readable($body_style) === 'After the style.', 'in-body style block dropped', $readable($body_style));

// ── Comments ─────────────────────────────────────────────────────────────────
section('Comments');

$mso = '<body><!--[if mso]><table width="600"><tr><td><![endif]-->'
	. '<p>Visible copy.</p><!-- tracking pixel note --></body>';
check($readable($mso) === 'Visible copy.', 'MSO conditional and plain comments dropped', $readable($mso));

// ── Word boundaries ──────────────────────────────────────────────────────────
section('Block boundaries become spaces');

$cells = '<body><table><tr><td>Status benefit</td><td>Terms apply</td></tr></table></body>';
check($readable($cells) === 'Status benefit Terms apply', 'table cells do not run together', $readable($cells));

$divs = '<body><div>First line</div><div>Second line</div></body>';
check($readable($divs) === 'First line Second line', 'sibling divs separated', $readable($divs));

$brs = '<body><p>Line one<br>Line two</p></body>';
check($readable($brs) === 'Line one Line two', 'br is a word boundary', $readable($brs));

// Inline markup must NOT introduce a space mid-word.
$inline = '<body><p>un<b>break</b>able</p></body>';
check($readable($inline) === 'unbreakable', 'inline tags do not split a word', $readable($inline));

// ── Invisible preheader padding ──────────────────────────────────────────────
section('Preheader padding');

// The real-world shape: a short preheader padded to fill a client's preview line
// with non-breaking spaces interleaved with combining grapheme joiners.
$pad = str_repeat("\xC2\xA0\xCD\x8F", 40);
$padded = '<body><div style="display:none">Real preheader.' . $pad . '</div>'
	. '<p>The message body.</p></body>';
$out = $readable($padded);
check($out === 'Real preheader. The message body.', 'invisible padding removed', $out);
check(strpos($out, "\xCD\x8F") === false, 'no combining grapheme joiners survive');
check(strpos($out, "\xC2\xA0") === false, 'no non-breaking spaces survive');

$zw = '<body><p>a' . "\xE2\x80\x8B\xEF\xBB\xBF\xC2\xAD" . 'b</p></body>';
check($readable($zw) === 'ab', 'zero-width space, BOM and soft hyphen removed', $readable($zw));

// ── Links and images ─────────────────────────────────────────────────────────
section('Links and images');

// A preview shows the words a person reads — not the URL behind them
// (toPlainText() is the one that renders "text <url>", for a faithful copy).
$link = '<body><p>Read the <a href="https://example.test/very/long/path">announcement</a>.</p></body>';
check($readable($link) === 'Read the announcement.', 'link text kept, URL left out', $readable($link));

$img_only = '<body><div><a href="https://example.test/"><img src="https://example.test/a.png" alt="Sale"></a></div></body>';
check($readable($img_only) === '', 'image-only message previews as empty, not as markup', $readable($img_only));

// ── Degenerate input ─────────────────────────────────────────────────────────
section('Degenerate input');

check($readable('') === '', 'empty in, empty out');
check($readable('   ') === '', 'whitespace in, empty out');
check($readable('<body><p>   </p></body>') === '', 'whitespace-only body is empty');
check($readable('Just bare text, no tags at all.') === 'Just bare text, no tags at all.',
	'tagless input passes through');

// Truncated mid-tag (a body cut short upstream) must not emit tag fragments.
$cut = '<body><p>Good text here.</p><div class="x" style="color:re';
check($readable($cut) === 'Good text here.', 'partial trailing tag not emitted', $readable($cut));

// Oversized input is capped rather than parsed whole; the opening copy survives.
$huge = '<body><p>Opening sentence.</p>' . str_repeat('<div>filler</div>', 40000) . '</body>';
$out = $readable($huge);
check(strpos($out, 'Opening sentence.') === 0, 'oversized document still previews its opening',
	substr($out, 0, 40));

// ── Plain-part previews ──────────────────────────────────────────────────────
section('previewText: a received plain part cleans like the HTML path');

$preview = function ($text) { return MailboxHtmlSanitizer::previewText($text); };

// The live defect: a sender's HTML-derived plain part carrying its preheader
// padding as literal entity text — &zwnj; (zero-width non-joiner) and &#847;
// (combining grapheme joiner) interleaved with spaces.
$zwnj = 'Your gift is expiring ' . str_repeat('&zwnj; ', 40);
check($preview($zwnj) === 'Your gift is expiring', 'literal &zwnj; padding stripped', $preview($zwnj));

$cgj = 'GoodRx' . str_repeat('&#847; ', 40) . 'See for yourself.';
check($preview($cgj) === 'GoodRx See for yourself.', 'numeric &#847; padding stripped', $preview($cgj));

$only_padding = str_repeat('&zwnj;&nbsp;', 60);
check($preview($only_padding) === '',
	'a padding-only plain part cleans to empty (the caller then uses the HTML preview)');

// Raw invisible characters (no entities) get the same treatment.
$raw = "Words here\u{200C}\u{034F}\u{00A0}\u{FEFF} and more";
check($preview($raw) === 'Words here and more', 'raw zero-width characters stripped', $preview($raw));

// Ordinary entities decode into readable text rather than surviving as source.
check($preview('Fish &amp; Chips &#8217;til late') === "Fish & Chips \u{2019}til late",
	'common entities decode for the preview', $preview('Fish &amp; Chips &#8217;til late'));

// Text that is not entity-laden passes through untouched.
check($preview('A perfectly ordinary sentence.') === 'A perfectly ordinary sentence.',
	'plain text passes through');
check($preview('AT&T works — an invalid entity is left alone') === 'AT&T works — an invalid entity is left alone',
	'a bare ampersand is not an entity and survives');
check($preview('') === '' && $preview('   ') === '', 'empty and whitespace stay empty');

harness_finish();
