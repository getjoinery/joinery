<?php
/** @joinery-test
 * name: boards_email
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Email-to-board and Make a card (the Boards half of the mailbox plugin's handler
 * aliases and personal addresses).
 *
 *  - a member's address for a board: shape, reuse, reset, viewers have none, the token
 *    survives a change of domain, the domain choice is limited to eligible domains;
 *  - mail to that address becomes a card made BY that member: title (Fwd: stripped, #label
 *    and @name shortcuts), body without its signature, attachments adopted without
 *    copying bytes, source, back reference, landing list and position;
 *  - everything that must NOT make a card: a message not from the member, one the sending
 *    domain does not vouch for, a member who is no longer an editor, an archived board,
 *    a duplicate delivery; the member is told once an hour when the sender is wrong;
 *  - Make a card: the picker page reads through the mailbox's access rules and refuses a
 *    sealing mailbox; one live card per message;
 *  - the card's back link says what this viewer may be told;
 *  - deleting a board revokes its addresses.
 *
 * Run: php tests/run.php test-db --filter=boards_email
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/boards_test_support.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));

$db = DbConnector::get_instance()->get_db_link();
$suffix = strtoupper(LibraryFunctions::random_string(5));
$dsuffix = strtolower($suffix) . '-' . getmypid();
harness_defer(function () use ($db, $dsuffix) {
	$db->exec("DELETE FROM ipa_inbound_personal_addresses WHERE ipa_handler = 'boards'");
	mailbox_purge_domains('bem-%-' . $dsuffix . '.example');
});

// The real handler, declared the way the registry reads it from plugin.json.
$declared = new ReflectionProperty(InboundHandlerRegistry::class, 'declared');
$declared->setValue(null, array(
	'boards' => array('class' => 'BoardsInboundHandler', 'label' => 'Boards', 'plugin' => 'boards'),
));
harness_set_setting_mem('mailbox_receive_mode', 'direct');
harness_set_setting_mem('mailbox_spam_filtering_enabled', '1');
harness_set_setting_mem('defaultemail', 'boards-test@dev.getjoinery.com');
harness_set_setting_mem('defaultemailname', 'Boards test');

$w = boards_world($suffix);
$board = $w['board'];
$owner = (int)$w['owner']->key;
$editor = (int)$w['editor']->key;
$viewer = (int)$w['viewer']->key;
$stranger = (int)$w['stranger']->key;
$editor_mail = strtolower((string)$w['editor']->get('usr_email'));
$owner_mail = strtolower((string)$w['owner']->get('usr_email'));
$todo = $w['todo'];
$doing = $w['doing'];

function bem_domain(string $kind, string $dsuffix, array $fields = array()): InboundEmailDomain {
	$d = new InboundEmailDomain(NULL);
	$d->set('ied_domain', 'bem-' . $kind . '-' . $dsuffix . '.example');
	$d->set('ied_is_enabled', true);
	$d->set('ied_reject_unmatched', true);
	foreach ($fields as $k => $v) { $d->set($k, $v); }
	$d->save();
	return $d;
}

$pass = array('source' => 'mailgun', 'dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'pass');
$none = array('source' => 'mailgun', 'dkim' => 'none', 'spf' => 'none', 'dmarc' => 'none');

/** Send one message to an address through the real router, returning the stored row id (or 0). */
function bem_send(string $to, string $from, string $subject, string $body, array $auth, array $opts = array()): int {
	global $db;
	$mid = $opts['message_id'] ?? (bin2hex(random_bytes(8)) . '@sender.example');
	$headers = "Return-Path: <" . $from . ">\r\nFrom: Sender <" . $from . ">\r\nTo: <" . $to . ">\r\n"
		. "Subject: " . $subject . "\r\nMessage-ID: <" . $mid . ">\r\nDate: " . gmdate('D, d M Y H:i:s') . " +0000\r\n";
	if (!empty($opts['attachments'])) {
		$b = 'bnd' . bin2hex(random_bytes(6));
		$raw = $headers . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"" . $b . "\"\r\n\r\n"
			. "--" . $b . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body . "\r\n";
		foreach ($opts['attachments'] as $name => $bytes) {
			$raw .= "--" . $b . "\r\nContent-Type: application/octet-stream; name=\"" . $name . "\"\r\n"
				. "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $name . "\"\r\n\r\n"
				. chunk_split(base64_encode($bytes)) . "\r\n";
		}
		$raw .= "--" . $b . "--\r\n";
	} else {
		$raw = $headers . "\r\n" . $body . "\r\n";
	}
	(new InboundEmailRouter())->processEmail($raw, $to, $auth);
	$q = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_message_id_header = ? ORDER BY 1 DESC LIMIT 1");
	$q->execute(array('<' . $mid . '>'));
	$id = $q->fetchColumn();
	if (!$id) {
		$q->execute(array($mid));
		$id = $q->fetchColumn();
	}
	return (int)$id;
}

