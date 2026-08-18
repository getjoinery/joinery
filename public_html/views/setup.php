<?php
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('setup_logic.php', 'logic'));

	$page_vars = process_logic(setup_logic(array_merge($_GET, $_POST, $params ?? [])));

	$settings = Globalvars::get_instance();
	$steps = $page_vars['steps'];
	$statuses = $page_vars['statuses'];
	$declined = $page_vars['declined'] ?? array();
	$current_key = $page_vars['current_key'];
	$current_index = $page_vars['current_index'];
	$total = $page_vars['total'];
	$viewer = $page_vars['viewer'];
	$permission = $page_vars['permission'];

	$site_name = trim((string)$settings->get_setting('site_name'));
	if ($site_name === '') {
		$site_name = 'Joinery';
	}
	$home_url = ($permission >= 10) ? '/admin' : '/profile';

	$current_step = null;
	foreach ($steps as $s) {
		if ($s['key'] === $current_key) {
			$current_step = $s;
			break;
		}
	}
	$next_key = ($current_step !== null) ? _setup_next_key($steps, $current_key) : 'done';

	// Live "what is not done" lines for the dismissal dialog. A declined step
	// is green only as a decision — the underlying thing is still not set up,
	// so its line belongs in this list too.
	$outstanding = array();
	foreach ($steps as $s) {
		if ($statuses[$s['key']] !== SetupSteps::STATUS_GREEN || !empty($declined[$s['key']])) {
			$outstanding[] = $s['dismiss_line'] ?? ($s['title'] . ' is not set up.');
		}
	}

	$page = new PublicPage();
	$page->public_header([
		'is_valid_page' => true,
		'title'         => 'Set up — ' . $site_name,
		'header_only'   => true,
	]);
