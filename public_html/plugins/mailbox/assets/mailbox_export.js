/**
 * The "Download a mailbox" page (/profile/mailbox/export).
 *
 * Asking is mailbox/mail_export_start, behind a fresh second factor
 * (JoineryPasskeys.withStepUp; without a usable passkey, /verify-stepup and
 * back, which resumes the ask). The list is mailbox/mail_export_list, polled
 * while anything is building; Delete is mailbox/mail_export_delete.
 *
 * A Fortress mailbox is built here: mailbox/mail_export_fortress_page hands
 * over each folder's rows as ciphertext, MailboxFortress opens them in this
 * browser, MailboxMimeBuild makes each message (as the server's
 * MessageRebuild would), and JoineryZip writes the zip — straight to disk
 * where the browser can (showSaveFilePicker), else held in parts of about
 * JoineryZip.PART_BYTES, each saved with its own Save press. One mbox file
 * never crosses a part: a folder past MBOX_PIECE_BYTES continues in
 * "Inbox (2).mbox" and on, which import reads as the same folder.
 *
 * Page contract: window.MAILBOX_EXPORT = {alias_id, address, level, exports};
 * #mail-export-form with [name=export_folder]; [data-export-note];
 * [data-export-list].
 *
 * Vanilla JS. @version 1.2 - writes the server's export header lines (item.headers) on each message (B70)
 */