/** Cards on the board made from email, newest first. */
function bem_cards(int $board_id): array {
	$q = DbConnector::GetPreparedStatement('SELECT bcd_card_id FROM bcd_cards WHERE bcd_bbd_board_id = ? AND bcd_source = \'email\' AND bcd_delete_time IS NULL ORDER BY bcd_card_id DESC');
	$q->execute(array($board_id));
	return array_map(function ($id) { return new BoardCard((int)$id, TRUE); }, $q->fetchAll(PDO::FETCH_COLUMN));
}

function bem_token(string $address): string {
	return preg_match('/\+([a-z2-7]{26})@/', $address, $m) ? $m[1] : '';
}

// ---------------------------------------------------------------------------
section('Nothing eligible: the panel says so');

check(BoardsEmail::available(), 'the mailbox plugin\'s pieces are here');
$eligible_before = InboundHandlerRegistry::eligibleDomains();
if (!$eligible_before) {
	$p = BoardsEmail::panel($board, $owner, 'admin');
	check($p['status'] === 'no_domain' && stripos((string)$p['reason'], 'Standard-level') !== false && $p['address'] === null,
		'with no usable mail domain the panel says what is needed and shows no address', (string)$p['reason']);
	check(BoardsEmail::addressFor($board, $editor) === null, 'and there is no address');
} else {
	check(true, 'the test database already carries an eligible domain');
}

$d1 = bem_domain('one', $dsuffix);
$d2 = bem_domain('two', $dsuffix);
$dpriv = bem_domain('priv', $dsuffix, array('ied_security_level' => 'private', 'ied_level_set_time' => gmdate('Y-m-d H:i:s')));

// ---------------------------------------------------------------------------
section('A member\'s address for a board');

$a1 = BoardsEmail::addressFor($board, $editor);
$default_domain = InboundHandlerRegistry::defaultDomain();
check($a1 !== null && preg_match('/^boards\+[a-z2-7]{26}@/', $a1) === 1, 'an editor has boards+token@domain', (string)$a1);
check(substr($a1, strrpos($a1, '@') + 1) === $default_domain->get('ied_domain'), 'on the default domain until the board chooses one');
check(BoardsEmail::addressFor($board, $editor) === $a1, 'asking again gives the same address');
check(BoardsEmail::addressFor($board, $owner) !== $a1, 'another member has another address');
check(BoardsEmail::addressFor($board, $viewer) === null, 'a viewer has none: adding a card needs editor');
check(BoardsEmail::addressFor($board, $stranger) === null, 'nor does someone with no role');
$token = bem_token($a1);
$row = InboundPersonalAddress::resolve('boards', $token);
check($row && (int)$row->get('ipa_usr_user_id') === $editor && $row->get('ipa_ref_type') === 'board'
	&& (int)$row->get('ipa_ref_id') === (int)$board->key, 'the token stands for this member and this board');

