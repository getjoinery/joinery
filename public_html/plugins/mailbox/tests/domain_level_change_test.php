<?php
/** @joinery-test
 * name: mailbox_domain_level_change
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A mail domain's level change, end to end through the domain editor
 * (admin_mailbox_domains_logic), which runs it through ProtectionLevelChange:
 *   - a change asks for a recent second factor, for an admin who has one;
 *   - a raise is refused until the checklist passes, and nothing is written;
 *   - lowering to Standard needs the acting admin's window when they hold a
 *     vault, and none when they do not (other holders converge in theirs);
 *   - a change that goes through flips the level and lands on its receipt.
 * Each case runs in a transaction that is rolled back.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
// The session starts before harness_boot(), which may print (a stale-mail sweep)
// and so make a later session_start() impossible on the CLI.
$has_session = vault_ensure_session();
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_domains_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$session = SessionControl::get_instance();
harness_set_setting_mem('passkeys_enabled', '1');

function dlc_domain(string $level, ?int $owner_id): InboundEmailDomain {
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'harnesstest-dlc-' . bin2hex(random_bytes(4)) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_security_level', $level);
	if ($owner_id !== null) {
		$domain->set('ied_owner_usr_user_id', $owner_id);
	}
	$domain->save();
	$domain->load();
	return $domain;
}

/** Post the editor's save for $domain at $level as $admin_id. */
function dlc_save(InboundEmailDomain $domain, string $level, int $admin_id): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_api_user($admin_id);
	$was_method = $_SERVER['REQUEST_METHOD'] ?? null;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try {
		return admin_mailbox_domains_logic(array(
			'domain_type' => 'custom',
			'edit_primary_key_value' => intval($domain->key),
			'ied_domain' => $domain->get('ied_domain'),
			'ied_is_enabled' => '1',
			'ied_catch_all_mode' => 'store',
			'ied_security_level' => $level,
		));
	} finally {
		if ($was_method === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $was_method; }
		$session->clear_api_user();
	}
}

function dlc_level(InboundEmailDomain $domain): string {
	return (new InboundEmailDomain(intval($domain->key), TRUE))->security_level();
}

// ---------------------------------------------------------------------------
section('A raise is refused until the checklist passes');

