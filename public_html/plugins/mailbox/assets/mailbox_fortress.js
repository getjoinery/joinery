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
 *                     it under the row DEK with the MIME-part AD.
 *
 * None of these starts an unlock: a content action does, through unlock().
 * While the mail vault is shut, rows get placeholders and the response says
 * `fortress_locked`, which the reader turns into a banner with one button.
 *
 * Locking (JoinerySealed's idle lock, the lock chip, pagehide) drops every row
 * key and revokes every object URL handed out; the reader's onLock handler
 * re-renders from the server copies, which are ciphertext.
 *
 * Nothing opened here is sent back to the server.
 *
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

	if (window.JoinerySealed) {
		// This page reads the mail vault: the lock chip lists it and reads
		// "locked" while it is shut, even with the account vault open.
		if (JoinerySealed.want) JoinerySealed.want(SCOPE, 'Vault');
		JoinerySealed.onLock(SCOPE, function () {
			wipe();
			lockHandlers.forEach(function (fn) {
				try { fn(); } catch (e) { /* one handler must not stop the next */ }
			});
		});
	}

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
		return { ok: checks.every(function (c) { return c.ok; }), checks: checks };
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
		onLock: onLock,
		selfCheck: selfCheck
	};
})();