$reset = BoardsEmail::resetAddress($board, $editor);
check($reset !== null && $reset !== $a1 && bem_token($reset) !== $token, 'reset gives a new address');
check(InboundPersonalAddress::resolve('boards', $token) === null, 'and the old one is dead');
$a1 = $reset;
$token = bem_token($a1);

// ---------------------------------------------------------------------------
section('The board\'s mail domain');

$std_ids = array_map(function ($d) { return (int)$d->key; }, InboundHandlerRegistry::eligibleDomains());
check(in_array((int)$d1->key, $std_ids, true) && in_array((int)$d2->key, $std_ids, true) && !in_array((int)$dpriv->key, $std_ids, true),
	'the eligible domains are the Standard ones');
$panel = BoardsEmail::panel($board, $owner, 'admin');
check(count($panel['domains']) >= 2, 'with two or more eligible domains an admin gets the picker');
$panel_editor = BoardsEmail::panel($board, $editor, 'editor');
check(!isset($panel_editor['domains']) && !empty($panel_editor['address']), 'an editor sees their address and no admin controls');

BoardsEmail::setDomain($board, (int)$d2->key);
$moved = BoardsEmail::addressFor($board, $editor);
check(bem_token($moved) === $token && substr($moved, strrpos($moved, '@') + 1) === $d2->get('ied_domain'),
	'choosing a domain moves the address there and keeps its token');
check(InboundHandlerRegistry::aliasFor('boards', $d2) !== null, 'the domain got its Boards alias');
check(InboundHandlerRegistry::aliasFor('boards', $d1) !== null || $default_domain === null || (int)$default_domain->key !== (int)$d1->key,
	'an alias made earlier at the old domain is not removed');
$refused = false;
try { BoardsEmail::setDomain($board, (int)$dpriv->key); } catch (BoardsRefusal $e) { $refused = true; }
check($refused, 'a domain that seals its mail cannot be chosen');
$refused = false;
try { BoardsEmail::setDomain($board, 999999999); } catch (BoardsRefusal $e) { $refused = true; }
check($refused, 'nor one that does not exist');
$d2_to_off = new InboundEmailDomain((int)$d2->key, TRUE);
$db->exec("UPDATE ied_inbound_email_domains SET ied_security_level = 'private', ied_level_set_time = now() WHERE ied_inbound_email_domain_id = " . (int)$d2->key);
$off = BoardsEmail::panel($board, $editor, 'editor');
check($off['status'] === 'domain_off' && $off['address'] === null && stripos((string)$off['reason'], 'off for this board') !== false,
	'a chosen domain raised to Private turns email off for the board, and the panel says why', (string)$off['reason']);
$db->exec("UPDATE ied_inbound_email_domains SET ied_security_level = 'standard' WHERE ied_inbound_email_domain_id = " . (int)$d2->key);
BoardsEmail::setDomain($board, null);
$back = BoardsEmail::addressFor($board, $editor);
check($back !== null && bem_token($back) === $token, 'back to the default, with the same token');
$domain_name = substr($back, strrpos($back, '@') + 1);

// ---------------------------------------------------------------------------
section('Mail to a member\'s address becomes a card made by that member');

$label_urgent = BoardsManager::saveLabel($board, null, 'Urgent', 'red');
$label_review = BoardsManager::saveLabel($board, null, 'Needs review', 'blue');
$first = $w['editor']->get('usr_first_name');
// Every harness user shares a first name, so @name would (rightly) be ambiguous; the
// owner gets a nickname of their own to be addressed by.
$owner_nick = 'boss' . strtolower($suffix);
$db->prepare("UPDATE usr_users SET usr_nickname = ? WHERE usr_user_id = ?")->execute(array($owner_nick, $owner));
$ambiguous = BoardsEmail::parseSubject($board, 'Ask @' . $first . ' about it');
check($ambiguous['title'] === 'Ask @' . $first . ' about it' && !$ambiguous['member_ids'],
	'an @name that matches more than one board member stays in the title');
