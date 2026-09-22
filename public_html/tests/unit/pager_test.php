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

restore_error_handler();
harness_finish();
