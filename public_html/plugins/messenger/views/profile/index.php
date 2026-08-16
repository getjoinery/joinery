<?php
/**
 * Messages — the member messaging app at /profile/messenger.
 *
 * Full-bleed app chrome (the same 'app' => true surface the mailbox and the
 * calendar use) holding a conversation rail beside the open thread. The page
 * ships the member's conversation list and, when ?c= names one, that thread's
 * newest page, embedded as JSON — so the app draws immediately with no
 * round-trip. Everything after that arrives through messenger_poll.
 *
 * @version 1.1.0
 * @changelog 1.1.0 - Unified picker (remote panel folded into the one search box); pick status line, standard-only note, admin not-set-up notice
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/messenger/logic/messenger_page_logic.php'));

$page_vars = process_logic(messenger_page_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Messages',
	'breadcrumbs' => array('Messages' => ''),
);
$page->public_header($hoptions, NULL);

$hoptions['app'] = true;
if (empty($unavailable)) {
	$hoptions['header_action'] = '<button type="button" class="btn btn-primary" id="msgr-new">New message</button>';
}
echo PublicPage::BeginPage('Messages', $hoptions);

if (!empty($unavailable)) {
	echo '<p>' . htmlspecialchars($unavailable, ENT_QUOTES, 'UTF-8') . '</p>';
	echo PublicPage::EndPage($hoptions);
	$page->public_footer();
	return;
}

$boot = array(
	'user_id'       => (int)$user_id,
	'settings'      => $client,
	'conversations' => $conversations,
	'open'          => $open,
	'federation'    => $federation,
);
?>
<div class="jy-ui msgr" id="msgr" data-pane="<?php echo $open ? 'thread' : 'list'; ?>">

	<aside class="msgr-rail">
		<div class="msgr-rail-head">
			<input type="search" id="msgr-filter" placeholder="Search conversations" aria-label="Search conversations">
		</div>
		<ul class="msgr-list" id="msgr-list" aria-label="Conversations"></ul>
	</aside>

	<section class="msgr-thread" id="msgr-thread" aria-label="Conversation">
		<div class="msgr-thread-head" id="msgr-thread-head" hidden>
			<button type="button" class="btn btn-sm btn-outline msgr-back" id="msgr-back" aria-label="Back to conversations">&larr;</button>
			<div class="msgr-thread-headings">
				<h2 id="msgr-title"></h2>
				<div class="msgr-thread-sub" id="msgr-subtitle"></div>
			</div>
			<span class="msgr-level-chip" id="msgr-level" hidden></span>
			<details class="jy-ui jy-actions-dropdown" id="msgr-menu">
				<summary class="btn btn-sm btn-secondary">More</summary>
				<div class="jy-actions-menu">
					<button type="button" data-msgr-menu="info">Group details</button>
					<button type="button" data-msgr-menu="protection">Protection&hellip;</button>
					<button type="button" data-msgr-menu="mute"></button>
					<button type="button" data-msgr-menu="leave">Leave group</button>
					<button type="button" data-msgr-menu="delete">Remove from my inbox</button>
				</div>
			</details>
		</div>

		<div class="msgr-error" id="msgr-error" role="alert"></div>

		<div class="msgr-empty" id="msgr-empty">
			<p>Pick a conversation, or start a new one.</p>
		</div>

		<div class="jy-chat-log msgr-log" id="msgr-log" hidden></div>
		<div class="msgr-receipt" id="msgr-receipt"></div>
		<div class="jy-chat-typing" id="msgr-typing"></div>

		<div class="msgr-reply-bar" id="msgr-reply-bar" hidden>
			<span>Replying to</span>
			<span class="msgr-reply-bar-body" id="msgr-reply-body"></span>
			<button type="button" class="btn btn-sm btn-outline" id="msgr-reply-cancel">Cancel</button>
		</div>

		<div class="jy-chat-tray" id="msgr-tray"></div>

		<form class="jy-chat-composer" id="msgr-composer" hidden>
			<input type="file" id="msgr-file" multiple hidden>
			<button type="button" class="jy-chat-composer-btn" id="msgr-attach" title="Attach a file" aria-label="Attach a file">+</button>
			<textarea id="msgr-input" rows="1" placeholder="Write a message" aria-label="Message"></textarea>
			<button type="submit" class="jy-chat-composer-btn jy-chat-composer-btn--send" id="msgr-send" title="Send" aria-label="Send">&uarr;</button>
		</form>
	</section>
</div>

<!-- New conversation / add people -->
<dialog class="msgr-dialog jy-ui" id="msgr-people-dialog">
	<form method="dialog">
		<div class="msgr-dialog-head">
			<h3 id="msgr-people-title">New message</h3>
		</div>
		<div class="msgr-dialog-body">
			<label class="msgr-field">
				<span id="msgr-name-label">Group name (optional)</span>
				<input type="text" id="msgr-group-name" maxlength="255" placeholder="Only needed for a group">
			</label>
			<div class="msgr-chips" id="msgr-picked"></div>
			<label class="msgr-field">
				<span>Find people</span>
				<input type="search" id="msgr-people-search" placeholder="<?php echo $federation['site_ready'] ? 'Search by name, contact, or address' : 'Search by name'; ?>" autocomplete="off">
			</label>
			<ul class="msgr-people" id="msgr-people-results"></ul>
			<p class="msgr-pick-status" id="msgr-pick-status" hidden></p>
			<?php if (($federation['admin_notice'] ?? '') === 'not_set_up'): ?>
			<p class="msgr-site-notice">Cross-site chat isn't set up on this site.
				<a href="/admin/admin_settings">Set up Joinery Direct</a></p>
			<?php elseif (($federation['admin_notice'] ?? '') === 'unpublished'): ?>
			<p class="msgr-site-notice">Joinery Direct is on, but this site's DNS records aren't published yet.
				<a href="/plugins/mailbox/admin/admin_mailbox_setup">Publish them on the Setup tab</a></p>
			<?php endif; ?>

			<p class="msgr-remote-level-note" id="msgr-remote-level-note" hidden>Cross-site conversations are Standard.</p>
			<div class="msgr-level-picker" id="msgr-new-level-picker">
				<?php
				// The shared protection-level cards — the same control, and the
				// same promises, a member sees anywhere on the platform.
				ProtectionLevelPicker::render($page->getFormWriter('msgr_new_level_form'), 'msgr_new_level', array(
					'service' => ProtectionLevelPicker::SERVICE_MESSAGING,
					'levels'  => Conversation::LEVELS,
					'value'   => $client['default_level'],
					'label'   => 'Protection',
				));
				?>
			</div>
		</div>
		<div class="msgr-dialog-foot">
			<button type="submit" class="btn btn-secondary" value="cancel">Cancel</button>
			<button type="button" class="btn btn-primary" id="msgr-people-confirm">Start</button>
		</div>
	</form>
</dialog>

<!-- Group details -->
<dialog class="msgr-dialog jy-ui" id="msgr-info-dialog">
	<form method="dialog">
		<div class="msgr-dialog-head">
			<h3>Group details</h3>
		</div>
		<div class="msgr-dialog-body">
			<label class="msgr-field" id="msgr-rename-field">
				<span>Group name</span>
				<input type="text" id="msgr-rename" maxlength="255">
			</label>
			<div class="msgr-dialog-foot" style="border:0;padding:0 0 var(--jy-space-3);">
				<button type="button" class="btn btn-sm btn-outline" id="msgr-photo-btn">Change picture</button>
				<button type="button" class="btn btn-sm btn-outline" id="msgr-rename-save">Save name</button>
			</div>
			<input type="file" id="msgr-photo-file" accept="image/*" hidden>
			<ul class="msgr-members" id="msgr-members"></ul>
			<button type="button" class="btn btn-sm btn-outline" id="msgr-add-member" style="margin-top: var(--jy-space-3);">Add people</button>
		</div>
		<div class="msgr-dialog-foot">
			<button type="submit" class="btn btn-secondary" value="close">Close</button>
		</div>
	</form>
</dialog>

<!-- Protection level -->
<dialog class="msgr-dialog jy-ui" id="msgr-protect-dialog">
	<form method="dialog">
		<div class="msgr-dialog-head">
			<h3>Protection</h3>
		</div>
		<div class="msgr-dialog-body">
			<p class="msgr-protect-note" id="msgr-protect-note"></p>
			<div class="msgr-level-picker">
				<?php
				ProtectionLevelPicker::render($page->getFormWriter('msgr_raise_level_form'), 'msgr_raise_level', array(
					'service' => ProtectionLevelPicker::SERVICE_MESSAGING,
					'levels'  => Conversation::LEVELS,
					'value'   => $open ? $open['conversation']['protection_level'] : $client['default_level'],
					'label'   => 'Protection for this conversation',
					'helptext' => 'Protection can be raised but never lowered — everyone in the conversation keeps what they have already been promised.',
				));
				?>
			</div>
		</div>
		<div class="msgr-dialog-foot">
			<button type="submit" class="btn btn-secondary" value="cancel">Cancel</button>
			<button type="button" class="btn btn-primary" id="msgr-protect-save">Apply</button>
		</div>
	</form>
</dialog>

<!-- Reaction picker -->
<dialog class="msgr-dialog jy-ui" id="msgr-emoji-dialog">
	<form method="dialog">
		<div class="msgr-dialog-head"><h3>React</h3></div>
		<div class="msgr-dialog-body">
			<div class="msgr-emoji-grid" id="msgr-emoji-grid"></div>
		</div>
		<div class="msgr-dialog-foot">
			<button type="submit" class="btn btn-secondary" value="cancel">Cancel</button>
		</div>
	</form>
</dialog>

<script id="msgr-boot" type="application/json"><?php
	// Message text is member-written and can contain anything, including the
	// characters that would end this element early. Escaping every '<' as a
	// JSON unicode escape keeps the payload inside its own tag whatever the
	// content — the value the browser parses is identical.
	echo str_replace('<', '\\u003C',
		json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
?></script>
<script src="/assets/js/joinery-poll.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/joinery-poll.js')) ?: '1'; ?>"></script>
<script src="/plugins/messenger/assets/js/messenger.js?v=<?php echo @filemtime(PathHelper::getIncludePath('plugins/messenger/assets/js/messenger.js')) ?: '1'; ?>"></script>
<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer(['track' => TRUE]);
?>
