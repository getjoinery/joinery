<?php
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getIncludePath('data/posts_class.php'));

	$session = SessionControl::get_instance();
	$session->set_return();

	$numperpage = 10;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'post_id', 0, '');
	$direction = LibraryFunctions::fetch_variable('direction', 'DESC', 0, '');

	$search_criteria = array('published'=>TRUE, 'deleted'=>false, 'listed'=>true);

	$posts = new MultiPost(
		$search_criteria,
		array($sort=>$direction),
		$numperpage,
		$offset);
	$numrecords = $posts->count_all();
	$posts->load();

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Jeremy Tunnell Blog',
		'description' => 'Jeremy Tunnell blog.',
	);
	$page->public_header($hoptions);
?>

	<h3 class="section-title">Latest Stories</h3>
	<div class="section-divider"></div>

	<ul class="post-list">

		<?php foreach ($posts as $post) {
			$author = new User($post->get('pst_usr_user_id'), TRUE);
			$first_letter = mb_substr($post->get('pst_title'), 0, 1);
		?>

		<li class="post-entry">
			<div class="post-dropcap"><?php echo htmlspecialchars($first_letter); ?></div>
			<div class="post-entry-inner">
				<h2 class="post-title">
					<a href="<?php echo $post->get_url(); ?>"><?php echo htmlspecialchars($post->get('pst_title')); ?></a>
				</h2>
				<div class="post-meta">
					<span>By</span>
					<a href="/page/about"><?php echo htmlspecialchars($author->display_name()); ?></a>
					<span class="sep">/</span>
					<span>on <?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></span>
				</div>
				<p class="post-excerpt"><?php
					if($post->get('pst_short_description')){
						echo htmlspecialchars($post->get('pst_short_description'));
					} else {
						echo htmlspecialchars(substr(strip_tags($post->get('pst_body')), 0, 300)) . '&hellip;';
					}
				?></p>
				<a href="<?php echo $post->get_url(); ?>" class="btn-read-on">Read on</a>
			</div>
		</li>

		<li class="post-entry-divider"></li>

		<?php } ?>

	</ul>

	<!-- Pagination -->
	<nav class="pagination">
		<?php
		if($pager->is_valid_page('+1')){
			echo '<a href="' . $pager->get_url('+1', '') . '">Next Page &rarr;</a>';
		}
		?>
	</nav>

<?php
	$page->public_footer(array('track'=>TRUE));
?>
