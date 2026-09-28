<?php
/** @joinery-test
 * name: fortress_compose
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * Compose on an end-to-end (Fortress) mailbox, the server's half
 * (specs/client_custody_mail.md § R6, WP4).
 *
 *  - a draft is sealed in the browser: the plaintext draft path refuses a
 *    Fortress mailbox and a Fortress draft; the first Fortress call makes a
 *    row with no content; the second stores the fields as the browser sealed
 *    them, and they open under the owner's key only;
 *  - what a sealed save refuses: a plaintext field, a column a draft does not
 *    carry, a DEK sealed to another vault, a part it cannot match;
 *  - `keep` is authoritative: a saved part the browser no longer lists goes;
 *  - draft_get hands a Fortress draft back sealed, parts by MIME part;
 *  - a reply or forward of an end-to-end message quotes what the browser
 *    opened (source_open), and refuses without it; a server-readable source is
 *    still read on the server;
 *  - a Fortress draft is sent from what the browser posts, never morphed, and
 *    a draft is refused by a mailbox of the other custody.
 *
 * The send itself (a transport, uploaded files) is walked in the browser; the
 * Sent copy's shape is pinned by fortress_ingest.
 *
 * Run: php tests/run.php test-db --filter=fortress_compose
 *
 * @version 1.1 - review of 2026-09-27: a plaintext draft on a Fortress mailbox stays one (B2, B9)
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(__DIR__ . '/lib/fortress_fixture.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

try {
	$fx = fortress_fixture('Compose');
	$owner = $fx['owner_id'];
	$alias_id = intval($fx['alias']->key);
	$viewer = MailboxViewer::forUser($owner, 0);
	$drafts = new MailboxDrafts($viewer);
	$crypto = new VaultCrypto();

	// What the owner's browser does: one DEK, sealed to the mail key, every field under it.
	$dek = random_bytes(32);
	$sealed_dek = $crypto->sealItemDekToBrowserKey($dek, $fx['pub'], 'mail');
	$seal = function (int $id, array $values) use ($crypto, $dek): array {
		$out = array();
		foreach ($values as $col => $v) {
			$out[$col] = $v === '' ? '' : $crypto->sealFieldForBrowser($v, $dek, 'mail:' . $id . ':' . $col);
		}
		return $out;
	};
	$refusal = function (callable $fn): ?string {
		try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
		return null;
	};

	// ---------------------------------------------------------------- plaintext refused
	section('the plaintext draft path refuses a Fortress mailbox');

	$msg = $refusal(function () use ($drafts, $alias_id) {
		$drafts->saveDraft(array('alias_id' => $alias_id, 'subject' => 'Plain words', 'body' => 'never stored'));
	});
	check($msg !== null && stripos($msg, 'end-to-end') !== false, 'saveDraft refuses, naming why', (string)$msg);
	$plain = DbConnector::get_instance()->get_db_link()->prepare("SELECT COUNT(*) FROM iem_inbound_email_messages
		WHERE iem_iea_inbound_email_alias_id = ? AND iem_direction = 'draft'");
	$plain->execute(array($alias_id));
	check(intval($plain->fetchColumn()) === 0, 'and no row was written');
	check(MailboxDrafts::isFortressAlias($fx['alias']), 'the mailbox reads as one whose drafts are sealed in the browser');

	// ---------------------------------------------------------------- the first call
	section('the first Fortress call makes a row with nothing in it');

	$src = fortress_ingest($fx, 'Plans for the quarter', 'quokkaberry');
	check($src > 0, 'a Fortress message arrived to reply to');
	$first = $drafts->saveFortressDraft(array('alias_id' => $alias_id, 'mode' => 'reply', 'source_id' => $src));
	$id = intval($first['draft_id'] ?? 0);
	check($id > 0 && ($first['id'] ?? 0) === $id && ($first['sealed_ad_prefix'] ?? '') === 'mail:',
		'it answers the id and the AD prefix to seal for', json_encode($first));
	$row = fortress_row($id);
	check($row['iem_direction'] === 'draft' && intval($row['iem_draft_author_user_id']) === $owner,
		'the row is the owner\'s draft');
	check((string)$row['iem_subject'] === '' && (string)$row['iem_body_plain'] === '' && $row['iem_draft_state'] === null
		&& !in_array($row['iem_content_sealed'], array(true, 't', 1, '1'), true), 'and holds no content');
	check((string)$row['iem_thread_key'] !== '' && $row['iem_thread_key'] === fortress_row($src)['iem_thread_key'],
		'it joins the conversation it replies to');
	$hollow = $drafts->getDraft($id);
	check(!empty($hollow['fortress']) && $hollow['sealed'] === null && $hollow['sealed_ad_prefix'] === 'mail:',
		'draft_get answers a Fortress shape with nothing sealed yet', json_encode($hollow));

	// ---------------------------------------------------------------- the sealed call
	section('the second call stores what the browser sealed');

	$values = array(
		'iem_sender' => $fx['address'], 'iem_recipient' => 'pat@elsewhere.example', 'iem_to' => 'pat@elsewhere.example',
		'iem_cc' => '', 'iem_bcc' => 'quiet@elsewhere.example', 'iem_subject' => 'Re: Plans for the quarter',
		'iem_body_html' => '<p>Draft wombatword reply</p>', 'iem_body_plain' => 'Draft wombatword reply',
		'iem_draft_state' => json_encode(array('mode' => 'reply', 'source_id' => $src, 'to' => 'pat@elsewhere.example', 'cc' => '')),
		'iem_snippet' => 'Draft wombatword reply', 'iem_attachment_manifest' => '',
	);
	$saved = $drafts->saveFortressDraft(array('alias_id' => $alias_id, 'draft_id' => $id, 'mode' => 'reply',
		'source_id' => $src, 'sealed_dek' => $sealed_dek, 'public_key' => $fx['pub'], 'fields' => $seal($id, $values),
		'parts' => array(), 'keep' => array()));
	check(intval($saved['draft_id']) === $id && $saved['parts'] === array(), 'the save answers the draft and its parts');
	$row = fortress_row($id);
	check(strncmp((string)$row['iem_sealed_key'], 'v1.edgeseal.mail.', 17) === 0
		&& intval($row['iem_sealed_owner_user_id']) === $owner, 'the row is sealed to the owner\'s mail key');
	$opened = fortress_open_dek($fx, (string)$row['iem_sealed_key']);
	check($opened === $dek, 'its DEK opens with the owner\'s key and is the browser\'s');
	$leaks = array();
	foreach ($values as $col => $v) {
		if ($v === '') { continue; }
		if (strncmp((string)$row[$col], 'v1.edge.', 8) !== 0) { $leaks[] = $col; continue; }
		if (fortress_open_field($fx, (string)$row[$col], $dek, 'mail:' . $id . ':' . $col) !== $v) { $leaks[] = $col . ' (wrong)'; }
	}
	check(empty($leaks), 'every field is ciphertext and opens to what was sealed', implode(', ', $leaks));
	$flat = json_encode($row);
	check(strpos($flat, 'wombatword') === false && strpos($flat, 'quiet@elsewhere') === false, 'no plaintext anywhere in the row');

	$shape = $drafts->getDraft($id);
	check(!empty($shape['fortress']) && ($shape['sealed']['sealed_dek'] ?? '') === $row['iem_sealed_key']
		&& ($shape['sealed']['iem_body_html'] ?? '') === $row['iem_body_html'] && !isset($shape['body_html']),
		'draft_get hands the sealed columns back, and nothing opened');

	$msg = $refusal(function () use ($drafts, $alias_id, $id) {
		$drafts->saveDraft(array('alias_id' => $alias_id, 'draft_id' => $id, 'subject' => 'Plain again'));
	});
	check($msg !== null && stripos($msg, 'end-to-end') !== false, 'the plaintext path refuses the sealed draft too', (string)$msg);

	// ---------------------------------------------------------------- refusals
	section('a sealed save refuses what is not the browser\'s ciphertext');

	$save_with = function (array $fields, ?string $key = null, array $parts = array()) use ($drafts, $alias_id, $id, $src, $sealed_dek, $fx) {
		return $drafts->saveFortressDraft(array('alias_id' => $alias_id, 'draft_id' => $id, 'mode' => 'reply',
			'source_id' => $src, 'sealed_dek' => $key ?? $sealed_dek, 'public_key' => $fx['pub'], 'fields' => $fields,
			'parts' => $parts, 'keep' => array()));
	};
	$full = $seal($id, $values);
	check($refusal(function () use ($save_with, $full) { $save_with(array_merge($full, array('iem_subject' => 'plain subject'))); }) !== null,
		'a plaintext field');
	check($refusal(function () use ($save_with, $full, $seal, $id) {
		$save_with(array_merge($full, $seal($id, array('iem_ai_summary' => 'x'))));
	}) !== null, 'a column a draft does not carry');
	$drive_key = $crypto->sealItemDekToBrowserKey($dek, $fx['pub'], 'drive');
	check($refusal(function () use ($save_with, $full, $drive_key) { $save_with($full, $drive_key); }) !== null,
		'a DEK sealed to another vault');
	$partial = $full;
	unset($partial['iem_body_html']);
	check($refusal(function () use ($save_with, $partial) { $save_with($partial); }) !== null,
		'a save that leaves a stored field out');
	check($refusal(function () use ($save_with, $full) {
		$save_with($full, null, array(array('mime_part' => 'draft:' . str_repeat('ab', 6), 'size' => 3)));
	}) !== null, 'a part with no upload behind it');
	check($refusal(function () use ($save_with, $full) {
		$save_with($full, null, array(array('mime_part' => '../etc', 'size' => 3)));
	}) !== null, 'a part name the browser could not have chosen');
	check(fortress_row($id)['iem_body_html'] === $row['iem_body_html'], 'and the draft is as it was after each');

	// ---------------------------------------------------------------- parts
	section('keep decides which saved parts stay');

	$persist = new ReflectionMethod('MailboxDrafts', 'persistFortressPart');
	$persist->setAccessible(true);
	$part_a = 'draft:' . bin2hex(random_bytes(6));
	$part_b = 'draftinl:' . bin2hex(random_bytes(6));
	foreach (array($part_a => false, $part_b => true) as $p => $inline) {
		$bytes = $crypto->sealFieldForBrowser('bytes of ' . $p, $dek, InboundEmailMessage::attachmentAd($id, $p));
		$persist->invoke($drafts, $id, array('mime_part' => $p, 'bytes' => $bytes, 'size' => strlen('bytes of ' . $p), 'inline' => $inline));
	}
	$shape = $drafts->getDraft($id);
	$by_part = array();
	foreach ($shape['parts'] as $p) { $by_part[$p['mime_part']] = $p; }
	check(isset($by_part[$part_a], $by_part[$part_b]) && $by_part[$part_b]['inline'] === true && $by_part[$part_a]['inline'] === false,
		'draft_get lists each part by MIME part, inline or not');
	$att = new InboundMessageAttachment($by_part[$part_a]['id'], TRUE);
	$file = new File(intval($att->get('ima_fil_file_id')), TRUE);
	check((string)$att->get('ima_filename') === '' && (string)$att->get('ima_content_type') === ''
		&& strpos((string)$file->get('fil_name'), 'part') !== false, 'a part is stored nameless, its File named by message and part');
	check(fortress_open_field($fx, (string)$file->read_bytes('original'), $dek, InboundEmailMessage::attachmentAd($id, $part_a))
		=== 'bytes of ' . $part_a, 'and its bytes open under the draft DEK');

	$drafts->saveFortressDraft(array('alias_id' => $alias_id, 'draft_id' => $id, 'mode' => 'reply', 'source_id' => $src,
		'sealed_dek' => $sealed_dek, 'public_key' => $fx['pub'], 'fields' => $seal($id, $values), 'parts' => array(),
		'keep' => array($part_b)));
	$left = array_column($drafts->getDraft($id)['parts'], 'mime_part');
	check($left === array($part_b), 'a save that keeps only the inline image drops the other part', json_encode($left));
	check(!(new File(intval($att->get('ima_fil_file_id')), TRUE))->key, 'and its File is gone');

	// ---------------------------------------------------------------- the quote
	section('a reply to an end-to-end message quotes what the browser opened');

	$sender = new MailboxSender($viewer);
	$quote = new ReflectionMethod('MailboxSender', 'sourceQuote');
	$quote->setAccessible(true);
	$build = new ReflectionMethod('MailboxSender', 'buildBody');
	$build->setAccessible(true);
	$source = new InboundEmailMessage($src, TRUE);
	$msg = $refusal(function () use ($quote, $sender, $source) { $quote->invoke($sender, $source, null); });
	check($msg !== null && stripos($msg, 'open it again') !== false, 'without source_open the reply is refused', (string)$msg);
	$q = $quote->invoke($sender, $source, json_encode(array('sender' => 'Secret <sender@elsewhere.example>',
		'subject' => 'Plans for the quarter', 'recipient' => $fx['address'], 'body_html' => '<p>The quokkaberry figures</p>',
		'body_plain' => 'The quokkaberry figures')));
	check($q['from_browser'] === true && $q['subject'] === 'Plans for the quarter' && $q['received_time'] !== '',
		'source_open supplies the quote; the time is the row\'s own');
	$body = $build->invoke($sender, MailboxSender::MODE_REPLY, '<p>My answer</p>', $q);
	check(strpos($body, '<p>My answer</p>') !== false && strpos($body, 'quokkaberry figures') !== false
		&& strpos($body, 'Secret &lt;sender@elsewhere.example&gt;') !== false, 'the reply quotes it, the sender line escaped');
	$fwd = $build->invoke($sender, MailboxSender::MODE_FORWARD, '<p>FYI</p>', $q);
	check(strpos($fwd, 'Forwarded message') !== false && strpos($fwd, 'Subject: Plans for the quarter') !== false,
		'a forward carries the forwarded header block');

	// ---------------------------------------------------------------- drafts at send
	section('a draft is sent by the custody it was saved in');

	$is_fortress = new ReflectionMethod('MailboxSender', 'isFortressDraft');
	$is_fortress->setAccessible(true);
	$draft_row = new InboundEmailMessage($id, TRUE);
	check($is_fortress->invoke($sender, $draft_row, $fx['alias']) === true, 'a sealed draft on its Fortress mailbox is sent as one');
	$standard = new InboundEmailAlias($alias_id, TRUE);
	$standard->set('iea_security_level', InboundEmailDomain::LEVEL_STANDARD);
	$msg = $refusal(function () use ($is_fortress, $sender, $draft_row, $standard) { $is_fortress->invoke($sender, $draft_row, $standard); });
	check($msg !== null && stripos($msg, 'end-to-end') !== false, 'the same draft from a Standard mailbox is refused', (string)$msg);
	// A draft saved in the clear on this mailbox (before it was Fortress) is not
	// the browser's: it may not be continued, sent or opened as one (review B2, B9).
	$plain_draft = new InboundEmailMessage(NULL);
	$plain_draft->set('iem_direction', 'draft');
	$plain_draft->set('iem_iea_inbound_email_alias_id', $alias_id);
	$plain_draft->set('iem_ied_inbound_email_domain_id', intval($fx['domain']->key));
	$plain_draft->set('iem_draft_author_user_id', $owner);
	$plain_draft->set('iem_sender', $fx['address']);
	$plain_draft->set('iem_recipient', '');
	$plain_draft->set('iem_subject', 'Older plain draft');
	$plain_draft->set('iem_body_plain', 'written before');
	$plain_draft->set('iem_body_html', '');
	$plain_draft->set('iem_draft_state', json_encode(array('mode' => 'new')));
	$plain_draft->save();
	$plain_row = new InboundEmailMessage(intval($plain_draft->key), TRUE);
	check(!MailboxDrafts::isHollowDraft($plain_row), 'a draft with words in it is not hollow');
	$msg = $refusal(function () use ($is_fortress, $sender, $plain_row, $fx) { $is_fortress->invoke($sender, $plain_row, $fx['alias']); });
	check($msg !== null && stripos($msg, 'not end-to-end') !== false, 'sending it from the Fortress mailbox is refused', (string)$msg);
	$msg = $refusal(function () use ($drafts, $alias_id, $plain_row) {
		$drafts->saveFortressDraft(array('alias_id' => $alias_id, 'draft_id' => intval($plain_row->key)));
	});
	check($msg !== null && stripos($msg, 'not end-to-end') !== false, 'the browser may not take it over', (string)$msg);
	$got = $drafts->getDraft(intval($plain_row->key));
	check(empty($got['fortress']) && ($got['subject'] ?? '') === 'Older plain draft', 'and it opens as what it is');
	$hollow_row = $drafts->saveFortressDraft(array('alias_id' => $alias_id, 'mode' => 'new'));
	check(MailboxDrafts::isHollowDraft(new InboundEmailMessage(intval($hollow_row['draft_id']), TRUE)),
		'a draft the first Fortress call made is hollow');

	$src_file = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
	check(strpos($src_file, "\$params['upload_count']") !== false, 'a send says how many files it posted, and a short count is refused');
	check(strpos($src_file, '$fortress_draft->permanent_delete()') !== false, 'a sent Fortress draft is deleted, not morphed');

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
