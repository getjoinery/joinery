<?php
/** @joinery-test
 * name: content_script_check
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * ContentScriptCheck names what in stored content the site's enforced policy
 * stops, so the page and component admin pages can say so. Checked against
 * the platform's own policies (PublicPageBase::csp_policy()), standard and
 * strict, with a pasted Mailchimp embed as the example that started it.
 *
 * Run: php tests/run.php safe --filter=content_script_check
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$header = function (array $policy) {
	$parts = array();
	foreach ($policy as $d => $sources) $parts[] = $d . ' ' . implode(' ', $sources);
	return ContentScriptCheck::parse_policy(implode('; ', $parts));
};
$strict = $header(PublicPageBase::csp_policy(array(), array(), true));
$standard = $header(PublicPageBase::csp_policy());
$listed = $header(PublicPageBase::csp_policy(array(), array('https://acme.us14.list-manage.com'), true));

$embed = '<link href="//cdn-images.mailchimp.com/x.css" rel="stylesheet"><style>#mc{}</style>'
	. '<form action="https://acme.us14.list-manage.com/subscribe/post?u=1&amp;id=2" method="post"><input name="EMAIL">'
	. '<input type="submit" value="Subscribe"></form>'
	. '<script type="text/javascript" src="//s3.amazonaws.com/downloads.mailchimp.com/js/mc-validate.js"></script>'
	. '<script type="text/javascript">(function($) {window.fnames = new Array();}(jQuery));</script>';

section('A pasted Mailchimp embed under the strict policy');
$p = ContentScriptCheck::problems($embed, $strict);
$joined = implode(' | ', $p);
check(count($p) === 3, 'three problems named', $joined);
check(strpos($joined, 'https://s3.amazonaws.com is blocked') !== false, 'the off-site script');
check(strpos($joined, 'A script written into the content ((function($) {window.fnames') !== false, 'the inline script, with its start');
check(strpos($joined, 'A form sends to https://acme.us14.list-manage.com') !== false
	&& strpos($joined, 'Other addresses this site\'s forms may send to') !== false, 'the form address, and where to allow it');

section('Listing the form address in the setting clears that one');
$p = ContentScriptCheck::problems($embed, $listed);
check(count($p) === 2 && strpos(implode(' ', $p), 'A form sends to') === false, 'only the two scripts remain', implode(' | ', $p));

section('Under the standard policy an inline script still runs');
$p = ContentScriptCheck::problems($embed, $standard);
check(count($p) === 2 && strpos(implode(' ', $p), 'written into the content') === false, 'the off-site script and the form, not the inline one', implode(' | ', $p));

section('Handlers, javascript: links, and what passes');
$p = ContentScriptCheck::problems('<a href="javascript:void(0)" onclick="go()">x</a><img src="a.png" onerror="y()">', $strict);
check(count($p) === 2 && strpos($p[0], 'onclick, onerror') !== false, 'handlers listed once, the link named', implode(' | ', $p));
check(ContentScriptCheck::problems('<a href="javascript:void(0)" onclick="go()">x</a>', $standard) === array(), 'both run under the standard policy');
$fine = '<p>Hello</p><script type="application/ld+json">{"a":1}</script><script src="/assets/js/x.js"></script>'
	. '<script src="https://js.stripe.com/v3/"></script><script src="https://newassets.hcaptcha.com/c.js"></script>'
	. '<form action="/subscribe" method="post"></form><form action="https://www.paypal.com/cgi-bin/webscr"></form>'
	. '<iframe src="https://www.youtube.com/embed/x"></iframe><p>Reload the page online.</p>';
check(ContentScriptCheck::problems($fine, $strict) === array(), 'data blocks, own and listed scripts (wildcard host too), own and listed form targets: nothing',
	implode(' | ', ContentScriptCheck::problems($fine, $strict)));
check(ContentScriptCheck::problems('', $strict) === array(), 'empty content: nothing');

section('No enforced policy, no problems');
check(ContentScriptCheck::parse_policy('') === array(), 'an empty header parses to nothing');

harness_finish();
