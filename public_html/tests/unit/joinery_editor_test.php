<?php
/** @joinery-test
 * name: joinery_editor
 * tier: safe
 * env: dev-only
 * needs: [chrome]
 * timeout: 90
 */
/**
 * PURPOSE: The editor saves what the author wrote, and cleanup does exactly
 * what its rule table says.
 *
 * The fixtures live in the browser, because the code under test does:
 * tests/fixtures/joinery_editor/runner.html loads assets/js/html-cleanup.js
 * and assets/js/joinery-editor.js, applies every fixture, and writes one
 * <li data-result="pass|fail"> per check. This test runs that page in
 * headless Chrome and reads the rows back, so a browser-side regression is a
 * red row here and not a support ticket.
 *
 * What is pinned:
 *   - every row of the cleanup rule table (keep, rename, drop, unwrap,
 *     attributes, structure), the Office paste shape, nested inline tags,
 *     pre verbatim, unsafe URL schemes, and that cleanup is idempotent
 *   - the textarea is untouched until the first edit, and an edit writes the
 *     whole surface (an SVG-only block is not emptied)
 *   - the hidden textarea is still visible to checkVisibility(), so required
 *     and minlength keep working on rich-text fields
 *   - editor_cleanup: always cleans the surface on load, the paste path, and
 *     the field before a submit listener reads it; the textarea stays raw
 *     until then
 *   - the Clean up button, and its Undo clean up until the next edit
 *   - a command's engine tags are renamed inside the selection only
 *   - the markdown dialect: bold wrap and unwrap, heading toggle on and off,
 *     list continuation on Enter, the link popover, and that a view change
 *     never touches the value
 *   - the PHP side: textbox() emits the wrapper, view, cleanup and toolbar the
 *     script binds to, with no inline script, and refuses an option from the
 *     wrong dialect at definition time
 *
 * Needs headless Chrome; the runner reports a SKIP when it is absent.
 *
 * Run:  php tests/unit/joinery_editor_test.php
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$runner = realpath(__DIR__ . '/../fixtures/joinery_editor/runner.html');

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

/** The markup textbox() emits for $options, or the exception message. */
function editor_markup(array $options) {
	$form = new FormWriterV2HTML5('jy_ed_probe_' . md5(serialize($options)));
	ob_start();
	try {
		$form->textbox('probe', 'Probe', $options);
	} catch (Throwable $e) {
		ob_end_clean();
		return 'THREW: ' . $e->getMessage();
	}
	return ob_get_clean();
}

// ---------------------------------------------------------------------------
section('FormWriter emits the chrome the script binds to');
// ---------------------------------------------------------------------------

$html = editor_markup(array('htmlmode' => 'yes', 'value' => '<section class="x">raw</section>'));
check(strpos($html, 'data-jy-editor="html"') !== false, 'htmlmode wraps the textarea in the html dialect');
check(strpos($html, 'data-jy-ed-initial-view="visual"') !== false, 'html opens visual by default');
check(strpos($html, 'data-jy-ed-cleanup="button"') !== false, 'cleanup defaults to button');
check(strpos($html, 'data-jy-ed-action="cleanup"') !== false, 'the Clean up button is offered');
check(strpos($html, '&lt;section class=&quot;x&quot;&gt;raw&lt;/section&gt;') !== false,
	'the value is the textarea content, escaped, untouched');
check(strpos($html, 'class="jy-ed-surface"') !== false, 'the surface is emitted empty for the script to fill');
check(!preg_match('/<script(?![^>]*\bsrc=)/', $html), 'no inline script (the assets are external, deferred scripts)');
check(preg_match('/<script src="\/assets\/js\/joinery-editor\.js\?v=\d+" defer>/', $html) === 1, 'the editor script is emitted once, with a cache-busting version');

$html = editor_markup(array('htmlmode' => 'yes', 'editor_view' => 'source', 'editor_cleanup' => 'none'));
check(strpos($html, 'data-jy-ed-initial-view="source"') !== false, 'editor_view source is carried');
check(strpos($html, 'data-jy-ed-cleanup="none"') !== false, 'editor_cleanup none is carried');
check(strpos($html, 'data-jy-ed-action="cleanup"') === false, 'cleanup none hides the button');

