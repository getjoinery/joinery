/*
 * /profile/conversation: send a message, in a thread or to start one
 * (views/profile/conversation.php).
 *
 * #conversation-config: { compose, conversation_id, recipient_id }.
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function() {
	var messagesDiv = document.getElementById('conversation-messages');
	var input = document.getElementById('message-input');
	var sendBtn = document.getElementById('send-btn');
	var CONV = (window.joineryPageData && joineryPageData('conversation-config')) || {};
	var isCompose = !!CONV.compose;
	var conversationId = CONV.conversation_id || null;
	var recipientId = CONV.recipient_id || null;
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