$body = "Please look at the quote.\n\nThanks\n-- \nJane Sender\nCompany Ltd\n";
$mid = bem_send($back, $editor_mail, 'Fwd: Re: Buy paper #urgent #needs-review #nosuchlabel', $body, $pass);
check($mid > 0, 'the message is stored');
$cards = bem_cards((int)$board->key);
check(count($cards) === 1, 'and a card was made');
$card = $cards[0];
check($card->get('bcd_title') === 'Buy paper #nosuchlabel', 'the title has Fwd:/Re: stripped and matched shortcuts removed; an unknown one stays', (string)$card->get('bcd_title'));
check((int)$card->get('bcd_usr_user_id_creator') === $editor && $card->get('bcd_source') === 'email'
	&& (int)$card->get('bcd_iem_message_id') === $mid, 'made by the member, from email, pointing at the stored message');
check(trim((string)$card->get('bcd_description')) === "Please look at the quote.\n\nThanks", 'the description is the body without its signature', (string)$card->get('bcd_description'));
$label_ids = array_map('intval', DbConnector::GetPreparedStatement('SELECT 1')->fetchAll() ?: array());
$q = DbConnector::GetPreparedStatement('SELECT bcl_blb_label_id FROM bcl_card_labels WHERE bcl_bcd_card_id = ? ORDER BY 1');
$q->execute(array((int)$card->key));
$on_card = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
check($on_card === array((int)$label_urgent->key, (int)$label_review->key), '#urgent and #needs-review applied their labels');
check((int)$card->get('bcd_bls_list_id') === (int)$todo->key, 'it landed in the first list');

// @name shortcut and the landing list/position
BoardsEmail::setLanding($board, (int)$doing->key, 'top');
$mid2 = bem_send($back, $editor_mail, 'Call the printer @' . $owner_nick, 'ring them', $pass);
$card2 = bem_cards((int)$board->key)[0];
check($card2->get('bcd_title') === 'Call the printer', 'an @name that matches exactly one board member is removed from the title', (string)$card2->get('bcd_title'));
$q = DbConnector::GetPreparedStatement('SELECT bcm_usr_user_id FROM bcm_card_members WHERE bcm_bcd_card_id = ?');
$q->execute(array((int)$card2->key));
check(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)) === array($owner), 'and that member is on the card');
check((int)$card2->get('bcd_bls_list_id') === (int)$doing->key, 'the admin\'s landing list is used');
$mid3 = bem_send($back, $editor_mail, 'Second', 'later', $pass);
$card3 = bem_cards((int)$board->key)[0];
check((float)$card3->get('bcd_position') < (float)$card2->get('bcd_position'), 'and "top" puts the new card above the existing ones');
BoardsEmail::setLanding($board, null, 'bottom');
$bad_list = false;
try { BoardsEmail::setLanding($board, 999999999, 'top'); } catch (BoardsRefusal $e) { $bad_list = true; }
check($bad_list, 'a landing list that is not on the board is refused');

section('The same message twice is one card');

$dup_id = bin2hex(random_bytes(6)) . '@sender.example';
$count_before = count(bem_cards((int)$board->key));
bem_send($back, $editor_mail, 'Delivered twice', 'once', $pass, array('message_id' => $dup_id));
bem_send($back, $editor_mail, 'Delivered twice', 'once', $pass, array('message_id' => $dup_id));
check(count(bem_cards((int)$board->key)) === $count_before + 1, 'redelivery makes no second card');
$dup_msg = new InboundEmailMessage(bem_send($back, $editor_mail, 'Another', 'x', $pass), TRUE);
$twice = false;
try { BoardsEmail::createFromMessage($board, $todo, $editor, $dup_msg, array()); } catch (BoardsRefusal $e) { $twice = stripos($e->getMessage(), 'already has a card') !== false; }
check($twice, 'and making a card from a message that has one is refused');

section('Attachments are adopted without copying bytes');

