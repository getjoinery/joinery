<?php
/**
 * Boards - Make a card from an email (/profile/boards/from_message).
 *
 * The target of the mailbox reader's Make a card button. A plain form: pick the list
 * the card goes on, check the title, make it.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/boards/logic/boards_from_message_logic.php'));

$page_vars = process_logic(boards_from_message_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Make a card',
	'breadcrumbs' => array('Boards' => '/profile/boards', 'Make a card' => ''),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Make a card', $hoptions);
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<div class="jy-ui jy-boards-frompage">
<?php if (!empty($message_summary)) { ?>
	<p class="jy-boards-muted">
		From <strong><?php echo $h($message_summary['from']); ?></strong><br>
		<?php echo $h($message_summary['subject'] !== '' ? $message_summary['subject'] : '(no subject)'); ?>
	</p>
<?php } ?>
<?php if (!empty($error)) { ?>
	<div class="jy-callout" role="alert"><p><?php echo $h($error); ?></p></div>
<?php } ?>
<?php if (!empty($info)) { ?>
	<div class="jy-callout"><p><?php echo $h($info); ?>
		<?php if (!empty($existing_url)) { ?><a href="<?php echo $h($existing_url); ?>">Open the card</a><?php } ?></p></div>
<?php } ?>
<?php if (!empty($targets) && empty($info)) {
	$options = array();
	foreach ($targets as $t) {
		foreach ($t['lists'] as $l) {
			$options[(int)$l['id']] = $t['name'] . ' — ' . $l['name'];
		}
	}
	$formwriter = $page->getFormWriter('boards_make_card_form', array('action' => '/profile/boards/from_message?message_id=' . (int)$message_id, 'method' => 'POST'));
	$formwriter->begin_form();
	$formwriter->hiddeninput('boards_make_card', array('value' => '1'));
	$formwriter->hiddeninput('message_id', array('value' => (int)$message_id));
	$formwriter->dropinput('list_id', 'Put it on', array('options' => $options, 'value' => $chosen_list ?: array_key_first($options)));
	$formwriter->textinput('title', 'Card title', array('value' => $default_title, 'maxlength' => 255));
	$formwriter->submitbutton('make', 'Make the card');
	$formwriter->end_form();
?>
	<p class="jy-boards-muted">The card gets the email's text and its attachments, and a link back to the email for people who can already open that mailbox.</p>
<?php } ?>
</div>
<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer(['track' => TRUE]);
