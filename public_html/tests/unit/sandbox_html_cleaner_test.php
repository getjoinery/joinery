<?php
/** @joinery-test
 * name: sandbox_html_cleaner
 * tier: safe
 * env: any
 * needs: []
 * timeout: 60
 */
/**
 * SandboxHtmlCleaner (specs/sandboxed_php_plugins.md design 6, WP5): the
 * cleaner for a sandboxed plugin's plain page, against a corpus of known
 * mutation and smuggling cases, directly and once through the parser jail.
 *
 * Every case's output must: hold no script, style, iframe, object, embed,
 * svg, math, template, base, meta or link element; no on* , style, id,
 * data-*, form, formaction or srcset attribute; no href or src with a
 * scheme other than http, https or mailto (relative allowed); every form
 * posting to the page's own address; and clean to itself a second time.
 *
 * @version 1.1 - reviewer2 H1-H5
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$options = array('page_url' => '/admin/admin_sandbox_page?plugin=p&page=x', 'asset_url' => '/sandbox_asset?plugin=p&file=',
	'image_hosts' => array('img.example.com'));
$cleaner = new SandboxHtmlCleaner($options);

/** What no output may hold, checked by parsing the output as a browser would. */
function sbx_cleaner_violations(string $html, array $options): array {
	$doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
	$bad = array();
	foreach ($doc->body->querySelectorAll('*') as $el) {
		$name = $el->localName;
		if ($el->namespaceURI !== 'http://www.w3.org/1999/xhtml' || in_array($name, array('script', 'style', 'iframe', 'object', 'embed', 'svg',
				'math', 'template', 'base', 'meta', 'link', 'noscript', 'frame', 'frameset', 'applet'), true)) {
			$bad[] = 'element ' . $name;
		}
		foreach ($el->attributes as $attr) {
			$a = strtolower($attr->localName);
			if (strpos($a, 'on') === 0 || strpos($a, 'data-') === 0
					|| in_array($a, array('style', 'id', 'form', 'formaction', 'formmethod', 'formtarget', 'srcset', 'srcdoc', 'xlink:href'), true)) {
				$bad[] = 'attribute ' . $a . ' on ' . $name;
			}
			if (in_array($a, array('href', 'src', 'action'), true)) {
				$plain = preg_replace('~[\x00-\x20\x7F]+~', '', $attr->value);
				if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $plain, $m) && !in_array(strtolower($m[1]), array('http', 'https', 'mailto'), true)) {
					$bad[] = $a . ' ' . $m[1] . ': on ' . $name;
				}
			}
		}
		if ($name === 'form' && $el->getAttribute('action') !== $options['page_url']) {
			$bad[] = 'form posting to ' . $el->getAttribute('action');
		}
		if ($name === 'input' && in_array(strtolower((string)$el->getAttribute('type')), array('password', 'file', 'image'), true)) {
			$bad[] = 'input ' . $el->getAttribute('type');
		}
		if ($name === 'img' && strpos((string)$el->getAttribute('src'), $options['asset_url']) !== 0 && strpos((string)$el->getAttribute('src'), 'https://img.example.com/') !== 0) {
			$bad[] = 'img from ' . $el->getAttribute('src');
		}
	}
	return $bad;
}

