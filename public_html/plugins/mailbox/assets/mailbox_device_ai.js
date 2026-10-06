/**
 * "Your AI, your model" — AI on end-to-end encrypted (Fortress) mail, run in
 * this browser against a model the member names (specs/fortress_mail_device_ai.md
 * § R2, R7): the AI panel's section on the mail page, and the Email settings
 * page where the model is named and tested.
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
 * docks (JoineryAiPanel.mount hostSection). The section is empty while all
 * is well and says one line, with the fix, when the person has something to
 * do; the address, key, model and Test live in Email settings (the settings
 * half at the end of this file). The decisions — the section's
 * state, the call address, what a Test outcome means — are plain functions on
 * MailboxDeviceAi.logic, loaded without a page by the suite
 * (plugins/mailbox/tests/device_ai_panel_test.php).
 *
 * The drain (R6): while the mailbox is open, its vault unlocked and the tab in
 * view, one tab at a time (navigator.locks 'jy-device-ai-drain') judges each
 * device recipe's queue through MailboxFortress.judgeEntry(), at most 200 a
 * page load. It stops, with the model's own words in the section, when the
 * model does not answer usefully.
 *
 * On demand (R6): messageActions(m) is the bar under an opened Fortress
 * message — Summarize, Scan now — running the same one-item path.
 *
 * Ready needs no click (R7): with a model saved here the drain runs on its
 * own. Opening the page calls nothing; the model is called only when a
 * message is waiting to be judged, which is when the browser asks to reach
 * the person's network. A drain the model stopped is one line with Check
 * again and a link to Email settings, naming the browser's permission when
 * that is what stopped it; the recipe rows say how far along each one is.
 *
 * The site's own model (R7): when the site's local provider sits on a private
 * or tailnet host, MAILBOX_DEVICE_AI.site_model names it and Email settings
 * offers it with one click — registerOrigin() (a passkey confirmation) and the
 * model saved here — with what the operator can see said plainly; on a
 * computer with nothing saved yet for that address, the form starts filled
 * with it.
 *
 * Vanilla JS, jy-ui classes, no framework. @version 1.11 - no check on page load: the
 *   model is called only when the drain has a message to judge
 * @version 1.10 - the panel section speaks only
 *   when something needs the person; the fields, Save and Test move to Email settings
 * @version 1.9 - the Email settings wiring lives here too (its own guarded section)
 * @version 1.8 - compact once reachable
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
				return { kind: 'browser_asks', text: 'Your browser asks first. Try again and choose Allow when it asks about devices on your network.' };
			}
			if (local && r.lna === 'denied') {
				return { kind: 'browser_blocked', text: 'Your browser blocked this site from reaching your computer or network. Allow it in the site settings (the icon left of the address bar), then try again.' };
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
		if (window.JoineryPasskeys && window.JoineryPasskeys.withStepUp) {
			return window.JoineryPasskeys.withStepUp(post, returnUrl);
		}
		return post().catch(function (e) {
			if (!(e && e.data && e.data.requires_stepup)) throw e;
			window.location.href = '/verify-stepup?return=' + encodeURIComponent(returnUrl);
			return new Promise(function () {});
		});
	}

	// ---- the section ---------------------------------------------------------

	function el(tag, className, text) {
		var n = document.createElement(tag);
		if (className) n.className = className;
		if (text != null) n.textContent = text;
		return n;
	}

	var root = null;

	/**
	 * The section says something only when the person has something to do:
	 * a recipe on for this mailbox and no model in this browser, the drain
	 * stopped by the model, a consent
	 * refusal, a model graded below what a recipe asks for. Each is one line
	 * with the way to fix it. The setup itself — the address, the key, the
	 * model, Test — lives in Email settings, and the recipe rows above already
	 * say what is on and how far along it is, so a healthy section is empty.
	 */
	function render() {
		if (!root) return;
		var cfg = window.MAILBOX_DEVICE_AI || {};
		var fortress = !!(window.MailboxReader && window.MailboxReader.currentIsFortress && window.MailboxReader.currentIsFortress());
		var st = sectionState({ origin: cfg.origin || null, fortress: fortress }, loadSaved());
		root.dataset.state = st.state;
		root.textContent = '';
		if (st.state === 'hidden') { root.hidden = true; return; }

		var here = window.MailboxReader ? window.MailboxReader.currentAddress() : '';
		var info = (drain.info && drain.infoFor === here) ? drain.info : null;
		if (here && !info && drain.infoLoading !== here) {
			drain.infoLoading = here;
			fetchInfo(here).then(render, function () {}).then(function () {
				if (drain.infoLoading === here) drain.infoLoading = '';
			});
		}

		if (st.state !== 'ready') {
			// Nothing to run against: worth a word only when something here
			// is waiting for a model.
			if (info && info.recipes.length) {
				problem(st.state === 'no_origin'
					? 'Your AI for this mailbox runs in this browser, against a model you choose. '
					: 'This browser does not have your AI model yet. ',
					[settingsLink('Set it up in Email settings')]);
			}
		} else if (drain.attention) {
			problem(drain.status + ' ', [checkAgainLink(), settingsLink('Email settings')]);
		}
		if (info && info.consent_refusal) problem(info.consent_refusal, []);
		if (info && st.usable) {
			var warn = floorWarning(st.usable.model, info.model_reference, info.recipes);
			if (warn) problem(warn + ' ', [settingsLink('Email settings')]);
		}
		root.hidden = !root.childElementCount;

		if (st.state === 'ready') tick();
	}

	function problem(text, links) {
		var p = el('p', 'mbx-dai-warn', text);
		links.forEach(function (link, i) {
			if (i) p.appendChild(document.createTextNode(' · '));
			p.appendChild(link);
		});
		root.appendChild(p);
	}

	function settingsLink(text) {
		var cfg = window.MAILBOX_DEVICE_AI || {};
		var a = el('a', null, text);
		a.href = cfg.settings_url || '/profile/mailbox/settings#your-model';
		return a;
	}

	/** Let a drain the model stopped start over. */
	function checkAgainLink() {
		var b = el('button', 'mbx-dai-link', 'Check again');
		b.type = 'button';
		b.addEventListener('click', function () {
			drain.stopped = false;
			drain.attention = false;
			render();
		});
		return b;
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

	// ---- the drain: judging new mail while the mailbox is open ------------------------

	var DRAIN_CAP = 200;         // items per page load (R6)
	var DRAIN_TICK_MS = 30000;   // how often an idle drain looks for new mail
	var drain = { running: false, count: 0, status: '', attention: false, stopped: false, info: null, infoFor: '', infoLoading: '' };

	/** Where the drain stands. Only a stop the person can act on — the model
	 *  not answering, a pass that failed — shows in the section; the rest
	 *  (judging, up to date, paused for a locked vault) is kept for whoever
	 *  asks, and the recipe rows already show what is left to judge. */
	function setDrainStatus(text, attention) {
		drain.status = text;
		var was = drain.attention;
		drain.attention = !!attention;
		if (was || drain.attention) render();
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
		render();
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
						drain.stopped = true;
						if (out.reason === 'unreachable') {
							// The browser's permission is the likeliest gate for a model on the person's network.
							var lna = await lnaState();
							var why = classify({ network: true, lna: lna }, isLocalHost(new URL(endpoint.url).hostname), endpoint.model);
							setDrainStatus(why.text, true);
							return;
						}
						setDrainStatus('Your model did not answer' + (out.http ? ' (' + out.http + ')' : '') + ': '
							+ out.reason + ' AI waits until it does.', true);
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
				setDrainStatus('AI paused: ' + ((e && e.message) || 'something went wrong') + '.', true);
			});
		};
		var done = function () { drain.running = false; };
		if (navigator.locks && navigator.locks.request) {
			navigator.locks.request('jy-device-ai-drain', { ifAvailable: true }, run).then(done, done);
		} else {
			run(true).then(done, done);
		}
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
		runTest: runTest,
		loadSaved: loadSaved,
		logic: { isLocalHost: isLocalHost, normalizePath: normalizePath, callUrl: callUrl, sectionState: sectionState, siteOffer: siteOffer, classify: classify,
			gradeModel: gradeModel, paramsFromTag: paramsFromTag, floorWarning: floorWarning,
			entryFromMessage: entryFromMessage, actionLabel: actionLabel },
	};
})();

