<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once('includes/PublicPage.php');
	require_once('includes/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');  	
	
	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('blog_active')){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		include_once("404.php");
		exit();			
	}

	$session = SessionControl::get_instance();
	$session->set_return();
	
	
	$numperpage = 10;
	$page_offset = LibraryFunctions::fetch_variable('page_offset', 0, 0, '');
	$page_sort = LibraryFunctions::fetch_variable('page_sort', 'post_id', 0, '');	
	$page_direction = LibraryFunctions::fetch_variable('page_direction', 'DESC', 0, '');
	
	
	$search_criteria = array('published'=>TRUE);

	$posts = new MultiPost(
		$search_criteria,
		array($page_sort=>$page_direction),
		$numperpage,
		$page_offset);	
	$numrecords = $posts->count_all();	
	$posts->load();	

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Blog',
		'description' => 'Blog.',
		'banner' => 'Blog',
		'submenu' => 'Blog',
	);
	$page->public_header($hoptions); 
	
	echo PublicPage::BeginPage('Blog');		
	
	foreach ($posts as $post){  
		$author = new User($post->get('pst_usr_user_id'), TRUE);
		?>
		<div class="single-post row">
			<div class="col-lg-3  col-md-3 meta-details">
				<ul class="tags">
					<li><a href="#">Health,</a></li>
					<!--<li><a href="#">Technology,</a></li>
					<li><a href="#">Politics,</a></li>
					<li><a href="#">Lifestyle</a></li>-->
				</ul>
				<div class="user-details row">
					<p class="user-name col-lg-12 col-md-12 col-6"><a href="#"><?php echo $author->display_name(); ?></a> <span class="lnr lnr-user"></span></p>
					<p class="date col-lg-12 col-md-12 col-6"><a href="#"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York', '%b %e, %i:%M %p'); ?></a> <span class="lnr lnr-calendar-full"></span></p>
					<!--<p class="view col-lg-12 col-md-12 col-6"><a href="#">1.2M Views</a> <span class="lnr lnr-eye"></span></p>-->
					<!--<p class="comments col-lg-12 col-md-12 col-6"><a href="#">06 Comments</a> <span class="lnr lnr-bubble"></span></p>-->				
				</div>
			</div>
			<div class="col-lg-9 col-md-9 ">
				<!--
				<div class="feature-img">
					<img class="img-fluid" src="img/blog/feature-img1.jpg" alt="">
				</div>
				-->
				<a class="posts-title" href="/blog_post?pst_post_id=<?php echo $post->key ?>"><h3><?php echo $post->get('pst_title'); ?></h3></a>
				<p class="excert">
					<?php echo strip_tags(substr($post->get('pst_body'),0,300)) . '...'; ?>
				</p>
				<a href="<?php echo $post->get_url() ?>" class="primary-btn">Read more</a>
			</div>
		</div>
	<?php } ?>
		
	<?php
	if($numrecords > $numperpage){
		$total_pages = $numrecords / $numperpage;
		$current_page = $page_offset / $numperpage;
		if($currentpage > 1){
			$prevpage = $currentpage - 1;
		}
		else{
			$prevpage = NULL;
		}
		
		if($currentpage < $total_pages){
			$nextpage = $currentpage + 1;
		}
		else{
			$nextpage = NULL;
		}
	}
	?>
																
		<nav class="blog-pagination justify-content-center d-flex">
			<ul class="pagination">
				<?php if($prevpage){ ?>
				<li class="page-item">
					<a href="/blog?offset=<?php echo $prevpage * $numperpage; ?>" class="page-link" aria-label="Previous">
						<span aria-hidden="true">
							<span class="lnr lnr-chevron-left"></span>
						</span>
					</a>
				</li>
				<?php } ?>
				
				<?php
				for($x=0; $x<$total_pages; $x++){
					if($x == $current_page){
						echo '<li class="page-item active"><a href="/blog?offset=<?php echo $x * $numperpage; ?>" class="page-link">0'.$x.'</a></li>';
					}
					else{
						echo '<li class="page-item"><a href="/blog?offset=<?php echo $x * $numperpage; ?>" class="page-link">0'.$x.'</a></li>';
					}
					
				}
				?>
				
				<?php if($nextpage){ ?>
				<li class="page-item">
					<a href="/blog?offset=<?php echo $nextpage * $numperpage; ?>" class="page-link" aria-label="Next">
						<span aria-hidden="true">
							<span class="lnr lnr-chevron-right"></span>
						</span>
					</a>
				</li>
			<?php } ?>
		</ul>
	</nav>

						


<?php
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>