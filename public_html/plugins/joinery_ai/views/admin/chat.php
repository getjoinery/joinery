<?php
/**
 * Joinery AI - Chat
 * URL: /admin/joinery_ai/chat
 *
 * Two-pane interactive assistant: conversation list on the left, transcript +
 * composer + inline confirmation cards on the right. Turns run over the shared
 * AgentLoop via the chat_send / chat_confirm AJAX endpoints. Built with plain
 * joai-chat-* markup (admin theme is not the .jy-ui kit).
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_chat_logic.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));

$page_vars = process_logic(admin_joinery_ai_chat_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$tz = $session->get_timezone();
$selected_id = $selected ? (int)$selected->key : 0;

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-chat',
    'page_title' => 'Joinery AI Chat',
    'readable_title' => 'Chat',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Chat' => '',
    ],
    'session' => $session,
]);
?>
<div class="joai-chat-wrap">

    <aside class="joai-chat-list">
        <button type="button" id="joai-new-chat" class="joai-btn joai-btn-primary joai-chat-newbtn">+ New chat</button>
        <nav class="joai-chat-threads" id="joai-threads">
            <?php if (!count($conversations)): ?>
                <p class="joai-chat-empty" id="joai-no-threads">No conversations yet.</p>
            <?php endif; ?>
            <?php foreach ($conversations as $c):
                $cid = (int)$c->key;
                $title = trim((string)$c->get('aic_title'));
                if ($title === '') $title = 'Untitled';
                $active = $cid === $selected_id ? ' is-active' : '';
            ?>
                <a class="joai-chat-list-item<?php echo $active; ?>"
                   data-conversation-id="<?php echo $cid; ?>"
                   href="/admin/joinery_ai/chat?aic_conversation_id=<?php echo $cid; ?>">
                    <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <section class="joai-chat-main">
        <div class="joai-chat-status">
            <span class="joai-chat-status-model" id="joai-status-model">
                <?php echo htmlspecialchars((string)$active_model, ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <span class="joai-chat-status-tools">
                <?php echo $web_enabled ? 'web search on' : 'web search off'; ?>
            </span>
        </div>

        <div class="joai-chat-transcript" id="joai-transcript">
            <?php if (!$selected): ?>
                <p class="joai-chat-empty" id="joai-blank">Start a new conversation below.</p>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <?php if ($m->get('aim_role') === AiConversationMessage::ROLE_ASSISTANT): ?>
                        <?php echo ChatRender::assistantBubble($m, $tz); ?>
                    <?php else: ?>
                        <?php
                        $t = LibraryFunctions::convert_time($m->get('aim_create_time'), 'UTC', $tz, 'g:i A');
                        echo ChatRender::userBubble((string)$m->get('aim_content'), $t);
                        ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="joai-chat-thinking" id="joai-thinking" hidden>Thinking…</div>

        <div class="joai-chat-composer">
            <textarea id="joai-input" rows="2" maxlength="8000"
                      placeholder="Ask Joinery AI to look something up or make a change…"
                      <?php echo $chat_enabled ? '' : 'disabled'; ?>></textarea>
            <button type="button" id="joai-send" class="joai-btn joai-btn-primary"
                    <?php echo $chat_enabled ? '' : 'disabled'; ?>>Send</button>
        </div>
        <?php if (!$chat_enabled): ?>
            <p class="joai-chat-empty">Chat is currently disabled in settings.</p>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    var transcript = document.getElementById('joai-transcript');
    var input = document.getElementById('joai-input');
    var sendBtn = document.getElementById('joai-send');
    var thinking = document.getElementById('joai-thinking');
    var threads = document.getElementById('joai-threads');
    var newChatBtn = document.getElementById('joai-new-chat');
    var currentConversationId = <?php echo $selected_id ? $selected_id : 'null'; ?>;

    function scrollToBottom() { transcript.scrollTop = transcript.scrollHeight; }
    scrollToBottom();

    function clearBlankNotice() {
        var blank = document.getElementById('joai-blank');
        if (blank) blank.remove();
    }

    function setBusy(busy) {
        sendBtn.disabled = busy;
        input.disabled = busy;
        thinking.hidden = !busy;
        if (busy) scrollToBottom();
    }

    function send() {
        var message = input.value.trim();
        if (!message) return;
        clearBlankNotice();

        // Optimistically show the user's message (server returns canonical HTML
        // on the next load; here we just echo the text in a mine bubble).
        var mine = document.createElement('div');
        mine.className = 'joai-chat-msg joai-chat-mine';
        mine.innerHTML = '<div class="joai-chat-body"></div>';
        mine.querySelector('.joai-chat-body').textContent = message;
        transcript.appendChild(mine);

        input.value = '';
        setBusy(true);

        var body = new FormData();
        body.append('message', message);
        if (currentConversationId) body.append('conversation_id', currentConversationId);

        fetch('/admin/joinery_ai/chat_send', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setBusy(false);
                if (!data.success) { alert(data.message || 'Send failed.'); return; }

                if (data.is_new) {
                    currentConversationId = data.conversation_id;
                    addThread(data.conversation_id, data.title);
                    history.replaceState(null, '', '/admin/joinery_ai/chat?aic_conversation_id=' + data.conversation_id);
                }
                transcript.insertAdjacentHTML('beforeend', data.assistant_html);
                scrollToBottom();
                input.focus();
            })
            .catch(function () {
                setBusy(false);
                alert('Send failed.');
            });
    }

    function addThread(id, title) {
        var noThreads = document.getElementById('joai-no-threads');
        if (noThreads) noThreads.remove();
        document.querySelectorAll('.joai-chat-list-item.is-active')
            .forEach(function (a) { a.classList.remove('is-active'); });
        var a = document.createElement('a');
        a.className = 'joai-chat-list-item is-active';
        a.setAttribute('data-conversation-id', id);
        a.href = '/admin/joinery_ai/chat?aic_conversation_id=' + id;
        a.textContent = title || 'Untitled';
        threads.insertBefore(a, threads.firstChild);
    }

    // Confirm / cancel a pending action (event-delegated — bubbles arrive dynamically).
    transcript.addEventListener('click', function (e) {
        var yes = e.target.closest('.joai-chat-confirm-yes');
        var no = e.target.closest('.joai-chat-confirm-no');
        if (!yes && !no) return;

        var card = e.target.closest('.joai-chat-confirm');
        if (!card) return;
        var conversationId = card.getAttribute('data-conversation-id');
        var messageId = card.getAttribute('data-message-id');
        var decision = yes ? 'confirm' : 'cancel';

        card.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        setBusy(true);

        var body = new FormData();
        body.append('conversation_id', conversationId);
        body.append('message_id', messageId);
        body.append('decision', decision);

        fetch('/admin/joinery_ai/chat_confirm', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setBusy(false);
                if (!data.success) { alert(data.message || 'Action failed.'); return; }
                var bubble = transcript.querySelector('.joai-chat-msg[data-message-id="' + data.message_id + '"]');
                if (bubble) bubble.outerHTML = data.assistant_html;
                scrollToBottom();
            })
            .catch(function () {
                setBusy(false);
                alert('Action failed.');
            });
    });

    newChatBtn.addEventListener('click', function () {
        currentConversationId = null;
        transcript.innerHTML = '<p class="joai-chat-empty" id="joai-blank">Start a new conversation below.</p>';
        document.querySelectorAll('.joai-chat-list-item.is-active')
            .forEach(function (a) { a.classList.remove('is-active'); });
        history.replaceState(null, '', '/admin/joinery_ai/chat');
        input.focus();
    });

    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
})();
</script>
<?php
$page->admin_footer();