$html = editor_markup(array('markdownmode' => 'yes', 'editor_view' => 'split'));
check(strpos($html, 'data-jy-editor="markdown"') !== false, 'markdownmode wraps in the markdown dialect');
check(strpos($html, 'data-jy-ed-initial-view="split"') !== false, 'editor_view split is carried');
check(strpos($html, 'class="jy-ed-preview markdown-content"') !== false, 'the preview pane is emitted');
check(strpos($html, 'data-jy-ed-action="cleanup"') === false, 'markdown has no cleanup button');
check(strpos($html, 'data-jy-ed-action="fullscreen"') !== false, 'markdown has fullscreen');

// ---------------------------------------------------------------------------
section('Options from the wrong dialect throw at definition time');
// ---------------------------------------------------------------------------

$msg = editor_markup(array('htmlmode' => 'yes', 'editor_view' => 'split'));
check(strpos($msg, 'THREW') === 0 && strpos($msg, 'split') !== false, 'a markdown view on htmlmode throws', $msg);
$msg = editor_markup(array('markdownmode' => 'yes', 'editor_view' => 'source'));
check(strpos($msg, 'THREW') === 0, 'an html view on markdownmode throws', $msg);
$msg = editor_markup(array('markdownmode' => 'yes', 'editor_cleanup' => 'always'));
check(strpos($msg, 'THREW') === 0 && strpos($msg, 'cleanup') !== false, 'editor_cleanup with markdownmode throws', $msg);
$msg = editor_markup(array('htmlmode' => 'yes', 'editor_cleanup' => 'sometimes'));
check(strpos($msg, 'THREW') === 0, 'an unknown cleanup value throws', $msg);
$msg = editor_markup(array('editor_view' => 'visual'));
check(strpos($msg, 'THREW') === 0, 'editor_view on a plain textarea throws', $msg);
$msg = editor_markup(array('htmlmode' => 'yes', 'markdownmode' => 'yes'));
check(strpos($msg, 'THREW') === 0, 'both dialects at once throws', $msg);
$plain = editor_markup(array('rows' => 3));
check(strpos($plain, 'jy-ed') === false && strpos($plain, '<textarea') !== false, 'a plain textbox is a bare textarea');

// ---------------------------------------------------------------------------
section('The fixture page and its scripts exist');
// ---------------------------------------------------------------------------

check($runner !== false && is_file($runner), 'runner.html is present', (string)$runner);
foreach (array('assets/js/html-cleanup.js', 'assets/js/joinery-editor.js', 'assets/css/joinery-editor.css') as $asset) {
	check(is_file(PathHelper::getIncludePath($asset)), $asset . ' is present');
}

$chrome = trim((string)shell_exec('command -v google-chrome 2>/dev/null'));
if ($chrome === '') {
	// needs: [chrome] makes the runner skip before we get here; reaching this
	// line means the probe and the box disagree, which is a failure to report.
	check(false, 'google-chrome is on PATH (declare/enforce needs:[chrome])');
	harness_finish();
}

// ---------------------------------------------------------------------------
section('Headless Chrome runs the fixtures to completion');
// ---------------------------------------------------------------------------

$cmd = 'timeout 60 ' . escapeshellarg($chrome)
	. ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
	. ' --virtual-time-budget=3000 --dump-dom ' . escapeshellarg('file://' . $runner) . ' 2>/dev/null';
$dom = (string)shell_exec($cmd);

check($dom !== '', 'Chrome returned a DOM', strlen($dom) . ' bytes');
check(strpos($dom, 'data-done="1"') !== false, 'the fixture script ran to its last line',
	'a missing marker means it threw before reporting; open runner.html in a browser');

// ---------------------------------------------------------------------------
section('Every browser-side fixture passed');
// ---------------------------------------------------------------------------

$rows = 0;
if (preg_match_all('/<li data-result="(pass|fail)" data-name="([^"]*)">(.*?)<\/li>/s', $dom, $m, PREG_SET_ORDER)) {
	foreach ($m as $row) {
		$rows++;
		$name = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5);
		$detail = '';
		if ($row[1] === 'fail' && preg_match('/<code>(.*?)<\/code>/s', $row[3], $c)) {
			$detail = html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5);
		}
		check($row[1] === 'pass', $name, $detail);
	}
}
check($rows >= 60, 'the fixture set is the full one, not a page that half-loaded', $rows . ' rows');

harness_finish();
