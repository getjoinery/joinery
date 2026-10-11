<?php
/** @joinery-test
 * name: vault_borrowed_unlock
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The borrowed unlock (specs/mail_apps_plugin.md § Core: the borrowed unlock):
 * a program signed in with an app credential never unlocks anything; it reads
 * through a window slot the person's own website unlock armed for it.
 *
 * Covers:
 *  - a website unlock arms the slot of each live credential whose purpose
 *    borrows, with the same caps, audited via=app_borrow naming the credential;
 *    a purpose that does not borrow, and a revoked credential, get nothing
 *  - inside the credential's request, isOpen() and secretKey() read its slot
 *  - touchActivity() keeps the slot alive and refuses outside a borrowing
 *    credential's request
 *  - the absolute cap ends it; Lock on the website (lockBorrowed) ends it;
 *    a credential event (lockAll) ends it; revoking the credential ends it
 *  - signing out of the website session does not end it
 *  - borrowedWindows() lists the open slot for the lock chip, with its end
 *
 * Run: php -d apc.enable_cli=1 tests/vault/vault_borrowed_unlock_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run manually: php -d apc.enable_cli=1 tests/vault/vault_borrowed_unlock_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

AppAccess::setDeclarationsForTests(array(
	'mail_apps_test' => array('mail' => array('label' => 'Mail apps', 'borrowsUnlock' => true)),
	'calendar_test'  => array('caldav' => array('label' => 'Calendar apps')),
));

$user = make_user('BorrowedUnlock');
$uid = (int)$user->key;
$kp = vault_fixture_server_vault($uid);
harness_defer(function () use ($uid) {
	VaultUnlock::leaveCredentialWindowForTests();
	VaultUnlock::lockAll($uid);
	AppAccess::setDeclarationsForTests(null);
});

$mint = function (string $purpose, string $name) use ($uid) {
	$minted = ApiKey::CreateAppPassword($uid, $purpose, $name);
	harness_register_model('ApiKey', (int)$minted['api_key']->key);
	return $minted['api_key'];
};
$laptop = $mint('mail', 'Laptop');
$phone = $mint('mail', 'Phone');
$calendar = $mint('caldav', 'Calendar');
$revoked = $mint('mail', 'Old laptop');
$revoked->soft_delete();

$audit = function () use ($uid) {
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT evl_note FROM evl_event_logs
		WHERE evl_usr_user_id = ? AND evl_event = ? ORDER BY evl_event_log_id");
	$q->execute(array($uid, VaultAudit::EVENT_OPENED));
	return $q->fetchAll(PDO::FETCH_COLUMN);
};
$as = function (?ApiKey $key) use ($uid) {
	if ($key === null) {
		VaultUnlock::leaveCredentialWindowForTests();
	} else {
		VaultUnlock::useCredentialWindow((int)$key->key, $uid);
	}
};

section('Undeclared purposes cannot be minted');
$refused = false;
try {
	ApiKey::CreateAppPassword($uid, 'nothing_declares_this', 'X');
} catch (ApiKeyException $e) {
	$refused = true;
}
check($refused, 'Minting an undeclared purpose is refused');

section('A website unlock arms the borrowing credentials');
$as(null);
vault_fixture_open_window($uid, $kp['secret'], 'user', array('idle' => null, 'absolute' => 3600), VaultAudit::VIA_PASSKEY);
check(VaultUnlock::isOpen($uid), 'The browser\'s own window is open');
$notes = $audit();
$borrowed = array_values(array_filter($notes, function ($n) { return strpos($n, 'via=app_borrow') !== false; }));
check(count($borrowed) === 2, 'Two borrowing slots were armed (laptop and phone)', count($borrowed) . ' of ' . count($notes));
check((bool)array_filter($borrowed, function ($n) use ($laptop) { return strpos($n, 'session=apk' . $laptop->key . ' ') !== false; }),
	'The audit names the credential', implode(' | ', $borrowed));
check((bool)array_filter($borrowed, function ($n) { return strpos($n, 'absolute_cap=3600s') !== false; }),
	'The slot carries the same caps');

section('Inside the credential\'s request');
$as($laptop);
check(VaultUnlock::isOpen($uid), 'The laptop\'s slot is open');
check(VaultUnlock::secretKey($uid) !== null, 'The key reads through the slot');
check(VaultUnlock::touchActivity($uid), 'A check counts as activity');
$as($calendar);
check(!VaultUnlock::isOpen($uid), 'A purpose that does not borrow has no window');
$as($revoked);
check(!VaultUnlock::isOpen($uid), 'A revoked credential got no window');

section('touchActivity() only for a borrowing credential');
$as(null);
$threw = false;
try {
	VaultUnlock::touchActivity($uid);
} catch (RuntimeException $e) {
	$threw = true;
}
check($threw, 'It refuses in a browser session');

section('The lock chip\'s line');
$list = VaultUnlock::borrowedWindows($uid);
$names = array_column($list, 'name');
sort($names);
check($names === array('Laptop', 'Phone'), 'Both open slots are listed by device name', implode(',', $names));
check($list && $list[0]['label'] === 'Mail apps' && $list[0]['ends_at'] > time(), 'With the purpose label and the cap\'s end');

section('Signing out of the website does not lock the apps');
VaultUnlock::close($uid, 'user', VaultAudit::REASON_LOGOUT);
check(!VaultUnlock::isOpen($uid), 'The browser\'s window is closed');
$as($laptop);
check(VaultUnlock::isOpen($uid), 'The laptop still reads');

section('Revoking a credential ends its window');
$as(null);
$phone->soft_delete();
$as($phone);
check(!VaultUnlock::isOpen($uid), 'The phone\'s window ended with its credential');
$as($laptop);
check(VaultUnlock::isOpen($uid), 'The laptop\'s did not');

section('Lock on the website ends every borrowed window');
$as(null);
VaultUnlock::lockBorrowed($uid);
$as($laptop);
check(!VaultUnlock::isOpen($uid), 'The laptop\'s window is closed by Lock');

section('The absolute cap ends a borrowed window');
$as(null);
vault_fixture_open_window($uid, $kp['secret'], 'user', array('idle' => null, 'absolute' => 1), VaultAudit::VIA_PASSKEY);
$as($laptop);
check(VaultUnlock::isOpen($uid), 'Open right after the unlock');
sleep(2);
check(!VaultUnlock::touchActivity($uid), 'Activity cannot keep it past the cap');
check(!VaultUnlock::isOpen($uid), 'Closed once past the cap');

section('Deactivating a credential ends its window');
$as(null);
vault_fixture_open_window($uid, $kp['secret'], 'user', array('idle' => null, 'absolute' => 3600), VaultAudit::VIA_PASSKEY);
$as($laptop);
check(VaultUnlock::isOpen($uid), 'Open after the unlock');
$as(null);
$laptop->set('apk_is_active', false);
$laptop->save();
$as($laptop);
check(!VaultUnlock::isOpen($uid), 'Closed the moment the credential is deactivated');
$as(null);
$laptop->set('apk_is_active', true);
$laptop->save();

section('No slot without an absolute cap');
VaultUnlock::lockAll($uid);
vault_fixture_open_window($uid, $kp['secret'], 'user', array('idle' => null, 'absolute' => null), VaultAudit::VIA_PASSKEY);
$as($laptop);
check(!VaultUnlock::isOpen($uid), 'An uncapped unlock arms no borrowed window: nothing would ever end it');
$as(null);

section('A credential event ends every borrowed window');
$as(null);
vault_fixture_open_window($uid, $kp['secret'], 'user', array('idle' => null, 'absolute' => 3600), VaultAudit::VIA_PASSKEY);
$as($laptop);
check(VaultUnlock::isOpen($uid), 'Re-armed by the next unlock');
VaultUnlock::lockAll($uid);
check(!VaultUnlock::isOpen($uid), 'lockAll closes it');

$as(null);
harness_finish();
