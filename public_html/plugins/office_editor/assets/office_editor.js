/* The document editor page (views/profile/edit.php). Vanilla JS.
 *
 * Asks office_editor_open for the editor's address and a token (starting the
 * editor first when it is not running), POSTs the token into the editor frame,
 * and turns the editor's postMessage notices into the bar's Saved / Saving…
 * line. Close asks the editor to save, then goes back to the folder.
 *
 * @version 0.1.0
 */
(function () {
	'use strict';

	var CFG = window.OFFICE_EDITOR || {};
	var api = window.joineryApi;
	var frame, statusEl, messageEl;
	var startedAt = Date.now();
	var START_WAIT_MS = 60000;
	var closing = false;
	var modified = false;   // the editor's last word: unsaved changes or not

	function $(id) { return document.getElementById(id); }

	function setStatus(text) { statusEl.textContent = text || ''; }

	// A message in place of the editor, with a way out: back to the folder, and
	// the file itself.
	function showMessage(text, offerDownload) {
		frame.hidden = true;
		messageEl.hidden = false;
		messageEl.textContent = '';
		var p = document.createElement('p');
		p.textContent = text;
		messageEl.appendChild(p);
		if (offerDownload) {
			if (CFG.downloadUrl) {
				var dl = document.createElement('a');
				dl.className = 'btn btn-primary';
				dl.href = CFG.downloadUrl;
				dl.textContent = 'Download the file';
				messageEl.appendChild(dl);
			}
			var back = document.createElement('a');
			back.className = 'btn btn-secondary';
			back.href = CFG.closeUrl || '/drive';
			back.textContent = 'Back to Drive';
			messageEl.appendChild(back);
		}
	}

	function open() {
		api.post('office_editor/office_editor_open', { file_id: CFG.fileId, origin: window.location.origin })
			.then(function (r) {
				if (r.status === 'starting') {
					if (Date.now() - startedAt > START_WAIT_MS) {
						showMessage('The editor is taking too long to start. Try again in a minute, or download the file.', true);
						return;
					}
					showMessage('Starting the editor…', false);
					setTimeout(open, 1500);
					return;
				}
				launch(r);
			})
			.catch(function (e) {
				// Busy and every other refusal come with their own words, and a
				// way to the file.
				showMessage((e && e.message) || 'The editor could not be opened.', true);
			});
	}

	function launch(r) {
		var form = $('oeLaunch');
		form.action = r.editor_url;
		$('oeToken').value = r.access_token;
		$('oeTokenTtl').value = String(r.access_token_ttl);
		if (r.folder_url) CFG.closeUrl = r.folder_url;
		messageEl.hidden = true;
		frame.hidden = false;
		form.submit();
		setStatus(r.can_write ? '' : 'View only');
	}

	function post(id, values) {
		if (!frame.contentWindow) return;
		frame.contentWindow.postMessage(JSON.stringify({ MessageId: id, SendTime: Date.now(), Values: values || {} }), window.location.origin);
	}

	// The editor's notices. It is served from this site's own origin (Apache
	// proxies it), so anything from elsewhere is ignored.
	function onMessage(e) {
		if (e.origin !== window.location.origin || e.source !== frame.contentWindow) return;
		var msg;
		try { msg = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch (err) { return; }
		if (!msg || !msg.MessageId) return;
		var v = msg.Values || {};
		switch (msg.MessageId) {
			case 'App_LoadingStatus':
				if (v.Status === 'Frame_Ready') post('Host_PostmessageReady');
				if (v.Status === 'Document_Loaded') setStatus(statusEl.textContent === 'View only' ? 'View only' : 'Saved');
				break;
			// The editor's own saves (autosave, Ctrl+S) answer with no
			// Action_Save_Resp: an unmodified document after one is the "saved".
			case 'Doc_ModifiedStatus':
				modified = !!v.Modified;
				if (v.Modified) { confirmTimers.forEach(clearTimeout); setStatus('Saving…'); break; }
				if (!closing) confirmSaved();
				break;
			case 'UI_Save':
				setStatus('Saving…');
				break;
			case 'Action_Save_Resp':
				// Answers the save Close asked for; whether the site kept it is
				// the site's to say (onClose waits for that).
				if (!v.success && !closing) setStatus('Not saved');
				break;
			case 'Action_Load_Resp':
				if (v.success === false) showMessage('The editor could not open this file' + (v.errorMsg ? ' (' + v.errorMsg + ')' : '') + '. You can download it instead.', true);
				break;
			case 'UI_Close':
				leave();
				break;
		}
	}

	// The editor says "not modified" after a save the site refused, too; the
	// site knows which it was. It says so as soon as its own copy is written,
	// before the upload to the site has an answer, so the site is asked after
	// a moment and again a little later.
	// While a refusal stands it is asked again every few seconds: the save the
	// member then chooses in the editor's own dialog (keep this copy) brings
	// no new notice to the page.
	var confirmTimers = [];
	function confirmSaved() {
		confirmTimers.forEach(clearTimeout);
		confirmTimers = [2000, 7000].map(function (ms, i) {
			return setTimeout(function () {
				saveState().then(function (r) {
					setStatus(r.saved ? 'Saved' : (r.message || 'Not saved'));
					if (!r.saved && i === 1 && !modified && !closing) {
						confirmTimers.push(setTimeout(confirmSaved, 3000));
					}
				}).catch(function () {});
			}, ms);
		});
	}

	function leave() { window.location.href = CFG.closeUrl || '/drive'; }

	function saveState() {
		return api.post('office_editor/office_editor_save_state', { file_id: CFG.fileId });
	}

	// Stay on the page with what went wrong and the way out: the editor's own
	// dialog (keep this copy, or reload the other) is right there too.
	function stayRefused(r) {
		closing = false;
		$('oeClose').textContent = 'Close';
		setStatus(r.message || 'Not saved');
		confirmSaved();
		window.alert((r.message || 'Not saved.') + ' Your changes are still in the editor: deal with the editor\'s message, or download a copy from the editor\'s File menu, before closing.');
	}

	// Close leaves only once the site has kept what is in the editor. The
	// editor says "saved" as soon as its own copy is written, before the site
	// has answered, and the same for a save the site refused; so the site is
	// asked, and the page waits for the answer to the save it asked for.
	function onClose(e) {
		if (frame.hidden || closing) { if (closing) e.preventDefault(); return; }
		e.preventDefault();
		closing = true;
		$('oeClose').textContent = 'Saving…';
		saveState().then(function (before) {
			if (!modified) {
				if (before.saved) { leave(); } else { stayRefused(before); }
				return;
			}
			setStatus('Saving…');
			post('Action_Save', { DontTerminateEdit: true, DontSaveIfUnmodified: true, Notify: true });
			var tries = 0;
			(function poll() {
				if (!closing) return;
				tries++;
				saveState().then(function (now) {
					if (now.marker !== before.marker) {
						if (now.saved) { leave(); } else { stayRefused(now); }
					} else if (tries >= 30) {
						leave(); // no answer at all; the editor saves as the last person leaves
					} else {
						setTimeout(poll, 1000);
					}
				}).catch(function () { setTimeout(poll, 1000); });
			})();
		}).catch(function () { leave(); });
	}

	function init() {
		frame = $('oeFrame');
		statusEl = $('oeStatus');
		messageEl = $('oeMessage');
		if (!api) { showMessage('This page could not start.', true); return; }
		window.addEventListener('message', onMessage);
		$('oeClose').addEventListener('click', onClose);
		open();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
