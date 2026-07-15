<?php
/**
 * Shared chat body — the two-pane assistant markup + behavior, rendered inside
 * whichever page shell included it (AdminPage for /admin/joinery_ai/chat,
 * PublicPage for /profile/joinery_ai/chat). Surface-independent: every endpoint
 * URL and thread link is built from $base, so the same markup/JS drives both.
 *
 * Expected in scope (set by the including view, mostly from the chat logic):
 *   $base            URL prefix with trailing slash, e.g. '/admin/joinery_ai/'
 *   $session         SessionControl
 *   $conversations, $selected, $messages
 *   $models, $active_model, $model_privacy
 *   $data_access, $web_search, $history_access, $temperature, $top_p, $max_tokens, $instructions,
 *   $thinking_level, $default_model, $default_thinking_level, $default_web_search,
 *   $def_temperature, $def_top_p, $def_max_tokens, $brave_key_set, $chat_enabled
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatLevel.php'));

$tz = $session->get_timezone();
$selected_id = $selected ? (int)$selected->key : 0;
// Effective model for the selected thread, used to price token usage.
$conv_model = $selected ? ChatRender::conversationModel($selected) : '';

if (!function_exists('joai_pin_svg')) {
    // Small thumbtack marker shown beside pinned threads (no emoji; inline SVG
    // matches the admin theme's icon approach).
    function joai_pin_svg(): string {
        return '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">'
             . '<path d="M16 3v2h-1v6l2 2v2h-4v6l-1 1-1-1v-6H7v-2l2-2V5H8V3z"/></svg>';
    }
}
?>
<div class="joai-chat-wrap">

    <aside class="joai-chat-list">
        <button type="button" id="joai-new-chat" class="joai-btn joai-btn-primary joai-chat-newbtn">+ New chat</button>
        <input type="search" id="joai-thread-search" class="joai-chat-search"
               placeholder="Search conversations" aria-label="Search conversations" autocomplete="off">
        <nav class="joai-chat-threads" id="joai-threads">
            <?php if (!count($conversations)): ?>
                <p class="joai-chat-empty" id="joai-no-threads">No conversations yet.</p>
            <?php endif; ?>
            <?php foreach ($conversations as $c):
                $cid = (int)$c->key;
                // A locked protected chat withholds its title — a placeholder
                // stands in until the owner unlocks (Phase 4 locked-state).
                if (ChatSeal::isLocked($c)) {
                    $title = ChatSeal::LOCKED_TITLE;
                } else {
                    $title = trim((string)$c->get('aic_title'));
                    if ($title === '') $title = 'Untitled';
                }
                $active = $cid === $selected_id ? ' is-active' : '';
                $pinned = (bool)$c->get('aic_pinned');
            ?>
                <div class="joai-chat-item<?php echo $pinned ? ' is-pinned' : ''; ?>"
                     data-conversation-id="<?php echo $cid; ?>" data-pinned="<?php echo $pinned ? '1' : '0'; ?>">
                    <a class="joai-chat-list-item<?php echo $active; ?>"
                       href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>chat?aic_conversation_id=<?php echo $cid; ?>">
                        <span class="joai-chat-pin" aria-hidden="true"><?php echo joai_pin_svg(); ?></span>
                        <span class="joai-chat-item-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                    <button type="button" class="joai-chat-item-menu-btn" aria-label="Thread actions" aria-haspopup="true" aria-expanded="false">&#8942;</button>
                    <div class="joai-chat-item-menu" role="menu" hidden>
                        <button type="button" role="menuitem" data-action="pin"><?php echo $pinned ? 'Unpin' : 'Pin'; ?></button>
                        <button type="button" role="menuitem" data-action="rename">Rename</button>
                        <button type="button" role="menuitem" data-action="export">Export</button>
                        <button type="button" role="menuitem" data-action="delete" class="joai-chat-menu-danger">Delete</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="joai-chat-list-backdrop" id="joai-list-backdrop" hidden></div>

    <section class="joai-chat-main">
        <div class="joai-chat-mobilebar">
            <button type="button" class="joai-chat-list-toggle" id="joai-list-toggle" aria-expanded="false">
                <span aria-hidden="true">&#9776;</span> Conversations
            </button>
        </div>
        <?php
        $model_options = $models;
        if ($active_model !== '' && !array_key_exists($active_model, $model_options)) {
            $model_options = [$active_model => $active_model . ' (unavailable)'] + $model_options;
        }
        ?>
        <div class="joai-chat-status">
            <details class="joai-chat-settings">
                <summary>
                    <span class="joai-chat-settings-summary" id="joai-settings-summary"></span>
                    <span class="joai-chat-settings-gear"><span aria-hidden="true">⚙</span><span class="joai-chat-settings-gear-label"> Settings</span></span>
                </summary>
                <div class="joai-chat-settings-body">
                    <label>Model
                        <select id="joai-model" class="joai-chat-control" data-field="model" title="Model (provider follows the model)">
                            <?php foreach ($model_options as $mid => $mlabel): ?>
                                <option value="<?php echo htmlspecialchars((string)$mid, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-private="<?php echo !empty($model_privacy[$mid]) ? '1' : '0'; ?>"
                                    data-local="<?php echo ChatLevel::isLocalModel((string)$mid) ? '1' : '0'; ?>"
                                    <?php echo $mid === $active_model ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$mlabel, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php
                    // Privacy level. Standard is always offered; Private needs a
                    // set-up vault; Fortress needs a vault + a configured local
                    // model. A level the current chat already sits at stays
                    // selectable even if a prerequisite later changed.
                    $level_opts = ['standard' => 'Standard — server-managed'];
                    if (!empty($private_available) || in_array($security_level, ['private', 'fortress'], true)) {
                        $level_opts['private'] = 'Private — sealed, unlock to read';
                    }
                    if (!empty($fortress_available) || $security_level === 'fortress') {
                        $level_opts['fortress'] = 'Fortress — sealed + local model only';
                    }
                    ?>
                    <label>Privacy
                        <select id="joai-security-level" class="joai-chat-control" data-field="security_level"
                                title="How private this chat is (sealing + local-only inference)">
                            <?php foreach ($level_opts as $lv => $lbl): ?>
                                <option value="<?php echo $lv; ?>" <?php echo $lv === $security_level ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="joai-chat-settings-toggles">
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
                        <label class="joai-chat-toggle"
                               title="Let the assistant search your own past conversations.">
                            <input type="checkbox" id="joai-toggle-history" data-capability="history_access"
                                   <?php echo $history_access ? 'checked' : ''; ?>>
                            History search
                        </label>
                    </div>

                    <label>Thinking
                        <select id="joai-thinking-level" class="joai-chat-control" data-field="thinking_level">
                            <?php foreach (['off'=>'Off','low'=>'Low','medium'=>'Medium','high'=>'High'] as $lv => $lbl): ?>
                                <option value="<?php echo $lv; ?>" <?php echo $lv === $thinking_level ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Attachments
                        <select id="joai-attachment-mode" class="joai-chat-control" data-field="attachment_mode"
                                title="How uploaded files are sent to the model">
                            <?php foreach (['extract'=>'Text only','on_demand'=>'Full file when needed','original'=>'Always full file'] as $am => $amlbl): ?>
                                <option value="<?php echo $am; ?>" <?php echo $am === $attachment_mode ? 'selected' : ''; ?>><?php echo $amlbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="joai-chat-settings-title">Advanced</div>
                    <div class="joai-chat-settings-row">
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
                    </div>
                    <label>Max reply length (tokens)
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
            <button type="button" class="joai-chat-fullscreen-toggle" id="joai-fullscreen-toggle"
                    aria-pressed="false" aria-label="Maximize chat" title="Maximize chat">
                <span class="joai-icon-max" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m13-5v3a2 2 0 0 1-2 2h-3"/></svg></span>
                <span class="joai-icon-min" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3m13-5h-3a2 2 0 0 0-2 2v3"/></svg></span>
                <span class="joai-fs-label" aria-hidden="true">Minimize</span>
            </button>
        </div>

        <div class="joai-chat-transcript" id="joai-transcript"
             data-locked="<?php echo (!empty($selected_locked)) ? '1' : '0'; ?>">
            <?php if (!$selected): ?>
                <p class="joai-chat-empty" id="joai-blank">Start a new conversation below.</p>
            <?php elseif (!empty($selected_locked)): ?>
                <div class="joai-chat-locked" id="joai-locked-notice"
                     style="margin:24px auto;max-width:420px;text-align:center;padding:20px;border:1px solid #d0d0d0;border-radius:8px;">
                    <p style="margin:0 0 12px;font-weight:600;">🔒 This chat is protected</p>
                    <p style="margin:0 0 16px;color:#555;font-size:14px;line-height:1.5;">
                        Its messages are sealed. Unlock your vault to read and continue this conversation.</p>
                    <button type="button" id="joai-unlock-btn" class="joai-btn joai-btn-primary">Unlock to read</button>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <?php if ($m->get('aim_role') === AiConversationMessage::ROLE_ASSISTANT): ?>
                        <?php echo ChatRender::assistantBubble($m, $tz, $conv_model); ?>
                    <?php else: ?>
                        <?php
                        $t = LibraryFunctions::convert_time($m->get('aim_create_time'), 'UTC', $tz, 'g:i A');
                        echo ChatRender::userBubble((string)$m->get('aim_content'), $t, (int)$m->key);
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

        <div class="joai-chat-attach-strip" id="joai-attach-strip" hidden></div>

        <div class="joai-chat-send-notice" id="joai-send-notice" role="alert" hidden
             style="margin:0 0 8px;padding:8px 12px;border:1px solid #e0a800;background:#fff8e1;color:#6b5200;border-radius:6px;font-size:13px;line-height:1.4;"></div>

        <div class="joai-chat-composer">
            <input type="file" id="joai-file-input" class="joai-chat-file-input" multiple
                   accept="<?php echo htmlspecialchars($attach_accept_types, ENT_QUOTES, 'UTF-8'); ?>"
                   <?php echo $chat_enabled ? '' : 'disabled'; ?>>
            <button type="button" id="joai-attach-btn" class="joai-btn joai-chat-attach-btn"
                    title="Attach files (images, PDF, text)" aria-label="Attach files"
                    <?php echo $chat_enabled ? '' : 'disabled'; ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></button>
            <textarea id="joai-input" rows="2" maxlength="8000"
                      placeholder="Ask Joinery AI to look something up or make a change…"
                      <?php echo $chat_enabled ? '' : 'disabled'; ?>></textarea>
            <button type="button" id="joai-send" class="joai-btn joai-btn-primary"
                    <?php echo $chat_enabled ? '' : 'disabled'; ?>>Send</button>
        </div>
        <?php if (!$chat_enabled): ?>
            <p class="joai-chat-empty">Chat is currently disabled in settings.</p>
        <?php endif; ?>

        <div class="joai-chat-meta" id="joai-chat-meta"<?php echo $selected ? '' : ' hidden'; ?>>
            <span class="joai-chat-meta-usage" title="Estimated tokens and cost for this conversation">
                <span id="joai-usage-value"><?php
                    if ($selected) {
                        $ci = (int)$selected->get('aic_total_input_tokens');
                        $co = (int)$selected->get('aic_total_output_tokens');
                        echo htmlspecialchars(
                            ChatRender::conversationUsageLabel($ci, $co, ChatRender::estimateCost($conv_model, $ci, $co)),
                            ENT_QUOTES, 'UTF-8'
                        );
                    }
                ?></span>
            </span>
        </div>
    </section>
</div>

<!-- WebAuthn/PRF helper for the vault unlock ceremony (must load before the inline script). -->
<script src="/assets/js/passkeys.js"></script>
<script>
(function () {
    // Endpoint + link base for this surface (admin vs profile). Every fetch and
    // thread link is built from it, so this one value is the only difference.
    var JOAI_BASE = <?php echo json_encode($base); ?>;

    // --- Vault unlock (protected chats) -----------------------------------
    // Mirrors the mailbox reader: a protected chat's content actions prompt a
    // one-tap passkey unlock, then re-run. The unlock ceremony hits the shared
    // /api/v1 vault endpoints (session cookie + X-Joinery-Csrf), so unlocking
    // here also opens mail's window and vice versa (one vault window per user).
    function joaiCsrf() {
        var meta = document.querySelector('meta[name="joinery-api-csrf"]');
        if (meta && meta.content) return meta.content;
        var m = document.cookie.match(/(?:^|;\s*)joinery_api_csrf=([^;]+)/);
        return m ? decodeURIComponent(m[1]) : '';
    }
    function joaiApiV1(action, payload) {
        return fetch('/api/v1/action/' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': joaiCsrf() },
            body: JSON.stringify(payload || {})
        }).then(function (r) { return r.json(); });
    }
    // Runs the passkey PRF unlock ceremony. Resolves to true on success.
    function unlockVault() {
        if (!window.JoineryPasskeys) { alert('Unlock is unavailable on this page.'); return Promise.resolve(false); }
        return joaiApiV1('vault_unlock_options', {}).then(function (opt) {
            var options = opt && opt.data ? opt.data.options : null;
            if (!options) throw new Error('no unlock options');
            return window.JoineryPasskeys.derive(options);
        }).then(function (res) {
            return joaiApiV1('vault_unlock_passkey', { credential: res.response });
        }).then(function (res) {
            return !!(res && res.data && res.data.unlocked);
        }).catch(function () { return false; });
    }
    var unlockBtn = document.getElementById('joai-unlock-btn');
    if (unlockBtn) {
        unlockBtn.addEventListener('click', function () {
            unlockBtn.disabled = true;
            unlockVault().then(function (ok) {
                if (ok) { location.reload(); }
                else { unlockBtn.disabled = false; alert('Could not unlock the vault. Try again, or unlock from your security settings.'); }
            });
        });
    }

    var transcript = document.getElementById('joai-transcript');
    var input = document.getElementById('joai-input');
    var sendBtn = document.getElementById('joai-send');
    var thinking = document.getElementById('joai-thinking');
    var threads = document.getElementById('joai-threads');
    var newChatBtn = document.getElementById('joai-new-chat');
    var dataToggle = document.getElementById('joai-toggle-data');
    var webToggle = document.getElementById('joai-toggle-web');
    var historyToggle = document.getElementById('joai-toggle-history');
    var modelSelect = document.getElementById('joai-model');
    var thinkingSelect = document.getElementById('joai-thinking-level');
    var sensitiveNotice = document.getElementById('joai-sensitive-notice');
    var currentConversationId = <?php echo $selected_id ? $selected_id : 'null'; ?>;

    // Mobile: the thread list is an off-canvas drawer below 767px. Tapping the
    // "Conversations" toggle slides it in over the chat pane; tapping the
    // backdrop, a thread, or "+ New chat" closes it again.
    var chatWrap = document.querySelector('.joai-chat-wrap');
    var listToggle = document.getElementById('joai-list-toggle');
    var listBackdrop = document.getElementById('joai-list-backdrop');
    function setListOpen(open) {
        if (!chatWrap) return;
        chatWrap.classList.toggle('list-open', open);
        if (listBackdrop) listBackdrop.hidden = !open;
        if (listToggle) listToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (listToggle) {
        listToggle.addEventListener('click', function () {
            setListOpen(!chatWrap.classList.contains('list-open'));
        });
    }
    if (listBackdrop) listBackdrop.addEventListener('click', function () { setListOpen(false); });
    // "+ New chat" resets the composer via AJAX (no reload), so close the drawer
    // to reveal the fresh chat. Thread links navigate, closing the drawer anyway.
    if (newChatBtn) newChatBtn.addEventListener('click', function () { setListOpen(false); });

    // Mobile: maximize the chat to a full-viewport overlay (over the site header,
    // page heading, and footer), and minimize it back into the page. The button
    // toggles a class on the wrap; CSS pins it to the viewport and locks the
    // background scroll. Esc also exits.
    var fullscreenToggle = document.getElementById('joai-fullscreen-toggle');
    function setFullscreen(on) {
        if (!chatWrap) return;
        chatWrap.classList.toggle('joai-fullscreen', on);
        document.body.classList.toggle('joai-fullscreen-lock', on);
        if (fullscreenToggle) {
            fullscreenToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            fullscreenToggle.setAttribute('aria-label', on ? 'Minimize chat' : 'Maximize chat');
            fullscreenToggle.setAttribute('title', on ? 'Minimize chat' : 'Maximize chat');
        }
        if (on) scrollToBottom();
    }
    if (fullscreenToggle) {
        fullscreenToggle.addEventListener('click', function () {
            setFullscreen(!chatWrap.classList.contains('joai-fullscreen'));
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && chatWrap && chatWrap.classList.contains('joai-fullscreen')) {
            setFullscreen(false);
        }
    });

    // New-chat defaults the composer resets to.
    var DEFAULT_MODEL = <?php echo json_encode($default_model); ?>;
    var DEFAULT_THINKING = <?php echo json_encode($default_thinking_level); ?>;
    var DEFAULT_WEB = <?php echo $default_web_search ? 'true' : 'false'; ?>;
    var DEFAULT_ATTACH_MODE = <?php echo json_encode($default_attachment_mode); ?>;

    // Attachment composer config: how many files a message accepts. Type/size and
    // model-capability policy are enforced authoritatively server-side (ingress
    // fails loud), so the client only bounds the count and previews selections.
    var ATTACH_MAX_FILES = <?php echo (int)$attach_max_files; ?>;

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
            fetch(JOAI_BASE + 'chat_poll?message_id=' + encodeURIComponent(messageId))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { onFailed(data.message || 'Could not load the reply.'); return; }
                    // Vault locked mid-turn (idle close, or a lock from another
                    // tab): one-tap unlock, then resume polling where we left off.
                    if (data.locked) {
                        unlockVault().then(function (ok) {
                            if (ok) { tick(); return; }
                            onFailed(data.message || 'Unlock your vault to view this reply.');
                        });
                        return;
                    }
                    // Cancelled settles like complete: the server renders the kept
                    // partial answer with its own "Cancelled" marker, so the same
                    // handler swaps the final bubble in and unlocks the composer.
                    if (data.status === 'complete' || data.status === 'cancelled') { onComplete(data.assistant_html, data.conversation_usage); return; }
                    if (data.status === 'failed') { onFailed(data.error || 'The assistant could not complete this turn.'); return; }
                    if (typeof data.partial_text === 'string') onPartial(data.partial_text, data);
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
        el.innerHTML = '<div class="joai-chat-body"></div><div class="joai-chat-activity" hidden></div>';
        scrollToBottom();
        return el;
    }

    // "2m 40s" from a seconds count, for the live activity line.
    function formatElapsed(seconds) {
        seconds = Math.max(0, Math.floor(seconds));
        if (seconds < 60) return seconds + 's';
        return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
    }

    // Show a live bubble for messageId and stream into it until the turn lands.
    // While it runs, the runner's stage label + elapsed time render under the
    // streaming text ("Waiting for glm-5p2… · 2m 40s") so the quiet stretch
    // before the first token is legible instead of an anonymous indicator.
    function streamInto(messageId) {
        inflightMessageId = messageId;   // now the Cancel button has a target
        var bubble = ensureLiveBubble(messageId);
        var body = bubble.querySelector('.joai-chat-body');
        var activityEl = bubble.querySelector('.joai-chat-activity');
        pollMessage(messageId,
            function (text, data) {
                body.textContent = text;
                var line = (data && data.activity) ? data.activity : '';
                if (line && typeof data.running_seconds === 'number') {
                    line += ' · ' + formatElapsed(data.running_seconds);
                }
                activityEl.textContent = line;
                activityEl.hidden = !line;
                scrollToBottom();
            },
            function (html, usage) { replaceBubble(messageId, html); updateUsage(usage); },
            function (err) { renderFailedBubble(messageId, err); });
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
            fetch(JOAI_BASE + 'chat_set_capabilities', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (!data.success) { alert(data.message || 'Could not update.'); el.checked = !el.checked; } })
                .catch(function () { el.checked = !el.checked; });
        });
    }
    wireToggle(dataToggle);
    wireToggle(webToggle);
    wireToggle(historyToggle);

    // Model controls (model, thinking level, temperature, top_p, max tokens,
    // instructions). On an existing chat each persists immediately; on a new chat
    // their values ride the first send. Same endpoint, generic {field, value}.
    var controls = Array.prototype.slice.call(document.querySelectorAll('.joai-chat-control'));
    function wireField(el) {
        el.addEventListener('change', function () {
            if (!currentConversationId) return; // new chat: seeded on first send
            var field = el.getAttribute('data-field');
            function send(retried) {
                var body = new FormData();
                body.append('conversation_id', currentConversationId);
                body.append('field', field);
                body.append('value', el.value);
                fetch(JOAI_BASE + 'chat_set_capabilities', { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        // Locked vault (a protected chat's instructions are sealed
                        // content): one-tap unlock, then retry the same change.
                        // A refused/failed unlock reloads so the control shows the
                        // real stored value instead of an unsaved edit.
                        if (data.locked && !retried) {
                            unlockVault().then(function (ok) {
                                if (ok) { send(true); return; }
                                alert(data.message || 'Unlock your vault to edit this protected chat.');
                                location.reload();
                            });
                            return;
                        }
                        if (!data.success || data.locked) {
                            alert(data.message || 'Could not update.');
                            location.reload();
                            return;
                        }
                        // Changing the privacy level reseals/reveals stored content and
                        // may re-pin the model — reload so the whole page reflects it.
                        if (field === 'security_level') location.reload();
                    })
                    .catch(function () {});
            }
            send(false);
        });
    }
    controls.forEach(wireField);

    // ----- Fortress model gate -----
    // A Fortress chat pins inference to a local model: disable the cloud options
    // in the picker when Privacy = Fortress, and switch off a cloud selection.
    var levelSelect = document.getElementById('joai-security-level');
    function applyFortressModelGate() {
        if (!modelSelect || !levelSelect) return;
        var fortress = levelSelect.value === 'fortress';
        var firstLocal = null;
        Array.prototype.forEach.call(modelSelect.options, function (opt) {
            var isLocal = opt.getAttribute('data-local') === '1';
            opt.disabled = fortress && !isLocal;
            if (isLocal && firstLocal === null) firstLocal = opt.value;
        });
        if (fortress && modelSelect.selectedOptions[0]
                && modelSelect.selectedOptions[0].getAttribute('data-local') !== '1'
                && firstLocal !== null) {
            modelSelect.value = firstLocal;
        }
        updateSettingsSummary();
    }
    if (levelSelect) levelSelect.addEventListener('change', applyFortressModelGate);
    applyFortressModelGate();

    // --- File attachments ---------------------------------------------------
    // Files chosen in the composer, held client-side until send() posts them as
    // multipart `attachments[]`. The strip shows a removable chip per file.
    var fileInput = document.getElementById('joai-file-input');
    var attachBtn = document.getElementById('joai-attach-btn');
    var attachStrip = document.getElementById('joai-attach-strip');
    var pendingFiles = [];

    function renderAttachStrip() {
        if (!attachStrip) return;
        if (!pendingFiles.length) { attachStrip.hidden = true; attachStrip.innerHTML = ''; return; }
        attachStrip.hidden = false;
        attachStrip.innerHTML = '';
        pendingFiles.forEach(function (file, idx) {
            var chip = document.createElement('span');
            chip.className = 'joai-chat-attach joai-chat-attach-pending';
            var name = document.createElement('span');
            name.className = 'joai-chat-attach-name';
            name.textContent = file.name;
            chip.appendChild(name);
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'joai-chat-attach-remove';
            rm.setAttribute('aria-label', 'Remove ' + file.name);
            rm.textContent = '×';
            rm.addEventListener('click', function () {
                pendingFiles.splice(idx, 1);
                renderAttachStrip();
            });
            chip.appendChild(rm);
            attachStrip.appendChild(chip);
        });
    }

    function addFiles(list) {
        for (var i = 0; i < list.length; i++) {
            if (pendingFiles.length >= ATTACH_MAX_FILES) {
                alert('Up to ' + ATTACH_MAX_FILES + ' files per message.');
                break;
            }
            pendingFiles.push(list[i]);
        }
        renderAttachStrip();
    }

    if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length) addFiles(fileInput.files);
            fileInput.value = ''; // allow re-selecting the same file
        });
    }

    // Drag-and-drop onto the composer area.
    var composer = document.querySelector('.joai-chat-composer');
    if (composer) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            composer.addEventListener(ev, function (e) {
                e.preventDefault(); composer.classList.add('joai-chat-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            composer.addEventListener(ev, function (e) {
                e.preventDefault(); composer.classList.remove('joai-chat-dragover');
            });
        });
        composer.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                addFiles(e.dataTransfer.files);
            }
        });
    }

    function clearPendingFiles() {
        pendingFiles = [];
        renderAttachStrip();
    }

    function clearBlankNotice() {
        var blank = document.getElementById('joai-blank');
        if (blank) blank.remove();
    }

    // Inline amber note above the composer for a rejected send (e.g. an attachment
    // the current model can't read) — replaces a blocking popup.
    function showSendNotice(msg) {
        var n = document.getElementById('joai-send-notice');
        if (!n) return;
        n.textContent = '⚠ ' + (msg || 'Send failed.');
        n.hidden = false;
    }
    function hideSendNotice() {
        var n = document.getElementById('joai-send-notice');
        if (n) { n.hidden = true; n.textContent = ''; }
    }

    // The RUNNING assistant message this composer is streaming, so the Send
    // button (morphed to Cancel while busy) can post it. Set when a poll starts,
    // cleared when the turn settles.
    var inflightMessageId = null;

    // While a turn runs the composer is locked and Send is idle — reuse that real
    // estate: morph Send into an active Cancel, and revert on EVERY terminal path
    // (complete / failed / cancelled all route through setBusy(false)).
    function setBusy(busy) {
        input.disabled = busy;
        thinking.hidden = !busy;
        if (busy) {
            sendBtn.disabled = false;              // stays clickable — to cancel
            sendBtn.textContent = 'Cancel';
            sendBtn.classList.add('joai-chat-cancel-active');
            sendBtn.classList.remove('joai-btn-primary');
            scrollToBottom();
        } else {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send';
            sendBtn.classList.remove('joai-chat-cancel-active');
            sendBtn.classList.add('joai-btn-primary');
            inflightMessageId = null;
        }
    }

    // Post a cancel for the in-flight turn. Leaves the poll running — the worker
    // flips the row to cancelled and the poll renders the kept partial answer, so
    // a turn that happens to finish first still lands correctly (no optimistic
    // teardown). The button is disabled until the poll settles (revert via
    // setBusy(false)) to prevent a double-post.
    function cancelInflight() {
        if (!inflightMessageId) return;            // nothing pollable to cancel yet
        sendBtn.disabled = true;
        var body = new FormData();
        body.append('message_id', inflightMessageId);
        fetch(JOAI_BASE + 'chat_cancel', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .catch(function () { sendBtn.disabled = false; }); // let them retry on a network error
    }

    // Conversation token/cost bar under the composer. The server is the source of
    // truth (it rolls per-turn tokens into the thread total), so each completed
    // turn hands back a fresh preformatted label and we just set it.
    var usageMeta = document.getElementById('joai-chat-meta');
    var usageValue = document.getElementById('joai-usage-value');
    function updateUsage(usage) {
        if (!usage || !usageValue) return;
        if (typeof usage.label === 'string') usageValue.textContent = usage.label;
        if (usageMeta) usageMeta.hidden = false;
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

    // ----- Settings summary -----
    // The control bar is a single line summarizing the active settings; every
    // control lives behind the ⚙ Settings disclosure. Keep the summary in sync
    // as those controls change (and after a new-chat reset).
    var settingsSummary = document.getElementById('joai-settings-summary');
    function updateSettingsSummary() {
        if (!settingsSummary) return;
        var parts = [];
        if (modelSelect && modelSelect.selectedOptions[0]) {
            // Drop the parenthetical pricing/provider note for the one-liner.
            parts.push(modelSelect.selectedOptions[0].textContent.trim().split(' (')[0]);
        }
        if (dataToggle && dataToggle.checked) parts.push('Data access');
        if (webToggle && webToggle.checked) parts.push('Web search');
        if (historyToggle && historyToggle.checked) parts.push('History search');
        if (thinkingSelect && thinkingSelect.value && thinkingSelect.value !== 'off') {
            parts.push('Thinking: ' + thinkingSelect.selectedOptions[0].textContent.trim());
        }
        settingsSummary.textContent = parts.join('  ·  ');
    }
    [modelSelect, dataToggle, webToggle, historyToggle, thinkingSelect].forEach(function (el) {
        if (el) el.addEventListener('change', updateSettingsSummary);
    });
    updateSettingsSummary();

    // The last message actually submitted, so an inline failed bubble can offer a
    // Retry that replays it (the File objects stay valid — they aren't revoked).
    var lastTurn = null;
    function retryLastTurn() {
        if (!lastTurn) return;
        input.value = lastTurn.message;
        pendingFiles = lastTurn.files.slice();
        renderAttachStrip();
        updateSensitivityNotice();
        send();
    }

    function send() {
        var message = input.value.trim();
        var files = pendingFiles.slice();
        // A turn needs either text or at least one attachment.
        if (!message && !files.length) return;
        clearBlankNotice();
        hideSendNotice();
        lastTurn = { message: message, files: files.slice() };

        // Optimistically show the user's message (server returns canonical HTML
        // on the next load; here we echo the text + any attachment names).
        var mine = document.createElement('div');
        mine.className = 'joai-chat-msg joai-chat-mine';
        if (message) {
            var mbody = document.createElement('div');
            mbody.className = 'joai-chat-body';
            mbody.textContent = message;
            mine.appendChild(mbody);
        }
        if (files.length) {
            var strip = document.createElement('div');
            strip.className = 'joai-chat-attachments';
            files.forEach(function (f) {
                var chip = document.createElement('span');
                chip.className = 'joai-chat-attach joai-chat-attach-file';
                var nm = document.createElement('span');
                nm.className = 'joai-chat-attach-name';
                nm.textContent = f.name;
                chip.appendChild(nm);
                strip.appendChild(chip);
            });
            mine.appendChild(strip);
        }
        transcript.appendChild(mine);

        input.value = '';
        clearPendingFiles();
        updateSensitivityNotice();
        setBusy(true);

        var body = new FormData();
        body.append('message', message);
        files.forEach(function (f) { body.append('attachments[]', f, f.name); });
        if (currentConversationId) {
            body.append('conversation_id', currentConversationId);
        } else {
            // New conversation — seed capability flags + model controls from the
            // panel so the first turn already honors them.
            if (dataToggle && dataToggle.checked) body.append('data_access', '1');
            if (webToggle && webToggle.checked) body.append('web_search', '1');
            if (historyToggle && historyToggle.checked) body.append('history_access', '1');
            controls.forEach(function (el) {
                var f = el.getAttribute('data-field');
                if (f === 'model' || f === 'thinking_level' || f === 'attachment_mode') body.append(f, el.value);
                else if (el.value !== '') body.append(f, el.value);
            });
        }

        // Roll the optimistic echo back and surface an inline amber note (not a
        // popup) when the send is rejected — e.g. an attachment the current model
        // can't read. Restores the typed message and pending files to fix + resend.
        function rejectSend(msg) {
            setBusy(false);
            if (mine && mine.parentNode) mine.parentNode.removeChild(mine);
            input.value = message;
            pendingFiles = files.slice();
            renderAttachStrip();
            updateSensitivityNotice();
            showSendNotice(msg);
        }

        fetch(JOAI_BASE + 'chat_send', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { rejectSend(data.message); return; }

                // Protected chat + locked vault: restore the composer, unlock, resend.
                if (data.locked) {
                    setBusy(false);
                    if (mine && mine.parentNode) mine.parentNode.removeChild(mine);
                    input.value = message; pendingFiles = files.slice(); renderAttachStrip(); updateSensitivityNotice();
                    unlockVault().then(function (ok) {
                        if (ok) send();
                        else showSendNotice('Unlock your vault to send in this protected chat.');
                    });
                    return;
                }

                if (data.is_new) {
                    currentConversationId = data.conversation_id;
                    addThread(data.conversation_id, data.title);
                    history.replaceState(null, '', JOAI_BASE + 'chat?aic_conversation_id=' + data.conversation_id);
                }

                // Message sent, but one or more attachments failed server-side and
                // won't reach the model — surface it inline (the send itself stands).
                if (data.attachment_warning) { showSendNotice(data.attachment_warning); }

                // Non-fpm fallback may finish the turn inline.
                if (data.status === 'complete') { appendReply(data.assistant_html); updateUsage(data.conversation_usage); return; }
                if (data.status === 'failed') { renderFailedBubble(data.message_id, data.error || 'Send failed.'); return; }

                // Async: stream the reply into a live bubble, then swap in the
                // final markdown bubble on completion.
                streamInto(data.message_id);
            })
            .catch(function () {
                rejectSend('Send failed — please check your connection and try again.');
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
        a.href = JOAI_BASE + 'chat?aic_conversation_id=' + id;
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
            + '<button type="button" role="menuitem" data-action="export">Export</button>'
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
        return fetch(JOAI_BASE + 'chat_thread_action', { method: 'POST', body: body })
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
            var id = item.getAttribute('data-conversation-id');
            function revert(msg) { titleSpan.textContent = current; if (msg) alert(msg); }
            threadAction(id, 'rename', next)
                .then(function (data) {
                    // Locked vault (a protected chat's title is sealed content):
                    // one-tap unlock, then retry the rename.
                    if (data.locked) {
                        unlockVault().then(function (ok) {
                            if (!ok) { revert(data.message || 'Unlock your vault to rename this protected chat.'); return; }
                            threadAction(id, 'rename', next).then(function (d2) {
                                if (!d2.success || d2.locked) revert(d2.message || 'Rename failed.');
                            }).catch(function () { revert(); });
                        });
                        return;
                    }
                    if (!data.success) revert(data.message || 'Rename failed.');
                })
                .catch(function () { revert(); });
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
            'Delete the conversation "' + title + '"? It will be removed from your list.',
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

    // ----- Export a thread (Markdown or plain text; Copy or Download) -----
    function slugifyTitle(title) {
        var s = (title || 'conversation').toLowerCase()
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
        return s || 'conversation';
    }
    function downloadText(filename, text) {
        var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }
    function openExportDialog(data) {
        var slug = slugifyTitle(data.title);
        var wrap = document.createElement('div');
        wrap.className = 'joai-export';
        wrap.innerHTML =
            '<p class="joai-export-title"></p>'
            + '<fieldset class="joai-export-formats">'
            + '<label><input type="radio" name="joai-export-format" value="markdown" checked>'
            + ' Markdown <span class="joai-export-hint">source — paste where Markdown renders</span></label>'
            + '<label><input type="radio" name="joai-export-format" value="text">'
            + ' Plain text <span class="joai-export-hint">paste-ready for social media</span></label>'
            + '</fieldset>';
        wrap.querySelector('.joai-export-title').textContent =
            'Export “' + (data.title || 'Untitled') + '”';

        function chosen() {
            var sel = wrap.querySelector('input[name="joai-export-format"]:checked');
            var fmt = sel ? sel.value : 'markdown';
            return fmt === 'text'
                ? { content: data.text, ext: 'txt' }
                : { content: data.markdown, ext: 'md' };
        }

        JoineryModal.open(wrap, {
            buttons: [
                { label: 'Cancel', style: 'secondary' },
                { label: 'Download', style: 'secondary', onClick: function () {
                    var c = chosen();
                    downloadText(slug + '.' + c.ext, c.content);
                } },
                // Copy runs synchronously off this click (content is already in
                // memory), so the clipboard write keeps the user-activation.
                { label: 'Copy', style: 'primary', onClick: function () {
                    var c = chosen();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(c.content).catch(function () { fallbackCopy(c.content); });
                    } else {
                        fallbackCopy(c.content);
                    }
                } }
            ]
        });
    }
    function doExport(item, retried) {
        var id = item.getAttribute('data-conversation-id');
        fetch(JOAI_BASE + 'chat_export?conversation_id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Locked vault (an export is a content read): one-tap unlock,
                // then retry the export.
                if (data.locked && !retried) {
                    unlockVault().then(function (ok) {
                        if (ok) { doExport(item, true); return; }
                        alert(data.message || 'Unlock your vault to export this chat.');
                    });
                    return;
                }
                if (!data.success) { alert(data.message || 'Export failed.'); return; }
                openExportDialog(data);
            })
            .catch(function () { alert('Export failed.'); });
    }

    // ----- Thread search (debounced; title + message-content match) -----
    var threadSearch = document.getElementById('joai-thread-search');
    function renderThreadList(list) {
        threads.innerHTML = '';
        if (!list.length) {
            threads.innerHTML = '<p class="joai-chat-empty" id="joai-no-threads">No conversations found.</p>';
            return;
        }
        list.forEach(function (c) {
            var item = buildThreadItem(c.id, c.title, c.pinned);
            if (String(c.id) === String(currentConversationId)) {
                item.querySelector('.joai-chat-list-item').classList.add('is-active');
            }
            threads.appendChild(item);
        });
    }
    if (threadSearch) {
        var searchTimer = null;
        var searchSeq = 0;
        threadSearch.addEventListener('input', function () {
            var term = threadSearch.value.trim();
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                var seq = ++searchSeq;
                fetch(JOAI_BASE + 'chat_list?search=' + encodeURIComponent(term))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (seq !== searchSeq) return; // a newer query superseded this one
                        if (!data || !data.success) return;
                        renderThreadList(data.conversations || []);
                        // Locked vault can't search sealed chats — offer to unlock.
                        if (data.search_locked) {
                            var b = document.createElement('button');
                            b.type = 'button';
                            b.className = 'joai-btn joai-chat-search-unlock';
                            b.style.cssText = 'margin:8px;font-size:13px;';
                            b.textContent = 'Unlock to search protected chats';
                            b.addEventListener('click', function () {
                                unlockVault().then(function (ok) {
                                    if (ok) threadSearch.dispatchEvent(new Event('input'));
                                });
                            });
                            threads.insertBefore(b, threads.firstChild);
                        }
                    })
                    .catch(function () {});
            }, 250);
        });
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
            else if (action === 'export') doExport(item);
            else if (action === 'delete') doDelete(item);
        }
    });

    // ----- Per-turn actions (copy / delete) -----
    // Event-delegated so it works for bubbles rendered on load and ones swapped
    // in after a turn completes.
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        ta.remove();
    }
    function copyTurn(bubble, btn) {
        var text = bubble.getAttribute('data-raw') || '';
        var prev = btn.textContent;
        function flash() {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = prev; }, 1200);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(flash, function () { fallbackCopy(text); flash(); });
        } else {
            fallbackCopy(text);
            flash();
        }
    }
    function deleteTurn(bubble) {
        var id = bubble.getAttribute('data-message-id');
        if (!id) return;
        // Deleting a query (your bubble) takes its reply with it; deleting a
        // standalone reply removes just that one.
        var isUser = bubble.classList.contains('joai-chat-mine');
        var prompt = isUser
            ? 'Delete this message and the reply to it? It will be removed from the conversation.'
            : 'Delete this message? It will be removed from the conversation.';
        JoineryModal.confirm(
            prompt,
            function () {
                var body = new FormData();
                body.append('message_id', id);
                body.append('action', 'delete');
                fetch(JOAI_BASE + 'chat_turn_action', { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) { alert(data.message || 'Delete failed.'); return; }
                        var ids = (data.deleted_ids && data.deleted_ids.length) ? data.deleted_ids : [id];
                        ids.forEach(function (mid) {
                            var el = transcript.querySelector('.joai-chat-msg[data-message-id="' + mid + '"]');
                            if (el) el.remove();
                        });
                    })
                    .catch(function () { alert('Delete failed.'); });
            },
            { confirmLabel: 'Delete', confirmStyle: 'danger' }
        );
    }
    transcript.addEventListener('click', function (e) {
        var actionBtn = e.target.closest('.joai-chat-action');
        if (!actionBtn) return;
        var bubble = actionBtn.closest('.joai-chat-msg');
        if (!bubble) return;
        var action = actionBtn.getAttribute('data-action');
        if (action === 'copy') copyTurn(bubble, actionBtn);
        else if (action === 'delete') deleteTurn(bubble);
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

        fetch(JOAI_BASE + 'chat_confirm', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { setBusy(false); alert(data.message || 'Action failed.'); return; }

                // Protected chat + locked vault: unlock, then reload so the pending
                // card re-renders for a fresh confirm.
                if (data.locked) {
                    setBusy(false);
                    card.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
                    unlockVault().then(function (ok) { if (ok) location.reload(); });
                    return;
                }

                // Non-fpm fallback may finish the resume inline.
                if (data.status === 'complete') { replaceBubble(data.message_id, data.assistant_html); updateUsage(data.conversation_usage); return; }
                if (data.status === 'failed') { renderFailedBubble(data.message_id, data.error || 'Action failed.'); return; }

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

    // A failed turn renders inline in the transcript (never a popup): the live
    // bubble for this message becomes an error card carrying the server's message
    // text, with a Retry that replays the last submitted turn. The error string is
    // set via textContent — provider error text is not trusted markup.
    function renderFailedBubble(messageId, errorText) {
        setBusy(false);
        var el = transcript.querySelector('.joai-chat-msg[data-message-id="' + messageId + '"]');
        if (!el) {
            el = document.createElement('div');
            el.setAttribute('data-message-id', messageId);
            transcript.appendChild(el);
        }
        el.className = 'joai-chat-msg joai-chat-assistant joai-chat-failed';
        el.textContent = '';
        var body = document.createElement('div');
        body.className = 'joai-chat-body';
        body.textContent = errorText || 'The turn could not be completed.';
        el.appendChild(body);
        if (lastTurn) {
            var actions = document.createElement('div');
            actions.className = 'joai-chat-failed-actions';
            var retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'joai-chat-retry';
            retry.textContent = 'Retry';
            retry.addEventListener('click', function () {
                if (el.parentNode) el.parentNode.removeChild(el);
                retryLastTurn();
            });
            actions.appendChild(retry);
            el.appendChild(actions);
        }
        scrollToBottom();
    }

    function resetToNewChat() {
        currentConversationId = null;
        transcript.innerHTML = '<p class="joai-chat-empty" id="joai-blank">Start a new conversation below.</p>';
        // A fresh thread has no usage yet — clear and hide the bar until a turn lands.
        if (usageValue) usageValue.textContent = '';
        if (usageMeta) usageMeta.hidden = true;
        document.querySelectorAll('.joai-chat-list-item.is-active')
            .forEach(function (a) { a.classList.remove('is-active'); });
        // New chats reset to the configured defaults: model + thinking back to
        // their defaults, web search on (when a search key is set), data access
        // off, and per-chat numeric/instruction overrides cleared (blank =
        // inherit the default).
        if (dataToggle) dataToggle.checked = false;
        if (webToggle && !webToggle.disabled) webToggle.checked = DEFAULT_WEB;
        if (historyToggle) historyToggle.checked = false;
        if (modelSelect) modelSelect.value = DEFAULT_MODEL;
        if (thinkingSelect) thinkingSelect.value = DEFAULT_THINKING;
        controls.forEach(function (el) {
            var f = el.getAttribute('data-field');
            if (f === 'temperature' || f === 'top_p' || f === 'max_tokens' || f === 'instructions') el.value = '';
            if (f === 'attachment_mode') el.value = DEFAULT_ATTACH_MODE;
        });
        clearPendingFiles();
        updateSettingsSummary();
        history.replaceState(null, '', JOAI_BASE + 'chat');
        input.focus();
    }

    newChatBtn.addEventListener('click', resetToNewChat);

    // Close the settings panel when clicking outside it (popover behavior).
    var settingsPanel = document.querySelector('.joai-chat-settings');
    if (settingsPanel) {
        document.addEventListener('click', function (e) {
            if (settingsPanel.open && !e.target.closest('.joai-chat-settings')) {
                settingsPanel.open = false;
            }
        });
    }

    // The Send button doubles as Cancel while a turn is in flight (setBusy morphs
    // it), so the click is state-aware.
    sendBtn.addEventListener('click', function () {
        if (sendBtn.classList.contains('joai-chat-cancel-active')) cancelInflight();
        else send();
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
})();
</script>
