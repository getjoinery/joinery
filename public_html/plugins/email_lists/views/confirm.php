<?php
	// The token is in the address: nothing this page loads may be told it.
	header('Referrer-Policy: no-referrer');

	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('confirm_logic.php', 'logic', 'system', null, 'email_lists', false));

	$page_vars = process_logic(confirm_logic(array_merge($_GET, $_POST, $params ?? [])));
	$page = new PublicPage();
	$page->public_header([
		'is_valid_page' => $is_valid_page ?? false,
		'title'         => 'Confirm Your Subscription',
		'header_only'   => true,
	]);
	$lists = implode(', ', array_map('htmlspecialchars', $page_vars['lists']));
?>

<div class="jy-ui">
<div class="auth-page">
	<div class="auth-card">
		<div class="text-center">
			<?php if ($page_vars['state'] === 'confirm'): ?>
				<h3>Confirm Your Subscription</h3>
				<p class="text-muted">Start sending <strong><?php echo $lists; ?></strong> to <?php echo htmlspecialchars($page_vars['email']); ?>?</p>
				<?php
				$formwriter = $page->getFormWriter('form1', ['action' => '/email_lists/confirm']);
				$formwriter->begin_form();
				$formwriter->hiddeninput('t', '', ['value' => $page_vars['token']]);
				$formwriter->submitbutton('btn_submit', 'Confirm');
				echo $formwriter->end_form();
				?>
			<?php elseif ($page_vars['state'] === 'done'): ?>
				<h3>You're Subscribed</h3>
				<p class="text-muted">You will get <strong><?php echo $lists; ?></strong>. Every email has a link to stop.</p>
				<a href="/" class="btn btn-primary">Go to the site</a>
			<?php else: ?>
				<h3>Confirm Your Subscription</h3>
				<p class="text-muted"><?php echo htmlspecialchars($page_vars['message']); ?></p>
				<a href="/lists" class="btn btn-primary">Mailing lists</a>
			<?php endif; ?>
		</div>
	</div>
</div>
</div>

<?php
	$page->public_footer(['header_only' => true]);
?>
