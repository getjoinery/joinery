/*
 * /admin/admin_passkey_lab: test one passkey variable at a time
 * (adm/admin_passkey_lab.php).
 *
 * #passkey-lab-config: { credentials }.
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	var CREDS = ((window.joineryPageData && joineryPageData('passkey-lab-config')) || {}).credentials || [];

	if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) {
		document.getElementById('lab-unsupported').classList.remove('d-none');
		return;
	}

	var csrf = document.querySelector('meta[name="joinery-api-csrf"]').content;
	function api(url, body) {
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf },
			body: JSON.stringify(body || {}),
		}).then(async function (res) {
			var json = await res.json();
			if (!res.ok) throw new Error((json && json.error) || 'Request failed.');
			return json;
		});
	}

	function report(body) {
		api('/api/v1/action/passkey_lab_report', body).catch(function () { /* the on-page result still shows */ });
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = String(s);
		return d.innerHTML;
	}

	var all = CREDS.map(function (c) { return c.credential_id; });
	var platform = CREDS.filter(function (c) { return c.is_platform; }).map(function (c) { return c.credential_id; });
	var external = CREDS.filter(function (c) { return !c.is_platform; }).map(function (c) { return c.credential_id; });

	var VARIANTS = [
		{ key: 'plain', name: 'Plain step-up', desc: 'All passkeys · verification required · no extensions — the shape the failing production step-up sends', uv: 'required', prf: false, creds: all },
		{ key: 'prf', name: 'With PRF extension', desc: 'All passkeys · verification required · throwaway PRF input — the shape the working vault unlock sends', uv: 'required', prf: true, creds: all },
		{ key: 'platform-only', name: 'Platform passkey only', desc: 'Only platform credentials (Windows Hello) in the allow list · verification required · no extensions', uv: 'required', prf: false, creds: platform },
		{ key: 'security-key-only', name: 'Security key only', desc: 'Only external security keys in the allow list · verification required · no extensions', uv: 'required', prf: false, creds: external },
		{ key: 'uv-preferred', name: 'Verification preferred', desc: 'All passkeys · verification preferred instead of required · no extensions', uv: 'preferred', prf: false, creds: all },
	];

	async function run(variant, btn, cell) {
		btn.disabled = true;
		cell.textContent = 'Waiting for the browser…';
		var phase = 'options';
		var started = performance.now();
		try {
			var opt = await api('/api/v1/action/passkey_lab_options', {
				variant: variant.key,
				uv: variant.uv,
				prf: variant.prf,
				credential_ids: variant.creds,
			});
			phase = 'ceremony';
			started = performance.now();
			var credential = await JoineryPasskeys.authenticate(opt.data.options);
			var ceremonyMs = Math.round(performance.now() - started);
			phase = 'verify';
			var ver = await api('/api/v1/action/passkey_lab_verify', { variant: variant.key, credential: credential });
			var prfNote = ver.data.prf_returned ? ', PRF secret returned' : '';
			report({
				variant: variant.key,
				outcome: 'success',
				detail: 'answered by "' + ver.data.label + '"' + prfNote,
				elapsed_ms: ceremonyMs,
			});
			cell.innerHTML = '<span style="color:#2a8a2a;">&#10003; Verified</span> — answered by '
				+ esc(ver.data.label) + esc(prfNote) + ' (' + (ceremonyMs / 1000).toFixed(1) + 's)';
		} catch (err) {
			var elapsedMs = Math.round(performance.now() - started);
			var name = (err && err.name && err.name !== 'Error') ? err.name : 'Error';
			var message = (err && err.message) ? err.message : String(err);
			report({
				variant: variant.key,
				outcome: 'error',
				error_name: name,
				error_message: '[' + phase + '] ' + message,
				elapsed_ms: elapsedMs,
			});
			cell.innerHTML = '<span style="color:#a00;">&#10007; ' + esc(name) + '</span> — '
				+ esc(message) + ' <span style="color:#888;">(' + phase + ', ' + (elapsedMs / 1000).toFixed(1) + 's)</span>';
		} finally {
			btn.disabled = false;
		}
	}

	var tbody = document.querySelector('#lab-variants tbody');
	VARIANTS.forEach(function (variant) {
		var tr = document.createElement('tr');

		var nameCell = document.createElement('td');
		nameCell.innerHTML = '<strong>' + esc(variant.name) + '</strong>';
		tr.appendChild(nameCell);

		var descCell = document.createElement('td');
		descCell.textContent = variant.desc;
		tr.appendChild(descCell);

		var btnCell = document.createElement('td');
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'btn btn-sm btn-primary';
		btn.textContent = 'Run';
		btnCell.appendChild(btn);
		tr.appendChild(btnCell);

		var resultCell = document.createElement('td');
		tr.appendChild(resultCell);

		if (!variant.creds.length) {
			btn.disabled = true;
			resultCell.innerHTML = '<em>No enrolled passkey matches this variant.</em>';
		} else {
			btn.addEventListener('click', function () { run(variant, btn, resultCell); });
		}

		tbody.appendChild(tr);
	});
});
