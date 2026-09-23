<?php
/** @joinery-test
 * name: protection_addons
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Mail protection is two levels plus add-ons (specs/implemented/protection_levels_fold.md).
 *
 *  - The setter: Standard and Private are accepted; the reserved end-to-end
 *    value is refused, so nothing writes it.
 *  - The add-on accessors: each flag is in force only at Private, and inert
 *    (stored, not in force) below it.
 *  - userHasHardenedDomain(): true for each add-on on an owned domain and for a
 *    grant-holder on a hardened domain; false for plain Private and for
 *    Standard with a stored flag.
 *  - An unconverted row (still holding 'fortress' before the migration runs;
 *    the sending lock counts only where it was enforcing)
 *    behaves as Private with both add-ons, in PHP and in the SQL predicates.
 *  - Migration ied_003_private_with_addons converts and is idempotent.
 *  - The domain editor renders the cards and add-ons through
 *    ProtectionLevelPicker, and offers the add-ons only under Private.
 *  - A save at Standard ignores the add-on switches; lowering to Standard is
 *    refused while the sending lock is enforcing; an enforcing lock always
 *    has a label.
 *  - A write to an unconverted row converts it first, so a lift sticks.
 *  - The stored owner is a key-owner candidate and is never replaced by a
 *    guess; the sending lock's key and switch need a Private domain.
 *  - A cancelled sending-lock request prescribes no signing records.
 *  - Neither add-on adds a second-factor enrollment gate.
 *
 * Run: php tests/run.php db --filter=protection_addons
 *
 * @version 1.2
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxAliasConfig.php'));

/** A live domain, registered for teardown. Named so a killed run's leftovers
 *  are reclaimed by harness_cleanup_stale_fixtures(). */
function pa_domain(string $level, array $flags = array(), ?int $owner_id = null): InboundEmailDomain {
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'harnesstest-pa-' . bin2hex(random_bytes(4)) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_security_level', $level);
	foreach ($flags as $column => $value) {
		$domain->set($column, $value);
	}
	if ($owner_id !== null) {
		$domain->set('ied_owner_usr_user_id', $owner_id);
	}
	$domain->save();
	$domain->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
	return $domain;
}

/** A live store-mode mailbox on $domain, with an optional level of its own. */
function pa_alias(InboundEmailDomain $domain, string $local, ?string $level = null): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$alias->set('iea_alias', 'harnesstest_' . $local);
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	if ($level !== null) {
		$alias->set('iea_security_level', $level);
	}
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	return $alias;
}

/** Grant $alias to $user_id without going through the invariant (fixture setup). */
function pa_grant_raw(int $alias_id, int $user_id): void {
	$grant = new InboundEmailMailboxGrant(NULL);
	$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
	$grant->set('ieg_usr_user_id', $user_id);
	$grant->save();
	$grant->load();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', intval($grant->key));
}

// ---------------------------------------------------------------------------
section('The setter accepts two levels and refuses the reserved one');

$d = new InboundEmailDomain(NULL);
$d->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
check($d->get('ied_security_level') === InboundEmailDomain::LEVEL_PRIVATE, 'Private is settable');
$d->set_security_level(InboundEmailDomain::LEVEL_STANDARD);
check($d->get('ied_security_level') === InboundEmailDomain::LEVEL_STANDARD, 'Standard is settable');
$refused = false;
try {
	$d->set_security_level(InboundEmailDomain::LEVEL_FORTRESS);
} catch (InboundEmailDomainException $e) {
	$refused = true;
}
check($refused && $d->get('ied_security_level') === InboundEmailDomain::LEVEL_STANDARD,
	'the reserved end-to-end level is refused and nothing is written');
check(InboundEmailDomain::SETTABLE_LEVELS === array('standard', 'private'), 'exactly two settable levels');

$logic_src = (string)file_get_contents(PathHelper::getIncludePath(
	'plugins/mailbox/logic/admin_mailbox_domains_logic.php'));
check(strpos($logic_src, "->set_security_level(\$new_level)") !== false
		&& strpos($logic_src, "set('ied_security_level'") === false,
	'the domain editor writes the level only through the refusing setter');