/**
 * The settings half — Email settings, "Your AI model": where the member's own AI model answers
 * (specs/fortress_mail_device_ai.md § R2).
 *
 * The member pastes the address their model's service gives them. The start
 * of it — the origin, https://host[:port] — goes to mailbox/device_ai_host and
 * is kept with the account; the mailbox page names exactly it in its CSP. The
 * rest of the address stays in this browser, in the same entry the AI panel's
 * "Your AI, your model" keeps the key and model in (mailbox_device_ai.js).
 *
 * Choosing where mail is sent needs a fresh second-factor confirmation:
 * MailboxDeviceAi.registerOrigin() (the panel half above) runs
 * the passkey confirmation here and saves again, or goes to the confirmation
 * page and back without a passkey. Saving a new key or model for the address
 * already registered changes only this browser, so it asks for nothing.
 *
 * Test ([data-device-ai-test]) sends what a real judgement sends to the model
 * saved in this browser (MailboxDeviceAi.runTest) and says which gate stopped
 * it, in [data-device-ai-test-note]. The page names the registered origin in
 * its CSP, as the mail page does.
 *
 * Page contract: window.MAILBOX_DEVICE_AI = {origin, user_id, site_model};
 * the form #device-ai-host-form with its device_ai_address, device_ai_key and
 * device_ai_model fields inside [data-device-ai-form] (hidden until
 * [data-device-ai-enter] opens it, where the site's model is the primary
 * choice); a [data-device-ai-remove] button when an address is registered; a
 * [data-device-ai-use-site] button when the site's own model is offered —
 * one click registers its origin and keeps its path and model here.
 *
 * Vanilla JS. @version 1.4 - Test lives here; a key or model change for the registered
 *   address skips the confirmation; the site's model prefills an empty browser
 * @version 1.3 - the form takes the key and model too, and opens on request
 * @version 1.2 - the site's own model with one click; registration shared
 * @version 1.1 - shows the whole address when this browser holds the rest
 */
