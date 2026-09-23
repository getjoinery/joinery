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
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/conversation_messages_class.php'));
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
harness_register_row('aim_conversation_messages', 'aim_conversation_message_id', (int)$msg->key);
$turn_cols = ChatSeal::turnColumns($conv, (int)$msg->key, 'The target is undervalued at 4x EBITDA.',
    [['name' => 'query_model', 'is_error' => false]]);
$turn_cols['aim_status'] = AiConversationMessage::STATUS_COMPLETE;
AiConversationMessage::updateColumns((int)$msg->key, $turn_cols);

// ---- Seal at rest: raw SQL shows ciphertext ------------------------------
section('Seal at rest');
$db = DbConnector::get_instance()->get_db_link();
$raw_msg = (function () use ($db, $msg) {
    $s = $db->prepare('SELECT * FROM aim_conversation_messages WHERE aim_conversation_message_id = ?');
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
$dek = $crypto->openItemDek((string)$raw_msg['aim_sealed_key'], vault_fixture_key($kp1['secret']));
$plain = $crypto->openField((string)$raw_msg['aim_content'], $dek, ChatSeal::messageAd((int)$msg->key, 'aim_content'));
check($plain === 'The target is undervalued at 4x EBITDA.', 'aim_content decrypts to the original plaintext');
$tcjson = $crypto->openField((string)$raw_msg['aim_tool_calls'], $dek, ChatSeal::messageAd((int)$msg->key, 'aim_tool_calls'));
$tc = json_decode($tcjson, true);
check(is_array($tc) && $tc[0]['name'] === 'query_model', 'aim_tool_calls decrypts + json_decodes to the trace');
$cdek = $crypto->openItemDek((string)$raw_conv['aic_sealed_key'], vault_fixture_key($kp1['secret']));
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

// ---- Local models only add-on -------------------------------------------
section('Local models only');
$fort = new AiConversation(NULL);
$fort->set('aic_owner_user_id', $uid);
$fort->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
$fort->set('aic_local_models_only', true);
$fort->set('aic_model', 'claude-haiku-4-5');   // a cloud model
$fort->save();
harness_register_row('aic_conversations', 'aic_conversation_id', (int)$fort->key);
check(ChatSeal::levels() === [ChatSeal::LEVEL_STANDARD, ChatSeal::LEVEL_PRIVATE],
	'chat offers exactly two levels, Standard and Private');
check($fort->localModelsOnly(), 'a Private chat with the flag carries the Local models only add-on');
// The add-on is enforced by the RESOLVER: the requirement carries a local
// trust floor, so nothing off the box is even a candidate.
harness_set_setting_mem('joinery_ai_local_model', 'qwen3:4b-instruct');
AiEndpointRegistry::clearCache();
$fort_req = AiModelRequirementBuilder::forConversation($fort);
check($fort_req->trustFloor() === AiModelRequirement::TRUST_LOCAL,
	'a local-only chat carries a local trust floor, taken from the add-on');

$rejected = false;
try {
	$fort_res = AiModelResolver::resolve($fort_req);
	// A cloud PIN on a local-only chat must not simply be routed around:
	// nothing it resolves to may leave the box, whatever the pin said.
	$rejected = !$fort_res->isLocal();
} catch (LlmProviderException $e) { $rejected = false; }
check(!$rejected, 'and a local-only chat can never resolve onto a cloud model');

$fort->set('aic_model', 'qwen3:4b-instruct');
$ok_local = false;
try {
	$ok_local = AiModelResolver::resolve(
		AiModelRequirementBuilder::forConversation($fort))->isLocal();
} catch (Throwable $e) {}
check($ok_local, 'a local-only chat on a local model resolves to the local endpoint');

// Lowered to Standard the stored flag stays (one-way) but is inert.
$inert = clone $fort;
$inert->set('aic_security_level', AiConversation::LEVEL_STANDARD);
check(!$inert->localModelsOnly()
	&& AiModelRequirementBuilder::forConversation($inert)->trustFloor() !== AiModelRequirement::TRUST_LOCAL,
	'on a Standard chat the stored flag is inert — no local floor');

// An unconverted row (still stored as the legacy value until migration
// aic_001 runs) reads exactly as before: protected, local-only, local floor.
$unmigrated = new AiConversation(NULL);
$unmigrated->set('aic_owner_user_id', $uid);
$unmigrated->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
$unmigrated->set('aic_model', 'claude-haiku-4-5');
$unmigrated->save();
harness_register_row('aic_conversations', 'aic_conversation_id', (int)$unmigrated->key);
AiConversation::updateColumns((int)$unmigrated->key,
	['aic_security_level' => AiConversation::LEGACY_LEVEL_LOCAL_ONLY]);
$unmigrated = new AiConversation((int)$unmigrated->key, TRUE);
check($unmigrated->isProtected() && ChatSeal::isProtectedLevel($unmigrated->get('aic_security_level'))
	&& $unmigrated->level() === AiConversation::LEVEL_PRIVATE,
	'an unconverted legacy row is protected and reads as Private');
check($unmigrated->localModelsOnly()
	&& AiModelRequirementBuilder::forConversation($unmigrated)->trustFloor() === AiModelRequirement::TRUST_LOCAL,
	'an unconverted legacy row is local-only and carries the local floor');
check(MultiAiConversation::ownerHasProtected($uid), 'the protected-chat SQL still counts unconverted rows');

// One-way: once on, turning it off is refused and the flag stays.
$off = ChatLevel::setLocalModelsOnly($fort, false, $uid);
$fort_row = new AiConversation((int)$fort->key, TRUE);
check(!$off['ok'] && (bool)$fort_row->get('aic_local_models_only'),
	'turning Local models only off once on is refused, and the flag stays on');

// Turning it on for a Private chat pins the model to a local one.
$plain = new AiConversation(NULL);
$plain->set('aic_owner_user_id', $uid);
$plain->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
$plain->set('aic_model', 'claude-haiku-4-5');
$plain->save();
harness_register_row('aic_conversations', 'aic_conversation_id', (int)$plain->key);
$plain->load();
$on = ChatLevel::setLocalModelsOnly($plain, true, $uid);
$plain_row = new AiConversation((int)$plain->key, TRUE);
check($on['ok'] && (bool)$plain_row->get('aic_local_models_only'),
	'turning Local models only on for a Private chat stores the flag');
check(ChatLevel::isLocalModel((string)$plain_row->get('aic_model')),
	'and pins the chat model to a local one');

// It lives under Private: a Standard chat is refused.
$std = new AiConversation(NULL);
$std->set('aic_owner_user_id', $uid);
$std->set('aic_security_level', AiConversation::LEVEL_STANDARD);
$std->save();
harness_register_row('aic_conversations', 'aic_conversation_id', (int)$std->key);
$std->load();
$std_on = ChatLevel::setLocalModelsOnly($std, true, $uid);
check(!$std_on['ok'], 'Local models only is refused on a Standard chat');

// Refused without a local model: a catalog with no local endpoint.
$scratch = harness_scratch_dir('no_local_catalog');
$endpoints = json_decode(file_get_contents(PathHelper::getIncludePath('plugins/joinery_ai/ai_endpoints.json')), true);
$endpoints['endpoints'] = array_values(array_filter($endpoints['endpoints'],
	function ($e) { return ($e['trust'] ?? '') !== 'local'; }));
file_put_contents($scratch . '/ai_endpoints.json', json_encode($endpoints));
$prev_catalog = AiEndpointRegistry::useCatalogFiles($scratch . '/ai_endpoints.json', null);
try {
	check(!ChatLevel::localModelConfigured(), 'fixture: a catalog with no local endpoint serves no local model');
	$nolocal = new AiConversation(NULL);
	$nolocal->set('aic_owner_user_id', $uid);
	$nolocal->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
	$nolocal->save();
	harness_register_row('aic_conversations', 'aic_conversation_id', (int)$nolocal->key);
	$nolocal->load();
	$nl = ChatLevel::setLocalModelsOnly($nolocal, true, $uid);
	$nolocal_row = new AiConversation((int)$nolocal->key, TRUE);
	check(!$nl['ok'] && !(bool)$nolocal_row->get('aic_local_models_only'),
		'Local models only is refused when no local model is configured');
	check(ChatLevel::resolveLocalOnlyForNew('1', AiConversation::LEVEL_PRIVATE) === false,
		'a new chat asking for Local models only without a local model starts without it');
} finally {
	AiEndpointRegistry::useCatalogFiles($prev_catalog[0] ?? null, $prev_catalog[1] ?? null);
}
check(ChatLevel::resolveLocalOnlyForNew('1', AiConversation::LEVEL_STANDARD) === false,
	'a new Standard chat never starts with Local models only');

// ---- New-chat level resolution -------------------------------------------
section('New-chat level resolution');
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSend.php'));
harness_set_setting_mem('joinery_ai_local_model', 'qwen3:4b-instruct');
AiEndpointRegistry::clearCache();
check(ChatLevel::localModelConfigured(), 'fixture: a local model is configured');

// B4: a site default still stored under the legacy name (before aic_001 runs)
// means Private pinned to a local model — not Standard with no pin.
harness_set_setting_mem('joinery_ai_default_chat_level', AiConversation::LEGACY_LEVEL_LOCAL_ONLY);
harness_set_setting_mem('joinery_ai_default_chat_local_only', '0');
check(ChatLevel::defaultLevel() === AiConversation::LEVEL_PRIVATE,
	'a legacy default level reads as Private');
check(ChatLevel::defaultLocalOnly() === true,
	'a legacy default level turns the Local models only default on, whatever the unseeded flag says');
$lvl_new = ChatLevel::resolveForNew(null, $uid);
check($lvl_new === AiConversation::LEVEL_PRIVATE
	&& ChatLevel::resolveLocalOnlyForNew(null, $lvl_new) === true,
	'under a legacy default a new chat starts Private with Local models only');
harness_set_setting_mem('joinery_ai_default_chat_level', AiConversation::LEVEL_STANDARD);
check(ChatLevel::defaultLocalOnly() === false, 'a current default level leaves the add-on default to its own setting');

// B7: a level the caller ASKED for that chat doesn't offer is refused, not
// silently replaced by the default.
check(ChatLevel::resolveForNew('privat', $uid) === null, 'a mistyped requested level is refused');
check(ChatLevel::resolveForNew(ProtectionLevel::FORTRESS . 'x', $uid) === null, 'an unknown requested level is refused');
check(ChatLevel::resolveForNew('', $uid) === ChatLevel::defaultLevel(), 'no requested level takes the default');
check(ChatLevel::resolveForNew('Private', $uid) === AiConversation::LEVEL_PRIVATE, 'a requested level is read case-insensitively');
// An older page posts the retired name (and no add-on field) for "sealed +
// pinned to a local model": read as Private with the add-on, not refused.
$legacy_req = ChatLevel::resolveForNew('fortress', $uid);
check($legacy_req === AiConversation::LEVEL_PRIVATE
	&& ChatLevel::resolveLocalOnlyForNew(null, $legacy_req, 'fortress') === true,
	'an older client\'s retired level name starts a Private, Local-models-only chat');
$built_bad = ChatSend::buildNewConversation($uid, ['security_level' => 'bogus'], 'hello');
check(isset($built_bad['error']) && !isset($built_bad['conversation']),
	'building a new chat at an unknown level returns an error, builds nothing');
$built_old = ChatSend::buildNewConversation($uid, ['security_level' => 'fortress'], 'hello');
check(($built_old['level'] ?? '') === AiConversation::LEVEL_PRIVATE
	&& isset($built_old['conversation']) && $built_old['conversation']->localModelsOnly()
	&& ChatLevel::isLocalModel((string)$built_old['conversation']->get('aic_model')),
	'a new chat from an older client\'s retired level is Private, local-only, on a local model');

// ---- Chat control writes need the CSRF proof -----------------------------
// The page sets controls through the /api/v1 action with the browser-session
// credential; a request without X-Joinery-Csrf is refused, and no CSRF-less
// web endpoint remains beside it.
section('Chat control CSRF');
require_once(PathHelper::getIncludePath('tests/lib/http.php'));
$view_src = file_get_contents(PathHelper::getIncludePath('plugins/joinery_ai/includes/chat_view_body.php'));
check(strpos($view_src, "'chat_set_capabilities'") === false
	&& strpos($view_src, "joaiApiV1('joinery_ai/chat_set_capabilities'") !== false,
	'the chat page sets controls only through the /api/v1 action');
check(!file_exists(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_set_capabilities.php'))
	&& !file_exists(PathHelper::getIncludePath('plugins/joinery_ai/views/profile/chat_set_capabilities.php')),
	'no CSRF-less web endpoint for chat controls remains');

$csrf_suffix = 'ChatCsrf' . substr(md5(uniqid('', true)), 0, 6);
$csrf_user = make_user($csrf_suffix);
$csrf_conv = new AiConversation(NULL);
$csrf_conv->set('aic_owner_user_id', (int)$csrf_user->key);
$csrf_conv->set('aic_security_level', AiConversation::LEVEL_STANDARD);
$csrf_conv->set('aic_temperature', 0.7);
$csrf_conv->save();
harness_register_row('aic_conversations', 'aic_conversation_id', (int)$csrf_conv->key);
$jar = harness_jar_new('jychat');
$token = harness_web_login($jar, $csrf_user->get('usr_email'), 'TestPassword_' . $csrf_suffix);
if ($token === null) {
	harness_skip('web login unavailable for the test user — HTTP CSRF checks skipped');
} else {
	$url = '/api/v1/action/joinery_ai/chat_set_capabilities';
	$temp_now = function () use ($db, $csrf_conv) {
		return (float)$db->query('SELECT aic_temperature FROM aic_conversations WHERE aic_conversation_id = '
			. (int)$csrf_conv->key)->fetchColumn();
	};
	$r = harness_request('POST', $url, ['jar' => $jar,
		'body' => ['conversation_id' => (int)$csrf_conv->key, 'field' => 'temperature', 'value' => '0.3']]);
	check($r['status'] === 403 && abs($temp_now() - 0.7) < 0.001,
		'a control write without X-Joinery-Csrf is refused (403) and changes nothing', 'status ' . $r['status'] . ' ' . $r['raw']);
	$r = harness_request('POST', $url, ['jar' => $jar, 'headers' => harness_csrf_header(str_repeat('0', 64)),
		'body' => ['conversation_id' => (int)$csrf_conv->key, 'field' => 'temperature', 'value' => '0.3']]);
	check($r['status'] === 403 && abs($temp_now() - 0.7) < 0.001,
		'a control write with a wrong token is refused (403)', 'status ' . $r['status']);
	$r = harness_request('POST', $url, ['jar' => $jar, 'headers' => harness_csrf_header($token),
		'body' => ['conversation_id' => (int)$csrf_conv->key, 'field' => 'temperature', 'value' => '0.3']]);
	check($r['status'] === 200 && abs($temp_now() - 0.3) < 0.001,
		'the same write with the page\'s token succeeds', 'status ' . $r['status'] . ' ' . $r['raw']);
	$r = harness_request('POST', '/profile/joinery_ai/chat_set_capabilities', ['jar' => $jar, 'encode' => 'form',
		'body' => ['conversation_id' => (int)$csrf_conv->key, 'field' => 'temperature', 'value' => '0.9']]);
	check(abs($temp_now() - 0.3) < 0.001 && empty($r['json']['success']),
		'the old web endpoint path writes nothing', 'status ' . $r['status']);

	// B7 surfaces as an ordinary error to the API caller, not a crash.
	if ((string)Globalvars::get_instance()->get_setting('joinery_ai_chat_enabled')) {
		$r = harness_request('POST', '/api/v1/action/joinery_ai/chat_send', ['jar' => $jar,
			'headers' => harness_csrf_header($token),
			'body' => ['message' => 'hello', 'security_level' => 'bogus']]);
		check($r['status'] === 422 && stripos((string)($r['json']['error'] ?? ''), 'privacy level') !== false,
			'chat_send at an unknown level answers a normal 422 error naming the level', 'status ' . $r['status'] . ' ' . $r['raw']);
	} else {
		harness_skip('chat disabled on this site — chat_send error-shape check skipped');
	}
}

// ---- Migration: the retired third level folds into Private + add-on ------
// Run inside a transaction that is rolled back, so neither the fixture row
// nor the setting write outlives the check.
section('Fold migration');
$migration = null;
foreach (require(PathHelper::getIncludePath('plugins/joinery_ai/migrations/migrations.php')) as $m) {
	if (($m['id'] ?? '') === 'aic_001_fold_fortress_into_local_models_only') $migration = $m;
}
check($migration !== null, 'the fold migration is declared');
if ($migration !== null) {
	$db->beginTransaction();
	try {
		$legacy = new AiConversation(NULL);
		$legacy->set('aic_owner_user_id', $uid);
		$legacy->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
		$legacy->save();
		AiConversation::updateColumns((int)$legacy->key, ['aic_security_level' => 'fortress']);
		Setting::put('joinery_ai_default_chat_level', 'fortress');
		Setting::put('joinery_ai_default_chat_local_only', '0');

		ob_start();
		$first = $migration['up'](DbConnector::get_instance());
		$out1 = ob_get_clean();
		$row = $db->query('SELECT aic_security_level, aic_local_models_only FROM aic_conversations WHERE aic_conversation_id = '
			. (int)$legacy->key)->fetch(PDO::FETCH_ASSOC);
		check($first !== 'defer' && $row['aic_security_level'] === 'private' && $row['aic_local_models_only'] === true,
			'a stored fortress chat becomes Private with Local models only on');
		$st = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
		$st->execute(['joinery_ai_default_chat_level']);
		$lvl = (string)$st->fetchColumn();
		$st->execute(['joinery_ai_default_chat_local_only']);
		$lo = (string)$st->fetchColumn();
		check($lvl === 'private' && $lo === '1',
			'a fortress default level becomes private with the local-only default on');
		check(strpos($out1, 'conversation(s) moved') !== false, 'the migration reports what it changed');

		ob_start();
		$migration['up'](DbConnector::get_instance());
		$out2 = ob_get_clean();
		$left = (int)$db->query("SELECT COUNT(*) FROM aic_conversations WHERE aic_security_level = 'fortress'")->fetchColumn();
		check($left === 0 && strpos($out2, 'aic_conversations: 0 ') !== false
			&& strpos($out2, 'no change') !== false,
			'a second run changes nothing (idempotent)');
	} finally {
		$db->rollBack();
	}
}

// ---- Rotation re-seals the chat DEKs -------------------------------------
section('Rotation re-seal');
$callbacks = VaultUnlock::resealCallbacks();   // triggers loadConsumerBootstraps()
check(count($callbacks) >= 1, 'a chat re-seal callback is registered via the bootstrap');
foreach ($callbacks as $cb) { call_user_func($cb, $uid, vault_fixture_key($kp1['secret']), 1, $kp2['public'], 2); }

$raw_msg2 = (function () use ($db, $msg) {
    $s = $db->prepare('SELECT * FROM aim_conversation_messages WHERE aim_conversation_message_id = ?');
    $s->execute([(int)$msg->key]); return $s->fetch(PDO::FETCH_ASSOC);
})();
$raw_conv2 = (function () use ($db, $conv) {
    $s = $db->prepare('SELECT * FROM aic_conversations WHERE aic_conversation_id = ?');
    $s->execute([(int)$conv->key]); return $s->fetch(PDO::FETCH_ASSOC);
})();
check((int)$raw_msg2['aim_key_generation'] === 2, 'message DEK moved to generation 2');
check((int)$raw_conv2['aic_key_generation'] === 2, 'conversation DEK moved to generation 2');
// The new sealed key opens under the NEW secret and yields the same content.
$dek2 = $crypto->openItemDek((string)$raw_msg2['aim_sealed_key'], vault_fixture_key($kp2['secret']));
$plain2 = $crypto->openField((string)$raw_msg2['aim_content'], $dek2, ChatSeal::messageAd((int)$msg->key, 'aim_content'));
check($plain2 === 'The target is undervalued at 4x EBITDA.', 'content re-seals to the new key with identical plaintext');
// The OLD secret no longer opens the re-sealed key.
$old_fails = false;
try { $crypto->openItemDek((string)$raw_msg2['aim_sealed_key'], vault_fixture_key($kp1['secret'])); } catch (Throwable $e) { $old_fails = true; }
check($old_fails, 'the old key no longer opens the re-sealed DEK');

// ---- In-window decrypt via the get() hook (needs APCu) -------------------
section('In-window decrypt (get() hook)');
if (!vault_apcu_usable() || !$has_session || session_id() === '') {
    harness_skip('APCu/session unavailable (run with -d apc.enable_cli=1) — window-based decrypt path skipped');
} else {
    // Open the window with the CURRENT (gen-2) secret, since rotation moved the DEKs.
    vault_fixture_open_window($uid, $kp2['secret'], UserEncryptionVault::SCOPE_USER);
    $c2 = new AiConversation((int)$conv->key, TRUE);
    check(trim((string)$c2->get('aic_title')) === 'Merger due diligence', 'get() decrypts aic_title in-window');
    $m2 = new AiConversationMessage((int)$msg->key, TRUE);
    check((string)$m2->get('aim_content') === 'The target is undervalued at 4x EBITDA.', 'get() decrypts aim_content in-window');
    $summary_open = ChatSerializer::conversationSummary($c2);
    check(($summary_open['locked'] ?? false) === false && $summary_open['title'] === 'Merger due diligence',
        'conversationSummary reveals the real title once unlocked');
    // One window, both consumers: the same secretKey serves any consumer.
    $shared = VaultUnlock::secretKey($uid, UserEncryptionVault::SCOPE_USER);
    check($shared instanceof VaultKey && $shared->id() === vault_fixture_key($kp2['secret'])->id(),
        'the one open window serves every server-custody consumer (mail + chat share it)');
    VaultUnlock::close($uid, UserEncryptionVault::SCOPE_USER);
}

harness_finish();
?>
