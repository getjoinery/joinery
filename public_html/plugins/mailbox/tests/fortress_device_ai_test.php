<?php
/** @joinery-test
 * name: fortress_device_ai
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * AI on Fortress mail, the server's half (specs/fortress_mail_device_ai.md
 * § R3, R4): MailboxDeviceAi and the mailbox/device_ai_entries,
 * mailbox/device_ai_verdict and mailbox/ai_device_record actions.
 *
 * Pins: the queue pages the owner's unread, unjudged Fortress messages newest
 * first by a cursor, carrying each one's sealed columns and nothing opened,
 * and never another member's row or a row sealed to anything but the mail
 * vault; a verdict writes its sealed field, the clear score and scan time
 * (the scan), and the recipe's `done` row together, or nothing at all, and a
 * second post for the same message is a no-op; a recipe writes only its own
 * field; `error` is refused for another member's recipe or message; a call
 * that never reached the model records nothing, so the message is offered
 * again; the actions answer through the API layer with the caller's session.
 *
 * Run: php tests/run.php test-db --filter=fortress_device_ai
 *
 * @version 1.3 - each recipe carries the reasoning control a server run would send
 * @version 1.2 - on demand: a verdict replaces an error, never a done; consent at the verdict write
 * @version 1.1 - the domain's consent, the recipes a device runs, a registered model per owner
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!class_exists('Recipe')) {
	harness_skip('the joinery_ai plugin is not active');
	harness_finish();
}
harness_test_mode();
require_once(__DIR__ . '/lib/fortress_fixture.php');

function fdai_recipe(int $owner_id, string $job_id, string $address): Recipe {
	$recipe = new Recipe(NULL);
	$recipe->set('rcp_name', 'fdai test ' . bin2hex(random_bytes(3)));
	$recipe->set('rcp_mode', Recipe::MODE_PIPELINE);
	$recipe->set('rcp_pipeline_job', $job_id);
	$recipe->set('rcp_source_config', json_encode(array('mailbox_aliases' => array($address))));
	$recipe->set('rcp_owner_user_id', $owner_id);
	$recipe->set('rcp_enabled', true);
	$recipe->save();
	$recipe->load();
	harness_register_row('rcp_recipes', 'rcp_recipe_id', intval($recipe->key));
	harness_defer(function () use ($recipe) {
		DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM aip_recipe_item_log WHERE aip_rcp_recipe_id = ?')
			->execute(array(intval($recipe->key)));
	});
	return $recipe;
}

/** Runs $fn; the MailboxDeviceAiException message, or null. */
function fdai_refusal(callable $fn): ?string {
	try { $fn(); } catch (MailboxDeviceAiException $e) { return $e->getMessage(); }
	return null;
}

function fdai_log(int $recipe_id, int $mid): ?string {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT aip_status FROM aip_recipe_item_log WHERE aip_rcp_recipe_id = ? AND aip_item_key = ?');
	$q->execute(array($recipe_id, (string)$mid));
	$v = $q->fetchColumn();
	return $v === false ? null : (string)$v;
}