(function () {
	'use strict';
	// Loaded under node by the panel's tests, with no page at all.
	if (typeof document === 'undefined' || typeof window === 'undefined') return;

	var cfg = window.MAILBOX_DEVICE_AI || {};
	var STORE = 'jy_device_ai:' + String(cfg.user_id || 0);
	var RETURN = '/profile/mailbox/settings#your-model';

	function note(text) {
		var n = document.querySelector('[data-device-ai-note]');
		if (!n) return;
		n.textContent = text;
		n.hidden = !text;
	}

	/** Split what was pasted into the origin (kept with the account) and the
	 *  rest (kept here). Throws with the reason when it is not an address. */
	function split(address) {
		var u;
		try { u = new URL(String(address || '').trim()); } catch (e) {
			throw new Error('That is not a web address. It starts with https://');
		}
		if (u.protocol !== 'https:' && u.protocol !== 'http:') {
			throw new Error('The address must start with https://');
		}
		if (u.username || u.password) {
			throw new Error('Leave any name or password out of the address; your key goes in the AI panel.');
		}
		if (u.search || u.hash) {
			throw new Error('Leave off anything after a ? or a #.');
		}
		return { origin: u.origin, path: u.pathname.replace(/\/+$/, '') };
	}

	/** Keep the rest of the address here, with the model and key when given. */
	function keepPath(origin, path, model, key) {
		try {
			var raw = window.localStorage.getItem(STORE);
			var old = raw ? JSON.parse(raw) : null;
			var entry = (old && old.origin === origin) ? old : { origin: origin, key: '', model: '' };
			entry.path = path;
			if (model !== undefined) entry.model = model;
			if (key !== undefined) entry.key = key;
			window.localStorage.setItem(STORE, JSON.stringify(entry));
		} catch (e) { /* a browser that keeps nothing: the panel asks again */ }
	}

	function savedHere() {
		try {
			var saved = JSON.parse(window.localStorage.getItem(STORE) || 'null');
			return saved && cfg.origin && saved.origin === cfg.origin ? saved : null;
		} catch (e) { return null; }
	}

	function register(origin) {
		if (!window.MailboxDeviceAi || !window.MailboxDeviceAi.registerOrigin) {
			return Promise.reject(new Error('This page could not load what saving needs. Reload it and try again.'));
		}
		return window.MailboxDeviceAi.registerOrigin(origin, RETURN);
	}

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('device-ai-host-form');
		// Only the Email settings page carries these controls; the mailbox page
		// loads this file for the panel above and has none of them.
		if (!form && !document.querySelector('[data-device-ai-form], [data-device-ai-remove], [data-device-ai-use-site]')) return;
		var box = document.querySelector('[data-device-ai-form]');
		// The server knows only the start of the address. Show the whole of it,
		// with the key and model, when this browser holds the rest, so saving
		// again unchanged keeps them instead of clearing them.
		var field = form ? form.querySelector('[name="device_ai_address"]') : null;
		var keyField = form ? form.querySelector('[name="device_ai_key"]') : null;
		var modelField = form ? form.querySelector('[name="device_ai_model"]') : null;
		var saved = savedHere();
		var logic = window.MailboxDeviceAi && window.MailboxDeviceAi.logic;
		var offer = logic ? logic.siteOffer(cfg, saved) : null;
		if (saved && field && field.value === cfg.origin) {
			if (saved.path) field.value = cfg.origin + saved.path;
			if (keyField) keyField.value = saved.key || '';
			if (modelField) modelField.value = saved.model || '';
		}
		// The site's own model is the address registered, and this browser
		// has no model for it yet (another computer): start the form filled.
		if (offer && offer.kind === 'prefill' && field && modelField) {
			field.value = offer.site.origin + (offer.site.path || '');
			if (!modelField.value) modelField.value = offer.site.model;
		}
		var enter = document.querySelector('[data-device-ai-enter]');
		if (enter && box) {
			enter.addEventListener('click', function () {
				box.hidden = false;
				enter.disabled = true;
				if (field) field.focus();
			});
		}
		// An address is registered but this browser holds no model for it (a
		// new computer, where the mail page's panel sends people): the form is
		// what they came for.
		if (cfg.origin && box && !(saved && String(saved.model || '').trim())) {
			box.hidden = false;
			if (enter) enter.disabled = true;
		}
		if (form) {
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var parts;
				try { parts = split(field ? field.value : ''); } catch (e) { note(e.message); return; }
				var model = modelField ? modelField.value.trim() : '';
				if (!model) { note('Enter the model\'s name, exactly as your service names it.'); if (modelField) modelField.focus(); return; }
				var key = keyField ? keyField.value : '';
				var btn = form.querySelector('[type="submit"]');
				if (btn) btn.disabled = true;
				note('Saving…');
				// The same address: only what this browser keeps changes.
				var stored = parts.origin === cfg.origin ? Promise.resolve(cfg.origin) : register(parts.origin);
				stored.then(function (origin) {
					keepPath(origin, parts.path, model, key);
					window.location.href = RETURN;
					window.location.reload();
				}).catch(function (e) {
					note((e && e.message) || 'Could not save the model.');
				}).then(function () { if (btn) btn.disabled = false; });
			});
		}
		var use = document.querySelector('[data-device-ai-use-site]');
		if (use && cfg.site_model && cfg.site_model.origin) {
			use.addEventListener('click', function () {
				var site = cfg.site_model;
				if (field) field.value = site.origin + (site.path || '');
				use.disabled = true;
				note('Saving…');
				register(site.origin).then(function (origin) {
					keepPath(origin, site.path || '', site.model, '');
					window.location.href = RETURN;
					window.location.reload();
				}).catch(function (e) {
					note((e && e.message) || 'Could not save the address.');
					use.disabled = false;
				});
			});
		}
		var test = document.querySelector('[data-device-ai-test]');
		var testNote = document.querySelector('[data-device-ai-test-note]');
		if (test && testNote && window.MailboxDeviceAi && window.MailboxDeviceAi.runTest) {
			test.addEventListener('click', function () {
				var mine = savedHere();
				testNote.hidden = false;
				if (!mine || !String(mine.model || '').trim()) {
					testNote.textContent = 'This browser has no model saved for this address yet. Change the model, enter it, and save.';
					return;
				}
				test.disabled = true;
				testNote.textContent = 'Testing ' + mine.model + '…';
				window.MailboxDeviceAi.runTest({ origin: cfg.origin, path: mine.path || '', key: mine.key || '', model: mine.model })
					.then(function (out) { testNote.textContent = out.text; },
						function (e) { testNote.textContent = (e && e.message) || 'The test could not start.'; })
					.then(function () { test.disabled = false; });
			});
		}
		var remove = document.querySelector('[data-device-ai-remove]');
		if (remove) {
			remove.addEventListener('click', function () {
				remove.disabled = true;
				window.joineryApi.post('mailbox/device_ai_host', { origin: '' }).then(function () {
					window.location.href = RETURN;
					window.location.reload();
				}).catch(function (e) {
					note((e && e.message) || 'Could not remove the address.');
					remove.disabled = false;
				});
			});
		}
	});

})();