$corpus = array(
	'event handlers and style'      => '<p onclick="x()" style="color:red" id="a" data-jy-prompt="go">t</p>',
	'script and style'              => '<script>alert(1)</script><style>body{}</style>ok',
	'javascript: with a tab'        => '<a href="java&#x09;script:alert(1)">x</a>',
	'javascript: with spaces'       => '<a href="   javascript:alert(1)">x</a>',
	'javascript: upper case'        => '<a href="JaVaScRiPt:alert(1)">x</a>',
	'data: link'                    => '<a href="data:text/html,<script>alert(1)</script>">x</a>',
	'vbscript and file'             => '<a href="vbscript:x">a</a><a href="file:///etc/passwd">b</a>',
	'noscript title escape'         => '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
	'svg style img'                 => '<svg><style><img src=x onerror=alert(1)></style></svg>',
	'math mglyph style'             => '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
	'nested form math'              => '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
	'template content'              => '<template><script>alert(1)</script><img src=x onerror=1></template>',
	'comment break-out'             => '<!--><script>alert(1)</script>--><!-- --!><img src=x onerror=1>-->',
	'textarea break-out'            => '<textarea></textarea><script>alert(1)</script>',
	'select style'                  => '<select><style><img src=x onerror=1></style></select>',
	'iframe srcdoc'                 => '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
	'object and embed'              => '<object data="javascript:x"></object><embed src="x.swf">',
	'base and meta refresh'         => '<base href="//evil.example/"><meta http-equiv="refresh" content="0;url=//evil">',
	'link stylesheet'               => '<link rel="stylesheet" href="//evil/x.css">',
	'form to an admin action'       => '<form action="/admin/admin_users" method="post"><input name="q"><button>go</button></form>',
	'button formaction'             => '<form><button formaction="/admin/x" formmethod="post">go</button></form>',
	'input attached to another form'=> '<input form="core-form" name="x"><button form="core-form">go</button>',
	'password and file inputs'      => '<form><input type="password" name="p"><input type="file" name="f"><input type="image" src="x"></form>',
	'core csrf token'               => '<form method="post"><input type="hidden" name="_csrf_token" value="abc"><input name="ok"></form>',
	'image from another host'       => '<img src="https://evil.example/pixel.gif?leak=1"><img src="//evil.example/x.png">',
	'image srcset'                  => '<img src="assets/a.png" srcset="https://evil.example/x.png 2x">',
	'image path escape'             => '<img src="assets/../../config/x.png"><img src="assets//x.png">',
	'image data: uri'               => '<img src="data:image/png;base64,AAAA">',
	'unknown elements'              => '<blink>x</blink><marquee>y</marquee><custom-el onclick=1>z</custom-el>',
	'frameset'                      => '<frameset><frame src="javascript:x"></frameset>',
	'xlink href'                    => '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
	'entity smuggling'              => '<a href="&#106;avascript:alert(1)">x</a>',
	'null byte'                     => "<a href=\"java\0script:alert(1)\">x</a><scr\0ipt>alert(1)</script>",
	'table foster parenting'        => '<table><tr><td>a</td></tr><script>alert(1)</script></table>',
	'select in table'               => '<table><select><option><style><img src=x onerror=1>',
	'details ontoggle'              => '<details open ontoggle="alert(1)"><summary>s</summary></details>',
);

section('The corpus, cleaned directly');
foreach ($corpus as $label => $html) {
	try {
		$out = $cleaner->clean($html);
		$bad = sbx_cleaner_violations($out, $options);
		check($bad === array(), $label, $bad ? implode('; ', $bad) . ' in ' . $out : $out);
		check($cleaner->clean($out) === $out, $label . ': clean twice, the same');
	} catch (DocumentTextException $e) {
		// A refusal is a safe answer too: the page is not shown.
		check(true, $label . ' (refused: ' . $e->getMessage() . ')');
	}
}

section('What is kept');
$kept = $cleaner->clean('<h2 class="text-center">Title</h2><p>Hello <strong>you</strong> <a href="https://example.com/a">link</a> <a href="/profile">here</a> <a href="mailto:a@b.c">mail</a></p>'
	. '<table><tr><th scope="col">A</th></tr><tr><td colspan="2">1</td></tr></table><ul><li>one</li></ul>'
	. '<form method="post" action="/elsewhere"><label>Name <input name="who" required></label><select name="pick"><option value="a" selected>A</option></select>'
	. '<textarea name="note" rows="3"></textarea><input type="checkbox" name="ok" checked><button type="submit">Send</button></form>'
	. '<img src="assets/logo.png" alt="Logo"><img src="https://img.example.com/a.png" alt="A">');
