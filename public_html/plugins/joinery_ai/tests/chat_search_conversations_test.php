<?php
/** @joinery-test
 * name: joinery_ai_chat_search_conversations
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
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/recipe_tools/SearchConversationsTool.php'));

$box = new SealedBox();
$has_session = vault_ensure_session();

// A locally-served model id and a cloud one. "Local" is now a lookup in the
// shipped catalog rather than a guess from the id's shape, so the local one has
// to actually be served — an id nothing declares is deliberately NOT classified
// local, which is what stops a Fortress chat trusting a name nobody recognises.
$LOCAL_MODEL  = 'qwen3:4b-instruct';
$REMOTE_MODEL = 'claude-haiku-4-5';
harness_set_setting_mem('joinery_ai_local_model', $LOCAL_MODEL);
AiEndpointRegistry::clearCache();

// ---- Helpers -------------------------------------------------------------
$make_standard = function (int $owner, string $title, string $body): AiConversation {
    $c = new AiConversation(NULL);
    $c->set('aic_owner_user_id', $owner);
    $c->set('aic_security_level', AiConversation::LEVEL_STANDARD);
    $c->set('aic_title', $title);
    $c->set('aic_model', 'qwen3:4b-instruct');
    $c->save();
    $c->load();
    harness_register_row('aic_conversations', 'aic_conversation_id', (int)$c->key);
    if ($body !== '') {
        $m = new AiConversationMessage(NULL);
        $m->set('aim_aic_conversation_id', (int)$c->key);
        $m->set('aim_role', AiConversationMessage::ROLE_USER);
        $m->set('aim_content', $body);
        $m->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
        $m->save();
        harness_register_row('aim_conversation_messages', 'aim_message_id', (int)$m->key);
    }
    return $c;
};

// Seal a protected conversation's title + one message under the owner's vault key.
$make_sealed = function (int $owner, string $title, string $body): AiConversation {
    $c = new AiConversation(NULL);
    $c->set('aic_owner_user_id', $owner);
    $c->set('aic_security_level', AiConversation::LEVEL_PRIVATE);
    $c->set('aic_model', 'qwen3:4b-instruct');
    $c->save();
    $c->load();
    harness_register_row('aic_conversations', 'aic_conversation_id', (int)$c->key);
    AiConversation::updateColumns((int)$c->key,
        ChatSeal::sealConversationColumns((int)$c->key, $c, ['aic_title' => $title, 'aic_instructions' => '']));
    if ($body !== '') {
        $m = new AiConversationMessage(NULL);
        $m->set('aim_aic_conversation_id', (int)$c->key);
        $m->set('aim_role', AiConversationMessage::ROLE_USER);
        $m->set('aim_content', '');
        $m->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
        $m->save();
        $m->load();
        harness_register_row('aim_conversation_messages', 'aim_message_id', (int)$m->key);
        $tcols = ChatSeal::turnColumns($c, (int)$m->key, $body, []);
        $tcols['aim_status'] = AiConversationMessage::STATUS_COMPLETE;
        AiConversationMessage::updateColumns((int)$m->key, $tcols);
    }
    $c->load();
    return $c;
};

// ---- Fixtures ------------------------------------------------------------
$userA = make_user('SearchA', 5);
$uidA  = (int)$userA->key;
$userB = make_user('SearchB', 5);
$uidB  = (int)$userB->key;

$kp = $box->generateKeypair();
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $uidA);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

// A: one standard chat (searchable plaintext) + one protected chat (sealed).
$make_standard($uidA, 'Mac Studio memory planning', 'we capped the Ollama context window at 24k tokens');
$make_sealed($uidA, 'Compensation review', 'the quarterly bonus figures are attached');

// B: a standard chat with a distinctive keyword, to prove owner-scoping.
$make_standard($uidB, 'Vendor shortlist', 'the Ollama context window came up again here');

// ==========================================================================
section('Standard search + snippet (remote turn)');
// surface_protected=false stands in for a remote turn: standard matches returned,
// protected only acknowledged.
$r = MultiAiConversation::searchForTool($uidA, 'Ollama context', 10, false);
$std = array_values(array_filter($r['matches'], fn($m) => $m['level'] === 'standard'));
check(count($std) === 1, 'one standard match for user A', 'got ' . count($std));
if ($std) {
    check($std[0]['title'] === 'Mac Studio memory planning', 'match carries the title');
    check((int)$std[0]['id'] > 0, 'match carries a conversation id');
    check($std[0]['date'] !== '', 'match carries a date');
    check(stripos($std[0]['snippet'], '24k') !== false, 'snippet is a window around the query', $std[0]['snippet']);
}

section('Owner-scoping');
// User A's search must never surface user B's thread, even on a shared keyword.
$ids = array_map(fn($m) => (int)$m['id'], $r['matches']);
$brows = new MultiAiConversation(['owner_user_id' => $uidB, 'deleted' => false], []);
$brows->load();
$b_ids = [];
foreach ($brows as $bc) $b_ids[] = (int)$bc->key;
check(count(array_intersect($ids, $b_ids)) === 0, 'user A results contain none of user B\'s conversations');
$rb = MultiAiConversation::searchForTool($uidB, 'quarterly bonus', 10, false);
check(count($rb['matches']) === 0, 'user B cannot find user A\'s protected content by keyword');

section('Protected acknowledged, never leaked (locked / remote)');
// In plain CLI the vault has no open window → locked. Protected content is not
// scanned; the owner is told, and standard search is unaffected.
check($r['protected_withheld'] === true, 'protected chats reported as withheld');
check($r['locked'] === true, 'withheld reason is the locked vault (no open window in CLI)');
$has_protected_match = (bool)array_filter($r['matches'], fn($m) => $m['level'] !== 'standard');
check(!$has_protected_match, 'no protected chat appears as a result row');

section('No metadata oracle (acceptance #8)');
// Two different queries on a withheld (remote/locked) turn must yield identical
// protected-acknowledgment signals — one that WOULD match sealed content, one that
// would not. Neither reveals a count or varies with the query.
$hit  = MultiAiConversation::searchForTool($uidA, 'quarterly bonus', 10, false);  // matches sealed body
$miss = MultiAiConversation::searchForTool($uidA, 'zzz-nonexistent-term', 10, false);
check($hit['protected_withheld'] === $miss['protected_withheld']
   && $hit['locked'] === $miss['locked']
   && $hit['protected_capped'] === $miss['protected_capped'],
   'withheld/locked/capped signals are identical for a matching vs non-matching query');
// And the rendered note is byte-identical (the tool formats fixed strings).
$fmt = new ReflectionMethod('SearchConversationsTool', 'format');
$note_hit  = $fmt->invoke(null, ['matches'=>[], 'protected_withheld'=>true, 'protected_capped'=>false, 'locked'=>true], 'UTC');
$note_miss = $fmt->invoke(null, ['matches'=>[], 'protected_withheld'=>true, 'protected_capped'=>false, 'locked'=>true], 'UTC');
check($note_hit === $note_miss, 'the withheld note is byte-identical regardless of the query');
check(!preg_match('/\d/', preg_replace('/No matching[^\n]*/', '', $note_hit)), 'the withheld note carries no count digits');

