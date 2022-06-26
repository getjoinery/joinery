<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$post = new Post($_GET['pst_post_id'], TRUE);
	//GET AUTHOR
	$author = new User($post->get('pst_usr_user_id'), TRUE);
	$tags = $post->get_tags();

	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => FALSE,
		'title' => $post->get('pst_title')
	);
	$page->public_header($hoptions); 
	
	echo PublicPageTW::BeginPage();	
	echo PublicPageTW::BeginPanel();
	

    ?>
    <div class="text-lg max-w-prose mx-auto">
      <h1>
        <span class="block text-base text-center text-indigo-600 font-semibold tracking-wide uppercase">Blog</span>
        <span class="mt-2 mb-4 block text-3xl text-center leading-8 font-extrabold tracking-tight text-gray-900 sm:text-4xl"><?php echo $post->get('pst_title'); ?></span>
      </h1>
				<p class="text-base text-gray-500 text-center">
					<?php echo $author->display_name().' at '; ?>
				  <time datetime="2020-03-16"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></time>
				</p>
	<div class="flow-root text-center">
		<?php
		foreach ($tags as $tag){
			echo '<a href="/blog/tag/'.urlencode($tag).'" class="inline-block p-1">
			<span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">'.$tag.'</span>
			</a>';			
		}
		?>				   
	</div> 
    
    <div class="mt-6 prose prose-indigo prose-lg text-gray-500 mx-auto">
      <?php echo $post->get('pst_body'); ?>
    </div>
	</div>
	<?php
			
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer(array('track'=>FALSE));
?>
