<?php
/** @joinery-test
 * name: joinery_ai_chat_encryption
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));   // vault_apcu_usable(), vault_ensure_session()

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('joinery_ai')) { harness_skip('joinery_ai plugin inactive'); harness_finish(); }
if (!extension_loaded('sodium')) { harness_skip('sodium extension unavailable'); harness_finish(); }

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

$box = new SealedBox();
$crypto = new VaultCrypto();

// Establish a session id up front (before any harness output) so the later
// VaultUnlock::open() window test can key on it — session_start() fails once
// headers/output have been sent.
$has_session = vault_ensure_session();

// ---- Fixtures: a user with a bare gen-1 vault row ------------------------
$user = make_user('ChatEnc');
$uid  = (int)$user->key;
$kp1  = $box->generateKeypair();
$kp2  = $box->generateKeypair();

$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $uid);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp1['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

// ---- Build a Private conversation with a sealed turn ---------------------
$conv = new AiConversation(NULL);
$conv->set('aic_owner_user_id', $uid);
$conv->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
$conv->set('aic_model', 'qwen3:4b-instruct');
$conv->save();
$conv->load();
harness_register_row('aic_conversations', 'aic_conversation_id', (int)$conv->key);
AiConversation::updateColumns((int)$conv->key,
    ChatSeal::sealConversationColumns((int)$conv->key, $conv,
        ['aic_title' => 'Merger due diligence', 'aic_instructions' => 'Be terse.']));

$msg = new AiConversationMessage(NULL);
$msg->set('aim_aic_conversation_id', (int)$conv->key);
$msg->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
$msg->set('aim_content', '');
$msg->save();
$msg->load();
harness_register_row('aim_conversation_messages', 'aim_message_id', (int)$msg->key);
$turn_cols = ChatSeal::turnColumns($conv, (int)$msg->key, 'The target is undervalued at 4x EBITDA.',
    [['name' => 'query_model', 'is_error' => false]]);
$turn_cols['aim_status'] = AiConversationMessage::STATUS_COMPLETE;
AiConversationMessage::updateColumns((int)$msg->key, $turn_cols);

// ---- Seal at rest: raw SQL shows ciphertext ------------------------------
section('Seal at rest');
$db = DbConnector::get_instance()->get_db_link();
$raw_msg = (function () use ($db, $msg) {
    $s = $db->prepare('SELECT * FROM aim_conversation_messages WHERE aim_message_id = ?');
    $s->execute([(int)$msg->key]); return $s->fetch(PDO::FETCH_ASSOC);
})();
$raw_conv = (function () use ($db, $conv) {
    $s = $db->prepare('SELECT * FROM aic_conversations WHERE aic_conversation_id = ?');
    $s->execute([(int)$conv->key]); return $s->fetch(PDO::FETCH_ASSOC);
})();
check(strpos((string)$raw_msg['aim_content'], 'v1.aead.') === 0, 'aim_content is ciphertext at rest');
check(strpos((string)$raw_msg['aim_tool_calls'], 'v1.aead.') === 0, 'aim_tool_calls is ciphertext at rest');
check(strpos((string)$raw_conv['aic_title'], 'v1.aead.') === 0, 'aic_title is ciphertext at rest');
check((string)$raw_msg['aim_sealed_key'] !== '', 'aim_sealed_key is populated');
check((int)$raw_msg['aim_content_sealed'] === 1 || $raw_msg['aim_content_sealed'] === true || $raw_msg['aim_content_sealed'] === 't', 'aim_content_sealed marked');
check((int)$raw_msg['aim_sealed_owner_user_id'] === $uid, 'aim_sealed_owner_user_id records the owner');
check((int)$raw_msg['aim_key_generation'] === 1, 'aim_key_generation matches the vault generation');

// ---- Crypto roundtrip (opens directly with the secret) -------------------
section('Crypto roundtrip');
$dek = $crypto->openItemDek((string)$raw_msg['aim_sealed_key'], $kp1['secret']);
$plain = $crypto->openField((string)$raw_msg['aim_content'], $dek, ChatSeal::messageAd((int)$msg->key, 'aim_content'));
check($plain === 'The target is undervalued at 4x EBITDA.', 'aim_content decrypts to the original plaintext');
$tcjson = $crypto->openField((string)$raw_msg['aim_tool_calls'], $dek, ChatSeal::messageAd((int)$msg->key, 'aim_tool_calls'));
$tc = json_decode($tcjson, true);
check(is_array($tc) && $tc[0]['name'] === 'query_model', 'aim_tool_calls decrypts + json_decodes to the trace');
$cdek = $crypto->openItemDek((string)$raw_conv['aic_sealed_key'], $kp1['secret']);
$ptitle = $crypto->openField((string)$raw_conv['aic_title'], $cdek, ChatSeal::conversationAd((int)$conv->key, 'title'));
check($ptitle === 'Merger due diligence', 'aic_title decrypts to the original');
// AD splice defense: the message body must NOT open under the wrong AD.
$spliced = false;
try { $crypto->openField((string)$raw_msg['aim_content'], $dek, ChatSeal::messageAd((int)$msg->key, 'aim_error')); $spliced = true; } catch (Throwable $e) {}
check(!$spliced, 'a ciphertext will not open under a different field AD (splice defense)');

// ---- Locked-state (no open window in plain CLI) --------------------------
section('Locked-state contract');
$summary = ChatSerializer::conversationSummary($conv);
check(($summary['locked'] ?? false) === true, 'conversationSummary flags a locked protected chat');
check($summary['title'] === ChatSeal::LOCKED_TITLE, 'the sealed title is withheld behind a placeholder');
check($summary['security_level'] === 'private' && $summary['protected'] === true, 'level + protected are cleartext on the summary');
$threw_locked = false;
try {
    $fresh = new AiConversationMessage((int)$msg->key, TRUE);
    $fresh->get('aim_content');
} catch (VaultLockedException $e) { $threw_locked = true; }
check($threw_locked, 'reading a sealed field with no open window raises VaultLockedException, never ciphertext');

// ---- Fortress local-only enforcement -------------------------------------
section('Fortress local-only');
$fort = new AiConversation(NULL);
$fort->set('aic_owner_user_id', $uid);
$fort->set('aic_security_level', AiConversation::LEVEL_FORTRESS);
$fort->set('aic_model', 'claude-haiku-4-5');   // a cloud model
$fort->save();
harness_register_row('aic_conversations', 'aic_conversation_id', (int)$fort->key);
$rejected = false;
try { LlmProviderFactory::forConversation($fort); } catch (LlmProviderException $e) { $rejected = true; }
check($rejected, 'a Fortress chat on a cloud model is rejected at the provider choke point');
// A Fortress chat on a local model resolves fine. The local provider is built
// from joinery_ai_local_model, which ships empty and is operator-configured —
// so reading whatever this box has would make the check fail on an unconfigured
// box while the routing under test is working correctly. Pin it instead: what
// is being asserted is that Fortress routes to the local provider, not that a
// particular host happens to be set up.
harness_set_setting_mem('joinery_ai_local_model', 'qwen3:4b-instruct');
$fort->set('aic_model', 'qwen3:4b-instruct');
$ok_local = false;
try { LlmProviderFactory::forConversation($fort); $ok_local = true; } catch (Throwable $e) {}
check($ok_local, 'a Fortress chat on a local model resolves to the local provider');

// ---- Rotation re-seals the chat DEKs -------------------------------------
section('Rotation re-seal');
$callbacks = VaultUnlock::resealCallbacks();   // triggers loadConsumerBootstraps()
check(count($callbacks) >= 1, 'a chat re-seal callback is registered via the bootstrap');
foreach ($callbacks as $cb) { call_user_func($cb, $uid, $kp1['secret'], 1, $kp2['public'], 2); }

$raw_msg2 = (function () use ($db, $msg) {
    $s = $db->prepare('SELECT * FROM aim_conversation_messages WHERE aim_message_id = ?');
    $s->execute([(int)$msg->key]); return $s->fetch(PDO::FETCH_ASSOC);
})();
$raw_conv2 = (function () use ($db, $conv) {
    $s = $db->prepare('SELECT * FROM aic_conversations WHERE aic_conversation_id = ?');
    $s->execute([(int)$conv->key]); return $s->fetch(PDO::FETCH_ASSOC);
})();
check((int)$raw_msg2['aim_key_generation'] === 2, 'message DEK moved to generation 2');
check((int)$raw_conv2['aic_key_generation'] === 2, 'conversation DEK moved to generation 2');
// The new sealed key opens under the NEW secret and yields the same content.
$dek2 = $crypto->openItemDek((string)$raw_msg2['aim_sealed_key'], $kp2['secret']);
$plain2 = $crypto->openField((string)$raw_msg2['aim_content'], $dek2, ChatSeal::messageAd((int)$msg->key, 'aim_content'));
check($plain2 === 'The target is undervalued at 4x EBITDA.', 'content re-seals to the new key with identical plaintext');
// The OLD secret no longer opens the re-sealed key.
$old_fails = false;
try { $crypto->openItemDek((string)$raw_msg2['aim_sealed_key'], $kp1['secret']); } catch (Throwable $e) { $old_fails = true; }
check($old_fails, 'the old key no longer opens the re-sealed DEK');

// ---- In-window decrypt via the get() hook (needs APCu) -------------------
section('In-window decrypt (get() hook)');
if (!vault_apcu_usable() || !$has_session || session_id() === '') {
    harness_skip('APCu/session unavailable (run with -d apc.enable_cli=1) — window-based decrypt path skipped');
} else {
    // Open the window with the CURRENT (gen-2) secret, since rotation moved the DEKs.
    VaultUnlock::open($uid, $kp2['secret'], UserEncryptionVault::SCOPE_USER);
    $c2 = new AiConversation((int)$conv->key, TRUE);
    check(trim((string)$c2->get('aic_title')) === 'Merger due diligence', 'get() decrypts aic_title in-window');
    $m2 = new AiConversationMessage((int)$msg->key, TRUE);
    check((string)$m2->get('aim_content') === 'The target is undervalued at 4x EBITDA.', 'get() decrypts aim_content in-window');
    $summary_open = ChatSerializer::conversationSummary($c2);
    check(($summary_open['locked'] ?? false) === false && $summary_open['title'] === 'Merger due diligence',
        'conversationSummary reveals the real title once unlocked');
    // One window, both consumers: the same secretKey serves any consumer.
    check(VaultUnlock::secretKey($uid, UserEncryptionVault::SCOPE_USER) === $kp2['secret'],
        'the one open window serves every server-custody consumer (mail + chat share it)');
    VaultUnlock::close($uid, UserEncryptionVault::SCOPE_USER);
}

harness_finish();
?>
