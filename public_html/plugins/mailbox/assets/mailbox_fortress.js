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
 *                     what this browser opened, since the server cannot;
 *   drainPending()    parses what a relay sealed to this key (§ R9): the
 *                     message is opened and parsed here (MailboxMime) and its
 *                     fields and parts go back sealed under the row's own DEK.
 *                     It runs whenever the mail vault opens, and when a list
 *                     read says some wait (showPending);
 *   checkRelayPins()  asks the relay, through the server, which key it seals
 *                     each relay-fronted mailbox to, and checks the signed
 *                     answer against the relay pinned for it (§ R10).
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
 * @version 1.21 - showPending(n): every list read sets the waiting line (end-to-end mail waiting to be opened lists
 *   nowhere until it is) and, with the vault open, opens what waits; the line hides rather than leaving the page
 * @version 1.20.2 - the relay-change popup is a few lines: what the site says was done and when, that the new relay
 *   passed this device's checks, trust it if you know of the update or ask your site admin first, and its key
 * @version 1.20.1 - the relay check takes what it reaches (records, API, step-up, challenge) as an argument, so
 *   selfCheck swaps nothing on the page (reviewer2 N1)
 * @version 1.20 - a relay change is one plain popup per new relay, for every mailbox it covers: what happened,
 *   what the site says (marked as the site's word), what this device checked, the code to check off-site, and
 *   one press for them all; the other alarms say in plain words what happened and what to do
 *   (specs/relay_update_trust_step.md R2, R3, R6)
 * @version 1.19 - an answer without the challenge this check sent is refused: every relay runs 3.18 or later, so there is no
 *   time-window fallback and no per-relay record of which relays sign one (owner decision Q1)
 * @version 1.18 - each relay check sends a one-time challenge; an answer is current only if the relay signed it back, or, from a relay
 *   that has never signed one, if signed within 5 minutes of this device's clock (B4: an old signed answer cannot be shown again)
 * @version 1.17 - a mailbox whose move to Fortress has not reached the relay yet gets a quiet note and a
 *   recheck every 30 s for up to 5 min before the alarm; a key's fingerprint reads base64url keys
 * @version 1.16 - its page settings come from a JSON block the page writes (no inline script); a read before the block exists is not kept
 * @version 1.15 - an opened message's inline parts are the ones its body shows by cid: (MailboxMime.isInline, B5)
 * @version 1.14 - a draft's key is sealed in the v2 frame (specs/post_quantum_sealing.md WP2)
 * @version 1.13.2 - a failed device_rules fetch is not cached for the drain (review F6); the rule
 *   backlog walk moves past a row it cannot open (F7)
 * @version 1.13.1 - the pin self-check derives from the PKCS#8 secret, as session.mac() does
 * @version 1.13 - mail rules run here on relay-sealed mail (rule_matches, forward_raw) and on
 *   existing end-to-end mail (drainRuleBacklog), with MailboxFilterMatch (specs/fortress_mobile_apps.md § R14)
 * @version 1.12 - postForm() goes through joineryApi.postForm (the rotated-token retry, B2)
 * @version 1.11 - checkRelayPins(): the relay's signed seal-target statement checked against the
 *                pinned relay and the keys this browser derives from its own secrets (B47);
 *                a pin re-made mid-rotation passes (B48); a local record of each pin keeps a
 *                deleted server pin from passing for a first use (B49); first use pins, a
 *                mismatch raises the alarm (§ R10)
 * @version 1.10.1 - review of 2026-09-28: parts go as one bundle upload (B37); a stale key is
 *                fetched once more (B44); the banner says when this device could not open
 *                them (B43); a row sealed to a key the vault does not hold says so (B40)
 * @version 1.10 - drainPending(): relay-sealed arrivals are opened, parsed (MailboxMime), sealed
 *                under their own DEK and stored from here (specs/client_custody_mail.md § R9);
 *                a browser-parsed manifest names parts by number
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
	var EDGE_SEAL = 'v2.edgeseal.';   // X-Wing; nothing here parses the frame
	var EDGE_FIELD = 'v1.edge.';
	var PENDING_NOTE = 'Waiting to be opened on this device.';
	var LOCKED_NOTE = 'End-to-end encrypted. Unlock your vault to read it.';
	var FAILED_NOTE = 'This message could not be opened on this device.';
	var FOREIGN_NOTE = 'End-to-end encrypted. Only the mailbox owner\'s devices can open it.';
	var UNOPENABLE_NOTE = 'This message arrived sealed to a key your vault does not hold, so it cannot be opened. You can delete it.';
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

	function cfg() { return window.MAILBOX_READER || (window.MAILBOX_READER = (window.joineryPageData && joineryPageData('mailbox-reader-config')) || null) || {}; }

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
		var bytes = await JoinerySealed.openDek(SCOPE, sealedDek);
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
		// Sealed to a key no vault here holds: no unlock opens it either.
		sealed.forEach(function (t) { if (!t.sealed.foreign && t.sealed.unopenable) placeholderThread(t, UNOPENABLE_NOTE); });
		sealed = sealed.filter(function (t) { return !t.sealed.foreign && !t.sealed.unopenable; });
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
		var byId = {}, byPart = {};
		manifest.forEach(function (e) {
			if (e && e.id != null) byId[String(e.id)] = e;
			// A browser-parsed row names its parts by number: it sealed the
			// manifest before the server gave them ids (drainPending).
			else if (e && e.mime_part) byPart[String(e.mime_part)] = e;
		});

		rememberKey(m.id, m.sealed.sealed_dek);
		var parts = (m.attachments || []).map(function (a) {
			var e = byId[String(a.id)] || byPart[String(a.mime_part)] || {};
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
		// Inline is what the opened body shows by cid:, the rule the server and
		// the parser apply (MailboxMime.isInline), whatever the stored flag says.
		var shown = MailboxMime.inlineIds(m.body_html);
		parts.forEach(function (a) { a.inline = MailboxMime.isInline(a.content_id, shown); });
		m.attachments = parts.filter(function (a) { return !a.inline; });
		var inline = parts.filter(function (a) { return a.inline; });
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
			if (m.sealed.unopenable) { placeholderMessage(m, UNOPENABLE_NOTE); continue; }
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

	// POST a FormData to an API action through the shared transport (which
	// retries once on a rotated CSRF token) and unwrap its envelope.
	async function postForm(url, body) {
		return (await window.joineryApi.postForm(url, body)) || {};
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

		d.dekBytes = await JoinerySealed.openDek(SCOPE, String(data.sealed.sealed_dek || ''), { reason: 'to open this draft' });
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

	// ---- relay-sealed arrivals (§ R9) --------------------------------------------------
	//
	// A relay that fronts a Fortress mailbox seals each message to this vault's
	// key: the raw message under a DEK only this key opens. The server stores it
	// pending and cannot read it, so the first of the owner's devices to unlock
	// parses it here and posts back the fields and parts, sealed under that same
	// DEK. Newest first; a message this device cannot read is skipped, not
	// retried in a loop, and stays pending for another device or a later visit.

	var FORTRESS_FIELD_MAX = { iem_subject: 4000, iem_sender: 500 };
	var draining = null;
	var ruleCache = {};   // alias id => the mailbox's rules (device_rules), for one drain

	/** Parse and store every relay-sealed message waiting for this vault. Resolves the count stored. */
	function drainPending() {
		if (draining) return draining;
		draining = (async function () {
			var epoch0 = lockEpoch, stored = 0, skip = [], refetched = {};
			ruleCache = {};
			try {
				while (isOpen() && lockEpoch === epoch0) {
					var d = await window.joineryApi.post('mailbox/fortress_pending', { skip: skip.join(',') });
					var item = d && d.item;
					// What is left once this device has given up on some: those
					// stay waiting for another device, and the banner says so.
					pendingBanner(d ? d.remaining : 0, item ? 0 : skip.length);
					if (!item) break;
					try {
						var outcome = await parsePending(item, epoch0);
						if (outcome === 'dropped') break;
						// A rotation re-wrapped the row's key after the fetch: fetch
						// it once more, then leave it for the next visit.
						if (outcome === 'stale') {
							if (refetched[item.id]) skip.push(item.id);
							refetched[item.id] = true;
							continue;
						}
						stored++;
					} catch (e) {
						skip.push(item.id);
						if (window.console) console.warn('MailboxFortress: message ' + item.id + ' could not be parsed here: '
							+ (e && e.message));
					}
				}
			} finally {
				draining = null;
				if (stored && window.MailboxReader && MailboxReader.refreshList) MailboxReader.refreshList();
			}
			return stored;
		})();
		return draining;
	}

	/**
	 * The waiting line's count, as the last fetch or list read said; hidden at
	 * none. Mail waiting here lists nowhere until it is opened, so this line is
	 * where it shows. `failed` of them could not be opened on this device (the
	 * drain skipped them), which the line says instead of promising they are
	 * on their way; with the vault shut it asks for the unlock.
	 */
	var pendingFailed = 0;
	function pendingBanner(n, failed) {
		if (typeof document === 'undefined') return;
		var el = document.querySelector('[data-fortress-pending]');
		if (!el) return;
		pendingFailed = failed || 0;
		el.hidden = !(n > 0);
		if (el.hidden) return;
		var text = el.querySelector('[data-fortress-pending-text]');
		if (!text) return;
		var plural = function (k) { return k + ' new end-to-end message' + (k === 1 ? '' : 's'); };
		var them = n === 1 ? 'it' : 'them';
		text.textContent = !isOpen()
			? plural(n) + ' waiting to be opened on this device. Unlock your vault to read ' + them + '.'
			: (pendingFailed >= n
				? plural(n) + ' could not be opened on this device. Another of your devices may open '
					+ them + '; if none can, please report this problem.'
				: plural(n) + ' being opened on this device.');
	}

	/**
	 * A list read said how many are waiting (fortress_pending): show the line
	 * and, with the vault open, open them now — mail that arrives while the
	 * page is open would otherwise wait for the next unlock or reload. A drain
	 * already running is joined, not doubled.
	 */
	function showPending(n) {
		n = n > 0 ? n : 0;
		// After ready(): a list read can land before the vault has said whether
		// it is open, and the line would ask for an unlock it does not need.
		return ready().then(function () {
			if (!draining) pendingBanner(n, Math.min(pendingFailed, n));
			if (n > 0 && isOpen()) return drainPending();
		}).catch(function () {});
	}

	/**
	 * One pending row: open the message under the row's DEK, parse it, seal
	 * every field and part under the same DEK, and post the lot. Resolves
	 * 'stored', 'already' (another device got there first), 'stale' (a
	 * rotation re-wrapped its key after the fetch: nothing stored) or 'dropped'
	 * (the vault shut part way: nothing was posted).
	 */
	async function parsePending(item, epoch0) {
		var key = await importRowKey(item.sealed_dek);
		var raw = await openEdgeBytes(item.sealed_raw, key, item.raw_ad);
		// The parser hands back views into these bytes for parts that need no
		// decoding, so they are wiped only once everything is sealed.
		try {
			return await sealAndStore(item, key, MailboxMime.parse(raw), epoch0, raw);
		} finally {
			raw.fill(0);
		}
	}

	/**
	 * The mailbox's mail rules as a device evaluates them, fetched once per
	 * drain. A mailbox with none, or a page without the matcher, runs none.
	 */
	async function rulesFor(aliasId) {
		if (!aliasId || !window.MailboxFilterMatch) return [];
		if (!ruleCache[aliasId]) {
			// A failed fetch is not kept: the next message asks again, so one
			// passing error costs one message a retry on a later visit, not
			// every message of the mailbox in this drain.
			ruleCache[aliasId] = window.joineryApi.post('mailbox/device_rules', { alias_id: aliasId })
				.then(function (d) { return (d && d.rules) || []; },
					function (e) { delete ruleCache[aliasId]; throw e; });
		}
		return ruleCache[aliasId];
	}

	/**
	 * Which of the mailbox's rules the parsed message matches, and whether any
	 * of them forwards (the server then needs the opened message to relay).
	 * The server applies the rules' own actions; this only names them.
	 */
	async function ruleMatches(item, p, rawLength) {
		var rules = await rulesFor(item.alias_id);
		if (!rules.length) return { ids: [], forwards: false };
		var ids = MailboxFilterMatch.matchingIds(rules, {
			sender: p.from, recipient: item.recipient || '', subject: p.subject,
			body_plain: p.textPlain, body_html: p.textHtml,
			size_bytes: item.size_bytes || rawLength, has_attachment: p.attachments.length > 0
		});
		var forwards = rules.some(function (r) { return r.forwards && ids.indexOf(r.id) !== -1; });
		return { ids: ids, forwards: forwards };
	}

	async function sealAndStore(item, key, p, epoch0, raw) {
		var readable = readableText(p.textHtml);
		var names = p.attachments.map(function (a) { return a.filename; }).filter(function (n) { return n; });
		var values = {
			iem_sender: p.from,
			iem_subject: p.subject,
			iem_body_plain: p.textPlain,
			iem_body_html: p.textHtml,
			iem_raw_headers: p.headers,
			iem_to: p.to,
			iem_cc: p.cc,
			iem_snippet: snippetOf(p.textPlain) || readable.replace(/\s+/g, ' ').trim().slice(0, 240),
			iem_search_text: await packSearchText([p.from, p.subject, names.join(' '), p.textPlain, readable].join(' ')),
			iem_attachment_manifest: p.attachments.length ? JSON.stringify(p.attachments.map(function (a) {
				return { mime_part: a.mimePart, filename: a.filename, content_type: a.contentType,
					content_id: a.contentId, inline: !!a.inline, size: a.bytes.length };
			})) : ''
		};
		var prefix = String(item.sealed_ad_prefix) + item.id;
		var fields = {};
		var cols = Object.keys(values);
		for (var i = 0; i < cols.length; i++) {
			var v = String(values[cols[i]] || '');
			if (FORTRESS_FIELD_MAX[cols[i]]) v = v.slice(0, FORTRESS_FIELD_MAX[cols[i]]);
			// Empty stays bare, as the server stores it.
			fields[cols[i]] = v === '' ? '' : EDGE_FIELD + await VaultCrypto.encrypt(v, key, prefix + ':' + cols[i]);
		}
		var body = new FormData();
		body.append('id', String(item.id));
		body.append('sealed_dek', item.sealed_dek);
		body.append('fields', JSON.stringify(fields));
		// Every part in ONE upload, each at its offset: the server takes at most
		// 20 files per request, and a message can have more parts than that.
		var parts = [], chunks = [], offset = 0;
		for (var j = 0; j < p.attachments.length; j++) {
			var a = p.attachments[j];
			var sealed = await sealEdgeBytes(a.bytes, key, prefix + ':att:' + a.mimePart);
			chunks.push(sealed);
			parts.push({ mime_part: a.mimePart, size: a.bytes.length, inline: !!a.inline,
				offset: offset, length: sealed.length });
			offset += sealed.length;   // ASCII: one byte per character
		}
		if (chunks.length) body.append('bundle', new Blob(chunks, { type: 'application/octet-stream' }), 'parts');
		body.append('parts', JSON.stringify(parts));
		body.append('spam_headers', JSON.stringify({
			x_spam: MailboxMime.headerValue(p, 'X-Spam'),
			x_spam_flag: MailboxMime.headerValue(p, 'X-Spam-Flag'),
			x_spam_score: MailboxMime.headerValue(p, 'X-Spam-Score'),
			x_spam_status: MailboxMime.headerValue(p, 'X-Spam-Status')
		}));
		var rules = await ruleMatches(item, p, raw ? raw.length : 0);
		if (rules.ids.length) body.append('rule_matches', JSON.stringify(rules.ids));
		// A forward is the one rule action the server cannot take from ciphertext:
		// it relays the message this device opened, and keeps nothing.
		if (rules.forwards && raw) body.append('forward_raw', new Blob([raw], { type: 'message/rfc822' }), 'message.eml');
		if (lockEpoch !== epoch0 || !isOpen()) return 'dropped';
		var answer = await postForm('/api/v1/action/mailbox/fortress_parse_store', body);
		return answer.stored ? 'stored' : (answer.stale ? 'stale' : 'already');
	}

	// ---- mail rules on existing mail (§ R14 of fortress_mobile_apps) ------------------
	//
	// "Also apply to existing mail" on end-to-end rows: the server hands this
	// device one rule and a page of the owner's rows it cannot read; this
	// device opens each, names the ones the rule matches, and the server applies
	// the rule's own actions (never a forward) and moves on.

	var backlogging = null;

	/** Walk every rule's existing-mail backlog. Resolves how many rows a rule applied to. */
	function drainRuleBacklog() {
		if (backlogging) return backlogging;
		backlogging = (async function () {
			var epoch0 = lockEpoch, applied = 0;
			try {
				while (window.MailboxFilterMatch && isOpen() && lockEpoch === epoch0) {
					var page = await window.joineryApi.post('mailbox/rule_backlog', {});
					if (!page || !page.rule) break;
					var matched = [];
					for (var i = 0; i < page.rows.length; i++) {
						var row = page.rows[i];
						var o;
						// A row this device cannot open cannot match here; the walk
						// moves past it rather than asking for the same page forever.
						try { o = await JoinerySealed.open(row.sealed); } catch (e) { continue; }
						if (MailboxFilterMatch.matches(page.rule, {
							sender: o.iem_sender || '', recipient: row.recipient || o.iem_recipient || '',
							subject: o.iem_subject || '', body_plain: o.iem_body_plain || '', body_html: o.iem_body_html || '',
							size_bytes: row.size_bytes, has_attachment: !!row.has_attachment
						})) matched.push(row.id);
					}
					if (lockEpoch !== epoch0 || !isOpen()) break;
					var done = await window.joineryApi.post('mailbox/rule_outcomes', {
						rule_id: page.rule.id, through_id: page.through_id, matched_ids: JSON.stringify(matched)
					});
					applied += (done && done.applied) || 0;
				}
			} finally {
				backlogging = null;
				if (applied && window.MailboxReader && MailboxReader.refreshList) MailboxReader.refreshList();
			}
			return applied;
		})();
		return backlogging;
	}

	/** An HTML body's readable text, for search and the preview. Parsing runs no script. */
	function readableText(html) {
		if (!html || typeof DOMParser === 'undefined') return '';
		var doc = new DOMParser().parseFromString(String(html), 'text/html');
		Array.prototype.forEach.call(doc.querySelectorAll('script,style,head'), function (n) { n.remove(); });
		return (doc.body ? doc.body.textContent : '') || '';
	}

	/**
	 * A search text as the server stores one (InboundEmailMessage::searchTextFor):
	 * whitespace folded, cut at 32768 characters, and 'gz:' + base64(gzip) when
	 * that is a third or more shorter.
	 */
	async function packSearchText(text) {
		text = Array.from(String(text).replace(/\s+/g, ' ').trim()).slice(0, 32768).join('');
		if (text === '' || typeof CompressionStream === 'undefined') return text;
		var plain = new TextEncoder().encode(text);
		var stream = new Blob([plain]).stream().pipeThrough(new CompressionStream('gzip'));
		var gz = 'gz:' + VaultCrypto.b64encode(new Uint8Array(await new Response(stream).arrayBuffer()));
		return (gz.length * 3 <= plain.length * 2) ? gz : text;
	}

	// ---- the relay pin (§ R10) ------------------------------------------------------
	//
	// Under Seal at the relay, the relay seals a Fortress mailbox's mail to the
	// key the server's map names. This browser asks the relay which key that is
	// (the relay signs the answer with its identity key, which the server never
	// holds) and checks it against the relay it pinned for the mailbox. The pin
	// is MACed with a key only this vault derives (session.mac), so the server
	// cannot plant one. The first check pins what the server reports (trust on
	// first use, which the Fortress card says).

	var SEAL_TARGET_PREFIX = 'joinery-relay:seal-target:v1\n';
	var PIN_PREFIX = 'joinery-relay-pin:v1\n';
	// A hacked server could keep a statement the relay signed while it sealed
	// to this device's key and show it again after moving the relay to its own.
	// Each check sends a challenge made for it, which the relay signs into the
	// statement; only an answer carrying it is current.
	var pinsChecked = false;

	function pinMessage(aliasId, identity) {
		return new TextEncoder().encode(PIN_PREFIX + aliasId + '\n' + identity);
	}

	function sameText(a, b) {
		a = String(a || ''); b = String(b || '');
		if (a.length !== b.length) return false;
		var d = 0;
		for (var i = 0; i < a.length; i++) d |= a.charCodeAt(i) ^ b.charCodeAt(i);
		return d === 0;
	}

	/**
	 * Judge one mailbox's answer from mailbox/relay_seal_target. mac(bytes)
	 * makes this vault's pin MAC, or is a list of them (the current key's and,
	 * mid-rotation, the pending key's: a pin re-made under the new key before
	 * the commit is still this vault's, B48); keys are the public keys this
	 * browser worked out from secrets it holds (acceptedKeys), never the
	 * server's report of them (B47). Resolves
	 * {ok, firstUse, identity} or {ok: false, reason, expected, reported,
	 * approvable}: reason 'pin' (a pin this vault did not make), 'identity'
	 * (a relay other than the pinned one; approvable when its own statement is
	 * signed, current and names this vault's key), 'signature', 'replayed'
	 * (not signed for this check: it carries no challenge, or another one than
	 * the one sent), 'key' (the relay seals to another key), 'unreadable'.
	 * opts: {challenge (the one this check sent)}.
	 */
	async function judgeSealTarget(answer, box, mac, keys, opts) {
		opts = opts || {};
		var relay, st;
		try { relay = JSON.parse(answer.relay_answer); st = JSON.parse(relay.statement); } catch (e) { relay = null; }
		if (!relay || !st || typeof relay.signature !== 'string') return { ok: false, reason: 'unreadable' };
		var signedChallenge = (typeof st.challenge === 'string') ? st.challenge : '';
		var sent = String(opts.challenge || '');
		var current = signedChallenge !== '' && sent !== '' && sameText(signedChallenge, sent);

		var pinned, firstUse = false;
		if (answer.pin) {
			var macs = Array.isArray(mac) ? mac : [mac], mine = false;
			for (var m = 0; m < macs.length && !mine; m++) {
				mine = sameText(VaultCrypto.b64encode(await macs[m](pinMessage(box.alias_id, answer.pin.relay_identity_public_key))), answer.pin.mac);
			}
			if (!mine) {
				return { ok: false, reason: 'pin', expected: answer.pin.relay_identity_public_key, reported: st.relay_identity_public_key };
			}
			pinned = answer.pin.relay_identity_public_key;
		} else {
			pinned = answer.relay_identity_public_key;
			firstUse = true;
		}
		var signed = new TextEncoder().encode(SEAL_TARGET_PREFIX + relay.statement);
		var names = st.recipient === String(box.address).toLowerCase() && st.key_scope === SCOPE
			&& st.key_kind === 'client' && keys.indexOf(st.public_key) !== -1;
		if (!sameText(st.relay_identity_public_key, pinned)) {
			var selfSigned = await VaultCrypto.verifyEd25519(st.relay_identity_public_key, signed, relay.signature);
			return { ok: false, reason: 'identity', expected: pinned, reported: st.relay_identity_public_key,
				approvable: selfSigned && names && current, identity: st.relay_identity_public_key };
		}
		if (!await VaultCrypto.verifyEd25519(pinned, signed, relay.signature)) {
			return { ok: false, reason: 'signature', expected: pinned, reported: pinned };
		}
		if (!current) {
			return { ok: false, reason: 'replayed', expected: pinned, reported: pinned, signedAt: st.signed_at };
		}
		if (!names) {
			// The relay this device trusts, in its own signed words, still seals
			// this address to the server's transport key: a mailbox just moved to
			// Fortress whose new key has not reached the relay yet. The server
			// opens such mail at pull and seals it to the mail key.
			var switching = st.recipient === String(box.address).toLowerCase() && st.key_kind === 'transport';
			return { ok: false, reason: 'key', expected: keys[0], reported: st.public_key, switching: switching };
		}
		return { ok: true, firstUse: firstUse, identity: pinned };
	}

	/** A key's fingerprint for people: SHA-256, the first 16 bytes in groups of four hex digits. */
	async function fingerprint(b64) {
		try {
			// Keys travel as base64url (SealedBox) or plain base64; b64urlDecode reads both.
			var d = new Uint8Array(await crypto.subtle.digest('SHA-256', VaultCrypto.b64urlDecode(String(b64))));
			var hex = Array.prototype.map.call(d.slice(0, 16), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
			return hex.match(/.{4}/g).join(' ');
		} catch (e) { return '(unreadable)'; }
	}

	/**
	 * The public keys this browser accepts a relay sealing to, each worked out
	 * from a secret it holds (B47): the server reports a vault's public keys,
	 * and a hacked one could report a key of its own, push it to the relay as
	 * the pending key, and have the honest relay sign for it. The current key
	 * comes from the open mail session. A pending key (mid-rotation) counts only
	 * once opened through the root vault from its `root` wrapping, which only
	 * this browser's root secret opens; with the root shut the check waits for
	 * a later page (null), which is no worse than a server withholding it.
	 * Resolves {keys, macs} or null.
	 */
	async function acceptedKeys(session) {
		var keys = [await session.derivedPublicKey()];
		var macs = [function (b) { return session.mac(b); }];
		var st = await VaultKeyring.status(SCOPE);
		if (st && st.pending_public_key) {
			if (!st.root_wrapped || !JoinerySealed.isOpen('root')) return null;
			var pending = await VaultKeyring.openThroughRoot(await JoinerySealed.session('root'), SCOPE, VaultKeyring.pendingStatus(st));
			if (!pending) return null;
			keys.push(await pending.derivedPublicKey());
			macs.push(function (b) { return pending.mac(b); });
			return { keys: keys, macs: macs, done: function () { pending.lock(); } };
		}
		return { keys: keys, macs: macs, done: function () {} };
	}

	// This browser's own record of the relay each mailbox was pinned to (B49):
	// a pin the server deleted then cannot pass for a first use here.
	// What the relay check reaches outside itself: this browser's records,
	// the API, the step-up and the challenge. The page uses RELAY_ENV;
	// selfCheck hands in its own, so a check it runs never touches the page's
	// records, API or challenge, even while a real check is in flight.
	var RELAY_ENV = {
		store: function () { return window.localStorage; },
		post: function (action, data) { return window.joineryApi.post(action, data); },
		stepUp: function () { return stepUp(); },
		challenge: function () { return newChallenge(); }
	};

	var LOCAL_PIN = 'joinery-relay-pin:';
	function localPin(aliasId, env) {
		try { return (env || RELAY_ENV).store().getItem(LOCAL_PIN + aliasId) || null; } catch (e) { return null; }
	}
	function rememberPin(aliasId, identity, env) {
		try { (env || RELAY_ENV).store().setItem(LOCAL_PIN + aliasId, identity); } catch (e) { /* no storage here */ }
	}
	// When this browser last saw a mailbox's relay answer as it should: shown
	// when the relay changes, for information only (R6).
	var LOCAL_PIN_TIME = 'joinery-relay-pin-time:';
	function rememberPinTime(aliasId, env) {
		try { (env || RELAY_ENV).store().setItem(LOCAL_PIN_TIME + aliasId, String(Date.now())); } catch (e) { /* no storage here */ }
	}
	function pinTime(aliasId, env) {
		try { return parseInt((env || RELAY_ENV).store().getItem(LOCAL_PIN_TIME + aliasId), 10) || null; } catch (e) { return null; }
	}
	// This browser's own note that it started a relay update (relay_grant.js,
	// R3): {run_id, at}, good for a day, used up by the trust it explains.
	var UPDATE_NOTE = 'joinery-relay-update:', UPDATE_NOTE_MS = 24 * 60 * 60 * 1000;
	function updateNote(update, now, env) {
		if (!update || !update.relay_id) return null;
		var note = null;
		try { note = JSON.parse((env || RELAY_ENV).store().getItem(UPDATE_NOTE + update.relay_id) || 'null'); } catch (e) { return null; }
		var clock = (typeof now === 'number') ? now : Date.now();
		if (!note || typeof note.at !== 'number' || clock - note.at > UPDATE_NOTE_MS || note.at > clock) return null;
		if (note.run_id !== update.run_id) return null;
		return note;
	}
	function forgetUpdateNote(update, env) {
		if (!update || !update.relay_id) return;
		try { (env || RELAY_ENV).store().removeItem(UPDATE_NOTE + update.relay_id); } catch (e) { /* no storage here */ }
	}

	/** A one-time challenge for one relay check: 18 random bytes, base64url (24 characters). */
	function newChallenge() {
		return VaultCrypto.b64urlEncode(crypto.getRandomValues(new Uint8Array(18)));
	}

	/**
	 * Check every mailbox the relay seals for this browser (MAILBOX_READER
	 * .relayPinMailboxes), once per page, while the mail vault is open.
	 */
	async function checkRelayPins(only) {
		var boxes = only || cfg().relayPinMailboxes || [];
		if (!only && pinsChecked) return;
		if (!boxes.length) return;
		// A recheck that cannot run now is asked again later, so a mailbox's
		// "finishing its move" note never outlives its grace unjudged.
		var again = function () { (only || []).forEach(recheckSoon); };
		if (!isOpen()) { again(); return; }
		if (!only) pinsChecked = true;
		if (!(await VaultCrypto.ed25519Supported())) {
			if (window.console) console.warn('MailboxFortress: this browser cannot check the relay\'s signature (no Ed25519).');
			return;
		}
		var session = await JoinerySealed.session(SCOPE);
		var accepted = await acceptedKeys(session);
		if (!accepted) {
			if (!only) pinsChecked = false;
			again();
			if (window.console) console.warn('MailboxFortress: the relay check waits for your vault to be open on a page with the rotation\'s new key.');
			return;
		}
		try {
			var changes = {};   // new relay identity => [{box, verdict, update}]
			for (var i = 0; i < boxes.length; i++) {
				await checkOne(boxes[i], session, accepted, changes);
			}
			for (var id in changes) {
				if (Object.prototype.hasOwnProperty.call(changes, id)) await relayChangePopup(changes[id], session);
			}
		} finally {
			accepted.done();   // the pending key's secret leaves memory with the check
		}
	}

	async function checkOne(box, session, accepted, changes, env) {
		env = env || RELAY_ENV;
		var answer;
		var challenge = env.challenge();
		try {
			answer = await env.post('mailbox/relay_seal_target', { alias_id: box.alias_id, challenge: challenge });
		} catch (e) {
			// The server can withhold the check; it cannot forge its answer.
			if (window.console) console.warn('MailboxFortress: the relay could not be asked about ' + box.address + ': ' + (e && e.message));
			// A mailbox mid-move is asked again; its grace keeps running meanwhile.
			if (typeof document !== 'undefined' && document.querySelector('[data-relay-switching="' + box.alias_id + '"]')) recheckSoon(box);
			return;
		}
		// A mailbox this browser pinned before whose pin the server no longer
		// has: judged against what this browser pinned, not as a first use.
		var local = localPin(box.alias_id, env);
		if (!answer.pin && local) answer = Object.assign({}, answer, { relay_identity_public_key: local });
		var verdict = await judgeSealTarget(answer, box, accepted.macs, accepted.keys, { challenge: challenge });
		// The grace is for a mailbox this browser has never seen sealed to its
		// key: no pin anywhere, since a pin is made only by an ok check. A relay
		// put back on the transport key after that is what the alarm is for.
		var neverSealed = !answer.pin && !local;
		if (!verdict.ok && verdict.switching && neverSealed) {
			if (stillSwitching(box.alias_id)) {
				switchingNotice(box, true);
				recheckSoon(box);
				return;
			}
			// The grace ran out. Its record stays while the relay still seals to
			// the transport key, so the next page alarms too instead of granting
			// a fresh grace.
			switchingNotice(box, false);
			await relayAlarm(box, verdict, session);
			return;
		}
		forgetSwitching(box.alias_id);
		switchingNotice(box, false);
		if (!verdict.ok && verdict.reason === 'identity' && verdict.approvable && changes) {
			collectChange(changes, box, verdict, answer.update || null);
			return;
		}
		if (verdict.ok) {
			rememberPin(box.alias_id, verdict.identity, env);
			rememberPinTime(box.alias_id, env);
			if (verdict.firstUse) {
				await savePin(session, box, verdict.identity, env).catch(function (e) {
					if (window.console) console.warn('MailboxFortress: could not pin the relay for ' + box.address + ': ' + (e && e.message));
				});
			}
		} else {
			await relayAlarm(box, verdict, session);
		}
	}

	// A mailbox just moved to Fortress: the relay keeps sealing to the server's
	// transport key until this server's next routing push reaches it (at once
	// as a rule, or the next cron pass). For that long this page says the move
	// is finishing and asks again; past SWITCH_GRACE_MS the alarm stands, and
	// stays on every later page until the relay seals to this key, so a server
	// that kept the relay off it gains no more than the push gap. Only a
	// mailbox never seen sealed here gets it (checkOne). The start is this
	// browser's own record, which the server cannot reset; a server can as well
	// withhold the check outright (checkOne's catch), so the grace hands it
	// nothing it lacks.
	var SWITCH_GRACE_MS = 5 * 60 * 1000, SWITCH_RECHECK_MS = 30 * 1000;
	var SWITCH_SEEN = 'joinery-relay-switching:';
	var switchSeen = {}, recheckTimer = null, recheckBoxes = {};
	function stillSwitching(aliasId) {
		var now = Date.now(), first = switchSeen[aliasId] || null;
		try { first = first || parseInt(window.localStorage.getItem(SWITCH_SEEN + aliasId), 10) || null; } catch (e) { /* no storage here */ }
		if (!first || first > now) {
			first = now;
			try { window.localStorage.setItem(SWITCH_SEEN + aliasId, String(now)); } catch (e) { /* no storage here */ }
		}
		switchSeen[aliasId] = first;
		return now - first < SWITCH_GRACE_MS;
	}
	function forgetSwitching(aliasId) {
		delete switchSeen[aliasId];
		try { window.localStorage.removeItem(SWITCH_SEEN + aliasId); } catch (e) { /* no storage here */ }
	}
	/** Ask again about the mailboxes still switching, and only those: one with a standing alarm is not asked again. */
	function recheckSoon(box) {
		recheckBoxes[box.alias_id] = box;
		if (recheckTimer) return;
		recheckTimer = setTimeout(function () {
			recheckTimer = null;
			var boxes = Object.keys(recheckBoxes).map(function (k) { return recheckBoxes[k]; });
			recheckBoxes = {};
			checkRelayPins(boxes).catch(function () {});
		}, SWITCH_RECHECK_MS);
	}
	/** The quiet note under the reader while a mailbox's move to Fortress reaches the relay. */
	function switchingNotice(box, show) {
		if (typeof document === 'undefined') return;
		var el = document.querySelector('[data-relay-switching="' + box.alias_id + '"]');
		if (!show) { if (el) el.parentNode.removeChild(el); return; }
		if (el) return;
		el = document.createElement('div');
		el.className = 'mbx-relay-switching';
		el.setAttribute('role', 'status');
		el.setAttribute('data-relay-switching', String(box.alias_id));
		el.textContent = box.address + ' is finishing its move to end-to-end encryption: the mail relay has not picked up '
			+ 'your device key yet. Until it does, new mail is sealed to your key when this server collects it. '
			+ 'This usually takes about a minute.';
		var anchor = document.getElementById('mbx-reader');
		if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(el, anchor);
		else document.body.appendChild(el);
	}

	function savePin(session, box, identity, env) {
		return session.mac(pinMessage(box.alias_id, identity)).then(function (m) {
			return (env || RELAY_ENV).post('mailbox/relay_pin_set', { alias_id: box.alias_id,
				relay_identity_public_key: identity, mac: VaultCrypto.b64encode(m) });
		});
	}

	function stepUp() {
		if (!window.JoineryPasskeys || !window.JoineryPasskeys.stepUp) return Promise.reject(new Error('no passkey here'));
		return window.JoineryPasskeys.stepUp();
	}

	// Every alarm says what happened, what it can mean and what to do, for
	// someone who has never heard the word relay.
	var RELAY_INTRO = 'Your mail passes through a relay on its way to this site, and the relay seals it so only you can read it.';
	var ALARMS = {
		pin: { title: 'Your mail protection was changed without your devices',
			what: 'Your devices keep a record, made with your own key, of which relay protects this mail. The record this site holds now was not made by any of your devices.' },
		identity: { title: 'A different relay is answering, and it did not pass this device\'s check',
			what: 'A different relay is answering for this mailbox, and it did not sign this device\'s one-time question, or it does not say it seals your mail to your own key.' },
		signature: { title: 'The relay\'s answer could not be confirmed',
			what: 'The answer that should come from your relay is not signed by it. Someone may be answering in its place.' },
		replayed: { title: 'The relay\'s answer was not made for this check',
			what: 'This device asked your relay a one-time question, and the answer it got back was made for another one: an old answer shown again. That can hide a change to your relay.' },
		key: { title: 'Your mail is not being sealed to your key',
			what: 'Your relay says it seals mail to this address with a key that is not yours, so whoever holds that key could read new mail.' },
		unreadable: { title: 'This device could not read the relay\'s answer',
			what: 'This can be a passing fault. If it keeps happening, tell your site admin.' }
	};

	function el(tag, text, cls) {
		var n = document.createElement(tag);
		if (text) n.textContent = text;
		if (cls) n.className = cls;
		return n;
	}
	function dateText(ms) {
		if (!ms) return '';
		return new Date(ms).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
	}
	function dialogShell(kind) {
		var dlg = document.createElement('dialog');
		dlg.className = 'mbx-relay-alarm';
		dlg.setAttribute('data-relay-alarm', kind);
		dlg.style.maxWidth = '36rem';
		return dlg;
	}
	function showDialog(dlg) {
		document.body.appendChild(dlg);
		if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
	}
	function closeButton(dlg, label) {
		var b = el('button', label || 'Close', 'btn btn-secondary');
		b.type = 'button';
		b.addEventListener('click', function () { dlg.close(); dlg.remove(); });
		return b;
	}

	/** Every alarm but a new relay that passed this device's check (that is relayChangePopup). */
	async function relayAlarm(box, verdict, session) {
		if (typeof document === 'undefined') return;
		var a = ALARMS[verdict.reason] || ALARMS.unreadable;
		var dlg = dialogShell(verdict.reason);
		dlg.appendChild(el('h3', a.title));
		dlg.appendChild(el('p', RELAY_INTRO + ' ' + a.what));
		if (verdict.reason !== 'unreadable') {
			dlg.appendChild(el('p', 'This can mean that someone who controls this site is trying to read your mail.'));
		}
		dlg.appendChild(el('p', 'Mailbox: ' + box.address));
		if (verdict.expected || verdict.reported) {
			var dl = document.createElement('dl');
			var row = function (label, value) {
				var dt = el('dt', label), dd = el('dd', value);
				dd.style.fontFamily = 'monospace';
				dl.appendChild(dt); dl.appendChild(dd);
			};
			row(verdict.reason === 'key' ? 'This device\'s key' : 'The relay this device trusts', await fingerprint(verdict.expected));
			row(verdict.reason === 'key' ? 'The key the relay seals to' : 'The relay answering now', await fingerprint(verdict.reported));
			dlg.appendChild(dl);
		}
		dlg.appendChild(el('p', 'What to do: do not trust anything new yet. Show this message to your site admin. '
			+ 'Your existing mail is safe; this is about new mail.'));
		var buttons = el('div');
		buttons.style.display = 'flex';
		buttons.style.gap = '.5rem';
		buttons.appendChild(closeButton(dlg));
		dlg.appendChild(buttons);
		showDialog(dlg);
	}

	/**
	 * What one new relay's popup says (R2), worked out from what this device
	 * checked and what the site says, without touching the page: the state
	 * ('explained' when the site's account names this identity and is done,
	 * 'finishing' or 'unfinished' when its run is still going or failed,
	 * 'unexplained' when it names none or another), and every line. The
	 * site's account picks words only; every state says the device cannot
	 * check it, and none trusts anything until the owner presses.
	 */
	function relayChangeView(identity, update, note, lastSeen, code) {
		var named = !!(update && update.identity_public_key && sameText(update.identity_public_key, identity));
		var state = !named ? 'unexplained'
			: (update.status === 'done' ? 'explained' : (update.status === 'failed' ? 'unfinished' : 'finishing'));
		var started = named ? dateText(Date.parse(update.started_time)) : '';
		var done = named ? dateText(Date.parse(update.done_time)) : '';
		var v = { state: state, intro: '', site: '', checked: [], lastSeen: '', code: code, trustPlain: state !== 'unexplained' };
		var when = started ? ' on ' + started : '';
		var by = (update && update.started_by) ? ' by ' + update.started_by : '';
		if (state === 'explained') {
			v.title = 'Your mail relay was updated';
			v.site = 'This site says your mail relay was rebuilt or upgraded' + (done ? ' on ' + done : when) + by + ', which gives it a new key.';
		} else if (state === 'finishing') {
			v.title = 'Your mail relay is being updated';
			v.site = 'This site says your mail relay is being rebuilt or upgraded (started' + when + by + '), which gives it a new key.';
		} else if (state === 'unfinished') {
			v.title = 'Your mail relay update did not finish';
			v.site = 'This site says an update of your mail relay was started' + when + by + ' and did not finish.';
		} else {
			v.title = 'Your mail relay changed without an update';
			v.intro = RELAY_INTRO;
			v.warning = 'A different relay is answering, and this site has no record of an update. '
				+ 'This can mean that someone who controls this site is trying to read your mail.';
			if (lastSeen) v.lastSeen = 'This device last confirmed your old relay on ' + dateText(lastSeen) + '.';
		}
		if (note && named) v.checked.push('You started this update from this browser.');
		v.checked.push('The new relay passed this device\'s checks.');
		// Who to ask is never taken from the site's record (reviewer2 Q4).
		v.ask = state === 'unexplained'
			? 'Contact your site admin before you trust it. Your existing mail is safe; this is about new mail.'
			: 'If you know about this update, trust the new relay. If you do not, contact your site admin before you trust it.';
		return v;
	}

	/**
	 * A new relay that signed this check and seals to this key: one popup per
	 * new relay, for every mailbox it covers (R2). Only an approvable
	 * `identity` verdict is ever collected.
	 */
	function collectChange(changes, box, verdict, update) {
		if (verdict.ok || verdict.reason !== 'identity' || !verdict.approvable) return false;
		(changes[verdict.identity] = changes[verdict.identity] || []).push({ box: box, verdict: verdict, update: update });
		return true;
	}

	/**
	 * The popup's press: a pin for every mailbox under one confirmation. The
	 * first refused save asks for the step-up, and the rest ride on it; then
	 * this browser's records, and the note the trust used up.
	 */
	function trustAll(items, identity, update, session, env) {
		env = env || RELAY_ENV;
		var pinAll = function () {
			var p = Promise.resolve();
			items.forEach(function (it) { p = p.then(function () { return savePin(session, it.box, identity, env); }); });
			return p;
		};
		return pinAll().catch(function (e) {
			if (!(e && e.data && e.data.requires_stepup)) throw e;
			return env.stepUp().then(pinAll);
		}).then(function () {
			items.forEach(function (it) { rememberPin(it.box.alias_id, identity, env); rememberPinTime(it.box.alias_id, env); });
			forgetUpdateNote(update, env);
		});
	}

	/**
	 * One new relay, every mailbox of this owner it covers (R2): the popup,
	 * and one press that trusts them all after one confirmation. Only
	 * verdicts that are `identity` and approvable come here: the new relay
	 * signed this check's challenge and names this vault's key.
	 */
	async function relayChangePopup(items, session) {
		if (typeof document === 'undefined' || !items.length) return;
		var identity = items[0].verdict.identity;
		var update = items[0].update;
		var note = updateNote(update);
		var lastSeen = null;
		items.forEach(function (it) { var t = pinTime(it.box.alias_id); if (t && (!lastSeen || t > lastSeen)) lastSeen = t; });
		var v = relayChangeView(identity, update, note, lastSeen, await fingerprint(identity));

		var dlg = dialogShell('identity');
		dlg.setAttribute('data-relay-change', v.state);
		dlg.appendChild(el('h3', v.title));
		if (v.intro) dlg.appendChild(el('p', v.intro));
		if (v.site) dlg.appendChild(el('p', 'ⓘ ' + v.site, 'mbx-relay-site-says'));
		if (v.warning) dlg.appendChild(el('p', v.warning));
		var ul = el('ul');
		ul.style.listStyle = 'none';
		ul.style.paddingLeft = '0';
		v.checked.forEach(function (line) { ul.appendChild(el('li', '✓ ' + line)); });
		dlg.appendChild(ul);
		if (v.lastSeen) dlg.appendChild(el('p', v.lastSeen, 'small'));
		dlg.appendChild(el('p', v.ask));
		var full = el('p', 'New relay key: ' + v.code, 'small');
		full.style.fontFamily = 'monospace';
		dlg.appendChild(full);
		dlg.appendChild(el('p', 'Mailboxes: ' + items.map(function (it) { return it.box.address; }).join(', ')));
		var msg = el('p', '', 'mbx-relay-alarm-status');
		var trust = el('button', v.trustPlain ? 'Trust the new relay' : 'Trust it anyway', 'btn btn-primary');
		trust.type = 'button';
		trust.addEventListener('click', function () {
			trust.disabled = true;
			msg.textContent = 'Confirm it is you to trust the new relay…';
			trustAll(items, identity, update, session).then(function () {
				dlg.close();
				dlg.remove();
			}, function (e) {
				trust.disabled = false;
				msg.textContent = 'The new relay was not trusted: ' + ((e && e.message) || 'the confirmation did not finish') + '.';
			});
		});
		var buttons = el('div');
		buttons.style.display = 'flex';
		buttons.style.gap = '.5rem';
		buttons.style.flexWrap = 'wrap';
		if (v.trustPlain) {
			buttons.appendChild(trust);
			buttons.appendChild(closeButton(dlg, 'Not now'));
		} else {
			buttons.appendChild(closeButton(dlg));
			var det = el('details');
			det.appendChild(el('summary', 'I have checked with my admin'));
			det.appendChild(trust);
			buttons.appendChild(det);
		}
		dlg.appendChild(msg);
		dlg.appendChild(buttons);
		showDialog(dlg);
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
		// Relay-sealed mail waits for an open vault; open is when it is parsed.
		document.addEventListener('joinery:vault-scope-unlocked', function (e) {
			if (e && e.detail && e.detail.scope === SCOPE) {
				drainPending().catch(function () {}).then(function () { return drainRuleBacklog(); }).catch(function () {});
				checkRelayPins().catch(function () {});
			}
		});
		ready().then(function () {
			if (!isOpen()) return;
			drainPending().catch(function () {}).then(function () { return drainRuleBacklog(); }).catch(function () {});
			checkRelayPins().catch(function () {});
		});
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
		await selfCheckPin(note);
		if (window.MailboxFilterMatch) {
			await selfCheckRules(note);
		}
		// A page holds the vault client; the node gates that load this file do not.
		if (window.JoinerySealed) {
			note('subscribed to the mail vault\'s lock, whatever order the scripts loaded in', hooked);
		}
		return { ok: checks.every(function (c) { return c.ok; }), checks: checks };
	}

	/**
	 * The parse's rule glue: the parsed message reaches the matcher with the
	 * row's recipient and size, the matching ids come back in the rules' order,
	 * and a matched forwarding rule asks for the raw.
	 */
	async function selfCheckRules(note) {
		var saved = ruleCache;
		try {
			ruleCache = { 99: Promise.resolve([
				{ id: 5, match: { subject: 'invoice' }, forwards: false },
				{ id: 6, match: { to: 'box@', size_op: 'gt', size_bytes: 100 }, forwards: true },
				{ id: 7, match: { from: 'nobody@' }, forwards: true }
			]) };
			var p = { from: 'Billing <billing@example.com>', subject: 'Your Invoice', textPlain: 'Pay.', textHtml: '',
				attachments: [] };
			var m = await ruleMatches({ alias_id: 99, recipient: 'box@example.com', size_bytes: 500 }, p, 0);
			note('the parse names the rules it matched, in order, and asks for the raw for a forward',
				m.ids.join(',') === '5,6' && m.forwards === true);
			var none = await ruleMatches({ alias_id: 99, recipient: 'box@example.com', size_bytes: 50 },
				{ from: 'x@y', subject: 'hello', textPlain: '', textHtml: '', attachments: [] }, 0);
			note('and nothing when nothing matched', none.ids.length === 0 && none.forwards === false);
		} catch (e) {
			note('the parse names the rules it matched, in order, and asks for the raw for a forward', false);
		} finally {
			ruleCache = saved;
		}
	}

	/**
	 * The relay pin against the shared vector
	 * (plugins/mailbox/tests/fixtures/relay_pin_vector.json, made by PHP): the
	 * MAC matches the server's formula, a signed statement carrying the
	 * challenge sent passes, one carrying none or another check's is refused,
	 * a changed key or signature fails, a pin this vault did not make is
	 * refused.
	 */
	async function selfCheckPin(note) {
		if (typeof VaultCrypto === 'undefined' || !VaultCrypto.macFromSecret || !(await VaultCrypto.ed25519Supported())) return;
		var v = {
			secretHex: '1a94f30594ae6bf54e06ea46c983aa626582a81dea924d01b69a67d0e0089cd4',
			aliasId: 42,
			identity: 'uzn2WYR3e1WyY2Kcl1yJATPGrSeOLN9pfAim/U/NObM=',
			mac: 'gPKbTONVl2T3ZmbEUFf+kh1c5OlSjTiWXw9SbDEaiaE=',
			statement: '{"recipient":"box@fortress.test","public_key":"YO6P+WWilqSUxSipdAY4nfvIGw9u4mFN3hBKas4ZBVY=",'
				+ '"key_kind":"client","key_scope":"mail","key_generation":1,"map_version":7,'
				+ '"relay_identity_public_key":"uzn2WYR3e1WyY2Kcl1yJATPGrSeOLN9pfAim/U/NObM=","signed_at":"2026-09-28T12:00:00Z"}',
			signature: '8pmxylHrOIw/ZDVwm3ab5K+J1TtwprMXYv6074UCUT5uU79N20dOWiglY7B125GuW5DZ2EZOZ1IN87GxcxvvBw=='
		};
		try {
			// The secret as the keyring holds it (PKCS#8), which is what session.mac() derives from.
			var secret = new Uint8Array(('302e020100300506032b656e04220420' + v.secretHex).match(/../g).map(function (h) { return parseInt(h, 16); }));
			var mac = function (b) { return VaultCrypto.macFromSecret(secret, 'sealed-vault:pin', b); };
			note('the pin MAC matches the server\'s vector',
				VaultCrypto.b64encode(await mac(pinMessage(v.aliasId, v.identity))) === v.mac);
			var box = { alias_id: v.aliasId, address: 'box@fortress.test' };
			var keys = ['YO6P+WWilqSUxSipdAY4nfvIGw9u4mFN3hBKas4ZBVY='];
			var old = { relay_answer: JSON.stringify({ statement: v.statement, signature: v.signature }),
				relay_identity_public_key: v.identity, pin: { relay_identity_public_key: v.identity, mac: v.mac } };
			var unchallenged = await judgeSealTarget(old, box, mac, keys, { challenge: 'q3Jx0mVb_k2n-Lp8ZtR4wA' });
			note('a signed statement carrying no challenge is refused, however recent (B4)',
				!unchallenged.ok && unchallenged.reason === 'replayed');

			// The relay signs the browser's challenge (the vector's challenge_vector).
			var cv = {
				identity: 'beLRmH6k7NuV/nZUn/k7bG0xw022H71XCBnqdgjArS4=',
				challenge: 'q3Jx0mVb_k2n-Lp8ZtR4wA',
				statement: '{"recipient":"box@fortress.test","public_key":"YO6P+WWilqSUxSipdAY4nfvIGw9u4mFN3hBKas4ZBVY=",'
					+ '"key_kind":"client","key_scope":"mail","key_generation":1,"map_version":8,'
					+ '"relay_identity_public_key":"beLRmH6k7NuV/nZUn/k7bG0xw022H71XCBnqdgjArS4=","signed_at":"2026-11-15T12:00:00Z",'
					+ '"challenge":"q3Jx0mVb_k2n-Lp8ZtR4wA"}',
				signature: 'VLhweEzu8PYtTjy43M6kPwd/5c1LS6OShMxkTgHlP402WCbDSwu4OdzopOyFc2IP+NNRPR/Ix48j71Q67MtfCg=='
			};
			var sent = { challenge: cv.challenge };
			var answer = { relay_answer: JSON.stringify({ statement: cv.statement, signature: cv.signature }),
				relay_identity_public_key: cv.identity, pin: { relay_identity_public_key: cv.identity,
					mac: VaultCrypto.b64encode(await mac(pinMessage(v.aliasId, cv.identity))) } };
			var ok = await judgeSealTarget(answer, box, mac, keys, sent);
			note('a statement carrying the challenge sent, under the pinned relay, passes', ok.ok && !ok.firstUse);
			var first = await judgeSealTarget(Object.assign({}, answer, { pin: null }), box, mac, keys, sent);
			note('with no pin, it passes as a first use', first.ok && first.firstUse);
			var other = await judgeSealTarget(answer, box, mac, ['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='], sent);
			note('a statement naming another key fails', !other.ok && other.reason === 'key');
			var bad = await judgeSealTarget(Object.assign({}, answer, { relay_answer: JSON.stringify({
				statement: cv.statement.replace('"map_version":8', '"map_version":9'), signature: cv.signature }) }), box, mac, keys, sent);
			note('a changed statement fails the signature', !bad.ok && bad.reason === 'signature');
			var forged = await judgeSealTarget(Object.assign({}, answer, { pin: { relay_identity_public_key: cv.identity,
				mac: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' } }), box, mac, keys, sent);
			note('a pin this vault did not make is refused', !forged.ok && forged.reason === 'pin');
			var otherCheck = await judgeSealTarget(answer, box, mac, keys, { challenge: 'zzzzzzzzzzzzzzzzzzzzzz' });
			note('an answer carrying another check\'s challenge is refused', !otherCheck.ok && otherCheck.reason === 'replayed');
			var unasked = await judgeSealTarget(answer, box, mac, keys, {});
			note('and so is one carrying a challenge this check never sent', !unasked.ok && unasked.reason === 'replayed');
			// A pin this vault made for some other relay: the answering relay is a new one.
			var elsewhere = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
			var elsewhereMac = VaultCrypto.b64encode(await mac(pinMessage(v.aliasId, elsewhere)));
			var moveTo = function (a) {
				return Object.assign({}, a, { pin: { relay_identity_public_key: elsewhere, mac: elsewhereMac } });
			};
			var moved = await judgeSealTarget(moveTo(answer), box, mac, keys, sent);
			note('a statement from a new relay carrying the challenge may be approved',
				!moved.ok && moved.reason === 'identity' && moved.approvable);
			var movedOld = await judgeSealTarget(moveTo(answer), box, mac, keys, { challenge: 'zzzzzzzzzzzzzzzzzzzzzz' });
			note('one signed for another check may not', !movedOld.ok && movedOld.reason === 'identity' && !movedOld.approvable);
			var movedBare = await judgeSealTarget(moveTo(old), box, mac, keys, sent);
			note('nor one carrying no challenge (B4-1)', !movedBare.ok && movedBare.reason === 'identity' && !movedBare.approvable);
			var two = function () { return Promise.resolve(new Uint8Array(32)); };
			var either = await judgeSealTarget(answer, box, [two, mac], keys, sent);
			note('a pin re-made under the rotation\'s new key still passes (B48)', either.ok);
			var neither = await judgeSealTarget(answer, box, [two], keys, sent);
			note('and one neither key made does not', !neither.ok && neither.reason === 'pin');
			// The relay-change popup's words (R2): the site's account picks them,
			// and never earns a tick.
			var nid = cv.identity, other2 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
			var run = function (status, idk) { return { relay_id: 4, run_id: 9, kind: 'upgrade', status: status,
				started_by: 'Pat Admin', started_time: '2026-11-15T11:52:00Z', done_time: status === 'done' ? '2026-11-15T12:07:00Z' : null,
				identity_public_key: idk || nid }; };
			var noTick = function (view) { return view.checked.every(function (l) { return l.indexOf('This site says') === -1 && l.indexOf('Pat Admin') === -1; }); };
			var vDone = relayChangeView(nid, run('done'), null, null, '6fd2 c2b8');
			note('a done run that names the new identity gives the explained popup, with a plain Trust',
				vDone.state === 'explained' && vDone.trustPlain && vDone.site.indexOf('This site says') === 0 && vDone.site.indexOf('by Pat Admin') !== -1);
			note('the site\'s account is never a tick, and the popup says to ask the admin when the update is news',
				noTick(vDone) && vDone.ask.indexOf('If you do not, contact your site admin') !== -1 && vDone.code === '6fd2 c2b8');
			note('a run still going, or failed, gives the finishing words without the accusation',
				relayChangeView(nid, run('booting'), null, null, 'x').state === 'finishing'
				&& relayChangeView(nid, run('failed'), null, null, 'x').state === 'unfinished'
				&& relayChangeView(nid, run('failed'), null, null, 'x').trustPlain);
			var vNone = relayChangeView(nid, null, null, null, 'x'), vOther = relayChangeView(nid, run('done', other2), null, null, 'x');
			note('no run, or a run naming another identity, is unexplained, with no plain Trust',
				vNone.state === 'unexplained' && !vNone.trustPlain && vOther.state === 'unexplained' && !vOther.trustPlain
				&& vNone.warning.indexOf('no record') !== -1 && vNone.ask.indexOf('before you trust it') !== -1);
			note('the last good check is information only: it never changes the state',
				relayChangeView(nid, run('done'), null, Date.parse('2026-11-15T12:30:00Z'), 'x').state === 'explained'
				&& relayChangeView(nid, null, null, Date.parse('2026-11-14T09:00:00Z'), 'x').state === 'unexplained');
			var started = { run_id: 9, at: Date.parse('2026-11-15T11:52:00Z') };
			note('this browser\'s note adds its own tick, and only when the site\'s account names the identity',
				relayChangeView(nid, run('done'), started, null, 'x').checked.length === 2
				&& relayChangeView(nid, null, started, null, 'x').checked.length === 1);

			// The mail page's path (checkOne -> collectChange -> trustAll), with
			// this check's own store, API and step-up, so nothing real is touched.
			var mem = {}, store = { getItem: function (k) { return Object.prototype.hasOwnProperty.call(mem, k) ? mem[k] : null; },
				setItem: function (k, val) { mem[k] = String(val); }, removeItem: function (k) { delete mem[k]; } };
			var stepped = 0, pinPosts = [];
			var upd = run('done');
			var movedFor = async function (aliasId, pinned) {
				return { relay_answer: JSON.stringify({ statement: cv.statement, signature: cv.signature }),
					relay_identity_public_key: cv.identity, update: upd,
					pin: pinned ? { relay_identity_public_key: other2, mac: VaultCrypto.b64encode(await mac(pinMessage(aliasId, other2))) } : null };
			};
			var answers = { 42: await movedFor(42, true), 43: await movedFor(43, true), 44: await movedFor(44, false) };
			var env = {
				store: function () { return store; },
				challenge: function () { return cv.challenge; },
				stepUp: function () { stepped++; return Promise.resolve(); },
				post: function (action, data) {
					if (action === 'mailbox/relay_seal_target') return Promise.resolve(answers[data.alias_id]);
					if (action === 'mailbox/relay_pin_set') {
						if (!stepped) { var err = new Error('step-up'); err.data = { requires_stepup: true }; return Promise.reject(err); }
						pinPosts.push(data);
						return Promise.resolve({ pinned: true });
					}
					return Promise.reject(new Error('unexpected ' + action));
				}
			};
			try {
				var acc = { macs: [mac], keys: keys };
				var changes = {};
				var bx = function (id) { return { alias_id: id, address: 'box@fortress.test' }; };
				rememberPin(44, other2, env);   // pinned here before; the server lost its pin
				await checkOne(bx(42), null, acc, changes, env);
				await checkOne(bx(43), null, acc, changes, env);
				await checkOne(bx(44), null, acc, changes, env);
				var batch = changes[nid] || [];
				note('two mailboxes moved to one new relay are one popup', batch.length === 3 && Object.keys(changes).length === 1);
				note('a mailbox whose server pin is gone is judged by this browser\'s own pin, not as a first use',
					batch.some(function (it) { return it.box.alias_id === 44 && it.verdict.reason === 'identity' && !it.verdict.firstUse; }));
				var oldStatement = await judgeSealTarget({ relay_answer: JSON.stringify({ statement: v.statement, signature: v.signature }),
					relay_identity_public_key: v.identity, pin: { relay_identity_public_key: other2,
						mac: VaultCrypto.b64encode(await mac(pinMessage(45, other2))) } }, bx(45), mac, keys, { challenge: cv.challenge });
				var none2 = {};
				note('a new relay that did not pass the check is never batched',
					oldStatement.reason === 'identity' && !oldStatement.approvable && !collectChange(none2, bx(45), oldStatement, upd)
					&& Object.keys(none2).length === 0);
				var two = {};
				collectChange(two, bx(1), { ok: false, reason: 'identity', approvable: true, identity: 'X' }, null);
				collectChange(two, bx(2), { ok: false, reason: 'identity', approvable: true, identity: 'Y' }, null);
				note('two new relays are two popups', Object.keys(two).length === 2);

				var now = Date.now();
				store.setItem('joinery-relay-update:4', JSON.stringify({ run_id: 9, at: now - 3600 * 1000 }));
				note('this browser\'s note counts for its own relay and run, inside a day',
					!!updateNote(upd, now, env) && !updateNote(Object.assign({}, upd, { relay_id: 5 }), now, env)
					&& !updateNote(Object.assign({}, upd, { run_id: 10 }), now, env) && !updateNote(upd, now + 24 * 3600 * 1000, env));
				await trustAll(batch, nid, upd, { mac: function (b) { return mac(b); } }, env);
				note('one press pins every mailbox after one step-up', stepped === 1 && pinPosts.length === 3
					&& pinPosts.every(function (d) { return d.relay_identity_public_key === nid; }));
				note('and the trust uses up the note, and this browser records the new relay',
					store.getItem('joinery-relay-update:4') === null && localPin(42, env) === nid && localPin(44, env) === nid
					&& !!pinTime(43, env));
			} catch (e) {
				note('the mail page\'s relay path runs', false);
			}

			var pair = await VaultCrypto.generateVaultKeypair();
			note('a vault\'s public key is worked out from its own secret (B47)',
				(await VaultCrypto.publicKeyFromSecret(pair.secretKeyBytes)) === pair.publicKeyB64);
			pair.secretKeyBytes.fill(0);
			secret.fill(0);
		} catch (e) {
			note('the relay pin checks run', false);
		}
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
				sealed: { key: 42, sealed_scope: 'mail', sealed_dek: 'v2.edgeseal.mail.stub', sealed_ad_prefix: 'mail:' } };
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
		drainPending: drainPending,
		showPending: showPending,
		drainRuleBacklog: drainRuleBacklog,
		checkRelayPins: checkRelayPins,
		judgeEntry: judgeEntry,
		epoch: epoch,
		selfCheck: selfCheck
	};
})();
