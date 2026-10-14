<?php
/**
 * A forum category — /forum/{slug}. Its topics, pinned first and then by
 * latest activity, with a New topic box for those who may post here.
 *
 * A category the visitor may not read answers 404, whether or not it exists.
 * Public categories are cached for signed-out visitors (ForumCache clears).
 *
 * @version 1.0.0
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

if (!Forum::isActive()) {
	LibraryFunctions::display_404_page();
}
$viewer_id = Forum::viewerId();
$category = Forum::categoryBySlug((string)($params['slug'] ?? ''));
if (!$category || !SpaceAccess::mayRead($category, $viewer_id)) {
	LibraryFunctions::display_404_page();
}

$listing = ForumPages::categoryPage($category, $viewer_id, (int)($_GET['page'] ?? 1));
$can_post = SpaceAccess::mayPost($category, $viewer_id);
$watch = ForumWatch::levelFor($viewer_id, ForumWatch::TARGET_CATEGORY, (int)$category->key);
$url_for = function (int $n) use ($category) {
	return $category->url() . ($n > 1 ? '?page=' . $n : '');
};

$page = new PublicPage();
$header = array(
	'is_valid_page'    => $is_valid_page ?? true,
	'title'            => (string)$category->get('fcg_name') . ' — Forum',
	'meta_description' => (string)$category->get('fcg_description'),
	'entity_type'      => 'forum_category',
);
if (!$category->isPublic()) {
	$header['noindex'] = true;
}
$page->public_header($header);
echo PublicPage::BeginPage((string)$category->get('fcg_name'), array(
	'subtitle' => htmlspecialchars((string)$category->get('fcg_description')),
));
?>
<div class="jy-ui forum" data-forum-page="category" data-category-id="<?php echo (int)$category->key; ?>">
	<?php echo ForumView::crumbs(array((string)$category->get('fcg_name') => '')); ?>

	<div class="forum-toolbar">
		<?php if ($can_post): ?>
			<button type="button" class="btn btn-primary" data-forum-open="forum-topic-form-wrap">New topic</button>
		<?php endif; ?>
		<?php if ($viewer_id): ?>
			<button type="button" class="btn btn-outline" data-forum-watch="category" data-id="<?php echo (int)$category->key; ?>"
				data-level="<?php echo htmlspecialchars($watch ?? 'none'); ?>" aria-pressed="<?php echo $watch === 'watching' ? 'true' : 'false'; ?>">
				<?php echo $watch === 'watching' ? 'Watching new topics' : 'Watch new topics'; ?>
			</button>
		<?php endif; ?>
		<?php ForumView::searchForm($page, array('category_id' => (int)$category->key)); ?>
	</div>

	<?php if ($can_post) {
		ForumView::composer($page, 'topic', $category, array('hidden' => true));
	} elseif (!$viewer_id) {
		echo ForumView::signInPrompt($category->url(), 'start a topic');
	} ?>

	<?php if (!$listing['topics']): ?>
		<div class="forum-empty"><p>No topics yet.<?php echo $can_post ? ' Start the first one.' : ''; ?></p></div>
	<?php else: ?>
		<ul class="forum-topics">
			<?php foreach ($listing['topics'] as $topic):
				$tid = (int)$topic->key;
				$unread = !empty($listing['unread'][$tid]);
				$replies = (int)$topic->get('ftp_reply_count');
				$author = Forum::person($topic->get('ftp_usr_user_id'));
			?>
				<li class="forum-topic<?php echo $unread ? ' is-unread' : ''; ?>">
					<div class="forum-topic-main">
						<?php if ($unread): ?><span class="forum-unread-dot" title="New posts"><span class="sr-only">New posts</span></span><?php endif; ?>
						<?php if ($topic->get('ftp_is_pinned')): ?><span class="forum-chip">Pinned</span><?php endif; ?>
						<?php if ($topic->get('ftp_is_locked')): ?><span class="forum-chip">Locked</span><?php endif; ?>
						<?php if (!$topic->get('ftp_is_approved')): ?><span class="forum-chip forum-chip-held">Awaiting approval</span><?php endif; ?>
						<a class="forum-topic-title" href="<?php echo htmlspecialchars($topic->url()); ?>"><?php echo htmlspecialchars((string)$topic->get('ftp_title')); ?></a>
						<div class="forum-muted">Started by <?php echo htmlspecialchars($author['name']); ?> · <?php echo htmlspecialchars(ForumPages::when($topic->get('ftp_create_time'))); ?></div>
					</div>
					<div class="forum-topic-count"><?php echo $replies; ?> <?php echo $replies === 1 ? 'reply' : 'replies'; ?></div>
					<div class="forum-topic-last forum-muted">Last post <?php echo htmlspecialchars(ForumPages::when($topic->get('ftp_last_post_time'))); ?></div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php echo ForumView::pager($listing['page'], $listing['pages'], $url_for); ?>
	<?php endif; ?>
</div>
<script src="/plugins/forum/assets/js/forum.js?v=<?php echo (int)@filemtime(PathHelper::getIncludePath('plugins/forum/assets/js/forum.js')); ?>" defer></script>
<?php
echo PublicPage::EndPage();
$page->public_footer();