try {
	$db = DbConnector::get_instance()->get_db_link();
	$crypto = new VaultCrypto();
	$a = fortress_fixture('DaiA');
	$b = fortress_fixture('DaiB');
	$A = $a['owner_id'];
	$B = $b['owner_id'];
	$m1 = fortress_ingest($a, 'First order');
	$m2 = fortress_ingest($a, 'Second order');
	$m3 = fortress_ingest($a, 'Third order');
	$m4 = fortress_ingest($a, 'Already read');
	$m5 = fortress_ingest($a, 'Lowered');
	$mb = fortress_ingest($b, 'Someone else\'s');
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_is_read = true WHERE iem_inbound_email_message_id = ?')->execute(array($m4));
	$r5 = fortress_row($m5);
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_key = ? WHERE iem_inbound_email_message_id = ?')
		->execute(array('v1.edgeseal.user.' . substr((string)$r5['iem_sealed_key'], 17), $m5));

	// Each owner has a model registered on their own machine, and their Fortress
	// domain has AI turned on: the queue honours the domain's consent (R5).
	foreach (array($a, $b) as $fx) {
		$fx['domain']->set('ied_ai_processing_enabled', true);
		$fx['domain']->save();
		MailboxAliasConfig::clearPostureCache();
		MailboxDeviceAiHost::setForUser($fx['owner_id'], 'http://localhost:11434');
		$uid = $fx['owner_id'];
		harness_defer(function () use ($uid) { MailboxDeviceAiHost::setForUser($uid, ''); });
	}

	$triage = fdai_recipe($A, 'email_triage', $a['address']);
	$scan = fdai_recipe($A, 'email_security_scan', $a['address']);
	$b_triage = fdai_recipe($B, 'email_triage', $b['address']);
	$sched = fdai_recipe($A, 'email_schedule', $a['address']);
	$T = (int)$triage->key;
	$S = (int)$scan->key;

	$seal = function (array $fx, int $mid, string $field, string $plain) use ($crypto) {
		$dek = fortress_open_dek($fx, (string)fortress_row($mid)['iem_sealed_key']);
		return $crypto->sealFieldForBrowser($plain, $dek, 'mail:' . $mid . ':' . $field);
	};

	// -------------------------------------------------------------------------
	section('The queue');

	$page = MailboxDeviceAi::entries($A, $T);
	$ids = array_column($page['entries'], 'id');
	check($ids === array($m3, $m2, $m1), 'the owner\'s unread, unjudged Fortress messages, newest first', json_encode($ids));
	check(!in_array($m4, $ids, true), 'a read message is not offered (unread only, as the server does for Private)');
	check(!in_array($m5, $ids, true), 'a row sealed to anything but the mail vault (v1.edgeseal.user.) is not offered');
	check(!in_array($mb, $ids, true), 'another member\'s message never appears');
	$e0 = $page['entries'][0];
	check(strncmp($e0['sealed']['sealed_dek'], 'v1.edgeseal.mail.', 17) === 0 && $e0['sealed']['sealed_scope'] === 'mail'
		&& isset($e0['sealed']['iem_subject'], $e0['sealed']['iem_body_plain'], $e0['sealed']['iem_sender']),
		'each entry carries the sealed columns one judgement needs');
	$opened = fortress_open_field($a, $e0['sealed']['iem_subject'], fortress_open_dek($a, $e0['sealed']['sealed_dek']),
		'mail:' . $m3 . ':iem_subject');
	check($opened === 'Third order', 'and they open as the owner\'s browser opens them');
	$plain_leak = false;
	array_walk_recursive($page, function ($v) use (&$plain_leak) { if (is_string($v) && stripos($v, 'order') !== false) $plain_leak = true; });
	check(!$plain_leak, 'nothing in the page is readable content');
	check(isset($e0['dkim_result']) || array_key_exists('dkim_result', $e0), 'the authentication results ride in the clear');
	check($page['next_before_id'] === null, 'one page holds them all: no cursor');
	check(array_column(MailboxDeviceAi::entries($A, $T, 0, $m3)['entries'], 'id') === array($m2, $m1), 'the cursor pages below it');
	check(array_column(MailboxDeviceAi::entries($A, $T, intval($a['alias']->key))['entries'], 'id') === array($m3, $m2, $m1),
		'narrowed to the covered mailbox: the same');
	check(fdai_refusal(function () use ($A, $T, $b) { MailboxDeviceAi::entries($A, $T, intval($b['alias']->key)); }) !== null,
		'a mailbox the recipe does not cover: refused');
	check(fdai_refusal(function () use ($A, $b_triage) { MailboxDeviceAi::entries($A, (int)$b_triage->key); }) !== null,
		'another member\'s recipe: refused');
	check(fdai_refusal(function () use ($A, $sched) { MailboxDeviceAi::entries($A, (int)$sched->key); }) !== null,
		'a job that does not run on a device (the schedule job): refused');

	// -------------------------------------------------------------------------
	section('The domain\'s consent decides where mail may go');

	check(MailboxDeviceAi::originTrust('http://localhost:11434') === 'local' && MailboxDeviceAi::originTrust('http://100.69.1.2:11434') === 'local',
		'a model on this computer or the owner\'s network is local');
	check(MailboxDeviceAi::originTrust('https://api.fireworks.ai') === 'trusted', 'the platform\'s trusted provider is trusted');
	check(MailboxDeviceAi::originTrust('https://models.example.test') === 'cloud', 'anywhere else is cloud');
	MailboxDeviceAiHost::setForUser($A, 'https://models.example.test');
	$e = fdai_refusal(function () use ($A, $T) { MailboxDeviceAi::entries($A, $T); });
	check($e !== null && stripos($e, 'own machines') !== false, 'a local-only domain pages no work for a model elsewhere', (string)$e);
	$a['domain']->set('ied_ai_processing_consent', InboundEmailDomain::CONSENT_CLOUD);
	$a['domain']->save();
	MailboxAliasConfig::clearPostureCache();
	check(fdai_refusal(function () use ($A, $T) { MailboxDeviceAi::entries($A, $T); }) === null, 'a domain that allows any model: the queue answers');
	$a['domain']->set('ied_ai_processing_consent', InboundEmailDomain::CONSENT_LOCAL);
	$a['domain']->set('ied_ai_processing_enabled', false);
	$a['domain']->save();
	MailboxAliasConfig::clearPostureCache();
	MailboxDeviceAiHost::setForUser($A, 'http://localhost:11434');
	$e = fdai_refusal(function () use ($A, $T) { MailboxDeviceAi::entries($A, $T); });
	check($e !== null && stripos($e, 'turned off') !== false, 'a domain with AI turned off pages nothing', (string)$e);
	$a['domain']->set('ied_ai_processing_enabled', true);
	$a['domain']->save();
	MailboxAliasConfig::clearPostureCache();
	MailboxDeviceAiHost::setForUser($A, '');
	$e = fdai_refusal(function () use ($A, $T) { MailboxDeviceAi::entries($A, $T); });
	check($e !== null && stripos($e, 'No model') !== false, 'no registered model: nothing to page', (string)$e);
	MailboxDeviceAiHost::setForUser($A, 'http://localhost:11434');

	$rec = MailboxDeviceAi::recipesFor($A, $a['address']);
	$jobs = array_column($rec['recipes'], 'job_id');
	sort($jobs);
	check($jobs === array('email_security_scan', 'email_triage'), 'the recipes a device may run on this mailbox, and not the schedule job', json_encode($jobs));
	$scan_rec = $rec['recipes'][array_search('email_security_scan', array_column($rec['recipes'], 'job_id'))];
	check(strpos($scan_rec['system'], 'You are a Joinery AI pipeline judge.') === 0 && strpos($scan_rec['system'], 'UNTRUSTED_' . $scan_rec['nonce']) !== false
		&& strpos($scan_rec['system'], 'email security analyst') !== false, 'each carries the full system prompt under its fresh nonce');
	check($scan_rec['reasoning_effort'] === 'none', 'a recipe with thinking off sends the reasoning control none', json_encode($scan_rec['reasoning_effort'] ?? null));
	check($scan_rec['min_tier'] === 'capable' && $scan_rec['attachments'] === false && isset($scan_rec['verdict_descriptor']['input']['score']),
		'and its tier floor, digest shape and verdict descriptor');
	check($rec['consent_refusal'] === null && !empty($rec['model_reference']['models']), 'with the consent verdict and the model reference');
	check(fdai_refusal(function () use ($A, $b) { MailboxDeviceAi::recipesFor($A, $b['address']); }) !== null, 'another member\'s mailbox: refused');

	// -------------------------------------------------------------------------
	section('A verdict, with its done row, together or not at all');

	$sum3 = $seal($a, $m3, 'iem_ai_summary', 'Third order is on its way.');
	check(MailboxDeviceAi::recordVerdict($A, $T, $m3, array('iem_ai_summary' => $sum3)) === true, 'the triage verdict is recorded');
	check(fortress_row($m3)['iem_ai_summary'] === $sum3 && fdai_log($T, $m3) === 'done', 'the sealed summary and the done row are both there');
	check(!in_array($m3, array_column(MailboxDeviceAi::entries($A, $T)['entries'], 'id'), true), 'the judged message leaves the queue');
	$again = $seal($a, $m3, 'iem_ai_summary', 'A second opinion.');
	check(MailboxDeviceAi::recordVerdict($A, $T, $m3, array('iem_ai_summary' => $again)) === false
		&& fortress_row($m3)['iem_ai_summary'] === $sum3, 'a second post for the same message is a no-op and writes nothing');

	$before = fortress_row($m2);
	$e = fdai_refusal(function () use ($A, $T, $m2) {
		MailboxDeviceAi::recordVerdict($A, $T, $m2, array('iem_ai_summary' => 'not sealed'));
	});
	check($e !== null && fdai_log($T, $m2) === null && fortress_row($m2)['iem_ai_summary'] === $before['iem_ai_summary'],
		'a verdict the door refuses leaves no done row behind', (string)$e);

	check(fdai_refusal(function () use ($A, $T, $m2, $seal, $a) {
		MailboxDeviceAi::recordVerdict($A, $T, $m2, array('iem_ai_scan' => $seal($a, $m2, 'iem_ai_scan', '{}')));
	}) !== null, 'the triage writes its summary and nothing else');
	check(fdai_refusal(function () use ($A, $T, $m2, $seal, $a) {
		MailboxDeviceAi::recordVerdict($A, $T, $m2, array('iem_body_html' => $seal($a, $m2, 'iem_body_html', 'x')));
	}) !== null, 'a body: refused');

	$scan_json = json_encode(array('verdict' => 'dangerous', 'red_flags' => array(array('check' => 'C', 'finding' => 'Lookalike domain.')),
		'summary' => 'Phishing.', 'model' => 'test-model', 'recipe_id' => $S));
	check(fdai_refusal(function () use ($A, $S, $m2, $seal, $a, $scan_json) {
		MailboxDeviceAi::recordVerdict($A, $S, $m2, array('iem_ai_scan' => $seal($a, $m2, 'iem_ai_scan', $scan_json)));
	}) !== null && fdai_log($S, $m2) === null, 'a scan without its danger score: refused, nothing recorded');
	check(MailboxDeviceAi::recordVerdict($A, $S, $m2, array('iem_ai_scan' => $seal($a, $m2, 'iem_ai_scan', $scan_json)), 8) === true,
		'the scan verdict is recorded');
	$r2 = fortress_row($m2);
	check((int)$r2['iem_ai_danger_score'] === 8 && !empty($r2['iem_ai_scan_time']) && fdai_log($S, $m2) === 'done'
		&& strncmp((string)$r2['iem_ai_scan'], 'v1.edge.', 8) === 0, 'the sealed scan, the clear score and time, and the done row');

	// -------------------------------------------------------------------------
	section('Who may record');

	$mb_sum = $seal($b, $mb, 'iem_ai_summary', 'x');
	check(fdai_refusal(function () use ($B, $T, $m1) { MailboxDeviceAi::recordError($B, $T, $m1); }) !== null && fdai_log($T, $m1) === null,
		'error for another member\'s recipe: refused — no one hides another\'s message from their own scan');
	check(fdai_refusal(function () use ($B, $b_triage, $m1) { MailboxDeviceAi::recordError($B, (int)$b_triage->key, $m1); }) !== null,
		'error with one\'s own recipe on another member\'s message: refused');
	check(fdai_refusal(function () use ($A, $T, $mb, $mb_sum) { MailboxDeviceAi::recordVerdict($A, $T, $mb, array('iem_ai_summary' => $mb_sum)); }) !== null,
		'a verdict on another member\'s message: refused');
	check(fdai_refusal(function () use ($A, $T, $m5) { MailboxDeviceAi::recordError($A, $T, $m5); }) !== null,
		'error on a row not sealed to the mail vault: refused');

	check(in_array($m1, array_column(MailboxDeviceAi::entries($A, $T)['entries'], 'id'), true),
		'a message whose call never reached the model has nothing recorded, and is offered again');
	check(MailboxDeviceAi::recordError($A, $T, $m1) === true && fdai_log($T, $m1) === 'error', 'the owner records error for an invalid answer');
	check(MailboxDeviceAi::recordError($A, $T, $m1) === false, 'a second error changes nothing');

	// On demand (R6, Q3): a message once logged `error` takes a verdict, which
	// replaces the error; a `done` is never replaced.
	$od = $seal($a, $m1, 'iem_ai_summary', 'Judged on demand.');
	check(MailboxDeviceAi::recordVerdict($A, $T, $m1, array('iem_ai_summary' => $od)) === true
		&& fdai_log($T, $m1) === 'done' && fortress_row($m1)['iem_ai_summary'] === $od,
		'a verdict on a message logged error replaces the error with done and stores the summary');
	check(MailboxDeviceAi::recordVerdict($A, $T, $m1, array('iem_ai_summary' => $seal($a, $m1, 'iem_ai_summary', 'Again.'))) === false
		&& fortress_row($m1)['iem_ai_summary'] === $od, 'and a later verdict still leaves the done one as it is');

	// The verdict write asks the domain's consent too: nothing is stored from a
	// model the domain does not allow.
	MailboxDeviceAiHost::setForUser($A, 'https://models.example.test');
	$e = fdai_refusal(function () use ($A, $S, $m1, $seal, $a) {
		MailboxDeviceAi::recordVerdict($A, $S, $m1, array('iem_ai_scan' => $seal($a, $m1, 'iem_ai_scan', '{}')), 1);
	});
	check($e !== null && fdai_log($S, $m1) === null, 'a verdict for a model a local-only domain refuses is not stored', (string)$e);
	MailboxDeviceAiHost::setForUser($A, 'http://localhost:11434');
	check(array_column(MailboxDeviceAi::entries($A, $T)['entries'], 'id') === array($m2),
		'the triage queue now holds only the message the SCAN judged — each recipe keeps its own log');

	// -------------------------------------------------------------------------
	section('Through the API layer, as the owner');

	if (session_id() === '') @session_start();
	$saved = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null);
	harness_defer(function () use ($saved) {
		foreach ($saved as $k => $v) { if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; } }
	});
	$_SESSION['usr_user_id'] = $A;
	$_SESSION['loggedin'] = true;
	$res = harness_call_logic('plugins/mailbox/logic/device_ai_entries_logic.php', 'device_ai_entries_logic', array('recipe_id' => $S));
	check($res->error === null && array_column($res->data['entries'], 'id') === array($m3, $m1),
		'device_ai_entries answers the owner\'s scan queue', (string)$res->error);
	$res = harness_call_logic('plugins/mailbox/logic/device_ai_verdict_logic.php', 'device_ai_verdict_logic', array(
		'id' => $m3, 'recipe_id' => $S, 'danger_score' => 1,
		'fields' => array('iem_ai_scan' => $seal($a, $m3, 'iem_ai_scan', json_encode(array('verdict' => 'safe', 'red_flags' => array(),
			'summary' => 'Fine.', 'model' => 'test-model', 'recipe_id' => $S))))));
	check($res->error === null && $res->data['recorded'] === true && fdai_log($S, $m3) === 'done', 'device_ai_verdict records it', (string)$res->error);
	$res = harness_call_logic('plugins/mailbox/logic/ai_device_record_logic.php', 'ai_device_record_logic',
		array('recipe_id' => (int)$b_triage->key, 'item_key' => $mb));
	check($res->error !== null, 'ai_device_record refuses another member\'s recipe');
	unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);
	$res = harness_call_logic('plugins/mailbox/logic/device_ai_entries_logic.php', 'device_ai_entries_logic', array('recipe_id' => $S));
	check($res->error !== null, 'signed out: refused');
} catch (Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
