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

if (!function_exists('joai_pin_svg')) {
    // Small thumbtack marker shown beside pinned threads (no emoji; inline SVG
    // matches the admin theme's icon approach).
    function joai_pin_svg(): string {
        return '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">'
             . '<path d="M16 3v2h-1v6l2 2v2h-4v6l-1 1-1-1v-6H7v-2l2-2V5H8V3z"/></svg>';
    }
}

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
                $pinned = (bool)$c->get('aic_pinned');
            ?>
                <div class="joai-chat-item<?php echo $pinned ? ' is-pinned' : ''; ?>"
                     data-conversation-id="<?php echo $cid; ?>" data-pinned="<?php echo $pinned ? '1' : '0'; ?>">
                    <a class="joai-chat-list-item<?php echo $active; ?>"
                       href="/admin/joinery_ai/chat?aic_conversation_id=<?php echo $cid; ?>">
                        <span class="joai-chat-pin" aria-hidden="true"><?php echo joai_pin_svg(); ?></span>
                        <span class="joai-chat-item-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                    <button type="button" class="joai-chat-item-menu-btn" aria-label="Thread actions" aria-haspopup="true" aria-expanded="false">&#8942;</button>
                    <div class="joai-chat-item-menu" role="menu" hidden>
                        <button type="button" role="menuitem" data-action="pin"><?php echo $pinned ? 'Unpin' : 'Pin'; ?></button>
                        <button type="button" role="menuitem" data-action="rename">Rename</button>
                        <button type="button" role="menuitem" data-action="delete" class="joai-chat-menu-danger">Delete</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
    </aside>

    <section class="joai-chat-main">
        <div class="joai-chat-status">
            <?php
            $model_options = $models;
            if ($active_model !== '' && !array_key_exists($active_model, $model_options)) {
                $model_options = [$active_model => $active_model . ' (unavailable)'] + $model_options;
            }
            ?>
            <select id="joai-model" class="joai-chat-control" data-field="model" title="Model (provider follows the model)">
                <?php foreach ($model_options as $mid => $mlabel): ?>
                    <option value="<?php echo htmlspecialchars((string)$mid, ENT_QUOTES, 'UTF-8'); ?>"
                        data-private="<?php echo !empty($model_privacy[$mid]) ? '1' : '0'; ?>"
                        <?php echo $mid === $active_model ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$mlabel, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="joai-chat-toggle">
                <input type="checkbox" id="joai-toggle-data" data-capability="data_access"
                       <?php echo $data_access ? 'checked' : ''; ?>>
                Data access
            </label>
            <label class="joai-chat-toggle<?php echo $brave_key_set ? '' : ' is-disabled'; ?>"
                   <?php echo $brave_key_set ? '' : 'title="Set the Brave Search API key in settings to enable web search."'; ?>>
                <input type="checkbox" id="joai-toggle-web" data-capability="web_search"
                       <?php echo $web_search ? 'checked' : ''; ?>
                       <?php echo $brave_key_set ? '' : 'disabled'; ?>>
                Web search
            </label>

            <label class="joai-chat-thinklabel">Thinking
                <select id="joai-thinking-level" class="joai-chat-control" data-field="thinking_level">
                    <?php foreach (['off'=>'Off','low'=>'Low','medium'=>'Medium','high'=>'High'] as $lv => $lbl): ?>
                        <option value="<?php echo $lv; ?>" <?php echo $lv === $thinking_level ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <details class="joai-chat-settings">
                <summary>⚙ Settings</summary>
                <div class="joai-chat-settings-body">
                    <label>Temperature
                        <input type="number" id="joai-temperature" class="joai-chat-control" data-field="temperature"
                               step="0.1" min="0" max="2"
                               value="<?php echo htmlspecialchars($temperature, ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="<?php echo htmlspecialchars($def_temperature !== '' ? $def_temperature : 'default', ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label>Top-p
                        <input type="number" id="joai-top-p" class="joai-chat-control" data-field="top_p"
                               step="0.05" min="0" max="1"
                               value="<?php echo htmlspecialchars($top_p, ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="<?php echo htmlspecialchars($def_top_p !== '' ? $def_top_p : 'default', ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label>Max tokens
                        <input type="number" id="joai-max-tokens" class="joai-chat-control" data-field="max_tokens"
                               step="100" min="1000"
                               value="<?php echo htmlspecialchars($max_tokens, ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="<?php echo htmlspecialchars($def_max_tokens !== '' ? $def_max_tokens : 'default', ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="joai-chat-settings-instr">Instructions
                        <textarea id="joai-instructions" class="joai-chat-control" data-field="instructions" rows="3"
                                  placeholder="Standing instructions for this chat (blank = use the default voice)."><?php echo htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </label>
                </div>
            </details>
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

        <div class="joai-chat-thinking" id="joai-thinking" hidden>Working…</div>

        <div class="joai-chat-sensitive-notice" id="joai-sensitive-notice" role="status" hidden
             style="margin:0 0 8px;padding:8px 12px;border:1px solid #e0a800;background:#fff8e1;color:#6b5200;border-radius:6px;font-size:13px;line-height:1.4;">
            ⚠ This message looks like it may contain personal data, and the selected model isn't private — your
            text will be processed off-device. Switch to a local or private model to keep it on-device.
        </div>

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
    var dataToggle = document.getElementById('joai-toggle-data');
    var webToggle = document.getElementById('joai-toggle-web');
    var modelSelect = document.getElementById('joai-model');
    var sensitiveNotice = document.getElementById('joai-sensitive-notice');
    var currentConversationId = <?php echo $selected_id ? $selected_id : 'null'; ?>;

    function scrollToBottom() { transcript.scrollTop = transcript.scrollHeight; }
    scrollToBottom();

    // A turn runs off the request: the send/confirm endpoints return a poll
    // handle (message_id + status:"running") and finish the turn in the
    // background. We poll until the assistant row is complete or failed, and
    // while it runs we show the answer text as it streams (partial_text).
    var POLL_INTERVAL_MS = 600;          // brisk while a turn is active
    var POLL_GIVE_UP_MS = 3600 * 1000;   // beyond the server-side stale ceiling

    function pollMessage(messageId, onPartial, onComplete, onFailed) {
        var startedAt = Date.now();
        function tick() {
            fetch('/admin/joinery_ai/chat_poll?message_id=' + encodeURIComponent(messageId))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { onFailed(data.message || 'Could not load the reply.'); return; }
                    if (data.status === 'complete') { onComplete(data.assistant_html); return; }
                    if (data.status === 'failed') { onFailed(data.error || 'The assistant could not complete this turn.'); return; }
                    if (typeof data.partial_text === 'string') onPartial(data.partial_text);
                    if (Date.now() - startedAt > POLL_GIVE_UP_MS) {
                        onFailed('This is taking longer than expected. It may still finish — reload to check.');
                        return;
                    }
                    setTimeout(tick, POLL_INTERVAL_MS);
                })
                .catch(function () {
                    if (Date.now() - startedAt > POLL_GIVE_UP_MS) { onFailed('Lost connection while waiting for the reply.'); return; }
                    setTimeout(tick, POLL_INTERVAL_MS);
                });
        }
        setTimeout(tick, POLL_INTERVAL_MS);
    }

    // A live assistant bubble that streamed partial text fills, then the final
    // server-rendered (markdown) bubble replaces. Reuses an existing bubble with
    // the same id (the confirm flow reuses the pending bubble).
    function ensureLiveBubble(messageId) {
        var el = transcript.querySelector('.joai-chat-msg[data-message-id="' + messageId + '"]');
        if (!el) {
            el = document.createElement('div');
            el.setAttribute('data-message-id', messageId);
            transcript.appendChild(el);
        }
        el.className = 'joai-chat-msg joai-chat-assistant joai-chat-streaming';
        el.innerHTML = '<div class="joai-chat-body"></div>';
        scrollToBottom();
        return el;
    }

    // Show a live bubble for messageId and stream into it until the turn lands.
    function streamInto(messageId) {
        var body = ensureLiveBubble(messageId).querySelector('.joai-chat-body');
        pollMessage(messageId,
            function (text) { body.textContent = text; scrollToBottom(); },
            function (html) { replaceBubble(messageId, html); },
            function (err) { setBusy(false); alert(err || 'The turn could not be completed.'); });
    }

    // Capability toggles. On an existing chat, persist immediately; on a new
    // chat (no id yet) the state rides along with the first send.
    function wireToggle(el) {
        if (!el) return;
        el.addEventListener('change', function () {
            if (!currentConversationId) return;
            var body = new FormData();
            body.append('conversation_id', currentConversationId);
            body.append('capability', el.getAttribute('data-capability'));
            body.append('enabled', el.checked ? '1' : '0');
            fetch('/admin/joinery_ai/chat_set_capabilities', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (!data.success) { alert(data.message || 'Could not update.'); el.checked = !el.checked; } })
                .catch(function () { el.checked = !el.checked; });
        });
    }
    wireToggle(dataToggle);
    wireToggle(webToggle);

    // Model controls (model, thinking level, temperature, top_p, max tokens,
    // instructions). On an existing chat each persists immediately; on a new chat
    // their values ride the first send. Same endpoint, generic {field, value}.
    var controls = Array.prototype.slice.call(document.querySelectorAll('.joai-chat-control'));
    function wireField(el) {
        el.addEventListener('change', function () {
            if (!currentConversationId) return; // new chat: seeded on first send
            var body = new FormData();
            body.append('conversation_id', currentConversationId);
            body.append('field', el.getAttribute('data-field'));
            body.append('value', el.value);
            fetch('/admin/joinery_ai/chat_set_capabilities', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (!data.success) alert(data.message || 'Could not update.'); })
                .catch(function () {});
        });
    }
    controls.forEach(wireField);

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

    // ----- Sensitivity notice -----
    // A passive, real-time warning: while composing, if the text looks like it
    // carries personal data AND the selected model's provider isn't private, show
    // a banner. It never blocks sending and needs no confirmation; it shows/hides
    // as the text and model change. Recipes are unaffected (this is chat-only).
    function luhnValid(num) {
        var sum = 0, alt = false;
        for (var i = num.length - 1; i >= 0; i--) {
            var d = parseInt(num.charAt(i), 10);
            if (alt) { d *= 2; if (d > 9) d -= 9; }
            sum += d; alt = !alt;
        }
        return sum % 10 === 0;
    }
    function cardPresent(text) {
        var matches = text.match(/\b(?:\d[ -]?){13,16}\b/g);
        if (!matches) return false;
        for (var i = 0; i < matches.length; i++) {
            var digits = matches[i].replace(/[ -]/g, '');
            if (digits.length >= 13 && digits.length <= 16 && luhnValid(digits)) return true;
        }
        return false;
    }
    function looksSensitive(text) {
        if (!text) return false;
        // Strong signals — any one is enough.
        if (/\b\d{3}-\d{2}-\d{4}\b/.test(text)) return true;          // SSN
        if (/^\s*(from|to|subject|date)\s*:/im.test(text)) return true; // pasted email headers
        if (cardPresent(text)) return true;                          // credit card
        // Weak signals — require two together.
        var weak = 0;
        if (/[\w.+-]+@[\w-]+\.[\w.-]+/.test(text)) weak++;          // email address
        if (/(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/.test(text)) weak++; // phone
        if (/\b\d{1,6}\s+([A-Za-z0-9.'-]+\s+){1,4}(st|street|ave|avenue|rd|road|blvd|boulevard|dr|drive|ln|lane|ct|court|way|pl|place)\b/i.test(text)) weak++; // street address
        return weak >= 2;
    }
    function selectedModelPrivate() {
        if (!modelSelect) return true; // no selector -> don't nag
        var opt = modelSelect.selectedOptions[0];
        return !opt || opt.getAttribute('data-private') === '1';
    }
    function updateSensitivityNotice() {
        if (!sensitiveNotice) return;
        var show = !selectedModelPrivate() && looksSensitive(input.value);
        sensitiveNotice.hidden = !show;
    }
    if (input) input.addEventListener('input', updateSensitivityNotice);
    if (modelSelect) modelSelect.addEventListener('change', updateSensitivityNotice);
    updateSensitivityNotice();

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
        updateSensitivityNotice();
        setBusy(true);

        var body = new FormData();
        body.append('message', message);
        if (currentConversationId) {
            body.append('conversation_id', currentConversationId);
        } else {
            // New conversation — seed capability flags + model controls from the
            // panel so the first turn already honors them.
            if (dataToggle && dataToggle.checked) body.append('data_access', '1');
            if (webToggle && webToggle.checked) body.append('web_search', '1');
            controls.forEach(function (el) {
                var f = el.getAttribute('data-field');
                if (f === 'model' || f === 'thinking_level') body.append(f, el.value);
                else if (el.value !== '') body.append(f, el.value);
            });
        }

        fetch('/admin/joinery_ai/chat_send', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { setBusy(false); alert(data.message || 'Send failed.'); return; }

                if (data.is_new) {
                    currentConversationId = data.conversation_id;
                    addThread(data.conversation_id, data.title);
                    history.replaceState(null, '', '/admin/joinery_ai/chat?aic_conversation_id=' + data.conversation_id);
                }

                // Non-fpm fallback may finish the turn inline.
                if (data.status === 'complete') { appendReply(data.assistant_html); return; }
                if (data.status === 'failed') { setBusy(false); alert(data.error || 'Send failed.'); return; }

                // Async: stream the reply into a live bubble, then swap in the
                // final markdown bubble on completion.
                streamInto(data.message_id);
            })
            .catch(function () {
                setBusy(false);
                alert('Send failed.');
            });
    }

    function appendReply(html) {
        setBusy(false);
        transcript.insertAdjacentHTML('beforeend', html);
        scrollToBottom();
        input.focus();
    }

    var PIN_SVG = '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">'
        + '<path d="M16 3v2h-1v6l2 2v2h-4v6l-1 1-1-1v-6H7v-2l2-2V5H8V3z"/></svg>';

    function buildThreadItem(id, title, pinned) {
        var item = document.createElement('div');
        item.className = 'joai-chat-item' + (pinned ? ' is-pinned' : '');
        item.setAttribute('data-conversation-id', id);
        item.setAttribute('data-pinned', pinned ? '1' : '0');

        var a = document.createElement('a');
        a.className = 'joai-chat-list-item';
        a.href = '/admin/joinery_ai/chat?aic_conversation_id=' + id;
        a.innerHTML = '<span class="joai-chat-pin" aria-hidden="true">' + PIN_SVG + '</span>'
            + '<span class="joai-chat-item-title"></span>';
        a.querySelector('.joai-chat-item-title').textContent = title || 'Untitled';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'joai-chat-item-menu-btn';
        btn.setAttribute('aria-label', 'Thread actions');
        btn.setAttribute('aria-haspopup', 'true');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML = '&#8942;';

        var menu = document.createElement('div');
        menu.className = 'joai-chat-item-menu';
        menu.setAttribute('role', 'menu');
        menu.hidden = true;
        menu.innerHTML =
            '<button type="button" role="menuitem" data-action="pin">' + (pinned ? 'Unpin' : 'Pin') + '</button>'
            + '<button type="button" role="menuitem" data-action="rename">Rename</button>'
            + '<button type="button" role="menuitem" data-action="delete" class="joai-chat-menu-danger">Delete</button>';

        item.appendChild(a);
        item.appendChild(btn);
        item.appendChild(menu);
        return item;
    }

    function addThread(id, title) {
        var noThreads = document.getElementById('joai-no-threads');
        if (noThreads) noThreads.remove();
        document.querySelectorAll('.joai-chat-list-item.is-active')
            .forEach(function (a) { a.classList.remove('is-active'); });
        var item = buildThreadItem(id, title, false);
        item.querySelector('.joai-chat-list-item').classList.add('is-active');
        threads.insertBefore(item, threads.firstChild);
        resortThreads();
    }

    // Stable re-sort: pinned items first, original order preserved within each group.
    function resortThreads() {
        var items = Array.prototype.slice.call(threads.querySelectorAll('.joai-chat-item'));
        items.sort(function (a, b) {
            return (b.getAttribute('data-pinned') === '1' ? 1 : 0)
                 - (a.getAttribute('data-pinned') === '1' ? 1 : 0);
        });
        items.forEach(function (it) { threads.appendChild(it); });
    }

    // ----- Thread actions menu (pin / rename / delete) -----
    function closeAllMenus() {
        threads.querySelectorAll('.joai-chat-item-menu').forEach(function (m) { m.hidden = true; });
        threads.querySelectorAll('.joai-chat-item-menu-btn').forEach(function (b) {
            b.setAttribute('aria-expanded', 'false');
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.joai-chat-item-menu') && !e.target.closest('.joai-chat-item-menu-btn')) {
            closeAllMenus();
        }
    });

    function threadAction(id, action, value) {
        var body = new FormData();
        body.append('conversation_id', id);
        body.append('action', action);
        if (value !== undefined) body.append('value', value);
        return fetch('/admin/joinery_ai/chat_thread_action', { method: 'POST', body: body })
            .then(function (r) { return r.json(); });
    }

    function startRename(item) {
        var link = item.querySelector('.joai-chat-list-item');
        var titleSpan = item.querySelector('.joai-chat-item-title');
        if (item.querySelector('.joai-chat-rename')) return; // already editing
        var current = titleSpan.textContent;

        var edit = document.createElement('input');
        edit.type = 'text';
        edit.className = 'joai-chat-rename';
        edit.maxLength = 255;
        edit.value = current;
        link.style.display = 'none';
        item.insertBefore(edit, link);
        edit.focus();
        edit.select();

        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            var next = edit.value.trim();
            edit.remove();
            link.style.display = '';
            if (!commit || next === '' || next === current) return;
            titleSpan.textContent = next; // optimistic
            threadAction(item.getAttribute('data-conversation-id'), 'rename', next)
                .then(function (data) {
                    if (!data.success) { titleSpan.textContent = current; alert(data.message || 'Rename failed.'); }
                })
                .catch(function () { titleSpan.textContent = current; });
        }
        edit.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        edit.addEventListener('blur', function () { finish(true); });
    }

    function doDelete(item) {
        var id = item.getAttribute('data-conversation-id');
        var title = item.querySelector('.joai-chat-item-title').textContent;
        JoineryModal.confirm(
            'Delete the conversation "' + title + '"? This cannot be undone.',
            function () {
                threadAction(id, 'delete')
                    .then(function (data) {
                        if (!data.success) { alert(data.message || 'Delete failed.'); return; }
                        var wasActive = String(currentConversationId) === String(id);
                        item.remove();
                        if (!threads.querySelector('.joai-chat-item')) {
                            threads.innerHTML = '<p class="joai-chat-empty" id="joai-no-threads">No conversations yet.</p>';
                        }
                        if (wasActive) resetToNewChat();
                    })
                    .catch(function () { alert('Delete failed.'); });
            },
            { confirmLabel: 'Delete', confirmStyle: 'danger' }
        );
    }

    function doPin(item) {
        var id = item.getAttribute('data-conversation-id');
        var nowPinned = item.getAttribute('data-pinned') !== '1';
        threadAction(id, 'pin', nowPinned ? '1' : '0')
            .then(function (data) {
                if (!data.success) { alert(data.message || 'Could not update.'); return; }
                item.setAttribute('data-pinned', nowPinned ? '1' : '0');
                item.classList.toggle('is-pinned', nowPinned);
                var pinBtn = item.querySelector('[data-action="pin"]');
                if (pinBtn) pinBtn.textContent = nowPinned ? 'Unpin' : 'Pin';
                resortThreads();
            })
            .catch(function () { alert('Could not update.'); });
    }

    threads.addEventListener('click', function (e) {
        var trigger = e.target.closest('.joai-chat-item-menu-btn');
        if (trigger) {
            e.preventDefault();
            var menu = trigger.parentNode.querySelector('.joai-chat-item-menu');
            var isOpen = !menu.hidden;
            closeAllMenus();
            if (!isOpen) {
                menu.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                // Flip the menu above the trigger if it would spill past the
                // bottom of the scrollable thread list.
                menu.classList.remove('open-up');
                var scroll = trigger.closest('.joai-chat-list');
                if (scroll && menu.getBoundingClientRect().bottom > scroll.getBoundingClientRect().bottom) {
                    menu.classList.add('open-up');
                }
            }
            return;
        }
        var actionBtn = e.target.closest('.joai-chat-item-menu [data-action]');
        if (actionBtn) {
            e.preventDefault();
            var item = actionBtn.closest('.joai-chat-item');
            closeAllMenus();
            var action = actionBtn.getAttribute('data-action');
            if (action === 'pin') doPin(item);
            else if (action === 'rename') startRename(item);
            else if (action === 'delete') doDelete(item);
        }
    });

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
                if (!data.success) { setBusy(false); alert(data.message || 'Action failed.'); return; }

                // Non-fpm fallback may finish the resume inline.
                if (data.status === 'complete') { replaceBubble(data.message_id, data.assistant_html); return; }
                if (data.status === 'failed') { setBusy(false); alert(data.error || 'Action failed.'); return; }

                // Async: reuse the pending bubble as the live bubble, stream the
                // resumed reply into it, then swap in the final markdown bubble.
                streamInto(data.message_id);
            })
            .catch(function () {
                setBusy(false);
                alert('Action failed.');
            });
    });

    function replaceBubble(messageId, html) {
        setBusy(false);
        var bubble = transcript.querySelector('.joai-chat-msg[data-message-id="' + messageId + '"]');
        if (bubble) bubble.outerHTML = html;
        scrollToBottom();
    }

    function resetToNewChat() {
        currentConversationId = null;
        transcript.innerHTML = '<p class="joai-chat-empty" id="joai-blank">Start a new conversation below.</p>';
        document.querySelectorAll('.joai-chat-list-item.is-active')
            .forEach(function (a) { a.classList.remove('is-active'); });
        // New chats default to a plain assistant — reset the toggles to off and
        // clear per-chat overrides (numeric/instructions blank = inherit the
        // defaults). Model and thinking selects keep their current choice.
        if (dataToggle) dataToggle.checked = false;
        if (webToggle) webToggle.checked = false;
        controls.forEach(function (el) {
            var f = el.getAttribute('data-field');
            if (f === 'temperature' || f === 'top_p' || f === 'max_tokens' || f === 'instructions') el.value = '';
        });
        history.replaceState(null, '', '/admin/joinery_ai/chat');
        input.focus();
    }

    newChatBtn.addEventListener('click', resetToNewChat);

    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
})();
</script>
<?php
$page->admin_footer();
