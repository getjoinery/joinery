/**
 * "Your AI, your model" — the AI panel section where a member names their own
 * AI model for end-to-end encrypted (Fortress) mail and tests it
 * (specs/fortress_mail_device_ai.md § R2, R7).
 *
 * The browser opens Fortress mail and sends it to a model the person names.
 * Three parts say where and how:
 *   - the model's origin (https://host[:port]), registered on the Email
 *     settings page under a second-factor confirmation. The server names it
 *     in this page's CSP connect-src, so the page can reach that host and no
 *     other; it arrives here as MAILBOX_DEVICE_AI.origin;
 *   - the rest of the address (e.g. /inference/v1), the key and the model
 *     name, kept in THIS browser (localStorage, one entry per account). The
 *     origin they were saved against rides with them: a saved path, key and
 *     model for another origin are not sent to this one.
 *
 * Test sends what a real judgement sends — the security scan's system prompt
 * and a made-up sample digest (mailbox/device_ai_test_prompt) — to
 * {origin}{path}/chat/completions, the OpenAI-compatible contract, and says
 * which gate stopped it: this browser (Chrome's Local Network Access, read
 * with navigator.permissions.query), the model refusing this site, the key,
 * the model name, or a context too small (the model's own words).
 *
 * The page contract: window.MAILBOX_DEVICE_AI = {origin, user_id,
 * settings_url}; MailboxDeviceAi.section() returns the element the AI panel
 * docks (JoineryAiPanel.mount hostSection). The decisions — the section's
 * state, the call address, what a Test outcome means — are plain functions on
 * MailboxDeviceAi.logic, loaded without a page by the suite
 * (plugins/mailbox/tests/device_ai_panel_test.php).
 *
 * The drain (R6): while the mailbox is open, its vault unlocked and the tab in
 * view, one tab at a time (navigator.locks 'jy-device-ai-drain') judges each
 * device recipe's queue through MailboxFortress.judgeEntry(), at most 200 a
 * page load, and says in the section where it stands. It stops, with the
 * model's own words, when the model does not answer usefully.
 *
 * On demand (R6): messageActions(m) is the bar under an opened Fortress
 * message — Summarize, Scan now — running the same one-item path.
 *
 * The site's own model (R7): when the site's local provider sits on a private
 * or tailnet host, MAILBOX_DEVICE_AI.site_model names it and the section
 * offers it with one click — registerOrigin() (a passkey confirmation, the
 * same as the settings page) and the model saved here — with what the
 * operator can see said plainly to a member.
 *
 * Ready needs no click (R7): with a model saved here the section shows one
 * line naming it, checks the model on its own once per page load (GET
 * {base}/models, the same gates Test names, which is also when the browser
 * asks to reach the person's network), and only then lets the drain run. The
 * fields, Save and Test sit behind "Change"; "Test again" runs the full Test.
 *
 * Once the check says reachable the section is compact: one muted line
 * ("Your model X at host is reachable. Change"), warnings, and the drain's
 * line only while it has something to say. The title, the custody sentence,
 * the fields, Save and Test all sit behind Change.
 *
 * Vanilla JS, jy-ui classes, no framework. @version 1.8 - compact once reachable
 * @version 1.7 - the ready state is one line and an automatic check; the fields and buttons open on Change
 * @version 1.6 - Test sends the reasoning control
 * @version 1.5 - the site's own model offered; registerOrigin() shared with the settings page
 * @version 1.4 - links the person-facing page "Using your own model"
 * @version 1.3 - on demand: Summarize and Scan now
 * @version 1.2 - the drain; the recipes, consent and
 *   model-tier lines
 * @version 1.1 - a "no such model" answer is
 *   checked against the model list, which tells a bad key apart
 */
