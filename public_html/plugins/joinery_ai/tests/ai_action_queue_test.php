<?php
/** @joinery-test
 * name: ai_action_queue
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The AI proposed-action queue (specs/implemented/ai_action_queue.md). Covers:
 *
 *  - Enqueue from a chat write tool call: row shape, the "queued" tool result,
 *    nothing executed; a tool with no card renderer refused outright.
 *  - The one-card rule: facts rendered from the LITERAL stored arguments.
 *  - Approve executes in-request with live re-validation (a missing target
 *    resolves the row failed with the reason); decline runs nothing; resolving
 *    twice refused; someone else's action refused.
 *  - Expiry: a past-due pending action resolves expired and can never run.
 *  - The resolution lands in the source conversation as an EVENT row.
 *  - Sealing: an enqueue from a hot process seals the arguments to the owner
 *    (ciphertext at rest, card locked without the window) and is REFUSED when
 *    there is no vault to seal to.
 *  - The deferred-write boundary: RiskHeuristic::isMutating() and the
 *    contexts' queuesWrites() split.
 *
 * Run: php tests/run.php db --only=plugins/joinery_ai/tests/ai_action_queue_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_queued_actions_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_notes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionQueue.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RiskHeuristic.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

$db = DbConnector::get_instance()->get_db_link();

// Session + APCu availability must be established BEFORE the harness prints
// anything — session_start() refuses once output has been sent, and the
// in-window sealed-card checks below need both.
$aq_window_capable = vault_apcu_usable() && vault_ensure_session();

$owner = make_user('AqOwner', 10);
$owner_id = intval($owner->key);
$other = make_user('AqOther');
$other_id = intval($other->key);

// ModelWriteExecutor::authenticate() reads the session for the permission half.
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = $owner_id;
$_SESSION['permission'] = 10;

function aq_conversation(int $owner_id): AiConversation {
	$c = new AiConversation(NULL);
	$c->set('aic_owner_user_id', $owner_id);
	$c->set('aic_title', 'queue test');
	$c->set('aic_data_access', true);
	$c->save();
	$c->load();
	harness_register_row('aic_conversations', 'aic_conversation_id', intval($c->key));
	return $c;
}

function aq_register(AiQueuedAction $row): void {
	harness_register_row('aqa_ai_queued_actions', 'aqa_ai_queued_action_id', intval($row->key));
}

$conversation = aq_conversation($owner_id);

// -----------------------------------------------------------------------------
section('The deferred-write boundary: what queues, and who queues');

check(RiskHeuristic::isMutating(['name' => 'update_model']), 'generic writes are mutating');
check(RiskHeuristic::isMutating(['name' => 'delete_model']), 'so are deletes');
check(RiskHeuristic::isMutating(['name' => 'invoke_action', 'input' => ['name' => 'no_such_action']]),
	'an unresolvable action fails safe to mutating');
check(!RiskHeuristic::isMutating(['name' => 'query_model']), 'reads are not');

$chat_ctx = new ChatTurnContext($conversation, $owner_id);
check($chat_ctx->queuesWrites() === true, 'the interactive chat defers writes');

// -----------------------------------------------------------------------------
section('Enqueue from a chat write tool: queued, not executed');

$before_notes = (int)$db->query("SELECT count(*) FROM rcn_notes")->fetchColumn();
$block = $chat_ctx->enqueueProposedAction([
	'type' => 'tool_use', 'id' => 'toolu_test_1', 'name' => 'create_model',
	'input' => ['model' => 'RecipeNote', 'fields' => [
		'rcn_owner_user_id' => $owner_id,
		'rcn_title'         => 'Queued note title',
		'rcn_content'       => 'Written only on approval.',
	]],
]);
check(empty($block['is_error']) && stripos((string)$block['content'], 'Queued for approval') !== false,
	'the model receives a queued tool result, not an outcome', json_encode($block));
check((int)$db->query("SELECT count(*) FROM rcn_notes")->fetchColumn() === $before_notes,
	'and nothing executed at proposal time');

preg_match('/#(\d+)/', (string)$block['content'], $m);
$action = new AiQueuedAction((int)($m[1] ?? 0), TRUE);
aq_register($action);
check($action->key > 0 && (string)$action->get('aqa_status') === AiQueuedAction::STATUS_PENDING,
	'the pending row exists');
check((int)$action->get('aqa_owner_user_id') === $owner_id
		&& (int)$action->get('aqa_aic_conversation_id') === intval($conversation->key)
		&& (string)$action->get('aqa_tool') === 'create_model',
	'owned by the conversation owner, tied to its conversation and tool');
check(substr((string)$action->get('aqa_expires_time'), 0, 10)
		=== gmdate('Y-m-d', time() + 7 * 86400),
	'proposals are perishable — a 7-day expiry is stamped');

// -----------------------------------------------------------------------------
section('The one-card rule: facts from the literal arguments');

$card = ActionQueue::card($action);
check($card['locked'] === false && is_array($card['facts']), 'a cold row renders its facts');
check(($card['facts'][0] ?? '') === 'Create a RecipeNote record',
	'the headline states the literal act', json_encode($card['facts']));
$joined = implode(' | ', $card['facts']);
check(strpos($joined, 'rcn_title: Queued note title') !== false,
	'and every fact line is a literal argument value', $joined);

$refused = '';
try {
	// query_model is real but read-only — it declares no card renderer
	// (the web tools all do now: hot-turn egress approval).
	ActionQueue::enqueue($owner_id, 'query_model', ['model' => 'RecipeNote'], intval($conversation->key));
} catch (ActionQueueException $e) {
	$refused = $e->getMessage();
}
check(stripos($refused, 'renderer') !== false,
	'a tool with no card renderer cannot enqueue — refused, not stored', $refused);

// -----------------------------------------------------------------------------
section('Owner scoping and double-resolve');

$refused = '';
try {
	ActionQueue::resolve((int)$action->key, $other_id, 'approve');
} catch (ActionQueueException $e) {
	$refused = $e->getMessage();
}
check($refused !== '', 'someone else cannot resolve your action', $refused);

// -----------------------------------------------------------------------------
section('Approve executes in-request; the note exists only after the yes');

$resolved = ActionQueue::resolve((int)$action->key, $owner_id, 'approve');
check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_APPROVED,
	'the row resolves approved', (string)$resolved->get('aqa_result'));
$note_id = (int)$db->query(
	"SELECT rcn_note_id FROM rcn_notes WHERE rcn_title = 'Queued note title'
	  ORDER BY rcn_note_id DESC LIMIT 1")->fetchColumn();
check($note_id > 0, 'the write ran at approval time, as the owner');
if ($note_id > 0) harness_register_row('rcn_notes', 'rcn_note_id', $note_id);

$refused = '';
try {
	ActionQueue::resolve((int)$action->key, $owner_id, 'approve');
} catch (ActionQueueException $e) {
	$refused = $e->getMessage();
}
check(stripos($refused, 'already approved') !== false,
	'resolving twice is refused, idempotent-safe', $refused);

// -----------------------------------------------------------------------------
section('Approve re-validates live: a vanished target resolves failed');

$stale = ActionQueue::enqueue($owner_id, 'update_model',
	['model' => 'RecipeNote', 'key' => 999999999, 'fields' => ['rcn_title' => 'x']],
	intval($conversation->key));
aq_register($stale);
$resolved = ActionQueue::resolve((int)$stale->key, $owner_id, 'approve');
check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_FAILED,
	'an approved action that fails validation resolves failed, never half-happens');
check(trim((string)$resolved->get('aqa_result')) !== ''
		&& stripos((string)$resolved->get('aqa_result'), 'error') !== false,
	'with the reason recorded on the card', (string)$resolved->get('aqa_result'));

// -----------------------------------------------------------------------------
section('Decline runs nothing');

$declined = ActionQueue::enqueue($owner_id, 'delete_model',
	['model' => 'RecipeNote', 'key' => $note_id], intval($conversation->key));
aq_register($declined);
$resolved = ActionQueue::resolve((int)$declined->key, $owner_id, 'decline');
check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_DECLINED, 'declined resolves declined');
$still = (int)$db->query("SELECT count(*) FROM rcn_notes
	WHERE rcn_note_id = " . (int)$note_id . " AND rcn_delete_time IS NULL")->fetchColumn();
check($still === 1, 'and the target is untouched');

// -----------------------------------------------------------------------------
section('The resolution lands in the conversation as an EVENT row');

$events = new MultiAiConversationMessage(
	['conversation_id' => intval($conversation->key), 'role' => AiConversationMessage::ROLE_EVENT,
	 'deleted' => false], ['aim_message_id' => 'ASC']);
$events->load();
check(count($events) === 3, 'each resolution appended one event row', count($events) . ' rows');
$texts = [];
foreach ($events as $e) {
	harness_register_row('aim_conversation_messages', 'aim_message_id', (int)$e->key);
	$texts[] = (string)$e->get('aim_content');
}
check(strpos($texts[0] ?? '', 'approved it and it ran') !== false,
	'the approval event says it ran', $texts[0] ?? '');
check(strpos($texts[2] ?? '', 'declined it; do not retry') !== false,
	'the decline event tells the model not to retry', $texts[2] ?? '');

// -----------------------------------------------------------------------------
section('Expiry: a past-due proposal can never run');

$overdue = ActionQueue::enqueue($owner_id, 'update_model',
	['model' => 'RecipeNote', 'key' => $note_id, 'fields' => ['rcn_title' => 'too late']],
	intval($conversation->key));
aq_register($overdue);
AiQueuedAction::updateColumns((int)$overdue->key,
	['aqa_expires_time' => gmdate('Y-m-d H:i:s', time() - 60)]);
$refused = '';
try {
	ActionQueue::resolve((int)$overdue->key, $owner_id, 'approve');
} catch (ActionQueueException $e) {
	$refused = $e->getMessage();
}
check(stripos($refused, 'expired') !== false, 'approving a past-due proposal is refused', $refused);
$overdue->load();
check((string)$overdue->get('aqa_status') === AiQueuedAction::STATUS_EXPIRED,
	'and the row is resolved expired');

$swept = ActionQueue::enqueue($owner_id, 'update_model',
	['model' => 'RecipeNote', 'key' => $note_id, 'fields' => ['rcn_title' => 'swept']],
	intval($conversation->key));
aq_register($swept);
AiQueuedAction::updateColumns((int)$swept->key,
	['aqa_expires_time' => gmdate('Y-m-d H:i:s', time() - 60)]);
ActionQueue::expireOverdueFor($owner_id);
$swept->load();
check((string)$swept->get('aqa_status') === AiQueuedAction::STATUS_EXPIRED,
	'the list-time sweep expires overdue rows');
check(ActionQueue::pendingCount($owner_id) === 0, 'nothing pending remains for the badge');

// -----------------------------------------------------------------------------
section('Sealing: a hot enqueue seals to the owner, or refuses');

// No vault yet: a hot process cannot protect the proposal — refused.
SealedEgressGuard::noteScopeOpened($owner_id);
SealedEgressGuard::markHot('ai_action_queue_test');
$refused = '';
try {
	ActionQueue::enqueue($owner_id, 'create_model',
		['model' => 'RecipeNote', 'fields' => ['rcn_title' => 'hot, unsealable']],
		intval($conversation->key));
} catch (ActionQueueException $e) {
	$refused = $e->getMessage();
}
check(stripos($refused, 'vault') !== false,
	'hot with no vault to seal to → the enqueue is refused, never stored in the clear', $refused);

// With a vault: the arguments land as ciphertext, sealed to the owner.
$kp = sodium_crypto_box_keypair();
$secret = SealedBox::b64url(sodium_crypto_box_secretkey($kp));
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $owner_id);
$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($kp)));
$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
$vault->save();
$vault->load();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));

$sealed = ActionQueue::enqueue($owner_id, 'create_model',
	['model' => 'RecipeNote', 'fields' => ['rcn_title' => 'A sealed proposal quoting protected mail']],
	intval($conversation->key));
aq_register($sealed);
SealedEgressGuard::reset();   // the resolving request is a fresh, cold process

$raw = $db->prepare("SELECT aqa_arguments, aqa_content_sealed FROM aqa_ai_queued_actions
	WHERE aqa_ai_queued_action_id = ?");
$raw->execute([(int)$sealed->key]);
$row = $raw->fetch(PDO::FETCH_ASSOC);
check(!empty($row['aqa_content_sealed']) && $row['aqa_content_sealed'] !== 'f',
	'the row is marked sealed');
check(strpos((string)$row['aqa_arguments'], 'v1.aead.') === 0,
	'and the arguments are ciphertext at rest', substr((string)$row['aqa_arguments'], 0, 24));

$sealed->load();
$card = ActionQueue::card($sealed);
check($card['locked'] === true && $card['facts'] === null,
	'without the window the card is locked — no facts leak');
$refused = '';
try {
	ActionQueue::resolve((int)$sealed->key, $owner_id, 'approve');
} catch (ActionQueueException $e) {
	$refused = $e->getMessage();
}
check(stripos($refused, 'unlock') !== false,
	'and approval is refused until the vault is unlocked', $refused);

if ($aq_window_capable) {
	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		['idle' => null, 'absolute' => null]);
	$sealed->load();
	$card = ActionQueue::card($sealed);
	check($card['locked'] === false
			&& strpos(implode(' ', $card['facts']), 'A sealed proposal') !== false,
		'in-window the card renders its literal facts', json_encode($card['facts']));
	$resolved = ActionQueue::resolve((int)$sealed->key, $owner_id, 'decline');
	check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_DECLINED,
		'and resolving works in-window');
	VaultUnlock::lockAll($owner_id);
} else {
	harness_skip('in-window sealed card checks', 'APCu unavailable in this process');
}

// -----------------------------------------------------------------------------
section('Recipes never queue');

check((new ReflectionMethod('RecipeRunContext', 'queuesWrites'))->getDeclaringClass()->getName()
		=== 'RecipeRunContext', 'RecipeRunContext declares its own answer');
$src = file_get_contents(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
check(strpos($src, 'return false;') !== false && strpos($src, 'function queuesWrites') !== false,
	'and that answer is false — the verdict handler stays their one write door');

harness_finish();
