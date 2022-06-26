<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$post = new Post($_GET['pst_post_id'], TRUE);


	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => FALSE,
		'title' => $post->get('pst_title')
	);
	$page->public_header($hoptions); 
	
	echo PublicPageTW::BeginPage();	
	echo PublicPageTW::BeginPanel();
	

    ?>
    <div class="mt-6 prose prose-indigo prose-lg text-gray-500 mx-auto">
      <?php echo $post->get('pst_body'); ?>
    </div>
	<?php
			
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer(array('track'=>FALSE));
?>
