<?php
/** @joinery-test
 * name: model_rules_on_every_save
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A model's own rules run on every save (spec model_rules_on_every_save),
 * whether or not the caller ran prepare():
 *
 *  - a save that breaks a rule, with no prepare() call, reaches the rule: while
 *    SystemBase::MODEL_RULES is 'log' it is logged as [MODEL_RULES] and the
 *    save goes ahead
 *  - a 'stamp_on_save' column is set on insert and on update
 *  - a name stored as it is survives an unrelated save; a name being changed
 *    is still tidied
 *  - a boards rule refuses (it used to return a result nobody read)
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$log = harness_scratch_dir('model_rules') . '/error-' . getmypid() . '.log';
@unlink($log);
$previous_log = ini_set('error_log', $log);
harness_defer(function () use ($log, $previous_log) {
	ini_set('error_log', (string)$previous_log);
	@unlink($log);
});

section('A broken rule is reached without prepare()');
$code = new CouponCode(NULL);
$code->set('ccd_code', 'MRTEST' . strtoupper(bin2hex(random_bytes(4))));
$code->set('ccd_amount_discount', 5);
$code->set('ccd_percent_discount', 10);   // CouponCode::prepare(): an amount or a percent, not both
$saved = true;
try {
	$code->save();
} catch (Throwable $e) {
	$saved = false;
}
if ($code->key) {
	harness_register_row('ccd_coupon_codes', 'ccd_coupon_code_id', (int)$code->key);
}
$logged = is_file($log) ? (string)file_get_contents($log) : '';
if (SystemBase::MODEL_RULES === 'refuse') {
	check(!$saved, 'refuse mode: the save is refused');
} else {
	check($saved && $code->key, 'log mode: the save goes ahead');
	check(strpos($logged, '[MODEL_RULES] CouponCode') !== false && strpos($logged, 'Coupon cannot have an amount and a percent') !== false,
		'and the broken rule is logged as [MODEL_RULES], naming the model and the rule', substr($logged, 0, 300));
}

section('stamp_on_save');
$job = new ManagementJob(NULL);
$job->set('mjb_job_type', 'model_rules_test');
$job->set('mjb_commands', array());
$job->save();
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', (int)$job->key);
$first = (string)$job->get('mjb_update_time');
check($first !== '', 'set on insert', $first);
$db = DbConnector::get_instance()->get_db_link();
$db->prepare('UPDATE mjb_management_jobs SET mjb_update_time = ? WHERE mjb_management_job_id = ?')
	->execute(array('2001-01-01 00:00:00', (int)$job->key));
$job = new ManagementJob((int)$job->key, true);
$job->set('mjb_status', 'pending');
$job->save();
$again = (string)(new ManagementJob((int)$job->key, true))->get('mjb_update_time');
check(strpos($again, '2001-01-01') !== 0, 'and moved on an update', $again);
$db->prepare('UPDATE mjb_management_jobs SET mjb_update_time = ? WHERE mjb_management_job_id = ?')
	->execute(array('2001-01-01 00:00:00', (int)$job->key));
$job = new ManagementJob((int)$job->key, true);
$job->save();
$idle = (string)(new ManagementJob((int)$job->key, true))->get('mjb_update_time');
check(strpos($idle, '2001-01-01') === 0, 'and left alone by a save with nothing to write', $idle);

section('Names are tidied as they are typed, never on an unrelated save');
$user = User::CreateNew(array(
	'usr_email' => 'mrtest-' . bin2hex(random_bytes(4)) . '@example.com',
	'password' => bin2hex(random_bytes(12)),
	'usr_first_name' => 'Ada',
	'usr_last_name' => 'Lovelace',
), true);
harness_register_row('usr_users', 'usr_user_id', (int)$user->key);
$db->prepare('UPDATE usr_users SET usr_last_name = ? WHERE usr_user_id = ?')->execute(array('van der berg', (int)$user->key));
$user = new User((int)$user->key, true);
$user->set('usr_timezone', 'UTC');
$user->save();
check((new User((int)$user->key, true))->get('usr_last_name') === 'van der berg', 'a stored name survives an unrelated save as it is');
$user->set('usr_last_name', 'MCDONALD');
$user->save();
check((new User((int)$user->key, true))->get('usr_last_name') === 'Mcdonald', 'a name being changed is tidied', (new User((int)$user->key, true))->get('usr_last_name'));

section('A row going to the trash is not judged by the rules');
$bad = new CouponCode(NULL);
$bad->set('ccd_code', 'MRDEL' . strtoupper(bin2hex(random_bytes(4))));
$bad->set('ccd_amount_discount', 5);
$bad->set('ccd_percent_discount', 10);
$bad->save();
harness_register_row('ccd_coupon_codes', 'ccd_coupon_code_id', (int)$bad->key);
$before = is_file($log) ? substr_count((string)file_get_contents($log), '[MODEL_RULES] CouponCode') : 0;
if (isset(CouponCode::$field_specifications['ccd_delete_time'])) {
	$bad->soft_delete();
	$after = is_file($log) ? substr_count((string)file_get_contents($log), '[MODEL_RULES] CouponCode') : 0;
	check($after === $before, 'soft_delete() runs no model rule on a row that breaks one', "$before -> $after");
} else {
	check(true, 'coupon codes have no soft delete here (skipped)');
}

section('A boards rule refuses');
if (class_exists('BoardCard')) {
	$card = new BoardCard(NULL);
	$card->set('bcd_title', '   ');
	$refused = false;
	try { $card->prepare(); } catch (DisplayableUserException $e) { $refused = strpos($e->getMessage(), 'A card needs a title') !== false; }
	check($refused, 'a card with a blank title is refused');
} else {
	check(true, 'boards is not active here (skipped)');
}

harness_finish();
