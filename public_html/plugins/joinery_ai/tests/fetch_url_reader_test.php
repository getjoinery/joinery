<?php
/** @joinery-test
 * name: fetch_url_reader
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * FetchUrlReader — the page the AI fetched is read in the parser jail, and
 * fetch_url itself never opens the bytes (specs/parser_jail.md).
 *
 * Run: php plugins/joinery_ai/tests/fetch_url_reader_test.php
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/FetchUrlReader.php'));

$read = function (string $html, string $mode = 'reader'): array {
	$r = DocumentText::parseWith('FetchUrlReader', $html, array('mode' => $mode));
	$a = json_decode((string)$r['text'], true);
	return array('status' => $r['status'], 'text' => (string)($a['text'] ?? ''), 'note' => (string)($a['note'] ?? ''), 'detail' => $r['detail']);
};

section('Reader mode: the article, as Markdown, through the subprocess');
$article = str_repeat('Engines of the analytical kind were described at length in the notes. ', 6);
$page = '<html><head><title>Notes on the Engine</title><style>.x{color:red}</style></head><body>'
	. '<nav><a href="/">Home</a> <a href="/about">About</a></nav>'
	. '<main><h1>The Engine</h1><p>' . $article . '</p><ul><li>one</li><li>two</li></ul>'
	. '<p>See <a href="https://example.com/more">more</a>.</p></main>'
	. '<footer>© nobody</footer><script>alert(1)</script></body></html>';
$r = $read($page);
check($r['status'] === DocumentText::OK, 'the page reads', $r['status'] . ' ' . (string)$r['detail']);
check(strpos($r['text'], "Notes on the Engine\n\n") === 0, 'the title leads', substr($r['text'], 0, 60));
check(strpos($r['text'], '# The Engine') !== false, 'headings become Markdown');
check(strpos($r['text'], '- one') !== false, 'lists become Markdown');
check(strpos($r['text'], '[more](https://example.com/more)') !== false, 'links survive as Markdown');
check(strpos($r['text'], 'Home') === false && strpos($r['text'], '©') === false, 'navigation and footer chrome are removed');
check(strpos($r['text'], 'alert(1)') === false && strpos($r['text'], 'color:red') === false, 'scripts and styles are removed');
check($r['note'] === '', 'no fallback note when the visible walk is enough');

section('Reader mode: escalation when the visible page is thin');
$body = str_repeat('Structured data carried the whole story where the markup had none. ', 5);
$jsonld = '<script type="application/ld+json">' . json_encode(array('@type' => 'Article', 'headline' => 'Hidden', 'articleBody' => $body)) . '</script>';
$r = $read('<html><head><title>T</title>' . $jsonld . '</head><body><div id="app"></div></body></html>');
check(strpos($r['text'], '# Hidden') !== false && strpos($r['text'], 'Structured data') !== false, 'JSON-LD is read when the DOM is empty');
check(strpos($r['note'], 'embedded data') !== false, 'and the note says so', $r['note']);

$r = $read('<html><body><p>short</p></body></html>');
check(strpos($r['note'], 'full-page text') !== false, 'a thin page with no embedded data falls to the full flatten', $r['note']);
check($r['text'] === 'short', 'and returns what there is', $r['text']);

section('Full mode');
$r = $read('<html><body><h1>A</h1><p>B</p><script>x()</script></body></html>', 'full');
check(strpos($r['text'], "A\n") === 0 && strpos($r['text'], 'B') !== false && strpos($r['text'], 'x()') === false,
	'full mode flattens blocks to lines and drops scripts', json_encode($r['text']));

section('Contract');
$r = $read('');
check($r['status'] === DocumentText::OK || $r['status'] === DocumentText::EMPTY, 'an empty page is not a failure', $r['status']);
$src = file_get_contents(PathHelper::getIncludePath('plugins/joinery_ai/recipe_tools/FetchUrlTool.php'));
foreach (array('DOMDocument', 'loadHTML', 'DOMXPath') as $token) {
	check(!preg_match('/^[^*\n]*\b' . $token . '\b/m', $src), 'fetch_url (pool side) never names ' . $token);
}
check(strpos($src, "DocumentText::parseWith('FetchUrlReader'") !== false, 'fetch_url hands the page to the jail');

harness_finish();