foreach (array('<h2 class="text-center">Title</h2>', '<strong>you</strong>', 'href="https://example.com/a"', 'href="/profile"', 'href="mailto:a@b.c"',
	'<th scope="col">', '<td colspan="2">', '<li>one</li>', 'action="' . htmlspecialchars($options['page_url']) . '"', '<form method="post"',
	'name="who"', 'required', '<select name="pick">', 'selected', '<textarea name="note" rows="3">', 'checked', '<button type="submit">',
	'src="/sandbox_asset?plugin=p&amp;file=logo.png"', 'src="https://img.example.com/a.png"') as $needle) {
	check(strpos($kept, $needle) !== false, 'Kept: ' . $needle, $kept);
}
check(SandboxHtmlCleaner::linkAllowed('/a?b=c:d') && SandboxHtmlCleaner::linkAllowed('page#x:y') && !SandboxHtmlCleaner::linkAllowed('a:b'),
	'A colon after the path starts is relative; one before it is a scheme');

section('Classes, core names, GET forms, newlines, autocomplete (reviewer2 H1-H5)');
$c2 = new SandboxHtmlCleaner($options + array('form_hidden' => array('plugin' => 'p', 'page' => 'x')));
$out = $c2->clean('<div class="jy-checkout-modal sidebar-overlay jy-btn alert-close">a</div><p class="is-fullscreen">b</p>');
check(strpos($out, '<div class="jy-btn">a</div>') !== false && strpos($out, 'checkout') === false && strpos($out, 'overlay') === false
	&& strpos($out, 'alert-close') === false && strpos($out, '<p>b</p>') !== false, 'Only the kit\'s content classes are kept', $out);
$out = $c2->clean('<form method="post"><input type="hidden" name="plugin" value="b"><input name="page" value="settings">'
	. '<input name="action" value="settings"><input name="section" value="0"><input name="ok"></form>');
check(strpos($out, 'value="b"') === false && strpos($out, 'settings') === false && strpos($out, 'name="section"') === false && strpos($out, 'name="ok"') !== false,
	'Controls named plugin, page, action or section are dropped', $out);
$out = $c2->clean('<form method="get"><input type="hidden" name="plugin" value="b"><input name="q"></form>');
check(substr_count($out, 'name="plugin"') === 1 && strpos($out, '<input type="hidden" name="plugin" value="p">') !== false
	&& strpos($out, '<input type="hidden" name="page" value="x">') !== false && $c2->clean($out) === $out,
	'A GET form carries the page\'s own plugin and page, and nothing the plugin named', $out);
$out = $c2->clean('<form method="post"><input name="q"></form>');
check(strpos($out, 'type="hidden"') === false, 'A POST form gets none (its action keeps the query)', $out);
foreach (array("<pre>\n\nfoo</pre>", "<textarea>\n\nbar</textarea>", "<pre>\nbaz</pre>") as $case) {
	try {
		$out = $c2->clean($case);
		check($c2->clean($out) === $out, 'A leading newline is kept and stable: ' . json_encode($case), json_encode($out));
	} catch (DocumentTextException $e) {
		check(false, 'A leading newline is kept and stable: ' . json_encode($case), $e->getMessage());
	}
}
$out = $c2->clean('<input name="a" autocomplete="cc-number"><input name="b" autocomplete="off">');
check(strpos($out, 'cc-number') === false && strpos($out, 'autocomplete="off"') !== false, 'autocomplete keeps on and off only', $out);

section('Through the parser jail');
$r = DocumentText::parseWith('SandboxHtmlCleaner', '<p onclick="x">Hi</p><script>alert(1)</script>', $options);
check($r['status'] === DocumentText::OK && $r['text'] === '<p>Hi</p>', 'DocumentText::parseWith runs the cleaner in the jail', json_encode($r));
$r = DocumentText::parseWith('SandboxHtmlCleaner', str_repeat('a', SandboxHtmlCleaner::MAX_BYTES + 1), $options);
check($r['status'] !== DocumentText::OK, 'A body over 1 MB is refused', json_encode(array($r['status'], $r['detail'])));

harness_finish();
