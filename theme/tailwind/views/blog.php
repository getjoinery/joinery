<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PathHelper.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(LibraryFunctions::get_logic_file_path('blog_logic.php'));
 	
	$page_vars = blog_logic($_GET, $_POST);

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $page_vars['title']
	);
	$page->public_header($hoptions); 
	
	echo PublicPage::BeginPage($page_vars['title']);		
?>



   <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:max-w-7xl lg:px-8">
      <h1 class="sr-only">Blog</h1>
      <!-- Main 3 column grid -->
      <div class="grid grid-cols-1 gap-4 items-start lg:grid-cols-3 lg:gap-8">
        <!-- Left column -->
        <div class="grid grid-cols-1 gap-4 lg:col-span-2">
          <!-- Blog posts -->
          <section aria-labelledby="blog-post">
            
			
		<?php
		if(!$page_vars['posts']){
			?>
			<div class="rounded-lg bg-white overflow-hidden shadow mb-6">
			  <div class="p-6">
				<p class="text-xl font-semibold text-gray-900">No Results</p>
		
			  <div class="mt-3 prose">
				<p>There are no posts matching that tag.</p>
				</div>
				
				</div>
			</div>	
				<?php
		}
		
		
		
		foreach ($page_vars['posts'] as $post){  
			$author = new User($post->get('pst_usr_user_id'), TRUE);
			$post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
			?>							
			
			
			<div class="rounded-lg bg-white overflow-hidden shadow mb-6">
			  <h2 class="sr-only" id="profile-overview-title">Profile Overview</h2>
			  <div class="p-6">
				<p class="text-sm text-gray-500">
					<?php echo $author->display_name().' at '; ?>
				  <time datetime="2020-03-16"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></time>
				</p>
				<a href="<?php echo $post->get_url(); ?>" class="mt-2 block">
				<p class="text-xl font-semibold text-gray-900"><?php echo $post->get('pst_title'); ?></p>
				<div>
				<?php
				foreach ($post_tags as $tag){
					echo '<a href="/blog/tag/'.urlencode($tag).'" class="inline-block p-1">
					<span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">'.$tag.'</span>
					</a>';			
				}
				?>
				</div>
			  <div class="mt-3 prose">
			  <?php
				if($post->get('pst_short_description')){
					echo $post->get('pst_short_description');
				}
				else{
					echo substr(strip_tags($post->get('pst_body')),0,300) . '...'; 
				}
				?>
				</div>
				</a>
				<div class="mt-3">
				  <a href="<?php echo $post->get_url(); ?>" class="text-base font-semibold text-indigo-600 hover:text-indigo-500"> Read more </a>
				</div>
				</div>
			</div>
			
			<?php } ?>
			</section>


        </div>

        <!-- Right column -->
        <div class="grid grid-cols-1 gap-4">
		

          <!-- Tags -->
          <section aria-labelledby="recent-hires-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="recent-hires-title">Tags</h2>
                <div class="flow-root mt-6">
					<?php
					foreach ($page_vars['tags'] as $tag){
						echo '<a href="/blog/tag/'.urlencode($tag).'" class="inline-block p-1">
						<span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">'.$tag.'</span>
						</a>';			
					}
					?>				   
                </div> 
              </div>
            </div>
          </section>

			
		<?php if($page_vars['num_pinned_posts'] > 0){ ?>
          <!-- Pinned posts -->
          <section aria-labelledby="announcements-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="announcements-title">Pinned Posts</h2>
                <div class="flow-root mt-6">
                  <ul role="list" class="-my-5 divide-y divide-gray-200">
                    <?php foreach ($page_vars['pinned_posts'] as $pinned_post){   ?>
					<li class="py-5">
                      <div class="relative focus-within:ring-2 focus-within:ring-cyan-500">
                        <h3 class="text-sm font-semibold text-gray-800">
                          <a href="<?php echo $pinned_post->get_url(); ?>" class="hover:underline focus:outline-none">
                            <!-- Extend touch target to entire panel -->
                            <span class="absolute inset-0" aria-hidden="true"></span>
                            <?php echo $pinned_post->get('pst_title'); ?>
                          </a>
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                            <?php
							if($pinned_post->get('pst_short_description')){
								echo $pinned_post->get('pst_short_description');
							}
							else{
								echo substr(strip_tags($pinned_post->get('pst_body')),0,300) . '...'; 
							}
							?>
                        </p>
                      </div>
                    </li>
					<?php } ?>
                    
                  </ul>
                </div>
				<!--
                <div class="mt-6">
                  <a href="#" class="w-full flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    View all
                  </a>
                </div>
				-->
              </div>
            </div>
          </section>
		  
		<?php } ?>			
			
	
			
        </div>
		
		<?php
		if($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')){
		?>
			<nav class="bg-white px-4 py-3 flex items-center justify-between border border-gray-200 sm:px-6 lg:col-span-2 rounded-lg" aria-label="Pagination">
			  <div class="hidden sm:block">
				<p class="text-sm text-gray-700">
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
					echo '<a class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50" href="'.$page_vars['pager']->get_url('-1', '').'">Previous</a>';
				}
				?>
				<?php	
				if($page_vars['pager']->is_valid_page('+1')){
					echo '<a class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50" href="'.$page_vars['pager']->get_url('+1', '').'">Next</a>';
				}
				?>
				
			  </div>
			</nav> 		
		
		<?php } ?>
				
      </div>
   



			
		<?php	

	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>