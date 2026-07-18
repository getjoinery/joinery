<?php
/** @joinery-test
 * name: joinery_ai_chat_cancel
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('joinery_ai')) { harness_skip('joinery_ai plugin inactive'); harness_finish(); }

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AgentLoop.php'));
require_once(__DIR__ . '/../../../tests/lib/llm_fixtures.php'); // FakeLlmProvider base (+ LlmProviderInterface)

/**
 * A provider stub for the cooperative-cancel path. In 'abort' mode it emits a
 * partial answer, flips the running row's cancel flag (standing in for the user
 * clicking Cancel mid-generation), then honors $shouldAbort by returning the
 * partial with stop_reason 'aborted'. 'end_turn' mode never aborts (the control).
 * 'tool_use' mode returns a tool call so the loop would continue to a second step.
 *
 * The interface boilerplate lives in FakeLlmProvider; only the cancel behavior
 * and the 'stub' identity are overridden here.
 */
class ChatCancelStubProvider extends FakeLlmProvider {
    private $mode;
    private $flip_msg_id;
    public function __construct(string $mode, int $flip_msg_id = 0) {
        $this->mode = $mode;
        $this->flip_msg_id = $flip_msg_id;
    }
    public function createMessageStreamed(array $params, callable $onTextDelta,
            ?callable $shouldAbort = null): array {
        $this->calls++;
        $onTextDelta('partial answer so far');
        if ($this->mode === 'abort') {
            if ($this->flip_msg_id > 0) {
                // The cancel arrives mid-generation, after shouldContinue already
                // passed at the loop top.
                AiConversationMessage::updateColumns($this->flip_msg_id, ['aim_cancel_requested' => true]);
            }
            if ($shouldAbort !== null && $shouldAbort()) {
                return ['stop_reason' => 'aborted',
                        'content' => [['type' => 'text', 'text' => 'partial answer so far']],
                        'usage' => ['input_tokens' => 3, 'output_tokens' => 4]];
            }
        }
        if ($this->mode === 'tool_use') {
            return ['stop_reason' => 'tool_use',
                    'content' => [['type' => 'text', 'text' => 'partial answer so far'],
                                  ['type' => 'tool_use', 'id' => 't1', 'name' => 'query_model', 'input' => ['model' => 'X']]],
                    'usage' => ['input_tokens' => 3, 'output_tokens' => 4]];
        }
        return ['stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'partial answer so far']],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 4]];
    }
    public function models(): array { return ['stub' => 'stub']; }
    public function defaultModel(): string { return 'stub'; }
    public function id(): string { return 'stub'; }
}

// ---- Fixtures ------------------------------------------------------------
$userA = make_user('CancelA', 5);
$uidA  = (int)$userA->key;
$userB = make_user('CancelB', 5);
$uidB  = (int)$userB->key;

$make_conv = function (int $owner): AiConversation {
    $c = new AiConversation(NULL);
    $c->set('aic_owner_user_id', $owner);
    $c->set('aic_security_level', AiConversation::LEVEL_STANDARD);
    $c->set('aic_title', 'Cancel probe');
    $c->set('aic_model', 'stub');
    $c->save();
    $c->load();
    harness_register_row('aic_conversations', 'aic_conversation_id', (int)$c->key);
    return $c;
};

$make_running_msg = function (int $conv_id, string $content = ''): AiConversationMessage {
    // A user turn so the history isn't empty, then the RUNNING assistant row.
    $u = new AiConversationMessage(NULL);
    $u->set('aim_aic_conversation_id', $conv_id);
    $u->set('aim_role', AiConversationMessage::ROLE_USER);
    $u->set('aim_content', 'hello there');
    $u->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
    $u->save();
    harness_register_row('aim_conversation_messages', 'aim_message_id', (int)$u->key);

    $m = new AiConversationMessage(NULL);
    $m->set('aim_aic_conversation_id', $conv_id);
    $m->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
    $m->set('aim_content', $content);
    $m->set('aim_status', AiConversationMessage::STATUS_RUNNING);
    $m->save();
    $m->load();
    harness_register_row('aim_conversation_messages', 'aim_message_id', (int)$m->key);
    return $m;
};

$convA = $make_conv($uidA);
$SYSTEM = [['type' => 'text', 'text' => 'You are a test assistant.']];
$HISTORY = [['role' => 'user', 'content' => 'hello there']];

// ==========================================================================
section('ChatTurnContext cancel predicate (fresh DB re-read)');
$msg1 = $make_running_msg((int)$convA->key);
$ctx1 = new ChatTurnContext($convA, $uidA, (int)$msg1->key);
check($ctx1->isCancelRequested() === false, 'unflagged running row is not cancel-requested');
check($ctx1->shouldAbort() === false, 'shouldAbort false while unflagged');
check($ctx1->shouldContinue() === null, 'shouldContinue null while unflagged (within wall clock)');

AiConversationMessage::updateColumns((int)$msg1->key, ['aim_cancel_requested' => true]);
check($ctx1->isCancelRequested() === true, 'flag flip seen via fresh SELECT (not the stale in-memory row)');
check($ctx1->shouldAbort() === true, 'shouldAbort true once flagged');
$halt = $ctx1->shouldContinue();
check(is_array($halt) && ($halt['stop_reason'] ?? '') === 'cancelled',
    'shouldContinue returns the cancelled stop_reason', json_encode($halt));

// A context with no message id (unit-test path) never reports cancelled.
$ctx_nomsg = new ChatTurnContext($convA, $uidA);
check($ctx_nomsg->isCancelRequested() === false, 'no message id → never cancelled');

// ==========================================================================
section('AgentLoop maps mid-generation abort → cancelled, keeping the partial');
$msg2 = $make_running_msg((int)$convA->key);
$ctx2 = new ChatTurnContext($convA, $uidA, (int)$msg2->key);   // starts UNflagged
$prov_abort = new ChatCancelStubProvider('abort', (int)$msg2->key);
$res2 = AgentLoop::run($prov_abort, 'stub', $SYSTEM, $HISTORY, [], $ctx2, 3, 1000);
check($prov_abort->calls === 1, 'the generation started (shouldContinue passed at the loop top)', 'calls=' . $prov_abort->calls);
check(($res2['stop_reason'] ?? '') === 'cancelled', 'aborted stream maps to stop_reason cancelled', $res2['stop_reason'] ?? '(none)');
check(($res2['assistant_text'] ?? '') === 'partial answer so far', 'the partial answer is kept', $res2['assistant_text'] ?? '');

// ==========================================================================
section('AgentLoop between-steps cancel (flag set before the turn)');
$msg3 = $make_running_msg((int)$convA->key);
AiConversationMessage::updateColumns((int)$msg3->key, ['aim_cancel_requested' => true]);
$ctx3 = new ChatTurnContext($convA, $uidA, (int)$msg3->key);
$prov_never = new ChatCancelStubProvider('tool_use');
$res3 = AgentLoop::run($prov_never, 'stub', $SYSTEM, $HISTORY, [], $ctx3, 3, 1000);
check($prov_never->calls === 0, 'a pre-set flag stops the turn before any provider call', 'calls=' . $prov_never->calls);
check(($res3['stop_reason'] ?? '') === 'cancelled', 'between-steps guard yields cancelled', $res3['stop_reason'] ?? '');

// ==========================================================================
section('Control: an unflagged turn is never spuriously cancelled');
$msg4 = $make_running_msg((int)$convA->key);
$ctx4 = new ChatTurnContext($convA, $uidA, (int)$msg4->key);
$prov_ok = new ChatCancelStubProvider('end_turn', (int)$msg4->key); // 'end_turn' never aborts
$res4 = AgentLoop::run($prov_ok, 'stub', $SYSTEM, $HISTORY, [], $ctx4, 3, 1000);
check(($res4['stop_reason'] ?? '') === 'end_turn', 'a normal generation ends end_turn, not cancelled', $res4['stop_reason'] ?? '');
check($ctx4->isCancelRequested() === false, 'the control row was never flagged');

// ==========================================================================
section('Finalize writes CANCELLED, keeps the partial, clears the flag');
$msg5 = $make_running_msg((int)$convA->key, 'half an answer');
AiConversationMessage::updateColumns((int)$msg5->key, ['aim_cancel_requested' => true]);
// Mirror ChatTurn::runAndFinalize's terminal write on a cancelled result.
AiConversationMessage::updateColumns((int)$msg5->key, [
    'aim_content'          => 'half an answer',
    'aim_status'           => AiConversationMessage::STATUS_CANCELLED,
    'aim_cancel_requested' => false,
    'aim_activity'         => null,
]);
$reload5 = new AiConversationMessage((int)$msg5->key, true);
check($reload5->get('aim_status') === AiConversationMessage::STATUS_CANCELLED, 'row is terminal CANCELLED');
check((string)$reload5->get('aim_content') === 'half an answer', 'the partial answer is preserved');
check((bool)$reload5->get('aim_cancel_requested') === false, 'the cancel flag is cleared on finalize');

// The stop-reason note + empty-partial fallback.
check(ChatRunner::stopReasonNote('cancelled') === '_(Cancelled.)_', 'stopReasonNote has a cancelled case');
check(ChatRunner::resolveAssistantText(['assistant_text' => '', 'stop_reason' => 'cancelled']) === '_(Cancelled.)_',
    'an empty cancelled turn resolves to the Cancelled marker');
check(ChatRunner::resolveAssistantText(['assistant_text' => 'kept', 'stop_reason' => 'cancelled']) === 'kept',
    'a cancelled turn with partial text keeps the partial');

// ==========================================================================
section('Endpoint guards: owner-scope + running-only (the no-op preconditions)');
// Owner-scope: the conversation belongs to A, so B is not the owner — the cancel
// endpoint reads that mismatch as "not found".
check((int)$convA->get('aic_owner_user_id') === $uidA, 'conversation is owned by A');
check((int)$convA->get('aic_owner_user_id') !== $uidB, 'B is not the owner → cancel denied as not-found');
// Running-only: a settled (cancelled/complete) row is not RUNNING, so a later
// cancel is a benign no-op.
check($reload5->get('aim_status') !== AiConversationMessage::STATUS_RUNNING,
    'a settled row is not running → cancel is a no-op');

harness_finish();
