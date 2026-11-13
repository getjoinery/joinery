/*
 * Browser-side checks for tests/unit/formwriter_behaviour_test.php: what
 * FormWriter's scripted fields do, pinned before their scripts move into
 * files (specs/strict_script_policy.md WP3). The test renders one real form,
 * puts this file after it, and reads the <li data-result> rows from Chrome.
 *
 * Runs once the page has loaded, so it sees the fields as a visitor would.
 */
(function () {
	'use strict';
	var results = document.getElementById('results');
	function report(name, ok, detail) {
		var li = document.createElement('li');
		li.setAttribute('data-result', ok ? 'pass' : 'fail');
		li.setAttribute('data-name', name);
		li.textContent = name + (ok ? '' : ' :: ' + (detail === undefined ? '' : detail));
		results.appendChild(li);
	}
	function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
	function $(id) { return document.getElementById(id); }
	function shown(name) {
		var el = $(name + '_container') || $(name);
		return !!el && el.style.display !== 'none' && !el.classList.contains('fw-field-hidden');
	}
	function change(el) { el.dispatchEvent(new Event('change', { bubbles: true })); }
	function input(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }
	// Whatever the validator lets through is recorded, never sent.
	var submitted = [];
	var submitters = [];
	document.addEventListener('submit', function (e) {
		var b = e.submitter;
		submitters.push(e.target.id + ':' + (b ? b.name + '=' + b.value : ''));
	}, true);
	var posted = [];
	document.addEventListener('submit', function (e) {
		if (!e.defaultPrevented) {
			submitted.push('native');
			// What the browser would send, as the form stands.
			var fd = new FormData(e.target, e.submitter || undefined);
			posted.push(e.target.id + ':' + (fd.get('fwb_ask3') || '-'));
			e.preventDefault();
		}
	});

	async function run() {
		var form = $('fwb_form');

		// ── Validation ──────────────────────────────────────────────────────
		var v = form && form.joineryValidator;
		report('the form has its validator', !!v, typeof v);
		if (v) {
			v.submitHandler = function () { submitted.push('validated'); };
			$('fwb_email').value = 'not-an-email';
			$('fwb_name').value = 'ab';
			form.requestSubmit($('fwb_submit') || undefined);
			await sleep(50);
			report('a bad email and a short name are refused: nothing is sent', submitted.length === 0, JSON.stringify(submitted));
			report('the bad email is marked', $('fwb_email').classList.contains('is-invalid'), $('fwb_email').className);
			report('the short name is marked', $('fwb_name').classList.contains('is-invalid'), $('fwb_name').className);
			$('fwb_email').value = 'a@example.com';
			$('fwb_name').value = 'Abe';
			input($('fwb_email')); input($('fwb_name'));
			form.requestSubmit($('fwb_submit') || undefined);
			await sleep(50);
			report('fixed, the form goes to its submitHandler', JSON.stringify(submitted) === '["validated"]', JSON.stringify(submitted));
			report('and the marks are gone', !$('fwb_email').classList.contains('is-invalid') && !$('fwb_name').classList.contains('is-invalid'));
		}

		// ── visibility_rules ────────────────────────────────────────────────
		await sleep(400);
		report('select: on load, the target for its value is shown and the other hidden', shown('fwb_sel_a') && !shown('fwb_sel_b'));
		$('fwb_mode').value = 'b'; change($('fwb_mode'));
		await sleep(400);
		report('select: changing it swaps them', !shown('fwb_sel_a') && shown('fwb_sel_b'));

		report('checkbox: unticked on load, its target is hidden', !shown('fwb_chk_target'));
		$('fwb_flag').checked = true; change($('fwb_flag'));
		await sleep(400);
		report('checkbox: ticked, its target shows', shown('fwb_chk_target'));
		$('fwb_flag').checked = false; change($('fwb_flag'));
		await sleep(400);
		report('checkbox: unticked again, it hides', !shown('fwb_chk_target'));

		report('radio: on load (P), its target is hidden', !shown('fwb_radio_target'));
		var q = document.querySelector('input[name="fwb_kind"][value="q"]');
		q.checked = true; change(q);
		await sleep(400);
		report('radio: choosing Q shows it', shown('fwb_radio_target'));

		report('radio checkboxList: on load (monthly), its target is hidden', !shown('fwb_list_target'));
		var y = document.querySelector('input[name="fwb_plan[]"][value="y"]');
		report('radio checkboxList: the yearly option is there', !!y);
		if (y) {
			y.checked = true; change(y);
			await sleep(400);
			report('radio checkboxList: choosing yearly shows it', shown('fwb_list_target'));
		}

		// ── Repeater ────────────────────────────────────────────────────────
		var rep = document.querySelector('.repeater[data-name="fwb_rows"]');
		function rows() { return rep.querySelectorAll('.repeater-items .repeater-row'); }
		var add = rep.querySelector('.repeater-add');
		report('repeater: one row to start, and it cannot be removed (min 1)',
			rows().length === 1 && rows()[0].querySelector('.repeater-remove').disabled === true, rows().length);
		add.click();
		report('repeater: Add makes a second row', rows().length === 2, rows().length);
		var second = rows()[1].querySelector('input[name]');
		report('repeater: the new row\'s field is numbered 1', !!second && second.name.indexOf('[1]') !== -1, second && second.name);
		add.click();
		report('repeater: at three rows (max), Add is disabled', rows().length === 3 && add.disabled === true, rows().length);
		add.click();
		report('repeater: and does nothing', rows().length === 3, rows().length);
		rows()[2].querySelector('.repeater-remove').click();
		report('repeater: Remove takes a row away and Add comes back', rows().length === 2 && add.disabled === false, rows().length);

		// ── Time input ──────────────────────────────────────────────────────
		var hidden = $('fwb_time');
		report('time: the hidden field holds the starting time', hidden && hidden.value === '09:15', hidden && hidden.value);
		$('fwb_time_hour').value = '2'; $('fwb_time_minute').value = '30'; $('fwb_time_ampm').value = 'PM';
		change($('fwb_time_ampm'));
		report('time: 2:30 PM fills it as 14:30', hidden.value === '14:30', hidden.value);
		$('fwb_time_hour').value = '12'; $('fwb_time_ampm').value = 'AM'; change($('fwb_time_hour'));
		report('time: 12:30 AM is 00:30', hidden.value === '00:30', hidden.value);

		// ── AJAX select ─────────────────────────────────────────────────────
		async function search(id) {
			var sel = $(id);
			var box = sel.parentNode.querySelector('input[list="' + id + '_list"]');
			report(id + ': the select is hidden behind a search box', !!box && sel.style.display === 'none');
			if (!box) return;
			window.__requests = [];
			box.value = 'al'; input(box);
			await sleep(400);
			report(id + ': two letters do not search', window.__requests.length === 0, JSON.stringify(window.__requests));
			box.value = 'ali'; input(box);
			await sleep(400);
			var opts = $(id + '_list').querySelectorAll('option');
			report(id + ': three letters search once and list the answers',
				window.__requests.length === 1 && opts.length === 2 && opts[0].value === 'Alice Smith', JSON.stringify(window.__requests));
			box.value = 'Alice Smith'; change(box);
			report(id + ': picking an answer sets the select to its id', sel.value === '7', sel.value);
			return window.__requests[0];
		}
		var plain = await search('fwb_plain');
		report('fwb_plain: a plain endpoint is asked by GET with ?q=, keeping its own query',
			!!plain && plain.via === 'fetch' && plain.url === '/ajax/fwb_stub_search?type=u&q=ali', JSON.stringify(plain));
		var api = await search('fwb_api');
		report('fwb_api: an /api/v1/ endpoint is asked through joineryApi.post with {q}',
			!!api && api.via === 'api' && api.action === '/api/v1/action/fwb_stub_search' && api.body && api.body.q === 'ali', JSON.stringify(api));

		// ── A submit button that asks first ─────────────────────────────────
		var form2 = $('fwb_form2'), ask = $('fwb_ask');
		var dlg = function () { var d = document.querySelector('dialog.jy-ui'); return d && d.open ? d : null; };
		var v2 = form2 && form2.joineryValidator;
		if (v2) v2.submitHandler = function () { submitted.push('validated2'); };
		report('confirm: the button posts nothing until asked (type="button")', ask && ask.type === 'button', ask && ask.type);
		submitters = []; submitted = [];
		ask.click();
		await sleep(50);
		report('confirm: with a needed field empty it does not ask, and sends nothing', !dlg() && submitters.length === 0, JSON.stringify(submitters));
		$('fwb_need').value = 'filled'; input($('fwb_need'));
		ask.click();
		await sleep(50);
		var d = dlg(), btns = d ? d.querySelectorAll('.dialog-actions button') : [];
		var yes = btns[btns.length - 1];
		report('confirm: filled, it asks with its words and its label', !!d && d.querySelector('.dialog-message').textContent === 'Really do it?'
			&& !!yes && yes.textContent === 'Go' && yes.className === 'btn btn-warning', d ? yes.textContent + ' ' + yes.className : 'no dialog');
		report('confirm: nothing is sent before the answer', submitters.length === 0, JSON.stringify(submitters));
		if (btns.length > 1) { btns[0].click(); await sleep(50); }
		report('confirm: Cancel sends nothing', submitters.length === 0, JSON.stringify(submitters));
		ask.click(); await sleep(50);
		d = dlg(); btns = d ? d.querySelectorAll('.dialog-actions button') : [];
		if (btns.length) { btns[btns.length - 1].click(); }
		await sleep(50);
		report('confirm: yes submits the form with the button\'s name and value',
			submitters.length >= 1 && submitters[0].indexOf('fwb_form2:') === 0 && new FormData(form2).get('fwb_ask') === 'yes', JSON.stringify(submitters));
		report('confirm: and the validator still had the say', submitted.indexOf('validated2') !== -1, JSON.stringify(submitted));
		report('confirm: the button is a plain button again', ask.type === 'button', ask.type);

		// ── A confirm button on a validated form, no submitHandler ──────────
		var form3 = $('fwb_form3'), ask3 = $('fwb_ask3'), mail3 = $('fwb_mail3');
		async function pressYes(btn) {
			btn.click(); await sleep(50);
			var dd = dlg(), bb = dd ? dd.querySelectorAll('.dialog-actions button') : [];
			if (bb.length) bb[bb.length - 1].click();
			await sleep(200);
		}
		report('validated form: the validator is on it, with no submitHandler', !!(form3 && form3.joineryValidator) && !form3.joineryValidator.submitHandler);
		posted = [];
		mail3.value = 'not-an-email'; input(mail3);
		await pressYes(ask3);
		report('validated form: a value only the validator refuses is refused, nothing posted', posted.length === 0, JSON.stringify(posted));
		report('validated form: and the refused press leaves no proof behind',
			!form3.querySelector('input[type="hidden"][data-joinery-submitter]'), form3.innerHTML.slice(0, 200));
		mail3.value = 'a@example.com'; input(mail3);
		await pressYes(ask3);
		report('validated form: yes posts once, with the button\'s name and value', JSON.stringify(posted) === '["fwb_form3:yes"]', JSON.stringify(posted));
		report('validated form: and the button is still a plain button', ask3.type === 'button', ask3.type);

		// ── navigate_on_change stays on the site ────────────────────────────
		var before = location.href, go = $('fwb_go');
		go.value = '//evil.example/'; change(go);
		await sleep(50);
		report('navigate_on_change: an address off the site goes nowhere', location.href === before && go.hasAttribute('data-jy-navigate'), location.href);
	}

	function start() {
		run().catch(function (e) {
			report('the checks ran without throwing', false, String(e && e.stack || e));
		}).then(function () {
			document.body.setAttribute('data-done', '1');
		});
	}
	if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
})();
