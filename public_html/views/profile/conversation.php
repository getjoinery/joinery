<?php
/**
 * Single conversation view + compose mode
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('conversation_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));

$page_vars = process_logic(conversation_logic($_GET, $_POST));

$page = new MemberPage();
$page->member_header([
	'title' => $page_vars['title'],
]);

$session = SessionControl::get_instance();
$current_user_id = $session->get_user_id();
$is_compose = $page_vars['is_compose_mode'];
$conversation = $page_vars['conversation'];
$other_user = $page_vars['other_user'];
$other_name = $other_user ? htmlspecialchars($other_user->display_name(), ENT_QUOTES, 'UTF-8') : 'Unknown';
?>

<div class="conversation-page">
	<!-- Header -->
	<div class="conversation-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
		<div style="display:flex;align-items:center;gap:0.75rem;">
			<a href="/profile/conversations" style="text-decoration:none;color:inherit;font-size:1.2rem;" title="Back to Messages">&larr;</a>
			<strong><?php echo $other_name; ?></strong>
		</div>
		<?php if (!$is_compose && $conversation): ?>
		<details class="conversation-more-menu" style="position:relative;">
			<summary class="btn btn-sm btn-outline" style="cursor:pointer;">More</summary>
			<div class="conversation-dropdown" style="position:absolute;right:0;top:100%;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.1);z-index:10;min-width:160px;margin-top:4px;">
				<button type="button" class="conversation-action-btn" data-action="<?php echo $page_vars['is_muted'] ? 'unmute' : 'mute'; ?>" data-conversation-id="<?php echo (int)$conversation->key; ?>" style="display:block;width:100%;text-align:left;padding:0.5rem 1rem;border:none;background:none;cursor:pointer;">
					<?php echo $page_vars['is_muted'] ? 'Unmute conversation' : 'Mute conversation'; ?>
				</button>
				<button type="button" class="conversation-action-btn" data-action="delete" data-conversation-id="<?php echo (int)$conversation->key; ?>" style="display:block;width:100%;text-align:left;padding:0.5rem 1rem;border:none;background:none;cursor:pointer;color:#c00;">
					Delete conversation
				</button>
			</div>
		</details>
		<?php endif; ?>
	</div>

	<!-- Messages -->
	<div class="conversation-messages" id="conversation-messages">
		<?php if (!$is_compose && $page_vars['messages']): ?>
			<?php
			$pager = $page_vars['pager'];
			if ($pager && $pager->total_pages() > 1 && $pager->is_valid_page('-1')):
			?>
			<div style="text-align:center;padding:0.5rem;">
				<a href="<?php echo htmlspecialchars($pager->get_url('-1'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline">Load older messages</a>
			</div>
			<?php endif; ?>

			<?php foreach ($page_vars['messages'] as $msg):
				$is_mine = ($msg->get('msg_usr_user_id_sender') == $current_user_id);
				$bubble_class = $is_mine ? 'message-mine' : 'message-theirs';
				$body = htmlspecialchars($msg->get('msg_body'), ENT_QUOTES, 'UTF-8');
				$time = LibraryFunctions::convert_time(
					$msg->get('msg_sent_time'), 'UTC',
					$session->get_timezone(), 'g:i A'
				);
				$date = LibraryFunctions::convert_time(
					$msg->get('msg_sent_time'), 'UTC',
					$session->get_timezone(), 'M j, Y'
				);
			?>
				<div class="message-bubble <?php echo $bubble_class; ?>">
					<?php if (!$is_mine && $other_user): ?>
						<div class="message-sender"><?php echo $other_name; ?></div>
					<?php endif; ?>
					<div class="message-body"><?php echo nl2br($body); ?></div>
					<div class="message-time" title="<?php echo htmlspecialchars($date . ' ' . $time, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($time, ENT_QUOTES, 'UTF-8'); ?></div>
				</div>
			<?php endforeach; ?>

			<?php if ($pager && $pager->total_pages() > 1 && $pager->is_valid_page('+1')): ?>
			<div style="text-align:center;padding:0.5rem;">
				<a href="<?php echo htmlspecialchars($pager->get_url('+1'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline">Load newer messages</a>
			</div>
			<?php endif; ?>
		<?php elseif ($is_compose): ?>
			<p style="text-align:center;color:#888;padding:2rem 0;">Start a conversation with <?php echo $other_name; ?></p>
		<?php endif; ?>
	</div>

	<!-- Compose area -->
	<div class="conversation-compose">
		<textarea id="message-input" placeholder="Type a message..." rows="2" maxlength="5000"></textarea>
		<button type="button" id="send-btn" class="btn btn-primary">Send</button>
	</div>
</div>

<style>
.conversation-page { display: flex; flex-direction: column; min-height: 400px; }
.conversation-messages { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem 0; flex: 1; }
.message-bubble { max-width: 70%; padding: 0.75rem 1rem; border-radius: 1rem; word-wrap: break-word; }
.message-bubble.message-mine { align-self: flex-end; background: var(--color-primary, #2563eb); color: #fff; border-bottom-right-radius: 0.25rem; }
.message-bubble.message-theirs { align-self: flex-start; background: #f0f0f0; color: #333; border-bottom-left-radius: 0.25rem; }
.message-sender { font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem; }
.message-body { line-height: 1.4; }
.message-time { font-size: 0.75rem; opacity: 0.7; margin-top: 0.25rem; }
.message-bubble.message-mine .message-time { text-align: right; }
.conversation-compose { display: flex; gap: 0.5rem; padding: 1rem 0; border-top: 1px solid #eee; align-items: flex-end; }
.conversation-compose textarea { flex: 1; resize: none; min-height: 44px; max-height: 120px; padding: 0.5rem 0.75rem; border: 1px solid #ccc; border-radius: 0.5rem; font-family: inherit; font-size: 0.95rem; }
.conversation-compose textarea:focus { outline: none; border-color: var(--color-primary, #2563eb); }
.conversation-action-btn:hover { background: #f5f5f5; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
	var messagesDiv = document.getElementById('conversation-messages');
	var input = document.getElementById('message-input');
	var sendBtn = document.getElementById('send-btn');
	var isCompose = <?php echo $is_compose ? 'true' : 'false'; ?>;
	var conversationId = <?php echo $conversation ? (int)$conversation->key : 'null'; ?>;
	var recipientId = <?php echo $is_compose ? (int)$page_vars['recipient_id'] : 'null'; ?>;

	// Scroll to bottom
	if (messagesDiv) {
		messagesDiv.scrollTop = messagesDiv.scrollHeight;
	}

	// Send message
	function sendMessage() {
		var body = input.value.trim();
		if (!body) return;

		sendBtn.disabled = true;
		var formData = new FormData();
		formData.append('action', 'send_message');
		formData.append('body', body);

		if (conversationId) {
			formData.append('conversation_id', conversationId);
		} else if (recipientId) {
			formData.append('recipient_user_id', recipientId);
		}

		fetch('/ajax/conversations_ajax', {
			method: 'POST',
			body: formData
		}).then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success) {
				if (!conversationId && data.conversation_id) {
					// New conversation created — redirect to it
					window.location.href = '/profile/conversation?id=' + data.conversation_id;
					return;
				}
				// Append message to DOM
				if (data.message_html) {
					var placeholder = messagesDiv.querySelector('p[style*="color:#888"]');
					if (placeholder) placeholder.remove();
					messagesDiv.insertAdjacentHTML('beforeend', data.message_html);
					messagesDiv.scrollTop = messagesDiv.scrollHeight;
				}
				input.value = '';
			} else {
				alert(data.message || 'Failed to send message');
			}
			sendBtn.disabled = false;
		}).catch(function() {
			alert('Failed to send message');
			sendBtn.disabled = false;
		});
	}

	sendBtn.addEventListener('click', sendMessage);

	input.addEventListener('keydown', function(e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendMessage();
		}
	});

	// Auto-resize textarea
	input.addEventListener('input', function() {
		this.style.height = 'auto';
		this.style.height = Math.min(this.scrollHeight, 120) + 'px';
	});

	// More menu actions
	document.querySelectorAll('.conversation-action-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var action = this.getAttribute('data-action');
			var cnvId = this.getAttribute('data-conversation-id');

			if (action === 'delete') {
				if (!confirm('Delete this conversation? It will be removed from your inbox.')) return;
			}

			var formData = new FormData();
			if (action === 'mute') {
				formData.append('action', 'mute_conversation');
			} else if (action === 'unmute') {
				formData.append('action', 'unmute_conversation');
			} else if (action === 'delete') {
				formData.append('action', 'delete_conversation');
			}
			formData.append('conversation_id', cnvId);

			fetch('/ajax/conversations_ajax', {
				method: 'POST',
				body: formData
			}).then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					if (action === 'delete') {
						window.location.href = '/profile/conversations';
					} else {
						window.location.reload();
					}
				} else {
					alert(data.message || 'Action failed');
				}
			});
		});
	});
});
</script>

<?php
$page->member_footer();
?>
