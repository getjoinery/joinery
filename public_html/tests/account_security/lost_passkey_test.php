<?php
/** @joinery-test
 * name: lost_passkey
 * tier: db
 * env: any
 * needs: [db]
 */
/**
 * Someone who has lost their passkey still holds their authenticator app, and
 * that must be enough to replace the passkey and to revoke the lost one.
 *
 * Both actions ask for a fresh second-factor confirmation. A passkey step-up
 * and an authenticator-code step-up (/verify-stepup) stamp the same marker, so
 * the server takes either; what each action must not do is insist on the
 * passkey, which is the one thing this person no longer has. Unconfirmed, each
 * answers second_factor_required, the flag that sends the page to the step-up
 * where the authenticator app is offered, rather than a dead-end error.
 *
 * The four vault actions behind the same confirmation answer with that flag
 * too (checked in their source: each needs a vault and a ceremony to reach).
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('logic/passkey_register_options_logic.php'));
require_once(PathHelper::getIncludePath('logic/passkey_revoke_logic.php'));

if (session_id() === '') @session_start();
if (session_id() === '') {
	harness_skip('lost passkey suite', 'no session could be started on the CLI');
	harness_finish();
}
$sid = session_id();
harness_defer(function () use ($sid) {
	DbConnector::get_instance()->get_db_link()
		->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?')->execute(array($sid));
});
harness_set_setting_mem('passkeys_enabled', '1');
harness_set_setting_mem('email_dry_run', '1');

$session = SessionControl::get_instance();
$user = make_user('LostPasskey');
$user->enable_totp('JBSWY3DPEHPK3PXP');
$lost = new Passkey(NULL);
$lost->set('pkc_usr_user_id', (int)$user->key);
$lost->set('pkc_credential_id', rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
$lost->set('pkc_source_json', '{}');
$lost->set('pkc_label', 'The phone that was lost');
$lost->save();
harness_defer(function () use ($lost) {
	DbConnector::get_instance()->get_db_link()
		->prepare('DELETE FROM pkc_passkey_credentials WHERE pkc_passkey_credential_id = ?')->execute(array((int)$lost->key));
});
$session->store_session_variables($user);
$clear = function () use ($sid) {
	DbConnector::get_instance()->get_db_link()
		->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?')->execute(array(session_id()));
};

section('Unconfirmed: the step-up flag, never a dead end');
$clear();
$r = passkey_register_options_logic(array());
check(!empty($r->data['second_factor_required']) && $r->error === null,
	'adding a passkey asks for a second-factor confirmation', json_encode(array($r->error, $r->data)));
$r = passkey_revoke_logic(array('credential_id' => (int)$lost->key));
check(!empty($r->data['second_factor_required']), 'so does revoking one', json_encode(array($r->error, $r->data)));

section('Confirmed with the authenticator app alone');
$session->stamp_second_factor();
$r = passkey_register_options_logic(array());
check(empty($r->data['second_factor_required']) && $r->error === null && !empty($r->data['options']),
	'a replacement passkey can be enrolled', json_encode(array($r->error, array_keys((array)$r->data))));
$r = passkey_revoke_logic(array('credential_id' => (int)$lost->key));
check(!empty($r->data['revoked']), 'and the lost one revoked', json_encode(array($r->error, $r->data)));

section('The vault actions behind the same confirmation');
foreach (array('vault_regenerate_codes', 'vault_passphrase_enroll', 'vault_passphrase_remove', 'vault_passkey_deactivate') as $action) {
	$src = file_get_contents(PathHelper::getIncludePath("logic/{$action}_logic.php"));
	check(strpos($src, "'second_factor_required' => true") !== false && strpos($src, 'with an existing passkey') === false,
		"{$action} asks with the step-up flag, not for an existing passkey");
}

$_SESSION = array();
harness_finish();