(function () {
	'use strict';

	var STORE_PREFIX = 'jy_device_ai:';
	var DOCS_URL = '/documentation?doc=plugin/mailbox/using_your_own_model';
	var TEST_TIMEOUT_MS = 120000;

	// ---- the decisions (no DOM) ----------------------------------------------

	/** True for this computer or an address on the person's own network —
	 *  where Chrome asks before a public page may call. */
	function isLocalHost(host) {
		host = String(host || '').toLowerCase().replace(/^\[|\]$/g, '');
		if (host === 'localhost' || host === '::1') return true;
		if (/\.(local|lan|internal|home\.arpa|ts\.net)$/.test(host)) return true;
		var m = host.match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/);
		if (m) {
			var a = +m[1], b = +m[2];
			return a === 127 || a === 10 || (a === 172 && b >= 16 && b <= 31)
				|| (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127);
		}
		return /^f[cd][0-9a-f]{2}:/.test(host);
	}

	/** The rest of the address as typed, tidied: one leading slash, no
	 *  trailing one, nothing that could leave the origin. '' is allowed. */
	function normalizePath(path) {
		path = String(path || '').trim();
		if (path === '' || path === '/') return '';
		if (/^[a-z][a-z0-9+.-]*:/i.test(path) || path.indexOf('//') === 0) return null;
		if (/[?#\s\\]|\.\./.test(path)) return null;
		if (path.charAt(0) !== '/') path = '/' + path;
		return path.replace(/\/+$/, '');
	}

	/** The chat URL for the registered origin and the saved path, or null when
	 *  the two do not make an address on that origin. */
	function callUrl(origin, path) {
		var p = normalizePath(path);
		if (!origin || p === null) return null;
		try {
			var u = new URL(origin + p + '/chat/completions');
			return u.origin === origin ? u.href : null;
		} catch (e) {
			return null;
		}
	}

	/**
	 * What the section shows. cfg: {origin, fortress}; saved: what this browser
	 * keeps ({origin, path, key, model} or null).
	 *   hidden      — the open mailbox is not end-to-end encrypted
	 *   no_origin   — nowhere registered yet: link to the settings page
	 *   needs_model — registered, but this browser has no model name for it
	 *   ready       — enough to Test
	 * `usable` is the saved entry when it belongs to the registered origin.
	 */
	/**
	 * The site's own model, where this browser could take it: 'register' when
	 * no address is registered yet (one click registers the site's), 'prefill'
	 * when the registered address is the site's and no model is saved here
	 * (the fields start filled), null otherwise — another address is
	 * registered, and the settings page is where that changes.
	 */
	function siteOffer(cfg, saved) {
		var site = cfg && cfg.site_model;
		if (!site || !site.origin || !site.model) return null;
		if (!cfg.origin) return { kind: 'register', site: site };
		if (cfg.origin !== site.origin) return null;
		var usable = (saved && saved.origin === cfg.origin) ? saved : null;
		if (!usable || !String(usable.model || '').trim()) return { kind: 'prefill', site: site };
		return null;
	}

	function sectionState(cfg, saved) {
		if (!cfg || !cfg.fortress) return { state: 'hidden', usable: null };
		if (!cfg.origin) return { state: 'no_origin', usable: null };
		var usable = (saved && saved.origin === cfg.origin) ? saved : null;
		if (!usable || !String(usable.model || '').trim()) return { state: 'needs_model', usable: usable };
		return { state: 'ready', usable: usable };
	}

	/**
	 * One Test outcome as {kind, text}. r: {status, body} for an answer the
	 * page could read, or {network: true, lna: 'granted'|'prompt'|'denied'|null}
	 * when the fetch itself failed; local: whether the host is this computer or
	 * the person's network; model: the name asked for.
	 *
	 * r.keyCheck ({status, body}) is the model list asked with the same key,
	 * fetched after a "no such model" answer: some services (Fireworks) answer
	 * a bad key on the chat address as a missing model, and only the list says
	 * which it is.
	 */
	function classify(r, local, model) {
		if (r.timeout) {
			return { kind: 'unreachable', text: 'No answer after two minutes. Is your model running?' };
		}
		if (r.network) {
			if (local && r.lna === 'prompt') {
				return { kind: 'browser_asks', text: 'Your browser asks first. Press Test again and choose Allow when it asks about devices on your network.' };
			}
			if (local && r.lna === 'denied') {
				return { kind: 'browser_blocked', text: 'Your browser blocked this site from reaching your computer or network. Allow it in the site settings (the icon left of the address bar), then Test again.' };
			}
			if (local) {
				return { kind: 'model_refused', text: 'Your model refused this site, or is not running. It must allow this site; see the setup notes.' };
			}
			return { kind: 'unreachable', text: 'Could not reach your model. Check the address, and that the service allows calls from a web page.' };
		}
		var body = r.body || {};
		var err = body.error;
		var msg = err ? (typeof err === 'string' ? err : (err.message || JSON.stringify(err))) : '';
		if (r.status === 401 || r.status === 403) {
			return { kind: 'wrong_key', text: 'Your model says the key is wrong' + (msg ? ': ' + msg : '.') };
		}
		if (r.status >= 400 && /context|too long|too many tokens|maximum.*tokens|exceed/i.test(msg)) {
			return { kind: 'context_small', text: 'Your model\'s context is too small for a message: ' + msg };
		}
		if (r.status === 404 && /model/i.test(msg)) {
			var kc = r.keyCheck;
			if (kc && (kc.status === 401 || kc.status === 403)) {
				var kerr = kc.body && kc.body.error;
				var kmsg = kerr ? (typeof kerr === 'string' ? kerr : (kerr.message || '')) : '';
				return { kind: 'wrong_key', text: 'Your model says the key is wrong' + (kmsg ? ': ' + kmsg : '.') };
			}
			return { kind: 'model_missing', text: 'Your model says it has no model named "' + model + '": ' + msg };
		}
		if (r.status < 200 || r.status >= 300) {
			return { kind: 'error', text: 'Your model answered with an error (' + r.status + ')' + (msg ? ': ' + msg : '.') };
		}
		var choice = (body.choices || [])[0];
		var content = choice && choice.message ? String(choice.message.content || '') : '';
		if (!content) {
			if (choice && choice.finish_reason === 'length') {
				return { kind: 'error', text: 'Your model spent its whole answer reasoning and never answered, '
					+ 'even when asked not to reason. Choose a model that can answer directly.' };
			}
			return { kind: 'error', text: 'Your model answered, but with nothing in it.' };
		}
		var json = false;
		try { json = typeof JSON.parse(content.replace(/<think>[\s\S]*?<\/think>/g, '').trim()) === 'object'; } catch (e) { json = false; }
		var tokens = body.usage && body.usage.prompt_tokens ? ' The test message was ' + body.usage.prompt_tokens + ' tokens.' : '';
		return {
			kind: 'reachable',
			text: 'Reachable: ' + (body.model || model) + ' answered.' + tokens
				+ (json ? '' : ' Its answer was not the JSON asked for, so it may not suit the security scan.'),
		};
	}

	var TIER_RANK = { basic: 0, standard: 1, capable: 2, frontier: 3 };

	/** fnmatch($glob, $name, FNM_CASEFOLD) for the reference list's globs. */
	function globMatch(glob, name) {
		var re = '^' + String(glob).replace(/[.+^${}()|\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$';
		try { return new RegExp(re, 'i').test(String(name)); } catch (e) { return false; }
	}

	/** AiEndpointRegistry::paramsFromTag(): billions announced by a tag, or null. */
	function paramsFromTag(id) {
		id = String(id).toLowerCase();
		var best = null, m;
		var moe = /(?<![a-z0-9.])(\d+)x(\d+(?:\.\d+)?)\s*b(?![a-z0-9])/g;
		while ((m = moe.exec(id)) !== null) { var v = parseFloat(m[1]) * parseFloat(m[2]); if (best === null || v > best) best = v; }
		var one = /(?<![a-z0-9.])(\d+(?:\.\d+)?)\s*b(?![a-z0-9])/g;
		while ((m = one.exec(id)) !== null) { var w = parseFloat(m[1]); if (best === null || w > best) best = w; }
		return best;
	}

	/** The tier the platform grades a model name at: the reference list's
	 *  first matching glob, else the size its tag announces, else basic. */
	function gradeModel(name, reference) {
		var models = (reference && reference.models) || [];
		for (var i = 0; i < models.length; i++) {
			if (models[i].match && globMatch(models[i].match, name)) return models[i].tier;
		}
		var params = paramsFromTag(name);
		if (params === null) return 'basic';
		var ladder = (reference && reference.ladder) || [];
		for (var j = 0; j < ladder.length; j++) {
			var max = ladder[j].max_params_b;
			if (max === null || max === undefined || params <= max) return ladder[j].tier;
		}
		return 'basic';
	}

	/** An opened thread message in the shape a queue entry has, for judgeEntry(). */
	function entryFromMessage(m) {
		return {
			id: m.id,
			received_time: m.received_time || '',
			dkim_result: m.dkim_result || '',
			spf_result: m.spf_result || '',
			dmarc_result: m.dmarc_result || '',
			auth_source: m.auth_source || '',
			recipient: m.recipient || '',
			sealed: m.sealed
		};
	}

	/** The on-demand button's label for a recipe's job. */
	function actionLabel(jobId) {
		return jobId === 'email_security_scan' ? 'Scan now' : 'Summarize';
	}

	/** A warning when the model grades below what a recipe asks for, or ''. */
	function floorWarning(model, reference, recipes) {
		if (!model || !recipes || !recipes.length) return '';
		var tier = gradeModel(model, reference);
		var need = null;
		recipes.forEach(function (r) {
			if (r.min_tier && (need === null || (TIER_RANK[r.min_tier] || 0) > (TIER_RANK[need.min_tier] || 0))) need = r;
		});
		if (!need || (TIER_RANK[tier] || 0) >= (TIER_RANK[need.min_tier] || 0)) return '';
		return 'The site grades ' + model + ' as ' + tier + ', and "' + need.label + '" asks for ' + need.min_tier
			+ '. Its verdicts may be unreliable.';
	}

	/**
	 * The automatic check's outcome, as {kind, text}: r is {status, body} for
	 * an answer to GET {base}/models, or {network: true, lna} when the fetch
	 * itself failed. A list answered means the model can be reached and the
	 * key is taken; anything else is what Test would say.
	 */
	function classifyProbe(r, local, model, host) {
		if (r.network) return classify(r, local, model);
		if (r.status === 401 || r.status === 403) return classify(r, local, model);
		if (r.status >= 200 && r.status < 300) {
			return { kind: 'ok', text: 'Reachable: ' + model + ' at ' + host + '.' };
		}
		return { kind: 'error', text: 'Your model answered with an error (' + r.status + '). Press Test again for the details.' };
	}

	// ---- this browser's settings ---------------------------------------------

	function storeKey() {
		var cfg = window.MAILBOX_DEVICE_AI || {};
		return STORE_PREFIX + String(cfg.user_id || 0);
	}
	function loadSaved() {
		try {
			var raw = window.localStorage.getItem(storeKey());
			var v = raw ? JSON.parse(raw) : null;
			return v && typeof v === 'object' ? v : null;
		} catch (e) {
			return null;
		}
	}
	function save(entry) {
		try {
			window.localStorage.setItem(storeKey(), JSON.stringify(entry));
			return true;
		} catch (e) {
			return false;
		}
	}

	// ---- registering an origin (shared with the settings page) ---------------

	function stepUp() {
		if (!window.JoineryPasskeys || !window.PublicKeyCredential) {
			return Promise.reject(new Error('no passkey here'));
		}
		return window.joineryApi.post('passkey_stepup_options', {}).then(function (opt) {
			if (!opt || !opt.options) throw new Error('Could not start the confirmation.');
			return window.JoineryPasskeys.authenticate(opt.options);
		}).then(function (credential) {
			return window.joineryApi.post('passkey_stepup_verify', { credential: credential });
		});
	}

	/**
	 * Post an origin to mailbox/device_ai_host; confirm with a passkey and post
	 * again when asked to. Without a passkey, or when it is dismissed, the
	 * confirmation page takes an authenticator code too and brings the person
	 * back to returnUrl. Resolves the origin as stored.
	 */
	function registerOrigin(origin, returnUrl) {
		function post() {
			return window.joineryApi.post('mailbox/device_ai_host', { origin: origin }).then(function (res) { return res.origin; });
		}
		return post().catch(function (e) {
			if (!(e && e.data && e.data.requires_stepup)) throw e;
			return stepUp().then(post, function () {
				window.location.href = '/verify-stepup?return=' + encodeURIComponent(returnUrl);
				return new Promise(function () {});
			});
		});
	}

	// ---- the section ---------------------------------------------------------

	function el(tag, className, text) {
		var n = document.createElement(tag);
		if (className) n.className = className;
		if (text != null) n.textContent = text;
		return n;
	}

	function field(labelText, input) {
		var wrap = el('label', 'mbx-dai-field');
		wrap.appendChild(el('span', 'mbx-dai-label', labelText));
		wrap.appendChild(input);
		return wrap;
	}

	/** The one-click offer of the site's own model, with what the operator can see. */
	function siteOfferBlock(site) {
		var wrap = el('div', 'mbx-dai-site');
		var p = el('p', 'mbx-dai-note');
		p.appendChild(document.createTextNode('This site runs its own model, '));
		p.appendChild(el('code', null, site.model));
		p.appendChild(document.createTextNode(' at '));
		p.appendChild(el('code', null, site.host));
		p.appendChild(document.createTextNode('. ' + (site.operator ? 'It runs on hardware you operate.'
			: 'The operator of this site runs that machine and could see mail sent to it.')));
		wrap.appendChild(p);
		var btn = el('button', 'btn btn-secondary btn-sm', 'Use this site\'s model');
		btn.type = 'button';
		var status = el('p', 'mbx-dai-note');
		status.setAttribute('role', 'status');
		btn.addEventListener('click', function () {
			btn.disabled = true;
			status.textContent = 'Saving…';
			registerOrigin(site.origin, window.location.pathname).then(function (origin) {
				save({ origin: origin, path: site.path || '', key: '', model: site.model });
				window.location.reload();
			}).catch(function (e) {
				status.textContent = (e && e.message) || 'Could not save the address.';
				btn.disabled = false;
			});
		});
		wrap.appendChild(btn);
		wrap.appendChild(status);
		return wrap;
	}

	var root = null;

	function render() {
		if (!root) return;
		var cfg = window.MAILBOX_DEVICE_AI || {};
		var fortress = !!(window.MailboxReader && window.MailboxReader.currentIsFortress && window.MailboxReader.currentIsFortress());
		var st = sectionState({ origin: cfg.origin || null, fortress: fortress }, loadSaved());
		root.hidden = st.state === 'hidden';
		root.dataset.state = st.state;
		root.textContent = '';
		if (root.hidden) return;

		var title = el('h3', 'aip-section-title', 'Your AI, your model');
		var note = el('p', 'mbx-dai-note',
			'AI for this mailbox runs in this browser. Your mail is sent from here to the model you name. '
			+ 'Joinery never sees it; whoever runs that model does. ');
		var docs = el('a', null, 'Using your own model');
		docs.href = DOCS_URL;
		docs.target = '_blank';
		docs.rel = 'noopener';
		note.appendChild(docs);
		// Reachable and set: nothing to do here, so nothing to read either.
		var compact = st.state === 'ready' && !!(probe.outcome && probe.outcome.kind === 'ok');
		root.dataset.compact = compact ? '1' : '0';
		if (!compact) {
			root.appendChild(title);
			root.appendChild(note);
		}

		if (st.state === 'no_origin') {
			var p = el('p', 'mbx-dai-note');
			p.appendChild(document.createTextNode('First choose where your model answers. '));
			var a = el('a', null, 'Set it up in Email settings');
			a.href = cfg.settings_url || '/profile/mailbox/settings';
			p.appendChild(a);
			root.appendChild(p);
			var offer = siteOffer({ origin: cfg.origin, site_model: cfg.site_model }, loadSaved());
			if (offer) root.appendChild(siteOfferBlock(offer.site));
			return;
		}

		var usable = st.usable || {};
		var status = el('p', 'mbx-dai-status');
		status.setAttribute('role', 'status');
		var fieldsBox = el('div', 'mbx-dai-fields');
		var hostName = (function () { try { return new URL(cfg.origin).host; } catch (e) { return cfg.origin; } })();
		if (st.state === 'ready') {
			// One line: the model, where, and the links. The rest waits behind Change.
			var line = el('p', 'mbx-dai-note');
			line.appendChild(document.createTextNode(compact ? 'Your model ' : 'Your model: '));
			line.appendChild(el('code', null, usable.model));
			line.appendChild(document.createTextNode(' at '));
			line.appendChild(el('code', null, hostName));
			line.appendChild(document.createTextNode(compact ? ' is reachable. ' : '. '));
			var changeLink = el('button', 'mbx-dai-link', 'Change');
			changeLink.type = 'button';
			changeLink.addEventListener('click', function () { fieldsBox.hidden = !fieldsBox.hidden; });
			line.appendChild(changeLink);
			if (!compact) {
				line.appendChild(document.createTextNode(' · '));
				var testLink = el('button', 'mbx-dai-link', 'Test again');
				testLink.type = 'button';
				testLink.addEventListener('click', function () { testBtn.click(); });
				line.appendChild(testLink);
			}
			root.appendChild(line);
			fieldsBox.hidden = true;
		}
		if (compact) {
			fieldsBox.appendChild(title);
			fieldsBox.appendChild(note);
		}
		root.appendChild(fieldsBox);

		var where = el('p', 'mbx-dai-note');
		where.appendChild(document.createTextNode('Your model answers at '));
		where.appendChild(el('code', null, cfg.origin));
		where.appendChild(document.createTextNode(' '));
		var change = el('a', null, 'Change the address');
		change.href = cfg.settings_url || '/profile/mailbox/settings';
		where.appendChild(change);
		fieldsBox.appendChild(where);

		var path = el('input', 'mbx-dai-input');
		path.type = 'text';
		path.placeholder = '/v1';
		var prefill = siteOffer({ origin: cfg.origin, site_model: cfg.site_model }, loadSaved());
		prefill = prefill && prefill.kind === 'prefill' ? prefill.site : null;
		path.value = usable.path || (prefill ? prefill.path : '');
		path.autocomplete = 'off';
		var key = el('input', 'mbx-dai-input');
		key.type = 'password';
		key.placeholder = 'Leave empty if your model needs none';
		key.value = usable.key || '';
		key.autocomplete = 'off';
		var model = el('input', 'mbx-dai-input');
		model.type = 'text';
		model.placeholder = 'e.g. accounts/fireworks/models/…';
		model.value = usable.model || (prefill ? prefill.model : '');
		model.autocomplete = 'off';
		fieldsBox.appendChild(field('Rest of the address', path));
		fieldsBox.appendChild(field('Key', key));
		fieldsBox.appendChild(field('Model', model));

		var actions = el('div', 'mbx-dai-actions');
		var saveBtn = el('button', 'btn btn-secondary', 'Save in this browser');
		saveBtn.type = 'button';
		var testBtn = el('button', 'btn btn-primary', 'Test');
		testBtn.type = 'button';
		actions.appendChild(saveBtn);
		actions.appendChild(testBtn);
		fieldsBox.appendChild(actions);
		(compact ? fieldsBox : root).appendChild(status);
		root.appendChild(el('div', 'mbx-dai-infos'));
		var drainLine = el('p', 'mbx-dai-status mbx-dai-drain', drain.status);
		drainLine.setAttribute('role', 'status');
		drainLine.hidden = compact && quietDrain(drain.status);
		root.appendChild(drainLine);
		var here = window.MailboxReader ? window.MailboxReader.currentAddress() : '';
		if (here && drain.infoFor !== here) {
			fetchInfo(here).then(renderInfo, function () {});
		} else {
			renderInfo();
		}
		// The check runs by itself, once, before the drain: it is where the
		// browser asks to reach the person's network, and what says why not.
		if (st.state === 'ready') {
			probeOnce({ url: callUrl(cfg.origin, usable.path), key: usable.key || '', model: usable.model })
				.then(function () { tick(); });
		} else {
			setTimeout(tick, 0);
		}

		function collect() {
			var p = normalizePath(path.value);
			if (p === null) {
				status.textContent = 'The rest of the address should look like /v1 or /inference/v1.';
				return null;
			}
			return { origin: cfg.origin, path: p, key: key.value.trim(), model: model.value.trim() };
		}

		saveBtn.addEventListener('click', function () {
			var entry = collect();
			if (!entry) return;
			if (!save(entry)) { status.textContent = 'This browser would not keep it (private window?).'; return; }
			probe.started = false;   // a new entry: check it again
			render();
		});

		testBtn.addEventListener('click', function () {
			var entry = collect();
			if (!entry) return;
			if (!entry.model) {
				status.textContent = 'Enter the model name first.';
				return;
			}
			save(entry);
			testBtn.disabled = true;
			status.textContent = 'Testing…';
			runTest(entry).then(function (out) {
				status.textContent = out.text;
				root.dataset.outcome = out.kind;
			}).catch(function (e) {
				status.textContent = (e && e.message) || 'The test could not start.';
				root.dataset.outcome = 'error';
			}).then(function () { testBtn.disabled = false; });
		});
	}

	function parseBody(t) {
		try { return JSON.parse(t); } catch (e) { return { error: String(t).slice(0, 300) }; }
	}

	function lnaState() {
		if (!navigator.permissions || !navigator.permissions.query) return Promise.resolve(null);
		return navigator.permissions.query({ name: 'local-network-access' })
			.then(function (s) { return s.state; }, function () { return null; });
	}

	function runTest(entry) {
		var url = callUrl(entry.origin, entry.path);
		if (!url) return Promise.reject(new Error('That address does not work with your registered model host.'));
		var host = new URL(url).hostname;
		var local = isLocalHost(host);
		var mailbox = window.MailboxReader ? window.MailboxReader.currentAddress() : '';
		return window.joineryApi.post('mailbox/device_ai_test_prompt', { mailbox: mailbox }).then(function (prompt) {
			var headers = { 'Content-Type': 'application/json' };
			if (entry.key) headers.Authorization = 'Bearer ' + entry.key;
			var ctl = new AbortController();
			var timer = setTimeout(function () { ctl.abort(); }, TEST_TIMEOUT_MS);
			return fetch(url, {
				method: 'POST',
				headers: headers,
				credentials: 'omit',
				signal: ctl.signal,
				body: JSON.stringify({
					model: entry.model,
					messages: [{ role: 'system', content: prompt.system }, { role: 'user', content: prompt.user }],
					max_tokens: prompt.max_tokens,
					reasoning_effort: prompt.reasoning_effort || 'none',
					response_format: { type: 'json_object' },
					stream: false,
				}),
			}).then(function (res) {
				clearTimeout(timer);
				return res.text().then(function (t) {
					var body = parseBody(t);
					var first = classify({ status: res.status, body: body }, local, entry.model);
					if (first.kind !== 'model_missing') return first;
					// Missing model, or a bad key dressed as one: ask the list.
					return fetch(url.replace(/\/chat\/completions$/, '/models'), {
						method: 'GET', headers: entry.key ? { Authorization: 'Bearer ' + entry.key } : {}, credentials: 'omit',
					}).then(function (kr) {
						return kr.text().then(function (kt) {
							return classify({ status: res.status, body: body, keyCheck: { status: kr.status, body: parseBody(kt) } }, local, entry.model);
						});
					}, function () { return first; });
				});
			}, function (e) {
				clearTimeout(timer);
				if (e && e.name === 'AbortError') return classify({ timeout: true }, local, entry.model);
				return lnaState().then(function (s) { return classify({ network: true, lna: s }, local, entry.model); });
			});
		});
	}

	// ---- the automatic check ---------------------------------------------------------

	var probe = { started: false, outcome: null };

	/** The check's line, on whatever render is current (the section re-renders
	 *  on every unlock and mailbox change). */
	function setProbeStatus(text, kind) {
		var n = root && root.querySelector('.mbx-dai-status:not(.mbx-dai-drain)');
		if (n) n.textContent = text;
		if (root && kind) root.dataset.outcome = kind;
	}

	/** Once per page load (or per saved entry): can the model be reached with
	 *  this key? Its outcome is the status line, kept across renders; never
	 *  throws. */
	function probeOnce(endpoint) {
		if (probe.started || !endpoint.url) {
			if (probe.outcome) setProbeStatus(probe.outcome.text, probe.outcome.kind);
			else if (probe.started) setProbeStatus('Checking your model…');
			return Promise.resolve();
		}
		probe.started = true;
		probe.outcome = null;
		var host = new URL(endpoint.url).hostname;
		var local = isLocalHost(host);
		var hostName = new URL(endpoint.url).host;
		setProbeStatus('Checking your model…');
		return fetch(endpoint.url.replace(/\/chat\/completions$/, '/models'), {
			method: 'GET', headers: endpoint.key ? { Authorization: 'Bearer ' + endpoint.key } : {}, credentials: 'omit',
		}).then(function (res) {
			return res.text().then(function (t) { return classifyProbe({ status: res.status, body: parseBody(t) }, local, endpoint.model, hostName); });
		}, function () {
			return lnaState().then(function (s) { return classifyProbe({ network: true, lna: s }, local, endpoint.model, hostName); });
		}).then(function (out) {
			probe.outcome = out;
			setProbeStatus(out.text, out.kind);
			render();   // reachable collapses the section; anything else opens it
		}, function () { /* the drain's own call says more */ });
	}

	// ---- the drain: judging new mail while the mailbox is open ------------------------

	var DRAIN_CAP = 200;         // items per page load (R6)
	var DRAIN_TICK_MS = 30000;   // how often an idle drain looks for new mail
	var drain = { running: false, count: 0, status: '', stopped: false, info: null, infoFor: '' };

	/** Drain text with nothing to act on, hidden in the compact section. */
	function quietDrain(text) {
		return !text || /^Up to date/.test(text);
	}

	function setDrainStatus(text) {
		drain.status = text;
		var n = root && root.querySelector('.mbx-dai-drain');
		if (n) {
			n.textContent = text;
			n.hidden = root.dataset.compact === '1' && quietDrain(text);
		}
	}

	/** What the server says about this mailbox's device recipes (fresh nonces each time). */
	function fetchInfo(mailbox) {
		return window.joineryApi.post('mailbox/ai_device_recipes', { mailbox: mailbox }).then(function (info) {
			drain.info = info;
			drain.infoFor = mailbox;
			return info;
		});
	}

	function ready() {
		var cfg = window.MAILBOX_DEVICE_AI || {};
		var fortress = !!(window.MailboxReader && window.MailboxReader.currentIsFortress && window.MailboxReader.currentIsFortress());
		var st = sectionState({ origin: cfg.origin || null, fortress: fortress }, loadSaved());
		if (st.state !== 'ready') return null;
		if (!window.MailboxFortress || !window.MailboxFortress.isOpen() || document.hidden) return null;
		var url = callUrl(cfg.origin, st.usable.path);
		if (!url) return null;
		return { url: url, key: st.usable.key || '', model: st.usable.model };
	}

	function whenVisible() {
		if (!document.hidden) return Promise.resolve();
		return new Promise(function (resolve) {
			function on() { if (!document.hidden) { document.removeEventListener('visibilitychange', on); resolve(); } }
			document.addEventListener('visibilitychange', on);
		});
	}

	/** One pass: every device recipe on this mailbox, its queue page by page. */
	async function drainPass(endpoint) {
		var mailbox = window.MailboxReader.currentAddress();
		var info = await fetchInfo(mailbox);
		renderInfo();
		if (info.consent_refusal) { setDrainStatus(info.consent_refusal); return; }
		if (!info.recipes.length) { setDrainStatus('No AI recipe is on for this mailbox.'); return; }
		var judged = 0;
		for (var r = 0; r < info.recipes.length; r++) {
			var recipe = Object.assign({ authserv_id: info.authserv_id }, info.recipes[r]);
			var before = 0;
			do {
				var page = await window.joineryApi.post('mailbox/device_ai_entries',
					{ recipe_id: recipe.recipe_id, alias_id: info.alias_id, before_id: before });
				for (var i = 0; i < page.entries.length; i++) {
					if (drain.count >= DRAIN_CAP) { setDrainStatus(DRAIN_CAP + ' judged; more next time.'); drain.stopped = true; return; }
					await whenVisible();
					if (!window.MailboxFortress.isOpen()) { setDrainStatus('Paused while your vault is locked.'); return; }
					setDrainStatus('Judging your mail with ' + endpoint.model + '… ' + drain.count + ' done.');
					var out = await window.MailboxFortress.judgeEntry(page.entries[i], recipe, endpoint);
					if (out.status === 'dropped') { setDrainStatus('Paused while your vault is locked.'); return; }
					if (out.status === 'stop') {
						setDrainStatus('Your model did not answer' + (out.http ? ' (' + out.http + ')' : '') + ': '
							+ (out.reason === 'unreachable' ? 'it could not be reached.' : out.reason) + ' AI waits until it does; press Test again to check.');
						drain.stopped = true;
						return;
					}
					drain.count++;
					if (out.status === 'done') {
						judged++;
						if (window.MailboxReader.refreshList) window.MailboxReader.refreshList();
					}
				}
				before = page.next_before_id || 0;
			} while (before);
		}
		setDrainStatus(drain.count ? 'Up to date. ' + drain.count + ' judged on this device so far.' : 'Up to date.');
	}

	/** Start a pass when everything a pass needs is in place, one tab at a time. */
	function tick() {
		if (drain.running || drain.stopped || drain.count >= DRAIN_CAP) return;
		var endpoint = ready();
		if (!endpoint) return;
		drain.running = true;
		var run = function (lock) {
			if (lock === null) { setDrainStatus('Another tab of this mailbox is judging your mail.'); return Promise.resolve(); }
			return drainPass(endpoint).catch(function (e) {
				setDrainStatus('AI paused: ' + ((e && e.message) || 'something went wrong') + '.');
			});
		};
		var done = function () { drain.running = false; };
		if (navigator.locks && navigator.locks.request) {
			navigator.locks.request('jy-device-ai-drain', { ifAvailable: true }, run).then(done, done);
		} else {
			run(true).then(done, done);
		}
	}

	/** The lines about this mailbox's recipes, consent and model, under the settings. */
	function renderInfo() {
		var box = root && root.querySelector('.mbx-dai-infos');
		if (!box) return;
		box.textContent = '';
		var info = drain.info;
		var mailbox = window.MailboxReader ? window.MailboxReader.currentAddress() : '';
		if (!info || drain.infoFor !== mailbox) return;
		if (info.consent_refusal) { box.appendChild(el('p', 'mbx-dai-warn', info.consent_refusal)); }
		if (info.recipes.length) {
			// The recipe cards above already say each runs on this device: the
			// compact section does not say it again.
			if (root.dataset.compact !== '1') {
				box.appendChild(el('p', 'mbx-dai-info', 'Runs on your device while this mailbox is open: '
					+ info.recipes.map(function (r) { return r.label; }).join(', ') + '.'));
			}
		} else {
			box.appendChild(el('p', 'mbx-dai-info', 'No AI recipe is on for this mailbox. Turn one on above.'));
		}
		var saved = loadSaved();
		var cfg = window.MAILBOX_DEVICE_AI || {};
		var warn = saved && saved.origin === cfg.origin ? floorWarning(saved.model, info.model_reference, info.recipes) : '';
		if (warn) box.appendChild(el('p', 'mbx-dai-warn', warn));
	}

	// ---- on demand, in a Fortress message (R6) --------------------------------------

	/**
	 * The bar under an opened Fortress message: one button per device recipe on
	 * this mailbox (Summarize for the triage, Scan now for the scan), each
	 * running the drain's one-item path for this message and showing what the
	 * model said at once. The verdict is stored as the drain stores it; a
	 * message the recipe already judged keeps its stored verdict. Null when
	 * this browser has no model set up.
	 */
	function messageActions(m) {
		var cfg = window.MAILBOX_DEVICE_AI || {};
		var st = sectionState({ origin: cfg.origin || null, fortress: true }, loadSaved());
		if (st.state !== 'ready' || !window.MailboxFortress || !window.MailboxFortress.judgeEntry) return null;
		var url = callUrl(cfg.origin, st.usable.path);
		if (!url) return null;
		var endpoint = { url: url, key: st.usable.key || '', model: st.usable.model };
		var bar = el('div', 'mbx-dai-bar');
		var result = el('div', 'mbx-dai-result');
		result.setAttribute('role', 'status');
		var mailbox = window.MailboxReader ? window.MailboxReader.currentAddress() : '';
		var infoP = (drain.info && drain.infoFor === mailbox) ? Promise.resolve(drain.info) : fetchInfo(mailbox);
		infoP.then(function (info) {
			if (info.consent_refusal) { result.textContent = info.consent_refusal; return; }
			info.recipes.forEach(function (recipe) {
				var btn = el('button', 'btn btn-secondary btn-sm', actionLabel(recipe.job_id));
				btn.type = 'button';
				btn.addEventListener('click', function (ev) {
					ev.stopPropagation();
					btn.disabled = true;
					result.textContent = 'Asking ' + endpoint.model + '…';
					// A fresh nonce for each run: ask the server again.
					fetchInfo(mailbox).then(function (fresh) {
						var rec = null;
						fresh.recipes.forEach(function (r) { if (r.recipe_id === recipe.recipe_id) rec = r; });
						if (!rec) throw new Error('That recipe is no longer on for this mailbox.');
						return window.MailboxFortress.judgeEntry(entryFromMessage(m),
							Object.assign({ authserv_id: fresh.authserv_id }, rec), endpoint);
					}).then(function (out) {
						result.textContent = onDemandText(out, recipe);
						if (out.status === 'done' && window.MailboxReader.refreshList) window.MailboxReader.refreshList();
					}).catch(function (e) {
						result.textContent = (e && e.message) || 'The model could not be asked.';
					}).then(function () { btn.disabled = false; });
				});
				bar.appendChild(btn);
			});
		}, function () {});
		var wrap = el('div', 'mbx-dai-ondemand');
		wrap.appendChild(bar);
		wrap.appendChild(result);
		return wrap;
	}

	/** What an on-demand run says, in the message. */
	function onDemandText(out, recipe) {
		if (out.status === 'dropped') return 'Stopped: your vault locked.';
		if (out.status === 'error') return 'Your model\'s answer could not be used: ' + out.error;
		if (out.status === 'stop') {
			return 'Your model did not answer' + (out.http ? ' (' + out.http + ')' : '') + ': '
				+ (out.reason === 'unreachable' ? 'it could not be reached.' : out.reason);
		}
		var kept = out.recorded ? '' : ' (not stored: this message already has one)';
		if (recipe.job_id === 'email_security_scan') {
			var scan = {};
			try { scan = JSON.parse(out.plaintext); } catch (e) { scan = {}; }
			return 'Scan by ' + out.model + ': ' + (scan.verdict || '') + '. ' + (scan.summary || '') + kept;
		}
		return 'Summary by ' + out.model + ': ' + out.plaintext + kept;
	}

	function section() {
		if (root) return root;
		root = el('section', 'mbx-dai');
		root.hidden = true;
		render();
		document.addEventListener('joineryareacontextchange', render);
		// The reader fills its mailbox list after the panel mounts.
		document.addEventListener('DOMContentLoaded', render);
		setTimeout(render, 0);
		// New mail, an unlock, a return to the tab: each is picked up by the
		// next tick. A lock pauses the pass in flight (judgeEntry drops it).
		setInterval(tick, DRAIN_TICK_MS);
		document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
		if (window.MailboxFortress && window.MailboxFortress.onLock) {
			window.MailboxFortress.onLock(function () { setDrainStatus('Paused while your vault is locked.'); });
		}
		return root;
	}

	window.MailboxDeviceAi = {
		section: section,
		messageActions: messageActions,
		registerOrigin: registerOrigin,
		logic: { isLocalHost: isLocalHost, normalizePath: normalizePath, callUrl: callUrl, sectionState: sectionState, siteOffer: siteOffer, classify: classify, classifyProbe: classifyProbe,
			gradeModel: gradeModel, paramsFromTag: paramsFromTag, floorWarning: floorWarning,
			entryFromMessage: entryFromMessage, actionLabel: actionLabel },
	};
})();