(function () {
	'use strict';

	var cfg = window.MAILBOX_EXPORT || {};
	var here = '/profile/mailbox/export?mailbox=' + encodeURIComponent(cfg.alias_id || '');
	var RESUME = 'joinery_mail_export_resume';
	var MBOX_PIECE_BYTES = 64 * 1024 * 1024;
	var NOTE_NAME = 'Messages not included.txt';
	var poll = null;

	function api(action, body) { return window.joineryApi.post('mailbox/' + action, body || {}); }

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}

	function note(text) {
		var n = document.querySelector('[data-export-note]');
		if (!n) return;
		n.textContent = text || '';
		n.hidden = !text;
	}

	function humanBytes(n) {
		if (n < 1024) return n + ' B';
		var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
		do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
		return (n >= 10 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
	}

	function localTime(utc) {
		if (!utc) return '';
		var d = new Date(String(utc).replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z');
		return isNaN(d) ? '' : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
	}

	// ── the list ───────────────────────────────────────────────────────────

	var STATE_WORDS = {
		queued: 'Waiting to start',
		building: 'Building',
		ready: 'Ready',
		failed: 'Could not be built'
	};

	function render(rows) {
		var box = document.querySelector('[data-export-list]');
		if (!box) return;
		box.textContent = '';
		if (!rows.length) {
			box.appendChild(el('p', 'jy-muted', 'You have no downloads waiting.'));
			return;
		}
		var list = el('ul', 'mbx-ex-list');
		rows.forEach(function (r) {
			var li = el('li', 'mbx-ex-row');
			var head = el('div', 'mbx-ex-row-head');
			head.appendChild(el('strong', null, r.mailbox + (r.folder ? ' — ' + r.folder : '')));
			head.appendChild(el('span', 'mbx-ex-state mbx-ex-state-' + r.state, STATE_WORDS[r.state] || r.state));
			li.appendChild(head);

			var facts = [];
			if (r.state === 'queued' || r.state === 'building') {
				var bar = el('div', 'mbx-ex-bar');
				var fill = el('span');
				fill.style.width = Math.max(2, r.percent) + '%';
				bar.appendChild(fill);
				bar.setAttribute('role', 'progressbar');
				bar.setAttribute('aria-valuenow', String(r.percent));
				bar.setAttribute('aria-valuemin', '0');
				bar.setAttribute('aria-valuemax', '100');
				li.appendChild(bar);
				facts.push(r.written + ' of ' + r.total + ' messages');
				if (r.level === 'private') facts.push('builds while you are signed in and unlocked');
			} else if (r.state === 'ready') {
				facts.push(r.written + (r.written === 1 ? ' message' : ' messages') + ', ' + humanBytes(r.bytes) + ' before zipping');
			}
			if (r.left_out) facts.push(r.left_out + ' left out (the zip names them in ' + NOTE_NAME + ')');
			if (r.state === 'failed' && r.error) facts.push(r.error);
			if (r.expires) facts.push('deleted ' + localTime(r.expires));
			li.appendChild(el('p', 'jy-muted mbx-ex-facts', facts.join(' · ')));

			var actions = el('div', 'mbx-ex-actions');
			if (r.download) {
				var dl = el('a', 'btn btn-primary btn-sm', 'Download');
				dl.href = r.download;
				if (r.level === 'private') {
					dl.addEventListener('click', function (ev) {
						// Sealed to the member's vault: it opens only in their window.
						if (!window.JoineryVaultLock || window.JoineryVaultLock.state() === 'open') return;
						ev.preventDefault();
						Promise.resolve(window.JoineryVaultLock.unlock()).then(function (ok) {
							if (ok) window.location.href = r.download;
						});
					});
				}
				actions.appendChild(dl);
			}
			var finished = r.state === 'ready' || r.state === 'failed';
			var del = el('button', 'btn btn-secondary btn-sm', finished ? 'Delete' : 'Cancel');
			del.type = 'button';
			del.addEventListener('click', function () {
				JoineryModal.confirm(finished ? 'Delete this download from the server?' : 'Stop building this download?', function () {
					del.disabled = true;
					api('mail_export_delete', { run_id: String(r.run_id) }).then(refresh, function (e) {
						del.disabled = false;
						note((e && e.message) || 'That did not work.');
					});
				}, { confirmLabel: finished ? 'Delete it' : 'Stop it' });
			});
			actions.appendChild(del);
			li.appendChild(actions);
			list.appendChild(li);
		});
		box.appendChild(list);
		schedule(rows);
	}

	function schedule(rows) {
		if (poll) { clearTimeout(poll); poll = null; }
		var busy = rows.some(function (r) { return r.state === 'queued' || r.state === 'building'; });
		if (busy) poll = setTimeout(refresh, 5000);
	}

	function refresh() {
		return api('mail_export_list').then(function (d) { render((d && d.exports) || []); },
			function () { poll = setTimeout(refresh, 15000); });
	}

	// ── asking ─────────────────────────────────────────────────────────────

	function start(folder, resumed) {
		var call = function () { return api('mail_export_start', { alias_id: String(cfg.alias_id), folder: folder }); };
		// No return URL: without a usable passkey withStepUp hands the refusal
		// back, and the trip to /verify-stepup below remembers the ask.
		var run = window.JoineryPasskeys ? JoineryPasskeys.withStepUp(call) : call();
		return run.catch(function (e) {
			if (e && e.data && e.data.requires_stepup && !resumed) {
				try { sessionStorage.setItem(RESUME, JSON.stringify({ t: Date.now(), folder: folder, alias: cfg.alias_id })); } catch (x) {}
				window.location = '/verify-stepup?return=' + encodeURIComponent(here + '#start');
				return new Promise(function () {});
			}
			throw e;
		});
	}

	/** Only the ask just made, landing where the step-up returns. */
	function remembered() {
		try {
			var v = JSON.parse(sessionStorage.getItem(RESUME) || 'null');
			sessionStorage.removeItem(RESUME);
			if (v && Date.now() - v.t < 180000 && location.hash === '#start' && String(v.alias) === String(cfg.alias_id)) return v;
		} catch (x) { /* nothing remembered */ }
		return null;
	}

	function ask(folder, resumed, writable) {
		var form = document.getElementById('mail-export-form');
		var btn = form && form.querySelector('[type="submit"]');
		if (btn) btn.disabled = true;
		note(cfg.level === 'fortress' ? 'Starting…' : 'Asking for your download…');
		return start(folder, resumed).then(function (r) {
			if (r.level === 'fortress') return fortressExport(r.run_id, writable);
			note(r.level === 'private'
				? 'Your download is being built while you are unlocked. It is sealed to your vault until you download it.'
				: 'Your download is being built. You will get an email when it is ready.');
			return refresh();
		}).catch(function (e) {
			if (writable) { try { writable.abort(); } catch (x) { /* closed */ } }
			note((e && e.message) || 'That did not work.');
		}).then(function () { if (btn) btn.disabled = false; });
	}

	// ── Fortress, in this browser ──────────────────────────────────────────

	function b64bytes(s) {
		var bin = atob(s), out = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
		return out;
	}

	function concat(list) {
		var n = 0, i;
		for (i = 0; i < list.length; i++) n += list[i].length;
		var out = new Uint8Array(n), at = 0;
		for (i = 0; i < list.length; i++) { out.set(list[i], at); at += list[i].length; }
		return out;
	}

	/** A Fortress row, opened here and rebuilt. Throws when it cannot be opened. */
	async function fortressMessage(m) {
		var all = (m.attachments || []).slice();
		await MailboxFortress.openThread({ messages: [m] });
		if (m.fortress_placeholder) throw new Error(m.body_plain || 'could not be opened on this device');
		var parts = [];
		for (var i = 0; i < all.length; i++) {
			var a = all[i];
			var blob = await MailboxFortress.attachmentBlob(a);
			parts.push({ content_type: a.content_type, filename: a.filename, content_id: a.content_id,
				inline: !!a.inline, mime_part: a.mime_part, bytes: new Uint8Array(await blob.arrayBuffer()) });
		}
		var built = await MailboxMimeBuild.build({
			id: m.id, raw_headers: m.raw_headers, sender: m.sender, to: m.to, cc: m.cc, bcc: m.bcc,
			recipient: m.recipient, direction: m.direction, subject: m.subject, received_time: m.received_time,
			message_id_header: m.message_id_header, body_plain: m.body_plain,
			body_html: m.body_html_source != null ? m.body_html_source : m.body_html, parts: parts
		});
		return MailboxMimeBuild.marked(built);
	}

	function writableSink(writable) {
		return function () {
			return { write: function (u8) { return writable.write(u8); }, close: function () { return writable.close(); } };
		};
	}

	// Held in the tab, one part at a time: a browser saves a file for a page
	// only from a click, so each finished part waits for its Save press.
	function heldPart(base, inParts) {
		return function (n) {
			var chunks = [];
			return {
				write: function (u8) { chunks.push(u8); },
				close: function () {
					var blob = new Blob(chunks, { type: 'application/zip' });
					chunks = [];
					var name = inParts ? base + '-part-' + (n < 10 ? '0' : '') + n + '.zip' : base + '.zip';
					return new Promise(function (resolve) {
						offer(name + ' is ready (' + humanBytes(blob.size) + ').', 'Save', function () {
							var url = URL.createObjectURL(blob);
							var a = document.createElement('a');
							a.href = url; a.download = name;
							document.body.appendChild(a); a.click(); a.remove();
							setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
							note('');
							resolve();
						});
					});
				}
			};
		};
	}

	function offer(text, label, onPress) {
		var n = document.querySelector('[data-export-note]');
		if (!n) return;
		n.textContent = text + ' ';
		var b = el('button', 'btn btn-primary btn-sm', label);
		b.type = 'button';
		b.addEventListener('click', function () { b.remove(); onPress(); });
		n.appendChild(b);
		n.hidden = false;
		b.focus();
	}

	function zipBase() {
		var day = new Date().toISOString().slice(0, 10);
		return 'mail-' + String(cfg.address || 'mailbox').replace(/[^A-Za-z0-9@._-]+/g, '-').replace(/^-+|-+$/g, '') + '-' + day;
	}

	async function fortressExport(runId, writable) {
		if (!window.MailboxFortress || !window.MailboxMimeBuild || !window.JoineryZip) {
			throw new Error('This page cannot build an end-to-end encrypted download.');
		}
		await MailboxFortress.ready();
		if (!MailboxFortress.isOpen()) {
			await MailboxFortress.unlock('to download your end-to-end encrypted mail');
			if (!MailboxFortress.isOpen()) throw new Error('Unlock your vault to download this mailbox.');
		}
		var Z = window.JoineryZip, base = zipBase();
		var first = await api('mail_export_fortress_page', { run_id: String(runId), folder: '0', after: '0' });
		var parts = new Z.Parts({
			partBytes: writable ? Infinity : Z.PART_BYTES,
			openPart: writable ? writableSink(writable) : heldPart(base, (first.bytes || 0) > Z.PART_BYTES)
		});
		var date = new Date(), taken = {}, notes = [], written = 0;
		var page = first;
		for (var f = 0; !page.done; ) {
			var folderName = (page.folders[f] && page.folders[f].name) || 'Mail';
			var chunk = [], chunkBytes = 0;
			var flush = async function () {
				if (!chunk.length) return;
				var name = Z.uniqueName(Z.cleanName(folderName) + '.mbox', taken);
				await parts.addFile(name, concat(chunk), { date: date, compress: true });
				chunk = []; chunkBytes = 0;
			};
			while (true) {
				for (var i = 0; i < page.messages.length; i++) {
					var item = page.messages[i], entry = null;
					try {
						if (item.left_out) { notes.push(item.left_out); continue; }
						if (item.entry) entry = b64bytes(item.entry);
						else {
							var raw = await fortressMessage(item.message);
							entry = MailboxMimeBuild.mboxEntry(raw, item.headers, MailboxMimeBuild.envelopeOf(item.message.sender), item.time);
						}
					} catch (e) {
						notes.push(folderName + ': message ' + item.id + ': ' + ((e && e.message) || 'could not be opened on this device') + '.');
						continue;
					}
					chunk.push(entry);
					chunkBytes += entry.length;
					written++;
					if (chunkBytes >= MBOX_PIECE_BYTES) await flush();
				}
				note('Building your download: ' + written + ' messages so far…');
				if (page.folder_done) break;
				page = await api('mail_export_fortress_page', { run_id: String(runId), folder: String(f), after: String(page.after) });
			}
			await flush();
			f++;
			page = await api('mail_export_fortress_page', { run_id: String(runId), folder: String(f), after: '0' });
		}
		if (notes.length) {
			await parts.addFile(Z.uniqueName(NOTE_NAME, taken), notes.length + (notes.length === 1 ? ' message was' : ' messages were')
				+ ' left out of this export:\n\n' + notes.join('\n') + '\n', { date: date });
		} else if (!written) {
			await parts.addFile('README.txt', 'This export holds no messages: there were none to export.\n', { date: date });
		}
		var n = await parts.finish();
		api('mail_export_delete', { run_id: String(runId) }).catch(function () { /* the retention sweep takes it */ });
		note(written + (written === 1 ? ' message' : ' messages') + ' downloaded'
			+ (n > 1 ? ', in ' + n + ' parts.' : '.')
			+ (notes.length ? ' ' + notes.length + ' could not be read; the zip names them in ' + NOTE_NAME + '.' : ''));
	}

	// ── wiring ─────────────────────────────────────────────────────────────

	document.addEventListener('DOMContentLoaded', function () {
		render(cfg.exports || []);
		var form = document.getElementById('mail-export-form');
		if (!form) return;
		form.addEventListener('submit', async function (ev) {
			ev.preventDefault();
			var folder = (form.querySelector('[name="export_folder"]') || {}).value || '';
			var writable = null;
			// Chrome and Edge write a Fortress download straight to disk. The save
			// dialog comes first, while the click still counts as the member's.
			if (cfg.level === 'fortress' && window.showSaveFilePicker) {
				try {
					var handle = await window.showSaveFilePicker({ suggestedName: zipBase() + '.zip',
						types: [{ description: 'Zip archive', accept: { 'application/zip': ['.zip'] } }] });
					writable = await handle.createWritable();
				} catch (e) {
					if (e && e.name === 'AbortError') return;
					note(e.message || 'Could not start the download.');
					return;
				}
			}
			ask(folder, false, writable);
		});
		var resume = remembered();
		if (resume) {
			var sel = form.querySelector('[name="export_folder"]');
			if (sel) sel.value = resume.folder || '';
			// A Fortress download needs a press of its own: the save dialog and
			// the build start from the member's click, not from a page load.
			if (cfg.level === 'fortress') note('You are confirmed. Press Download to build it in this browser.');
			else ask(resume.folder || '', true, null);
		}
	});
})();
