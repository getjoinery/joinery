<?php
/**
 * Forwarding confirmation (/mail/forward-confirm?t=...): the person at a
 * forwarding destination agrees to receive forwards, or ignores the request.
 * See logic/forward_confirm_logic.php.
 *
 * @version 1.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/forward_confirm_logic.php'));

// Only a press of the button confirms: a link scanner's GET carries no confirm.
$input = array('t' => $_GET['t'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	$input['t'] = $_POST['t'] ?? $input['t'];
	$input['confirm'] = !empty($_POST['confirm_forwarding']);
}
$page_vars = process_logic(forward_confirm_logic($input));
extract($page_vars);

$page = new PublicPage();
$page->public_header(array('is_valid_page' => true, 'title' => 'Confirm forwarding'), NULL);
echo PublicPage::BeginPage('Confirm forwarding', array());
?>
<div class="jy-ui" style="max-width: 36rem; margin: 2rem auto;">
<?php if (!$is_valid_page): ?>
	<h2>This link is not valid</h2>
	<p>It may be incomplete, or a newer confirmation message has replaced it. Nothing will be forwarded to you unless you confirm through the most recent message.</p>
<?php elseif ($confirmed_now || $already_confirmed): ?>
	<h2>Forwarding confirmed</h2>
	<p>Mail for <strong><?php echo htmlspecialchars($source); ?></strong> will be forwarded to <strong><?php echo htmlspecialchars($destination); ?></strong>.</p>
	<p>To stop it, ask whoever runs <?php echo htmlspecialchars($source); ?> to remove your address.</p>
<?php else: ?>
	<h2>Forward mail to you?</h2>
	<p>Someone has asked for mail sent to <strong><?php echo htmlspecialchars($source); ?></strong> to be forwarded to <strong><?php echo htmlspecialchars($destination); ?></strong>.</p>
	<p>Nothing is forwarded unless you confirm. If you did not expect this, close this page.</p>
	<?php
	$form = $page->getFormWriter('forward_confirm_form', array('action' => '/mail/forward-confirm'));
	$form->begin_form();
	$form->hiddeninput('t', '', array('value' => $token));
	$form->hiddeninput('confirm_forwarding', '', array('value' => '1'));
	$form->submitbutton('btn_confirm', 'Confirm');
	$form->end_form();
	?>
<?php endif; ?>
</div>
<?php
echo PublicPage::EndPage();
$page->public_footer(array('track' => TRUE));