$files_before = (int)$db->query("SELECT COUNT(*) FROM fil_files WHERE fil_source = 'boards_attachment'")->fetchColumn();
$att_mid = bem_send($back, $editor_mail, 'With files', 'see attached', $pass, array('attachments' => array(
	'quote.txt' => "line one\nline two\n", 'run.php' => '<?php echo 1;', 'second.csv' => "a,b\n1,2\n")));
$att_card = bem_cards((int)$board->key)[0];
$q = DbConnector::GetPreparedStatement('SELECT bat_fil_file_id, bat_name FROM bat_attachments WHERE bat_bcd_card_id = ? ORDER BY bat_attachment_id');
$q->execute(array((int)$att_card->key));
$attached = $q->fetchAll(PDO::FETCH_ASSOC);
check(array_column($attached, 'bat_name') === array('quote.txt', 'second.csv'), 'the two allowed files are attached; the blocked type is skipped', json_encode($attached));
if ($attached) {
	$file = new File((int)$attached[0]['bat_fil_file_id'], TRUE);
	check($file->get('fil_access_provider') === 'boards_board' && (int)$file->get('fil_access_ref') === (int)$board->key
		&& (int)$file->get('fil_usr_user_id') === $editor, 'the adopted File is gated on the board and owned by the member');
	$q = DbConnector::GetPreparedStatement('SELECT ima_fil_file_id FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ? AND ima_filename = ?');
	$q->execute(array($att_mid, 'quote.txt'));
	$mail_file = new File((int)$q->fetchColumn(), TRUE);
	check((int)$mail_file->get('fil_fbb_file_blob_id') > 0
		&& (int)$mail_file->get('fil_fbb_file_blob_id') === (int)$file->get('fil_fbb_file_blob_id'),
		'and shares the mailbox copy\'s blob: no bytes were copied');
}

// ---------------------------------------------------------------------------
section('What must not make a card');

$cards_now = count(bem_cards((int)$board->key));
$strange = bem_send($back, 'someone.else@sender.example', 'Not from the member', 'hello', $pass);
check($strange > 0 && count(bem_cards((int)$board->key)) === $cards_now, 'mail from someone else\'s address: stored, no card');
$notes = DbConnector::GetPreparedStatement("SELECT ntf_title, ntf_body FROM ntf_notifications WHERE ntf_usr_user_id = ? AND ntf_title LIKE '%Email not used'");
$notes->execute(array($editor));
$told = $notes->fetchAll(PDO::FETCH_ASSOC);
check(count($told) === 1 && stripos($told[0]['ntf_body'], 'isn\'t yours') !== false, 'the member is told once, in the app', json_encode($told));
bem_send($back, 'again@sender.example', 'Not from the member either', 'hello', $pass);
$notes->execute(array($editor));
check(count($notes->fetchAll(PDO::FETCH_ASSOC)) === 1, 'a second one within the hour does not tell them again');

$unattested = bem_send($back, $editor_mail, 'Forged?', 'from the right address but not vouched for', $none);
check($unattested > 0 && count(bem_cards((int)$board->key)) === $cards_now, 'the right From address with no authentication: no card');

$other_board = BoardsManager::createBoard($owner, 'Other board ' . $suffix);
harness_register_model('Board', (int)$other_board->key);
$wrong_board_addr = BoardsEmail::addressFor($other_board, $owner);
check($wrong_board_addr !== null, 'another board has its own address');
$other_cards_before = count(bem_cards((int)$other_board->key));
BoardsManager::createList($other_board, $owner, 'Inbox');
bem_send($wrong_board_addr, $editor_mail, 'Using someone else\'s address', 'x', $pass);
check(count(bem_cards((int)$other_board->key)) === $other_cards_before && count(bem_cards((int)$board->key)) === $cards_now,
	'a member\'s mail to the owner\'s address is not the owner: no card anywhere');

