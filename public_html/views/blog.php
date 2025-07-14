<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('blog_logic.php'));
 	
	$page_vars = blog_logic($_GET, $_POST);

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $page_vars['title']
	);
	$page->public_header($hoptions); 
	
	//echo PublicPage::BeginPage($page_vars['title']);	

?>





  <div class="container">
    <div class="row">
      <!-- Left column: posts -->
      <section class="col-md-8 pt-0">
	  
	  
		<?php
		if(!$page_vars['posts']){
			?>
			<article class="mb-5">
			  <h2 class="h3"><a href="#" class="text-decoration-none text-dark">No Results</a></h2>
			  <p class="lead">There are no posts matching that tag.</p>
			</article>
			<?php
		}
		else {		
			foreach ($page_vars['posts'] as $post){  
				$author = new User($post->get('pst_usr_user_id'), TRUE);
				$post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
				?>							
				

				<div class="card mb-4">
					<div class="card-header bg-white border-bottom-0">
						<h2 class="h3"><a href="<?php echo $post->get_url(); ?>" class="text-decoration-none text-dark"><?php echo $post->get('pst_title'); ?></a></h2>
						  <p class="text-muted"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></p>
						  <p>
						  <?php
							foreach ($post_tags as $tag){
								echo '<a href="/blog/tag/'.urlencode($tag).'"><button class="btn btn-sm btn-falcon-default rounded-pill me-1 mb-1 mt-1" type="button">'.$tag.'</button></a>';		
							}
							?></p>
					</div>
				  <div class="card-body">

					  <p class="lead">				  
						  <?php
							if($post->get('pst_short_description')){
								echo $post->get('pst_short_description');
							}
							else{
								echo substr(strip_tags($post->get('pst_body')),0,300) . '...'; 
							}
							?>
						</p>
					  <a href="<?php echo $post->get_url(); ?>" class="btn btn-sm btn-primary">Read more</a>
				  </div>
				</div>
	


				

			<?php }
		}			
		?>	  
	  

      </section>

      <!-- Right column: sidebar -->
      <aside class="col-md-4">
        <!-- Tags card -->
        <div class="card mb-4">
          <div class="card-header bg-white border-bottom-0">Tags</div>
          <div class="card-body">
			<?php
			foreach ($page_vars['tags'] as $tag){
				echo '<a href="/blog/tag/'.urlencode($tag).'"><button class="btn btn-falcon-default rounded-pill me-1 mb-1 mt-1" type="button">'.$tag.'</button></a>';			
			}
			?>		
          </div>
        </div>

		<?php if($page_vars['num_pinned_posts'] > 0){ ?>
			<!-- Suggested posts card -->
			<div class="card mb-4">
			  <div class="card-header bg-white border-bottom-0">Pinned Posts</div>
			  <ul class="list-group list-group-flush">
				<?php foreach ($page_vars['pinned_posts'] as $pinned_post){
					echo '<li class="list-group-item"><a href="'.$pinned_post->get_url().'" class="text-decoration-none">'.$pinned_post->get('pst_title').'</a></li>';
				} ?>
				
			  </ul>
			</div>
		<?php } ?>
		
      </aside>
    </div>
  </div>



		
		<?php
		if($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')){
		?>
			<nav class="bg-white px-4 py-3 flex items-center justify-between border border sm:px-6 lg:col-span-2 rounded-lg" aria-label="Pagination">
			  <div class="hidden sm:block">
				<p class="text-sm text-muted">
				  Showing
				  <span class="font-medium"><?php echo $page_vars['pager']->current_record_start(); ?></span>
				  to
				  <span class="font-medium"><?php echo $page_vars['pager']->current_record_end(); ?></span>
				  of
				  <span class="font-medium"><?php echo $page_vars['pager']->num_records(); ?></span>
				  results
				</p>
			  </div>
			  <div class="flex-1 flex justify-between sm:justify-end">

				<?php	
				if($page_vars['pager']->is_valid_page('-1')){
					echo '<a class="relative inline-flex items-center px-4 py-2 border border text-sm font-medium rounded-md text-muted bg-white hover:bg-light" href="'.$page_vars['pager']->get_url('-1', '').'">Previous</a>';
				}
				?>
				<?php	
				if($page_vars['pager']->is_valid_page('+1')){
					echo '<a class="relative inline-flex items-center px-4 py-2 border border text-sm font-medium rounded-md text-muted bg-white hover:bg-light" href="'.$page_vars['pager']->get_url('+1', '').'">Next</a>';
				}
				?>
				
			  </div>
			</nav> 		
		
		<?php 
		} 

	//echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>