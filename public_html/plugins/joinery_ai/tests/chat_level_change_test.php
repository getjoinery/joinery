<?php
/** @joinery-test
 * name: joinery_ai_chat_level_change
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Changing an existing chat's privacy level (chat_set_capabilities,
 * field=security_level), then converging it with chat_level_batch until
 * nothing remains:
 *   - only the owner, only Standard <-> Private, Private needs a vault;
 *   - raising seals the title, the instructions and every turn, with the
 *     window closed (sealing takes only the public key);
 *   - lowering needs the owner's window, before anything changes, and opens
 *     everything back to plaintext;
 *   - a running turn blocks the change;
 *   - the level flips first, and a long chat converges in bounded batches;
 *   - a change asks for a recent second factor, for an owner who has one.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
// The session starts before harness_boot(), which may print (a stale-mail sweep)
// and so make a later session_start() impossible on the CLI.
$has_session = vault_ensure_session();
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('joinery_ai')) { harness_skip('joinery_ai plugin inactive'); harness_finish(); }

$db = DbConnector::get_instance()->get_db_link();
$saved_session = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null,
	'permission' => $_SESSION['permission'] ?? null);
harness_defer(function () use ($saved_session) {
	foreach ($saved_session as $k => $v) { if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; } }
});
function clc_as($user) {
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
}

/** A Standard chat with a title, instructions and $turns messages. */
function clc_chat(int $owner_id, int $turns): AiConversation {
	$c = new AiConversation(NULL);
	$c->set('aic_owner_user_id', $owner_id);
	$c->set('aic_security_level', AiConversation::LEVEL_STANDARD);
	$c->set('aic_model', 'qwen3:4b-instruct');
	$c->set('aic_title', 'Quarterly plan');
	$c->set('aic_instructions', 'Answer briefly.');
	$c->save();
	$c->load();
	harness_register_row('aic_conversations', 'aic_conversation_id', (int)$c->key);
	for ($i = 0; $i < $turns; $i++) {
		$m = new AiConversationMessage(NULL);
		$m->set('aim_aic_conversation_id', (int)$c->key);
		$m->set('aim_role', $i % 2 ? AiConversationMessage::ROLE_ASSISTANT : AiConversationMessage::ROLE_USER);
		$m->set('aim_content', 'turn ' . $i . ' of the plan');
		$m->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
		$m->save();
		harness_register_row('aim_conversation_messages', 'aim_conversation_message_id', (int)$m->key);
	}
	return $c;
}

function clc_set_level(AiConversation $c, string $level): LogicResult {
	return harness_call_logic('plugins/joinery_ai/logic/chat_set_capabilities_logic.php', 'chat_set_capabilities_logic',
		array('conversation_id' => (int)$c->key, 'field' => 'security_level', 'value' => $level));
}

/** Drive chat_level_batch until nothing remains; returns the last pass. */
function clc_converge(AiConversation $c, ?LogicResult $change) {
	$remaining = (int)($change->data['remaining'] ?? 0);
	$last = $change;
	for ($i = 0; $i < 20 && $remaining > 0; $i++) {
		$last = harness_call_logic('plugins/joinery_ai/logic/chat_level_batch_logic.php', 'chat_level_batch_logic',
			array('conversation_id' => (int)$c->key));
		if ($last->error !== null) return $last;
		$remaining = (int)($last->data['remaining'] ?? 0);
		if ((int)($last->data['converted'] ?? 0) === 0) break;
	}
	return $last;
}

