<?php
/** @joinery-test
 * name: per_mailbox_labels
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A label belongs to one mailbox (mailbox_per_mailbox_labels.md).
 *
 *  1. Each mailbox lists only its own labels, and two mailboxes can each have a
 *     "Receipts" — two labels.
 *  2. Another mailbox's label is refused everywhere a label id arrives: adding
 *     or removing it on messages, deleting it, listing it, a filter's label —
 *     and a message never carries it, whatever path tries.
 *  3. Deleting one mailbox's label leaves the other's messages labelled.
 *  4. IMAP: two feeds with a folder of the same name get two labels; pointing an
 *     account at another mailbox binds the new mailbox's label.
 *  5. Filters: a domain-wide filter cannot apply a label; a mailbox filter's
 *     label is applied only within its mailbox.
 *  6. The migration splits a label several mailboxes used, moves each mailbox's
 *     memberships, bindings and filters to its own, and changes nothing the
 *     second time.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/thread_action_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_filters_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$session = SessionControl::get_instance();
$suffix = bin2hex(random_bytes(3));

// ── Fixtures ─────────────────────────────────────────────────────────────────
$both = make_user('PmlBoth', 1);     // granted on mailboxes A and B
$only_b = make_user('PmlOnlyB', 1);  // granted on B only
$both_uid = (int)$both->key;
$only_b_uid = (int)$only_b->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'pml-' . $suffix . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$mk_alias = function ($name, array $uids) use ($domain) {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
	$alias->set('iea_alias', $name);
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_is_enabled', true);
	$alias->prepare();
	$alias->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);
	foreach ($uids as $uid) {
		$grant = new InboundEmailMailboxGrant(NULL);
		$grant->set('ieg_iea_inbound_email_alias_id', (int)$alias->key);
		$grant->set('ieg_usr_user_id', $uid);
		$grant->save();
		harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);
	}
	return (int)$alias->key;
};
$a = $mk_alias('pml-a', array($both_uid));
$b = $mk_alias('pml-b', array($both_uid, $only_b_uid));
$c = $mk_alias('pml-c', array($both_uid));

$mk_message = function ($alias_id, $subject) use ($domain) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'sender@example.test');
	$m->set('iem_recipient', 'inbox@x');
	$m->set('iem_subject', $subject);
	$mid = '<pml-' . bin2hex(random_bytes(4)) . '@x>';
	$m->set('iem_message_id_header', $mid);
	$m->set('iem_thread_key', $mid);
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};
$labels_of = function ($service, $alias_id) {
	foreach ($service->listMailboxes()['mailboxes'] as $m) {
		if ((int)$m['alias_id'] === $alias_id) {
			return array_column((array)$m['folders'], 'name', 'id');
		}
	}
	return array();
};
$track_label = function ($id) {
	harness_register_row('ilb_inbound_email_labels', 'ilb_inbound_email_label_id', (int)$id);
};

$session->set_api_user($both_uid);
$service = new MailboxService(MailboxViewer::fromSession($session));

// ── 1 ────────────────────────────────────────────────────────────────────────
section('Each mailbox has its own labels');
$ra = $service->createFolder($a, 'Receipts');
$rb = $service->createFolder($b, 'Receipts');
$track_label($ra['id']); $track_label($rb['id']);
check($ra && $rb && (int)$ra['id'] !== (int)$rb['id'], 'Two mailboxes each have a "Receipts": two labels', json_encode(array($ra, $rb)));
$wa = $service->createFolder($a, 'Only A ' . $suffix);
$track_label($wa['id']);
check(isset($labels_of($service, $a)[$wa['id']]) && !isset($labels_of($service, $b)[$wa['id']]),
	'A mailbox lists its own label; another mailbox does not');
$session->set_api_user($only_b_uid);
$service_b = new MailboxService(MailboxViewer::fromSession($session));
$listed = $labels_of($service_b, $b);
check(!isset($listed[$wa['id']]) && !isset($listed[$ra['id']]) && isset($listed[$rb['id']]),
	'A member of B sees only B\'s labels', json_encode($listed));
$session->set_api_user($both_uid);

// ── 2 ────────────────────────────────────────────────────────────────────────
section('Another mailbox\'s label is refused');
$ma = $mk_message($a, 'in A');
$mb = $mk_message($b, 'in B');
check($service->setMembership(array($ma), (int)$rb['id'], true) === 0 && !InboundLabelMember::isMember($ma, (int)$rb['id']),
	'B\'s label is not added to A\'s message');
check($service->setMembership(array($ma, $mb), (int)$rb['id'], true) === 1 && InboundLabelMember::isMember($mb, (int)$rb['id']),
	'Given both, only B\'s message gets B\'s label');
InboundLabelMember::apply($ma, (int)$rb['id']);
check(!InboundLabelMember::isMember($ma, (int)$rb['id']), 'Even the lowest-level apply adds no foreign label');
check($service->deleteLabel($a, (int)$rb['id']) === null && InboundEmailLabel::aliasOf((int)$rb['id']) === $b,
	'Deleting B\'s label "from A" is refused, and it stays');
$listed_threads = $service->listThreads($a, array(), 1, 50, (int)$rb['id']);
check(count($listed_threads['threads']) === 0, 'Listing A by B\'s label lists nothing');
$threw = '';
try { _filter_resolve_label(array('ief_action_ilb_inbound_email_label_id' => (string)$rb['id']), $a); }
catch (InboundEmailFilterException $e) { $threw = $e->getMessage(); }
check($threw !== '', 'A filter of A cannot save B\'s label', $threw);
check(_filter_resolve_label(array('ief_action_ilb_inbound_email_label_id' => (string)$ra['id']), $a) === (int)$ra['id'],
	'It can save its own');
$threw = '';
try { _filter_resolve_label(array('ief_action_ilb_inbound_email_label_id' => (string)$ra['id']), null); }
catch (InboundEmailFilterException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'one mailbox') !== false, 'A domain-wide filter cannot apply a label, and says why', $threw);
check(_filter_label_options('domain:' . $domain->key) === array() && isset(_filter_label_options('alias:' . $a)[$ra['id']])
	&& !isset(_filter_label_options('alias:' . $a)[$rb['id']]), 'The filter form offers a mailbox only its own labels');

// ── 3 ────────────────────────────────────────────────────────────────────────
section('Deleting one mailbox\'s label leaves the other\'s');
$service->setMembership(array($ma), (int)$ra['id'], true);
$del = $service->deleteLabel($a, (int)$ra['id']);
check(is_array($del) && !InboundLabelMember::isMember($ma, (int)$ra['id']), 'A\'s "Receipts" is gone from A\'s message');
check(InboundLabelMember::isMember($mb, (int)$rb['id']) && InboundEmailLabel::aliasOf((int)$rb['id']) === $b,
	'B\'s "Receipts" and its message are untouched');

// ── 4 ────────────────────────────────────────────────────────────────────────
section('IMAP feeds bind their own mailbox\'s labels');
$mk_account = function ($alias_id, $tag) use ($suffix) {
	$acc = new InboundImapAccount(NULL);
	$acc->set('iia_label', 'Pml ' . $tag . ' ' . $suffix);
	$acc->set('iia_provider_key', 'imap_generic');
	$acc->set('iia_imap_host', 'imap.test');
	$acc->set('iia_iea_inbound_email_alias_id', $alias_id);
	$acc->set('iia_username', 'harnesstest_pml_' . $tag . '@x.example');
	$acc->set('iia_is_enabled', false);
	$acc->save();
	harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', (int)$acc->key);
	return new InboundImapAccount((int)$acc->key, TRUE);
};
$acc_a = $mk_account($a, 'a');
$acc_b = $mk_account($b, 'b');
$fa = new InboundImapFolder(InboundImapFolder::upsert(intval($acc_a->key), 'Work', null, true)->key, TRUE);
$fb = new InboundImapFolder(InboundImapFolder::upsert(intval($acc_b->key), 'Work', null, true)->key, TRUE);
$la = $fa->ensureLabel(); $lb = $fb->ensureLabel();
$track_label($la); $track_label($lb);
check($la && $lb && $la !== $lb && InboundEmailLabel::aliasOf($la) === $a && InboundEmailLabel::aliasOf($lb) === $b,
	'Two feeds\' "Work" folders are two labels, each its feed\'s mailbox\'s', json_encode(array($la, $lb)));
$acc_a->set('iia_iea_inbound_email_alias_id', $c);
$acc_a->save();
$fa = new InboundImapFolder(intval($fa->key), TRUE);
check($fa->labelId() === null, 'Pointing the account at another mailbox clears its folder\'s binding');
$lc = $fa->ensureLabel();
$track_label($lc);
check($lc && InboundEmailLabel::aliasOf($lc) === $c, 'The next binding is the new mailbox\'s label');

// ── 5 ────────────────────────────────────────────────────────────────────────
section('A filter\'s label stays in its mailbox');
$msg_a = new InboundEmailMessage($mk_message($a, 'filtered'), TRUE);
$done = InboundEmailFilter::applyActionSet($msg_a, array('label_ids' => array((int)$rb['id'])));
check(!in_array('label:' . $rb['id'], $done, true) && !InboundLabelMember::isMember((int)$msg_a->key, (int)$rb['id']),
	'A filter action naming another mailbox\'s label applies nothing', json_encode($done));
$own = $service->createFolder($a, 'Filed ' . $suffix);
$track_label($own['id']);
$done = InboundEmailFilter::applyActionSet($msg_a, array('label_ids' => array((int)$own['id'])));
check(in_array('label:' . $own['id'], $done, true) && InboundLabelMember::isMember((int)$msg_a->key, (int)$own['id']),
	'Its own mailbox\'s label is applied');

// ── 6 ────────────────────────────────────────────────────────────────────────
section('The migration gives each label its mailbox');
$migrations = require(PathHelper::getIncludePath('plugins/mailbox/migrations/migrations.php'));
$migrate = null;
foreach ($migrations as $mg) {
	if (($mg['id'] ?? '') === 'ilb_001_labels_belong_to_a_mailbox') { $migrate = $mg['up']; }
}
check($migrate !== null, 'The migration is declared');
$has_old_name = (bool)$db->query("SELECT 1 FROM information_schema.columns
	WHERE table_name = 'ilb_inbound_email_labels' AND column_name = 'ilb_name'")->fetchColumn();
if ($migrate !== null && $has_old_name) {
	// A label of the site-wide namespace, as one was before: no mailbox, its name in ilb_name.
	$old = (int)$db->query("INSERT INTO ilb_inbound_email_labels (ilb_name, ilb_create_time)
		VALUES ('Shared " . $suffix . "', now()) RETURNING ilb_inbound_email_label_id")->fetchColumn();
	$track_label($old);
	$raw_member = function ($mid, $lid) use ($db) {
		$db->prepare('INSERT INTO ilm_inbound_label_members (ilm_iem_inbound_email_message_id, ilm_ilb_inbound_email_label_id,
			ilm_present_local, ilm_present_base) VALUES (?, ?, true, true)')->execute(array($mid, $lid));
	};
	$a1 = $mk_message($a, 'm a1'); $a2 = $mk_message($a, 'm a2'); $b1 = $mk_message($b, 'm b1');
	$raw_member($a1, $old); $raw_member($a2, $old); $raw_member($b1, $old);
	$fb2 = new InboundImapFolder(InboundImapFolder::upsert(intval($acc_b->key), 'Shared ' . $suffix, null, true)->key, TRUE);
	$db->prepare('UPDATE iif_inbound_imap_folders SET iif_ilb_inbound_email_label_id = ? WHERE iif_inbound_imap_folder_id = ?')
		->execute(array($old, intval($fb2->key)));
	$mk_filter = function ($alias_id, $lid) use ($domain, $db) {
		$f = new InboundEmailFilter(NULL);
		$f->set('ief_iea_inbound_email_alias_id', $alias_id);
		$f->set('ief_ied_inbound_email_domain_id', (int)$domain->key);
		$f->set('ief_match_from', 'someone@example.test');
		$f->save();
		harness_register_row('ief_inbound_email_filters', 'ief_inbound_email_filter_id', (int)$f->key);
		$db->prepare('UPDATE ief_inbound_email_filters SET ief_action_ilb_inbound_email_label_id = ? WHERE ief_inbound_email_filter_id = ?')
			->execute(array($lid, (int)$f->key));
		return (int)$f->key;
	};
	$filter_c = $mk_filter($c, $old);
	$filter_domain = $mk_filter(null, $old);

	ob_start(); $migrate(DbConnector::get_instance()); ob_end_clean();

	$label_of_msg = function ($mid) use ($db) {
		return (int)$db->query('SELECT ilm_ilb_inbound_email_label_id FROM ilm_inbound_label_members
			WHERE ilm_iem_inbound_email_message_id = ' . (int)$mid)->fetchColumn();
	};
	check(InboundEmailLabel::aliasOf($old) === $a, 'The mailbox with the most messages keeps the label');
	check($label_of_msg($a1) === $old && $label_of_msg($a2) === $old, 'and its messages keep it');
	$b_copy = $label_of_msg($b1);
	$track_label($b_copy);
	check($b_copy !== $old && InboundEmailLabel::aliasOf($b_copy) === $b, 'Another mailbox\'s message moves to that mailbox\'s own copy');
	check((new InboundEmailLabel($b_copy, TRUE))->get('ilb_label_name') === 'Shared ' . $suffix, 'The copy has the same name');
	check((new InboundImapFolder(intval($fb2->key), TRUE))->labelId() === $b_copy, 'The feed folder binds its mailbox\'s copy');
	$c_label = (int)$db->query('SELECT ief_action_ilb_inbound_email_label_id FROM ief_inbound_email_filters
		WHERE ief_inbound_email_filter_id = ' . $filter_c)->fetchColumn();
	$track_label($c_label);
	check($c_label !== $old && InboundEmailLabel::aliasOf($c_label) === $c, 'A mailbox filter points at its mailbox\'s copy');
	check($db->query('SELECT ief_action_ilb_inbound_email_label_id FROM ief_inbound_email_filters
		WHERE ief_inbound_email_filter_id = ' . $filter_domain)->fetchColumn() === null, 'A domain-wide filter loses its label');

	$count = function () use ($db) {
		return $db->query('SELECT COUNT(*), COALESCE(SUM(ilb_iea_inbound_email_alias_id), 0) FROM ilb_inbound_email_labels
			WHERE ilb_delete_time IS NULL')->fetch(PDO::FETCH_NUM);
	};
	$before = $count();
	ob_start(); $migrate(DbConnector::get_instance()); ob_end_clean();
	check($count() === $before && $label_of_msg($b1) === $b_copy, 'A second run changes nothing');
} elseif ($migrate !== null) {
	check(true, 'No site-wide labels column on this install; the migration has nothing to split');
}

harness_finish();
