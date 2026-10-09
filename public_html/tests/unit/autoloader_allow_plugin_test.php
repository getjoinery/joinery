<?php
/** @joinery-test
 * name: autoloader_allow_plugin
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * An inactive plugin's classes do not resolve, except for the length of its own
 * install's migrations (ClassAutoloader::allowPlugin / forgetPlugin), which run
 * before activation and use the plugin's classes. Without it, installing
 * server_manager on a fresh site failed in sm_016 ('Class "CloudAccounts" not
 * found', 2026-10-09).
 *
 * Uses the items plugin, which is installed and inactive on dev; skipped where
 * it is active.
 *
 * Run: php tests/unit/autoloader_allow_plugin_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('An inactive plugin\'s classes resolve only while it is allowed in');

if (PluginHelper::isPluginActive('items')) {
	harness_skip('the items plugin is active here, so it cannot show the inactive rule');
} else {
	check(!class_exists('ItemRelationType'), 'an inactive plugin\'s class does not resolve');
	ClassAutoloader::allowPlugin('items');
	check(class_exists('ItemRelationType'), 'it resolves once the plugin is allowed in');
	ClassAutoloader::forgetPlugin('items');
	check(!class_exists('MultiItemRelation'), 'and its other classes stop resolving once it is forgotten');
	ClassAutoloader::forgetPlugin('items');
	check(true, 'forgetting twice is harmless');
}

harness_finish();
