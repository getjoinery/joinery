<?php
/** @joinery-test
 * name: joinery_ai_memory
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('joinery_ai')) { harness_skip('joinery_ai plugin inactive'); harness_finish(); }

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_memories_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatMemory.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/recipe_tools/RememberTool.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/recipe_tools/RecallTool.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/recipe_tools/ForgetTool.php'));

// A locally-served model id vs a cloud one (classification only — neither needs
// to be a configured provider), same convention as the search_conversations test.
// A locally-served model id and a cloud one. "Local" is now a lookup in the
// shipped catalog rather than a guess from the id's shape, so the local one has
// to actually be served — an id nothing declares is deliberately NOT classified
// local, which is what stops a Fortress chat trusting a name nobody recognises.
$LOCAL_MODEL  = 'qwen3:4b-instruct';
$REMOTE_MODEL = 'claude-haiku-4-5';
harness_set_setting_mem('joinery_ai_local_model', $LOCAL_MODEL);
AiEndpointRegistry::clearCache();

// ---- Fixtures --------------------------------------------------------------
$userA = make_user('MemA', 5);
$uidA = (int)$userA->key;
$userB = make_user('MemB', 5);
$uidB = (int)$userB->key;

$make_conversation = function (int $owner, string $level, string $model) {
    $c = new AiConversation(NULL);
    $c->set('aic_owner_user_id', $owner);
    $c->set('aic_security_level', $level);
    $c->set('aic_model', $model);
    $c->set('aic_memory_access', true);
    $c->save();
    $c->load();
    harness_register_row('aic_conversations', 'aic_conversation_id', (int)$c->key);
    return $c;
};

$make_memory = function (?int $owner, string $scope, string $source, string $title, string $content, array $tags = []) {
    $m = new AiMemory(NULL);
    $m->set('mem_scope', $scope);
    $m->set('mem_owner_user_id', $owner);
    $m->set('mem_created_by_user_id', $owner);
    $m->set('mem_source', $source);
    $m->set('mem_title', $title);
    $m->set('mem_content', $content);
    if ($tags) $m->set('mem_tags', $tags);
    $m->prepare();
    $m->save();
    $m->load();
    harness_register_row('mem_memories', 'mem_memory_id', (int)$m->key);
    return $m;
};

$convA = $make_conversation($uidA, AiConversation::LEVEL_STANDARD, $REMOTE_MODEL);
$ctxA = new ChatTurnContext($convA, $uidA);
$convB = $make_conversation($uidB, AiConversation::LEVEL_STANDARD, $REMOTE_MODEL);
$ctxB = new ChatTurnContext($convB, $uidB);

// ==========================================================================
section('Data class validation');
try {
    $bad = new AiMemory(NULL);
    $bad->set('mem_scope', 'org');
    $bad->set('mem_owner_user_id', $uidA);
    $bad->set('mem_content', 'x');
    $bad->prepare();
    check(false, 'invalid scope rejected');
} catch (AiMemoryException $e) {
    check(true, 'invalid scope rejected');
}
try {
    $bad = new AiMemory(NULL);
    $bad->set('mem_scope', AiMemory::SCOPE_SHARED);
    $bad->set('mem_owner_user_id', $uidA);
    $bad->set('mem_content', 'x');
    $bad->save();
    check(false, 'shared row with an owner rejected');
} catch (AiMemoryException $e) {
    check(true, 'shared row with an owner rejected');
}
try {
    $bad = new AiMemory(NULL);
    $bad->set('mem_scope', AiMemory::SCOPE_USER);
    $bad->set('mem_owner_user_id', $uidA);
    $bad->set('mem_content', "  \n  ");
    $bad->save();
    check(false, 'whitespace-only content rejected at save()');
} catch (AiMemoryException $e) {
    check(true, 'whitespace-only content rejected at save()');
}

// ==========================================================================
section('remember: always the acting user\'s private scope, source=ai');
$remember = new RememberTool();
$r = $remember->execute(['content' => 'User A is allergic to shellfish.', 'title' => 'Shellfish allergy',
    'tags' => ['health']], $ctxA);
check(is_string($r) && strpos($r, 'Remembered') === 0, 'remember returns confirmation', var_export($r, true));
preg_match('/id (\d+)/', (string)$r, $mm);
$allergy_id = (int)($mm[1] ?? 0);
check($allergy_id > 0, 'remember reports the new id');
if ($allergy_id) harness_register_row('mem_memories', 'mem_memory_id', $allergy_id);

$row = new AiMemory($allergy_id, TRUE);
check((string)$row->get('mem_scope') === AiMemory::SCOPE_USER, 'remember writes scope=user');
check((int)$row->get('mem_owner_user_id') === $uidA, 'remember writes owner=actingUserId');
check((string)$row->get('mem_source') === AiMemory::SOURCE_AI, 'remember writes source=ai');

$r = $remember->execute(['content' => "   \n  "], $ctxA);
check(is_array($r) && !empty($r['is_error']), 'remember rejects empty/whitespace content');
$r = $remember->execute(['content' => 'x', 'title' => str_repeat('t', 300)], $ctxA);
check(is_array($r) && !empty($r['is_error']), 'remember rejects an over-long title');

// ==========================================================================
section('recall: scope isolation + shared pool + non-leaking ids');
$memB = $make_memory($uidB, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER,
    'B private fact', 'User B has a shellfish boat.');
$shared = $make_memory(NULL, AiMemory::SCOPE_SHARED, AiMemory::SOURCE_ADMIN,
    'Refund policy', 'Refunds are honored within 30 days of purchase.', ['policy']);

$recall = new RecallTool();
$out = $recall->execute(['query' => 'shellfish'], $ctxA);
check(strpos($out, 'Shellfish allergy') !== false, 'A recalls their own memory by query');
check(strpos($out, 'B private fact') === false, 'A never recalls B\'s private memory');
check(strpos($out, '<<UNTRUSTED_' . $ctxA->untrustedNonce() . '>>') === 0,
    'recall payload is wrapped in the untrusted envelope');

$out = $recall->execute(['query' => 'refund'], $ctxA);
check(strpos($out, 'Refund policy') !== false, 'A recalls the shared memory');
$out = $recall->execute(['query' => 'refund'], $ctxB);
check(strpos($out, 'Refund policy') !== false, 'B recalls the same shared memory');

$out = $recall->execute(['ids' => [(int)$memB->key]], $ctxA);
check(strpos((string)$out, 'B private fact') === false
        && strpos((string)$out, 'No matching memories') !== false,
    'a foreign id returns nothing and leaks nothing', var_export($out, true));
$out = $recall->execute(['ids' => [(int)$shared->key]], $ctxA);
check(strpos($out, 'Refund policy') !== false, 'a shared id resolves for any user');

$out = $recall->execute(['query' => 'refund', 'scope' => 'mine'], $ctxA);
check(strpos((string)$out, 'Refund policy') === false, 'scope=mine excludes shared');
$out = $recall->execute(['query' => 'shellfish', 'scope' => 'shared'], $ctxA);
check(strpos((string)$out, 'Shellfish allergy') === false, 'scope=shared excludes personal');

$out = $recall->execute([], $ctxA);
check(is_array($out) && !empty($out['is_error']), 'recall with neither query nor ids errors (no full dump)');

// ==========================================================================
section('forget: own rows only, neutral no-op otherwise');
$forget = new ForgetTool();
$mine = $make_memory($uidA, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER, 'Temp fact', 'Delete me.');
$out = $forget->execute(['memory_id' => (int)$mine->key], $ctxA);
check(strpos((string)$out, 'Forgot') === 0, 'forget deletes the caller\'s own row');
$mine->load();
check($mine->get('mem_delete_time') !== null, 'forget soft-deletes (delete_time set)');

$out_foreign = $forget->execute(['memory_id' => (int)$memB->key], $ctxA);
$out_shared = $forget->execute(['memory_id' => (int)$shared->key], $ctxA);
$out_missing = $forget->execute(['memory_id' => 99999999], $ctxA);
check(strpos((string)$out_foreign, 'No memory') === 0, 'foreign id: neutral no-op');
check(strpos((string)$out_shared, 'No memory') === 0, 'shared id: neutral no-op');
$memB->load(); $shared->load();
check(!$memB->get('mem_delete_time') && !$shared->get('mem_delete_time'),
    'foreign/shared rows untouched by forget');
// Same wording for foreign, shared, and nonexistent — no existence signal.
$neutral = function ($id) { return "No memory with id $id was found among the user's own memories — nothing was deleted."; };
check((string)$out_foreign === $neutral((int)$memB->key)
        && (string)$out_shared === $neutral((int)$shared->key)
        && (string)$out_missing === $neutral(99999999),
    'neutral message is identical across foreign/shared/nonexistent ids');

// ==========================================================================
section('Layer 1: salient terms, pre-retrieval, caps, truncation');
$terms = ChatMemory::salientTerms('Can you please recommend a good shellfish restaurant for me', $uidA);
check(in_array('shellfish', $terms, true) && in_array('restaurant', $terms, true),
    'salient terms keep the content words', implode(',', $terms));
check(!in_array('please', $terms, true) && !in_array('you', $terms, true) && !in_array('can', $terms, true),
    'stopwords and short words dropped');

$nonce = $ctxA->untrustedNonce();
$pre = ChatMemory::prefetchSection($uidA, ['shellfish'], 5, 6000, $nonce, 'UTC');
check(strpos($pre['text'], 'allergic to shellfish') !== false, 'matching body is pre-retrieved in full');
check(in_array($allergy_id, $pre['ids'], true), 'prefetch reports the injected ids');
check(strpos($pre['text'], "<<UNTRUSTED_$nonce>>") !== false, 'prefetched bodies are nonce-wrapped');

$pre = ChatMemory::prefetchSection($uidA, ['zzzznomatch'], 5, 6000, $nonce, 'UTC');
check($pre['text'] === '' && $pre['ids'] === [], 'a message matching nothing injects nothing');

// Char cap: a long body is truncated with a recall marker, envelope still closed.
$big = $make_memory($uidA, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER,
    'Big xylophone dossier', str_repeat('xylophone facts. ', 50));   // ~850 chars
$pre = ChatMemory::prefetchSection($uidA, ['xylophone'], 5, 200, $nonce, 'UTC');
check(strpos($pre['text'], 'truncated — recall id ' . (int)$big->key) !== false,
    'overflow body truncated with a recall marker');
check(strpos($pre['text'], "<</UNTRUSTED_$nonce>>") !== false, 'truncated body still closes its envelope');

// Count cap.
$make_memory($uidA, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER, 'Quartz one', 'quartz alpha');
$make_memory($uidA, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER, 'Quartz two', 'quartz beta');
$make_memory($uidA, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER, 'Quartz three', 'quartz gamma');
$pre = ChatMemory::prefetchSection($uidA, ['quartz'], 2, 6000, $nonce, 'UTC');
check(count($pre['ids']) === 2, 'count cap limits pre-retrieved bodies', count($pre['ids']) . ' ids');

// ==========================================================================
section('Layer 2: title index — shared always, dedup, sanitization, personal cap');
$weird = $make_memory($uidA, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER,
    "Line\nbroken   title", 'body');
$untitled = $make_memory($uidA, AiMemory::SCOPE_USER, AiMemory::SOURCE_AI, '', 'untitled body');

$index = ChatMemory::indexSection($uidA, 200, [], $nonce);
check(strpos($index, 'Refund policy · shared') !== false, 'shared memory listed in the index');
check(strpos($index, 'Line broken title') !== false, 'newline in a title is collapsed');
check(strpos($index, '(untitled)') !== false, 'empty title renders as (untitled)');
check(strpos($index, 'id ' . (int)$untitled->key) !== false, 'index lines carry ids');
check(strpos($index, "<<UNTRUSTED_$nonce>>") === 0, 'index is wrapped in the untrusted envelope');

$index = ChatMemory::indexSection($uidA, 200, [$allergy_id], $nonce);
check(strpos($index, 'Shellfish allergy') === false, 'a Layer-1 id is deduped out of the index');

// Personal cap never crowds out shared.
$index = ChatMemory::indexSection($uidA, 1, [], $nonce);
check(strpos($index, 'Refund policy · shared') !== false, 'shared stays listed with personal capped at 1');
check(substr_count($index, ' · personal') === 1, 'personal entries capped', substr_count($index, ' · personal') . ' personal lines');

// ==========================================================================
section('Selectivity guard: an over-common term is skipped');
for ($i = 0; $i < 12; $i++) {
    $make_memory($uidA, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER,
        "Omniproject note $i", "omniproject detail number $i");
}
$terms = ChatMemory::salientTerms('what is the omniproject shellfish status', $uidA);
check(!in_array('omniproject', $terms, true), 'term matching most of the set is dropped', implode(',', $terms));
check(in_array('shellfish', $terms, true), 'a selective term survives the guard');

// ==========================================================================
section('contextBlock: assembled block');
$block = ChatMemory::contextBlock($convA, $ctxA, 'any shellfish around?');
check(strpos($block, '## Stored memories') === 0, 'block carries the header');
check(strpos($block, 'Matched to the current message') !== false, 'block has a Layer-1 section on a match');
check(strpos($block, 'Other stored memories') !== false, 'block has the Layer-2 index section');

// ==========================================================================
section('Security-level gate (tools + injection share one predicate)');
check(ChatMemory::activeFor($convA, $REMOTE_MODEL), 'standard chat: active on a remote model');
$convA->set('aic_memory_access', false);
check(!ChatMemory::activeFor($convA, $REMOTE_MODEL), 'toggle off: inactive');
$convA->set('aic_memory_access', true);

$convPriv = $make_conversation($uidA, AiConversation::LEVEL_PRIVATE, $REMOTE_MODEL);
check(!ChatMemory::activeFor($convPriv, $REMOTE_MODEL), 'private chat + remote model: inactive');
check(ChatMemory::activeFor($convPriv, $LOCAL_MODEL), 'private chat + local model: active');
$convFort = $make_conversation($uidA, AiConversation::LEVEL_FORTRESS, $LOCAL_MODEL);
check(ChatMemory::activeFor($convFort, $LOCAL_MODEL), 'fortress chat (pinned local): active');

// ==========================================================================
section('ChatControls: memory_access validates and seeds');
[$col, $stored] = ChatControls::validate('memory_access', '1');
check($col === 'aic_memory_access' && $stored === 't', 'memory_access maps to aic_memory_access true');
[$col, $stored] = ChatControls::validate('memory_access', '0');
check($stored === 'f', 'memory_access false maps to f');

$fresh = new AiConversation(NULL);
$fresh->set('aic_owner_user_id', $uidA);
ChatControls::seedNewConversation($fresh, ['memory_access' => '1']);
check($fresh->get('aic_memory_access') === 't', 'seedNewConversation copies a posted memory_access');

// ==========================================================================
section('User deletion: private memories cascade, shared survive with a null author');
$userC = make_user('MemC', 5);
$uidC = (int)$userC->key;
$privC = $make_memory($uidC, AiMemory::SCOPE_USER, AiMemory::SOURCE_USER, 'C private', 'C fact');
$sharedC = new AiMemory(NULL);
$sharedC->set('mem_scope', AiMemory::SCOPE_SHARED);
$sharedC->set('mem_created_by_user_id', $uidC);
$sharedC->set('mem_source', AiMemory::SOURCE_ADMIN);
$sharedC->set('mem_title', 'C authored shared');
$sharedC->set('mem_content', 'org fact authored by C');
$sharedC->save();
$sharedC->load();
harness_register_row('mem_memories', 'mem_memory_id', (int)$sharedC->key);

$userC->permanent_delete();

$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare('SELECT COUNT(*) FROM mem_memories WHERE mem_memory_id = ?');
$q->execute([(int)$privC->key]);
check((int)$q->fetchColumn() === 0, 'deleting the user removes their private memories (cascade)');
$q = $db->prepare('SELECT mem_created_by_user_id FROM mem_memories WHERE mem_memory_id = ?');
$q->execute([(int)$sharedC->key]);
$created_by = $q->fetch(PDO::FETCH_ASSOC);
check($created_by !== false, 'the shared memory survives the author\'s deletion');
check($created_by && $created_by['mem_created_by_user_id'] === null, 'the shared memory\'s author pointer is nulled');

harness_finish();
