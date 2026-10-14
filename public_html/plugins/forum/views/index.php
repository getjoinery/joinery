<?php
/**
 * Forum home — /forum. The categories the visitor may read, each with its
 * counts and latest topic, and the search box.
 *
 * Signed-out visitors see the public categories; this page is then served
 * from the static page cache, which ForumCache clears whenever a list changes.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

if (!Forum::isActive()) {
	LibraryFunctions::display_404_page();
}

$viewer_id = Forum::viewerId();
$rows = ForumPages::categories($viewer_id);
$is_moderator = Forum::isModerator($viewer_id);

$page = new PublicPage();
$page->public_header(array(
	'is_valid_page'    => $is_valid_page ?? true,
	'title'            => 'Forum',
	'meta_description' => 'Discussions on ' . Globalvars::get_instance()->get_setting('site_name', true, true) . '.',
));
echo PublicPage::BeginPage('Forum');
?>
<div class="jy-ui forum">
	<div class="forum-toolbar">
		<?php ForumView::searchForm($page); ?>
		<?php if ($is_moderator): ?>
			<a class="btn btn-outline" href="/plugins/forum/admin/admin_forum_categories">Manage categories</a>
		<?php endif; ?>
	</div>

	<?php if (!$rows): ?>
		<div class="forum-empty">
			<p>There is nothing to read here yet.</p>
			<?php if ($is_moderator): ?>
				<p><a class="btn btn-primary" href="/plugins/forum/admin/admin_forum_category">Create the first category</a></p>
			<?php elseif (!$viewer_id): ?>
				<p><a href="/login?return=<?php echo rawurlencode('/forum'); ?>">Sign in</a> to see the members' categories.</p>
			<?php endif; ?>
		</div>
	<?php else: ?>
		<ul class="forum-categories">
			<?php foreach ($rows as $row):
				$category = $row['category'];
				$latest = $row['latest'];
			?>
				<li class="forum-category">
					<div class="forum-category-main">
						<a class="forum-category-name" href="<?php echo htmlspecialchars($category->url()); ?>"><?php echo htmlspecialchars((string)$category->get('fcg_name')); ?></a>
						<?php echo ForumView::accessBadge($category); ?>
						<?php if ($category->get('fcg_description')): ?>
							<p class="forum-category-desc"><?php echo htmlspecialchars((string)$category->get('fcg_description')); ?></p>
						<?php endif; ?>
					</div>
					<div class="forum-category-stats">
						<span><?php echo (int)$row['topics']; ?> <?php echo $row['topics'] === 1 ? 'topic' : 'topics'; ?></span>
						<span><?php echo (int)$row['posts']; ?> <?php echo $row['posts'] === 1 ? 'post' : 'posts'; ?></span>
					</div>
					<div class="forum-category-latest">
						<?php if ($latest): ?>
							<a href="<?php echo htmlspecialchars($latest->url()); ?>"><?php echo htmlspecialchars((string)$latest->get('ftp_title')); ?></a>
							<span class="forum-muted"><?php echo htmlspecialchars(ForumPages::when($latest->get('ftp_last_post_time'))); ?></span>
						<?php else: ?>
							<span class="forum-muted">No topics yet</span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if (!$viewer_id): ?>
			<p class="forum-muted forum-signin-note"><a href="/login?return=<?php echo rawurlencode('/forum'); ?>">Sign in</a> to join the conversation.</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php
echo PublicPage::EndPage();
$page->public_footer();
