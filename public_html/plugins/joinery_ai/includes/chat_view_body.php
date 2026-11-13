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
 *   $data_access, $web_search, $history_access, $memory_access, $temperature, $top_p,
 *   $max_tokens, $instructions,
 *   $thinking_level, $default_model, $default_thinking_level, $default_web_search,
 *   $default_memory_access,
 *   $def_temperature, $def_top_p, $def_max_tokens, $brave_key_set, $chat_enabled
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatLevel.php'));

$tz = $session->get_timezone();
$selected_id = $selected ? (int)$selected->key : 0;
// Rows the selected chat still has to converge after a privacy change that
// stopped part-way; the page resumes it on load.
$level_remaining = $selected ? (new ChatConversationLevel($selected))->remaining() : 0;
// Effective model for the selected thread, used to price token usage.
$conv_model = $selected ? ChatRender::conversationModel($selected) : '';

// The queued-action cards resolve over /api/v1 (window.joineryApi + the
// session CSRF token). The public shell already emits both; the admin shell
// does not, so emit them here — a duplicate script tag just re-defines the
// same transport, and querySelector reads the first meta.
$joai_api_js = PathHelper::getIncludePath('assets/js/joinery-api.js');
echo '<script src="/assets/js/joinery-api.js?v='
   . (is_file($joai_api_js) ? filemtime($joai_api_js) : '1') . '"></script>' . "\n";
// Turns are drawn by the core transcript, the same one the Help panel uses:
// its sheet, and its module for the running turn (includes/ChatTranscript.php).
echo ChatTranscript::assets();
if ($session->is_logged_in()) {
    echo '<meta name="joinery-api-csrf" content="'
       . htmlspecialchars($session->get_api_csrf_token(), ENT_QUOTES, 'UTF-8') . '" />' . "\n";
}

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
                                    data-trust="<?php echo htmlspecialchars((string)($model_trust[$mid] ?? 'cloud'), ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $mid === $active_model ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$mlabel, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="joai-chat-consent-note" id="joai-consent-note" hidden
                       style="margin:4px 0 0;font-size:12px;color:#6b5200;">
                        Private content stays <?php echo htmlspecialchars(PrivateContentConsent::where($private_ai_consent ?? 'local'), ENT_QUOTES, 'UTF-8'); ?>,
                        so models elsewhere are greyed out for this chat. Change that under
                        <a href="/profile/security#private-ai-consent-block">Security › Encrypted Vault › Private content and AI</a>.
                    </p>

                    <?php
                    // Privacy level. Standard is always offered; Private needs a
                    // set-up vault. A level the current chat already sits at stays
                    // selectable even if a prerequisite later changed.
                    $level_opts = ['standard' => 'Standard — server-managed'];
                    if (!empty($private_available) || $security_level === 'private') {
                        $level_opts['private'] = 'Private — sealed, unlock to read';
                    }
                    // Extra protection under Private: the Local models only add-on,
                    // offered when a local model is configured. One-way — once a
                    // chat carries it the switch shows checked and locked.
                    $local_only_on    = !empty($local_models_only);
                    $local_only_fixed = $local_only_on && !empty($selected);
                    $show_local_only  = !empty($local_only_available) || $local_only_fixed;
                    ?>
                    <label>Privacy
                        <select id="joai-security-level" class="joai-chat-control" data-field="security_level"
                                title="How private this chat is">
                            <?php foreach ($level_opts as $lv => $lbl): ?>
                                <option value="<?php echo $lv; ?>" <?php echo $lv === $security_level ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($show_local_only): ?>
                    <div class="joai-chat-addons" id="joai-local-only-wrap"
                         <?php echo $security_level === 'private' ? '' : 'hidden'; ?>>
                        <span class="joai-chat-addons-heading">Extra protection</span>
                        <label class="joai-chat-toggle<?php echo $local_only_fixed ? ' is-disabled' : ''; ?>"
                               title="<?php echo $local_only_fixed ? 'Local models only stays on once it is on.' : 'Only AI models running on your own hardware can answer.'; ?>">
                            <input type="checkbox" id="joai-toggle-local-only" data-field="local_models_only"
                                   <?php echo $local_only_on ? 'checked' : ''; ?>
                                   <?php echo $local_only_fixed ? 'disabled' : ''; ?>>
                            Local models only
                        </label>
                        <p class="joai-chat-addon-note">Nothing in this chat is sent to an outside AI company.
                            Only AI models running on your own hardware can answer.</p>
                    </div>
                    <?php endif; ?>

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
                        <label class="joai-chat-toggle"
                               title="Let the assistant remember facts across chats and recall them automatically.">
                            <input type="checkbox" id="joai-toggle-memory" data-capability="memory_access"
                                   <?php echo $memory_access ? 'checked' : ''; ?>>
                            Memory
                        </label>
                    </div>

                    <label>Thinking
                        <select id="joai-thinking-level" class="joai-chat-control" data-field="thinking_level">
                            <?php foreach (AiModelRequirement::THINKING_LEVELS as $lv => $lbl): ?>
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
                    <?php elseif ($m->get('aim_role') === AiConversationMessage::ROLE_EVENT): ?>
                        <?php
                        $t = LibraryFunctions::convert_time($m->get('aim_create_time'), 'UTC', $tz, 'g:i A');
                        echo ChatRender::eventChip((string)$m->get('aim_content'), $t);
                        ?>
                    <?php else: ?>
                        <?php
                        $t = LibraryFunctions::convert_time($m->get('aim_create_time'), 'UTC', $tz, 'g:i A');
                        echo ChatRender::userBubble((string)$m->get('aim_content'), $t, (int)$m->key);
                        ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Waiting for you: this conversation's pending queued actions,
             rendered from server-stated facts (ai_actions_list) and resolved
             through ai_action_resolve — one object, one renderer, one
             execution path with the AI panel's list. -->
        <div class="joai-chat-queued" id="joai-queued" hidden></div>

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

<!-- WebAuthn/PRF helper for the vault unlock ceremony (must load before assets/js/chat.js). -->
<script src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<?php echo PageScript::json('joai-chat-config', array(
    'base'                => $base,
    'level_remaining'     => (int)$level_remaining,
    'selected_id'         => $selected_id ? (int)$selected_id : null,
    'default_model'       => $default_model,
    'default_thinking'    => $default_thinking_level,
    'default_web'         => (bool)$default_web_search,
    'default_memory'      => (bool)$default_memory_access,
    'default_local_only'  => (bool)ChatLevel::defaultLocalOnly(),
    'default_attach_mode' => $default_attachment_mode,
    'attach_max_files'    => (int)$attach_max_files,
    'consent'             => PrivateContentConsent::normalize($private_ai_consent ?? 'local'),
    'egress_restricted'   => !empty($egress_restricted),
)), PageScript::src('plugins/joinery_ai/assets/js/chat.js', false); ?>
