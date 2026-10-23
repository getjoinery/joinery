<?php
/**
 * The document editor page: /profile/office_editor/edit?file={id}
 *
 * A thin bar (where the file is, its name, Saved / Saving…, Close) over the
 * editor, which fills the rest of the screen. The page asks
 * office_editor_open for the editor's address and a token, then POSTs the token
 * into the editor frame (the token never appears in an address the browser
 * shows). assets/office_editor.js does the work.
 *
 * @version 0.1.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$session = SessionControl::get_instance();
$session->check_permission(0);

$file_id = (int)($_GET['file'] ?? 0);
$file = $file_id > 0 ? DriveHelper::load_file($file_id) : null;
$user_id = (int)$session->get_user_id();
if (!$file || ($file->get('fil_delete_time') !== null && $file->get('fil_delete_time') !== '')
		|| !DriveHelper::can_read(DriveHelper::ENTITY_FILE, $file, $user_id, $session->get_permission())) {
	LibraryFunctions::display_404_page();
	return;
}

// Where Close goes, and the path shown in the bar.
$folder_id = (int)$file->get('fil_fol_folder_id');
$crumbs = array();
if ($folder_id > 0) {
	foreach (array_merge(array_reverse(DriveHelper::ancestors($folder_id)), array($folder_id)) as $fid) {
		$fo = DriveHelper::load_folder($fid);
		if ($fo && DriveHelper::can_read(DriveHelper::ENTITY_FOLDER, $fo, $user_id)) {
			$crumbs[] = (string)$fo->get('fol_name');
		}
	}
}
$close_url = '/drive' . ($folder_id > 0 ? '?folder_id=' . $folder_id : '');

$asset = function ($rel) {
	$path = PathHelper::getIncludePath('plugins/office_editor/assets/' . $rel);
	return '/plugins/office_editor/assets/' . $rel . '?v=' . (is_file($path) ? filemtime($path) : '1');
};

$page = new PublicPage();
$page->public_header(array('title' => (string)$file->get('fil_title')));
$config = array(
	'fileId'   => (int)$file->key,
	'fileName' => (string)$file->get('fil_title'),
	'closeUrl' => $close_url,
	'downloadUrl' => $file->mintSignedUrl('original', 3600),
);
?>
<div class="jy-ui oe-shell" id="oeShell">
	<header class="oe-bar">
		<div class="oe-where">
			<span class="oe-path"><?php echo htmlspecialchars(implode(' / ', array_merge(array('Drive'), $crumbs)), ENT_QUOTES, 'UTF-8'); ?> /</span>
			<span class="oe-name"><?php echo htmlspecialchars((string)$file->get('fil_title'), ENT_QUOTES, 'UTF-8'); ?></span>
		</div>
		<span class="oe-status" id="oeStatus" aria-live="polite"></span>
		<a class="btn btn-secondary oe-close" id="oeClose" href="<?php echo htmlspecialchars($close_url, ENT_QUOTES, 'UTF-8'); ?>">Close</a>
	</header>
	<div class="oe-body">
		<div class="oe-message" id="oeMessage">Opening…</div>
		<iframe class="oe-frame" id="oeFrame" name="oeFrame" title="Document editor" allow="clipboard-read; clipboard-write; fullscreen" hidden></iframe>
		<form id="oeLaunch" method="post" target="oeFrame" hidden>
			<input type="hidden" name="access_token" id="oeToken">
			<input type="hidden" name="access_token_ttl" id="oeTokenTtl">
		</form>
	</div>
</div>
<style>
.oe-shell{position:fixed;inset:0;z-index:2000;display:flex;flex-direction:column;background:var(--jy-surface,#fff)}
.oe-bar{display:flex;align-items:center;gap:.8rem;padding:.4rem .8rem;border-bottom:1px solid rgba(127,127,127,.25);min-height:2.6rem}
.oe-where{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.oe-path{opacity:.6;font-size:.9rem}
.oe-name{font-weight:600}
.oe-status{font-size:.85rem;opacity:.7;white-space:nowrap}
.oe-body{position:relative;flex:1 1 auto;min-height:0}
.oe-frame{position:absolute;inset:0;width:100%;height:100%;border:0}
.oe-message{max-width:34rem;margin:4rem auto;padding:0 1rem;text-align:center;line-height:1.5}
.oe-message .btn{margin:.8rem .3rem 0}
@media(max-width:600px){.oe-path{display:none}}
</style>
<script>window.OFFICE_EDITOR = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES); ?>;</script>
<script defer src="<?php echo htmlspecialchars($asset('office_editor.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php
$page->public_footer();