/** [conversation sealed?, sealed message count, total message count, stored level] straight from the table. */
function clc_state(AiConversation $c): array {
	$db = DbConnector::get_instance()->get_db_link();
	$conv = $db->query('SELECT aic_content_sealed, aic_title, aic_security_level FROM aic_conversations WHERE aic_conversation_id = ' . (int)$c->key)->fetch(PDO::FETCH_ASSOC);
	$counts = $db->query('SELECT COUNT(*) FILTER (WHERE aim_content_sealed) AS sealed, COUNT(*) AS total
		FROM aim_conversation_messages WHERE aim_delete_time IS NULL AND aim_aic_conversation_id = ' . (int)$c->key)->fetch(PDO::FETCH_ASSOC);
	return array('conv_sealed' => in_array($conv['aic_content_sealed'], array(true, 't', 1, '1'), true),
		'title_blob' => strpos((string)$conv['aic_title'], 'v1.aead.') === 0,
		'sealed' => (int)$counts['sealed'], 'total' => (int)$counts['total'], 'level' => (string)$conv['aic_security_level']);
}

$owner = make_user('ChatLvlOwner', 5);
$stranger = make_user('ChatLvlStranger', 5);
$novault = make_user('ChatLvlNoVault', 5);
$twofa = make_user('ChatLvlTwoFactor', 5);
$twofa->enable_totp('JBSWY3DPEHPK3PXP');
$twofa->save();
$kp = vault_fixture_server_vault((int)$owner->key);
vault_fixture_server_vault((int)$twofa->key);
$window_ok = $has_session && vault_apcu_usable();

// ---------------------------------------------------------------------------
section('Who and which levels');

$chat = clc_chat((int)$owner->key, 4);
clc_as($stranger);
$r = clc_set_level($chat, AiConversation::LEVEL_PRIVATE);
check($r->error !== null && clc_state($chat)['level'] === AiConversation::LEVEL_STANDARD, 'someone else\'s chat is refused', (string)$r->error);
clc_as($owner);
$r = clc_set_level($chat, 'secret');
check($r->error !== null && clc_state($chat)['level'] === AiConversation::LEVEL_STANDARD, 'a level chat does not offer is refused', (string)$r->error);
clc_as($novault);
$nv_chat = clc_chat((int)$novault->key, 1);
$r = clc_set_level($nv_chat, AiConversation::LEVEL_PRIVATE);
check($r->error !== null && stripos((string)$r->error, 'vault') !== false && clc_state($nv_chat)['level'] === AiConversation::LEVEL_STANDARD,
	'Private needs a vault', (string)$r->error);
clc_as($owner);

// ---------------------------------------------------------------------------
section('A running turn blocks the change');

$busy = clc_chat((int)$owner->key, 1);
$run = new AiConversationMessage(NULL);
$run->set('aim_aic_conversation_id', (int)$busy->key);
$run->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
$run->set('aim_status', AiConversationMessage::STATUS_RUNNING);
$run->save();
harness_register_row('aim_conversation_messages', 'aim_conversation_message_id', (int)$run->key);
$r = clc_set_level($busy, AiConversation::LEVEL_PRIVATE);
check($r->error !== null && stripos((string)$r->error, 'reply') !== false && clc_state($busy)['level'] === AiConversation::LEVEL_STANDARD,
	'a chat with a reply in flight keeps its level until the reply finishes', (string)$r->error);

// ---------------------------------------------------------------------------
section('Raising seals everything, with the window closed');

if ($window_ok) VaultUnlock::close((int)$owner->key, UserEncryptionVault::SCOPE_USER);
$r = clc_set_level($chat, AiConversation::LEVEL_PRIVATE);
check($r->error === null && ($r->data['security_level'] ?? '') === AiConversation::LEVEL_PRIVATE,
	'the raise is accepted with the window closed', (string)$r->error);
$pass = clc_converge($chat, $r);
$st = clc_state($chat);
check($st['level'] === AiConversation::LEVEL_PRIVATE && $st['conv_sealed'] && $st['title_blob'],
	'the chat is Private and its title is sealed');
check($st['total'] === 4 && $st['sealed'] === 4, 'every turn is sealed', json_encode($st));

// ---------------------------------------------------------------------------
section('Lowering needs the owner\'s window, before anything changes');

$r = clc_set_level($chat, AiConversation::LEVEL_STANDARD);
$st = clc_state($chat);
check($r->error !== null && stripos((string)$r->error, 'Unlock your vault') !== false
	&& $st['level'] === AiConversation::LEVEL_PRIVATE && $st['sealed'] === 4,
	'with the window closed the lowering is refused and nothing changes', (string)$r->error);
if (!$window_ok) {
	harness_skip('window-open lowering', 'APCu/session unavailable (run with -d apc.enable_cli=1)');
} else {
	vault_fixture_open_window((int)$owner->key, $kp['secret'], UserEncryptionVault::SCOPE_USER);
	$r = clc_set_level($chat, AiConversation::LEVEL_STANDARD);
	$pass = clc_converge($chat, $r);
	$st = clc_state($chat);
	check($r->error === null && $st['level'] === AiConversation::LEVEL_STANDARD && !$st['conv_sealed'] && $st['sealed'] === 0,
		'with the window open the chat is Standard and every turn is plaintext again', (string)$r->error . ' ' . json_encode($st));
	$reread = new AiConversation((int)$chat->key, TRUE);
	check((string)$reread->get('aic_title') === 'Quarterly plan' && (string)$reread->get('aic_instructions') === 'Answer briefly.',
		'the title and instructions come back exactly');
	VaultUnlock::close((int)$owner->key, UserEncryptionVault::SCOPE_USER);
}

// ---------------------------------------------------------------------------
section('The level flips first; a long chat converges in bounded batches');

$long = clc_chat((int)$owner->key, 60);
$r = clc_set_level($long, AiConversation::LEVEL_PRIVATE);
$st = clc_state($long);
check($r->error === null && $st['level'] === AiConversation::LEVEL_PRIVATE, 'the promise is Private as soon as the change returns', (string)$r->error);
check((int)($r->data['remaining'] ?? 0) > 0 && $st['sealed'] < 60,
	'one request does not convert a long chat whole: the rest is reported as remaining', json_encode($r->data) . ' ' . json_encode($st));
$pass = clc_converge($long, $r);
$st = clc_state($long);
check($pass->error === null && (int)($pass->data['remaining'] ?? -1) === 0 && $st['sealed'] === 60 && $st['conv_sealed'],
	'chat_level_batch converges the rest', (string)$pass->error . ' ' . json_encode($st));

// ---------------------------------------------------------------------------
section('A change that stopped part-way always finishes');

$halt = clc_chat((int)$owner->key, 60);
$r = clc_set_level($halt, AiConversation::LEVEL_PRIVATE);   // one pass, then the tab "closes"
$left_1 = (int)($r->data['remaining'] ?? 0);
check($left_1 > 0, 'the first request leaves a backlog', json_encode($r->data));
$again = clc_set_level($halt, AiConversation::LEVEL_PRIVATE);
check($again->error === null && (int)($again->data['remaining'] ?? -1) < $left_1,
	'picking the level it already has runs another pass', json_encode($again->data));
$running = new AiConversationMessage(NULL);
$running->set('aim_aic_conversation_id', (int)$halt->key);
$running->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
$running->set('aim_status', AiConversationMessage::STATUS_RUNNING);
$running->save();
harness_register_row('aim_conversation_messages', 'aim_conversation_message_id', (int)$running->key);
check(ChatConversationLevel::hasWork((int)$owner->key), 'the vault\'s deferred work sees the unfinished chat');
ChatConversationLevel::drain((int)$owner->key, microtime(true) + 60);
$st = clc_state($halt);
check($st['sealed'] === 60 && $st['conv_sealed'], 'and its drain finishes it', json_encode($st));
check(!(new AiConversationMessage((int)$running->key, TRUE))->rowIsSealed(),
	'a reply still being written is left to its own finalize');
$running->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
$running->save();   // done now: the drain may take it
ChatConversationLevel::drain((int)$owner->key, microtime(true) + 60);
check(!ChatConversationLevel::hasWork((int)$owner->key), 'once everything is at its promise there is no deferred work');

if (!$window_ok) {
	harness_skip('a lowered chat still holding sealed turns', 'APCu/session unavailable (run with -d apc.enable_cli=1)');
} else {
	vault_fixture_open_window((int)$owner->key, $kp['secret'], UserEncryptionVault::SCOPE_USER);
	$r = clc_set_level($halt, AiConversation::LEVEL_STANDARD);   // one pass of 61 rows
	check($r->error === null && (int)($r->data['remaining'] ?? 0) > 0, 'a lowering of a long chat also leaves a backlog', (string)$r->error);
	VaultUnlock::close((int)$owner->key, UserEncryptionVault::SCOPE_USER);
	$lowered = new AiConversation((int)$halt->key, TRUE);
	check(!$lowered->isProtected() && ChatSeal::holdsSealedContent($lowered) && ChatSeal::isLocked($lowered),
		'while it still holds sealed turns, a Standard chat reads as locked with the window closed');
	check(ChatSeal::lockedForContentEdit($lowered), 'and editing its instructions asks for the unlock first');
	$found = ChatConversationLevel::hasWork((int)$owner->key);
	$tool = MultiAiConversation::searchForTool((int)$owner->key, 'turn', 50, false);
	$ids = array_map(function ($m) { return (int)$m['id']; }, $tool['matches']);
	check($found && !in_array((int)$halt->key, $ids, true),
		'SQL search leaves it alone (no ciphertext as a title)');
	vault_fixture_open_window((int)$owner->key, $kp['secret'], UserEncryptionVault::SCOPE_USER);
	ChatConversationLevel::drain((int)$owner->key, microtime(true) + 60);
	$st = clc_state($halt);
	check($st['sealed'] === 0 && !$st['conv_sealed'], 'in the owner\'s window the drain opens the rest', json_encode($st));
	VaultUnlock::close((int)$owner->key, UserEncryptionVault::SCOPE_USER);
}

// ---------------------------------------------------------------------------
section('Attachments: a failed read never costs the key; a turn\'s attachments follow it later');

/** An attachment on $m owned by $owner_id, its text and bytes plain. */
function clc_attach(AiConversationMessage $m, int $owner_id, string $bytes): array {
	$file = File::createFromBytes($bytes, 'note_' . bin2hex(random_bytes(3)) . '.txt', 'text/plain', $owner_id,
		array('fil_private' => true, 'fil_source' => File::SOURCE_AI_CHAT_UPLOAD));
	harness_defer(function () use ($file) { try { $f = new File((int)$file->key, true); if ($f->key) $f->permanent_delete(); } catch (Throwable $e) {} });
	$link = new AiMessageAttachment(NULL);
	$link->set('aia_aim_conversation_message_id', (int)$m->key);
	$link->set('aia_fil_file_id', (int)$file->key);
	$link->set('aia_extracted_text', 'extracted ' . $bytes);
	$link->set('aia_extract_status', 'ok');
	$link->save();
	harness_register_row('aia_message_attachments', 'aia_message_attachment_id', (int)$link->key);
	return array('file' => new File((int)$file->key, true), 'link_id' => (int)$link->key);
}
function clc_link_sealed(int $link_id): bool {
	return in_array(DbConnector::get_instance()->get_db_link()
		->query('SELECT aia_sealed FROM aia_message_attachments WHERE aia_message_attachment_id = ' . $link_id)->fetchColumn(),
		array(true, 't', 1, '1'), true);
}
function clc_row(int $message_id): array {
	return DbConnector::get_instance()->get_db_link()
		->query('SELECT * FROM aim_conversation_messages WHERE aim_conversation_message_id = ' . $message_id)->fetch(PDO::FETCH_ASSOC);
}

$att_owner = make_user('ChatLvlAttach', 5);
$att_kp = vault_fixture_server_vault((int)$att_owner->key);
clc_as($att_owner);
$achat = clc_chat((int)$att_owner->key, 3);
$turn = null;
foreach (new MultiAiConversationMessage(array('conversation_id' => (int)$achat->key), array('aim_conversation_message_id' => 'ASC')) as $m) { $turn = $m; break; }
$a = clc_attach($turn, (int)$att_owner->key, 'attached figures 42');
$path = $a['file']->get_filesystem_path('original');
rename($path, $path . '.away');   // the next read of it fails
if ($window_ok) VaultUnlock::close((int)$att_owner->key, UserEncryptionVault::SCOPE_USER);
$r = clc_set_level($achat, AiConversation::LEVEL_PRIVATE);
$row = clc_row((int)$turn->key);
check($r->error === null && !clc_link_sealed($a['link_id']) && !empty($row['aim_level_attempt_time']),
	'a failed attachment read leaves the link unsealed and stamps the turn', json_encode($r->data));
check((int)(new ChatConversationLevel(new AiConversation((int)$achat->key, true)))->remaining() === 1,
	'the turn stays in the backlog: its row sealed, its attachment not (B5)');
check(!ChatConversationLevel::hasWork((int)$att_owner->key),
	'a stamped turn does not wake the deferred work until its retry is due');
rename($path . '.away', $path);
$db->prepare("UPDATE aim_conversation_messages SET aim_level_attempt_time = NOW() AT TIME ZONE 'UTC' - INTERVAL '2 hours'
	WHERE aim_conversation_message_id = ?")->execute(array((int)$turn->key));   // the retry is due
check(ChatConversationLevel::hasWork((int)$att_owner->key), 'once due, the turn is work again');
if (!$window_ok) {
	harness_skip('finishing the attachment in window, and the lowering side', 'APCu/session unavailable');
} else {
	vault_fixture_open_window((int)$att_owner->key, $att_kp['secret'], UserEncryptionVault::SCOPE_USER);
	ChatConversationLevel::drain((int)$att_owner->key, microtime(true) + 60);
	$sealed_bytes = (string)(new File((int)$a['file']->key, true))->read_bytes('original');
	$fresh = new AiConversationMessage((int)$turn->key, true);
	check(clc_link_sealed($a['link_id']) && strpos($sealed_bytes, 'v1.aead.') === 0
		&& ChatSeal::openAttachmentBytes($fresh, $a['link_id'], $sealed_bytes) === 'attached figures 42'
		&& empty(clc_row((int)$turn->key)['aim_level_attempt_time']),
		'in window the drain finishes the attachment under the turn\'s own DEK, and clears the stamp');

	// A second driver holding a stale copy from before the seal mints nothing.
	$key_before = (string)clc_row((int)$turn->key)['aim_sealed_key'];
	$stale_chat = clc_chat((int)$att_owner->key, 1);
	$stale_turn = null;
	foreach (new MultiAiConversationMessage(array('conversation_id' => (int)$stale_chat->key)) as $m) { $stale_turn = $m; }
	$b = clc_attach($stale_turn, (int)$att_owner->key, 'second driver bytes');
	AiConversation::updateColumns((int)$stale_chat->key, array('aic_security_level' => AiConversation::LEVEL_PRIVATE));
	$stale_chat = new AiConversation((int)$stale_chat->key, true);
	$stale_copy = new AiConversationMessage((int)$stale_turn->key, true);   // loaded unsealed
	ChatSeal::sealExistingMessage(new AiConversationMessage((int)$stale_turn->key, true), $stale_chat);
	$first_key = (string)clc_row((int)$stale_turn->key)['aim_sealed_key'];
	ChatSeal::sealExistingMessage($stale_copy, $stale_chat);
	$b_bytes = (string)(new File((int)$b['file']->key, true))->read_bytes('original');
	check($first_key !== '' && (string)clc_row((int)$stale_turn->key)['aim_sealed_key'] === $first_key
		&& ChatSeal::openAttachmentBytes(new AiConversationMessage((int)$stale_turn->key, true), $b['link_id'], $b_bytes) === 'second driver bytes',
		'a second driver with a stale unsealed copy mints no second DEK: one key opens the turn and its attachment (R2)');

	// The lowering side: a failed read keeps the turn sealed, with its key.
	rename($path, $path . '.away');
	$r = clc_set_level($achat, AiConversation::LEVEL_STANDARD);
	$row = clc_row((int)$turn->key);
	check($r->error === null && in_array($row['aim_content_sealed'], array(true, 't', 1, '1'), true)
		&& (string)$row['aim_sealed_key'] === $key_before && clc_link_sealed($a['link_id']) && !empty($row['aim_level_attempt_time']),
		'lowering with the attachment unreadable keeps the turn sealed, its DEK and link intact, and stamps it (R1)');
	rename($path . '.away', $path);

	// Lowered with rows still sealed: the page, the serializer and the editor all see it.
	$lowered_chat = new AiConversation((int)$achat->key, true);
	VaultUnlock::close((int)$att_owner->key, UserEncryptionVault::SCOPE_USER);
	$summary = ChatSerializer::conversationSummary($lowered_chat);
	check(!empty($summary['locked']) && $summary['title'] === ChatSeal::LOCKED_TITLE,
		'the chat list shows the locked placeholder, not ciphertext, with the window closed');
	$tool = MultiAiConversation::searchForTool((int)$att_owner->key, 'turn', 50, false);
	$tool_ids = array_map(function ($m) { return (int)$m['id']; }, $tool['matches']);
	check(!in_array((int)$achat->key, $tool_ids, true), 'SQL search leaves the lowered, unconverged chat alone');
	vault_fixture_open_window((int)$att_owner->key, $att_kp['secret'], UserEncryptionVault::SCOPE_USER);
	// A chat flipped to Standard whose own row has not converged back yet.
	$edit = new AiConversation(NULL);
	$edit->set('aic_owner_user_id', (int)$att_owner->key);
	$edit->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
	$edit->set('aic_title', 'Sealed title');
	$edit->set('aic_instructions', 'Be terse.');
	$edit->save();
	harness_register_row('aic_conversations', 'aic_conversation_id', (int)$edit->key);
	AiConversation::updateColumns((int)$edit->key, array('aic_security_level' => AiConversation::LEVEL_STANDARD));
	$edit = new AiConversation((int)$edit->key, true);
	check($edit->rowIsSealed() && !$edit->isProtected(), 'fixture: a Standard chat whose row is still sealed');
	ChatSeal::setConversationContent($edit, 'aic_instructions', 'Answer at length.');
	$after = new AiConversation((int)$edit->key, true);
	check((string)$after->get('aic_instructions') === 'Answer at length.' && (string)$after->get('aic_title') === 'Sealed title'
		&& !$after->rowIsSealed(),
		'an instructions edit opens the still-sealed row first, then lands (the title comes back too)');

	// An owner whose ONLY chat is Standard-but-still-sealed: search withholds it rather than showing ciphertext.
	$solo = make_user('ChatLvlSolo', 5);
	vault_fixture_server_vault((int)$solo->key);
	$only = new AiConversation(NULL);
	$only->set('aic_owner_user_id', (int)$solo->key);
	$only->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
	$only->set('aic_title', 'turn planning');
	$only->save();
	harness_register_row('aic_conversations', 'aic_conversation_id', (int)$only->key);
	AiConversation::updateColumns((int)$only->key, array('aic_security_level' => AiConversation::LEVEL_STANDARD));
	$solo_search = MultiAiConversation::searchForTool((int)$solo->key, 'turn', 10, false);
	check(empty($solo_search['matches']) && !empty($solo_search['protected_withheld']),
		'an owner whose only chat still holds sealed rows gets it withheld, not matched by SQL');
	VaultUnlock::close((int)$att_owner->key, UserEncryptionVault::SCOPE_USER);
}
clc_as($owner);

// ---------------------------------------------------------------------------
section('A change asks for a recent second factor, for an owner who has one');

if (!$has_session || session_id() === '') {
	harness_skip('step-up', 'no session could be started on the CLI');
} else {
	$sid = session_id();
	$clear_markers = function () use ($sid) {
		DbConnector::get_instance()->get_db_link()
			->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	};
	harness_defer($clear_markers);
	$clear_markers();
	clc_as($twofa);
	$mine = clc_chat((int)$twofa->key, 2);
	$r = clc_set_level($mine, AiConversation::LEVEL_PRIVATE);
	check($r->error !== null && !empty($r->data['requires_stepup']) && clc_state($mine)['level'] === AiConversation::LEVEL_STANDARD,
		'without a recent confirmation: refused with requires_stepup, nothing changes', (string)$r->error);
	SessionControl::get_instance()->stamp_second_factor();
	$r = clc_set_level($mine, AiConversation::LEVEL_PRIVATE);
	check($r->error === null && clc_state($mine)['level'] === AiConversation::LEVEL_PRIVATE, 'confirmed: the change goes through', (string)$r->error);
	$clear_markers();
}

harness_finish();
?>