$db->beginTransaction();
try {
	$admin = make_user('DlcAdmin', 10);
	$domain = dlc_domain(InboundEmailDomain::LEVEL_STANDARD, null);
	$r = dlc_save($domain, InboundEmailDomain::LEVEL_PRIVATE, intval($admin->key));
	$error = (string)($r->data['error'] ?? '');
	check($r->redirect === null && stripos($error, 'owner') !== false,
		'a domain with no owner cannot be raised; the checklist\'s own words say why', $error);
	check(dlc_level($domain) === InboundEmailDomain::LEVEL_STANDARD, 'and nothing was written');
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('A raise that passes flips the level and lands on its receipt');

$db->beginTransaction();
try {
	$admin = make_user('DlcRaiser', 10);
	vault_fixture_server_vault(intval($admin->key));
	$domain = dlc_domain(InboundEmailDomain::LEVEL_STANDARD, intval($admin->key));
	$r = dlc_save($domain, InboundEmailDomain::LEVEL_PRIVATE, intval($admin->key));
	check($r->redirect !== null && strpos($r->redirect, 'sealed_now=1') !== false,
		'the raise redirects to the sealing receipt', (string)$r->redirect . ' ' . (string)($r->data['error'] ?? ''));
	check(dlc_level($domain) === InboundEmailDomain::LEVEL_PRIVATE, 'and the domain is Private');
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('Lowering to Standard needs the acting admin\'s window only when they hold a vault');

$db->beginTransaction();
try {
	$holder = make_user('DlcHolder', 10);
	vault_fixture_server_vault(intval($holder->key));
	$domain = dlc_domain(InboundEmailDomain::LEVEL_PRIVATE, intval($holder->key));
	$r = dlc_save($domain, InboundEmailDomain::LEVEL_STANDARD, intval($holder->key));
	$error = (string)($r->data['error'] ?? '');
	check($r->redirect === null && stripos($error, 'Unlock your vault before lowering protection on this domain') !== false,
		'an admin who holds a vault, window closed, is refused', $error);
	check(dlc_level($domain) === InboundEmailDomain::LEVEL_PRIVATE, 'and nothing was written');

	$no_vault_admin = make_user('DlcNoVault', 10);
	$r = dlc_save($domain, InboundEmailDomain::LEVEL_STANDARD, intval($no_vault_admin->key));
	check($r->redirect !== null && strpos($r->redirect, 'unsealed_now=1') !== false,
		'an admin with no vault lowers without one and lands on the lowering receipt',
		(string)$r->redirect . ' ' . (string)($r->data['error'] ?? ''));
	check(dlc_level($domain) === InboundEmailDomain::LEVEL_STANDARD, 'and the domain is Standard');
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('A pulled-in mailbox: its own scope, prerequisites after its holders are set');

$db->beginTransaction();
try {
	$holder = make_user('DlcBoxHolder', 10);
	vault_fixture_server_vault(intval($holder->key));
	$imap = dlc_domain(InboundEmailDomain::LEVEL_STANDARD, intval($holder->key));
	$imap->set('ied_is_imap_source', true);
	$imap->save();
	$box = new InboundEmailAlias(NULL);
	$box->set('iea_ied_inbound_email_domain_id', intval($imap->key));
	$box->set('iea_alias', 'harnesstest_dlcbox');
	$box->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$box->set('iea_destinations', '');
	$box->set('iea_is_enabled', true);
	$box->save();
	$box = new InboundEmailAlias(intval($box->key), TRUE);
	$scope = new MailboxAliasLevel(new InboundEmailDomain(intval($imap->key), TRUE), $box, intval($holder->key));
	check($scope->levels() === array(ProtectionLevel::STANDARD, ProtectionLevel::PRIVATE_), 'a mailbox moves between Standard and Private');
	$r = ProtectionLevelChange::change($scope, ProtectionLevel::PRIVATE_, intval($holder->key), false);
	check($r['status'] === ProtectionLevelChange::REFUSED
		&& (new InboundEmailAlias(intval($box->key), TRUE))->security_level() === InboundEmailDomain::LEVEL_STANDARD,
		'with nobody holding it, the raise is refused and nothing is written', (string)$r['error']);
	InboundEmailMailboxGrant::sync_for_alias(intval($box->key), array(intval($holder->key)));
	$r = ProtectionLevelChange::change($scope, ProtectionLevel::PRIVATE_, intval($holder->key), false);
	check($r['status'] === ProtectionLevelChange::OK
		&& (new InboundEmailAlias(intval($box->key), TRUE))->security_level() === InboundEmailDomain::LEVEL_PRIVATE,
		'once its one holder (with a vault) is set, the same change raises it', (string)($r['error'] ?? ''));
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('A change asks for a recent second factor, for an admin who has one');

if (!$has_session || session_id() === '') {
	harness_skip('step-up', 'no session could be started on the CLI');
} else {
	$sid = session_id();
	$clear_markers = function () use ($sid) {
		DbConnector::get_instance()->get_db_link()
			->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	};
	$db->beginTransaction();
	try {
		$clear_markers();
		$twofa = make_user('DlcTwoFactor', 10);
		$twofa->enable_totp('JBSWY3DPEHPK3PXP');
		$twofa->save();
		vault_fixture_server_vault(intval($twofa->key));
		$domain = dlc_domain(InboundEmailDomain::LEVEL_STANDARD, intval($twofa->key));
		$r = dlc_save($domain, InboundEmailDomain::LEVEL_PRIVATE, intval($twofa->key));
		check($r->redirect !== null && strpos($r->redirect, '/verify-stepup') === 0
			&& strpos(rawurldecode($r->redirect), 'target_level=private') !== false,
			'the save sends the admin to confirm, carrying the chosen level back', (string)$r->redirect);
		check(dlc_level($domain) === InboundEmailDomain::LEVEL_STANDARD, 'and nothing was written');
		// A pulled-in mailbox's own scope asks the same, unless it is new.
		$imap2 = dlc_domain(InboundEmailDomain::LEVEL_STANDARD, intval($twofa->key));
		$box2 = new InboundEmailAlias(NULL);
		$box2->set('iea_ied_inbound_email_domain_id', intval($imap2->key));
		$box2->set('iea_alias', 'harnesstest_dlcbox2');
		$box2->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$box2->set('iea_destinations', '');
		$box2->set('iea_is_enabled', true);
		$box2->save();
		$box_scope = new MailboxAliasLevel($imap2, new InboundEmailAlias(intval($box2->key), TRUE), intval($twofa->key));
		$session->set_api_user(intval($twofa->key));
		try {
			check(ProtectionLevelChange::gate($box_scope, ProtectionLevel::PRIVATE_, true)['status'] === ProtectionLevelChange::STEPUP,
				'an existing mailbox\'s level change asks the admin to confirm');
			check(ProtectionLevelChange::gate($box_scope, ProtectionLevel::PRIVATE_, false)['status'] === ProtectionLevelChange::OK,
				'a mailbox being created does not');
		} finally {
			$session->clear_api_user();
		}
		$session->stamp_second_factor();
		$r = dlc_save($domain, InboundEmailDomain::LEVEL_PRIVATE, intval($twofa->key));
		check(dlc_level($domain) === InboundEmailDomain::LEVEL_PRIVATE, 'once confirmed, the same save raises the domain',
			(string)$r->redirect . ' ' . (string)($r->data['error'] ?? ''));
	} finally {
		$db->rollBack();
		$clear_markers();
	}
}

harness_finish();
?>
