/**
 * The chat transcript in the browser: the turn still being written, drawn the
 * way includes/ChatTranscript.php draws a finished one (same joai-chat-*
 * markup, styled by assets/css/chat_transcript.css). Used by Joinery AI's chat
 * and by the Help panel, so a running turn looks and behaves the same in both.
 *
 *   JoineryChatTranscript.liveBubble(container, messageId)   the streaming bubble
 *   JoineryChatTranscript.showPartial(bubble, text, data)    its text + status line
 *   JoineryChatTranscript.failedBubble(container, id, text, onRetry)
 *   JoineryChatTranscript.poll({fetch, onData, interval, giveUp, onGiveUp})
 *   JoineryChatTranscript.formatElapsed(seconds)              "2m 40s"
 *
 * poll() owns only the timing: it calls fetch() (a Promise of the decoded
 * answer), hands each answer to onData(data, resume), and asks again after
 * `interval` while onData returns true. onData returning false stops the loop;
 * it may call resume() later (after an unlock, say) to start it again. A
 * network error is retried until `giveUp` milliseconds have passed, then
 * onGiveUp(message) is called.
 *
 * Vanilla JS, no framework. @version 1.0.0
 */
(function () {
	'use strict';

	function formatElapsed(seconds) {
		seconds = Math.max(0, Math.floor(seconds));
		if (seconds < 60) return seconds + 's';
		return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
	}

	function liveBubble(container, messageId) {
		var el = container.querySelector('.joai-chat-msg[data-message-id="' + messageId + '"]');
		if (!el) {
			el = document.createElement('div');
			el.setAttribute('data-message-id', messageId);
			container.appendChild(el);
		}
		el.className = 'joai-chat-msg joai-chat-assistant joai-chat-streaming';
		el.innerHTML = '<div class="joai-chat-body"></div><div class="joai-chat-activity" hidden></div>';
		return el;
	}

	function showPartial(bubble, text, data) {
		var body = bubble.querySelector('.joai-chat-body');
		var activity = bubble.querySelector('.joai-chat-activity');
		if (body) body.textContent = text || '';
		if (!activity) return;
		var line = (data && data.activity) ? data.activity : '';
		if (line && data && typeof data.running_seconds === 'number') {
			line += ' · ' + formatElapsed(data.running_seconds);
		}
		activity.textContent = line;
		activity.hidden = !line;
	}

	function failedBubble(container, messageId, text, onRetry) {
		var el = messageId ? container.querySelector('.joai-chat-msg[data-message-id="' + messageId + '"]') : null;
		if (!el) {
			el = document.createElement('div');
			if (messageId) el.setAttribute('data-message-id', messageId);
			container.appendChild(el);
		}
		el.className = 'joai-chat-msg joai-chat-assistant joai-chat-failed';
		el.textContent = '';
		var body = document.createElement('div');
		body.className = 'joai-chat-body';
		body.textContent = text || 'The turn could not be completed.';
		el.appendChild(body);
		if (typeof onRetry === 'function') {
			var actions = document.createElement('div');
			actions.className = 'joai-chat-failed-actions';
			var retry = document.createElement('button');
			retry.type = 'button';
			retry.className = 'joai-chat-retry';
			retry.textContent = 'Retry';
			retry.addEventListener('click', function () {
				if (el.parentNode) el.parentNode.removeChild(el);
				onRetry();
			});
			actions.appendChild(retry);
			el.appendChild(actions);
		}
		return el;
	}

	function poll(opts) {
		var interval = opts.interval || 600;
		var giveUp = opts.giveUp || 3600 * 1000;
		var startedAt = Date.now();
		var stopped = false;
		function resume() {
			stopped = false;
			tick();
		}
		function later() {
			if (!stopped) setTimeout(tick, interval);
		}
		function tick() {
			if (stopped) return;
			opts.fetch().then(function (data) {
				var keepGoing = opts.onData(data, resume);
				if (!keepGoing) { stopped = true; return; }
				if (Date.now() - startedAt > giveUp) {
					stopped = true;
					if (opts.onGiveUp) opts.onGiveUp('This is taking longer than expected. It may still finish — reload to check.');
					return;
				}
				later();
			}).catch(function () {
				if (Date.now() - startedAt > giveUp) {
					stopped = true;
					if (opts.onGiveUp) opts.onGiveUp('Lost connection while waiting for the reply.');
					return;
				}
				later();
			});
		}
		setTimeout(tick, interval);
		return { stop: function () { stopped = true; } };
	}

	window.JoineryChatTranscript = {
		formatElapsed: formatElapsed,
		liveBubble: liveBubble,
		showPartial: showPartial,
		failedBubble: failedBubble,
		poll: poll
	};
})();