// ---------------------------------------------------------------------------
section('Add-ons are in force only at Private');

$relay_on = pa_domain('private', array('ied_relay_seals_to_owner' => true));
check($relay_on->relay_seals_to_owner() && !$relay_on->send_lock_requested(), 'relay sealing alone');
check($relay_on->is_hardened(), 'relay sealing hardens the domain');
check($relay_on->addon_labels() === array('Seal at the relay'), 'and shows beside the level, by its catalog name');

$lock_asked = pa_domain('private', array('ied_send_lock_requested' => true));
check($lock_asked->send_lock_requested() && $lock_asked->send_lock_outstanding(),
	'a requested, unfinished sending lock is outstanding');
check($lock_asked->addon_labels() === array("Only send while I'm signed in (unfinished)"), 'and says it is unfinished');

$lock_done = pa_domain('private', array('ied_send_lock_requested' => true, 'ied_is_protected_identity' => true));
check(!$lock_done->send_lock_outstanding() && $lock_done->addon_labels() === array("Only send while I'm signed in"),
	'a finished sending lock is not outstanding');

$plain = pa_domain('private');
check(!$plain->is_hardened() && !$plain->send_lock_outstanding() && $plain->addon_labels() === array(),
	'plain Private carries no add-on and nothing outstanding');

$inert = pa_domain('standard', array('ied_relay_seals_to_owner' => true, 'ied_send_lock_requested' => true));
check(!$inert->relay_seals_to_owner() && !$inert->send_lock_requested() && !$inert->is_hardened(),
	'at Standard the stored flags are inert');
check($inert->stored_flag('ied_relay_seals_to_owner') && $inert->stored_flag('ied_send_lock_requested'),
	'but stay stored, so raising again restores them');

// ---------------------------------------------------------------------------
section('userHasHardenedDomain');

$u_plain = make_user('PaPlain');
pa_domain('private', array(), intval($u_plain->key));
check(InboundEmailDomain::userHasHardenedDomain(intval($u_plain->key)) === false,
	'an owner of plain Private is not hardened');
check(InboundEmailDomain::maxSecurityLevelForUser(intval($u_plain->key)) === InboundEmailDomain::LEVEL_PRIVATE,
	'but still gets the Private window');

$u_relay = make_user('PaRelay');
pa_domain('private', array('ied_relay_seals_to_owner' => true), intval($u_relay->key));
check(InboundEmailDomain::userHasHardenedDomain(intval($u_relay->key)) === true,
	'an owner of a relay-sealed domain is hardened');

$u_send = make_user('PaSend');
pa_domain('private', array('ied_send_lock_requested' => true), intval($u_send->key));
check(InboundEmailDomain::userHasHardenedDomain(intval($u_send->key)) === true,
	'an owner of a domain that asked for the sending lock is hardened');

$u_std = make_user('PaStd');
pa_domain('standard', array('ied_relay_seals_to_owner' => true), intval($u_std->key));
check(InboundEmailDomain::userHasHardenedDomain(intval($u_std->key)) === false,
	'an inert flag at Standard hardens nobody');

$u_grant = make_user('PaGrant');
$hardened_domain = pa_domain('private', array('ied_relay_seals_to_owner' => true));
$granted = pa_alias($hardened_domain, 'pa_grant');
pa_grant_raw(intval($granted->key), intval($u_grant->key));
check(InboundEmailDomain::userHasHardenedDomain(intval($u_grant->key)) === true,
	'a grant-holder on a hardened domain is hardened');

$u_grant_plain = make_user('PaGrantPlain');
$granted_plain = pa_alias(pa_domain('private'), 'pa_grant_plain');
pa_grant_raw(intval($granted_plain->key), intval($u_grant_plain->key));
check(InboundEmailDomain::userHasHardenedDomain(intval($u_grant_plain->key)) === false,
	'a grant-holder on plain Private is not');

check(InboundEmailDomain::userHasHardenedDomain(0) === false, 'no user, no answer');

