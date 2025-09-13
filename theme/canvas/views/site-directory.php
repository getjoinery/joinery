<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/pages_class.php');
	PathHelper::requireOnce('data/posts_class.php');
	PathHelper::requireOnce('data/events_class.php');
	PathHelper::requireOnce('data/locations_class.php');
	PathHelper::requireOnce('data/videos_class.php');

	$paged = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Sitemap',
	);
	$paged->public_header($hoptions);
	echo PublicPage::BeginPage('Sitemap');
?>

<!-- Canvas Site Directory Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-10">
					
					<!-- Page Header -->
					<div class="text-center mb-5">
						<h1 class="h2 mb-2">Site Directory</h1>
						<p class="text-muted">Browse all content on our website</p>
					</div>

					<div class="row g-4">
						
						<?php 
						$settings = Globalvars::get_instance();
						if($settings->get_setting('page_contents_active')): 
						?>
						<!-- Pages Section -->
						<div class="col-lg-6">
							<div class="card shadow-sm rounded-4 border-0 h-100">
								<div class="card-header bg-primary text-white rounded-top-4">
									<h4 class="mb-0"><i class="icon-file-text me-2"></i>Pages</h4>
								</div>
								<div class="card-body">
									<?php
									$search_criteria = array('published' => TRUE, 'deleted' => false, 'has_link' => TRUE);
									$pages = new MultiPage($search_criteria);	
									$pages->load();
									
									if($pages->count() > 0):
									?>
									<ul class="list-unstyled mb-0">
										<?php foreach ($pages as $page): ?>
										<li class="py-2 border-bottom">
											<a href="/page/<?php echo $page->get_url(); ?>" class="text-decoration-none d-flex align-items-center">
												<i class="icon-chevron-right text-primary me-2"></i>
												<?php echo $page->get('pag_title'); ?>
											</a>
										</li>
										<?php endforeach; ?>
									</ul>
									<?php else: ?>
									<p class="text-muted mb-0">No pages available.</p>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endif; ?>

						<?php if($settings->get_setting('events_active')): ?>
						<!-- Events Section -->
						<div class="col-lg-6">
							<div class="card shadow-sm rounded-4 border-0 h-100">
								<div class="card-header bg-success text-white rounded-top-4">
									<h4 class="mb-0"><i class="icon-calendar me-2"></i>Events</h4>
								</div>
								<div class="card-body">
									<?php
									$sort = 'start_time';
									$sdirection = 'ASC';
									$searches = array();
									$searches['deleted'] = FALSE;
									$searches['visibility'] = 1;
									$searches['status'] = 1;
									$events = new MultiEvent(
										$searches,
										array($sort=>$sdirection),
										NULL,
										NULL,
										'AND');
									$events->load();	
									
									if($events->count() > 0):
									?>
									<ul class="list-unstyled mb-0">
										<?php foreach ($events as $event): ?>
										<li class="py-2 border-bottom">
											<a href="<?php echo $event->get_url(); ?>" class="text-decoration-none d-flex align-items-center">
												<i class="icon-chevron-right text-success me-2"></i>
												<?php echo $event->get('evt_name'); ?>
											</a>
										</li>
										<?php endforeach; ?>
									</ul>
									<?php else: ?>
									<p class="text-muted mb-0">No events available.</p>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<!-- Locations Section -->
						<div class="col-lg-6">
							<div class="card shadow-sm rounded-4 border-0 h-100">
								<div class="card-header bg-info text-white rounded-top-4">
									<h4 class="mb-0"><i class="icon-map-marker me-2"></i>Locations</h4>
								</div>
								<div class="card-body">
									<?php
									$sort = 'location_id';
									$sdirection = 'ASC';
									$searches = array();
									$searches['deleted'] = FALSE;
									$searches['published'] = true;
									$locations = new MultiLocation(
										$searches,
										array($sort=>$sdirection),
										NULL,
										NULL,
										'AND');
									$locations->load();	
									
									if($locations->count() > 0):
									?>
									<ul class="list-unstyled mb-0">
										<?php foreach ($locations as $location): ?>
										<li class="py-2 border-bottom">
											<a href="<?php echo $location->get_url(); ?>" class="text-decoration-none d-flex align-items-center">
												<i class="icon-chevron-right text-info me-2"></i>
												<?php echo $location->get('loc_name'); ?>
											</a>
										</li>
										<?php endforeach; ?>
									</ul>
									<?php else: ?>
									<p class="text-muted mb-0">No locations available.</p>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endif; ?>

						<?php if($settings->get_setting('blog_active')): ?>
						<!-- Blog Posts Section -->
						<div class="col-lg-6">
							<div class="card shadow-sm rounded-4 border-0 h-100">
								<div class="card-header bg-warning text-dark rounded-top-4">
									<h4 class="mb-0"><i class="icon-edit me-2"></i>Blog Posts</h4>
								</div>
								<div class="card-body">
									<?php
									$page_sort = LibraryFunctions::fetch_variable('page_sort', 'post_id', 0, '');	
									$page_direction = LibraryFunctions::fetch_variable('page_direction', 'DESC', 0, '');
									$search_criteria = array('published'=>TRUE);
									$search_criteria['deleted'] = false;
									$posts = new MultiPost($search_criteria);	
									$posts->load();		
									
									if($posts->count() > 0):
									?>
									<ul class="list-unstyled mb-0">
										<?php foreach ($posts as $post): ?>
										<li class="py-2 border-bottom">
											<a href="<?php echo $post->get_url(); ?>" class="text-decoration-none d-flex align-items-center">
												<i class="icon-chevron-right text-warning me-2"></i>
												<?php echo $post->get('pst_title'); ?>
											</a>
										</li>
										<?php endforeach; ?>
									</ul>
									<?php else: ?>
									<p class="text-muted mb-0">No blog posts available.</p>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endif; ?>

					</div>
				</div>
			</div>
		</div>
	</div>
</section>

<?php
	echo PublicPage::EndPage();
	$paged->public_footer(array('track'=>TRUE));
?>