section('No matches');
$none = MultiAiConversation::searchForTool($uidA, 'zzz-nothing-here-xyz', 10, false);
check(count($none['matches']) === 0, 'a non-matching query returns no matches');
$none_note = $fmt->invoke(null, $none, 'UTC');
check(stripos($none_note, 'No matching conversations') !== false, 'the tool renders an explicit no-matches line');

section('Excludes the active conversation (§7)');
// A conversation that matches the query is dropped from its own results when the
// caller passes its id (so the model never "finds" the thread it is already in).
$self_conv = $make_standard($uidA, 'Self exclude probe', 'unique-selfexclude-keyword lives here');
$self_id   = (int)$self_conv->key;
$inc = MultiAiConversation::searchForTool($uidA, 'unique-selfexclude-keyword', 10, false, 0);
check((bool)array_filter($inc['matches'], fn($m) => (int)$m['id'] === $self_id),
    'without an exclusion the conversation is found');
$exc = MultiAiConversation::searchForTool($uidA, 'unique-selfexclude-keyword', 10, false, $self_id);
check(!array_filter($exc['matches'], fn($m) => (int)$m['id'] === $self_id),
    'the active conversation is excluded when its id is passed');

section('Capability gating (acceptance #5, gate independence)');
$rm = new ReflectionMethod('ChatRunner', 'resolveAllowedTools');
$gate_conv = $make_standard($uidA, 'Gate probe', '');
$ctxA = new ChatTurnContext($gate_conv, $uidA);