// A member who can still see the board is told why their mail made no card, within the same
// once-an-hour allowance, so each case below starts with the earlier notice an hour old.
$age_notices = function () use ($db, $editor) {
	$db->exec("UPDATE ntf_notifications SET ntf_create_time = now() - interval '2 hours' WHERE ntf_usr_user_id = " . (int)$editor . " AND ntf_title LIKE '%Email not used'");
};
$told_with = function (string $phrase) use ($notes, $editor): bool {
	$notes->execute(array($editor));
	foreach ($notes->fetchAll(PDO::FETCH_ASSOC) as $n) {
		if (stripos($n['ntf_body'], $phrase) !== false) { return true; }
	}
	return false;
};

$age_notices();
BoardsManager::setRole($board, BoardsManager::memberOn($board, boards_member_id($board, $editor)), 'viewer');
Boards::forgetRoles();
bem_send($back, $editor_mail, 'After being demoted', 'x', $pass);
check(count(bem_cards((int)$board->key)) === $cards_now, 'an editor demoted to viewer since the address was made: no card, though the address still resolves');
check($told_with('only view this board'), 'and the member is told they can only view the board');
check(InboundPersonalAddress::resolve('boards', $token) !== null, 'and the address was not revoked on access loss');
BoardsManager::setRole($board, BoardsManager::memberOn($board, boards_member_id($board, $editor)), 'editor');
Boards::forgetRoles();

$age_notices();
BoardsManager::archiveBoard($board);
bem_send($back, $editor_mail, 'To an archived board', 'x', $pass);
check(count(bem_cards((int)$board->key)) === $cards_now, 'an archived board is read-only: no card');
check($told_with('archived'), 'and the member is told the board is archived');
BoardsManager::restoreBoard($board);

$age_notices();
$empty_board = BoardsManager::createBoard($editor, 'No lists ' . $suffix);
harness_register_model('Board', (int)$empty_board->key);
$empty_addr = BoardsEmail::addressFor($empty_board, $editor);
bem_send($empty_addr, $editor_mail, 'To a board with no list', 'x', $pass);
check(count(bem_cards((int)$empty_board->key)) === 0, 'a board with no list has nowhere to put a card');
check($told_with('no list yet'), 'and the member is told to add a list');

$db->exec("UPDATE usr_users SET usr_is_disabled = true WHERE usr_user_id = " . $editor);
bem_send($back, $editor_mail, 'From a disabled account', 'x', $pass);
check(count(bem_cards((int)$board->key)) === $cards_now, 'a disabled account: no card');
$db->exec("UPDATE usr_users SET usr_is_disabled = false WHERE usr_user_id = " . $editor);

// ---------------------------------------------------------------------------
section('Subject shortcuts');

$dup_label = BoardsManager::saveLabel($board, null, 'Same', 'green');
$dup_label2 = BoardsManager::saveLabel($board, null, 'same', 'orange');
$parsed = BoardsEmail::parseSubject($board, 'FW: Fwd: Plan #Same #URGENT @nobody @' . $first . ' end');
check($parsed['title'] === 'Plan #Same @nobody @' . $first . ' end' || strpos($parsed['title'], '#Same') !== false,
	'an ambiguous #label stays in the title', $parsed['title']);
check(in_array((int)$label_urgent->key, $parsed['label_ids'], true), '#URGENT matches in any case');
check(strpos($parsed['title'], '@nobody') !== false, 'an unknown @name stays');
check(BoardsEmail::cleanSubject('RE: re:  FWD: hello   world') === 'hello world', 'Re:/Fwd:/Fw: prefixes are stripped, spaces collapsed');
check(BoardsEmail::stripSignature("Body\n--\nSig") === 'Body' && BoardsEmail::stripSignature("Body\n-- \nSig") === 'Body'
	&& BoardsEmail::stripSignature("Hi\n\nSent from my iPhone") === 'Hi' && BoardsEmail::stripSignature("A -- B") === 'A -- B',
	'signatures are cut at the standard delimiter and "Sent from my…" is dropped; a dash in a sentence is not a delimiter');

// ---------------------------------------------------------------------------
section('Make a card from the reader');

