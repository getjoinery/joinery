<?php
/** @joinery-test
 * name: joinery_ai_turn_activity
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Turn-activity lifecycle — the live "what's happening" line on a running
 * chat turn (specs/ai_chat_turn_activity.md).
 *
 * Drives the shared AgentLoop with a scripted fake provider over a real
 * conversation + assistant row, asserting:
 *   - the loop stamps "Waiting for {short model label}…" before each provider
 *     call, with the step suffix from the second iteration on;
 *   - ChatTurnContext::beginToolCall stamps "Running tool: {name}…";
 *   - the stream sink flips the row to "Writing…" on its first content flush;
 *   - ChatSerializer::runningExtras() returns activity + running_seconds for
 *     a RUNNING row and nothing for a settled one;
 *   - markFailed() and the completion path clear aim_activity.
 *
 * Writes two throwaway rows (a conversation and one assistant message) and
 * permanently deletes the conversation at the end. Run:
 *   php tests/integration/joinery_ai_turn_activity_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(__DIR__ . '/../lib/llm_fixtures.php'); // ScriptedLlmProvider (+ LlmProviderInterface)
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurnContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AgentLoop.php'));

section('joinery_ai turn-activity lifecycle');

// --- fixtures: a throwaway conversation + running assistant row -------------

// Any real user owns the throwaway conversation — derive one rather than assume
// user 1 exists (a fresh install may not have it).
$owner_uid = (int)DbConnector::get_instance()->get_db_link()
	->query("SELECT usr_user_id FROM usr_users WHERE usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")
	->fetchColumn();
if ($owner_uid <= 0) { harness_skip('no active user to own the test conversation'); harness_finish(); }
$conversation = new AiConversation(NULL);
$conversation->set('aic_owner_user_id', $owner_uid);
$conversation->set('aic_title', 'activity-test ' . gmdate('His'));
$conversation->save();

$msg = new AiConversationMessage(NULL);
$msg->set('aim_aic_conversation_id', (int)$conversation->key);
$msg->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
$msg->set('aim_status', AiConversationMessage::STATUS_RUNNING);
$msg->save();

try {
    // --- the loop stamps waiting labels; the sink flips to Writing… ---------

    $labels = [];
    $stamper = ChatAsync::activityStamper($msg);
    $capture = function (string $label) use (&$labels, $stamper): void {
        $labels[] = $label;
        $stamper($label);
    };

    $ctx = new ChatTurnContext($conversation, $owner_uid);
    $ctx->setActivityStamper($capture);
    // >80 chars so the throttled sink flushes on the first delta.
    $long_text = str_repeat('All work and no play makes the assistant a dull model. ', 3);
    $provider = new ScriptedLlmProvider([
        [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $long_text]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 20],
        ],
    ]);
    $ctx->setStreamSink(ChatAsync::streamSink($msg));

    $result = AgentLoop::run($provider, 'accounts/fireworks/models/glm-5p2', [],
        [['role' => 'user', 'content' => 'hi']], [], $ctx, 3, 1000);

    ok('turn ends normally', ($result['stop_reason'] ?? '') === 'end_turn');
    ok('loop stamped the waiting label with the short model name',
        in_array('Waiting for glm-5p2…', $labels, true));
    $msg->load();
    ok('first content flush flipped the row to Writing…',
        $msg->get('aim_activity') === 'Writing…');

    // --- beginToolCall stamps the tool name ---------------------------------

    $ctx->beginToolCall(['name' => 'web_search', 'started_time' => gmdate('Y-m-d H:i:s.u')]);
    ok('beginToolCall stamped the tool label',
        in_array('Running tool: web_search…', $labels, true));
    $msg->load();
    ok('tool label reached the row', $msg->get('aim_activity') === 'Running tool: web_search…');

    // --- step suffix appears from the second provider call ------------------

    $labels2 = [];
    $ctx2 = new ChatTurnContext($conversation, $owner_uid);
    $ctx2->setActivityStamper(function (string $label) use (&$labels2): void { $labels2[] = $label; });
    $tool_use = ['type' => 'tool_use', 'id' => 'toolu_x1', 'name' => 'nonexistent_tool', 'input' => []];
    $provider2 = new ScriptedLlmProvider([
        ['stop_reason' => 'tool_use', 'content' => [$tool_use], 'usage' => []],
        ['stop_reason' => 'end_turn',
         'content' => [['type' => 'text', 'text' => 'done']], 'usage' => []],
    ]);
    AgentLoop::run($provider2, 'fake/test-model', [],
        [['role' => 'user', 'content' => 'hi']], [], $ctx2, 3, 1000);
    ok('second provider call carries the step suffix',
        in_array('Waiting for test-model… (step 2)', $labels2, true));

    // --- the poll payload: extras on running, nothing once settled ----------

    $msg->set('aim_activity', 'Waiting for glm-5p2…');
    $msg->save();
    $extras = ChatSerializer::runningExtras($msg);
    ok('runningExtras carries the activity', ($extras['activity'] ?? '') === 'Waiting for glm-5p2…');
    ok('runningExtras carries sane elapsed seconds',
        isset($extras['running_seconds'])
        && $extras['running_seconds'] >= 0 && $extras['running_seconds'] < 600);
    $serialized = ChatSerializer::message($msg);
    ok('serialized running message carries the extras',
        ($serialized['activity'] ?? '') === 'Waiting for glm-5p2…'
        && isset($serialized['running_seconds']));

    // --- finalize clears the label ------------------------------------------

    ChatTurn::markFailed($msg, 'test failure');
    $msg->load();
    ok('markFailed nulls the activity', $msg->get('aim_activity') === null
        || $msg->get('aim_activity') === '');
    ok('runningExtras is empty on a settled row',
        ChatSerializer::runningExtras($msg) === []);
    $serialized = ChatSerializer::message($msg);
    ok('serialized settled message has no extras',
        !isset($serialized['activity']) && !isset($serialized['running_seconds']));
} finally {
    // Permanent delete cascades to the message row.
    $conversation->permanent_delete();
}

harness_finish();