AiConversation::updateColumns((int)$gate_conv->key, ['aic_history_access' => false, 'aic_data_access' => false]);
$gate_conv->load();
$tools_off = $rm->invoke(null, $gate_conv, $ctxA);
check(!in_array('search_conversations', $tools_off, true), 'search_conversations absent when history access is off');

AiConversation::updateColumns((int)$gate_conv->key, ['aic_history_access' => true]);
$gate_conv->load();
$tools_on = $rm->invoke(null, $gate_conv, new ChatTurnContext($gate_conv, $uidA));
check(in_array('search_conversations', $tools_on, true), 'search_conversations offered when history access is on');

// Independence from data access: history on + data off still offers it; data on
// alone (history off) does not.
AiConversation::updateColumns((int)$gate_conv->key, ['aic_history_access' => true, 'aic_data_access' => true]);
$gate_conv->load();
$tools_both = $rm->invoke(null, $gate_conv, new ChatTurnContext($gate_conv, $uidA));
check(in_array('search_conversations', $tools_both, true), 'history access is independent of data access (both on)');

AiConversation::updateColumns((int)$gate_conv->key, ['aic_history_access' => false, 'aic_data_access' => true]);
$gate_conv->load();
$tools_dataonly = $rm->invoke(null, $gate_conv, new ChatTurnContext($gate_conv, $uidA));
check(!in_array('search_conversations', $tools_dataonly, true), 'data access alone does not offer search_conversations');

section('ChatControls validation');
[$col, $stored] = ChatControls::validate('history_access', '1');
check($col === 'aic_history_access' && $stored === 't', 'history_access validates to the column with a truthy store');
[$col2, $stored2] = ChatControls::validate('history_access', '0');
check($stored2 === 'f', 'a falsey history_access stores f');

section('Tool guard: chat-only + empty query');
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
$tool = new SearchConversationsTool();
$chat_ctx = new ChatTurnContext($gate_conv, $uidA);
$empty = $tool->execute(['query' => '   '], $chat_ctx);
check(is_array($empty) && !empty($empty['is_error']), 'an empty query is a tool error, not a search');

// The remote-turn execute() path renders the fixed withheld note and no protected
// content — end to end through the tool.
$remote_conv = $make_standard($uidA, 'Remote turn probe', 'talking about the Ollama context window here');
AiConversation::updateColumns((int)$remote_conv->key, ['aic_model' => $REMOTE_MODEL]);
$remote_conv->load();
$out_remote = $tool->execute(['query' => 'quarterly bonus'], new ChatTurnContext($remote_conv, $uidA));
$out_remote_text = is_array($out_remote) ? (string)($out_remote['content'] ?? '') : (string)$out_remote;
check(stripos($out_remote_text, 'quarterly bonus figures') === false, 'protected body never appears on a remote turn');
check(stripos($out_remote_text, 'Compensation review') === false, 'protected title never appears on a remote turn');
check(stripos($out_remote_text, 'protected conversations') !== false, 'the fixed protected-history note is present on a remote turn');

