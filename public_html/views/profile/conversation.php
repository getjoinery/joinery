<?php
/**
 * Single conversation view + compose mode
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('conversation_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(conversation_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
	'title' => $page_vars['title'],
]);

$session = SessionControl::get_instance();
$current_user_id = $session->get_user_id();
$is_compose = $page_vars['is_compose_mode'];
$conversation = $page_vars['conversation'];
$other_user = $page_vars['other_user'];
$other_name = $other_user ? htmlspecialchars($other_user->display_name(), ENT_QUOTES, 'UTF-8') : 'Unknown';
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-narrow">

<div class="conversation-page">
	<!-- Header -->
	<div class="conversation-header">
		<div class="jy-convo-back-wrap">
			<a href="/profile/conversations" class="jy-convo-back" title="Back to Messages">&larr;</a>
			<strong><?php echo $other_name; ?></strong>
		</div>
		<?php if (!$is_compose && $conversation): ?>
		<details class="conversation-more-menu">
			<summary class="btn btn-sm btn-outline jy-convo-summary">More</summary>
			<div class="conversation-dropdown">
				<button type="button" class="conversation-action-btn" data-action="<?php echo $page_vars['is_muted'] ? 'unmute' : 'mute'; ?>" data-conversation-id="<?php echo (int)$conversation->key; ?>">
					<?php echo $page_vars['is_muted'] ? 'Unmute conversation' : 'Mute conversation'; ?>
				</button>
				<button type="button" class="conversation-action-btn jy-convo-delete" data-action="delete" data-conversation-id="<?php echo (int)$conversation->key; ?>">
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
			<div class="jy-convo-loadmore">
				<a href="<?php echo htmlspecialchars($pager->get_url('-1'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline">Load older messages</a>
			</div>
			<?php endif; ?>

			<?php foreach ($page_vars['messages'] as $msg):
				$is_mine = ($msg->get('msg_usr_user_id_sender') == $current_user_id);
				$bubble_class = $is_mine ? 'message-mine' : 'message-theirs';
				$body = htmlspecialchars($msg->get('msg_body'), ENT_QUOTES, 'UTF-8');
				$time = $msg->get_local('msg_sent_time', 'g:i A');
				$date = $msg->get_local('msg_sent_time', 'M j, Y');
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
			<div class="jy-convo-loadmore">
				<a href="<?php echo htmlspecialchars($pager->get_url('+1'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline">Load newer messages</a>
			</div>
			<?php endif; ?>
		<?php elseif ($is_compose): ?>
			<p class="jy-convo-placeholder">Start a conversation with <?php echo $other_name; ?></p>
		<?php endif; ?>
	</div>

	<!-- Compose area -->
	<div class="conversation-compose">
		<textarea id="message-input" placeholder="Type a message..." rows="2" maxlength="5000"></textarea>
		<button type="button" id="send-btn" class="btn btn-primary">Send</button>
	</div>
</div>

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
		var params = { body: body };
		if (conversationId) {
			params.conversation_id = conversationId;
		} else if (recipientId) {
			params.to = recipientId;
		}

		joineryApi.post('conversation_send', params).then(function(data) {
			if (!conversationId && data.conversation_id) {
				// New conversation created — redirect to it
				window.location.href = '/profile/conversation?id=' + data.conversation_id;
				return;
			}
			// Append message to DOM
			var placeholder = messagesDiv.querySelector('.jy-convo-placeholder');
			if (placeholder) placeholder.remove();
			var bubble = document.createElement('div');
			bubble.className = 'message-bubble message-mine';
			var bodyDiv = document.createElement('div');
			bodyDiv.className = 'message-body';
			bodyDiv.innerHTML = data.body.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
			var timeDiv = document.createElement('div');
			timeDiv.className = 'message-time';
			timeDiv.textContent = new Date(data.sent_time + 'Z').toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
			bubble.appendChild(bodyDiv);
			bubble.appendChild(timeDiv);
			messagesDiv.appendChild(bubble);
			messagesDiv.scrollTop = messagesDiv.scrollHeight;
			input.value = '';
			sendBtn.disabled = false;
		}).catch(function(err) {
			alert(err.message || 'Failed to send message');
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

			joineryApi.post('conversation_action', { conversation_id: cnvId, action: action }).then(function() {
				if (action === 'delete') {
					window.location.href = '/profile/conversations';
				} else {
					window.location.reload();
				}
			}).catch(function(err) {
				alert(err.message || 'Action failed');
			});
		});
	});
});
</script>

        </div>
    </div>
</section>
</div>
<?php
$page->public_footer();
?>
