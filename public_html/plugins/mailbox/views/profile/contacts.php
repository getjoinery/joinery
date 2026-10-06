<?php
/**
 * Member "Contacts" page, mounted at /profile/mailbox/contacts.
 *
 * Where a member manages the contacts of one mailbox at a time: add one, import
 * a .vcf / .csv, change a contact's name or address, delete one. A contact is
 * permission for that address to reach the member directly, so nothing lands
 * here except by one of those deliberate acts.
 *
 * Reached from the mail page: the pencil on the contacts pane opens this page for
 * the mailbox in view, and the pencil beside a contact opens it on that contact
 * (?mailbox=ID&edit=CONTACT_ID). Also linked from the gear menu and Email
 * settings.
 *
 * The list is server-rendered (mailbox_contacts_page_logic); every change is a
 * call to the mailbox API actions from mailbox_contacts.js, then a reload.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_contacts_page_logic.php'));

$page_vars = process_logic(mailbox_contacts_page_logic(array_merge($_GET, $_POST, $params ?? array())));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Contacts',
	'breadcrumbs' => array(
		'Email' => '/profile/mailbox/mailbox',
		'Contacts' => '',
	),
);
$page->public_header($hoptions, NULL);

$hoptions['app'] = true;
echo PublicPage::BeginPage('Contacts', $hoptions);

$pencil = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
	. ' stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
?>
<div class="jy-ui mbx-cp">
<?php if (!$current): ?>
	<div class="jy-panel">
		<h2>No mailboxes yet</h2>
		<p class="jy-muted">No mailboxes are assigned to your account, so there are no contacts to keep.</p>
	</div>
<?php else: ?>

	<?php if (count($mailboxes) > 1): ?>
	<nav class="mbx-cp-mailboxes" aria-label="Mailbox">
		<?php foreach ($mailboxes as $mb): ?>
			<a href="/profile/mailbox/contacts?mailbox=<?php echo intval($mb['alias_id']); ?>"
				class="mbx-cp-mailbox<?php echo $mb['alias_id'] === $current['alias_id'] ? ' is-current' : ''; ?>"
				<?php echo $mb['alias_id'] === $current['alias_id'] ? 'aria-current="page"' : ''; ?>><?php echo htmlspecialchars($mb['address']); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<?php if ($locked): ?>
	<div class="jy-panel mbx-cp-locked">
		<p>Your contacts for <strong><?php echo htmlspecialchars($current['address']); ?></strong> are sealed to your vault.</p>
		<button type="button" class="btn btn-primary" data-contacts-unlock>Unlock</button>
	</div>
	<?php else: ?>

	<div class="jy-panel">
		<h2>Add a contact</h2>
		<?php
		$formwriter = $page->getFormWriter('contact-add-form', array('action' => '/profile/mailbox/contacts'));
		echo $formwriter->begin_form();
		echo '<div class="mbx-cp-add-fields">';
		$formwriter->textinput('new_contact_name', 'Name', array('placeholder' => 'Optional'));
		$formwriter->textinput('new_contact_email', 'Email', array('placeholder' => 'name@example.com'));
		echo '</div>';
		$formwriter->submitbutton('add_contact', 'Add contact');
		echo $formwriter->end_form();
		?>
		<p class="mbx-cp-note" role="status" data-contacts-add-note hidden></p>

		<h3 class="mbx-cp-subhead">Import</h3>
		<p class="jy-muted">A vCard (.vcf) or a Google Contacts export (.csv). Names and email addresses are kept; the rest is left out.</p>
		<?php
		$formwriter = $page->getFormWriter('contact-import-form', array('action' => '/profile/mailbox/contacts'));
		echo $formwriter->begin_form();
		$formwriter->fileinput('contact_file', 'Contacts file', array('accept' => '.vcf,.csv,text/vcard,text/csv'));
		$formwriter->submitbutton('import_contacts', 'Import');
		echo $formwriter->end_form();
		?>
		<p class="mbx-cp-note" role="status" data-contacts-import-note hidden></p>
	</div>

	<div class="jy-panel">
		<h2><?php echo count($contacts) === 1 ? '1 contact' : count($contacts) . ' contacts'; ?></h2>
		<?php if (!$contacts): ?>
			<p class="jy-muted">No contacts for this mailbox yet.</p>
		<?php else: ?>
		<ul class="mbx-cp-list">
			<?php foreach ($contacts as $c): ?>
			<li class="mbx-cp-row" data-contact-id="<?php echo intval($c['id']); ?>"
				data-name="<?php echo htmlspecialchars($c['name']); ?>"
				data-address="<?php echo htmlspecialchars($c['address']); ?>">
				<span class="mbx-cp-who">
					<span class="mbx-cp-name"><?php echo htmlspecialchars($c['name'] !== '' ? $c['name'] : $c['address']); ?></span>
					<?php if ($c['name'] !== ''): ?><span class="mbx-cp-addr"><?php echo htmlspecialchars($c['address']); ?></span><?php endif; ?>
				</span>
				<button type="button" class="mbx-cp-edit" data-contacts-edit title="Edit"
					aria-label="Edit <?php echo htmlspecialchars($c['name'] !== '' ? $c['name'] : $c['address']); ?>"><?php echo $pencil; ?></button>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
	</div>

	<dialog class="jy-ui mbx-cp-dialog" data-contacts-dialog aria-labelledby="mbx-cp-dialog-title">
		<h2 id="mbx-cp-dialog-title">Edit contact</h2>
		<?php
		$formwriter = $page->getFormWriter('contact-edit-form', array('action' => '/profile/mailbox/contacts'));
		echo $formwriter->begin_form();
		$formwriter->hiddeninput('edit_contact_id', '', array('value' => ''));
		$formwriter->textinput('edit_contact_name', 'Name', array('placeholder' => 'Optional'));
		$formwriter->textinput('edit_contact_email', 'Email');
		?>
		<p class="mbx-cp-note" role="status" data-contacts-edit-note hidden></p>
		<div class="mbx-cp-dialog-actions">
			<?php $formwriter->submitbutton('save_contact', 'Save'); ?>
			<button type="button" class="btn btn-secondary" data-contacts-cancel>Cancel</button>
			<button type="button" class="btn btn-danger mbx-cp-delete" data-contacts-delete>Delete</button>
		</div>
		<?php echo $formwriter->end_form(); ?>
	</dialog>

	<?php endif; ?>
<?php endif; ?>
</div>
<?php
if ($current) {
	echo '<script>window.MAILBOX_CONTACTS = ' . json_encode(array(
		'alias_id' => intval($current['alias_id']),
		'edit_id'  => intval($edit_id),
	)) . ';</script>';
	$css = PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_contacts.css');
	$js = PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_contacts.js');
	echo '<link rel="stylesheet" href="/plugins/mailbox/assets/mailbox_contacts.css?v=' . (is_file($css) ? filemtime($css) : '1') . '">';
	echo '<script src="/plugins/mailbox/assets/mailbox_contacts.js?v=' . (is_file($js) ? filemtime($js) : '1') . '"></script>';
}

echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
