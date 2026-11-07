<?php
/** @joinery-test
 * name: mailbox_oversight
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * All-access is deliberate (mail_access_hardening WP3, MailboxViewer).
 *
 *  - A level-10 account with no second factor is never all-access and says
 *    needs_second_factor; with a factor but no fresh step-up marker on the
 *    session it says needs_stepup and reads like a grant holder: its own
 *    mailboxes, no All mail, no Unmatched.
 *  - With a marker younger than mailbox_oversight_stepup_minutes it is
 *    all-access; a marker older than the window is not enough; the window is
 *    clamped to 1 to 60 minutes.
 *  - A viewer built without a session (forUser) is never all-access, whatever
 *    its level; a member is none.
 *  - The switcher payload carries the state; the confirmed test constructor is
 *    refused outside the harness (checked by reading the guard).
 *
 * Run: php tests/run.php db --filter=mailbox_oversight
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!PluginHelper::isPluginActive('mailbox')) { harness_skip('mailbox plugin inactive'); harness_finish(); }

$db = DbConnector::get_instance()->get_db_link();
$suffix = bin2hex(random_bytes(4));
$admin = make_user('Ovs' . $suffix, 10);
$aid = (int)$admin->key;
$member = make_user('OvsM' . $suffix, 0);
$sid = 'ovs-session-' . $suffix;
harness_defer(function () use ($db, $sid) {
	$db->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?')->execute(array($sid));
});

// A mailbox the admin holds, and one they do not.
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'ovs-' . $suffix . '.example');
$domain->set('ied_owner_usr_user_id', $aid);
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);
$mk_alias = function (string $local) use ($domain): int {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_is_enabled', true);
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	return (int)$a->key;
};
$held = $mk_alias('mine');
$other = $mk_alias('theirs');
$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', $held);
$grant->set('ieg_usr_user_id', $aid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

$stamp = function (int $age_seconds) use ($db, $sid): void {
	$db->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?')->execute(array($sid));
	$m = new PasskeyCeremony(NULL);
	$m->set('pks_session_id', $sid);
	$m->set('pks_kind', 'stepup');
	$m->set('pks_purpose', 'stepup_verified');
	$m->set('pks_expires_time', gmdate('Y-m-d H:i:s', time() + 3600));
	$m->save();
	$db->prepare("UPDATE pks_passkey_ceremonies SET pks_create_time = now() - make_interval(secs => ?) WHERE pks_passkey_ceremony_id = ?")
		->execute(array($age_seconds, (int)$m->key));
};

// ---------------------------------------------------------------------------
section('a level-10 account with no second factor has no all-access at all');

$v = MailboxViewer::withOversight($aid, 10, $sid);
check(!$v->isAllAccess() && $v->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_SECOND_FACTOR,
	'no factor: needs_second_factor, not all-access', $v->oversightState());
check($v->canAccess($held) && !$v->canAccess($other), 'it reads the mailbox it holds and not the other');
$stamp(10);
$v = MailboxViewer::withOversight($aid, 10, $sid);
check(!$v->isAllAccess() && $v->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_SECOND_FACTOR,
	'a step-up marker alone does not help an account with nothing to step up with');

// ---------------------------------------------------------------------------
section('with a second factor: a fresh step-up marker on the session is what confirms');

$db->prepare("UPDATE usr_users SET usr_totp_secret = 'ovs-test-secret', usr_totp_enabled_time = now() WHERE usr_user_id = ?")->execute(array($aid));
$db->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?')->execute(array($sid));
$v = MailboxViewer::withOversight($aid, 10, $sid);
check(!$v->isAllAccess() && $v->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_STEPUP,
	'no marker: needs_stepup', $v->oversightState());
check($v->accessibleAliasIds() === array($held), 'scoped like a grant holder', json_encode($v->accessibleAliasIds()));
$svc = new MailboxService($v);
$boxes = $svc->listMailboxes();
check($boxes['all_access'] === false && $boxes['oversight'] === MailboxViewer::OVERSIGHT_NEEDS_STEPUP
		&& !isset($boxes['all_mail']) && !isset($boxes['unmatched']),
	'the switcher payload says needs_stepup and offers no All mail or Unmatched', json_encode(array_keys($boxes)));

$stamp(10);
$v = MailboxViewer::withOversight($aid, 10, $sid);
check($v->isAllAccess() && $v->oversightState() === MailboxViewer::OVERSIGHT_CONFIRMED,
	'a marker ten seconds old confirms', $v->oversightState());
check($v->canAccess($other) && in_array($other, $v->accessibleAliasIds(), true), 'and the other mailbox is reachable');
$boxes = (new MailboxService($v))->listMailboxes();
check($boxes['all_access'] === true && $boxes['oversight'] === MailboxViewer::OVERSIGHT_CONFIRMED && isset($boxes['all_mail']),
	'the switcher payload says confirmed, with All mail');

$stamp(MailboxViewer::oversightMinutes() * 60 + 30);
$v = MailboxViewer::withOversight($aid, 10, $sid);
check(!$v->isAllAccess() && $v->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_STEPUP,
	'a marker older than the window is not enough');
check(MailboxViewer::withOversight($aid, 10, 'ovs-other-' . $suffix)->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_STEPUP,
	'another session has no marker to show');
check(MailboxViewer::oversightMinutes() >= 1 && MailboxViewer::oversightMinutes() <= MailboxViewer::OVERSIGHT_MAX_MINUTES,
	'the window is clamped to 1 to 60 minutes', (string)MailboxViewer::oversightMinutes());

// ---------------------------------------------------------------------------
section('a viewer built without a session is never all-access');

$stamp(10);
$v = MailboxViewer::forUser($aid, 10);
check(!$v->isAllAccess() && $v->oversightState() === MailboxViewer::OVERSIGHT_NONE,
	'forUser() at level 10 reads the mailboxes the person holds', $v->oversightState());
check($v->accessibleAliasIds() === array($held), 'which is the held one');
$m = MailboxViewer::withOversight((int)$member->key, 0, $sid);
check(!$m->isAllAccess() && $m->oversightState() === MailboxViewer::OVERSIGHT_NONE && $m->accessibleAliasIds() === array(),
	'a member is none, with no mailboxes');
check(MailboxViewer::confirmedForTest($aid, 10)->isAllAccess(), 'the harness constructor confirms for the suites that need all-access');
$src = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
check(strpos($src, "defined('JOINERY_HARNESS_LOADED')") !== false, 'and is refused outside the harness');

harness_finish();
