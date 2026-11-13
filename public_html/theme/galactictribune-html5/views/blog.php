<?php
	// The blog: post cards on the left, tags and pinned posts on the right.
	// Styled by assets/css/galactictribune.css (gt-*).
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));

	$page_vars = process_logic(blog_logic(array_merge($_GET, $_POST)));
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page ?? true,
		'title' => $page_vars['title'],
	));

	echo PublicPage::BeginPage($page_vars['title']);

	$tag_link = function ($tag) {
		return '<a href="/blog/tag/' . urlencode($tag) . '" class="gt-tag">' . htmlspecialchars($tag) . '</a>';
	};
?>

<div class="gt-blog">
	<section class="gt-blog-posts" aria-label="Posts">
		<?php if (!$page_vars['posts']): ?>
		<div class="gt-card">
			<div class="gt-card-body">
				<p class="gt-post-title">No Results</p>
				<div class="gt-excerpt"><p>There are no posts matching that tag.</p></div>
			</div>
		</div>
		<?php endif; ?>

		<?php foreach ($page_vars['posts'] as $post):
			$author = new User($post->get('pst_usr_user_id'), TRUE);
			$post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
		?>
		<article class="gt-card">
			<div class="gt-card-body">
				<p class="gt-meta">
					<?php echo htmlspecialchars($author->display_name()) . ' at '; ?>
					<time><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></time>
				</p>
				<a href="<?php echo $post->get_url(); ?>" class="gt-post-link">
					<p class="gt-post-title"><?php echo htmlspecialchars($post->get('pst_title')); ?></p>
				</a>
				<?php if ($post_tags): ?>
				<div><?php foreach ($post_tags as $tag) { echo $tag_link($tag); } ?></div>
				<?php endif; ?>
				<div class="gt-excerpt">
					<?php
					if ($post->get('pst_short_description')) {
						echo $post->get('pst_short_description');
					} else {
						echo htmlspecialchars(substr(strip_tags($post->get('pst_body')), 0, 300)) . '...';
					}
					?>
				</div>
				<a href="<?php echo $post->get_url(); ?>" class="gt-readmore">Read more</a>
			</div>
		</article>
		<?php endforeach; ?>
	</section>

	<aside class="gt-blog-side">
		<section class="gt-card" aria-labelledby="gt-tags-title">
			<div class="gt-card-body">
				<h2 class="gt-side-title" id="gt-tags-title">Tags</h2>
				<div><?php foreach ($page_vars['tags'] as $tag) { echo $tag_link($tag); } ?></div>
			</div>
		</section>

		<?php if ($page_vars['num_pinned_posts'] > 0): ?>
		<section class="gt-card" aria-labelledby="gt-pinned-title">
			<div class="gt-card-body">
				<h2 class="gt-side-title" id="gt-pinned-title">Pinned Posts</h2>
				<ul class="gt-pinned">
					<?php foreach ($page_vars['pinned_posts'] as $pinned_post): ?>
					<li>
						<h3><a href="<?php echo $pinned_post->get_url(); ?>"><?php echo htmlspecialchars($pinned_post->get('pst_title')); ?></a></h3>
						<p>
							<?php
							if ($pinned_post->get('pst_short_description')) {
								echo $pinned_post->get('pst_short_description');
							} else {
								echo htmlspecialchars(substr(strip_tags($pinned_post->get('pst_body')), 0, 300)) . '...';
							}
							?>
						</p>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
		<?php endif; ?>
	</aside>

	<?php if ($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
	<nav class="gt-blog-pager" aria-label="Pagination">
		<p>
			Showing <strong><?php echo $page_vars['pager']->current_record_start(); ?></strong>
			to <strong><?php echo $page_vars['pager']->current_record_end(); ?></strong>
			of <strong><?php echo $page_vars['pager']->num_records(); ?></strong> results
		</p>
		<div class="gt-blog-pager-links">
			<?php if ($page_vars['pager']->is_valid_page('-1')): ?>
			<a href="<?php echo $page_vars['pager']->get_url('-1', ''); ?>">Previous</a>
			<?php endif; ?>
			<?php if ($page_vars['pager']->is_valid_page('+1')): ?>
			<a href="<?php echo $page_vars['pager']->get_url('+1', ''); ?>">Next</a>
			<?php endif; ?>
		</div>
	</nav>
	<?php endif; ?>
</div>

<?php
	echo PublicPage::EndPage();
	$page->public_footer(array('track' => TRUE));
?>
