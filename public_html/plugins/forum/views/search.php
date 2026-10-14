<?php
/**
 * Forum search — /forum/search?q=…&category_id=…&sort=best|newest.
 *
 * Searches only what the visitor may read (ForumSearch); signed-out visitors
 * search the public categories. Best match first unless sorted by newest. The
 * results page is never indexed.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

if (!Forum::isActive()) {
	LibraryFunctions::display_404_page();
}
$viewer_id = Forum::viewerId();
$q = trim((string)($_GET['q'] ?? ''));
$sort = ($_GET['sort'] ?? '') === 'newest' ? 'newest' : 'best';
$category_id = (int)($_GET['category_id'] ?? 0);
$category = $category_id > 0 ? Forum::liveCategory($category_id) : null;
if ($category && !SpaceAccess::mayRead($category, $viewer_id)) {
	$category = null;
}
$found = ForumSearch::search($q, $viewer_id, $category ? (int)$category->key : null, $sort, (int)($_GET['page'] ?? 1));
$readable = new MultiForumCategory(array('readable_by' => $viewer_id, 'deleted' => false), array('fcg_sort_order' => 'ASC', 'fcg_name' => 'ASC'));
$url_for = function (int $n) use ($q, $category, $sort) {
	$args = array('q' => $q);
	if ($category) {
		$args['category_id'] = (int)$category->key;
	}
	if ($sort === 'newest') {
		$args['sort'] = 'newest';
	}
	if ($n > 1) {
		$args['page'] = $n;
	}
	return '/forum/search?' . http_build_query($args);
};

$page = new PublicPage();
$page->public_header(array(
	'is_valid_page' => $is_valid_page ?? true,
	'title'         => $q !== '' ? 'Search: ' . $q . ' — Forum' : 'Search the forum',
	'noindex'       => true,
));
echo PublicPage::BeginPage('Search the forum');
?>
<div class="jy-ui forum" data-forum-page="search">
	<?php echo ForumView::crumbs(array('Search' => '')); ?>

	<?php
	$category_names = array();
	foreach ($readable as $option) {
		$category_names[(string)(int)$option->key] = (string)$option->get('fcg_name');
	}
	ForumView::searchForm($page, array('full' => true, 'q' => $q, 'sort' => $sort,
		'category_id' => $category ? (int)$category->key : 0, 'categories' => $category_names));
	?>

	<?php if ($q === ''): ?>
		<p class="forum-muted">Type a few words. Put a phrase in quotes, and a minus before a word to leave it out.</p>
	<?php elseif (!$found['results']): ?>
		<div class="forum-empty"><p>Nothing matched <strong><?php echo htmlspecialchars($q); ?></strong>.</p></div>
	<?php else: ?>
		<p class="forum-muted"><?php echo (int)$found['total']; ?> <?php echo $found['total'] === 1 ? 'post' : 'posts'; ?> found.</p>
		<ol class="forum-results">
			<?php foreach ($found['results'] as $r): ?>
				<li class="forum-result">
					<a class="forum-result-title" href="<?php echo htmlspecialchars($r['url']); ?>"><?php echo htmlspecialchars($r['topic_title']); ?></a>
					<div class="forum-muted"><?php echo $r['is_first'] ? 'Opening post' : 'Reply'; ?> by <?php echo htmlspecialchars($r['author']); ?> · <?php echo htmlspecialchars($r['category_name']); ?> · <?php echo htmlspecialchars($r['when']); ?></div>
					<p class="forum-result-snippet"><?php echo $r['snippet_html']; ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php echo ForumView::pager($found['page'], $found['pages'], $url_for); ?>
	<?php endif; ?>
</div>
<?php
echo PublicPage::EndPage();
$page->public_footer();