?>
<div class="jy-ui">
<style>
.setup-shell { max-width: 720px; margin: 0 auto; padding: 0 16px 48px; }
.setup-topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 0; }
.setup-site { font-weight: 600; }
.setup-progress { color: var(--jy-muted, #667085); font-size: 0.9em; }
.setup-card { background: var(--jy-card-bg, #fff); border: 1px solid var(--jy-border, #e4e7ec); border-radius: 10px; padding: 28px; }
.setup-card h1 { margin-top: 0; font-size: 1.4em; }
.setup-copy { color: var(--jy-muted, #475467); margin-bottom: 20px; }
.setup-nav { display: flex; justify-content: space-between; align-items: center; margin-top: 24px; }
/* A step whose answer is one-or-the-other puts both answers on one row. */
.setup-choice { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.setup-choice form { margin: 0; }
.setup-field { display: block; }
.setup-field > span { display: block; font-weight: 600; margin-bottom: 4px; }
.setup-field > input { width: 100%; max-width: 360px; }
.setup-nav .setup-skip { color: var(--jy-muted, #667085); }
.setup-done-row { display: flex; align-items: center; gap: 10px; padding: 14px; border: 1px solid var(--jy-border, #e4e7ec); border-radius: 8px; background: #f6fef9; }
.setup-checklist { list-style: none; margin: 0; padding: 0; }
.setup-checklist li { display: flex; align-items: center; gap: 10px; padding: 10px 4px; border-bottom: 1px solid var(--jy-border, #eef1f5); }
.setup-checklist li:last-child { border-bottom: none; }
.setup-dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
.setup-dot.green { background: #12b76a; }
.setup-dot.amber { background: #f79009; }
.setup-dot.none { background: #d0d5dd; }
.setup-checklist .setup-home { margin-left: auto; font-size: 0.9em; }
#setup-dismiss { border: 1px solid var(--jy-border, #e4e7ec); border-radius: 10px; padding: 24px; max-width: 460px; }
#setup-dismiss::backdrop { background: rgba(16, 24, 40, 0.5); }
#setup-dismiss ul { margin: 12px 0; padding-left: 20px; }
.setup-dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
</style>

	<div class="setup-shell">
		<header class="setup-topbar">
			<span class="setup-site"><?php echo htmlspecialchars($site_name); ?></span>
			<span class="setup-progress">
				<?php echo ($current_key === 'done') ? 'All steps reviewed' : 'Step ' . ($current_index + 1) . ' of ' . $total; ?>
			</span>
			<button type="button" class="btn btn-secondary" id="setup-finish-later">Finish later</button>
		</header>

		<main class="setup-card">
<?php echo $page->render_messages(); ?>
<?php if (!empty($page_vars['error'])) { ?>
			<div class="jy-alert jy-alert-error"><?php echo htmlspecialchars($page_vars['error']); ?></div>
<?php } ?>

<?php if ($current_key === 'done') { ?>
			<h1>You're set up</h1>
			<p class="setup-copy">Here is where everything stands. Each item links to its permanent home — that is where these controls live from now on.</p>
<?php if (!empty($page_vars['heartbeat_warning'])) { ?>
			<div class="jy-alert jy-alert-error"><?php echo htmlspecialchars($page_vars['heartbeat_warning']); ?></div>
<?php } ?>
			<ul class="setup-checklist">
<?php foreach ($steps as $s) { $st = $statuses[$s['key']]; ?>
				<li>
					<span class="setup-dot <?php echo htmlspecialchars($st); ?>"></span>
					<span><?php echo htmlspecialchars($s['title']); ?><?php if (!empty($declined[$s['key']])) { ?> <span class="jy-muted">— not set up, by your choice</span><?php } ?></span>
<?php if (!empty($s['home_url'])) { ?>
					<a class="setup-home" href="<?php echo htmlspecialchars($s['home_url']); ?>"><?php
						// "Manage" belongs to a thing that exists; a declined
						// step's thing does not.
						if (!empty($declined[$s['key']])) { echo 'Set up'; }
						elseif ($st === SetupSteps::STATUS_GREEN) { echo 'Manage'; }
						else { echo 'Finish'; }
					?></a>
<?php } ?>
				</li>
<?php } ?>
			</ul>
			<div class="setup-nav">
				<span></span>
				<a class="btn btn-primary" href="<?php echo htmlspecialchars($home_url); ?>">Go to your site</a>
			</div>
<?php } elseif ($current_step !== null) { ?>
			<h1><?php echo htmlspecialchars($current_step['title']); ?></h1>
			<p class="setup-copy"><?php echo htmlspecialchars(SetupSteps::copyFor($current_step, $viewer)); ?></p>

<?php
		$force_render = ($page_vars['force_render_step'] ?? '') === $current_key;
		$was_declined = !empty($declined[$current_key]);
		if ($statuses[$current_key] === SetupSteps::STATUS_GREEN && !$force_render && !$was_declined) {
?>
			<div class="setup-done-row">
				<span class="setup-dot green"></span>
				<span>Already done — nothing needed here.</span>
			</div>
<?php
		} else {
			if ($was_declined) {
?>
			<div class="jy-callout jy-callout-info">
				<div class="jy-callout-title">You chose not to set this up</div>
				<p>The wizard will stop asking. You can change your mind here or at <a href="<?php echo htmlspecialchars($current_step['home_url'] ?? '/profile'); ?>">its permanent home</a> whenever you like.</p>
			</div>
<?php
			}
			$render_file = $current_step['render_file'] ?? '';
			$render_path = $render_file !== '' ? PathHelper::getIncludePath($render_file) : '';
			if ($render_path !== '' && file_exists($render_path)) {
				$step = $current_step;
				include($render_path);
			} else {
?>
			<p class="jy-muted">This step's controls are not available.</p>
<?php
			}
		}
?>
			<div class="setup-nav">
<?php if ($current_index > 0) { ?>
				<a class="setup-skip" href="/setup?step=<?php echo urlencode($steps[$current_index - 1]['key']); ?>">&larr; Back</a>
<?php } else { ?>
				<span></span>
<?php } ?>
<?php if ($force_render) { ?>
				<span></span><!-- shown-once screen: the partial supplies its own gated continue -->
<?php } elseif ($statuses[$current_key] === SetupSteps::STATUS_GREEN) { ?>
				<a class="btn btn-primary" href="/setup?step=<?php echo urlencode($next_key); ?>">Continue &rarr;</a>
<?php } else { ?>
				<a class="setup-skip" href="/setup?step=<?php echo urlencode($next_key); ?>">Skip for now &rarr;</a>
<?php } ?>
			</div>
<?php } ?>
		</main>
	</div>

	<dialog id="setup-dismiss">
		<h3>Finish later?</h3>
<?php if ($outstanding) { ?>
		<p>Here is what is not set up yet:</p>
		<ul>
<?php foreach ($outstanding as $line) { ?>
			<li><?php echo htmlspecialchars($line); ?></li>
<?php } ?>
		</ul>
<?php } else { ?>
		<p>Everything in your list is done — you can leave any time.</p>
<?php } ?>
		<form method="POST" action="/setup">
			<input type="hidden" name="action" value="dismiss">
			<label class="jy-check">
				<input type="checkbox" name="understand" value="1" required>
				I understand — I can finish any time from the "Finish setup" link.
			</label>
			<div class="setup-dialog-actions">
				<button type="button" class="btn btn-secondary" id="setup-dismiss-cancel">Keep going</button>
				<button type="submit" class="btn btn-primary">Finish later</button>
			</div>
		</form>
	</dialog>

	<script>
	(function () {
		var dialog = document.getElementById('setup-dismiss');
		var open = document.getElementById('setup-finish-later');
		var cancel = document.getElementById('setup-dismiss-cancel');
		if (open && dialog) {
			open.addEventListener('click', function () { dialog.showModal(); });
		}
		if (cancel && dialog) {
			cancel.addEventListener('click', function () { dialog.close(); });
		}
	})();
	</script>
</div>
<?php
	$page->public_footer(['header_only' => true]);
?>
