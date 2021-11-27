<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('products_logic.php'));
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Products'
	));
	echo PublicPage::BeginPage('Products');


	
	?>
	<div class="section padding-top-20">
		<div class="container">
			<?php



	foreach ($products as $product){
		//$now = LibraryFunctions::get_current_time_obj('UTC');
		//$product_time = LibraryFunctions::get_time_obj($product->get('pro_start_time'), 'UTC');
		?>
		<div class="row align-items-center col-spacing-50">
			<div class="col-12 col-md-6">
				<?php
				//if($pic = $product->get_picture_link('small')){
				//	echo '<img src="'.$pic.'" alt="">';
				//}
				?>
			</div>
			<div class="col-12 col-md-6">
				<h4 class=" font-weight-normal "><?php echo $product->get('pro_name'); ?></h4>
				<h6 class="font-family-tertiary font-small font-weight-normal uppercase">
				test
				</h6>
				<p><?php echo $product->get('pro_description'); ?></p>
				<a class="button button-xs button-dark button-rounded margin-top-20 margin-bottom-30" href="<?php echo $product->get_url(); ?>">Read More</a>
				<br><br>
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
	
			</div><!-- end container -->
		</div>
		<?php
  
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

