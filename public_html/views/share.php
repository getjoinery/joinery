<?php
/**
 * Public share page for /s/{token}. Anonymous-safe.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('share_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(share_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header(array('title' => $page_vars['title'] ?? 'Shared'));

function share_human_bytes($n) {
	$n = (int)$n;
	if ($n < 1024) return $n . ' B';
	$u = array('KB', 'MB', 'GB', 'TB'); $i = -1;
	do { $n /= 1024; $i++; } while ($n >= 1024 && $i < count($u) - 1);
	return round($n, $n < 10 ? 1 : 0) . ' ' . $u[$i];
}
?>
<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container">
		<div class="jy-narrow-lg">

		<?php if (!empty($page_vars['share_error'])): ?>
			<div class="jy-page-header"><div class="jy-page-header-bar"><h1>Unavailable</h1></div></div>
			<p><?php echo htmlspecialchars($page_vars['share_error']); ?></p>

		<?php elseif (!empty($page_vars['need_password'])): ?>
			<div class="jy-page-header"><div class="jy-page-header-bar"><h1>Password required</h1></div></div>
			<?php if (!empty($page_vars['password_error'])): ?>
				<p class="jy-error" style="color:#e0533d;"><?php echo htmlspecialchars($page_vars['password_error']); ?></p>
			<?php endif; ?>
			<?php
			$fw = $page->getFormWriter('drive_share_password', array('action' => '/s/' . rawurlencode($page_vars['token']), 'method' => 'POST'));
			$fw->begin_form();
			$fw->passwordinput('drv_link_password', 'Password', array('required' => true));
			$fw->submitbutton('btn_submit', 'View', array('class' => 'jy-btn jy-btn-primary'));
			$fw->end_form();
			?>

		<?php elseif (($page_vars['entity_type'] ?? '') === 'file' && !empty($page_vars['file']['encrypted'])): $f = $page_vars['file']; ?>
			<div class="jy-page-header"><div class="jy-page-header-bar"><h1 id="shareEncName">Encrypted file</h1></div></div>
			<div class="share-file-card" style="border:1px solid rgba(127,127,127,.2);border-radius:12px;padding:1.5rem;text-align:center;">
				<div id="shareEncThumb"></div>
				<p style="opacity:.7;margin:.4rem 0;">🔒 <span id="shareEncMeta"><?php echo share_human_bytes($f['size']); ?> · encrypted</span></p>
				<p id="shareEncStatus" style="opacity:.7;">This file is end-to-end encrypted. It is decrypted in your browser using the key in your link.</p>
				<p><button type="button" class="jy-btn jy-btn-primary" id="shareEncDownload" disabled>Decrypt &amp; download</button></p>
				<p id="shareEncError" style="color:#e0533d;" hidden></p>
			</div>
			<script>
			window.SHARE_ENC = {
				downloadUrl: <?php echo json_encode($f['download_url'], JSON_UNESCAPED_SLASHES); ?>,
				metadata: <?php echo json_encode($f['encrypted_metadata'], JSON_UNESCAPED_SLASHES); ?>,
				size: <?php echo (int)$f['size']; ?>
			};
			</script>
			<script defer src="/assets/js/vault-crypto.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/vault-crypto.js')) ?: '1'; ?>"></script>
			<script defer src="/assets/js/drive-crypto.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/drive-crypto.js')) ?: '1'; ?>"></script>
			<script defer src="/assets/js/share-decrypt.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/share-decrypt.js')) ?: '1'; ?>"></script>

		<?php elseif (($page_vars['entity_type'] ?? '') === 'file'): $f = $page_vars['file']; ?>
			<div class="jy-page-header"><div class="jy-page-header-bar"><h1><?php echo htmlspecialchars($f['name']); ?></h1></div></div>
			<div class="share-file-card" style="border:1px solid rgba(127,127,127,.2);border-radius:12px;padding:1.5rem;text-align:center;">
				<?php if (!empty($f['preview_url'])): ?>
					<img src="<?php echo htmlspecialchars($f['preview_url']); ?>" alt="" style="max-width:100%;max-height:60vh;border-radius:8px;margin-bottom:1rem;">
				<?php endif; ?>
				<p style="opacity:.7;margin:.4rem 0;"><?php echo share_human_bytes($f['size']); ?> · <?php echo htmlspecialchars($f['mime'] ?: 'file'); ?></p>
				<p><a class="jy-btn jy-btn-primary" href="<?php echo htmlspecialchars($f['download_url']); ?>" download>Download</a></p>
			</div>

		<?php elseif (($page_vars['entity_type'] ?? '') === 'folder'): ?>
			<div class="jy-page-header"><div class="jy-page-header-bar"><h1><?php echo htmlspecialchars($page_vars['folder_name']); ?></h1></div></div>
			<nav class="share-breadcrumb" style="margin-bottom:1rem;display:flex;gap:.35rem;flex-wrap:wrap;">
				<?php foreach ($page_vars['breadcrumb'] as $i => $c): ?>
					<?php if ($i > 0): ?><span style="opacity:.4;">/</span><?php endif; ?>
					<a href="/s/<?php echo rawurlencode($page_vars['token']); ?>?folder=<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if (empty($page_vars['items'])): ?>
				<p style="opacity:.6;">This folder is empty.</p>
			<?php else: ?>
				<ul class="share-list" style="list-style:none;padding:0;margin:0;">
					<?php foreach ($page_vars['items'] as $it): ?>
						<li style="display:flex;align-items:center;gap:.6rem;padding:.5rem .4rem;border-bottom:1px solid rgba(127,127,127,.12);">
							<?php if ($it['type'] === 'folder'): ?>
								<span aria-hidden="true">📁</span>
								<a href="/s/<?php echo rawurlencode($page_vars['token']); ?>?folder=<?php echo (int)$it['id']; ?>" style="flex:1;"><?php echo htmlspecialchars($it['name']); ?></a>
							<?php else: ?>
								<span aria-hidden="true"><?php echo $it['is_image'] ? '🖼️' : '📄'; ?></span>
								<span style="flex:1;"><?php echo htmlspecialchars($it['name']); ?></span>
								<a class="jy-btn jy-btn-secondary" href="<?php echo htmlspecialchars($it['download_url']); ?>" download>Download</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endif; ?>

		</div>
	</div>
</section>
</div>
<?php
$page->public_footer();
?>
