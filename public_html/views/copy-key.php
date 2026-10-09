<?php
/**
 * /copy-key - open a backup's key for this copy with the recovery key
 * (specs/site_copy.md WP10). See logic/copy_key_logic.php and CopyKeyHandoff.
 *
 * The key box sits outside the form and has no name, so the recovery key is
 * never part of anything posted; assets/js/copy-key.js works out the one value
 * that opens this backup's sealed key and puts that in the form.
 *
 * @version 1.2 - the date the backup's storage provider says it was stored, or that it could not be checked
 * @version 1.1 - after an answer, a page with no request left says the key opened the backup, not "not waiting"; the
 *               intro no longer says the source cannot be reached
 * @version 1.0
 */
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getIncludePath('logic/copy_key_logic.php'));

	$page_vars = process_logic(copy_key_logic(array_merge($_GET, $_POST)));
	$pending = $page_vars['pending'] ?? null;
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

	$page = new PublicPage();
	$page->public_header([
		'is_valid_page' => true,
		'title'         => 'Open this copy\'s backup',
		'header_only'   => true,
	]);
?>
<div class="jy-ui">
<div class="auth-page">
	<div class="auth-card">
		<h3>Open this copy's backup</h3>
<?php if (!empty($page_vars['message'])): ?>
		<div class="alert alert-success"><?php echo $h($page_vars['message']); ?></div>
<?php endif; ?>
<?php if (!empty($page_vars['error'])): ?>
		<div class="alert alert-danger"><?php echo $h($page_vars['error']); ?></div>
<?php endif; ?>
<?php if (!$pending && !empty($_GET['sent'])): ?>
		<div class="alert alert-success">The key opened the backup. This server is now restoring it; follow the rest on the
			management node's Copy tab. You can close this page.</div>
<?php elseif (!$pending): ?>
		<p class="text-muted">This server is not waiting for a backup's key. When a copy is made from a site's backups, its
			management node's Copy tab says when to open this page.</p>
<?php else:
	$ago = function ($when) {
		if (!$when) {
			return '';
		}
		$hours = max(0, (int)floor((time() - $when) / 3600));
		return $hours >= 48 ? floor($hours / 24) . ' days ago' : $hours . ' hours ago';
	};
	$run_when = strtotime($pending['run_time']);
	$stored_when = $pending['stored_at'] !== '' ? strtotime($pending['stored_time']) : false;
	$age = $ago($run_when);
	// A backup is stored as it runs; one stored days later was copied or written again since.
	$late_days = ($run_when && $stored_when) ? (int)floor(($stored_when - $run_when) / 86400) : 0;
?>
		<p>This server is becoming a copy of <strong><?php echo $h($pending['site']); ?></strong>, made from its backups. Its
			newest backup is locked with the site's backup recovery key. Paste that key here:
			your browser uses it to unlock this one backup, and the key itself never leaves this page.</p>
		<table class="table table-sm">
			<tr><th>Backup</th><td><code><?php echo $h($pending['chain_id']); ?></code></td></tr>
			<tr><th>Newest run</th><td><?php echo $h($pending['run_time']); ?> UTC<?php echo $age !== '' ? ' (' . $h($age) . ')' : ''; ?><br>
				<span class="small text-muted">Anything written on the site after this is not in the copy.</span></td></tr>
<?php if ($stored_when): ?>
			<tr><th>Stored</th><td><?php echo $h(gmdate('Y-m-d H:i', $stored_when)); ?> UTC (<?php echo $h($ago($stored_when)); ?>), by <?php echo $h($pending['stored_at']); ?><br>
				<span class="small text-muted">This server read the backup at <?php echo $h($pending['stored_at']); ?> itself. The provider set
					this date when the backup arrived, and nobody can set it earlier. If it is after the site's server stopped, this is
					not the site's backup: decline.</span>
<?php if ($late_days >= 2): ?>
				<div class="alert alert-warning mt-2 mb-0">It was stored <?php echo (int)$late_days; ?> days after its run. A backup is stored
					as it runs, so this one was copied or written again since. Decline unless you know why.</div>
<?php endif; ?>
			</td></tr>
<?php else: ?>
			<tr><th>Stored</th><td>Not checked<br>
				<span class="small text-muted">This backup's storage is not at a provider this server knows, so it cannot check when
					the backup was stored. Only the management node vouches for it.</span></td></tr>
<?php endif; ?>
			<tr><th>Backup fingerprint</th><td><code><?php echo $h(substr($pending['manifest_sha256'], 0, 16)); ?></code></td></tr>
			<tr><th>Recovery key fingerprint</th><td><code><?php echo $h(substr($pending['recovery_fingerprint'], 0, 16)); ?></code></td></tr>
		</table>
<?php if ($pending['last_error'] !== ''): ?>
		<div class="alert alert-warning">The last key did not open it: <?php echo $h($pending['last_error']); ?></div>
<?php endif; ?>
<?php if ($pending['answered']): ?>
		<p>Your answer is with this machine's agent. This page reloads in a few seconds.</p>
		<script>setTimeout(function () { window.location.href = '/copy-key?sent=1'; }, 5000);</script>
<?php else: ?>
		<label for="copy_key_secret" class="form-label">Recovery key (private half)</label>
		<input type="password" id="copy_key_secret" class="form-control" autocomplete="off" spellcheck="false">
		<p id="copy_key_status" class="small mt-2"></p>
		<button type="button" id="copy_key_open" class="btn btn-primary">Unlock this backup</button>
<?php
		$formwriter = $page->getFormWriter('copy_key_form', ['action' => '/copy-key']);
		$formwriter->begin_form();
		$formwriter->hiddeninput('copy_key_action', ['value' => 'answer']);
		$formwriter->hiddeninput('job_id', ['value' => (string)$pending['job_id']]);
		$formwriter->hiddeninput('shared', ['value' => '']);
		$formwriter->hiddeninput('public_key', ['value' => '']);
		$formwriter->end_form();

		$decline = $page->getFormWriter('copy_key_decline', ['action' => '/copy-key']);
		$decline->begin_form();
		$decline->hiddeninput('copy_key_action', ['value' => 'decline']);
		$decline->hiddeninput('job_id', ['value' => (string)$pending['job_id']]);
		$decline->submitbutton('btn_decline', 'This is not my backup: decline', ['class' => 'btn btn-outline-danger mt-3']);
		$decline->end_form();
?>
		<script>
			window.copyKeyCeremony = <?php echo json_encode(array(
				'ephemeralPublic' => $pending['ephemeral_public'],
				'fingerprint'     => $pending['recovery_fingerprint'],
			)); ?>;
		</script>
		<script src="/assets/js/copy-key.js"></script>
<?php endif; ?>
<?php endif; ?>
	</div>
</div>
</div>
<?php
	$page->public_footer(['header_only' => true]);
