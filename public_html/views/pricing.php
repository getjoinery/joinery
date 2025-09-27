<?php
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('pricing_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = pricing_logic($_GET, $_POST);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Pricing'
	));
	echo PublicPage::BeginPage('Pricing');
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
				echo $product->get_readable_price();		
			?></p>
          </div>
        </div>
      </div>

	<?php } ?>
	</div>

  </div>	

		<?php
  
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