$mb_domain = $d1;
$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$mb_domain->key);
$alias->set('iea_alias', 'desk');
$alias->set('iea_delivery_mode', 'store');
$alias->save();
InboundEmailMailboxGrant::sync_for_alias((int)$alias->key, array($editor));
$desk = 'desk@' . $mb_domain->get('ied_domain');
$desk_msg_id = bem_send($desk, 'customer@sender.example', 'Please quote 40 chairs', "40 chairs, delivered.\n", $pass);
check($desk_msg_id > 0, 'a message lands in a Standard-level mailbox');

$res = boards_call_page('boards_from_message', $editor, array('message_id' => $desk_msg_id));
check($res->error === null && empty($res->data['error']) && !empty($res->data['targets']) && $res->data['default_title'] === 'Please quote 40 chairs',
	'the picker opens for a member with a grant: boards to choose from, title prefilled', json_encode($res->data['error'] ?? $res->error));
$res = boards_call_page('boards_from_message', $stranger, array('message_id' => $desk_msg_id));
check(!empty($res->data['error']) && stripos($res->data['error'], 'access') !== false, 'a member without the grant is refused', (string)($res->data['error'] ?? ''));
$res = boards_call_page('boards_from_message', $owner, array('message_id' => 999999999));
check(!empty($res->data['error']) && stripos($res->data['error'], 'no longer exists') !== false, 'a missing message is refused');
$db->exec("UPDATE iea_inbound_email_aliases SET iea_security_level = 'private' WHERE iea_inbound_email_alias_id = " . (int)$alias->key);
$res = boards_call_page('boards_from_message', $editor, array('message_id' => $desk_msg_id));
check(!empty($res->data['error']) && stripos($res->data['error'], 'protected') !== false && empty($res->data['message_summary']),
	'a mailbox that seals its content is refused before anything is read', (string)($res->data['error'] ?? ''));
$db->exec("UPDATE iea_inbound_email_aliases SET iea_security_level = NULL WHERE iea_inbound_email_alias_id = " . (int)$alias->key);

$desk_msg = new InboundEmailMessage($desk_msg_id, TRUE);
$made = BoardsEmail::createFromMessage($board, $todo, $editor, $desk_msg, array('title' => 'Quote 40 chairs', 'source' => 'web', 'shortcuts' => false));
$mc = $made['card'];
check($mc->get('bcd_source') === 'web' && (int)$mc->get('bcd_iem_message_id') === $desk_msg_id && $mc->get('bcd_title') === 'Quote 40 chairs'
	&& trim((string)$mc->get('bcd_description')) === '40 chairs, delivered.', 'Make a card keeps the message\'s text, the chosen title and the back reference');
$res = boards_call_page('boards_from_message', $editor, array('message_id' => $desk_msg_id));
check(!empty($res->data['info']) && !empty($res->data['existing_url']) && strpos($res->data['existing_url'], 'card=' . (int)$mc->key) !== false,
	'opening the picker again for that message says it has a card and links to it');

section('The card\'s back link says what this viewer may be told');

$detail_editor = BoardsPayload::cardDetail($mc, $board, $editor, 'editor');
check($detail_editor['source_email']['state'] === 'open' && strpos((string)$detail_editor['source_email']['url'], 'open=' . $desk_msg_id) !== false,
	'someone who can open the mailbox gets a link to the email');
$detail_owner = BoardsPayload::cardDetail($mc, $board, $owner, 'admin');
check($detail_owner['source_email']['state'] === 'closed' && $detail_owner['source_email']['url'] === null,
	'someone who cannot gets "from an email in a mailbox you can\'t open"');
$detail_plain = BoardsPayload::cardDetail($w['card'], $board, $owner, 'admin');
check($detail_plain['source_email'] === null, 'a card made on the web has no back link');
$db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = " . $desk_msg_id);
$db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = " . $desk_msg_id);
$detail_gone = BoardsPayload::cardDetail($mc, $board, $editor, 'editor');
check($detail_gone['source_email']['state'] === 'gone', 'once the email is deleted: "no longer available", and the card is untouched');
check($db->query("SELECT COUNT(*) FROM bcd_cards WHERE bcd_card_id = " . (int)$mc->key)->fetchColumn() == 1, 'the card is still there');

