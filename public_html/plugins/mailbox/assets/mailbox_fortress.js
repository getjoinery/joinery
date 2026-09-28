/**
 * MailboxFortress - the reader's half of end-to-end (Fortress) mail
 * (specs/client_custody_mail.md § R4).
 *
 * A Fortress row arrives from mailbox/thread_list and mailbox/thread with its
 * clear content fields empty and `sealed` carrying its columns as stored:
 * {key, sealed_scope: 'mail', sealed_dek, sealed_ad_prefix, iem_*}. Only the
 * owner's `mail` vault opens the DEK, so everything readable is made here, in
 * memory, from the server's ciphertext:
 *
 *   openList(data)    fills a thread row's subject, sender and snippet;
 *   openThread(data)  fills each message's headers and bodies, names its
 *                     attachments from the sealed manifest and turns its
 *                     inline cid: images into data: URLs (the body renders in
 *                     a sandboxed, opaque-origin frame, which cannot load a
 *                     blob: URL of this page's origin);
 *   attachmentBlob(att) / download(att)  fetch a part's ciphertext and open
 *                     it under the row DEK with the MIME-part AD;
 *   saveDraft / openDraft / draftFiles / sourceOpen / sourceFiles  the
 *                     compose half (§ R6): a draft is sealed here under one
 *                     DEK for its life and posted as ciphertext; a reply or
 *                     forward of an end-to-end message quotes and re-attaches
 *                     what this browser opened, since the server cannot.
 *
 * None of these starts an unlock: a content action does, through unlock().
 * While the mail vault is shut, rows get placeholders and the response says
 * `fortress_locked`, which the reader turns into a banner with one button.
 *
 * Locking (JoinerySealed's idle lock, the lock chip, pagehide) drops every row
 * key and revokes every object URL handed out; the reader's onLock handler
 * re-renders from the server copies, which are ciphertext.
 *
 * Nothing opened here is sent back to the server, except what the person
 * sends: a message leaves as plaintext for its recipients (B10), so a reply
 * carries the quoted source and a forward its parts.
 *
 * @version 1.9.2 - another person's rows (an all-access viewer) say only the owner's devices open them
 * @version 1.9.1 - review of 2026-09-27: a part removed during a save stays removed (B4); an inline
 *   image that will not open fails the draft's open rather than being dropped at the next save (B5)
 * @version 1.9 - compose: sealed drafts (saveDraft, openDraft, draftFiles), and the quote and
 *   parts of an end-to-end source for a reply or forward (sourceOpen, sourceFiles)
 * @version 1.8.1 - the load-time lock hook needs a document only when it waits for one (node gates), and its self-check line runs only on a page
 * @version 1.8 - subscribes to the mail vault's lock once the deferred vault modules have run
 *   (it loads before them, so the load-time check subscribed to nothing and a lock wiped nothing)
 * @version 1.7 - judgeEntry() sends the recipe's reasoning control, as a server run does; an
 *   answer spent entirely on reasoning is asked again with reasoning off
 * @version 1.6 - judgeEntry(): one AI judgement on the owner's own model, with the lock
 *   epoch; selfCheck() runs it against a stub model (specs/fortress_mail_device_ai.md § R6)
 * @version 1.5 - opens the AI verdicts (ai_summary, ai_scan) and the header block (raw_headers)
 *   a device judgement wrote or reads (specs/fortress_mail_device_ai.md § R4)
 * @version 1.4 - one name: "your vault" (specs/one_vault_experience.md § R5)
 * @version 1.3 - names the mail vault to the lock chip (JoinerySealed.want)
 * @version 1.2 - ready(): waits for a reload's resume before treating the vault as shut
 * @version 1.1 - isSetUp(): the reader offers mail-vault setup before any mail exists
 * @version 1.0
 */
