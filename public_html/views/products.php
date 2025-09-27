<?php
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('products_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = products_logic($_GET, $_POST);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Products'
	));
	echo PublicPage::BeginPage('Products');
	echo PublicPage::BeginPanel();
?>
<div class="max-w-2xl mx-auto pb-16 px-4 sm:py-24 sm:px-6 lg:max-w-7xl lg:px-8">
    <h2 class="sr-only">Products</h2>
	<div class="grid grid-cols-1 gap-y-4 sm:grid-cols-2 sm:gap-x-6 sm:gap-y-10 lg:grid-cols-3 lg:gap-x-8">

	<?php foreach ($page_vars['products'] as $product){ ?>

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
			/*if(!$product->count_product_versions()){
				echo $page_vars['currency_symbol'].$product->get('pro_ce');
			}*/	
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
      <span class="font-medium"><?php echo $page_vars['offsetdisp']; ?></span>
      to
      <span class="font-medium"><?php echo $page_vars['numperpage'] + $page_vars['offset']; ?></span>
      of
      <span class="font-medium"><?php echo $page_vars['numrecords']; ?></span>
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
		echo '<a class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50" href="'.$page_vars['pager']->get_url('+1', '').'">Next</a>';
	}
	?>
  </div>
</nav>
    
  </div>	

		<?php
  
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