// ==========================================================================
// Surfaced (local + open vault) path — needs an unlock window (APCu + session).
section('Surfaced protected path (local + vault open)');
if (!vault_apcu_usable() || !$has_session || session_id() === '') {
    harness_skip('APCu/session unavailable (run with -d apc.enable_cli=1) — surfaced protected path skipped');
} else {
    VaultUnlock::open($uidA, $kp['secret'], UserEncryptionVault::SCOPE_USER);

    // Seam, surfaced: the protected chat is decrypted and returned.
    $sr = MultiAiConversation::searchForTool($uidA, 'quarterly bonus', 10, true);
    $prot = array_values(array_filter($sr['matches'], fn($m) => $m['level'] !== 'standard'));
    check(count($prot) === 1, 'protected chat is surfaced on a local + unlocked turn', 'got ' . count($prot));
    if ($prot) {
        check($prot[0]['title'] === 'Compensation review', 'surfaced protected match carries the decrypted title');
        check(stripos($prot[0]['snippet'], 'bonus') !== false, 'surfaced protected match carries a decrypted snippet');
    }
    check($sr['protected_withheld'] === false, 'nothing is withheld on a surfaced turn');

    // Remote turn but vault OPEN: still withheld (model gate), but NOT locked.
    $ro = MultiAiConversation::searchForTool($uidA, 'quarterly bonus', 10, false);
    check($ro['protected_withheld'] === true && $ro['locked'] === false,
        'an open vault on a remote turn withholds without claiming the vault is locked');

    // End-to-end through the tool on a LOCAL model → the protected match surfaces.
    $tool2 = new SearchConversationsTool();
    $local_conv = $make_standard($uidA, 'Local turn probe', '');
    AiConversation::updateColumns((int)$local_conv->key, ['aic_model' => $LOCAL_MODEL]);
    $local_conv->load();
    $out_local = $tool2->execute(['query' => 'quarterly bonus'], new ChatTurnContext($local_conv, $uidA));
    $out_local_text = is_array($out_local) ? (string)($out_local['content'] ?? '') : (string)$out_local;
    check(stripos($out_local_text, 'Compensation review') !== false, 'protected title surfaces on a local turn with the vault open');

    section('Protected match not crowded out by standard (merge ranking)');
    // With more standard matches than the limit, a pinned protected match must still
    // surface — the seam merges both groups and ranks pinned-first, then caps once,
    // rather than letting the standard block fill every slot.
    $kw = 'mergerank-kw';
    for ($i = 0; $i < 3; $i++) $make_standard($uidA, 'MergeStd ' . $i, $kw . ' appears here');
    $prot_pinned = $make_sealed($uidA, 'MergeProt', $kw . ' in a protected thread');
    AiConversation::updateColumns((int)$prot_pinned->key, ['aic_pinned' => true]);
    $mixed = MultiAiConversation::searchForTool($uidA, $kw, 2, true);
    check(count($mixed['matches']) === 2, 'the merged result is capped at the limit', 'got ' . count($mixed['matches']));
    check((bool)array_filter($mixed['matches'], fn($m) => $m['level'] !== 'standard'),
        'a pinned protected match surfaces even with more standard matches than the limit');

    section('Candidate cap (acceptance #7)');
    // Seed more than the scan cap of (title-only) protected chats, then search a
    // non-matching term so the overflow is reported rather than a false empty.
    $cap = MultiAiConversation::PROTECTED_SCAN_CAP;
    for ($i = 0; $i < $cap + 1; $i++) {
        $make_sealed($uidA, 'Capfill ' . $i, '');
    }
    $capped = MultiAiConversation::searchForTool($uidA, 'zzz-cap-nomatch-term', 10, true);
    check($capped['protected_capped'] === true, 'more protected chats than the cap sets protected_capped');

    VaultUnlock::close($uidA, UserEncryptionVault::SCOPE_USER);
}

harness_finish();
?>
