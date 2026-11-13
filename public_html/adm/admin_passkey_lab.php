<?php
/** @joinery-admin class=keys */

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
<?php echo PageScript::json('passkey-lab-config', array('credentials' => $credentials)), PageScript::src('assets/js/admin-passkey-lab.js'); ?>

<?php
$page->admin_footer();
?>