window.MailboxFortress = (function () {
	'use strict';

	var SCOPE = 'mail';
	var EDGE_SEAL = 'v1.edgeseal.';
	var EDGE_FIELD = 'v1.edge.';
	var PENDING_NOTE = 'Waiting to be opened on this device.';
	var LOCKED_NOTE = 'End-to-end encrypted. Unlock your vault to read it.';
	var FAILED_NOTE = 'This message could not be opened on this device.';
	var FOREIGN_NOTE = 'End-to-end encrypted. Only the mailbox owner\'s devices can open it.';
	// Inline images rendered in a message body. Anything else stays an
	// unresolved cid: reference.
	var INLINE_IMAGE_TYPES = {
		'image/png': 1, 'image/jpeg': 1, 'image/gif': 1, 'image/webp': 1, 'image/avif': 1, 'image/bmp': 1
	};
	var IMAGE_EXTENSIONS = {
		png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif',
		webp: 'image/webp', avif: 'image/avif', bmp: 'image/bmp'
	};
	var INLINE_MAX_BYTES = 5 * 1024 * 1024;

	var rowKeys = new Map();   // message id -> Promise<CryptoKey>, the row DEK
	var objectUrls = [];       // every object URL handed out, revoked on lock
	var lockHandlers = [];
	// Bumped by every lock. A judgement in flight notes it before it starts and
	// checks it before it seals or posts, so nothing is sealed or posted after
	// the vault shut (specs/fortress_mail_device_ai.md § R6, B12).
	var lockEpoch = 0;

	function cfg() { return window.MAILBOX_READER || {}; }

	function isOpen() {
		return !!(window.JoinerySealed && JoinerySealed.isOpen(SCOPE));
	}

	// Settles once a reload has reopened the mail vault (or given up), so a
	// first render never mistakes a vault that is about to reopen for a shut one.
	function ready() {
		return (window.JoinerySealed && JoinerySealed.ready) ? JoinerySealed.ready : Promise.resolve();
	}

	/**
	 * Whether this person has a mail vault yet. Until they do, mail to a Fortress
	 * mailbox is held, not stored, so the reader offers setup rather than unlock.
	 * Resolves true / false, or null when the status could not be read.
	 */
	async function isSetUp() {
		if (!window.VaultKeyring) return null;
		try {
			var st = await VaultKeyring.status(SCOPE);
			return !!(st && st.set_up);
		} catch (e) {
			return null;
		}
	}

	/** Run the mail-vault ceremony (setup on first use). Resolves true when open. */
	async function unlock(reason) {
		if (!window.JoinerySealed) throw new Error('This page cannot open end-to-end encrypted mail.');
		await JoinerySealed.session(SCOPE, { reason: reason || 'to read your end-to-end encrypted mail' });
		return isOpen();
	}

	// ---- row keys --------------------------------------------------------------

	async function importRowKey(sealedDek) {
		var prefix = EDGE_SEAL + SCOPE + '.';
		if (typeof sealedDek !== 'string' || sealedDek.indexOf(prefix) !== 0) {
			throw new Error('This message is not sealed to your vault.');
		}
		var session = await JoinerySealed.session(SCOPE);
		var bytes = await session.openSealed(sealedDek.slice(prefix.length));
		try {
			return await VaultCrypto.importDek(bytes);
		} finally {
			bytes.fill(0);
		}
	}

	function rememberKey(messageId, sealedDek) {
		if (!rowKeys.has(messageId)) {
			var p = importRowKey(sealedDek);
			p.catch(function () { rowKeys.delete(messageId); });
			rowKeys.set(messageId, p);
		}
		return rowKeys.get(messageId);
	}

	function keyFor(messageId) {
		var p = rowKeys.get(messageId);
		if (!p) throw new Error('Open the message again to read this attachment.');
		return p;
	}

	// base64( IV[12] || ciphertext || tag ) after the v1.edge. prefix, under
	// `key`, bound to `ad`. Bytes in, bytes out.
	async function openEdgeBytes(value, key, ad) {
		if (typeof value !== 'string' || value.indexOf(EDGE_FIELD) !== 0) {
			throw new Error('This part is not sealed for this device.');
		}
		var raw = VaultCrypto.b64decode(value.slice(EDGE_FIELD.length));
		var pt = await crypto.subtle.decrypt(
			{ name: 'AES-GCM', iv: raw.slice(0, 12), additionalData: new TextEncoder().encode(ad) },
			key, raw.slice(12));
		return new Uint8Array(pt);
	}

	// ---- the list ----------------------------------------------------------------

	function placeholderThread(t, note) {
		t.subject = '';
		t.sender = 'Encrypted';
		t.senders = 'Encrypted';
		t.snippet = note;
		t.fortress_placeholder = true;
	}

	/** Fill every Fortress thread row in a thread_list response, in place. */
	async function openList(data) {
		var threads = (data && data.threads) || [];
		var sealed = threads.filter(function (t) { return t && t.sealed; });
		if (!sealed.length) return data;
		// Another person's rows (an all-access viewer): no unlock here opens them.
		sealed.forEach(function (t) { if (t.sealed.foreign) placeholderThread(t, FOREIGN_NOTE); });
		sealed = sealed.filter(function (t) { return !t.sealed.foreign; });
		if (!sealed.length) return data;
		await ready();
		if (!isOpen()) {
			sealed.forEach(function (t) {
				placeholderThread(t, t.sealed.pending ? PENDING_NOTE : LOCKED_NOTE);
			});
			data.fortress_locked = sealed.some(function (t) { return !t.sealed.pending; });
			return data;
		}
		await Promise.all(sealed.map(async function (t) {
			if (t.sealed.pending) { placeholderThread(t, PENDING_NOTE); return; }
			try {
				var o = await JoinerySealed.open(t.sealed);
				t.subject = o.iem_subject || '';
				t.sender = o.iem_sender || '';
				t.senders = t.sender;
				t.snippet = o.iem_snippet || '';
				// The summary the owner's own model wrote, sealed under the row.
				t.ai_summary = o.iem_ai_summary || '';
			} catch (e) {
				placeholderThread(t, FAILED_NOTE);
			}
		}));
		return data;
	}

	// ---- a thread ----------------------------------------------------------------

	function placeholderMessage(m, note) {
		m.body_plain = note;
		m.body_html = '';
		(m.attachments || []).forEach(function (a) {
			a.filename = 'Encrypted attachment';
			a.content_type = 'application/octet-stream';
			a.preview_kind = null;
			a.fortress = true;
			a.fortress_locked = true;
		});
		m.attachments = (m.attachments || []).filter(function (a) { return !a.inline; });
		m.fortress_placeholder = true;
	}

	function imagePreviewKind(type, name) {
		type = String(type || '').toLowerCase();
		if (INLINE_IMAGE_TYPES[type]) return 'image';
		var ext = String(name || '').toLowerCase().split('.').pop();
		return IMAGE_EXTENSIONS[ext] ? 'image' : null;
	}

	async function openMessage(m) {
		var o = await JoinerySealed.open(m.sealed);
		m.sender = o.iem_sender || '';
		m.subject = o.iem_subject || '';
		m.body_plain = o.iem_body_plain || '';
		m.body_html = o.iem_body_html || '';
		m.to = o.iem_to || '';
		m.cc = o.iem_cc || '';
		if (o.iem_recipient) m.recipient = o.iem_recipient;
		if (o.iem_bcc) m.bcc = o.iem_bcc;
		// What the owner's own model found, sealed under the row
		// (specs/fortress_mail_device_ai.md § R4): the same shapes a Private row
		// hands the reader, so the list preview and the danger banner need
		// nothing new. The header block is what a device judgement reads.
		m.ai_summary = o.iem_ai_summary || '';
		m.ai_scan = null;
		if (o.iem_ai_scan) {
			try { var scan = JSON.parse(o.iem_ai_scan); m.ai_scan = (scan && typeof scan === 'object') ? scan : null; } catch (e) { m.ai_scan = null; }
		}
		m.raw_headers = o.iem_raw_headers || '';

		var manifest = [];
		try { manifest = JSON.parse(o.iem_attachment_manifest || '[]') || []; } catch (e) { manifest = []; }
		var byId = {};
		manifest.forEach(function (e) { if (e && e.id != null) byId[String(e.id)] = e; });

		rememberKey(m.id, m.sealed.sealed_dek);
		var parts = (m.attachments || []).map(function (a) {
			var e = byId[String(a.id)] || {};
			a.filename = e.filename || 'attachment';
			a.content_type = e.content_type || 'application/octet-stream';
			a.content_id = e.content_id || '';
			a.message_id = m.id;
			a.ad_prefix = m.sealed.sealed_ad_prefix;
			a.fortress = true;
			// Pictures preview here, decrypted in this browser; text preview is
			// the server's extractor, which cannot read this file.
			a.preview_kind = imagePreviewKind(a.content_type, a.filename);
			return a;
		});
		m.attachments = parts.filter(function (a) { return !a.inline; });
		var inline = parts.filter(function (a) { return a.inline && a.content_id; });
		// As the sender wrote them, for a reply's quote and a forward's parts:
		// the rendered body's cid: images become data: URLs below.
		m.body_html_source = m.body_html;
		m.inline_parts = inline;
		if (m.body_html && inline.length) {
			m.body_html = await inlineRewrite(inline, m.body_html);
		}
	}

	/** Open every Fortress message in a thread response, in place. */
	async function openThread(data) {
		var msgs = (data && data.messages) || [];
		var sealed = msgs.filter(function (m) { return m && m.sealed; });
		if (!sealed.length) return data;
		await ready();
		var locked = !isOpen();
		for (var i = 0; i < sealed.length; i++) {
			var m = sealed[i];
			if (m.sealed.foreign) { placeholderMessage(m, FOREIGN_NOTE); continue; }
			if (m.sealed.pending) { placeholderMessage(m, PENDING_NOTE); continue; }
			if (locked) { placeholderMessage(m, LOCKED_NOTE); data.fortress_locked = true; continue; }
			try {
				await openMessage(m);
			} catch (e) {
				placeholderMessage(m, FAILED_NOTE);
			}
		}
		return data;
	}

	// ---- attachments ----------------------------------------------------------------

	/** One part's plaintext bytes: fetched as stored, opened under the row DEK. */
	async function attachmentBytes(att) {
		if (!att || !att.fortress || att.fortress_locked) {
			throw new Error('Unlock your vault to open this attachment.');
		}
		var key = await keyFor(att.message_id);
		var res = await fetch(cfg().attachmentUrlBase + '?ima_inbound_message_attachment_id='
			+ encodeURIComponent(att.id), { credentials: 'same-origin' });
		var type = res.headers.get('content-type') || '';
		// The endpoint renders an HTML page for its own refusals.
		if (!res.ok || /text\/html/i.test(type)) throw new Error('This attachment could not be fetched.');
		var stored = await res.text();
		return openEdgeBytes(stored, key, String(att.ad_prefix) + att.message_id + ':att:' + att.mime_part);
	}

	/** The part as a Blob typed from the sealed manifest. */
	async function attachmentBlob(att) {
		var bytes = await attachmentBytes(att);
		return new Blob([bytes], { type: att.content_type || 'application/octet-stream' });
	}

	/** Hand the opened part to the browser as a download named from the manifest. */
	async function download(att) {
		// Downloaded as bytes, never navigated to: a sender's declared type must
		// not decide how this origin treats the file.
		var bytes = await attachmentBytes(att);
		var url = URL.createObjectURL(new Blob([bytes], { type: 'application/octet-stream' }));
		objectUrls.push(url);
		var a = document.createElement('a');
		a.href = url;
		a.download = att.filename || 'attachment';
		a.rel = 'noopener';
		document.body.appendChild(a);
		a.click();
		a.remove();
	}

	/** Track an object URL made elsewhere from Fortress plaintext, so a lock revokes it. */
	function adoptObjectUrl(url) {
		objectUrls.push(url);
		return url;
	}

	// cid: references in an opened body -> data: URLs of the opened inline images.
	async function inlineRewrite(inline, html) {
		var map = {};
		for (var i = 0; i < inline.length; i++) {
			var a = inline[i];
			var type = String(a.content_type || '').toLowerCase();
			if (!INLINE_IMAGE_TYPES[type] || Number(a.size_bytes) > INLINE_MAX_BYTES) continue;
			try {
				var bytes = await attachmentBytes(a);
				map[String(a.content_id).replace(/^<|>$/g, '')] = 'data:' + type + ';base64,' + VaultCrypto.b64encode(bytes);
			} catch (e) { /* the reference stays unresolved; the rest of the body renders */ }
		}
		return html.replace(/cid:([^"'\s>]+)/gi, function (whole, id) {
			var key;
			try { key = decodeURIComponent(id); } catch (e) { key = id; }
			key = key.replace(/^<|>$/g, '');
			return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : whole;
		});
	}

	// ---- compose (specs/client_custody_mail.md § R6) -----------------------------------
	//
	// A draft is a Fortress row the browser seals: one DEK for the draft's life
	// (its saved parts stay openable across saves), sealed afresh to the mail
	// key on each save, every field and part under it. The reader holds the
	// draft state `d` = {id, adPrefix, dekBytes, dek, parts[]}, where each part
	// is {mime_part, filename, content_type, content_id, inline, size, id}.

	function newDraftState() {
		return { id: null, adPrefix: null, dekBytes: null, dek: null, parts: [], threadKey: '' };
	}

	function hex(n) {
		return Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(n)),
			function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}

	// Bytes under `key` bound to `ad`, in the v1.edge. format openEdgeBytes reads.
	async function sealEdgeBytes(bytes, key, ad) {
		var iv = crypto.getRandomValues(new Uint8Array(12));
		var ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: new TextEncoder().encode(ad) },
			key, bytes);
		var out = new Uint8Array(12 + ct.byteLength);
		out.set(iv, 0);
		out.set(new Uint8Array(ct), 12);
		return EDGE_FIELD + VaultCrypto.b64encode(out);
	}

	// The list preview, as the server derives it for a Fortress row.
	function snippetOf(plain) {
		return String(plain || '').slice(0, 4000).replace(/\s+/g, ' ').trim().slice(0, 240);
	}

	// The key new material seals to: the pending key during a rotation.
	async function mailPublicKey() {
		var st = await VaultKeyring.status(SCOPE);
		if (!st || !st.set_up) throw new Error('Set up your vault to write from an end-to-end encrypted mailbox.');
		return st.pending_public_key || st.public_key;
	}

	// POST a FormData to an API action and unwrap its envelope.
	async function postForm(url, body) {
		var res = await fetch(url, { method: 'POST', credentials: 'same-origin',
			headers: { 'X-Joinery-Csrf': joineryApi.csrf() }, body: body });
		var env = await res.json().catch(function () { return null; });
		if (!env || env.errortype || !res.ok) {
			var err = new Error((env && env.error) || 'The draft could not be saved.');
			err.errorRef = (env && env.error_ref) || null;
			throw err;
		}
		return env.data || {};
	}

	/**
	 * Save a draft. c = {alias_id, mode, source_id, sender, to, cc, bcc,
	 * subject, body_html, body_plain, files: [File], inline: [{localId, file}],
	 * keep: [mime_part]} — files and inline are the parts not saved yet; keep
	 * names the saved parts still wanted. Updates `d` in place; resolves the
	 * server's answer. No plaintext leaves this function.
	 */
	async function saveDraft(d, c) {
		var url = cfg().draftSaveUrl;
		var clear = function (body) {
			body.append('fortress', '1');
			body.append('alias_id', String(c.alias_id || ''));
			body.append('mode', String(c.mode || 'new'));
			body.append('source_id', String(c.source_id || ''));
			if (d.id) body.append('draft_id', String(d.id));
			return body;
		};
		if (!d.id) {
			var first = await postForm(url, clear(new FormData()));
			d.id = first.draft_id;
			d.adPrefix = first.sealed_ad_prefix;
		}
		if (!d.dek) {
			var fresh = await VaultCrypto.newDek();
			d.dekBytes = fresh.dekBytes;
			d.dek = fresh.dekKey;
		}
		var publicKey = await mailPublicKey();
		var body = clear(new FormData());
		var added = [], uploads = [];
		var add = async function (file, inline, contentId) {
			var part = 'draft' + (inline ? 'inl' : '') + ':' + hex(12);
			var bytes = new Uint8Array(await file.arrayBuffer());
			var sealed = await sealEdgeBytes(bytes, d.dek, String(d.adPrefix) + d.id + ':att:' + part);
			body.append('attachments[]', new Blob([sealed], { type: 'application/octet-stream' }), part);
			uploads.push({ mime_part: part, size: bytes.length, inline: inline });
			added.push({ mime_part: part, filename: file.name || 'attachment',
				content_type: file.type || 'application/octet-stream', content_id: contentId || '',
				inline: inline, size: bytes.length, id: null });
		};
		for (var i = 0; i < (c.files || []).length; i++) await add(c.files[i], false, '');
		for (var j = 0; j < (c.inline || []).length; j++) await add(c.inline[j].file, true, c.inline[j].localId);

		var keep = {};
		(c.keep || []).forEach(function (p) { keep[p] = true; });
		var parts = d.parts.filter(function (p) { return keep[p.mime_part]; }).concat(added);
		var values = {
			iem_sender: c.sender || '',
			iem_recipient: [c.to, c.cc].filter(function (v) { return v; }).join(', '),
			iem_to: c.to || '', iem_cc: c.cc || '', iem_bcc: c.bcc || '',
			iem_subject: c.subject || '', iem_body_html: c.body_html || '', iem_body_plain: c.body_plain || '',
			iem_draft_state: JSON.stringify({ mode: c.mode || 'new', source_id: Number(c.source_id) || 0,
				to: c.to || '', cc: c.cc || '' }),
			iem_snippet: snippetOf(c.body_plain),
			iem_attachment_manifest: parts.length ? JSON.stringify(parts.map(function (p) {
				return { mime_part: p.mime_part, filename: p.filename, content_type: p.content_type,
					content_id: p.content_id, inline: p.inline, size: p.size };
			})) : ''
		};
		var fields = {};
		var names = Object.keys(values);
		for (var k = 0; k < names.length; k++) {
			var v = values[names[k]];
			// Empty stays bare, as the server stores it.
			fields[names[k]] = v === '' ? '' : EDGE_FIELD + await VaultCrypto.encrypt(v, d.dek,
				String(d.adPrefix) + d.id + ':' + names[k]);
		}
		body.append('sealed_dek', EDGE_SEAL + SCOPE + '.' + await VaultCrypto.sealToPublicKey(d.dekBytes, publicKey));
		body.append('public_key', publicKey);
		body.append('fields', JSON.stringify(fields));
		body.append('parts', JSON.stringify(uploads));
		body.append('keep', JSON.stringify(parts.map(function (p) { return p.mime_part; })));
		var answer = await postForm(url, body);
		var ids = {};
		(answer.parts || []).forEach(function (p) { ids[p.mime_part] = p.id; });
		// What the server holds after this save, and nothing else: a part removed
		// while the save was in flight must not come back.
		parts.forEach(function (p) { if (ids[p.mime_part] != null) p.id = ids[p.mime_part]; });
		d.parts = parts.filter(function (p) { return ids[p.mime_part] != null; });
		return answer;
	}

	/**
	 * Open a draft_get answer for a Fortress draft. Resolves {d, fields} where
	 * fields is the compose state (to, cc, bcc, subject, body_html, mode,
	 * source_id), attachments are the saved regular parts for the chips, and
	 * inline the saved inline images as {content_id, url, file, mime_part}.
	 * The draft's key stays in `d` for the compose's life: it protects what
	 * is on screen in that compose, and its saved parts need it to stay
	 * openable at the next save.
	 */
	async function openDraft(data) {
		var d = newDraftState();
		d.id = data.draft_id;
		d.adPrefix = data.sealed_ad_prefix;
		d.threadKey = data.thread_key || '';
		var out = { draft_id: data.draft_id, alias_id: data.alias_id, mode: 'new', source_id: 0, to: '', cc: '',
			bcc: '', subject: '', body_html: '', attachments: [], inline: [] };
		if (!data.sealed) return { d: d, fields: out };

		var parsed = String(data.sealed.sealed_dek || '');
		var prefix = EDGE_SEAL + SCOPE + '.';
		if (parsed.indexOf(prefix) !== 0) throw new Error('This draft is not sealed to your vault.');
		var session = await JoinerySealed.session(SCOPE, { reason: 'to open this draft' });
		d.dekBytes = await session.openSealed(parsed.slice(prefix.length));
		d.dek = await VaultCrypto.importDek(d.dekBytes);
		var open = async function (col) {
			var v = data.sealed[col];
			if (typeof v !== 'string' || v.indexOf(EDGE_FIELD) !== 0) return '';
			return VaultCrypto.decrypt(v.slice(EDGE_FIELD.length), d.dek, String(d.adPrefix) + d.id + ':' + col);
		};
		var state = {};
		try { state = JSON.parse(await open('iem_draft_state') || '{}') || {}; } catch (e) { state = {}; }
		var manifest = [];
		try { manifest = JSON.parse(await open('iem_attachment_manifest') || '[]') || []; } catch (e) { manifest = []; }
		var named = {};
		manifest.forEach(function (e) { if (e && e.mime_part) named[e.mime_part] = e; });

		out.mode = state.mode || 'new';
		out.source_id = Number(state.source_id) || 0;
		out.to = state.to || '';
		out.cc = state.cc || '';
		out.bcc = await open('iem_bcc');
		out.subject = await open('iem_subject');
		out.body_html = await open('iem_body_html');

		for (var i = 0; i < (data.parts || []).length; i++) {
			var p = data.parts[i];
			var e = named[p.mime_part] || {};
			var part = { id: p.id, mime_part: p.mime_part, filename: e.filename || 'attachment',
				content_type: e.content_type || 'application/octet-stream', content_id: e.content_id || '',
				inline: !!p.inline, size: p.size_bytes };
			d.parts.push(part);
			if (!part.inline) {
				out.attachments.push({ id: part.id, filename: part.filename, content_type: part.content_type,
					size_bytes: part.size, mime_part: part.mime_part });
				continue;
			}
			if (!part.content_id) continue;
			// An image that will not open fails the whole open: opened without it,
			// the next save would drop it from the draft.
			var file = await draftPartFile(d, part);
			out.inline.push({ content_id: part.content_id, url: adoptObjectUrl(URL.createObjectURL(file)), file: file,
				mime_part: part.mime_part });
		}
		return { d: d, fields: out };
	}

	// One saved part of the draft, opened, as a File named from the manifest.
	async function draftPartFile(d, part) {
		var res = await fetch(cfg().attachmentUrlBase + '?ima_inbound_message_attachment_id='
			+ encodeURIComponent(part.id), { credentials: 'same-origin' });
		var type = res.headers.get('content-type') || '';
		if (!res.ok || /text\/html/i.test(type)) throw new Error('An attachment of this draft could not be fetched.');
		var bytes = await openEdgeBytes(await res.text(), d.dek, String(d.adPrefix) + d.id + ':att:' + part.mime_part);
		return new File([bytes], part.filename || 'attachment', { type: part.content_type || 'application/octet-stream' });
	}

	/** The draft's saved regular parts named in `keep`, opened, for a send. */
	async function draftFiles(d, keep) {
		var want = {};
		(keep || []).forEach(function (p) { want[p] = true; });
		var out = [];
		for (var i = 0; i < d.parts.length; i++) {
			var p = d.parts[i];
			if (p.inline || !want[p.mime_part]) continue;
			out.push(await draftPartFile(d, p));
		}
		return out;
	}

	/**
	 * What a reply or forward quotes of an opened end-to-end message, for
	 * mailbox/send's source_open. Null when the message is not open here.
	 */
	function sourceOpen(m) {
		if (!m || !m.sealed || m.fortress_placeholder) return null;
		return { sender: m.sender || '', subject: m.subject || '', recipient: m.recipient || '',
			body_html: m.body_html_source != null ? m.body_html_source : (m.body_html || ''),
			body_plain: m.body_plain || '' };
	}

	/**
	 * An opened end-to-end message's parts for a forward: {files: [File],
	 * inline: {localId: name}} — each inline part named uniquely and keyed by
	 * its Content-ID, which the server turns into a fresh one in the quote.
	 */
	async function sourceFiles(m) {
		var out = { files: [], inline: {} };
		if (!m || !m.sealed || m.fortress_placeholder) return out;
		var regular = m.attachments || [];
		for (var i = 0; i < regular.length; i++) {
			var a = regular[i];
			if (!a.fortress) continue;
			var bytes = await attachmentBytes(a);
			out.files.push(new File([bytes], a.filename || 'attachment', { type: a.content_type || 'application/octet-stream' }));
		}
		var inline = m.inline_parts || [];
		for (var j = 0; j < inline.length; j++) {
			var p = inline[j];
			var cid = String(p.content_id || '').replace(/^<|>$/g, '');
			if (!cid) continue;
			var pb = await attachmentBytes(p);
			var name = 'fwdinl' + j + '-' + String(p.filename || 'image').replace(/[^A-Za-z0-9._-]/g, '_');
			out.files.push(new File([pb], name, { type: p.content_type || 'application/octet-stream' }));
			out.inline[cid] = name;
		}
		return out;
	}

	// ---- search text (the sealed iem_search_text) ------------------------------

	/** A search text as stored, 'gz:' + base64(gzip) or plain, to its text. */
	async function inflateSearchText(value) {
		value = String(value || '');
		if (value.indexOf('gz:') !== 0) return value;
		var bytes = VaultCrypto.b64decode(value.slice(3));
		var stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'));
		return new Response(stream).text();
	}

	// ---- locking ---------------------------------------------------------------------

	function wipe() {
		rowKeys.clear();
		objectUrls.forEach(function (u) { try { URL.revokeObjectURL(u); } catch (e) { /* gone */ } });
		objectUrls = [];
	}

	/** fn() runs after a lock has dropped every key and object URL. */
	function onLock(fn) { lockHandlers.push(fn); }

	// Subscribe to the mail vault's lock once JoinerySealed exists. The vault
	// modules load deferred in the page head, and this file loads in the body
	// without defer, so it runs FIRST: a check made only at load found no
	// JoinerySealed, subscribed to nothing, and a lock left every opened key,
	// object URL and decrypted row on screen. Deferred scripts all run before
	// DOMContentLoaded, so that event is the latest this can be.
	var hooked = false;
	function hookVault() {
		if (hooked || !window.JoinerySealed) return;
		hooked = true;
		// This page reads the mail vault: the lock chip lists it and reads
		// "locked" while it is shut, even with the account vault open.
		if (JoinerySealed.want) JoinerySealed.want(SCOPE, 'Vault');
		JoinerySealed.onLock(SCOPE, function () {
			lockEpoch++;
			wipe();
			lockHandlers.forEach(function (fn) {
				try { fn(); } catch (e) { /* one handler must not stop the next */ }
			});
		});
	}
	hookVault();
	// A page always has a document; the node gates that load this file do not.
	if (!hooked && typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', hookVault);

	// ---- one AI judgement on the owner's own model --------------------------------------

	/**
	 * Judge one Fortress message (an entry from mailbox/device_ai_entries) with
	 * one recipe (from mailbox/ai_device_recipes) on the owner's own model, and
	 * record the outcome (specs/fortress_mail_device_ai.md § R5, R6).
	 *
	 * Open the entry's sealed columns; build the digest exactly as the server
	 * would (EmailDigest), with the ATTACHMENTS section when the recipe's digest
	 * carries one; wrap it under the recipe's nonce; call the model with the
	 * recipe's full system prompt; validate (VerdictCheck), and on a refusal ask
	 * once more with the validator's words; seal the verdict under the row's own
	 * key and AD; post it (mailbox/device_ai_verdict), or post `error` when the
	 * answer is still invalid (mailbox/ai_device_record).
	 *
	 * endpoint: {url (…/chat/completions), key, model}. deps (tests): open,
	 * key, fetch, post — each defaults to the real one.
	 *
	 * Resolves {status}: 'done' (with field, plaintext, model, recorded),
	 * 'error' (answered twice, invalid both times; recorded), 'dropped' (the
	 * vault shut mid-item: nothing sealed, nothing posted), or 'stop' (the model
	 * did not answer usefully — unreachable, refused, wrong key, context too
	 * small — nothing recorded, so the message comes back next time; the drain
	 * stops and says why).
	 */
	async function judgeEntry(entry, recipe, endpoint, deps) {
		deps = deps || {};
		var open = deps.open || function (sealed) { return JoinerySealed.open(sealed); };
		var keyOf = deps.key || function (e) { return rememberKey(e.id, e.sealed.sealed_dek); };
		var doFetch = deps.fetch || function (u, o) { return window.fetch(u, o); };
		var post = deps.post || function (a, b) { return window.joineryApi.post(a, b); };
		var at = lockEpoch;

		var o = await open(entry.sealed);
		var digest = EmailDigest.build({
			raw: o.iem_raw_headers || null,
			sender: o.iem_sender || '',
			recipient: o.iem_recipient || entry.recipient || '',
			received_time: entry.received_time || '',
			subject: o.iem_subject || '',
			body_plain: o.iem_body_plain || '',
			body_html: o.iem_body_html || '',
			spf_result: entry.spf_result || '',
			dkim_result: entry.dkim_result || '',
			dmarc_result: entry.dmarc_result || '',
			authserv_id: recipe.authserv_id || ''
		});
		if (recipe.attachments) {
			var manifest = [];
			try { manifest = JSON.parse(o.iem_attachment_manifest || '[]') || []; } catch (e) { manifest = []; }
			var att = EmailDigest.attachments(manifest);
			if (att !== '') digest += '\n\n' + att;
		}
		var messages = [
			{ role: 'system', content: recipe.system },
			{ role: 'user', content: EmailDigest.wrapBlock(digest, recipe.nonce) }
		];
		var headers = { 'Content-Type': 'application/json' };
		if (endpoint.key) headers.Authorization = 'Bearer ' + endpoint.key;

		var verdict = null, error = '', served = endpoint.model;
		// The recipe's reasoning control, as a server run sends it. The device
		// budget is a quarter of the server's, so a model that reasons at the
		// recipe's level can spend all of it thinking and answer with nothing
		// (finish_reason 'length', empty content): that is asked again with
		// reasoning off, since the model never answered and the validator has
		// nothing to say about it.
		var effort = recipe.reasoning_effort || 'none';
		for (var attempt = 1; attempt <= 2; attempt++) {
			if (lockEpoch !== at) return { status: 'dropped' };
			var res;
			try {
				res = await doFetch(endpoint.url, {
					method: 'POST', headers: headers, credentials: 'omit',
					body: JSON.stringify({ model: endpoint.model, messages: messages, max_tokens: recipe.max_tokens,
						reasoning_effort: effort,
						response_format: { type: 'json_object' }, stream: false })
				});
			} catch (e) {
				return { status: 'stop', reason: 'unreachable' };
			}
			var text = await res.text();
			var body = null;
			try { body = JSON.parse(text); } catch (e) { body = null; }
			if (!res.ok) {
				var err = body && body.error;
				return { status: 'stop', http: res.status,
					reason: err ? (typeof err === 'string' ? err : (err.message || JSON.stringify(err))) : String(text).slice(0, 200) };
			}
			if (body && body.model) served = body.model;
			var choice = body && body.choices && body.choices[0] ? body.choices[0] : null;
			var content = choice && choice.message ? String(choice.message.content || '') : '';
			if (!content && choice && choice.finish_reason === 'length' && effort !== 'none') {
				effort = 'none';
				error = 'The model spent its whole answer reasoning and never answered.';
				continue;
			}
			var parsed = VerdictCheck.parse(content, recipe.verdict_descriptor, recipe.job_id);
			if (parsed.verdict) { verdict = parsed.verdict; break; }
			error = parsed.error;
			if (attempt < 2) {
				messages.push({ role: 'assistant', content: content });
				messages.push({ role: 'user', content: VerdictCheck.retryMessage(error) });
			}
		}
		if (lockEpoch !== at) return { status: 'dropped' };
		if (!verdict) {
			await post('mailbox/ai_device_record', { recipe_id: recipe.recipe_id, item_key: String(entry.id) });
			return { status: 'error', error: error };
		}

		// The same shapes the server's jobs write (recordVerdict()): the triage's
		// bare summary; the scan's JSON with the score kept out, in the clear.
		var field, plaintext, score = null;
		if (recipe.job_id === 'email_security_scan') {
			field = 'iem_ai_scan';
			plaintext = JSON.stringify({ verdict: verdict.verdict, red_flags: verdict.red_flags || [],
				summary: verdict.summary || '', model: served, recipe_id: recipe.recipe_id });
			score = verdict.score;
		} else {
			field = 'iem_ai_summary';
			plaintext = String(verdict.summary || '');
		}
		var key = await keyOf(entry);
		if (lockEpoch !== at) return { status: 'dropped' };
		var sealedValue = EDGE_FIELD + await VaultCrypto.encrypt(plaintext, key,
			String(entry.sealed.sealed_ad_prefix) + entry.id + ':' + field);
		if (lockEpoch !== at) return { status: 'dropped' };
		var fields = {};
		fields[field] = sealedValue;
		var payload = { id: entry.id, recipe_id: recipe.recipe_id, fields: fields };
		if (score !== null) payload.danger_score = score;
		var r = await post('mailbox/device_ai_verdict', payload);
		return { status: 'done', recorded: !!(r && r.recorded), field: field, plaintext: plaintext, model: served };
	}

	function epoch() { return lockEpoch; }

	// ---- self-check --------------------------------------------------------------------

	// The formats this module reads, round-tripped with a throwaway key: an
	// attachment in the v1.edge. shape under a MIME-part AD, a wrong AD refused,
	// and a gzip search text ('gz:' + base64) inflated. Resolves
	// { ok, checks: [{name, ok}] }.
	async function selfCheck() {
		var checks = [];
		function note(name, ok) { checks.push({ name: name, ok: !!ok }); }
		try {
			var d = await VaultCrypto.newDek();
			d.dekBytes.fill(0);
			var ad = 'mail:42:att:2';
			var iv = crypto.getRandomValues(new Uint8Array(12));
			var body = new Uint8Array([0, 1, 2, 250, 251, 255]);
			var ct = new Uint8Array(await crypto.subtle.encrypt(
				{ name: 'AES-GCM', iv: iv, additionalData: new TextEncoder().encode(ad) }, d.dekKey, body));
			var joined = new Uint8Array(12 + ct.length);
			joined.set(iv, 0);
			joined.set(ct, 12);
			var stored = EDGE_FIELD + VaultCrypto.b64encode(joined);
			var back = await openEdgeBytes(stored, d.dekKey, ad);
			note('attachment bytes open under the MIME-part AD',
				back.length === body.length && back.every(function (b, i) { return b === body[i]; }));
			var refused = false;
			try { await openEdgeBytes(stored, d.dekKey, 'mail:42:att:3'); } catch (e) { refused = true; }
			note('another part\'s AD is refused', refused);
		} catch (e) {
			note('attachment bytes open under the MIME-part AD', false);
		}
		try {
			// gzip of "fortress search text", made by PHP gzencode() (the server's writer).
			var text = await inflateSearchText('gz:H4sIAAAAAAACA0vLLyopSi0uVihOTSxKzlAoSa0oAQCiqAuHFAAAAA==');
			note('a gzip search text inflates', text === 'fortress search text');
		} catch (e) {
			note('a gzip search text inflates', false);
		}
		if (window.EmailDigest && window.VerdictCheck) {
			await selfCheckJudge(note);
		}
		// A page holds the vault client; the node gates that load this file do not.
		if (window.JoinerySealed) {
			note('subscribed to the mail vault\'s lock, whatever order the scripts loaded in', hooked);
		}
		return { ok: checks.every(function (c) { return c.ok; }), checks: checks };
	}

	/**
	 * judgeEntry() against a stub model, with a throwaway key: an invalid first
	 * answer is retried with the validator's words, the verdict posted is sealed
	 * under the row's key and AD and opens back to what the model said, a lock
	 * during the call drops the item unposted, an answer invalid twice records
	 * `error`, and a refusal from the model stops with nothing recorded.
	 */
	async function selfCheckJudge(note) {
		try {
			var d = await VaultCrypto.newDek();
			d.dekBytes.fill(0);
			var entry = { id: 42, received_time: '2026-09-24 10:00:00', spf_result: 'pass', dkim_result: 'pass',
				dmarc_result: 'pass', recipient: 'you@example.test',
				sealed: { key: 42, sealed_scope: 'mail', sealed_dek: 'v1.edgeseal.mail.stub', sealed_ad_prefix: 'mail:' } };
			var opened = { iem_raw_headers: 'From: Shop <s@shop.example>\nSubject: Your order', iem_sender: 'Shop <s@shop.example>',
				iem_subject: 'Your order', iem_body_plain: 'It shipped. https://track.example/1', iem_body_html: '',
				iem_attachment_manifest: '[{"filename":"label.pdf","content_type":"application/pdf","size":10}]' };
			var recipe = { recipe_id: 7, job_id: 'email_triage', system: 'SYSTEM PROMPT', nonce: 'feedf00d', max_tokens: 99,
				attachments: true, authserv_id: '',
				verdict_descriptor: { input: { summary: { type: 'string', required: true, max_length: 280 } } } };
			var endpoint = { url: 'https://stub.invalid/v1/chat/completions', key: 'stub-key', model: 'stub-model' };
			function reply(content, status, finish) {
				return { ok: !status || status < 300, status: status || 200,
					text: function () { return Promise.resolve(JSON.stringify(status >= 300
						? { error: { message: content } } : { model: 'stub-model', choices: [{ message: { content: content }, finish_reason: finish || 'stop' }] })); } };
			}
			function deps(answers, posts, calls, onCall) {
				return {
					open: function () { return Promise.resolve(opened); },
					key: function () { return Promise.resolve(d.dekKey); },
					post: function (a, b) { posts.push({ action: a, body: b }); return Promise.resolve({ recorded: true }); },
					fetch: function (u, o) { calls.push({ url: u, body: JSON.parse(o.body), headers: o.headers });
						if (onCall) onCall(); return Promise.resolve(answers.shift()); }
				};
			}

			var posts = [], calls = [];
			var out = await judgeEntry(entry, recipe, endpoint,
				deps([reply('I think it is fine.'), reply('{"summary":"Your order shipped."}')], posts, calls));
			var first = calls[0] ? calls[0].body : {};
			note('the call carries the model, max_tokens, JSON mode, the reasoning control and the key',
				first.model === 'stub-model' && first.max_tokens === 99 && first.response_format && first.response_format.type === 'json_object'
				&& first.reasoning_effort === 'none' && calls[0].headers.Authorization === 'Bearer stub-key');
			note('the digest goes wrapped under the recipe\'s nonce, attachments included',
				first.messages && first.messages[0].content === 'SYSTEM PROMPT'
				&& first.messages[1].content.indexOf('<<UNTRUSTED_feedf00d>>\n=== EMAIL DIGEST ===') === 0
				&& first.messages[1].content.indexOf('ATTACHMENTS (1):') !== -1);
			note('an invalid first answer is retried once with the validator\'s words',
				calls.length === 2 && calls[1].body.messages.length === 4
				&& calls[1].body.messages[3].content.indexOf('That response was invalid: no JSON object found') === 0);
			var tposts = [], tcalls = [];
			var thought = await judgeEntry(entry, Object.assign({}, recipe, { reasoning_effort: 'medium' }), endpoint,
				deps([reply('', 200, 'length'), reply('{"summary":"Your order shipped."}')], tposts, tcalls));
			note('an answer spent entirely on reasoning is asked again with reasoning off, not with the validator\'s words',
				thought.status === 'done' && tcalls.length === 2 && tcalls[0].body.reasoning_effort === 'medium'
				&& tcalls[1].body.reasoning_effort === 'none' && tcalls[1].body.messages.length === 2);
			var p = posts[0] || { body: { fields: {} } };
			var sealedValue = p.body.fields.iem_ai_summary || '';
			var back = '';
			try { back = await VaultCrypto.decrypt(sealedValue.slice(EDGE_FIELD.length), d.dekKey, 'mail:42:iem_ai_summary'); } catch (e) { back = ''; }
			note('the verdict posted is sealed under the row\'s key and AD, and says what the model said',
				out.status === 'done' && p.action === 'mailbox/device_ai_verdict' && p.body.id === 42 && p.body.recipe_id === 7
				&& sealedValue.indexOf(EDGE_FIELD) === 0 && back === 'Your order shipped.');

			posts = []; calls = [];
			out = await judgeEntry(entry, recipe, endpoint,
				deps([reply('{"summary":"x"}')], posts, calls, function () { lockEpoch++; }));
			note('a lock during the call drops the item: nothing sealed, nothing posted', out.status === 'dropped' && posts.length === 0);

			posts = []; calls = [];
			out = await judgeEntry(entry, recipe, endpoint, deps([reply('nope'), reply('still nope')], posts, calls));
			note('an answer invalid twice records error', out.status === 'error' && posts.length === 1
				&& posts[0].action === 'mailbox/ai_device_record' && posts[0].body.item_key === '42');

			posts = []; calls = [];
			out = await judgeEntry(entry, recipe, endpoint, deps([reply('The API key you provided is invalid.', 401)], posts, calls));
			note('a refusal from the model stops the drain with nothing recorded',
				out.status === 'stop' && out.http === 401 && posts.length === 0 && out.reason.indexOf('API key') !== -1);
		} catch (e) {
			note('the judgement runs against a stub model (' + (e && e.message) + ')', false);
		}
	}

	return {
		isOpen: isOpen,
		ready: ready,
		isSetUp: isSetUp,
		unlock: unlock,
		openList: openList,
		openThread: openThread,
		attachmentBlob: attachmentBlob,
		download: download,
		adoptObjectUrl: adoptObjectUrl,
		inlineRewrite: inlineRewrite,
		inflateSearchText: inflateSearchText,
		newDraftState: newDraftState,
		saveDraft: saveDraft,
		openDraft: openDraft,
		draftFiles: draftFiles,
		sourceOpen: sourceOpen,
		sourceFiles: sourceFiles,
		onLock: onLock,
		judgeEntry: judgeEntry,
		epoch: epoch,
		selfCheck: selfCheck
	};
})();
