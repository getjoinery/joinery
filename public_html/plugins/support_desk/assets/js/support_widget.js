/**
 * The support desk's public widget (/support_desk/ask): a visitor's question,
 * the answer streamed into the core transcript (window.JoineryChatTranscript),
 * follow-ups in the same conversation. The conversation lives in the browser
 * session; the first question of a session sends the captcha answer when the
 * page shows one.
 *
 * Vanilla JS, no framework. @version 0.1.0
 */
(function () {
	'use strict';
	var transcript = document.getElementById('sdk-widget-transcript');
	var input = document.getElementById('sdk-widget-input');
	var send = document.getElementById('sdk-widget-send');
	var errorBox = document.getElementById('sdk-widget-error');
	if (!transcript || !input || !send) return;
	var threadId = 0;
	var busy = false;

	function showError(text) {
		errorBox.textContent = text || '';
		errorBox.hidden = !text;
	}

	function captchaAnswer(body) {
		['h-captcha-response', 'g-recaptcha-response'].forEach(function (name) {
			var f = document.querySelector('[name="' + name + '"]');
			if (f && f.value) body[name] = f.value;
		});
	}

	function ask() {
		var text = input.value.trim();
		if (!text || busy) return;
		busy = true;
		send.disabled = true;
		showError('');
		var body = { message: text, thread_id: threadId };
		if (!threadId) captchaAnswer(body);
		window.joineryApi.post('support_desk/widget_ask', body).then(function (r) {
			threadId = r.thread_id;
			var captcha = document.getElementById('sdk-widget-captcha');
			if (captcha) captcha.hidden = true;
			input.value = '';
			var mine = document.createElement('div');
			mine.className = 'joai-chat-msg joai-chat-mine';
			var b = document.createElement('div');
			b.className = 'joai-chat-body';
			b.textContent = text;
			mine.appendChild(b);
			transcript.appendChild(mine);
			if (r.status !== 'running') { done(); return; }
			var bubble = JoineryChatTranscript.liveBubble(transcript, r.message_id);
			JoineryChatTranscript.poll({
				interval: 900,
				fetch: function () { return window.joineryApi.post('support_desk/widget_poll', { thread_id: threadId, message_id: r.message_id }); },
				onData: function (d) {
					if (d.status === 'running') { JoineryChatTranscript.showPartial(bubble, d.partial_text || '', d); return true; }
					if (d.status === 'failed') {
						JoineryChatTranscript.failedBubble(transcript, r.message_id, d.error, null);
					} else if (d.html) {
						var holder = document.createElement('div');
						holder.innerHTML = d.html;
						if (holder.firstElementChild) bubble.parentNode.replaceChild(holder.firstElementChild, bubble);
					}
					done();
					return false;
				},
				onGiveUp: function (msg) { JoineryChatTranscript.failedBubble(transcript, r.message_id, msg, null); done(); }
			});
		}).catch(function (err) {
			showError((err && err.message) || 'That did not go through. Try again.');
			done();
		});
	}

	function done() {
		busy = false;
		send.disabled = false;
	}

	send.addEventListener('click', ask);
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); ask(); }
	});
})();
