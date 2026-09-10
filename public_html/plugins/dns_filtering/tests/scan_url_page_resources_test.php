<?php
/** @joinery-test
 * name: scan_url_page_resources
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * ScanUrlPageResources — the scanned page is read in the parser jail and
 * every resource URL it refers to comes back as a list (specs/parser_jail.md).
 *
 * Run: php plugins/dns_filtering/tests/scan_url_page_resources_test.php
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/dns_filtering/includes/ScanUrlPageResources.php'));

section('Every resource reference is listed');
$page = '<html><head><link rel="stylesheet" href="https://cdn.example/a.css">'
	. '<style>body{background:url("https://img.example/bg.png")} .x{background:url(/local.png)}</style>'
	. '<script src="//tracker.example/t.js"></script></head><body>'
	. '<img src="https://img.example/1.png" srcset="https://img.example/2.png 2x, https://img.example/3.png 3x">'
	. '<picture><source srcset="https://img.example/4.webp"><source src="https://img.example/5.webp"></picture>'
	. '<iframe src="https://frames.example/f"></iframe><video src="https://v.example/v.mp4"></video>'
	. '<audio src="https://a.example/a.mp3"></audio><form action="https://forms.example/post"></form>'
	. '<a href="https://links.example/not-a-resource">x</a></body></html>';
$r = DocumentText::parseWith('ScanUrlPageResources', $page);
check($r['status'] === DocumentText::OK, 'the page reads', $r['status'] . ' ' . (string)$r['detail']);
$urls = json_decode((string)$r['text'], true);
check(is_array($urls), 'the answer is a list');
foreach (array('https://cdn.example/a.css', 'https://img.example/bg.png', '/local.png', '//tracker.example/t.js',
		'https://img.example/1.png', 'https://img.example/2.png', 'https://img.example/3.png',
		'https://img.example/4.webp', 'https://img.example/5.webp', 'https://frames.example/f',
		'https://v.example/v.mp4', 'https://a.example/a.mp3', 'https://forms.example/post') as $u) {
	check(in_array($u, $urls, true), 'lists ' . $u);
}
check(!in_array('https://links.example/not-a-resource', $urls, true), 'a plain link is not a resource');

section('Contract');
$r = DocumentText::parseWith('ScanUrlPageResources', 'not html at all');
check($r['status'] === DocumentText::OK && json_decode((string)$r['text'], true) === array(), 'a page with nothing lists nothing');
$src = file_get_contents(PathHelper::getIncludePath('plugins/dns_filtering/logic/scan_url_logic.php'));
foreach (array('DOMDocument', 'loadHTML') as $token) {
	check(!preg_match('/^[^*\n]*\b' . $token . '\b/m', $src), 'scan_url_logic (pool side) never names ' . $token);
}
check(strpos($src, "DocumentText::parseWith('ScanUrlPageResources'") !== false, 'scan_url_logic hands the page to the jail');

harness_finish();
