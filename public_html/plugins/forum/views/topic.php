<?php
/**
 * A forum topic — /forum/t/{id}/{slug}. Its posts oldest first, a page at a
 * time, with the reply box under the last page.
 *
 * The id finds the topic; a missing or stale slug redirects to the current
 * address. Opened without ?page, a signed-in member lands on their first
 * unread post (ForumPages::landing). The page itself writes nothing: once it
 * is shown, forum.js tells the server how far the member has read.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

if (!Forum::isActive()) {
	LibraryFunctions::display_404_page();
}
$viewer_id = Forum::viewerId();
$topic_id = (int)($params['id'] ?? 0);
if ($topic_id <= 0 || !ForumTopic::check_if_exists($topic_id)) {
	LibraryFunctions::display_404_page();
}
$topic = new ForumTopic($topic_id, TRUE);
if ($topic->get('ftp_delete_time') || !Forum::mayReadTopic($topic, $viewer_id)) {
	LibraryFunctions::display_404_page();
}
$category = $topic->category();

// One address per topic: a stale or missing slug goes to the current one.
if ((string)($params['slug'] ?? '') !== (string)$topic->get('ftp_slug')) {
	// Keep what the link asked for (a page, a post), and nothing else.
	$keep = array_intersect_key($_GET, array('page' => true, 'post' => true));
	$keep = array_map('intval', $keep);
	header('Location: ' . $topic->url() . ($keep ? '?' . http_build_query($keep) : ''), true, 301);
	exit();
}

// A link to one post: send this visitor to the page it is on for them.
if (isset($_GET['post'])) {
	$post_id = (int)$_GET['post'];
	$target = ($post_id > 0 && ForumPost::check_if_exists($post_id)) ? new ForumPost($post_id, TRUE) : null;
	if ($target && $target->topicId() === $topic_id && Forum::mayReadPost($target, $viewer_id)) {
		$page_no = ForumPages::pageOf($topic, $post_id, $viewer_id, Forum::mayModerate($category, $viewer_id));
		header('Location: ' . $topic->url($page_no, $post_id), true, 302);
	} else {
		header('Location: ' . $topic->url(), true, 302);
	}
	exit();
}

$scroll_to = null;
if (isset($_GET['page'])) {
	$page_no = (int)$_GET['page'];
} else {
	$landing = ForumPages::landing($topic, $category, $viewer_id);
	$page_no = $landing['page'];
	$scroll_to = $landing['post_id'];
}
$view = ForumPages::topicPage($topic, $category, $viewer_id, $page_no);
$is_last_page = $view['page'] === $view['pages'];
$moderator = $view['moderator'];
$watch = ForumWatch::levelFor($viewer_id, ForumWatch::TARGET_TOPIC, $topic_id);
$url_for = function (int $n) use ($topic) {
	return $topic->url($n);
};

$opening = null;
foreach ($view['posts'] as $row) {
	if ($row['is_first']) {
		$opening = $row;
	}
}

$page = new PublicPage();
$header = array(
	'is_valid_page'    => $is_valid_page ?? true,
	'title'            => (string)$topic->get('ftp_title'),
	'og_type'          => 'article',
	'entity_type'      => 'forum_topic',
	'entity_body_html' => $opening ? $opening['html'] : '',
);
if (!$category->isPublic() || !$topic->get('ftp_is_approved')) {
	$header['noindex'] = true;
}
$page->public_header($header);
echo PublicPage::BeginPage((string)$topic->get('ftp_title'));
?>
<div class="jy-ui forum" data-forum-page="topic" data-topic-id="<?php echo $topic_id; ?>"
	data-category-id="<?php echo (int)$category->key; ?>"
	data-last-post-id="<?php echo (int)$view['last_post_id']; ?>"
	data-signed-in="<?php echo $viewer_id ? '1' : '0'; ?>"
	<?php if ($scroll_to): ?>data-scroll-to="<?php echo (int)$scroll_to; ?>"<?php endif; ?>>
	<?php echo ForumView::crumbs(array(
		(string)$category->get('fcg_name') => $category->url(),
		(string)$topic->get('ftp_title') => '',
	)); ?>

	<div class="forum-toolbar">
		<div class="forum-topic-flags">
			<?php if ($topic->get('ftp_is_pinned')): ?><span class="forum-chip">Pinned</span><?php endif; ?>
			<?php if ($topic->get('ftp_is_locked')): ?><span class="forum-chip">Locked</span><?php endif; ?>
			<?php if (!$topic->get('ftp_is_approved')): ?><span class="forum-chip forum-chip-held">Awaiting approval</span><?php endif; ?>
		</div>
		<?php if ($viewer_id): ?>
			<label class="forum-watch-select">
				<span class="sr-only">Notifications for this topic</span>
				<select class="form-control" data-forum-watch-select="topic" data-id="<?php echo $topic_id; ?>">
					<option value="none"<?php echo $watch === null ? ' selected' : ''; ?>>Not watching</option>
					<option value="watching"<?php echo $watch === 'watching' ? ' selected' : ''; ?>>Watching: tell me about replies</option>
					<option value="muted"<?php echo $watch === 'muted' ? ' selected' : ''; ?>>Muted: nothing from this topic</option>
				</select>
			</label>
		<?php endif; ?>
		<?php if ($moderator): ?>
			<details class="forum-mod-tools">
				<summary class="btn btn-outline">Moderate</summary>
				<div class="forum-mod-menu">
					<button type="button" class="btn btn-sm btn-secondary" data-forum-topic-op="<?php echo $topic->get('ftp_is_pinned') ? 'unpin' : 'pin'; ?>">
						<?php echo $topic->get('ftp_is_pinned') ? 'Unpin' : 'Pin to top'; ?></button>
					<button type="button" class="btn btn-sm btn-secondary" data-forum-topic-op="<?php echo $topic->get('ftp_is_locked') ? 'unlock' : 'lock'; ?>">
						<?php echo $topic->get('ftp_is_locked') ? 'Unlock' : 'Lock replies'; ?></button>
					<label class="forum-move">
						<span>Move to</span>
						<select class="form-control" data-forum-move>
							<?php foreach (new MultiForumCategory(array('deleted' => false), array('fcg_sort_order' => 'ASC', 'fcg_name' => 'ASC')) as $option): ?>
								<option value="<?php echo (int)$option->key; ?>"<?php echo (int)$option->key === (int)$category->key ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$option->get('fcg_name')); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="btn btn-sm btn-danger-soft" data-forum-topic-op="delete">Remove topic</button>
				</div>
			</details>
		<?php endif; ?>
	</div>

	<?php echo ForumView::pager($view['page'], $view['pages'], $url_for); ?>

	<div class="forum-posts">
		<?php foreach ($view['posts'] as $row):
			$id = $row['id'];
		?>
			<article class="forum-post<?php echo $row['held'] ? ' is-held' : ''; ?>" id="post-<?php echo $id; ?>" data-post-id="<?php echo $id; ?>"
				data-author="<?php echo htmlspecialchars($row['author']['name']); ?>"<?php echo $row['is_first'] ? ' data-first="1"' : ''; ?>>
				<header class="forum-post-head">
					<span class="forum-avatar" aria-hidden="true"><?php echo htmlspecialchars(ForumView::initials($row['author']['name'])); ?></span>
					<span class="forum-post-author"><?php echo htmlspecialchars($row['author']['name']); ?></span>
					<a class="forum-post-time forum-muted" href="#post-<?php echo $id; ?>"><?php echo htmlspecialchars(ForumPages::when($row['post']->get('fpo_create_time'))); ?></a>
					<?php if ($row['edited']): ?><span class="forum-muted">· edited</span><?php endif; ?>
					<?php if ($row['held']): ?><span class="forum-chip forum-chip-held">Awaiting approval</span><?php endif; ?>
				</header>
				<?php if ($row['reply_to']): ?>
					<a class="forum-reply-chip" href="<?php echo htmlspecialchars($row['reply_to']['url']); ?>">In reply to <?php echo htmlspecialchars($row['reply_to']['name']); ?></a>
				<?php endif; ?>
				<div class="forum-post-body markdown-content"><?php echo $row['html']; ?></div>
				<?php if ($row['attachments']): ?>
					<ul class="forum-attachments">
						<?php foreach ($row['attachments'] as $a): ?>
							<li>
								<?php if ($a['is_image']): ?>
									<a href="<?php echo htmlspecialchars($a['url']); ?>" target="_blank" rel="noopener"><img src="<?php echo htmlspecialchars($a['url']); ?>" alt="<?php echo htmlspecialchars($a['name']); ?>" loading="lazy"></a>
								<?php else: ?>
									<a href="<?php echo htmlspecialchars($a['url']); ?>" download><?php echo htmlspecialchars($a['name']); ?></a>
									<span class="forum-muted"><?php echo htmlspecialchars(ForumView::size($a['size'])); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<footer class="forum-post-actions">
					<?php if ($row['can_like']): ?>
						<button type="button" class="btn btn-sm btn-link" data-forum-like aria-pressed="<?php echo $row['liked'] ? 'true' : 'false'; ?>">
							<?php echo $row['liked'] ? 'Liked' : 'Like'; ?><span class="forum-like-count"><?php echo $row['likes'] ? ' · ' . $row['likes'] : ''; ?></span></button>
					<?php elseif ($row['likes']): ?>
						<span class="forum-muted"><?php echo $row['likes']; ?> <?php echo $row['likes'] === 1 ? 'like' : 'likes'; ?></span>
					<?php endif; ?>
					<?php if ($row['can_reply']): ?>
						<?php if ($is_last_page): ?>
							<button type="button" class="btn btn-sm btn-link" data-forum-reply>Reply</button>
							<button type="button" class="btn btn-sm btn-link" data-forum-quote>Quote</button>
						<?php else: ?>
							<a class="btn btn-sm btn-link" href="<?php echo htmlspecialchars($topic->url($view['pages']) . (strpos($topic->url($view['pages']), '?') === false ? '?' : '&') . 'reply_to=' . $id . '#reply'); ?>">Reply</a>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ($row['can_edit']): ?>
						<button type="button" class="btn btn-sm btn-link" data-forum-edit>Edit</button>
					<?php endif; ?>
					<?php if ($row['can_delete']): ?>
						<button type="button" class="btn btn-sm btn-link" data-forum-delete><?php echo $row['is_first'] ? 'Remove topic' : 'Remove'; ?></button>
					<?php endif; ?>
					<?php if ($row['can_report']): ?>
						<button type="button" class="btn btn-sm btn-link forum-report" data-forum-report>Report</button>
					<?php endif; ?>
					<?php if ($row['held'] && $moderator): ?>
						<button type="button" class="btn btn-sm btn-primary" data-forum-moderate="approve">Approve</button>
						<button type="button" class="btn btn-sm btn-danger-soft" data-forum-moderate="reject">Turn down</button>
					<?php endif; ?>
				</footer>
			</article>
		<?php endforeach; ?>
	</div>

	<?php echo ForumView::pager($view['page'], $view['pages'], $url_for); ?>

	<?php
	$can_edit_any = false;
	foreach ($view['posts'] as $row) {
		$can_edit_any = $can_edit_any || $row['can_edit'];
	}
	if ($can_edit_any) {
		ForumView::composer($page, 'edit', $category, array('hidden' => true, 'topic_id' => $topic_id));
	}
	?>

	<section class="forum-reply-section" id="reply" aria-label="Reply">
		<?php if ($view['can_post'] && $topic->get('ftp_is_approved')): ?>
			<?php if ($is_last_page): ?>
				<h2 class="forum-reply-heading">Reply</h2>
				<?php ForumView::composer($page, 'reply', $category, array('topic_id' => $topic_id)); ?>
			<?php else: ?>
				<p><a class="btn btn-primary" href="<?php echo htmlspecialchars($topic->url($view['pages'])); ?>#reply">Go to the last page to reply</a></p>
			<?php endif; ?>
		<?php elseif ($topic->get('ftp_is_locked')): ?>
			<p class="forum-muted">This topic is locked. No new replies.</p>
		<?php elseif (!$viewer_id): ?>
			<?php echo ForumView::signInPrompt($topic->url(), 'reply'); ?>
		<?php endif; ?>
	</section>
</div>
<script src="/plugins/forum/assets/js/forum.js?v=<?php echo (int)@filemtime(PathHelper::getIncludePath('plugins/forum/assets/js/forum.js')); ?>" defer></script>
<?php
echo PublicPage::EndPage();
$page->public_footer();
