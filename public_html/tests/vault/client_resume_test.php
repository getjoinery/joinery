<?php
/** @joinery-test
 * name: client_resume
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * The server's half of reopening a browser-held vault after a reload
 * (specs/client_custody_mail.md § R4a; includes/VaultClientResume.php).
 *
 *  - put / get / drop round trip, per scope and per tab: two tabs of one
 *    session keep their own halves, and dropping one leaves the other;
 *  - get answers the vault's current public key (and the pending one during a
 *    rotation), so the browser can refuse a secret a rotation retired;
 *  - refusals: a scope that is not client custody, a vault the user does not
 *    hold, a share that is not 32 bytes, a malformed tab id;
 *  - a half kept for one user is never answered to another in the same
 *    session (login-as);
 *  - at most MAX_PER_SCOPE halves per scope, oldest dropped first;
 *  - the action declares a browser session and session_write, and nothing in
 *    it or the class logs a share.
 *
 * Run: php tests/run.php test-db --filter=client_resume
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
if (!vault_ensure_session()) {
	harness_skip('no PHP session in this CLI');
	harness_finish();
}
harness_test_mode();

try {
	$box = new SealedBox();
	$pair = $box->generateKeypair();
	$pub = base64_encode(SealedBox::b64url_decode($pair['public']));
	$owner = make_user('ResumeOwner');
	$owner_id = intval($owner->key);
	$vault_id = vault_fixture_client_vault($owner_id, $pub, 'drive');
	$other = make_user('ResumeOther');
	$other_id = intval($other->key);
	vault_fixture_client_vault($other_id, $pub, 'drive');
	harness_defer(function () { VaultClientResume::drop(); });

	$share = function () { return base64_encode(random_bytes(32)); };
	$tab_a = bin2hex(random_bytes(16));
	$tab_b = bin2hex(random_bytes(16));

	// ---------------------------------------------------------------- round trip
	section('put, get, drop — per scope and per tab');

	$a = $share();
	$b = $share();
	VaultClientResume::put($owner_id, 'drive', $tab_a, $a);
	VaultClientResume::put($owner_id, 'drive', $tab_b, $b);
	$got = VaultClientResume::get($owner_id, 'drive', $tab_a);
	check($got !== null && $got['share'] === $a, 'a tab gets back the half it kept');
	check(VaultClientResume::get($owner_id, 'drive', $tab_b)['share'] === $b, 'and a second tab its own');
	check($got['public_key'] === $pub && $got['pending_public_key'] === null,
		'the answer carries the vault\'s public key, and no pending one outside a rotation');

	DbConnector::get_instance()->get_db_link()->prepare(
		'UPDATE uev_user_encryption_vaults SET uev_pending_public_key = ?, uev_pending_key_generation = 2
		 WHERE uev_user_encryption_vault_id = ?')->execute(array('pending-key-fixture', $vault_id));
	check(VaultClientResume::get($owner_id, 'drive', $tab_a)['pending_public_key'] === 'pending-key-fixture',
		'during a rotation it names the pending key too');

	VaultClientResume::drop('drive', $tab_a);
	check(VaultClientResume::get($owner_id, 'drive', $tab_a) === null, 'a dropped half is gone');
	check(VaultClientResume::get($owner_id, 'drive', $tab_b) !== null, 'and the other tab keeps its own');
	VaultClientResume::drop('drive');
	check(VaultClientResume::get($owner_id, 'drive', $tab_b) === null, 'dropping the scope forgets every tab');

	// ---------------------------------------------------------------- refusals
	section('refusals');

	$refused = function (callable $fn) {
		try { $fn(); } catch (VaultClientResumeException $e) { return $e->getMessage(); }
		return null;
	};
	check($refused(function () use ($owner_id, $tab_a, $share) {
		VaultClientResume::put($owner_id, 'user', $tab_a, $share());
	}) !== null, 'a server-custody scope is refused');
	check($refused(function () use ($owner_id, $tab_a, $share) {
		VaultClientResume::put($owner_id, 'passwords', $tab_a, $share());
	}) !== null || !VaultScopes::isRegistered('passwords'), 'a vault the user does not hold is refused');
	check($refused(function () use ($owner_id, $tab_a) {
		VaultClientResume::put($owner_id, 'drive', $tab_a, base64_encode(random_bytes(16)));
	}) !== null, 'a share that is not 32 bytes is refused');
	check($refused(function () use ($owner_id, $share) {
		VaultClientResume::put($owner_id, 'drive', 'not-a-tab', $share());
	}) !== null, 'a malformed tab id is refused');

	// ---------------------------------------------------------------- another user
	section('a half is only ever answered to the user who kept it');

	VaultClientResume::put($owner_id, 'drive', $tab_a, $share());
	check(VaultClientResume::get($other_id, 'drive', $tab_a) === null,
		'another user in the same session (login-as) gets nothing');
	check(VaultClientResume::get($owner_id, 'drive', $tab_a) === null,
		'and the mismatch forgot it, so it is not answered later either');

	// ---------------------------------------------------------------- the cap
	section('at most MAX_PER_SCOPE halves per scope');

	$tabs = array();
	for ($i = 0; $i < VaultClientResume::MAX_PER_SCOPE + 2; $i++) {
		$tabs[$i] = bin2hex(random_bytes(16));
		VaultClientResume::put($owner_id, 'drive', $tabs[$i], $share());
	}
	check(VaultClientResume::get($owner_id, 'drive', $tabs[0]) === null
		&& VaultClientResume::get($owner_id, 'drive', $tabs[1]) === null, 'the oldest halves went first');
	check(VaultClientResume::get($owner_id, 'drive', $tabs[VaultClientResume::MAX_PER_SCOPE + 1]) !== null,
		'the newest is kept');
	VaultClientResume::drop();

	// ---------------------------------------------------------------- the action
	section('the action: browser session, session writes, no logging');

	require_once(PathHelper::getIncludePath('logic/vault_client_resume_logic.php'));
	$d = vault_client_resume_logic_descriptor();
	check(!empty($d['auth']['requires_browser_session']) && !empty($d['auth']['session_write']),
		'it needs the browser session and keeps $_SESSION writable');
	$src = file_get_contents(PathHelper::getIncludePath('logic/vault_client_resume_logic.php'))
		. file_get_contents(PathHelper::getIncludePath('includes/VaultClientResume.php'));
	check(!preg_match('/error_log|RequestLogger|EventLog/', $src), 'nothing in it writes a log');

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