// ---------------------------------------------------------------------------
section('The API action');

$r = boards_ok(boards_call('boards_email', $editor, array('op' => 'panel', 'board_id' => (int)$board->key)), 'an editor opens the panel');
check($r['email']['status'] === 'ok' && !empty($r['email']['address']) && empty($r['email']['is_admin']), 'with their address and no admin controls');
$r2 = boards_ok(boards_call('boards_email', $editor, array('op' => 'reset', 'board_id' => (int)$board->key)), 'an editor resets their address');
check($r2['email']['address'] !== $r['email']['address'], 'and gets a different one');
boards_refused(boards_call('boards_email', $editor, array('op' => 'set_landing', 'board_id' => (int)$board->key, 'position' => 'top')), 'an editor cannot choose where cards land');
boards_refused(boards_call('boards_email', $editor, array('op' => 'set_domain', 'board_id' => (int)$board->key, 'domain_id' => (int)$d2->key)), 'nor the domain');
$r3 = boards_ok(boards_call('boards_email', $owner, array('op' => 'set_landing', 'board_id' => (int)$board->key, 'list_id' => (int)$doing->key, 'position' => 'top')), 'a board admin can');
check($r3['email']['landing']['list_id'] === (int)$doing->key && $r3['email']['landing']['position'] === 'top', 'and the panel reflects it');
$r4 = boards_ok(boards_call('boards_email', $viewer, array('op' => 'panel', 'board_id' => (int)$board->key)), 'a viewer can open the panel');
check(empty($r4['email']['can_have_address']) && empty($r4['email']['address']), 'and has no address');
boards_refused(boards_call('boards_email', $stranger, array('op' => 'panel', 'board_id' => (int)$board->key)), 'someone with no role gets "not found"', 'not found');
boards_refused(boards_call('boards_email', $viewer, array('op' => 'reset', 'board_id' => (int)$board->key)), 'a viewer cannot reset an address they do not have');

section('Declarations');

$manifest = json_decode(file_get_contents(PathHelper::getIncludePath('plugins/boards/plugin.json')), true);
check(($manifest['inboundHandlers']['boards']['class'] ?? '') === 'BoardsInboundHandler', 'plugin.json declares the Boards handler');
check(($manifest['mailboxMessageActions']['boards_make_card']['url'] ?? '') === '/profile/boards/from_message', 'and the Make a card reader action');
check(isset($manifest['signals']['boards.email_refused']) && $manifest['signals']['boards.email_refused']['notify']['default_email'] === false,
	'and the in-app-only "email not used" signal');

// ---------------------------------------------------------------------------
section('Deleting a board revokes its addresses');

$live_token = bem_token((string)BoardsEmail::addressFor($board, $editor));
$doomed = BoardsManager::createBoard($owner, 'Doomed email board ' . $suffix);
$doomed_addr = BoardsEmail::addressFor($doomed, $owner);
$doomed_token = bem_token((string)$doomed_addr);
check($doomed_token !== '' && InboundPersonalAddress::resolve('boards', $doomed_token) !== null, 'a board\'s address resolves');
$doomed->permanent_delete();
check(InboundPersonalAddress::resolve('boards', $doomed_token) === null, 'and stops when the board is permanently deleted');
check(InboundPersonalAddress::resolve('boards', $live_token) !== null, 'other boards\' addresses are untouched');

harness_finish();

/** Run a page logic function (not an API action) as a person. */
function boards_call_page(string $name, int $user_id, array $input): LogicResult {
	require_once(PathHelper::getIncludePath('plugins/boards/logic/' . $name . '_logic.php'));
	$session = SessionControl::get_instance();
	$session->set_api_user($user_id, null);
	try {
		return call_user_func($name . '_logic', $input);
	} finally {
		$session->clear_api_user();
	}
}
