<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/Pager.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php'); 	

	$session = SessionControl::get_instance();
	$session->set_return();
	
	$numperpage = 10;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'post_id', 0, '');	
	$direction = LibraryFunctions::fetch_variable('direction', 'DESC', 0, '');
	
	
	$search_criteria = array('published'=>TRUE, 'deleted'=>false, 'is_on_homepage'=>true);

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
		'title' => 'Jeremy Tunnell Blog',
		'description' => 'Jeremy Tunnell blog.',
		'banner' => 'Blog',
		'submenu' => 'Blog',
	);
	$page->public_header($hoptions); 
	
			
	?>
	<div class="section-head"><h3 class="section-title h6">Latest stories</h3></div>									
	<div class="section-content section-content-a">
		<div class="typology-posts">
	
			<?php foreach ($posts as $post){  
				$author = new User($post->get('pst_usr_user_id'), TRUE);
			?>

			<article class="typology-post typology-layout-a  post-131 post type-post status-publish format-standard hentry category-politics tag-noimages tag-stories tag-writing">

				<header class="entry-header">
					<h2 class="entry-title h1"><a href="<?php echo $post->get('pst_link') ?>"><?php echo $post->get('pst_title'); ?></a></h2>         
					<div class="entry-meta">
						<div class="meta-item meta-author">By <span class="vcard author"><span class="fn"><a href="/page/about"><?php echo $author->display_name(); ?></a></span></span></div><div class="meta-item meta-category">on <!--<a href="category/politics/index.html" rel="category tag">Politics</a></div>--><div class="meta-item meta-rtime"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?>
						</div>
					</div>
					<div class="post-letter"><?php echo $post->get('pst_title')[0]; ?></div>
				</header>
				<div class="entry-content">
					<p><?php 
					if($post->get('pst_short_description')){
						echo $post->get('pst_short_description');
					}
					else{
						echo substr(strip_tags($post->get('pst_body')),0,300) . '...'; 
					}
					?></p>
				</div>
				<div class="entry-footer">
					<a href="<?php echo $post->get_url() ?>" class="typology-button">Read on</a><!--<a href="javascript:void(0);" class="typology-button button-invert typology-rl pocket" data-url="https://getpocket.com/edit?url=https%3A%2F%2Fjeremytestsite-1b8274.ingress-bonde.easywp.com%2Fwhy-do-people-think-clouds-are-so-interesting%2F"><i class="fa fa-bookmark-o"></i>Read later</a> -->       
				</div>
			</article>  

			<?php } ?>
		</div>

		<div class="typology-pagination">

		<nav class="navigation load-more">
			<?php
			/*
			if($pager->is_valid_page('-1')){
				echo '<a href="'.$pager->get_url('-1', '').'">Previous Page</a>';
			}
			*/
			
			if($pager->is_valid_page('+1')){
				echo '<a href="'.$pager->get_url('+1', '').'">Next Page</a>';
			}
			?>
	    
			<div class="typology-loader">
				  <div class="dot dot1"></div>
				  <div class="dot dot2"></div>
				  <div class="dot dot3"></div>
				  <div class="dot dot4"></div>
		    </div>
		</nav>
	</div>
</div>


<?php 

	$page->public_footer(array('track'=>TRUE));
?>