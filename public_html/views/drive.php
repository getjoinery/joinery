<?php
/**
 * Drive — member file storage page.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('drive_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(drive_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header(array('title' => $page_vars['title'] ?? 'Drive'));

$initial = $page_vars['initial'] ?? array('items' => array());
$config = array(
	'shareLinksEnabled' => (bool)($page_vars['share_links_enabled'] ?? false),
	'maxFileBytes'      => (int)($page_vars['max_file_bytes'] ?? 0),
	'quotaBytes'        => (int)($page_vars['quota_bytes'] ?? 0),
	'chunkBytes'        => (int)($page_vars['chunk_bytes'] ?? 8388608),
	'userId'            => (int)SessionControl::get_instance()->get_user_id(),
	'passkeysEnabled'   => (bool)($page_vars['passkeys_enabled'] ?? false),
	'vaultScope'        => 'drive',
);

// Dialog forms are FormWriter-built; drive.js intercepts submit and calls the API.
$new_folder_fw = $page->getFormWriter('drive_new_folder', array('action' => '/drive', 'method' => 'POST'));
$rename_fw     = $page->getFormWriter('drive_rename', array('action' => '/drive', 'method' => 'POST'));
$move_fw       = $page->getFormWriter('drive_move', array('action' => '/drive', 'method' => 'POST'));
?>
<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container">
		<div class="jy-page-header">
			<div class="jy-page-header-bar">
				<h1>Drive</h1>
				<nav class="jy-breadcrumbs" aria-label="breadcrumb">
					<ol>
						<li><a href="/">Home</a></li>
						<li class="active">Drive</li>
					</ol>
				</nav>
			</div>
		</div>

		<div class="drv-app" id="drvApp">
			<aside class="drv-rail">
				<nav class="drv-nav" id="drvNav">
					<button type="button" class="drv-nav-item active" data-view="mine">My Drive</button>
					<button type="button" class="drv-nav-item" data-view="shared">Shared with me</button>
					<button type="button" class="drv-nav-item" data-view="starred">Starred</button>
					<button type="button" class="drv-nav-item" data-view="trash">Trash</button>
				</nav>
				<div class="drv-meter" id="drvMeter">
					<div class="drv-meter-track"><div class="drv-meter-fill" id="drvMeterFill"></div></div>
					<div class="drv-meter-label" id="drvMeterLabel">&nbsp;</div>
					<div id="drvUpgrade" class="drv-upgrade" hidden></div>
				</div>
			</aside>

			<main class="drv-main">
				<div class="drv-toolbar">
					<nav class="drv-breadcrumb" id="drvBreadcrumb" aria-label="folder path"></nav>
					<div class="drv-actions">
						<input type="search" id="drvSearch" class="drv-search" placeholder="Search files" aria-label="Search files">
						<button type="button" id="drvNewFolderBtn" class="jy-btn jy-btn-secondary">New folder</button>
						<button type="button" id="drvUploadBtn" class="jy-btn jy-btn-primary">Upload</button>
						<button type="button" id="drvViewToggle" class="jy-btn jy-btn-secondary" title="Toggle list / grid" aria-label="Toggle list or grid view">Grid</button>
					</div>
				</div>

				<div class="drv-dropzone" id="drvDropzone">
					<div class="drv-items drv-view-list" id="drvItems" role="list"></div>
					<div class="drv-empty" id="drvEmpty" hidden>Nothing here yet.</div>
					<div class="drv-drop-hint" id="drvDropHint">Drop files to upload</div>
				</div>

				<div class="drv-uploads" id="drvUploads" aria-live="polite"></div>
			</main>
		</div>
	</div>
</section>
</div>

<input type="file" id="drvFileInput" multiple hidden>
<div class="drv-menu" id="drvMenu" role="menu" hidden></div>

<dialog id="drvNewFolderDialog" class="drv-dialog">
	<h3>New folder</h3>
	<?php
	$new_folder_fw->begin_form();
	$new_folder_fw->textinput('drv_new_folder_name', 'Folder name', array('id' => 'drvNewFolderName', 'required' => true, 'maxlength' => 255));
	?>
	<label class="drv-enc-opt" id="drvNewFolderEncWrap">
		<input type="checkbox" id="drvNewFolderEnc">
		<span>Encrypted vault folder — files inside are end-to-end encrypted in your browser. Lose every unlocker and they are unrecoverable.</span>
	</label>
	<div class="drv-dialog-actions">
		<button type="button" class="jy-btn jy-btn-secondary" data-close>Cancel</button>
		<button type="submit" class="jy-btn jy-btn-primary">Create</button>
	</div>
	<?php $new_folder_fw->end_form(); ?>
</dialog>

<dialog id="drvVaultDialog" class="drv-dialog">
	<div id="drvVaultSetup" hidden>
		<h3>Set up your Drive vault</h3>
		<p style="font-size:.9rem;opacity:.85;">Encrypted files are locked with a key only your devices ever hold. Choose how you'll unlock it. If you lose every unlocker, encrypted files are permanently gone — there is no recovery.</p>
		<label class="drv-enc-opt"><input type="checkbox" id="drvVaultAck"> <span>I understand encrypted files are unrecoverable if I lose all unlockers.</span></label>
		<div id="drvVaultSetupPpWrap" hidden style="margin-top:.5rem;">
			<input type="password" id="drvVaultSetupPp" class="drv-search" style="width:100%;" placeholder="Passphrase (min 10 chars)" autocomplete="new-password">
		</div>
		<div class="drv-dialog-actions" style="justify-content:flex-start;flex-wrap:wrap;">
			<button type="button" class="jy-btn jy-btn-primary" id="drvVaultSetupPasskey">Set up with a passkey</button>
			<button type="button" class="jy-btn jy-btn-secondary" id="drvVaultSetupPpToggle">Use a passphrase</button>
			<button type="button" class="jy-btn jy-btn-primary" id="drvVaultSetupPpGo" hidden>Set up with passphrase</button>
		</div>
		<div id="drvVaultRecovery" hidden style="margin-top:.6rem;">
			<p style="font-size:.9rem;"><strong>Save your recovery keys</strong> — shown once. They are the only way back in if you lose your passkey and passphrase.</p>
			<pre id="drvVaultRecoveryCodes" style="white-space:pre-wrap;font-size:.8rem;background:rgba(127,127,127,.1);padding:.6rem;border-radius:8px;"></pre>
			<button type="button" class="jy-btn jy-btn-primary" id="drvVaultRecoveryDone">I've saved them — continue</button>
		</div>
	</div>
	<div id="drvVaultUnlock" hidden>
		<h3>Unlock your Drive vault</h3>
		<button type="button" class="jy-btn jy-btn-primary jy-btn-block" id="drvVaultUnlockPasskey" style="width:100%;margin-bottom:.5rem;">Unlock with a passkey</button>
		<div style="margin:.4rem 0;">
			<input type="password" id="drvVaultUnlockPp" class="drv-search" style="width:100%;" placeholder="Passphrase" autocomplete="current-password">
			<button type="button" class="jy-btn jy-btn-secondary" id="drvVaultUnlockPpGo" style="margin-top:.35rem;">Unlock with passphrase</button>
		</div>
		<div style="margin:.4rem 0;">
			<input type="text" id="drvVaultUnlockRec" class="drv-search" style="width:100%;" placeholder="Recovery key" autocomplete="off">
			<button type="button" class="jy-btn jy-btn-secondary" id="drvVaultUnlockRecGo" style="margin-top:.35rem;">Unlock with recovery key</button>
		</div>
	</div>
	<p class="drv-vault-error" id="drvVaultError" role="alert" hidden style="color:#e0533d;font-size:.85rem;"></p>
	<div class="drv-dialog-actions">
		<button type="button" class="jy-btn jy-btn-secondary" data-close>Cancel</button>
	</div>
</dialog>

<dialog id="drvRenameDialog" class="drv-dialog">
	<h3>Rename</h3>
	<?php
	$rename_fw->begin_form();
	$rename_fw->textinput('drv_rename_name', 'New name', array('id' => 'drvRenameName', 'required' => true, 'maxlength' => 255));
	?>
	<div class="drv-dialog-actions">
		<button type="button" class="jy-btn jy-btn-secondary" data-close>Cancel</button>
		<button type="submit" class="jy-btn jy-btn-primary">Save</button>
	</div>
	<?php $rename_fw->end_form(); ?>
</dialog>

<dialog id="drvMoveDialog" class="drv-dialog">
	<h3>Move to</h3>
	<?php
	$move_fw->begin_form();
	$move_fw->dropinput('drv_move_parent', 'Destination', array('id' => 'drvMoveParent', 'options' => array('0' => 'My Drive (root)')));
	?>
	<div class="drv-dialog-actions">
		<button type="button" class="jy-btn jy-btn-secondary" data-close>Cancel</button>
		<button type="submit" class="jy-btn jy-btn-primary">Move</button>
	</div>
	<?php $move_fw->end_form(); ?>
</dialog>

<dialog id="drvConfirmDialog" class="drv-dialog">
	<h3 id="drvConfirmTitle">Delete forever?</h3>
	<p id="drvConfirmBody"></p>
	<div class="drv-dialog-actions">
		<button type="button" class="jy-btn jy-btn-secondary" data-close>Cancel</button>
		<button type="button" class="jy-btn jy-btn-danger" id="drvConfirmOk">Delete forever</button>
	</div>
</dialog>

<dialog id="drvVersionsDialog" class="drv-dialog">
	<h3 id="drvVersionsTitle">Version history</h3>
	<div id="drvVersionsBody" class="drv-versions"></div>
	<div class="drv-dialog-actions">
		<button type="button" class="jy-btn jy-btn-secondary" data-close>Close</button>
	</div>
</dialog>

<dialog id="drvShareDialog" class="drv-dialog">
	<h3 id="drvShareTitle">Share</h3>

	<h4 class="drv-share-h">People with access</h4>
	<div id="drvShareGrants" class="drv-share-list"></div>
	<?php
	$add_person_fw = $page->getFormWriter('drive_add_person', array('action' => '/drive', 'method' => 'POST'));
	$add_person_fw->begin_form();
	$add_person_fw->textinput('drv_share_email', 'Add by email', array('id' => 'drvShareEmail', 'placeholder' => 'name@example.com'));
	$add_person_fw->dropinput('drv_share_role', 'Role', array('id' => 'drvShareRole', 'options' => array('viewer' => 'Viewer', 'editor' => 'Editor')));
	?>
	<div class="drv-dialog-actions" style="justify-content:flex-start;">
		<button type="submit" class="jy-btn jy-btn-secondary">Add person</button>
	</div>
	<?php $add_person_fw->end_form(); ?>

	<div id="drvShareLinksSection" hidden>
		<hr>
		<h4 class="drv-share-h">Share link</h4>
		<div id="drvShareLinks" class="drv-share-list"></div>
		<div id="drvNewLink" class="drv-new-link" hidden></div>
		<?php
		$link_fw = $page->getFormWriter('drive_create_link', array('action' => '/drive', 'method' => 'POST'));
		$link_fw->begin_form();
		$link_fw->numberinput('drv_link_expires', 'Expires in days (0 = never)', array('id' => 'drvLinkExpires', 'value' => '0', 'min' => '0'));
		$link_fw->passwordinput('drv_link_pw', 'Password (optional)', array('id' => 'drvLinkPw'));
		?>
		<div class="drv-dialog-actions" style="justify-content:flex-start;">
			<button type="submit" class="jy-btn jy-btn-secondary">Create link</button>
		</div>
		<?php $link_fw->end_form(); ?>
	</div>

	<div class="drv-dialog-actions">
		<button type="button" class="jy-btn jy-btn-primary" data-close>Done</button>
	</div>
</dialog>

<style>
.drv-app{display:flex;gap:1.5rem;align-items:flex-start}
.drv-rail{flex:0 0 210px;position:sticky;top:1rem}
.drv-nav{display:flex;flex-direction:column;gap:.25rem}
.drv-nav-item{text-align:left;background:transparent;border:0;padding:.55rem .75rem;border-radius:8px;cursor:pointer;font:inherit;color:inherit}
.drv-nav-item:hover{background:rgba(127,127,127,.12)}
.drv-nav-item.active{background:rgba(80,120,255,.16);font-weight:600}
.drv-meter{margin-top:1.25rem;font-size:.82rem}
.drv-meter-track{height:8px;border-radius:6px;background:rgba(127,127,127,.2);overflow:hidden}
.drv-meter-fill{height:100%;width:0;background:#5078ff;transition:width .3s}
.drv-meter-fill.full{background:#e0533d}
.drv-meter-label{margin-top:.4rem;opacity:.8}
.drv-upgrade{margin-top:.5rem}
.drv-main{flex:1 1 auto;min-width:0}
.drv-toolbar{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;justify-content:space-between;margin-bottom:.75rem}
.drv-breadcrumb{display:flex;flex-wrap:wrap;gap:.3rem;align-items:center;font-size:.95rem}
.drv-breadcrumb a{cursor:pointer;text-decoration:none;opacity:.85}
.drv-breadcrumb a:hover{text-decoration:underline}
.drv-breadcrumb .sep{opacity:.4}
.drv-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.drv-search{padding:.4rem .6rem;border:1px solid rgba(127,127,127,.35);border-radius:8px;font:inherit}
.drv-dropzone{position:relative;border:2px dashed transparent;border-radius:12px;min-height:200px;transition:border-color .15s,background .15s}
.drv-dropzone.dragover{border-color:#5078ff;background:rgba(80,120,255,.06)}
.drv-drop-hint{display:none;position:absolute;inset:0;align-items:center;justify-content:center;font-weight:600;color:#5078ff;pointer-events:none}
.drv-dropzone.dragover .drv-drop-hint{display:flex}
.drv-items.drv-view-list{display:flex;flex-direction:column}
.drv-items.drv-view-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem}
.drv-item{display:flex;align-items:center;gap:.7rem;padding:.55rem .6rem;border-radius:8px;cursor:pointer;position:relative}
.drv-item:hover{background:rgba(127,127,127,.10)}
.drv-view-grid .drv-item{flex-direction:column;text-align:center;padding:1rem .5rem;border:1px solid rgba(127,127,127,.18)}
.drv-item-icon{flex:0 0 auto;width:34px;height:34px;display:flex;align-items:center;justify-content:center;color:#5078ff}
.drv-view-grid .drv-item-icon{width:56px;height:56px}
.drv-item-thumb{width:34px;height:34px;object-fit:cover;border-radius:6px}
.drv-view-grid .drv-item-thumb{width:100%;height:96px}
.drv-item-name{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.drv-view-grid .drv-item-name{white-space:normal;word-break:break-word;font-size:.9rem}
.drv-item-meta{flex:0 0 auto;font-size:.8rem;opacity:.65}
.drv-view-grid .drv-item-meta{display:none}
.drv-star{background:transparent;border:0;cursor:pointer;color:#c9a227;padding:.2rem;line-height:0}
.drv-star.off{color:rgba(127,127,127,.5)}
.drv-item-more{background:transparent;border:0;cursor:pointer;padding:.25rem;line-height:0;opacity:.6}
.drv-item-more:hover{opacity:1}
.drv-empty{padding:3rem 1rem;text-align:center;opacity:.6}
.drv-menu{position:absolute;z-index:50;min-width:170px;background:var(--jy-surface,#fff);border:1px solid rgba(127,127,127,.25);border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.18);padding:.35rem;display:flex;flex-direction:column}
.drv-menu button{background:transparent;border:0;text-align:left;padding:.5rem .65rem;border-radius:6px;cursor:pointer;font:inherit;color:inherit}
.drv-menu button:hover{background:rgba(127,127,127,.14)}
.drv-menu button.danger{color:#e0533d}
.drv-dialog{border:0;border-radius:14px;padding:1.4rem;max-width:420px;width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.drv-dialog::backdrop{background:rgba(0,0,0,.4)}
.drv-dialog h3{margin:0 0 .8rem}
.drv-dialog-actions{display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem}
.jy-btn-danger{background:#e0533d;color:#fff;border:0}
.drv-uploads{margin-top:1rem;display:flex;flex-direction:column;gap:.4rem}
.drv-upload-row{display:flex;align-items:center;gap:.6rem;font-size:.85rem}
.drv-upload-bar{flex:1 1 auto;height:6px;border-radius:4px;background:rgba(127,127,127,.2);overflow:hidden}
.drv-upload-bar > span{display:block;height:100%;width:0;background:#5078ff;transition:width .2s}
.drv-upload-row.error{color:#e0533d}
.drv-upload-row.done .drv-upload-bar > span{background:#3ba55d}
.drv-versions{display:flex;flex-direction:column;gap:.5rem;max-height:50vh;overflow:auto}
.drv-version-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.4rem 0;border-bottom:1px solid rgba(127,127,127,.15)}
.drv-version-label{font-size:.88rem}
.drv-version-empty{opacity:.6;font-size:.88rem}
.drv-share-h{margin:.6rem 0 .4rem;font-size:.95rem}
.drv-share-list{display:flex;flex-direction:column;gap:.35rem;margin-bottom:.6rem}
.drv-share-row{display:flex;align-items:center;justify-content:space-between;gap:.6rem;font-size:.88rem}
.drv-share-row select{font:inherit;padding:.2rem}
.drv-share-empty{opacity:.55;font-size:.85rem}
.drv-new-link{background:rgba(80,120,255,.08);border-radius:8px;padding:.5rem;margin-bottom:.5rem;word-break:break-all;font-size:.82rem}
.drv-new-link input{width:100%;font:inherit;padding:.3rem;border:1px solid rgba(127,127,127,.3);border-radius:6px}
.drv-link-del{background:transparent;border:0;color:#e0533d;cursor:pointer}
.drv-enc-opt{display:flex;gap:.5rem;align-items:flex-start;margin:.6rem 0;font-size:.82rem;opacity:.9;cursor:pointer}
.drv-enc-opt input{margin-top:.15rem}
.drv-item-lock{margin-left:.35rem;opacity:.6;font-size:.85em}
.drv-crumb-lock{opacity:.7}
.jy-btn-block{display:block;width:100%}
@media(max-width:720px){.drv-app{flex-direction:column}.drv-rail{position:static;width:100%;flex-basis:auto}.drv-nav{flex-direction:row;flex-wrap:wrap}}
</style>

<script>
window.DRIVE_INITIAL = <?php echo json_encode($initial, JSON_UNESCAPED_SLASHES); ?>;
window.DRIVE_CONFIG = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script defer src="/assets/js/vault-crypto.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/vault-crypto.js')) ?: '1'; ?>"></script>
<script defer src="/assets/js/vault-keyring.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/vault-keyring.js')) ?: '1'; ?>"></script>
<script defer src="/assets/js/drive-crypto.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/drive-crypto.js')) ?: '1'; ?>"></script>
<script defer src="/assets/js/drive.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/drive.js')) ?: '1'; ?>"></script>

<?php
$page->public_footer();
?>
