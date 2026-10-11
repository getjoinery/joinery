<?php
/** @joinery-test
 * name: markdown_renderer_safety
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * MarkdownRenderer link and image targets.
 *
 * The renderer escapes HTML first, but its link and image rules once put any
 * target straight into an href/src — so `[x](javascript:alert(1))` rendered a
 * live script link, and an image could point anywhere. Text other people write
 * (card comments, anything that arrives by email) goes through it, so the target
 * is now checked: http, https, mailto and relative targets become links; an image
 * is http, https or relative; everything else is shown as plain text.
 *
 * Run: php tests/run.php --filter=markdown_renderer_safety
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));

section('Links to a script scheme are not links');

$bad = array(
	'javascript:alert(1)',
	'JaVaScRiPt:alert(1)',
	'  javascript:alert(1)',
	"java\tscript:alert(1)",
	"java\nscript:alert(1)",
	'data:text/html;base64,PHNjcmlwdD4=',
	'vbscript:msgbox(1)',
	'file:///etc/passwd',
);
foreach ($bad as $target) {
	foreach (array(false, true) as $restricted) {
		$html = MarkdownRenderer::render('[go](' . $target . ')', false, $restricted);
		check(strpos($html, '<a ') === false && strpos($html, 'href') === false,
			'no link for ' . json_encode($target) . ($restricted ? ' (restricted)' : ''),
			$html);
		check(strpos($html, 'go') !== false, 'the link text survives as text for ' . json_encode($target));
	}
}

section('Ordinary targets still link');

$good = array(
	'https://example.test/a?b=1&c=2',
	'http://example.test/',
	'mailto:person@example.test',
	'/profile/boards',
	'#section',
	'relative/page',
);
foreach ($good as $target) {
	$html = MarkdownRenderer::render('[go](' . $target . ')');
	check(strpos($html, '<a href="') !== false, 'link kept for ' . $target, $html);
}

section('Images: only http, https and relative, and none in the restricted profile');

check(strpos(MarkdownRenderer::render('![a](https://example.test/p.png)'), '<img src="https://example.test/p.png"') !== false,
	'an https image renders in the default profile');
check(strpos(MarkdownRenderer::render('![a](/assets/p.png)'), '<img ') !== false,
	'a relative image renders in the default profile');
check(strpos(MarkdownRenderer::render('![a](data:image/png;base64,AAAA)'), '<img') === false,
	'a data: image does not');
check(strpos(MarkdownRenderer::render('![a](javascript:alert(1))'), '<img') === false,
	'a javascript: image does not');
check(strpos(MarkdownRenderer::render('![a](mailto:x@y.test)'), '<img') === false,
	'a mailto: image does not');

$restricted_image = MarkdownRenderer::render('![tracker](https://example.test/p.png)', false, true);
check(strpos($restricted_image, '<img') === false, 'the restricted profile renders no image', $restricted_image);
check(strpos($restricted_image, 'tracker') !== false, 'but keeps the alt text');

section('Restricted profile: links leave the opener behind, headings carry no id');

$link = MarkdownRenderer::render('[go](https://example.test)', false, true);
check(strpos($link, 'rel="noopener noreferrer nofollow"') !== false, 'external links drop the opener', $link);
check(strpos($link, 'target="_blank"') !== false, 'and open in a new tab');

$heading = MarkdownRenderer::render('# boards-app', false, true);
check(strpos($heading, ' id=') === false, 'a heading has no id of its own', $heading);
check(strpos($heading, '<h1') === false, 'and is not larger than the page\'s own headings', $heading);

$authored = MarkdownRenderer::render('# Title');
check(strpos($authored, '<h1 id="title">') !== false, 'the default profile still anchors headings');

section('Raw HTML stays escaped');

$html = MarkdownRenderer::render('<script>alert(1)</script> <img src=x onerror=alert(1)>', false, true);
check(strpos($html, '<script') === false && strpos($html, '<img') === false,
	'tags in the text are escaped', $html);

harness_finish();
