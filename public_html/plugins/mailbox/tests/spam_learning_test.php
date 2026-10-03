<?php
/** @joinery-test
 * name: spam_learning
 * tier: test-db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * Spam learning in core, against the copied test database
 * (spam_learning_in_core.md). The corpus is one deployment-wide table,
 * so this never runs against live data; counts are asserted as deltas because
 * the test database keeps what earlier runs taught.
 *
 *  - the verdict order with its facts looked up for real: a reply matches only
 *    the user's own composed Message-IDs, never a thread root someone else
 *    started; a contact is found by fingerprint; contact and correspondent lose
 *    to a single prior spam teaching;
 *  - teaching: teach, flip and unteach; one message taught twice adds once,
 *    also from two processes at once; two messages with the same tokens taught
 *    at once both land, no deadlock; ingest verdicts never teach; a forward
 *    neither teaches nor counts as a send, a reply does both; deleting from
 *    Spam teaches nothing; unteaching after a tokenizer bump skips the tokens
 *    and clamps at 0; sender counters survive the purge of their messages;
 *    IMAP-polled rows never teach;
 *  - sealed: the fingerprint is computed at ingest on a sealed mailbox; a
 *    sealed correction waits for the window, then teaches without writing
 *    plaintext; a Fortress correction is marked handled and never re-selected;
 *  - the backfill fills fingerprints and arrivals for rows stored before;
 *  - upgrade: corrected rows are re-taught; minting a key never overwrites one.
 *
 * Run: php tests/run.php test-db --filter=spam_learning
 *
 * @version 2.0 - the in-core corpus; replaces the rspamd stub test
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
$live_db = DbConnector::get_instance()->get_db_link()->query('SELECT current_database()')->fetchColumn();
harness_test_mode();

// The corpus is deployment-wide, so this suite leaves the test database's
// corpus empty again: the model suite creates its own ibt_ rows there and must
// not collide with what was taught here. Only ever while connected to a
// database other than the live one (runs before test mode closes: LIFO).
harness_defer(function () use ($live_db) {
	$link = DbConnector::get_instance()->get_db_link();
	if ((string)$link->query('SELECT current_database()')->fetchColumn() !== (string)$live_db) {
		$link->exec('DELETE FROM ibt_inbound_bayes_tokens');
	}
});

require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/LearnSpamFeedback.php'));

$db = DbConnector::get_instance()->get_db_link();
$SPAM = InboundEmailMessage::SPAM_VERDICT_SPAM;
$HAM  = InboundEmailMessage::SPAM_VERDICT_HAM;

function sl_col(int $id, string $col) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		"SELECT $col FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
	$q->execute(array($id));
	return $q->fetchColumn();
}

function sl_record(int $alias_id, string $address): array {
	return SpamSenderRecords::get($alias_id, SpamSenderRecords::fingerprint($address))
		?? array('sent' => 0, 'spam' => 0, 'ham' => 0, 'messages' => 0, 'first_seen' => null);
}

/** Corpus count for one token string. */
function sl_token(string $token): array {
	$h = SpamBayes::hashes(array($token));
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT ibt_spam_count, ibt_ham_count FROM ibt_inbound_bayes_tokens WHERE ibt_token = ?');
	$q->execute(array($h[0]));
	$r = $q->fetch(PDO::FETCH_NUM);
	return $r ? array(intval($r[0]), intval($r[1])) : array(0, 0);
}

function sl_domain(string $prefix, string $level) {
	$d = new InboundEmailDomain(NULL);
	$d->set('ied_domain', $prefix . '-' . bin2hex(random_bytes(4)) . '.example');
	$d->set('ied_is_enabled', true);
	$d->set('ied_security_level', $level);
	$d->save();
	$d->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($d->key));
	return $d;
}

function sl_alias($domain, array $grantees) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$a->set('iea_alias', 'me');
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_destinations', '');
	$a->set('iea_is_enabled', true);
	$a->save();
	$a->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($a->key));
	InboundEmailMailboxGrant::sync_for_alias($a->key, $grantees);
	harness_defer(function () use ($a) { InboundEmailMailboxGrant::sync_for_alias($a->key, array()); });
	return $a;
}

