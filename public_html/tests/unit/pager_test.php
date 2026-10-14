<?php
/** @joinery-test
 * name: pager
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Pager reads the request line without parse_url() (specs/post_release_fleet_defects.md B4.3):
 * a scheme-relative or absolute-form request line (what scanners send) used to
 * make parse_url() return false and the constructor warn on every such hit.
 *
 * Run: php tests/unit/pager_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$warnings = array();
set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });

section('An ordinary request');
$p = new Pager(array('getvars' => '/admin/admin_users?offset=30&sort=name&x=1', 'numrecords' => 100));
check($p->base_url() === '/admin/admin_users', 'the base is the path', $p->base_url());
check(strpos($p->current_url(array('offset')), '/admin/admin_users?offset=30') === 0, 'links keep the base', $p->current_url(array('offset')));

section('A scheme-relative request line yields a base URL and no warning');
$warnings = array();
$p = new Pager(array('getvars' => '//x:abc', 'numrecords' => 10));
check($warnings === array(), 'no warning', implode('; ', $warnings));
check($p->base_url() === '', 'the base is empty, so links are relative', $p->base_url());
check(strpos($p->current_url(), '//') === false, 'and never point off the site', $p->current_url());

section('An absolute-form request line');
$warnings = array();
$p = new Pager(array('getvars' => 'http:///x?offset=5', 'numrecords' => 10));
check($warnings === array(), 'no warning', implode('; ', $warnings));
check($p->base_url() === '', 'an absolute-form base is not kept', $p->base_url());
check(strpos($p->current_url(array('offset')), '?offset=5') === 0, 'the variables still parse', $p->current_url(array('offset')));

section('A fragment is not a query variable');
$p = new Pager(array('getvars' => '/page?offset=2#top', 'numrecords' => 10));
check(strpos($p->current_url(array('offset')), '?offset=2') !== false && strpos($p->current_url(array('offset')), '#') === false, 'the fragment is dropped', $p->current_url(array('offset')));

section('What comes from the URL is escaped on the way back out');
$warnings = array();
$p = new Pager(array('getvars' => '/admin/admin_users?x=%22%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E&filter=a&tags[]=b%26c', 'numrecords' => 100));
$hidden = $p->url_vars_as_hidden_input(array('filter'));
check(strpos($hidden, '<script>') === false && strpos($hidden, 'value="&quot;&gt;&lt;script&gt;') !== false,
	'a query value cannot close the hidden input', $hidden);
check(strpos($hidden, 'name="filter"') === false, 'the excluded name is left to its own control', $hidden);
check(strpos($hidden, 'name="tags[0]" value="b&amp;c"') !== false, 'an array variable becomes one input per value', $hidden);
check($warnings === array(), 'no warning', implode('; ', $warnings));

$p = new Pager(array('getvars' => '/x?rfilter=old&keep=1', 'numrecords' => 100), 'r');
$hidden = $p->url_vars_as_hidden_input(array('filter'));
check(strpos($hidden, 'rfilter') === false && strpos($hidden, 'name="keep"') !== false,
	'a prefixed pager excludes its own prefixed name', $hidden);

section('Links re-encode the values they carry');
$p = new Pager(array('getvars' => '/admin/admin_users?searchterm=a%26b%22c&x=%22%3E', 'numrecords' => 100, 'numperpage' => 10));
$link = $p->get_url(2);
check(strpos($link, '"') === false && strpos($link, '>') === false, 'no quote or bracket reaches a link', (string)$link);
check(strpos($link, 'searchterm=a%26b%22c') !== false, 'the search term survives the round trip', (string)$link);

section('An array in place of a pager control is ignored');
$warnings = array();
$p = new Pager(array('getvars' => '/x?filter[]=a&sort[]=b&offset[]=3', 'numrecords' => 100));
check($p->get_filter() === null && $p->get_sort() === null, 'the controls read as unset', var_export($p->get_filter(), true));
$p->url_vars_as_hidden_input(array('filter'));
$p->get_url(2);
check($warnings === array(), 'no warning', implode('; ', $warnings));

restore_error_handler();
harness_finish();
