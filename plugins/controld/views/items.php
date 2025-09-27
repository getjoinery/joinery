<?php
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));

	require_once(PathHelper::getIncludePath('plugins/items/logic/items_logic.php'));

	$page_vars = process_logic(items_logic($_GET, $_POST));

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
		
		
		
		foreach ($page_vars['items'] as $item){  
			echo 'Item'.$item->get(''); 
			
		}

		
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