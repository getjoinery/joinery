<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('blog_logic.php'));
 	

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Galactic Tribune - An urbit news site'
	);
	$page->public_header($hoptions); 
	
	echo PublicPage::BeginPage($title);		
?>


		<!-- Blog section  -->
		<div class="section">
			<div class="container">
				<div class="row col-spacing-50">
					<!-- Blog Posts -->
					<div class="col-12 col-lg-8">
					
					
						<?php
						foreach ($posts as $post){  
							$author = new User($post->get('pst_usr_user_id'), TRUE);
						
						?>
						<!-- Blog Post box 1 -->
						<div class="margin-bottom-50">
							<div class="hoverbox-8">
								<a href="#">
									<img src="../assets/images/col-1.jpg" alt="">
								</a>
							</div>
							<div class="margin-top-30">
								<div class="d-flex justify-content-between margin-bottom-10">
									<div class="d-inline-flex">
										<a class="font-family-tertiary font-small font-weight-normal uppercase" href="#">Post</a>
									</div>
									<div class="d-inline-flex">
										<span class="font-small"><?php echo $author->display_name().' at '.LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></span>
									</div>
								</div>
								<h5><a href="<?php echo $post->get_url(); ?>"><?php echo $post->get('pst_title'); ?></a></h5>
								
								<?php
								echo'<p>';					
								if($post->get('pst_short_description')){
									echo $post->get('pst_short_description');
								}
								else{
									echo substr(strip_tags($post->get('pst_body')),0,300) . '...'; 
								}
								echo '</p>';
								?>
								<div class="margin-top-20">
									<a class="button-text-1" href="<?php echo $post->get_url(); ?>">Read More</a>
								</div>
							</div>
						</div>
						<?php
						}
						?>
						
						<!-- Pagination -->
						<nav>
							<ul class="pagination justify-content-center margin-top-70">
								<?php	
								if($pager->is_valid_page('-1')){
									echo '<li class="page-item"><a class="page-link" href="'.$pager->get_url('-1', '').'">&laquo;</a></li>';
								}
								?>
								
								<?php
								if($pager->is_valid_page('+1')){
									echo '<li class="page-item"><a class="page-link" href="'.$pager->get_url('+1', '').'">&raquo;</a></li>';
								}
								?>
								<!--
								<li class="page-item"><a class="page-link" href="#">&laquo;</a></li>
								<li class="page-item active"><a class="page-link" href="#">1</a></li>
								<li class="page-item"><a class="page-link" href="#">2</a></li>
								<li class="page-item"><a class="page-link" href="#">3</a></li>
								<li class="page-item"><a class="page-link" href="#">&raquo;</a></li>-->
							</ul>
						</nav>
					</div>
					<!-- end Blog Posts -->

					<!-- Blog Sidebar -->
					<div class="col-12 col-lg-4 sidebar-wrapper">
						<!-- Sidebar box 1 - About me --> 
						<!--
						<div class="sidebar-box text-center">
							<h6 class="font-small font-weight-normal uppercase">About Me</h6>
							<img class="img-circle-md margin-bottom-20" src="../assets/images/img-circle-medium.jpg" alt="">
							<p>Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
							<ul class="list-inline margin-top-20">
								<li><a href="#"><i class="fab fa-facebook-f"></i></a></li>
								<li><a href="#"><i class="fab fa-twitter"></i></a></li>
								<li><a href="#"><i class="fab fa-pinterest"></i></a></li>
								<li><a href="#"><i class="fab fa-instagram"></i></a></li>
							</ul>
						</div>
						-->
						<!-- Sidebar box 2 - Categories -->
						<!--
						<div class="sidebar-box">
							<h6 class="font-small font-weight-normal uppercase">Categories</h6>
							<ul class="list-category">
								<li><a href="#">Art <span>11</span></a></li>
								<li><a href="#">Fashion <span>4</span></a></li>
								<li><a href="#">Lifestyle <span>12</span></a></li>
								<li><a href="#">Nature <span>8</span></a></li>
								<li><a href="#">Travel <span>15</span></a></li>
							</ul>
						</div>
						-->
						<!-- Sidebar box 3 - Popular Posts -->
						<!--
						<div class="sidebar-box">
							<h6 class="font-small font-weight-normal uppercase">Popular Posts</h6>
							<div class="popular-post">
								<a href="#">
									<img src="../assets/images/img-circle-small.jpg" alt="">
								</a>
								<div>
									<h6 class="font-weight-normal"><a href="#">Blog Post with Image</a></h6>
									<span>January 07, 2018</span>
								</div>
							</div>
							<div class="popular-post">
								<a href="#">
									<img src="../assets/images/img-circle-small.jpg" alt="">
								</a>
								<div>
									<h6 class="font-weight-normal"><a href="#">Blog Post with Image</a></h6>
									<span>January 07, 2018</span>
								</div>
							</div>
							<div class="popular-post">
								<a href="#">
									<img src="../assets/images/img-circle-small.jpg" alt="">
								</a>
								<div>
									<h6 class="font-weight-normal"><a href="#">Blog Post with Image</a></h6>
									<span>January 07, 2018</span>
								</div>
							</div>
						</div>
						-->
						<!-- Sidebar box 4 - Banner Image -->
						<!--
						<div class="margin-bottom-20">
							<a href="#">
								<img src="../assets/images/col-3.jpg" alt="">
							</a>
						</div>
						-->
						<!-- Sidebar box 5 - Tags -->
						<div class="sidebar-box">
							<h6 class="font-small font-weight-normal uppercase">Tags</h6>
							<ul class="tags">
								<?php
								$post_tags = MultiPost::get_all_tags();
								foreach ($post_tags as $tag){
									echo '<li><a href="/blog/tag/'.urlencode($tag).'">'.$tag.'</a></li>';
								} 
								?>
							</ul>
						</div>
						<!-- Sidebar box 6 - Facebook Like box -->
						<!--
						<div class="sidebar-box text-center">
							<h6 class="font-small font-weight-normal uppercase">Follow on</h6>
							<ul class="list-inline">
								<li><a href="#"><i class="fab fa-facebook-f"></i></a></li>
								<li><a href="#"><i class="fab fa-twitter"></i></a></li>
								<li><a href="#"><i class="fab fa-pinterest"></i></a></li>
								<li><a href="#"><i class="fab fa-behance"></i></a></li>
								<li><a href="#"><i class="fab fa-instagram"></i></a></li>
							</ul>
						</div>
						-->
					</div>
					<!-- end Blog Sidebar -->
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		<!-- end Blog section -->
		<?php
			

	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>