// ---------------------------------------------------------------------------
section('An unconverted row behaves as Private with the add-ons it actually had');

$u_old = make_user('PaUnconverted');
$old = pa_domain('fortress', array(), intval($u_old->key));
check($old->is_unconverted(), 'the fixture holds the reserved value');
check($old->security_level() === InboundEmailDomain::LEVEL_PRIVATE && $old->seals_content(),
	'it reads as Private and seals — never as Standard');
check($old->relay_seals_to_owner() && !$old->send_lock_requested(),
	'with Seal at the relay in force, and no sending lock (it never finished one)');
check(InboundEmailDomain::userHasHardenedDomain(intval($u_old->key)) === true,
	'so its owner keeps the short window');
$old_locked = pa_domain('fortress', array('ied_is_protected_identity' => true));
check($old_locked->relay_seals_to_owner() && $old_locked->send_lock_requested()
		&& !$old_locked->send_lock_outstanding(),
	'an unconverted row whose sending lock is enforcing reads with both add-ons, the lock finished');

$old_alias = pa_alias($old, 'pa_unconverted');
check($old_alias->seals_content(), 'a mailbox inheriting it seals');
$address = strtolower($old_alias->get('iea_alias') . '@' . $old->get('ied_domain'));
check(MailboxAliasConfig::isSealedAtRest($address) === true
		&& MailboxAliasConfig::securityLevelForAddress($address) === InboundEmailDomain::LEVEL_PRIVATE,
	'the address-level resolver agrees');

$own_old = pa_alias(pa_domain('standard'), 'pa_own_unconverted', 'fortress');
check($own_old->security_level() === InboundEmailDomain::LEVEL_PRIVATE && $own_old->seals_content(),
	'a mailbox holding the reserved value itself reads as Private');
check(InboundEmailAlias::domainHasSealingMailbox(intval($own_old->get('iea_ied_inbound_email_domain_id'))),
	'and the raw SQL sealing predicate still catches it');