function sl_message($domain, $alias, array $cols): int {
	$msg = new InboundEmailMessage(NULL);
	$msg->set('iem_ied_inbound_email_domain_id', intval($domain->key));
	if ($alias) $msg->set('iem_iea_inbound_email_alias_id', intval($alias->key));
	$msg->set('iem_direction', 'inbound');
	$msg->set('iem_sender', 'Pitch Person <pitch@sender.example>');
	$msg->set('iem_recipient', 'me@' . $domain->get('ied_domain'));
	$msg->set('iem_message_id_header', '<sl-' . bin2hex(random_bytes(8)) . '@sender.example>');
	$msg->set('iem_subject', 'Cheap watches');
	$msg->set('iem_body_plain', 'Buy cheap watches today with free shipping.');
	$msg->set('iem_spam_verdict', InboundEmailMessage::SPAM_VERDICT_HAM);
	$msg->set('iem_spam_meta', SpamMeta::encode(array('first_contact', 'dmarc:pass'), 'untrained'));
	foreach ($cols as $k => $v) $msg->set($k, $v);
	$msg->save();
	harness_register_model('InboundEmailMessage', intval($msg->key));
	return intval($msg->key);
}

/** Run SpamLearning::teach for each id list in its own process, all starting at once. */
function sl_parallel(array $id_lists): array {
	$child = tempnam(sys_get_temp_dir(), 'sl_child_') . '.php';
	file_put_contents($child, '<?php
require ' . var_export(PathHelper::getAbsolutePath('tests/lib/harness.php'), true) . ';
harness_boot();
DbConnector::get_instance()->set_test_mode();
$start = (float)$argv[2];
while (microtime(true) < $start) { usleep(500); }
$out = array();
foreach (explode(",", $argv[1]) as $id) { $out[] = SpamLearning::teach((int)$id); }
echo "\nSL_CHILD " . implode(",", $out) . "\n";
DbConnector::get_instance()->close_test_mode();
exit(0);
');
	$start = microtime(true) + 1.5;
	$procs = array();
	foreach ($id_lists as $ids) {
		$procs[] = proc_open(array('php', $child, implode(',', $ids), (string)$start),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		$procs[count($procs) - 1] = array($procs[count($procs) - 1], $pipes);
	}
	$results = array();
	foreach ($procs as $p) {
		list($proc, $pipes) = $p;
		$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		proc_close($proc);
		$results[] = preg_match('/SL_CHILD (\S*)/', $out, $m) ? $m[1] : 'error: ' . substr(trim($out), -300);
	}
	@unlink($child);
	@unlink(substr($child, 0, -4));
	return $results;
}

$owner_id = 0;
try {
	harness_set_setting_mem('mailbox_spam_filtering_enabled', '1');
	harness_set_setting_mem('mailbox_spam_learning_enabled', '1');
	harness_set_setting_mem('mailbox_provider', 'postfix');
	MailboxSpamPolicy::reset();

	$std = sl_domain('sl-std', InboundEmailDomain::LEVEL_STANDARD);
	$box = sl_alias($std, array());
	$alias_id = intval($box->key);
	$router = new InboundEmailRouter();
	$svc = new MailboxService(MailboxViewer::forUser(0, 10));
	$pass = array('dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'pass', 'source' => 'milter');
	$none = array('signal' => 'none', 'score' => null, 'source' => null);
	$parsed = function (string $from, string $refs = '', string $irt = '') {
		return array('from' => $from, 'from_email' => $from, 'subject' => 'Hello',
			'headers' => array('references' => $refs, 'in-reply-to' => $irt));
	};
	$decide = function (array $p, array $auth = null, array $scan = null) use ($router, $box, $pass, $none) {
		return $router->spamDecision($box, $auth ?? $pass, $scan ?? $none, $p, null, false);
	};

	// -----------------------------------------------------------------------
	section('reply: only the user\'s own composed Message-IDs');
	$own_id = '<own-' . bin2hex(random_bytes(6)) . '@' . $std->get('ied_domain') . '>';
	$stranger_root = '<root-' . bin2hex(random_bytes(6)) . '@list.example>';
	MailboxSendAttempt::record(array('mst_kind' => MailboxSendAttempt::KIND_COMPOSE,
		'mst_iea_inbound_email_alias_id' => $alias_id, 'mst_message_id_header' => $own_id,
		'mst_from_address' => 'me@' . $std->get('ied_domain'), 'mst_recipients' => array(),
		'mst_transport' => 'smtp', 'mst_outcome' => MailboxSendAttempt::OUTCOME_SENT));
	harness_defer(function () use ($db, $own_id) {
		$db->prepare('DELETE FROM mst_mailbox_send_attempts WHERE mst_message_id_header = ?')->execute(array($own_id));
	});
	// The user's reply in a thread a stranger started: its row's thread key is the
	// stranger's root, its own Message-ID is ours.
	sl_message($std, $box, array('iem_direction' => 'outbound', 'iem_message_id_header' => '<sent-' . bin2hex(random_bytes(4)) . '@x>',
		'iem_thread_key' => $stranger_root, 'iem_recipient' => 'pitch@sender.example'));
	$d = $decide($parsed('stranger@nowhere.example', $own_id, $own_id), array('dkim' => 'none', 'spf' => 'none', 'dmarc' => 'none', 'source' => 'milter'));
	check($d['reason'] === 'reply' && $d['verdict'] === $HAM, 'a reply to the user\'s composed Message-ID → ham, no DMARC needed', json_encode($d));
	$d = $decide($parsed('stranger@nowhere.example', $stranger_root, $stranger_root));
	check($d['reason'] !== 'reply', 'a thread root someone else started is never proof', json_encode($d));
	$d = $decide($parsed('stranger@nowhere.example', $own_id, $own_id), array('dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'fail', 'source' => 'milter'));
	check($d['reason'] === 'auth', 'DMARC fail still beats a reply');
	check(substr((string)$d['meta'], -2) === '|o', 'and decides before the corpus is asked (the early way out)', (string)$d['meta']);

	// -----------------------------------------------------------------------
	section('contact and correspondent, and what cancels them');
	$user = make_user('SlContact');
	harness_defer(function () use ($db, $alias_id) {
		$db->prepare('DELETE FROM imc_mailbox_contacts WHERE imc_iea_inbound_email_alias_id = ?')->execute(array($alias_id));
	});
	check((new MailboxContacts())->manualAdd(intval($user->key), 'Friend <friend@pal.example>', $alias_id), 'a contact is added');
	$q = $db->prepare('SELECT imc_sender_fingerprint FROM imc_mailbox_contacts WHERE imc_iea_inbound_email_alias_id = ?');
	$q->execute(array($alias_id));
	check($q->fetchColumn() === SpamSenderRecords::fingerprint('friend@pal.example'), 'the add writes its sender fingerprint');
	$d = $decide($parsed('friend@pal.example'));
	check($d['reason'] === 'contact', 'a contact with DMARC pass → ham/contact', json_encode($d));
	check($d['fingerprint'] === SpamSenderRecords::fingerprint('friend@pal.example'), 'the decision carries the fingerprint');
	$d = $decide($parsed('friend@pal.example'), null, array('signal' => 'spam', 'score' => 16.0, 'source' => 'rspamd'));
	check($d['reason'] === 'scanner' && $d['verdict'] === $SPAM, 'SCANNER_FLOOR beats a contact');

	SpamSenderRecords::recordSent($alias_id, array('Pen.Pal@Corr.Example'));
	$d = $decide($parsed('pen.pal@corr.example'));
	check($d['reason'] === 'correspondent', 'someone the user wrote to (case-insensitive) → correspondent', json_encode($d));

	SpamSenderRecords::adjustTaught($alias_id, SpamSenderRecords::fingerprint('friend@pal.example'), $SPAM, 1);
	SpamSenderRecords::adjustTaught($alias_id, SpamSenderRecords::fingerprint('pen.pal@corr.example'), $SPAM, 1);
	check($decide($parsed('friend@pal.example'))['reason'] === 'none', 'one spam teaching cancels contact');
	check($decide($parsed('pen.pal@corr.example'))['reason'] === 'none', 'and correspondent');
	SpamSenderRecords::adjustTaught($alias_id, SpamSenderRecords::fingerprint('pen.pal@corr.example'), $SPAM, 1);
	check($decide($parsed('pen.pal@corr.example'))['reason'] === 'sender_history', 'twice, never ham → sender_history');

	// -----------------------------------------------------------------------
	section('teach, flip, unteach');
	$t0 = SpamBayes::totals();
	$tok0 = sl_token('w:watches');
	$m1 = sl_message($std, $box, array());
	check(!in_array($m1, SpamLearning::keylessIds(5000), true), 'an ingest verdict is never selected (no evidence)');
	check($svc->setSpamVerdict(array($m1), $SPAM) === 1, 'Mark as spam');
	check(sl_col($m1, 'iem_train_verdict') === $SPAM && sl_col($m1, 'iem_learned_verdict') === $SPAM,
		'taught in the request that recorded it');
	check(intval(sl_col($m1, 'iem_learned_tokenizer')) === SpamBayes::TOKENIZER_VERSION, 'stamped with the tokenizer version');
	$t1 = SpamBayes::totals();
	check($t1['spam'] === $t0['spam'] + 1 && $t1['ham'] === $t0['ham'], 'totals: +1 spam', json_encode(array($t0, $t1)));
	check(sl_token('w:watches')[0] === $tok0[0] + 1, 'its words counted as spam');
	check(sl_token('meta:first_contact')[0] >= 1, 'its stored meta tokens too');
	check(sl_record($alias_id, 'pitch@sender.example')['spam'] >= 1, 'the sender record counts the teaching');

	$rec_before = sl_record($alias_id, 'pitch@sender.example');
	check(SpamLearning::teach($m1) === SpamLearning::HANDLED, 'teaching it again changes nothing');
	$cas = new ReflectionMethod('SpamLearning', 'compareAndSet');
	check($cas->invoke(null, $m1, '', $HAM, 1) === false, 'a stale compare-and-set (another request taught it) writes nothing');
	check(SpamBayes::totals() === $t1, 'totals unchanged by both');

	$svc->setSpamVerdict(array($m1), $HAM);
	$t2 = SpamBayes::totals();
	check($t2['spam'] === $t1['spam'] - 1 && $t2['ham'] === $t1['ham'] + 1, 'Not spam flips it: -1 spam, +1 ham', json_encode($t2));
	check(sl_token('w:watches') === array($tok0[0], $tok0[1] + 1), 'its words moved from spam to ham');
	$rec = sl_record($alias_id, 'pitch@sender.example');
	check($rec['spam'] === $rec_before['spam'] - 1 && $rec['ham'] === $rec_before['ham'] + 1, 'and so did the sender record');

	// -----------------------------------------------------------------------
	section('concurrent teachings');
	$same = sl_message($std, $box, array('iem_train_verdict' => $SPAM));
	$a = sl_message($std, $box, array('iem_train_verdict' => $SPAM, 'iem_body_plain' => 'identical overlapping words alpha beta gamma'));
	$b = sl_message($std, $box, array('iem_train_verdict' => $SPAM, 'iem_body_plain' => 'identical overlapping words alpha beta gamma'));
	$ta = SpamBayes::totals();
	$alpha = sl_token('w:alpha');
	$res = sl_parallel(array(array($same, $a), array($same, $b)));
	check(count(array_filter($res, function ($r) { return strpos($r, 'error') === 0; })) === 0, 'both processes finished', json_encode($res));
	$tb = SpamBayes::totals();
	check($tb['spam'] === $ta['spam'] + 3, 'one message taught from two processes adds once; two overlapping messages both land',
		json_encode(array($ta, $tb, $res)));
	check(sl_token('w:alpha')[0] === $alpha[0] + 2, 'the shared token counted once per message');

	// -----------------------------------------------------------------------
	section('what never teaches');
	$del = sl_message($std, $box, array('iem_spam_verdict' => $SPAM));
	$svc->softDelete(array($del));
	check(sl_col($del, 'iem_train_verdict') === null, 'deleting from Spam teaches nothing');
	$imap = sl_message($std, $box, array('iem_iia_inbound_imap_account_id' => 999999));
	check(SpamLearning::recordEvidence(array($imap), $SPAM) === 0 && sl_col($imap, 'iem_train_verdict') === null,
		'an IMAP-polled row never takes evidence');
	$out = sl_message($std, $box, array('iem_direction' => 'outbound'));
	check(SpamLearning::recordEvidence(array($out), $SPAM) === 0, 'nor does the user\'s own outbound row');

	$sender = new MailboxSender(MailboxViewer::forUser(0, 10));
	$send = new ReflectionMethod('MailboxSender', 'recordForSpamFilter');
	$src = sl_message($std, $box, array());
	$fwd_to = 'fwd-' . bin2hex(random_bytes(3)) . '@else.example';
	$send->invoke($sender, $box, MailboxSender::MODE_FORWARD, array(array('email' => $fwd_to, 'name' => '')), array(), array(), new InboundEmailMessage($src, TRUE), null);
	check(sl_record($alias_id, $fwd_to)['sent'] === 0 && sl_col($src, 'iem_train_verdict') === null,
		'a forward neither counts as a send nor teaches');
	$send->invoke($sender, $box, MailboxSender::MODE_REPLY, array(array('email' => 'pitch@sender.example', 'name' => '')), array(),
		array(array('email' => 'bcc-' . $fwd_to, 'name' => '')), new InboundEmailMessage($src, TRUE), null);
	check(sl_record($alias_id, 'bcc-' . $fwd_to)['sent'] === 1, 'a reply counts a send to every recipient, Bcc included');
	$fwd_row = sl_message($std, $box, array('iem_direction' => 'outbound', 'iem_subject' => 'Fwd: something',
		'iem_to' => $fwd_to, 'iem_recipient' => $fwd_to));
	$send->invoke($sender, $box, MailboxSender::MODE_FORWARD, array(array('email' => $fwd_to, 'name' => '')), array(), array(),
		new InboundEmailMessage($src, TRUE), $fwd_row);
	check(sl_col($fwd_row, 'iem_sender_recorded') === true, 'a forward\'s Sent copy is settled at send, so the backfill never counts it');
	$old_fwd_to = 'oldfwd-' . bin2hex(random_bytes(3)) . '@else.example';
	$old_fwd = sl_message($std, $box, array('iem_direction' => 'outbound', 'iem_subject' => 'Fwd: older',
		'iem_to' => $old_fwd_to, 'iem_recipient' => $old_fwd_to));
	SpamSenderBackfill::keylessPass(5000);
	check(sl_col($old_fwd, 'iem_sender_recorded') === true && sl_record($alias_id, $old_fwd_to)['sent'] === 0,
		'a forward stored before that is recognised by its subject and counts no send');
	check(sl_col($src, 'iem_train_verdict') === $HAM && sl_col($src, 'iem_learned_verdict') === $HAM,
		'and teaches the message replied to as ham');

	// -----------------------------------------------------------------------
	section('tokenizer bump, clamping, and counters that outlive their messages');
	$old = sl_message($std, $box, array('iem_body_plain' => 'zetaword only here',
		'iem_train_verdict' => $HAM, 'iem_learned_verdict' => $SPAM, 'iem_learned_tokenizer' => 0,
		'iem_sender' => 'old@purged.example'));
	$zeta = sl_token('w:zetaword');
	check(SpamLearning::teach($old) === SpamLearning::TAUGHT, 'a row taught by an older tokenizer is re-taught');
	check(sl_token('w:zetaword') === array($zeta[0], $zeta[1] + 1), 'its tokens are not subtracted (cannot be reproduced), only added');
	$r = sl_record($alias_id, 'old@purged.example');
	check($r['spam'] === 0 && $r['ham'] === 1, 'counters clamp at 0 rather than going negative', json_encode($r));
	$m = new InboundEmailMessage($old, TRUE);
	$m->permanent_delete();
	check(sl_record($alias_id, 'old@purged.example')['ham'] === 1, 'the sender record survives the purge of its message');

	// -----------------------------------------------------------------------
	section('the backfill');
	$back = sl_message($std, $box, array('iem_sender' => 'Back Fill <backfill@old.example>'));
	check(sl_col($back, 'iem_sender_recorded') === false, 'a row stored before has no sender facts');
	SpamSenderBackfill::keylessPass(5000);
	check(sl_col($back, 'iem_sender_recorded') === true
		&& sl_col($back, 'iem_sender_fingerprint') === SpamSenderRecords::fingerprint('backfill@old.example'),
		'the keyless pass fills its fingerprint');
	check(sl_record($alias_id, 'backfill@old.example')['messages'] === 1, 'and counts its arrival once');
	SpamSenderBackfill::keylessPass(5000);
	check(sl_record($alias_id, 'backfill@old.example')['messages'] === 1, 'a second pass does not count it again');
	$polled = sl_message($std, $box, array('iem_sender' => 'polled@remote.example', 'iem_iia_inbound_imap_account_id' => 999999));
	SpamSenderBackfill::keylessPass(5000);
	check(sl_col($polled, 'iem_sender_recorded') === true && sl_record($alias_id, 'polled@remote.example')['messages'] === 0,
		'an IMAP-polled row is settled with nothing counted: outside the spam filter');

	// -----------------------------------------------------------------------
	section('sealed: fingerprint at ingest, teaching in the window');
	if (!vault_apcu_usable() || !vault_ensure_session()) {
		harness_skip('sealed section: no APCu or no CLI session here, so no unlock window can open', 'run through tests/run.php');
	} else {
		$owner = make_user('SlOwner');
		$owner_id = intval($owner->key);
		$kp = sodium_crypto_box_keypair();
		$secret = SealedBox::b64url(sodium_crypto_box_secretkey($kp));
		$vault = new UserEncryptionVault(NULL);
		$vault->set('uev_usr_user_id', $owner_id);
		$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($kp)));
		$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
		$vault->save();
		$vault->load();
		harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));
		$priv = sl_domain('sl-sealed', InboundEmailDomain::LEVEL_PRIVATE);
		$sbox = sl_alias($priv, array($owner_id));

		$raw = "From: Sealed Sender <sealed.sender@far.example>\r\nTo: me@" . $priv->get('ied_domain')
			. "\r\nSubject: Sealed pitch\r\nMessage-ID: <sl-ingest-" . bin2hex(random_bytes(6)) . "@far.example>\r\n\r\nSealed body words.\r\n";
		$stored = $router->storeMessage($raw, $router->parseEmail($raw), $sbox, $priv, 'me@' . $priv->get('ied_domain'));
		$sid = intval($stored['message']->key);
		harness_register_model('InboundEmailMessage', $sid);
		check(sl_col($sid, 'iem_content_sealed') === true, 'the arrival is sealed at rest');
		check(sl_col($sid, 'iem_sender_fingerprint') === SpamSenderRecords::fingerprint('sealed.sender@far.example'),
			'its sender fingerprint was computed at ingest, in the clear');
		check(sl_record(intval($sbox->key), 'sealed.sender@far.example')['messages'] === 1, 'and its arrival recorded');

		VaultUnlock::lockAll($owner_id);
		$ts = SpamBayes::totals();
		$svc->setSpamVerdict(array($sid), $SPAM);
		check(sl_col($sid, 'iem_train_verdict') === $SPAM && sl_col($sid, 'iem_learned_verdict') === null,
			'a sealed correction is recorded but not taught in the request');
		check(!in_array($sid, SpamLearning::keylessIds(5000), true) && in_array($sid, SpamLearning::windowIds($owner_id), true),
			'it waits for its owner\'s window');
		check(SpamLearning::teach($sid) === SpamLearning::DEFERRED, 'a shut window defers it');
		check(SpamLearning::hasWindowWork($owner_id), 'the heartbeat predicate reports it');
		vault_fixture_open_window($owner_id, $secret, UserEncryptionVault::SCOPE_USER, array('idle' => null, 'absolute' => null));
		check(SpamLearning::drainForUser($owner_id, microtime(true) + 5) >= 1 && sl_col($sid, 'iem_learned_verdict') === $SPAM,
			'an open window teaches it');
		check(SpamBayes::totals()['spam'] === $ts['spam'] + 1, 'into the corpus');
		check(sl_col($sid, 'iem_content_sealed') === true && strpos((string)sl_col($sid, 'iem_body_plain'), 'Sealed body words') === false,
			'and the row\'s content is still sealed: no plaintext written');
		VaultUnlock::lockAll($owner_id);
	}

	// -----------------------------------------------------------------------
	section('Fortress: handled, never taught');
	$fort = sl_message($std, $box, array('iem_subject' => '', 'iem_body_plain' => '', 'iem_train_verdict' => $SPAM));
	$db->prepare("UPDATE iem_inbound_email_messages SET iem_sealed_key = ?, iem_content_sealed = true
		WHERE iem_inbound_email_message_id = ?")->execute(array(InboundEmailMessage::MAIL_KEY_PREFIX . 'fixture', $fort));
	$tf = SpamBayes::totals();
	check(in_array($fort, SpamLearning::keylessIds(5000), true), 'the keyless pass takes it');
	check(SpamLearning::teach($fort) === SpamLearning::HANDLED, 'and marks it handled');
	check(SpamBayes::totals() === $tf, 'without touching the corpus');
	check(!in_array($fort, SpamLearning::keylessIds(5000), true), 'it is never selected again');

	// -----------------------------------------------------------------------
	section('upgrade: corrections are re-taught into the table');
	$legacy = sl_message($std, $box, array('iem_spam_verdict' => $SPAM, 'iem_spam_corrected_time' => gmdate('Y-m-d H:i:s'),
		'iem_learned_verdict' => $SPAM, 'iem_body_plain' => 'legacyredisword'));
	$migrations = require(PathHelper::getAbsolutePath('plugins/mailbox/migrations/migrations.php'));
	$up = null;
	foreach ($migrations as $mig) {
		if ($mig['id'] === 'iem_019_spam_reteach_into_core') { $up = $mig['up']; }
	}
	check($up !== null, 'the upgrade migration exists');
	ob_start();
	$ok = $up(DbConnector::get_instance());
	ob_end_clean();
	check($ok === true, 'it runs');
	check(sl_col($legacy, 'iem_train_verdict') === $SPAM && sl_col($legacy, 'iem_learned_verdict') === null,
		'the correction becomes evidence and its learned marker is cleared');
	$legacy_tok = sl_token('w:legacyredisword');
	$task = (new LearnSpamFeedback())->run(array());
	check(($task['status'] ?? '') === 'success', 'the cron pass runs', json_encode($task));
	check(sl_col($legacy, 'iem_learned_verdict') === $SPAM && sl_token('w:legacyredisword')[0] === $legacy_tok[0] + 1,
		'and teaches it into the corpus');

	section('keys are minted once, never replaced');
	$fp_key = MailboxSpamKeys::get(MailboxSpamKeys::FINGERPRINT);
	check(strlen($fp_key) === 32, 'a 32-byte fingerprint key exists');
	MailboxSpamKeys::reset();
	check(MailboxSpamKeys::mint(MailboxSpamKeys::FINGERPRINT) === bin2hex($fp_key), 'minting again returns the stored key');
	MailboxSpamKeys::reset();
	check(MailboxSpamKeys::get(MailboxSpamKeys::TOKEN) !== $fp_key, 'the token key is a different key');

} catch (Throwable $e) {
	check(false, 'unexpected exception: ' . get_class($e) . ': ' . $e->getMessage(), $e->getTraceAsString());
} finally {
	if ($owner_id > 0) {
		VaultUnlock::lockAll($owner_id);
	}
	MailboxSpamPolicy::reset();
	MailboxSpamKeys::reset();
}

harness_finish();
