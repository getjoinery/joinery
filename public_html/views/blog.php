<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('blog_logic.php'));
 	

	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Blog'
	);
	$page->public_header($hoptions); 
	
	echo PublicPageTW::BeginPage($title);		
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
		foreach ($posts as $post){  
			if(!$post->get('pst_is_on_homepage')){
				continue;
			}
			$author = new User($post->get('pst_usr_user_id'), TRUE);
			$post_tags = $post->get_tags();
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

<?php /*
<div>
  <div class="sm:hidden">
    <label for="tabs" class="sr-only">Select a tab</label>
    <!-- Use an "onChange" listener to redirect the user to the selected tab URL. -->
    <select id="tabs" name="tabs" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
      <option <?php if(!$_REQUEST['tab']){ echo 'selected'; } ?>>Active Events</option>

      <option <?php if($_REQUEST['tab'] == 'past'){ echo 'selected'; } ?>>Past Events</option>

    </select>
  </div>
  <div class="hidden sm:block">
    <div class="border-b border-gray-200">
      <nav class="-mb-px flex space-x-8" aria-label="Tabs">
        <!-- Current: "border-indigo-500 text-indigo-600", Default: "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300" -->
		<?php
		$current_style = 'class="border-indigo-500 text-indigo-600 group inline-flex items-center py-4 px-1 border-b-2 font-medium text-sm" aria-current="page"';
		$standard_style = 'class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm"';
		?>
        <a href="/profile/profile"  <?php if(!$_REQUEST['tab']){ echo $current_style; } else{ echo $standard_style; } ?>>
          Active Events
        </a>

        <a href="/profile/profile?tab=past" <?php if($_REQUEST['tab'] == 'past'){ echo $current_style; } else{ echo $standard_style; } ?>>
          Past Events
        </a>
      </nav>
    </div>
  </div>
</div>
*/
?>


        </div>

        <!-- Right column -->
        <div class="grid grid-cols-1 gap-4">
		
		<?php /* ?>
          <!-- Announcements -->
          <section aria-labelledby="announcements-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="announcements-title">Announcements</h2>
                <div class="flow-root mt-6">
                  <ul role="list" class="-my-5 divide-y divide-gray-200">
                    <li class="py-5">
                      <div class="relative focus-within:ring-2 focus-within:ring-cyan-500">
                        <h3 class="text-sm font-semibold text-gray-800">
                          <a href="#" class="hover:underline focus:outline-none">
                            <!-- Extend touch target to entire panel -->
                            <span class="absolute inset-0" aria-hidden="true"></span>
                            Office closed on July 2nd
                          </a>
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                          Cum qui rem deleniti. Suscipit in dolor veritatis sequi aut. Vero ut earum quis deleniti. Ut a sunt eum cum ut repudiandae possimus. Nihil ex tempora neque cum consectetur dolores.
                        </p>
                      </div>
                    </li>

                    <li class="py-5">
                      <div class="relative focus-within:ring-2 focus-within:ring-cyan-500">
                        <h3 class="text-sm font-semibold text-gray-800">
                          <a href="#" class="hover:underline focus:outline-none">
                            <!-- Extend touch target to entire panel -->
                            <span class="absolute inset-0" aria-hidden="true"></span>
                            New password policy
                          </a>
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                          Alias inventore ut autem optio voluptas et repellendus. Facere totam quaerat quam quo laudantium cumque eaque excepturi vel. Accusamus maxime ipsam reprehenderit rerum id repellendus rerum. Culpa cum vel natus. Est sit autem mollitia.
                        </p>
                      </div>
                    </li>

                    <li class="py-5">
                      <div class="relative focus-within:ring-2 focus-within:ring-cyan-500">
                        <h3 class="text-sm font-semibold text-gray-800">
                          <a href="#" class="hover:underline focus:outline-none">
                            <!-- Extend touch target to entire panel -->
                            <span class="absolute inset-0" aria-hidden="true"></span>
                            Office closed on July 2nd
                          </a>
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                          Tenetur libero voluptatem rerum occaecati qui est molestiae exercitationem. Voluptate quisquam iure assumenda consequatur ex et recusandae. Alias consectetur voluptatibus. Accusamus a ab dicta et. Consequatur quis dignissimos voluptatem nisi.
                        </p>
                      </div>
                    </li>
                  </ul>
                </div>
                <div class="mt-6">
                  <a href="#" class="w-full flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    View all
                  </a>
                </div>
              </div>
            </div>
          </section>
		  
		  <?php */ ?>

          <!-- Tags -->
          <section aria-labelledby="recent-hires-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="recent-hires-title">Tags</h2>
                <div class="flow-root mt-6">
					<?php
					foreach ($tags as $tag){
						echo '<a href="/blog/tag/'.urlencode($tag).'" class="inline-block p-1">
						<span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">'.$tag.'</span>
						</a>';			
					}
					?>				   
                </div> 
              </div>
            </div>
          </section>

			
			
			
          <!-- Order History -->
		  <?php
		  /*
			if($settings->get_setting('products_active')){
				?>
				<section aria-labelledby="recent-hires-title">
					<div class="rounded-lg bg-white overflow-hidden shadow">
					  <div class="p-6">
						<h2 class="text-base font-medium text-gray-900" id="recent-hires-title">Your Orders</h2>
						<div class="flow-root mt-6">
						  <ul role="list" class="-my-5 divide-y divide-gray-200">
						  <?php
				
				
				foreach($orders as $order) {
					?>
					<li class="py-4">
					  <div class="flex items-center space-x-4">
					  <!--
						<div class="flex-shrink-0">
						  <img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1519345182560-3f2917c472ef?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
						</div>
						-->
						<div class="flex-1 min-w-0">
						  <p class="text-sm font-medium text-gray-900 truncate">
							Order <?php echo $order->key. ' ($'.$order->get('ord_total_cost').')'; ?>
						  </p>
						  
						  <p class="text-sm text-gray-500 truncate">
							<?php echo  LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $session->get_timezone(), 'M d, Y'); ?>
						  </p>
						  
						</div>
						<?php
						
						if($action){
						?>
						<div>
						  <?php echo $action; ?>
						</div>
						<?php
						}
						
						?>
					  </div>
					</li>
				<?php
				}
				
				?>
				  
                  </ul>
                </div>
                <div class="mt-6">
					<a href="/product/recurring-donation" class="w-full flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
						See All Orders
					  </a>
                </div>
              </div>
            </div>
          </section>
		  <?php
		  
			}
			*/
			?>			
			
        </div>
		
		<?php
		if($pager->is_valid_page('-1') || $pager->is_valid_page('+1')){
		?>
			<nav class="bg-white px-4 py-3 flex items-center justify-between border border-gray-200 sm:px-6 lg:col-span-2 rounded-lg" aria-label="Pagination">
			  <div class="hidden sm:block">
				<p class="text-sm text-gray-700">
				  Showing
				  <span class="font-medium">1</span>
				  to
				  <span class="font-medium">10</span>
				  of
				  <span class="font-medium">20</span>
				  results
				</p>
			  </div>
			  <div class="flex-1 flex justify-between sm:justify-end">

				<?php	
				if($pager->is_valid_page('-1')){
					echo '<a class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50" href="'.$pager->get_url('-1', '').'">Previous</a>';
				}
				?>
				<?php	
				if($pager->is_valid_page('+1')){
					echo '<a class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50" href="'.$pager->get_url('+1', '').'">Next</a>';
				}
				?>
				
			  </div>
			</nav> 		
		
		<?php } ?>
				
      </div>
   



			
		<?php	

	echo PublicPageTW::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>