$db = DbConnector::get_instance()->get_db_link();
$sql_seals = $db->query("SELECT COUNT(*) FROM iea_inbound_email_aliases a
	JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id
	WHERE a.iea_inbound_email_alias_id = " . intval($old_alias->key) . "
	  AND " . mailbox_protection_seals_sql())->fetchColumn();
check((int)$sql_seals === 1, 'the ceremony\'s set-based sealing predicate catches it too');

// ---------------------------------------------------------------------------
section('Migration ied_003_private_with_addons');

$migration = null;
foreach (require(PathHelper::getIncludePath('plugins/mailbox/migrations/migrations.php')) as $m) {
	if (($m['id'] ?? '') === 'ied_003_private_with_addons') { $migration = $m; }
}
check($migration !== null, 'the migration is declared');
if ($migration !== null) {
	$db->beginTransaction();
	try {
		$enforcing = pa_domain('private', array('ied_is_protected_identity' => true));

		ob_start();
		$first = $migration['up'](DbConnector::get_instance());
		$out1 = ob_get_clean();
		$row = $db->query('SELECT ied_security_level, ied_relay_seals_to_owner, ied_send_lock_requested,
			ied_is_protected_identity FROM ied_inbound_email_domains
			WHERE ied_inbound_email_domain_id = ' . intval($old->key))->fetch(PDO::FETCH_ASSOC);
		check($first !== 'defer' && $row['ied_security_level'] === 'private'
				&& $row['ied_relay_seals_to_owner'] === true && $row['ied_send_lock_requested'] === false,
			'a stored fortress domain that never finished its sending lock becomes Private with Seal at the relay only');
		$row2 = $db->query('SELECT ied_security_level, ied_relay_seals_to_owner, ied_send_lock_requested
			FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = ' . intval($old_locked->key))->fetch(PDO::FETCH_ASSOC);
		check($row2['ied_security_level'] === 'private' && $row2['ied_relay_seals_to_owner'] === true
				&& $row2['ied_send_lock_requested'] === true,
			'one whose sending lock was enforcing keeps it, recorded as asked for');
		check($row['ied_is_protected_identity'] === false,
			'whether the sending lock finished is left as it was');
		$lock = $db->query('SELECT ied_send_lock_requested FROM ied_inbound_email_domains
			WHERE ied_inbound_email_domain_id = ' . intval($enforcing->key))->fetchColumn();
		check($lock === true, 'an enforcing domain records the sending-lock request');
		$own = $db->query('SELECT iea_security_level FROM iea_inbound_email_aliases
			WHERE iea_inbound_email_alias_id = ' . intval($own_old->key))->fetchColumn();
		check($own === 'private', 'a mailbox-level fortress becomes Private');
		check(strpos($out1, 'ied_003:') !== false && strpos($out1, 'domain(s) converted') !== false,
			'the migration reports what it changed', $out1);

		ob_start();
		$second = $migration['up'](DbConnector::get_instance());
		$out2 = ob_get_clean();
		$left = (int)$db->query("SELECT COUNT(*) FROM ied_inbound_email_domains
			WHERE LOWER(TRIM(ied_security_level)) = 'fortress'")->fetchColumn();
		check($second !== 'defer' && $left === 0
				&& strpos($out2, 'ied_003: 0 domain(s) converted') !== false
				&& strpos($out2, '; 0 enforcing domain(s)') !== false
				&& strpos($out2, '; 0 mailbox level(s)') !== false,
			'a second run changes nothing (idempotent)', $out2);
	} finally {
		$db->rollBack();
	}
}

// ---------------------------------------------------------------------------
section('The domain editor renders the levels and add-ons through ProtectionLevelPicker');

$view = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/admin/admin_mailbox_domains.php'));
check(strpos($view, "ProtectionLevelPicker::render(\$formwriter, 'ied_security_level'") !== false
		&& strpos($view, "'service' => ProtectionLevelPicker::SERVICE_MAIL") !== false,
	'the level cards come from the picker, in the mail flavour');
check(strpos($view, "radioinput('ied_security_level'") === false && strpos($view, "'descriptions' =>") === false,
	'no hand-rolled card radio or card copy is left in the editor');
check(strpos($view, "checkboxinput('ied_send_lock_requested'") === false
		&& strpos($view, "checkboxinput('ied_relay_seals_to_owner'") === false,
	'no hand-rolled add-on checkboxes');
check(strpos($view, 'Cost: new mail waits') === false && strpos($view, "Seal at the relay</strong>") === false,
	'no add-on sentence is restated in the editor');
check(strpos($view, "InboundEmailDomain::LEVEL_FORTRESS") === false, 'the level picker has no third card');

// Render it the way the editor does and read what a member sees.
function pa_render_picker(bool $relay_offered, bool $send): string {
	$fw = new FormWriterV2HTML5('pa_form');
	$relay = array('checked' => false, 'disabled' => !$relay_offered);
	if (!$relay_offered) {
		$relay['note'] = 'It needs a relay in front of this server first.';
		$relay['link'] = array('/plugins/mailbox/admin/admin_mailbox_setup?advanced=1#relay-section', 'Set up a relay');
	}
	ob_start();
	ProtectionLevelPicker::render($fw, 'ied_security_level', array(
		'service' => ProtectionLevelPicker::SERVICE_MAIL,
		'levels'  => InboundEmailDomain::SETTABLE_LEVELS,
		'value'   => InboundEmailDomain::LEVEL_PRIVATE,
		'visibility_rules' => array(
			InboundEmailDomain::LEVEL_STANDARD => array('show' => array('ied_ai_processing_consent'),
				'hide' => array('ied_ai_processing_enabled')),
			InboundEmailDomain::LEVEL_PRIVATE => array('show' => array('ied_ai_processing_enabled')),
		),
		'addons' => array(
			ProtectionLevelPicker::ADDON_RELAY_SEALS_TO_OWNER => $relay,
			ProtectionLevelPicker::ADDON_SEND_LOCK => array('checked' => $send,
				'note' => 'Switching it on takes you through publishing its DNS records on the Setup tab.'),
		),
	));
	return (string)ob_get_clean();
}
$html = pa_render_picker(false, true);
$relay_copy = ProtectionLevelPicker::addonCopy(ProtectionLevelPicker::ADDON_RELAY_SEALS_TO_OWNER);
$send_copy = ProtectionLevelPicker::addonCopy(ProtectionLevelPicker::ADDON_SEND_LOCK);
check(strpos($html, htmlspecialchars($relay_copy['protects'] . ' ' . $relay_copy['costs'])) !== false
		&& strpos($html, htmlspecialchars($send_copy['protects'] . ' ' . $send_copy['costs'])) !== false,
	'each add-on says the catalog\'s two sentences');
check(strpos($html, 'role="switch" name="ied_security_level_relay_seals_to_owner"') !== false
		&& strpos($html, 'role="switch" name="ied_security_level_send_lock"') !== false,
	'both add-ons are switches');
check(preg_match('/name="ied_security_level_relay_seals_to_owner"[^>]*disabled/', $html) === 1
		&& strpos($html, 'href="/plugins/mailbox/admin/admin_mailbox_setup?advanced=1#relay-section"') !== false,
	'with no relay, Seal at the relay is a disabled switch with a link to set one up');
check(strpos($html, 'Only you can read your stored mail.') !== false, 'the Private card carries the mail copy');
check(strpos($html, '"standard":{"hide":["ied_security_level_addons","ied_ai_processing_enabled"]') !== false
		&& strpos($html, '"private":{"show":["ied_security_level_addons","ied_ai_processing_enabled"]') !== false,
	'choosing Standard hides the add-ons and the AI read switch; Private shows them');
check(preg_match('/name="ied_security_level_relay_seals_to_owner"[^>]*disabled/', pa_render_picker(true, false)) === 0,
	'with a relay, the switch is live');

// ---------------------------------------------------------------------------
section('A save at Standard ignores the add-on switches (B2)');

require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_domains_logic.php'));
$relay_field = ProtectionLevelPicker::addonFieldName('ied_security_level', ProtectionLevelPicker::ADDON_RELAY_SEALS_TO_OWNER);
$send_field = ProtectionLevelPicker::addonFieldName('ied_security_level', ProtectionLevelPicker::ADDON_SEND_LOCK);
$db->beginTransaction();
try {
	$stored = pa_domain('private', array('ied_relay_seals_to_owner' => true));
	// Hidden switches still post what they last showed.
	$stale = array($send_field => '1');
	check(admin_mailbox_domains_addon_flags($stored, $stale, InboundEmailDomain::LEVEL_STANDARD, false)
			=== array(true, false),
		'lowering to Standard keeps the stored flags and ignores what the hidden switches posted');
	check(admin_mailbox_domains_addon_flags($stored, $stale, InboundEmailDomain::LEVEL_PRIVATE, false)
			=== array(false, true),
		'at Private the switches decide');
	check(admin_mailbox_domains_addon_flags($stored, $stale, InboundEmailDomain::LEVEL_STANDARD, true)
			=== array(true, false),
		'a provider domain keeps its stored flags');

	// Lowering while the lock is enforcing is refused, before anything is written.
	$enforcing = pa_domain('private', array('ied_send_lock_requested' => true, 'ied_is_protected_identity' => true));
	$admin = make_user('PaLowerAdmin', 10);
	$session = SessionControl::get_instance();
	$session->set_api_user(intval($admin->key));
	$was_method = $_SERVER['REQUEST_METHOD'] ?? null;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try {
		$result = admin_mailbox_domains_logic(array(
			'domain_type' => 'custom',
			'edit_primary_key_value' => intval($enforcing->key),
			'ied_domain' => $enforcing->get('ied_domain'),
			'ied_is_enabled' => '1',
			'ied_catch_all_mode' => 'store',
			'ied_security_level' => InboundEmailDomain::LEVEL_STANDARD,
		));
	} finally {
		if ($was_method === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $was_method; }
		$session->clear_api_user();
	}
	$error = (string)($result->data['error'] ?? '');
	check(strpos($error, "Only send while I'm signed in") !== false && strpos($error, 'Switch that off first') !== false,
		'lowering to Standard with the sending lock enforcing is refused, naming the switch', $error);
	$after = new InboundEmailDomain(intval($enforcing->key), TRUE);
	check($after->security_level() === InboundEmailDomain::LEVEL_PRIVATE && $after->is_protected_identity(),
		'and nothing was written');

	// An enforcing lock is never invisible, whatever the level.
	$std_enforcing = pa_domain('standard', array('ied_is_protected_identity' => true));
	check($std_enforcing->is_hardened() && $std_enforcing->addon_labels() === array("Only send while I'm signed in"),
		'an enforcing lock at Standard still hardens and still has a label');
	foreach (array($relay_on, $lock_asked, $lock_done, $plain, $inert, $std_enforcing) as $case) {
		check($case->is_hardened() === (count($case->addon_labels()) > 0),
			'hardened exactly when a label shows (' . $case->get('ied_domain') . ')');
	}
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('A write to an unconverted row converts it first (B3)');

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));
$db->beginTransaction();
try {
	$legacy = pa_domain('fortress', array('ied_is_protected_identity' => true));
	$loaded = new InboundEmailDomain(intval($legacy->key), TRUE);
	check($loaded->is_unconverted() && $loaded->send_lock_requested(), 'the stored row is unconverted, lock on');
	mailbox_protect_lift($loaded);
	$reread = new InboundEmailDomain(intval($legacy->key), TRUE);
	check(!$reread->is_unconverted() && $reread->security_level() === InboundEmailDomain::LEVEL_PRIVATE,
		'the lift stored the conversion: Private');
	check(!$reread->send_lock_requested() && !$reread->is_protected_identity(),
		'and the sending lock stays off after the lift');
	check($reread->relay_seals_to_owner(), 'the add-on the lift did not touch keeps the conversion\'s value');
	check($reread->addon_labels() === array('Seal at the relay'), 'so the chip no longer claims a sending lock');

	$legacy2 = pa_domain('fortress');
	$edit = new InboundEmailDomain(intval($legacy2->key), TRUE);
	$edit->set('ied_relay_seals_to_owner', false);
	check($edit->get('ied_security_level') === InboundEmailDomain::LEVEL_PRIVATE
			&& !$edit->stored_flag('ied_send_lock_requested') && !$edit->stored_flag('ied_relay_seals_to_owner'),
		'the caller\'s write lands after the conversion, so it wins');

	$fixture = new InboundEmailDomain(NULL);
	$fixture->set('ied_security_level', InboundEmailDomain::LEVEL_FORTRESS);
	$fixture->set('ied_domain', 'unsaved.example');
	check($fixture->is_unconverted(), 'an unsaved object is never converted behind its builder\'s back');
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('The stored owner is a candidate and is never replaced by a guess (B5)');

$db->beginTransaction();
try {
	$owner = make_user('PaOwner');
	$other = make_user('PaOtherAdmin');
	$owned = pa_domain('private', array('ied_send_lock_requested' => true), intval($owner->key));
	$candidates = mailbox_protect_candidate_owners($owned, intval($other->key));
	check(isset($candidates[intval($owner->key)]) && !isset($candidates[intval($other->key)]),
		'the stored owner is a candidate even without a mailbox; the admin acting is not added');
	check(!mailbox_protect_owner_is_unambiguous($owned, intval($other->key)),
		'another admin switching the lock on is not the obvious owner, so no key is minted for them');
	check(mailbox_protect_owner_is_unambiguous($owned, intval($owner->key)),
		'the stored owner switching it on is');
	check(mailbox_protect_state($owned, intval($other->key))['default_owner_id'] === intval($owner->key),
		'the owner picker starts on the stored owner');
	$unowned = pa_domain('private', array('ied_send_lock_requested' => true));
	check(mailbox_protect_owner_is_unambiguous($unowned, intval($other->key)),
		'a domain with no owner and no holders still belongs to whoever sets it up');
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('The sending lock\'s key and switch need a Private domain (B6)');

/** Enough of a session for mailbox_protect_handle_action(): who is acting, and the flash. */
class PaSessionStub {
	public $messages = array();
	private $uid;
	function __construct(int $uid) { $this->uid = $uid; }
	function get_user_id() { return $this->uid; }
	function save_message($m) { $this->messages[] = $m; }
}
function pa_last_message(PaSessionStub $s): string {
	$m = end($s->messages);
	return $m ? (string)$m->message : '';
}

$db->beginTransaction();
try {
	$actor = make_user('PaActor', 10);
	$stub = new PaSessionStub(intval($actor->key));
	$std = pa_domain('standard');
	mailbox_protect_handle_action(array('action' => 'protect_generate',
		'ied_inbound_email_domain_id' => intval($std->key)), $stub, '/x');
	$std_after = new InboundEmailDomain(intval($std->key), TRUE);
	check((string)$std_after->get('ied_dkim_sealed_key') === ''
			&& strpos(pa_last_message($stub), 'Set this domain to Private first') !== false,
		'protect_generate on a Standard domain is refused and makes no key');

	$std_keyed = pa_domain('standard', array('ied_dkim_sealed_key' => 'not-a-real-key', 'ied_dkim_selector' => 'mailk1'));
	mailbox_protect_handle_action(array('action' => 'protect_activate',
		'ied_inbound_email_domain_id' => intval($std_keyed->key)), $stub, '/x');
	$keyed_after = new InboundEmailDomain(intval($std_keyed->key), TRUE);
	check(!$keyed_after->is_protected_identity()
			&& strpos(pa_last_message($stub), 'Set this domain to Private first') !== false,
		'protect_activate on a Standard domain is refused');

	$priv = pa_domain('private', array('ied_send_lock_requested' => true));
	mailbox_protect_handle_action(array('action' => 'protect_activate',
		'ied_inbound_email_domain_id' => intval($priv->key)), $stub, '/x');
	check(strpos(pa_last_message($stub), 'There is no key yet') !== false,
		'on a Private domain the gate lets the action through to its own checks');
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('A cancelled sending-lock request prescribes no signing records (B12)');

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
function pa_has_dkim_record(InboundEmailDomain $d): bool {
	$name = strtolower((string)$d->get('ied_domain'));
	foreach ((new InboundEmailSetupCheck())->signingReadinessPlan($name)->getRecords() as $rec) {
		if ($rec->name === 'mailk1._domainkey.' . $name) { return true; }
	}
	return false;
}
$db->beginTransaction();
try {
	$keyed = array('ied_dkim_selector' => 'mailk1', 'ied_dkim_sealed_key' => 'sealed',
		'ied_dkim_public_dns' => 'v=DKIM1; k=rsa; p=AAAA');
	$cancelled = pa_domain('private', $keyed);
	check(!pa_has_dkim_record($cancelled), 'a stored key with no request publishes nothing');
	$asked = pa_domain('private', array_merge($keyed, array('ied_send_lock_requested' => true)));
	check(pa_has_dkim_record($asked), 'while the lock is asked for, its record is prescribed');
	$on = pa_domain('standard', array_merge($keyed, array('ied_is_protected_identity' => true)));
	check(pa_has_dkim_record($on), 'and while it is enforcing, at any level');
} finally {
	$db->rollBack();
}

// ---------------------------------------------------------------------------
section('The add-ons shorten the window and add no second-factor gate');

$enroll_gates = array();
foreach ((new ReflectionClass('SessionControl'))->getMethods() as $m) {
	if (strpos($m->getName(), 'must_enroll_') === 0) {
		$enroll_gates[] = $m->getName();
	}
}
check($enroll_gates === array('must_enroll_2fa_for_vault'),
	'SessionControl holds no mail enrollment gate: the vault gate is the only one',
	'found: ' . implode(', ', $enroll_gates));
$sc = (string)file_get_contents(PathHelper::getIncludePath('includes/SessionControl.php'));
check(strpos($sc, 'uses extra mail protection') === false,
	'no page redirect names extra mail protection');

harness_finish();
