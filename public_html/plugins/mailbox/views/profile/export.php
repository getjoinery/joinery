<?php
/**
 * Member "Download a mailbox" page, mounted at /profile/mailbox/export.
 *
 * Takes a mailbox's mail out: the whole mailbox, or one folder or label, as a
 * zip with one mbox file per folder, which mail programs and other providers
 * read and our own import reads back. Labels, read and starred travel in each
 * message's X-Gmail-Labels header; read and starred also as Status and
 * X-Mozilla-Status, which mail programs read.
 *
 * A Standard mailbox's download is built on the server and the member is
 * emailed when it is ready; a Private one is built while its owner is signed
 * in and unlocked, sealed to their vault; a Fortress one is built here, in
 * this browser, from what only it can open. Asking needs a fresh second
 * factor. Linked from the mail page's Settings menu and the admin mailbox
 * list (?mailbox=).
 *
 * @version 1.1 - doc names the Status and X-Mozilla-Status headers
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_export_page_logic.php'));

$page_vars = process_logic(mailbox_export_page_logic(array_merge($_GET, $_POST, $params ?? array())));
extract($page_vars);

$page = new PublicPage();
// A Fortress mailbox is opened and zipped in this browser.
$fortress = $current && $current['level'] === MailExportRun::LEVEL_FORTRESS;
if ($fortress) {
	$page->needs_vault_client();
}
$hoptions = array(
	'title' => 'Download a mailbox',
	'breadcrumbs' => array(
		'Email' => '/profile/mailbox/mailbox',
		'Download a mailbox' => '',
	),
);
$page->public_header($hoptions, NULL);

$hoptions['app'] = true;
echo PublicPage::BeginPage('Download a mailbox', $hoptions);

$level_words = array(
	MailExportRun::LEVEL_STANDARD => 'It is built on this server, and you get an email when it is ready to download.',
	MailExportRun::LEVEL_PRIVATE  => 'This mailbox is Private, so its download is built while you are signed in with your vault unlocked, and sealed to your vault until you download it. Keep any page of the site open until it is ready.',
	MailExportRun::LEVEL_FORTRESS => 'This mailbox is Fortress, so only this browser can read it: the download is built here, and the server sends only what it cannot read. Keep this page open until it is done.',
);
?>
<div class="jy-ui mbx-ex">
<?php if (!$current): ?>
	<div class="jy-panel">
		<h2>No mailboxes yet</h2>
		<p class="jy-muted">No mailboxes are assigned to your account, so there is no mail to download.</p>
	</div>
<?php else: ?>

	<?php if (count($mailboxes) > 1): ?>
	<nav class="mbx-ex-mailboxes" aria-label="Mailbox">
		<?php foreach ($mailboxes as $mb): ?>
			<a href="/profile/mailbox/export?mailbox=<?php echo intval($mb['alias_id']); ?>"
				class="mbx-ex-mailbox<?php echo $mb['alias_id'] === $current['alias_id'] ? ' is-current' : ''; ?>"
				<?php echo $mb['alias_id'] === $current['alias_id'] ? 'aria-current="page"' : ''; ?>><?php echo htmlspecialchars($mb['address']); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<div class="jy-panel">
		<h2><?php echo htmlspecialchars($current['address']); ?></h2>
		<p>You get a zip with one mailbox file (mbox) for each folder: Inbox, Archive, Sent, Spam and Trash.
			Labels, read and starred travel with each message, the way Gmail's export writes them, so Thunderbird,
			Apple Mail and most providers can import it, and so can this site. Drafts are not included.</p>
		<p class="jy-muted"><?php echo htmlspecialchars($level_words[$current['level']]); ?>
			<?php if ($retention_days > 0): ?>A finished download is deleted after <?php echo intval($retention_days); ?> day<?php echo $retention_days === 1 ? '' : 's'; ?>.<?php endif; ?></p>
		<?php
		$options = array('' => 'The whole mailbox (' . number_format(intval($current['count'])) . ' messages)');
		foreach ($current['folders'] as $key => $name) {
			$options[$key] = (strpos($key, 'label:') === 0 ? 'Label: ' : '') . $name;
		}
		$formwriter = $page->getFormWriter('mail-export-form', array('action' => '/profile/mailbox/export'));
		echo $formwriter->begin_form();
		$formwriter->dropinput('export_folder', 'What to download', array('options' => $options));
		$formwriter->submitbutton('start_export', $current['level'] === MailExportRun::LEVEL_FORTRESS ? 'Download' : 'Build the download');
		echo $formwriter->end_form();
		?>
		<p class="mbx-ex-note" role="status" data-export-note hidden></p>
	</div>

	<div class="jy-panel">
		<h2>Your downloads</h2>
		<div data-export-list></div>
	</div>
<?php endif; ?>
</div>
<?php
if ($current) {
	$asset = function ($rel) {
		$path = PathHelper::getIncludePath($rel);
		return '/' . $rel . '?v=' . (is_file($path) ? filemtime($path) : '1');
	};
	echo '<script>window.MAILBOX_EXPORT = ' . json_encode(array(
		'alias_id' => intval($current['alias_id']),
		'address'  => (string)$current['address'],
		'level'    => (string)$current['level'],
		'exports'  => $exports,
	), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
	echo '<link rel="stylesheet" href="' . htmlspecialchars($asset('plugins/mailbox/assets/mailbox_export.css')) . '">';
	echo '<script src="' . htmlspecialchars($asset('assets/js/passkeys.js')) . '"></script>';
	if ($fortress) {
		// The reader's Fortress module opens each message here; it reads its
		// part URL from the reader's config.
		echo '<script>window.MAILBOX_READER = ' . json_encode(array('attachmentUrlBase' => '/profile/mailbox/attachment')) . ';</script>';
		echo '<script src="' . htmlspecialchars($asset('plugins/mailbox/assets/mailbox_fortress.js')) . '"></script>';
		echo '<script src="' . htmlspecialchars($asset('plugins/mailbox/assets/mailbox_mime_build.js')) . '"></script>';
		echo '<script src="' . htmlspecialchars($asset('assets/js/zip-writer.js')) . '"></script>';
	}
	echo '<script src="' . htmlspecialchars($asset('plugins/mailbox/assets/mailbox_export.js')) . '"></script>';
}

echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
