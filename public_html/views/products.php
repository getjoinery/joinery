<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('products_logic.php'));
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPageTW.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');

	$page = new PublicPageTW(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Products'
	));
	echo PublicPageTW::BeginPage('Products');
	echo PublicPageTW::BeginPanel();
?>
<div class="max-w-2xl mx-auto pb-16 px-4 sm:py-24 sm:px-6 lg:max-w-7xl lg:px-8">
    <h2 class="sr-only">Products</h2>
	<div class="grid grid-cols-1 gap-y-4 sm:grid-cols-2 sm:gap-x-6 sm:gap-y-10 lg:grid-cols-3 lg:gap-x-8">

	<?php foreach ($products as $product){ ?>

    
      <div class="group relative bg-white border border-gray-200 rounded-lg flex flex-col overflow-hidden">
        <div class="aspect-w-3 aspect-h-4 bg-gray-200 group-hover:opacity-75 sm:aspect-none sm:h-96">
			<?php
				//if($pic = $product->get_picture_link('small')){
				//	echo '<img src="'.$pic.'" class="w-full h-full object-center object-cover sm:w-full sm:h-full">';
				//}
				?>		
		
        </div>
        <div class="flex-1 p-4 space-y-2 flex flex-col">
          <h3 class="text-sm font-medium text-gray-900">
            <a href="<?php echo $product->get_url(); ?>">
              <span aria-hidden="true" class="absolute inset-0"></span>
              <?php echo $product->get('pro_name'); ?>
            </a>
          </h3>
          <p class="text-sm text-gray-500"><?php echo $product->get('pro_description'); ?></p>
          <div class="flex-1 flex flex-col justify-end">
            <!--<p class="text-sm italic text-gray-500">8 colors</p>-->
            <p class="text-base font-medium text-gray-900">
			<?php 
			if(!$product->num_versions() && $product->get('pro_price_type') != Product::PRICE_TYPE_USER_CHOOSE){
				echo $currency_symbol.$product->get('pro_price');
			} 			
			?></p>
          </div>
        </div>
      </div>


	<?php } ?>
	</div>
<nav class="bg-white mt-3 px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6" aria-label="Pagination">
  <div class="hidden sm:block">
    <p class="text-sm text-gray-700">
      Showing
      <span class="font-medium"><?php echo $offsetdisp; ?></span>
      to
      <span class="font-medium"><?php echo $numperpage + $offset; ?></span>
      of
      <span class="font-medium"><?php echo $numrecords; ?></span>
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
		echo '<a class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50" href="'.$pager->get_url('+1', '').'">Next</a>';
	}
	?>
  </div>
</nav>
    
  </div>	
	

		<?php
  
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

