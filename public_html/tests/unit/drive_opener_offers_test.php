<?php
/** @joinery-test
 * name: drive_opener_offers
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * DriveHelper::opener_offers(): what a member could open once a file is in
 * their Drive, asked before any file exists (the mail reader's "Open in
 * editor" on an attachment). Test openers stand in for plugins, and Drive's
 * on/off switch is overridden in memory only.
 *
 *   - nothing while Drive is off;
 *   - an opener without `available` is not offered outside Drive;
 *   - `available` answering null hides the opener;
 *   - a reason comes back as `explain`, true as a plain offer;
 *   - an `available` that throws is left out, not fatal.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

DriveHelper::resetOpenersForTest();
harness_defer(function () { DriveHelper::resetOpenersForTest(); });

$never = function () { return null; };
DriveHelper::registerOpener('test_plain', array(
	'label' => 'Open plain', 'extensions' => array('txt'), 'url' => $never,
));
DriveHelper::registerOpener('test_yes', array(
	'label' => 'Open in test editor', 'extensions' => array('.DOCX', 'xlsx'), 'url' => $never,
	'available' => function ($user_id) { return true; },
));
DriveHelper::registerOpener('test_hidden', array(
	'label' => 'Hidden here', 'extensions' => array('odt'), 'url' => $never,
	'available' => function ($user_id) { return null; },
));
DriveHelper::registerOpener('test_reason', array(
	'label' => 'Open in other editor', 'extensions' => array('ods'), 'url' => $never,
	'available' => function ($user_id) { return 'Not part of your plan.'; },
));
DriveHelper::registerOpener('test_throws', array(
	'label' => 'Broken', 'extensions' => array('pptx'), 'url' => $never,
	'available' => function ($user_id) { throw new RuntimeException('boom'); },
));

function doo_by_key(array $offers): array {
	$out = array();
	foreach ($offers as $o) $out[$o['key']] = $o;
	return $out;
}

section('Drive off');
harness_set_setting_mem('drive_active', '0');
check(DriveHelper::opener_offers(1) === array(), 'nothing is offered while Drive is off');

section('Drive on');
harness_set_setting_mem('drive_active', '1');
$offers = doo_by_key(DriveHelper::opener_offers(1));
check(!isset($offers['test_plain']), 'an opener without available is not offered outside Drive');
check(isset($offers['test_yes']) && !isset($offers['test_yes']['explain']), 'available true is a plain offer');
check(($offers['test_yes']['extensions'] ?? null) === array('docx', 'xlsx'), 'the offer carries its extensions, normalized');
check(($offers['test_yes']['label'] ?? null) === 'Open in test editor', 'the offer carries its label');
check(!isset($offers['test_hidden']), 'available null hides the opener');
check(($offers['test_reason']['explain'] ?? null) === 'Not part of your plan.', 'a reason comes back as explain');
check(!isset($offers['test_throws']), 'an available that throws is left out');
// A real plugin's opener may register here as well; only this test's are counted.
$mine = array_values(array_filter(array_keys($offers), function ($k) { return strpos($k, 'test_') === 0; }));
check(count($mine) === 2, 'exactly the two of this test\'s offers that apply', 'got ' . implode(',', $mine));

harness_finish();
