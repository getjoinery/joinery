<?php
/** @joinery-test
 * name: community_components
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The shared community pieces that need no database (specs/community_shared_components.md):
 *
 *  - C1 mention tokens: parsed outside code only, rewritten to the person's
 *    real name or to plain text, rendered as a chip that cannot carry a link;
 *    restricted links are marked ugc;
 *  - C1 editor: the restricted markdown editor offers no heading, image or
 *    table button, and carries the mention space;
 *  - C4 full-text search: the index entry and the query use one expression,
 *    the snippet escapes everything it does not mark;
 *  - the static page cache clears every copy of a path, query strings and all.
 *
 * Run: php tests/run.php --filter=community_components
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// =====================================================================
section('mention tokens');

$md = "Hi @[Sam Lee](user:42), and @[Bad](user:7).\n`@[Code](user:9)`\n\n```\n@[Fence](user:8)\n```\n@[NoId](user:0) @sam";
check(MentionParser::userIds($md) === array(42, 7), 'mentions outside code are found, in order, once each');
$out = MentionParser::normalize($md, function ($id) { return $id === 42 ? 'Samuel Lee' : null; });
check(strpos($out, '@[Samuel Lee](user:42)') !== false, 'a kept mention takes the real name');
check(strpos($out, '@Bad.') !== false && strpos($out, '(user:7)') === false, 'a dropped mention becomes plain text');
check(strpos($out, '`@[Code](user:9)`') !== false && strpos($out, "@[Fence](user:8)") !== false, 'code is left exactly as written');
$evil = MentionParser::token(5, "Evil] [x](javascript:alert(1)");
check(preg_match_all(MentionParser::TOKEN_PATTERN, $evil, $m) === 1 && $m[2][0] === '5' && strpbrk($m[1][0], '[]') === false,
	'a name cannot close the token early or open another', $evil);
check(MentionParser::token(5, '') === '@[Member](user:5)', 'an empty name reads as Member');

$html = MarkdownRenderer::render('Hi @[Sam **Lee**](user:42) [x](https://e.test)', true, true);
check(strpos($html, '<span class="jy-mention" data-user-id="42">@Sam **Lee**</span>') !== false, 'a mention renders as a chip, its name not parsed as markdown', $html);
check(strpos($html, 'rel="noopener noreferrer nofollow ugc"') !== false, 'restricted links are marked ugc');
$html = MarkdownRenderer::render('@[<b>x</b>](user:3)', true, true);
check(strpos($html, '<b>') === false, 'a mention name is escaped');
$html = MarkdownRenderer::render('`@[Sam](user:4)`', true, true);
check(strpos($html, 'jy-mention') === false, 'a mention inside code stays code');

// =====================================================================
section('block quotes (what the Quote button writes)');

$html = MarkdownRenderer::render("> **Sam wrote:**\n> Hello\n>\n> - one\n> - two\n\nMy answer", true, true);
check(strpos($html, '<blockquote><p><strong>Sam wrote:</strong><br>Hello</p><ul><li>one</li><li>two</li></ul></blockquote>') === 0,
	'a quote is a block whose contents are markdown', $html);
check(strpos($html, '<p>My answer</p>') !== false && strpos($html, '&gt;') === false, 'no marker is left as text');
$html = MarkdownRenderer::render("> a\n> > b", false, false);
check(substr_count($html, '<blockquote>') === 2, 'quotes nest');
$deep = MarkdownRenderer::render(str_repeat('&gt; ', 0) . str_repeat('> ', 10) . 'deep', false, false);
check(substr_count($deep, '<blockquote>') === MarkdownRenderer::MAX_QUOTE_DEPTH, 'but only so deep');
check(strpos(MarkdownRenderer::render("```\n> code\n```", false, false), '<blockquote>') === false, 'a > line inside code is code');
check(strpos(MarkdownRenderer::render('1 > 0', false, false), '<blockquote>') === false, 'a > inside a sentence is text');
$html = MarkdownRenderer::render('> [x](javascript:alert(1)) <script>', true, true);
check(strpos($html, 'href') === false && strpos($html, '<script>') === false, 'a quote is held to the same profile as the rest');

// =====================================================================
section('hostile input stays fast, whole and inert');

$t = microtime(true);
$html = MarkdownRenderer::render(str_repeat("> a\n\nb\n\n", 8000), true, true);
check(microtime(true) - $t < 1.0 && substr_count($html, '<blockquote>') === 8000, 'eight thousand quotes render in well under a second', sprintf('%.2fs', microtime(true) - $t));
$html = MarkdownRenderer::render(str_repeat("> x\n", 5000), true, true);
check(strlen($html) > 5000 && substr_count($html, '<blockquote>') === 1, 'a five-thousand-line quote renders as one quote, not as nothing');
$html = MarkdownRenderer::render("\x02MDPH0\x02 `code` [t](\x02MDPH0\x02)", true, true);
check(strpos($html, "\x02") === false && strpos($html, 'href="<') === false, 'a typed placeholder sentinel is stripped, never filled with markup');
$html = MarkdownRenderer::render('[@[Name](user:1)](http://evil.example)', true, true);
check(strpos($html, 'evil.example') === false && strpos($html, 'jy-mention') !== false, 'a mention chip is never the face of a link');
$fence = "```foo bar\n@[Admin](user:1)\n```";
$normalized = MentionParser::normalize($fence, function ($id) { return null; });
check(strpos(MarkdownRenderer::render($normalized, true, true), 'jy-mention') === false,
	'a fence the renderer does not call code is prose to the parser too, so its mention is checked');
check(FullTextSearch::snippet('Tom & Jerry', 'amp') === 'Tom &amp; Jerry', 'a snippet never marks inside an escaped entity');
$html = MarkdownRenderer::render('see [x](`a`) and `b` [y](@[Sam](user:42))', true, true);
check(strpos($html, '[x](<code>a</code>)') !== false && strpos($html, '<code>b</code>') !== false,
	'a link whose target holds code keeps every word the author wrote', $html);
$html = MarkdownRenderer::render('@[Na`me`](user:7) @[Bo``b``](user:8)', true, true);
check(strpos($html, "\x02") === false && strpos($html, '@Na<code>me</code>') !== false, 'code in a mention name is shown as code, never a raw placeholder', $html);
$t = microtime(true);
MarkdownRenderer::render(str_repeat("# a `b`\n", 32768), true, false);
check(microtime(true) - $t < 2.0, 'thousands of headings with code render quickly in the full profile too', sprintf('%.2fs', microtime(true) - $t));

// =====================================================================
section('the restricted markdown editor');

ob_start();
$fw = new FormWriterV2HTML5('cc_test', array('method' => 'get'));
$fw->textbox('body', 'Body', array('markdownmode' => 'yes', 'markdown_restricted' => true, 'mention_space' => 'forum_category:12'));
$field = ob_get_clean();
check(strpos($field, 'data-jy-ed-restricted="1"') !== false, 'the wrapper is marked restricted');
check(strpos($field, 'data-jy-ed-mention-space="forum_category:12"') !== false, 'and carries the mention space');
foreach (array('h1', 'h2', 'h3', 'image', 'table') as $gone) {
	check(strpos($field, 'data-jy-ed-action="' . $gone . '"') === false, "no $gone button");
}
foreach (array('bold', 'italic', 'link', 'quote', 'ul', 'codeblock') as $kept) {
	check(strpos($field, 'data-jy-ed-action="' . $kept . '"') !== false, "the $kept button stays");
}
check(!preg_match('#<span class="jy-ed-sep"[^>]*></span>\s*<span class="jy-ed-sep"#', $field), 'no doubled separators where buttons went');
ob_start();
$fw->textbox('full', 'Full', array('markdownmode' => 'yes'));
$plain = ob_get_clean();
check(strpos($plain, 'data-jy-ed-action="table"') !== false && strpos($plain, 'data-jy-ed-restricted') === false,
	'the ordinary markdown editor is unchanged');
$threw = false;
try {
	ob_start();
	$fw->textbox('nope', 'Nope', array('markdown_restricted' => true));
} catch (Exception $e) {
	$threw = true;
} finally {
	ob_end_clean();
}
check($threw, 'markdown_restricted without markdownmode is refused at definition time');

// =====================================================================
section('full-text search');

check(FullTextSearch::indexSpec('fpo_body') === array('columns' => array("to_tsvector('english', fpo_body)"), 'method' => 'gin'),
	'the index entry is the expression the query uses');
list($sql, $params) = FullTextSearch::match('fpo.fpo_body', 'cats "big dogs" -mice');
check($sql === "to_tsvector('english', fpo.fpo_body) @@ websearch_to_tsquery('english', ?)", 'the match condition', $sql);
check($params === array('cats "big dogs" -mice'), 'with the query as its one parameter');
$threw = false;
try { FullTextSearch::match('fpo_body; DROP TABLE x', 'a'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'a column that is not a name is refused');
check(FullTextSearch::terms('cats -mice or "big dogs"') === array('cats', 'big', 'dogs'), 'highlight terms skip operators and exclusions');
$snip = FullTextSearch::snippet('Some <script>x</script> text about Cats and more', 'cats');
check(strpos($snip, '<script>') === false && strpos($snip, '&lt;script&gt;') !== false, 'the snippet escapes the text');
check(strpos($snip, '<mark>Cats</mark>') !== false, 'and marks the matched word');
check(!FullTextSearch::isSearchable('  - "" '), 'a query with nothing to look for is not searched');

// =====================================================================
section('the static page cache clears every copy of a path');

$dir = harness_scratch_dir('page_cache');
StaticPageCache::setCacheDirForTests($dir);
StaticPageCache::createCache('/forum/t/5/hello', array(), '<html><head></head><body>p1</body></html>');
StaticPageCache::createCache('/forum/t/5/hello', array('page' => '2'), '<html><head></head><body>p2</body></html>');
StaticPageCache::createCache('/forum/t/55/other', array(), '<html><head></head><body>x</body></html>');
StaticPageCache::createCache('/forum/general', array(), '<html><head></head><body>c</body></html>');
StaticPageCache::createCache('/forum', array(), '<html><head></head><body>h</body></html>');
check(StaticPageCache::invalidatePath('/forum/t/5', true) === 2, 'a topic\'s pages, every ?page= of them, go together');
check(StaticPageCache::checkCache('/forum/t/55/other', array()) !== false, 'a topic whose id only starts the same stays');
check(StaticPageCache::invalidatePath('/forum') === 1, 'without children, only the path itself goes');
check(StaticPageCache::checkCache('/forum/general', array()) !== false, 'so the category page stays');
check(StaticPageCache::invalidatePath('/forum', true) === 2, 'with children, everything under it goes');
StaticPageCache::skipThisRequest();
check(StaticPageCache::createCache('/forum/search', array('q' => 'x'), '<html><head></head><body>s</body></html>') === false
	&& StaticPageCache::markAsNostatic('/forum/search', array('q' => 'y')) === false,
	'a request marked never-cached writes no page and no index entry, whoever asks');
StaticPageCache::setCacheDirForTests(null);

harness_finish();
