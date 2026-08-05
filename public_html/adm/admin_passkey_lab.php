<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_passkey_lab_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_passkey_lab_logic(array_merge($_GET, $_POST)));

$session          = $page_vars['session'];
$passkeys_enabled = $page_vars['passkeys_enabled'];
$credentials      = $page_vars['credentials'];
$recent           = $page_vars['recent'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'system-passkey-lab',
	'breadcrumbs' => array(
		'System'      => '',
		'Passkey Lab' => '',
	),
	'session' => $session,
));
?>

<h2>Passkey Lab</h2>

<?php if (!$passkeys_enabled): ?>
	<div class="alert alert-danger" role="alert">Passkeys are disabled (<code>passkeys_enabled</code> setting). The lab cannot run ceremonies.</div>
<?php endif; ?>

<div class="alert alert-danger d-none" role="alert" id="lab-unsupported">This browser does not support WebAuthn — ceremonies cannot run here.</div>

<h3>Your passkeys</h3>
<?php if (!$credentials): ?>
	<div class="alert alert-warning" role="alert">
		No passkeys are enrolled on this account. Enroll the authenticators under test
		at <a href="/profile/security">/profile/security</a>, then return here.
	</div>
<?php else: ?>
	<table class="table">
		<thead>
			<tr><th>Label</th><th>Type</th><th>Transports</th><th>Vault capability</th><th>Signals</th><th>Last used</th></tr>
		</thead>
		<tbody>
		<?php
		$capability_notes = array(
			'capable'   => 'has evaluated PRF at least once',
			'incapable' => 'CTAP1 fallback — can never unlock a vault',
			'unknown'   => 'not proven either way; the ceremony is the real test',
		);
		foreach ($credentials as $cred):
			// Null is "the signal was never reported", which is not the same
			// answer as false and must not read like it.
			$tri = function ($value) {
				return $value === null ? '—' : ($value ? 'yes' : 'no');
			};
		?>
			<tr>
				<td><?php echo htmlspecialchars($cred['label']); ?></td>
				<td><?php echo $cred['is_platform'] ? 'Platform' : 'Security key'; ?></td>
				<td><?php echo htmlspecialchars(implode(', ', $cred['transports']) ?: '—'); ?></td>
				<td><?php echo htmlspecialchars($cred['vault_capability']); ?><br>
					<small class="text-muted"><?php echo htmlspecialchars($capability_notes[$cred['vault_capability']] ?? ''); ?></small></td>
				<td><small>
					PRF reported: <?php echo $cred['prf_capable'] ? 'yes' : 'no'; ?><br>
					discoverable: <?php echo $tri($cred['discoverable']); ?><br>
					attachment: <?php echo htmlspecialchars($cred['attachment'] ?: '—'); ?><br>
					user verification ever performed: <?php echo $cred['uv_never_performed'] ? 'no' : 'yes'; ?>
				</small></td>
				<td><?php echo $cred['last_used_time']
					? htmlspecialchars(LibraryFunctions::convert_time($cred['last_used_time'], 'UTC', $session->get_timezone(), 'M j, Y g:i A T'))
					: '<em>never</em>'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<h3>Ceremony variants</h3>
<table class="table" id="lab-variants">
	<thead>
		<tr><th style="width:220px;">Variant</th><th>Request shape</th><th style="width:110px;"></th><th style="width:45%;">Result</th></tr>
	</thead>
	<tbody></tbody>
</table>

<h3>Recorded outcomes</h3>
<p>Every run is logged server-side (feature <code>passkey_lab</code> in the request log), including browser-side failures that never reach the server. Reload to refresh.</p>
<?php if (!$recent): ?>
	<p><em>No runs recorded yet.</em></p>
<?php else: ?>
	<table class="table">
		<thead>
			<tr><th>Time</th><th>Event</th><th>Outcome</th><th>Detail</th><th>ms</th></tr>
		</thead>
		<tbody>
		<?php foreach ($recent as $row): ?>
			<tr>
				<td><?php echo htmlspecialchars(LibraryFunctions::convert_time($row['time'], 'UTC', $session->get_timezone(), 'M j g:i:s A')); ?></td>
				<td><code><?php echo htmlspecialchars($row['action']); ?></code></td>
				<td><?php echo $row['success']
					? '<span style="color:#2a8a2a;">&#10003;</span>'
					: '<span style="color:#a00;">&#10007;</span>'; ?></td>
				<td><?php echo htmlspecialchars((string)$row['note']); ?></td>
				<td><?php echo $row['response_ms'] !== null ? (int)$row['response_ms'] : ''; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script defer>
document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	var CREDS = <?php echo json_encode($credentials); ?>;

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
</script>

<?php
$page->admin_footer();
?>
