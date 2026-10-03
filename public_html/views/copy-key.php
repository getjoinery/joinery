<?php
/**
 * /copy-key - open a backup's key for this copy with the recovery key
 * (specs/site_copy.md WP10). See logic/copy_key_logic.php and CopyKeyHandoff.
 *
 * The key box sits outside the form and has no name, so the recovery key is
 * never part of anything posted; assets/js/copy-key.js works out the one value
 * that opens this backup's sealed key and puts that in the form.
 *
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
<?php if (!$pending): ?>
		<p class="text-muted">This server is not waiting for a backup's key. When a copy is made from a site's backups, its
			management node's Copy tab says when to open this page.</p>
<?php else:
	$age = '';
	$when = strtotime($pending['run_time']);
	if ($when) {
		$hours = (int)floor((time() - $when) / 3600);
		$age = $hours >= 48 ? floor($hours / 24) . ' days ago' : $hours . ' hours ago';
	}
?>
		<p>This server is becoming a copy of <strong><?php echo $h($pending['site']); ?></strong> from its backups, because its
			own server cannot be reached. Its newest backup is locked with the site's backup recovery key. Paste that key here:
			your browser uses it to unlock this one backup, and the key itself never leaves this page.</p>
		<table class="table table-sm">
			<tr><th>Backup</th><td><code><?php echo $h($pending['chain_id']); ?></code></td></tr>
			<tr><th>Newest run</th><td><?php echo $h($pending['run_time']); ?> UTC<?php echo $age !== '' ? ' (' . $h($age) . ')' : ''; ?><br>
				<span class="small text-muted">Anything written on the site after this is not in the copy.</span></td></tr>
			<tr><th>Backup fingerprint</th><td><code><?php echo $h(substr($pending['manifest_sha256'], 0, 16)); ?></code></td></tr>
			<tr><th>Recovery key fingerprint</th><td><code><?php echo $h(substr($pending['recovery_fingerprint'], 0, 16)); ?></code></td></tr>
		</table>
<?php if ($pending['last_error'] !== ''): ?>
		<div class="alert alert-warning">The last key did not open it: <?php echo $h($pending['last_error']); ?></div>
<?php endif; ?>
<?php if ($pending['answered']): ?>
		<p>Your answer is with this machine's agent. This page reloads in a few seconds.</p>
		<script>setTimeout(function () { window.location.href = '/copy-key'; }, 5000);</script>
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
