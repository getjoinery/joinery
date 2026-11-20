<?php
/** @joinery-test
 * name: seo_content_summary
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A page's inferred description and share image come from what a reader sees
 * (SeoPageMetadata::summarize_html(), infer_preview_image()). A pasted embed
 * brings a style block, a script and a form with a vendor badge: the
 * description once read "#mc_embed_signup{background:#fff..." and the share
 * image was Mailchimp's logo. Text on either side of a block or line break
 * stays two words.
 *
 * Run: php tests/run.php safe --filter=seo_content_summary
 *
 * @version 1.1 - content is parsed as a browser reads it: a pasted span with broken quotes in its style keeps its text
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('The description is the visible text');
$embed = '<p>Location: Valencia, Spain<br>Phone: +55 61</p>'
	. '<link href="//cdn-images.mailchimp.com/x.css" rel="stylesheet">'
	. '<style type="text/css">#mc_embed_signup{background:#fff}</style>'
	. '<!-- a comment -->'
	. '<div id="mc_embed_signup"><form action="https://x.list-manage.com/subscribe/post"><h2>Subscribe</h2>'
	. '<label>Email Address</label><input name="EMAIL"><img src="https://eep.io/badge.svg"></form></div>'
	. '<script>window.fnames = new Array();</script><p>Classes every week.</p>';
$d = SeoPageMetadata::summarize_html($embed);
check($d === 'Location: Valencia, Spain Phone: +55 61 Classes every week.', 'style, script, comment and form text left out; breaks are spaces', json_encode($d));
check(SeoPageMetadata::summarize_html('<h1>One</h1><p>Two</p><ul><li>a</li><li>b</li></ul>') === 'One Two a b', 'block boundaries keep words apart');
check(SeoPageMetadata::summarize_html('<p>Bold <b>word</b>s stay joined</p>') === 'Bold words stay joined', 'an inline tag adds no space');
check(SeoPageMetadata::summarize_html('<style>a{}</style><form><label>x</label></form>') === null, 'nothing visible is no description');
// What an editor left on xandyliberato's community page: a style attribute
// whose font list broke out of its quotes. strip_tags() took everything after
// it for part of the tag, and the page had no description.
$broken = '<p><span style="font-family: -apple-system, " segoe="" ui",="" roboto,="" "helvetica="" neue",="" arial;"="">If you would like to be part of the community</span>, fill in this form:</p>';
$d = SeoPageMetadata::summarize_html($broken);
check($d === 'If you would like to be part of the community, fill in this form:', 'a broken attribute does not swallow the text after it', json_encode($d));

section('The share image is not one inside a form or script');
check(SeoPageMetadata::infer_preview_image('/', array('entity_body_html' => $embed)) === null, 'the badge inside the pasted form is not the share image');
check(SeoPageMetadata::infer_preview_image('/', array('entity_body_html' => $embed . '<img src="/uploads/hero.jpg">')) === '/uploads/hero.jpg',
	'the first image a reader sees is');

section('A body a member wrote is summarized without the parser');
$member = '<p>Hello <b>all</b></p><script>alert(1)</script><style>p{color:red}</style><ul><li>one</li><li>two &amp; three</li></ul>';
check(SeoPageMetadata::summarize_untrusted_html($member) === 'Hello all one two & three', 'tags, script and style go; block edges keep words apart',
	(string)SeoPageMetadata::summarize_untrusted_html($member));
check(SeoPageMetadata::summarize_untrusted_html('<style>a{}</style>  ') === null, 'nothing visible is no description');
check(SeoPageMetadata::infer_description('/', array('entity_body_untrusted_html' => $member)) === 'Hello all one two & three'
	&& SeoPageMetadata::infer_preview_image('/', array('entity_body_untrusted_html' => $member . '<img src="/x.jpg">')) === null,
	'it reaches the description, and no image is taken from it');
check(mb_strlen((string)SeoPageMetadata::summarize_untrusted_html(str_repeat('word ', 80))) <= 161, 'a long one is cut to length');

harness_finish();
