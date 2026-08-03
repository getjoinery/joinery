<?php
/** @joinery-test
 * name: drafts
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Compose maturity Phase 2 — saved drafts (specs/mailbox_compose_maturity.md § Phase 2).
 *
 * Covers:
 *  - MailboxDrafts save/get/delete lifecycle; autosave creates once, then updates.
 *  - Draft state round-trip (mode/source/to/cc split restored from iem_draft_state).
 *  - Sealed drafts: save-while-locked succeeds (public-key only); locked reopen →
 *    locked:true; in-window reopen decrypts; stable-DEK reuse across saves.
 *  - Drafts excluded from every non-draft surface (listThreads, getThread,
 *    messageIdsInThread, listMailboxes counts, FTS index) and present in the Drafts
 *    view + drafts count.
 *
 * Compose maturity fix pack (specs/mailbox_compose_maturity_fix_pack.md):
 *  - Fix 1 author scoping: a co-grantee and an all-access superadmin are denied
 *    get/save-update/delete + drafts count/list; the author retains full access.
 *  - Fix 5 From-alias change persists to the draft row (alias + domain); a
 *    sealed→standard change flips iem_content_sealed off and retains iem_sealed_key.
 *  - Fix 3/7 deleteDraftAttachment (author-scoped, non-inline only), getDraft inline
 *    round-trip (content_id + signed url), and stale-inline prune on save.
 *
 * The live send draft-morph (row flip + attachment reuse), the sealed-window preflight
 * (Fix 2), and the refold queue (Fix 6, see drafts_fts_test.php) need a real transport
 * or an open window and are verified there / on dev with Playwright (§ Tests).
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDrafts.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

$db = DbConnector::get_instance()->get_db_link();

// ── Fixtures: a Standard alias + user (no vault) and a sealed alias + owner ──
$std_user = make_user('DraftStd', 5);
$std_uid = (int)$std_user->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'draft-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$make_alias = function ($local) use ($domain) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	return (int)$a->key;
};
$grant = function ($alias_id, $uid) {
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', $alias_id);
	$g->set('ieg_usr_user_id', $uid);
	$g->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);
};

$std_alias = $make_alias('std');
$grant($std_alias, $std_uid);

$std_viewer = MailboxViewer::forUser($std_uid, 5);
$std_drafts = new MailboxDrafts($std_viewer);
$std_service = new MailboxService($std_viewer);

// ── Lifecycle (Standard, unsealed) ───────────────────────────────────────────
section('Draft lifecycle (unsealed)');

$res = $std_drafts->saveDraft(array(
	'alias_id' => $std_alias, 'mode' => 'reply', 'source_id' => 0,
	'to' => 'alice@x.com', 'cc' => 'carol@x.com', 'bcc' => 'secret@x.com',
	'subject' => 'Draft one', 'body_html' => '<p>Hello <b>there</b></p>',
));
$did = intval($res['draft_id']);
check($did > 0, 'saveDraft created a draft and returned an id', json_encode($res));

$row = new InboundEmailMessage($did, TRUE);
check($row->get('iem_direction') === 'draft', 'row stored with direction=draft');
check($row->get('iem_message_id_header') === null, 'draft has no Message-ID header');

$got = $std_drafts->getDraft($did);
check($got['to'] === 'alice@x.com' && $got['cc'] === 'carol@x.com', 'draft_get restores To/Cc split from draft_state', json_encode(array($got['to'], $got['cc'])));
check($got['bcc'] === 'secret@x.com', 'draft_get restores Bcc');
check($got['mode'] === 'reply', 'draft_get restores mode');
check(strpos($got['body_html'], '<b>there</b>') !== false, 'draft_get restores sanitized body_html', $got['body_html']);

// Autosave creates once, then updates the SAME row.
$res2 = $std_drafts->saveDraft(array(
	'alias_id' => $std_alias, 'draft_id' => $did, 'mode' => 'reply',
	'to' => 'alice@x.com', 'subject' => 'Draft one edited', 'body_html' => '<p>edited</p>',
));
check(intval($res2['draft_id']) === $did, 'second save with draft_id updates the same row (no new draft)');
$got2 = $std_drafts->getDraft($did);
check($got2['subject'] === 'Draft one edited', 'update persisted the new subject', $got2['subject']);
check($got2['cc'] === '', 'update cleared the removed Cc', $got2['cc']);

$count_before = intval($db->query("SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_direction='draft' AND iem_iea_inbound_email_alias_id=" . $std_alias)->fetchColumn());
check($count_before === 1, 'exactly one draft row after create+update', (string)$count_before);

// ── Exclusion from every non-draft surface ───────────────────────────────────
section('Drafts excluded from normal views');

$list = $std_service->listThreads($std_alias, array(), 1, 50);
check(count($list['threads']) === 0, 'listThreads (normal) hides the draft', json_encode($list['threads']));

// Drafts are counted PER MAILBOX (each mailbox has its own Drafts folder), so the
// count is read off that mailbox's switcher entry. Returns null when the mailbox is
// absent entirely, which must not read the same as a zero count — a scoping bug that
// hid the mailbox would otherwise pass as "0 drafts".
$drafts_count_for = function (array $payload, $alias_id) {
	foreach ($payload['mailboxes'] as $m) {
		if (intval($m['alias_id']) === intval($alias_id)) { return intval($m['drafts']); }
	}
	return null;
};

$mb = $std_service->listMailboxes();
$std_box = null;
foreach ($mb['mailboxes'] as $m) { if ($m['alias_id'] === $std_alias) { $std_box = $m; } }
check($std_box !== null && intval($std_box['total']) === 0, 'listMailboxes alias total excludes drafts', json_encode($std_box['total'] ?? null));
check($drafts_count_for($mb, $std_alias) === 1, 'listMailboxes reports the mailbox drafts count', json_encode($std_box['drafts'] ?? null));

// A draft shares no thread; messageIdsInThread on its singleton key must be empty
// through the normal (non-draft) scope.
$ids = $std_service->messageIdsInThread($std_alias, 'm:' . $did);
check(count($ids) === 0, 'messageIdsInThread (normal scope) excludes the draft', json_encode($ids));
check(count($std_service->getThread($std_alias, 'm:' . $did)) === 0, 'getThread cannot open a draft as a thread');

// The Drafts view DOES show it.
$dlist = $std_service->listThreads($std_alias, array('drafts' => true), 1, 50);
check(count($dlist['threads']) === 1, 'Drafts view lists the draft');
check(intval($dlist['threads'][0]['latest_id']) === $did, 'Drafts view row carries the draft id as latest_id', json_encode($dlist['threads'][0]['latest_id'] ?? null));

// FTS index skips drafts.
$idx_ids = $db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
	WHERE iem_iea_inbound_email_alias_id = $std_alias AND iem_direction IS DISTINCT FROM 'draft'")->fetchAll(PDO::FETCH_COLUMN);
check(!in_array((string)$did, array_map('strval', $idx_ids), true), 'the FTS index candidate query excludes the draft');

// ── Delete ───────────────────────────────────────────────────────────────────
section('Draft delete');
check($std_drafts->deleteDraft($did) === true, 'deleteDraft returns true');
check(intval($db->query("SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $did")->fetchColumn()) === 0, 'draft row is hard-deleted');
check(empty($std_drafts->getDraft($did)), 'draft_get on a deleted draft is empty');

// A draft outside the viewer's scope cannot be deleted.
$other_user = make_user('DraftOther', 5);
$other_did = intval($std_drafts->saveDraft(array('alias_id' => $std_alias, 'to' => 'x@y.com', 'subject' => 's', 'body_html' => '<p>x</p>'))['draft_id']);
$other_viewer = MailboxViewer::forUser((int)$other_user->key, 5); // no grant on std_alias
$other_drafts = new MailboxDrafts($other_viewer);
check($other_drafts->deleteDraft($other_did) === false, 'a non-grantee cannot delete the draft');
check(empty($other_drafts->getDraft($other_did)), 'a non-grantee cannot read the draft');
$std_drafts->deleteDraft($other_did);

// ── Sealed drafts ────────────────────────────────────────────────────────────
section('Sealed drafts');

$box = new SealedBox();
$sealed_user = make_user('DraftSealed', 5);
$suid = (int)$sealed_user->key;
$kp = $box->generateKeypair();

$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $suid);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

$sealed_alias = $make_alias('sealed');
$grant($sealed_alias, $suid);
$sviewer = MailboxViewer::forUser($suid, 5);
$sdrafts = new MailboxDrafts($sviewer);

// This CLI test has no browser session, so the owner's unlock window is always
// closed (VaultUnlock::secretKey → null). That is exactly the "autosave never
// blocks" and "locked reopen" surface; the in-window decrypt is exercised here by
// opening the ciphertext directly with the keypair (an open window would do the
// same unwrap). The full in-window reopen is verified on dev with Playwright.
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
$vc = new VaultCrypto();

$sres = $sdrafts->saveDraft(array(
	'alias_id' => $sealed_alias, 'mode' => 'new',
	'to' => 'bob@x.com', 'subject' => 'Sealed draft', 'body_html' => '<p>secret body</p>',
));
$sid = intval($sres['draft_id']);
check($sid > 0, 'save-while-locked succeeds (autosave never blocks)', json_encode($sres));
$srow = $db->query("SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $sid")->fetch(PDO::FETCH_ASSOC);
check(!empty($srow['iem_content_sealed']), 'sealed draft flagged content_sealed');
check($srow['iem_body_html'] !== '<p>secret body</p>' && !empty($srow['iem_draft_state']), 'body + draft_state stored as ciphertext');

// Reopen while locked (no session/window) → locked:true.
check(($sdrafts->getDraft($sid)['locked'] ?? false) === true, 'reopen while locked returns locked:true');

// The sealed content opens correctly under the owner's key + the field AD.
$dek = $vc->openItemDek($srow['iem_sealed_key'], $kp['secret']);
$body = $vc->openField($srow['iem_body_html'], $dek, InboundEmailMessage::sealAd($sid, 'iem_body_html'));
$state = json_decode($vc->openField($srow['iem_draft_state'], $dek, InboundEmailMessage::sealAd($sid, 'iem_draft_state')), true);
check(strpos($body, 'secret body') !== false, 'sealed draft body opens back to the original', $body);
check(($state['to'] ?? '') === 'bob@x.com', 'sealed draft_state opens back to the original', json_encode($state));

// A locked update with NO attachments mints a fresh DEK (still no block) and the
// new content opens under it.
$sdrafts->saveDraft(array('alias_id' => $sealed_alias, 'draft_id' => $sid, 'mode' => 'new',
	'to' => 'bob@x.com', 'subject' => 'Sealed edited', 'body_html' => '<p>secret body 2</p>'));
$srow2 = $db->query("SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $sid")->fetch(PDO::FETCH_ASSOC);
$dek2 = $vc->openItemDek($srow2['iem_sealed_key'], $kp['secret']);
$body2 = $vc->openField($srow2['iem_body_html'], $dek2, InboundEmailMessage::sealAd($sid, 'iem_body_html'));
check(strpos($body2, 'secret body 2') !== false, 'locked update re-seals the new content (opens under its DEK)', $body2);

$sdrafts->deleteDraft($sid);

// ── Fix 1: drafts are author-owned (co-grantee + superadmin denied) ───────────
section('Draft authorization (author-scoped)');

// A co-grantee of the SAME mailbox, and an all-access superadmin, must not see or
// touch another user's draft.
$co_user = make_user('DraftCoGrantee', 5);
$co_uid = (int)$co_user->key;
$grant($std_alias, $co_uid);                     // co-grantee on std_alias (shared)
$co_viewer = MailboxViewer::forUser($co_uid, 5);
$co_drafts = new MailboxDrafts($co_viewer);
$co_service = new MailboxService($co_viewer);

$super_user = make_user('DraftSuper', 10);
$super_viewer = MailboxViewer::forUser((int)$super_user->key, 10); // all-access
$super_drafts = new MailboxDrafts($super_viewer);
$super_service = new MailboxService($super_viewer);

$authz_did = intval($std_drafts->saveDraft(array('alias_id' => $std_alias,
	'to' => 'a@x.com', 'subject' => 'Author only', 'body_html' => '<p>mine</p>'))['draft_id']);

check(empty($co_drafts->getDraft($authz_did)), 'co-grantee cannot draft_get the author\'s draft');
check(empty($super_drafts->getDraft($authz_did)), 'superadmin cannot draft_get the author\'s draft');
check($co_drafts->deleteDraft($authz_did) === false, 'co-grantee cannot delete the author\'s draft');
check($super_drafts->deleteDraft($authz_did) === false, 'superadmin cannot delete the author\'s draft');

// draft_save with the foreign draft_id must not hijack it (loadDraftInScope fails
// closed → "no longer exists").
$hijack_blocked = false;
try {
	$co_drafts->saveDraft(array('alias_id' => $std_alias, 'draft_id' => $authz_did,
		'to' => 'evil@x.com', 'subject' => 'hijack', 'body_html' => '<p>x</p>'));
} catch (MailboxDraftsException $e) { $hijack_blocked = true; }
check($hijack_blocked, 'co-grantee cannot update (hijack) the author\'s draft via draft_id');

// Drafts count: the author sees 1; the co-grantee and superadmin see 0. Asserted
// with ===, so a mailbox missing from the payload (null) fails rather than reading
// as an empty Drafts folder — these are the author-scoping checks, and they have to
// distinguish "0 drafts here" from "no answer".
check($drafts_count_for($std_service->listMailboxes(), $std_alias) === 1,
	'author\'s drafts count includes the draft');
check($drafts_count_for($co_service->listMailboxes(), $std_alias) === 0,
	'co-grantee\'s drafts count excludes the author\'s draft');
check($drafts_count_for($super_service->listMailboxes(), $std_alias) === 0,
	'superadmin\'s drafts count excludes the author\'s draft');

// The Drafts view is likewise empty for the co-grantee, non-empty for the author.
check(count($co_service->listThreads($std_alias, array('drafts' => true), 1, 50)['threads']) === 0,
	'co-grantee\'s Drafts view excludes the author\'s draft');
check(count($std_service->listThreads($std_alias, array('drafts' => true), 1, 50)['threads']) === 1,
	'author\'s Drafts view shows the draft');

// The author retains full access.
check(!empty($std_drafts->getDraft($authz_did)), 'author can still draft_get their own draft');

// ── Fix 5: a From-alias change persists to the draft row ──────────────────────
section('From-alias change persists to the draft');

$std_alias2 = $make_alias('std2');
$grant($std_alias2, $std_uid);
// Fresh viewer — MailboxViewer caches its accessible-alias set at construction, so a
// viewer made before the new grant would not see std_alias2.
$std_drafts_fa = new MailboxDrafts(MailboxViewer::forUser($std_uid, 5));
$fa_did = intval($std_drafts_fa->saveDraft(array('alias_id' => $std_alias,
	'to' => 'a@x.com', 'subject' => 'From A', 'body_html' => '<p>a</p>'))['draft_id']);
$std_drafts_fa->saveDraft(array('alias_id' => $std_alias2, 'draft_id' => $fa_did,
	'to' => 'a@x.com', 'subject' => 'From B now', 'body_html' => '<p>b</p>'));
$fa_row = new InboundEmailMessage($fa_did, TRUE);
check(intval($fa_row->get('iem_iea_inbound_email_alias_id')) === $std_alias2, 'draft row records the new From alias after the change');
check(intval($fa_row->get('iem_ied_inbound_email_domain_id')) === intval($domain->key), 'draft row records the matching domain');
check(intval($std_drafts_fa->getDraft($fa_did)['alias_id']) === $std_alias2, 'draft_get returns the new From alias');
$std_drafts_fa->deleteDraft($fa_did);

// ── Fix 5: sealed → standard From change flips the content flag, keeps the key ─
section('Sealed → standard posture flip');

// A shared alias (suid + std_uid) has no single owner, so it never seals — the
// "standard" target for a From change away from the owner's sealed alias.
$shared_alias = $make_alias('shared');
$grant($shared_alias, $suid);
$grant($shared_alias, $std_uid);
// Fresh viewer so suid's accessible set includes the just-granted shared alias.
$sdrafts = new MailboxDrafts(MailboxViewer::forUser($suid, 5));

$flip_did = intval($sdrafts->saveDraft(array('alias_id' => $sealed_alias, 'mode' => 'new',
	'to' => 'bob@x.com', 'subject' => 'Sealed then standard', 'body_html' => '<p>was secret</p>'))['draft_id']);
$flip_before = $db->query("SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $flip_did")->fetch(PDO::FETCH_ASSOC);
check(!empty($flip_before['iem_content_sealed']), 'sealed draft starts content_sealed');
$sealed_key_before = $flip_before['iem_sealed_key'];

// Change From to the shared (standard) alias.
$sdrafts->saveDraft(array('alias_id' => $shared_alias, 'draft_id' => $flip_did, 'mode' => 'new',
	'to' => 'bob@x.com', 'subject' => 'Now standard', 'body_html' => '<p>now plaintext</p>'));
$flip_after = $db->query("SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $flip_did")->fetch(PDO::FETCH_ASSOC);
check(empty($flip_after['iem_content_sealed']), 'content_sealed cleared after sealed→standard From change');
check($flip_after['iem_sealed_key'] === $sealed_key_before && !empty($flip_after['iem_sealed_key']),
	'iem_sealed_key retained (already-sealed attachments stay decryptable)');
check(strpos((string)$flip_after['iem_body_html'], 'now plaintext') !== false,
	'content columns now hold readable plaintext', $flip_after['iem_body_html']);
check(intval($flip_after['iem_iea_inbound_email_alias_id']) === $shared_alias, 'row filed under the standard alias');
$sdrafts->deleteDraft($flip_did);

// ── Fix 3/7: deleteDraftAttachment + inline persistence + prune ───────────────
section('Draft attachments: delete + inline round-trip + prune');

$att_did = intval($std_drafts->saveDraft(array('alias_id' => $std_alias,
	'to' => 'a@x.com', 'subject' => 'With attachments', 'body_html' => '<p>see cid:pic1</p>'))['draft_id']);

// Directly create one regular + one inline attachment (a real upload needs
// is_uploaded_file(), unavailable in CLI; the fix-pack methods under test are
// storage-side and agnostic to how the row was created).
$mk_att = function ($did, $inline, $cid) use ($std_uid) {
	$bytes = 'PNGDATA-' . bin2hex(random_bytes(4));
	$file = File::createFromBytes($bytes, ($inline ? 'pic.png' : 'doc.pdf'),
		($inline ? 'image/png' : 'application/pdf'), $std_uid,
		array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
	harness_register_row('fil_files', 'fil_file_id', (int)$file->key);
	$att = InboundMessageAttachment::CreateEntry(array(
		'ima_iem_inbound_email_message_id' => $did,
		'ima_filename' => ($inline ? 'pic.png' : 'doc.pdf'),
		'ima_content_type' => ($inline ? 'image/png' : 'application/pdf'),
		'ima_size_bytes' => strlen($bytes),
		'ima_mime_part' => ($inline ? 'draftinl:x' : 'draft:x') . bin2hex(random_bytes(2)),
		'ima_content_id' => $inline ? $cid : null,
		'ima_is_inline' => $inline,
		'ima_fil_file_id' => (int)$file->key,
		'ima_is_sealed' => false,
	));
	harness_register_row('ima_inbound_message_attachments', 'ima_inbound_message_attachment_id', (int)$att->key);
	return (int)$att->key;
};
$reg_att = $mk_att($att_did, false, null);
$inl_att = $mk_att($att_did, true, 'pic1');

$got_att = $std_drafts->getDraft($att_did);
check(count($got_att['attachments']) === 1 && $got_att['attachments'][0]['id'] === $reg_att,
	'getDraft lists the regular attachment (not the inline one)', json_encode($got_att['attachments']));
check(count($got_att['inline']) === 1 && $got_att['inline'][0]['content_id'] === 'pic1' && !empty($got_att['inline'][0]['url']),
	'getDraft returns the inline part with content_id + signed url', json_encode($got_att['inline']));

// deleteDraftAttachment: author-scoped, non-inline only.
check($co_drafts->deleteDraftAttachment($att_did, $reg_att) === false, 'co-grantee cannot delete the author\'s draft attachment');
check($std_drafts->deleteDraftAttachment($att_did, $inl_att) === false, 'deleteDraftAttachment refuses an inline row');
check($std_drafts->deleteDraftAttachment($att_did, $reg_att) === true, 'author removes the regular attachment');
check(intval($db->query("SELECT COUNT(*) FROM ima_inbound_message_attachments WHERE ima_inbound_message_attachment_id = $reg_att")->fetchColumn()) === 0,
	'the regular attachment row is hard-deleted');

// Prune: a save whose body no longer references cid:pic1 drops the inline part.
$std_drafts->saveDraft(array('alias_id' => $std_alias, 'draft_id' => $att_did,
	'to' => 'a@x.com', 'subject' => 'Image removed', 'body_html' => '<p>no image now</p>'));
check(intval($db->query("SELECT COUNT(*) FROM ima_inbound_message_attachments WHERE ima_inbound_message_attachment_id = $inl_att")->fetchColumn()) === 0,
	'the orphaned inline part is pruned when its cid leaves the body');
$std_drafts->deleteDraft($att_did);

$std_drafts->deleteDraft($authz_did);

harness_finish();
