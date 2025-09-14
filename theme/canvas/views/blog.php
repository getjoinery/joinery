<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/ThemeHelper.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));
 	
	$page_vars = blog_logic($_GET, $_POST);

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $page_vars['title']
	);
	$page->public_header($hoptions); 
?>

<!-- Canvas Blog Section - Small Thumbs Layout -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row gx-5">
				
				<!-- Main Blog Content -->
				<main class="postcontent col-lg-9">
					
					<!-- Posts with Small Thumbnails -->
					<div id="posts" class="row gutter-40">
						
						<?php
						if(!$page_vars['posts']){
							?>
							<div class="col-12">
								<div class="card shadow-sm rounded-4 mb-5">
									<div class="card-body p-5 text-center">
										<h2 class="h3 mb-3">No Results</h2>
										<p class="lead text-muted">There are no posts matching that tag.</p>
									</div>
								</div>
							</div>
							<?php
						} else {
							foreach ($page_vars['posts'] as $post){  
								$author = new User($post->get('pst_usr_user_id'), TRUE);
								$post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
								?>
								
								<div class="entry col-12">
									<div class="grid-inner row g-0">
										<!-- Post Thumbnail -->
										<div class="col-md-4">
											<div class="entry-image">
												<a href="<?php echo $post->get_url(); ?>">
													<img src="https://via.placeholder.com/400x300/f8f9fa/6c757d?text=Blog+Post" 
														 class="img-fluid" 
														 alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
												</a>
											</div>
										</div>
										
										<!-- Post Content -->
										<div class="col-md-8 ps-md-4">
											<div class="entry-title title-sm">
												<h2><a href="<?php echo $post->get_url(); ?>" class="text-dark text-decoration-none">
													<?php echo htmlspecialchars($post->get('pst_title')); ?>
												</a></h2>
											</div>
											
											<div class="entry-meta">
												<ul>
													<li>
														<i class="uil uil-schedule"></i> 
														<?php echo date('jS M Y', strtotime($post->get('pst_published_time'))); ?>
													</li>
													<li>
														<a href="#">
															<i class="uil uil-user"></i> 
															<?php echo htmlspecialchars($author->get('usr_first_name') . ' ' . $author->get('usr_last_name')); ?>
														</a>
													</li>
													<?php if (!empty($post_tags)): ?>
													<li>
														<i class="uil uil-folder-open"></i>
														<?php 
														$tag_links = array();
														foreach ($post_tags as $tag) {
															$tag_links[] = '<a href="/blog/tag/' . urlencode($tag) . '">' . htmlspecialchars($tag) . '</a>';
														}
														echo implode(', ', $tag_links);
														?>
													</li>
													<?php endif; ?>
													<li>
														<a href="<?php echo $post->get_url(); ?>#comments">
															<i class="uil uil-comments-alt"></i> 0
														</a>
													</li>
												</ul>
											</div>
											
											<div class="entry-content">
												<p>				  
													<?php
													if($post->get('pst_short_description')){
														echo htmlspecialchars($post->get('pst_short_description'));
													} else {
														echo htmlspecialchars(substr(strip_tags($post->get('pst_body')),0,250)) . '...'; 
													}
													?>
												</p>
												<a href="<?php echo $post->get_url(); ?>" class="more-link">Read More</a>
											</div>
										</div>
									</div>
								</div>
								
								<?php
							}
						}
						?>
					</div>
					
					<!-- Pagination -->
					<?php if($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
					<div class="row mb-3">
						<div class="col-12">
							<ul class="pagination justify-content-center">
								<?php if($page_vars['pager']->is_valid_page('-1')): ?>
									<li class="page-item">
										<a class="page-link" href="?<?php echo $page_vars['pager']->get_param_string('-1'); ?>" aria-label="Previous">
											<span aria-hidden="true">«</span>
										</a>
									</li>
								<?php endif; ?>
								
								<?php
								$current_page = $page_vars['pager']->get_param();
								$total_pages = ceil($page_vars['pager']->num_records() / $page_vars['pager']->get_limit());
								
								for($i = 1; $i <= $total_pages && $i <= 5; $i++):
									$active = ($i == $current_page) ? 'active' : '';
									?>
									<li class="page-item <?php echo $active; ?>">
										<a class="page-link" href="?<?php echo $page_vars['pager']->get_param_string($i); ?>"><?php echo $i; ?></a>
									</li>
								<?php endfor; ?>
								
								<?php if($page_vars['pager']->is_valid_page('+1')): ?>
									<li class="page-item">
										<a class="page-link" href="?<?php echo $page_vars['pager']->get_param_string('+1'); ?>" aria-label="Next">
											<span aria-hidden="true">»</span>
										</a>
									</li>
								<?php endif; ?>
							</ul>
						</div>
					</div>
					<?php endif; ?>
					
				</main>

				<!-- Sidebar -->
				<aside class="sidebar col-lg-3">
					<div class="sidebar-widgets-wrap">

						<!-- Search Widget - Commented Out -->
						<!--
						<div class="widget widget_search clearfix">
							<form role="search" method="get" class="search-form" action="/blog">
								<div class="input-group">
									<input type="search" class="form-control" placeholder="Search..." name="s" value="">
									<button class="btn btn-outline-secondary" type="submit">
										<i class="uil uil-search"></i>
									</button>
								</div>
							</form>
						</div>
						-->

						<!-- Popular/Recent/Comments Tab Widget -->
						<div class="widget">
							<ul class="nav canvas-tabs tabs nav-tabs size-sm mb-3" id="canvas-tab" role="tablist">
								<li class="nav-item" role="presentation">
									<button class="nav-link active" id="canvas-tab-1" data-bs-toggle="pill" data-bs-target="#tab-1" type="button" role="tab" aria-controls="canvas-tab-1" aria-selected="true">Pinned</button>
								</li>
								<li class="nav-item" role="presentation">
									<button class="nav-link" id="canvas-tab-2" data-bs-toggle="pill" data-bs-target="#tab-2" type="button" role="tab" aria-controls="canvas-tab-2" aria-selected="false">Recents</button>
								</li>
								<li class="nav-item" role="presentation">
									<button class="nav-link uil uil-comments-alt" id="canvas-tab-3" data-bs-toggle="pill" data-bs-target="#tab-3" type="button" role="tab" aria-controls="canvas-tab-3" aria-selected="false"></button>
								</li>
							</ul>

							<div id="canvas-TabContent" class="tab-content">
								<!-- Pinned Tab (Pinned Posts) -->
								<div class="tab-pane show active" id="tab-1" role="tabpanel" aria-labelledby="canvas-tab-1" tabindex="0">
									<div class="posts-sm row col-mb-30" id="pinned-post-list-sidebar">
										<?php
										// Get pinned posts
										$pinned_posts = new MultiPost(
											array('published' => TRUE, 'deleted' => false, 'pinned' => TRUE),
											array('pst_published_time' => 'DESC'),
											3, 0
										);
										$pinned_posts->load();
										$num_pinned = $pinned_posts->count_all();

										if($num_pinned > 0):
											foreach($pinned_posts as $pinned_post):
										?>
										<div class="entry col-12">
											<div class="grid-inner row g-0">
												<div class="col-auto">
													<div class="entry-image">
														<a href="<?php echo $pinned_post->get_url(); ?>"><img class="rounded-circle" src="https://via.placeholder.com/80x80/1ABC9C/ffffff?text=Pin" alt="Pinned Post"></a>
													</div>
												</div>
												<div class="col ps-3">
													<div class="entry-title">
														<h4><a href="<?php echo $pinned_post->get_url(); ?>"><?php echo htmlspecialchars($pinned_post->get('pst_title')); ?></a></h4>
													</div>
													<div class="entry-meta">
														<ul>
															<li><?php echo date('jS M Y', strtotime($pinned_post->get('pst_published_time'))); ?></li>
														</ul>
													</div>
												</div>
											</div>
										</div>
										<?php
											endforeach;
										else:
										?>
										<div class="entry col-12">
											<div class="grid-inner row g-0">
												<div class="col">
													<p class="text-muted small">No pinned posts available.</p>
												</div>
											</div>
										</div>
										<?php endif; ?>
									</div>
								</div>

								<!-- Recent Tab -->
								<div class="tab-pane" id="tab-2" role="tabpanel" aria-labelledby="canvas-tab-2" tabindex="0">
									<div class="posts-sm row col-mb-30" id="recent-post-list-sidebar">
										<?php
										$recent_posts = new MultiPost(
											array('published' => TRUE, 'deleted' => false),
											array('pst_published_time' => 'DESC'),
											3, 0
										);
										$recent_posts->load();

										foreach($recent_posts as $recent_post):
										?>
										<div class="entry col-12">
											<div class="grid-inner row g-0">
												<div class="col-auto">
													<div class="entry-image">
														<a href="<?php echo $recent_post->get_url(); ?>"><img class="rounded-circle" src="https://via.placeholder.com/80x80/f8f9fa/6c757d?text=Post" alt="Recent Post"></a>
													</div>
												</div>
												<div class="col ps-3">
													<div class="entry-title">
														<h4><a href="<?php echo $recent_post->get_url(); ?>"><?php echo htmlspecialchars($recent_post->get('pst_title')); ?></a></h4>
													</div>
													<div class="entry-meta">
														<ul>
															<li><?php echo date('jS M Y', strtotime($recent_post->get('pst_published_time'))); ?></li>
														</ul>
													</div>
												</div>
											</div>
										</div>
										<?php endforeach; ?>
									</div>
								</div>

								<!-- Comments Tab -->
								<div class="tab-pane" id="tab-3" role="tabpanel" aria-labelledby="canvas-tab-3" tabindex="0">
									<div class="posts-sm row col-mb-30" id="recent-comments-list-sidebar">
										<div class="entry col-12">
											<div class="grid-inner row g-0">
												<div class="col-auto">
													<div class="entry-image">
														<a href="#"><img class="rounded-circle" src="https://via.placeholder.com/50x50/6c757d/ffffff?text=JD" alt="User Avatar"></a>
													</div>
												</div>
												<div class="col ps-3">
													<strong>John Doe:</strong> Great article! Really helped me understand the concepts better...
												</div>
											</div>
										</div>

										<div class="entry col-12">
											<div class="grid-inner row g-0">
												<div class="col-auto">
													<div class="entry-image">
														<a href="#"><img class="rounded-circle" src="https://via.placeholder.com/50x50/6c757d/ffffff?text=SM" alt="User Avatar"></a>
													</div>
												</div>
												<div class="col ps-3">
													<strong>Sarah Miller:</strong> Thanks for sharing this. The examples were very helpful...
												</div>
											</div>
										</div>

										<div class="entry col-12">
											<div class="grid-inner row g-0">
												<div class="col-auto">
													<div class="entry-image">
														<a href="#"><img class="rounded-circle" src="https://via.placeholder.com/50x50/6c757d/ffffff?text=MJ" alt="User Avatar"></a>
													</div>
												</div>
												<div class="col ps-3">
													<strong>Mike Johnson:</strong> Excellent tutorial! Looking forward to more posts like this...
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Recent Posts Widget - Commented Out -->
						<!--
						<div class="widget clearfix">
							<h4>Recent Posts</h4>
							<div class="posts-sm row col-mb-30" id="post-list-sidebar">
								<?php
								$recent_posts = new MultiPost(
									array('published' => TRUE, 'deleted' => false),
									array('pst_published_time' => 'DESC'),
									5, 0
								);
								$recent_posts->load();

								foreach($recent_posts as $recent_post):
								?>
								<div class="entry col-12">
									<div class="grid-inner row g-0">
										<div class="col-auto">
											<div class="entry-image">
												<a href="<?php echo $recent_post->get_url(); ?>">
													<img src="https://via.placeholder.com/80x80/f8f9fa/6c757d?text=Post" alt="Image">
												</a>
											</div>
										</div>
										<div class="col ps-3">
											<div class="entry-title">
												<h4><a href="<?php echo $recent_post->get_url(); ?>"><?php echo htmlspecialchars($recent_post->get('pst_title')); ?></a></h4>
											</div>
											<div class="entry-meta">
												<ul>
													<li><?php echo date('jS M Y', strtotime($recent_post->get('pst_published_time'))); ?></li>
												</ul>
											</div>
										</div>
									</div>
								</div>
								<?php endforeach; ?>
							</div>
						</div>
						-->

						<!-- Tags Widget -->
						<?php if(!empty($page_vars['tags'])): ?>
						<div class="widget clearfix">
							<h4>Tags</h4>
							<div class="tagcloud">
								<?php foreach($page_vars['tags'] as $tag): ?>
									<a href="/blog/tag/<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?></a>
								<?php endforeach; ?>
							</div>
						</div>
						<?php endif; ?>

					</div>
				</aside>

			</div>
		</div>
	</div>
</section>

<!-- Custom CSS for Canvas Blog Small Layout -->
<style>
	/* Entry Meta Styling */
	.entry-meta ul {
		list-style: none;
		padding: 0;
		margin: 0 0 1rem;
		display: flex;
		flex-wrap: wrap;
		gap: 1rem;
		font-size: 0.875rem;
		color: #999;
	}
	
	.entry-meta ul li {
		display: flex;
		align-items: center;
	}
	
	.entry-meta ul li i {
		margin-right: 0.25rem;
	}
	
	.entry-meta ul li a {
		color: #999;
		text-decoration: none;
	}
	
	.entry-meta ul li a:hover {
		color: #1ABC9C;
	}
	
	/* Entry Title */
	.entry-title.title-sm h2 {
		font-size: 1.5rem;
		margin-bottom: 0.75rem;
	}
	
	/* Entry Content */
	.entry-content {
		color: #666;
	}
	
	.entry-content .more-link {
		color: #1ABC9C;
		font-weight: 600;
		text-decoration: none;
	}
	
	.entry-content .more-link:hover {
		text-decoration: underline;
	}
	
	/* Entry Spacing */
	.entry {
		padding-bottom: 3rem;
		margin-bottom: 3rem;
		border-bottom: 1px solid #EEE;
	}
	
	.entry:last-child {
		border-bottom: 0;
	}
	
	/* Widget Styling */
	.widget {
		margin-bottom: 3rem;
	}
	
	.widget h4 {
		font-size: 1.125rem;
		margin-bottom: 1.5rem;
		font-weight: 600;
	}
	
	/* Tag Cloud */
	.tagcloud a {
		display: inline-block;
		padding: 0.25rem 0.75rem;
		margin: 0 0.25rem 0.5rem 0;
		background: #f8f9fa;
		color: #666;
		text-decoration: none;
		border-radius: 3px;
		font-size: 0.875rem;
	}
	
	.tagcloud a:hover {
		background: #1ABC9C;
		color: #fff;
	}
	
	/* Sidebar Recent Posts */
	.posts-sm .entry {
		padding-bottom: 1.5rem;
		margin-bottom: 1.5rem;
		border-bottom: 1px solid #f0f0f0;
	}
	
	.posts-sm .entry:last-child {
		border-bottom: 0;
		padding-bottom: 0;
		margin-bottom: 0;
	}
	
	.posts-sm .entry-title h4 {
		font-size: 0.9375rem;
		margin-bottom: 0.25rem;
	}
	
	.posts-sm .entry-meta {
		font-size: 0.8125rem;
	}
</style>

<?php
$page->public_footer();
?>