<?php
/** @joinery-test
 * name: content_version_history
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * A saved version is the content as it was before the save.
 *
 * The edit pages offer "Load another version" to bring back earlier content,
 * so each save records what it is about to replace. Recording the new content
 * instead made every listed version the current one, and the content a
 * record was created with was never kept at all. A record not saved yet has
 * no versions.
 *
 * Run: php tests/unit/content_version_history_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$suffix = substr(md5((string)microtime(true)), 0, 6);

$latest_version = function ($type, $id) {
	$versions = new MultiContentVersion(array('type' => $type, 'foreign_key_id' => $id),
		array('content_version_id' => 'DESC'), 1);
	foreach ($versions as $v) {
		return $v;
	}
	return null;
};

section('An edit records the content it replaces');

$template = new EmailTemplate(NULL);
$template->set('emt_name', 'Harness template v1 ' . $suffix);
$template->set('emt_body', 'body v1');
$template->set('emt_type', 1);
$template->save();
harness_register_model('EmailTemplate', (int)$template->key);
check($latest_version(ContentVersion::TYPE_EMAIL_TEMPLATE, $template->key) === null,
	'creating a record records no version (the record itself holds v1)');

$template = new EmailTemplate($template->key, TRUE);
$template->set('emt_name', 'Harness template v2 ' . $suffix);
$template->set('emt_body', 'body v2');
$template->save();
$v = $latest_version(ContentVersion::TYPE_EMAIL_TEMPLATE, $template->key);
check($v !== null && $v->get('cvn_content') === 'body v1', 'the version holds the body before the save',
	$v ? (string)$v->get('cvn_content') : 'no version');
check($v !== null && $v->get('cvn_title') === 'Harness template v1 ' . $suffix, 'and the name before the save',
	$v ? (string)$v->get('cvn_title') : 'no version');
$template = new EmailTemplate($template->key, TRUE);
check($template->get('emt_body') === 'body v2', 'the record holds the new body');

section('A record not saved yet has no versions');

$none = new MultiContentVersion(array('type' => ContentVersion::TYPE_EMAIL_TEMPLATE, 'foreign_key_id' => NULL));
check(count($none) === 0, 'no record id lists none, never every record\'s', 'count: ' . count($none));

harness_finish();
