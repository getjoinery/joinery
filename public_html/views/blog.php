<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('blog_logic.php'));
 	

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Blog'
	);
	$page->public_header($hoptions); 
	
	echo PublicPage::BeginPage('Blog');		
	
	foreach ($posts as $post){  
		$author = new User($post->get('pst_usr_user_id'), TRUE);

		echo '<h2><a href="'.$post->get('pst_link').'">'.$post->get('pst_title').'</a></h2>';
		
		echo '<div>By '.$author->display_name().' at '.LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York').'</div>';

		echo'<p>';					
		if($post->get('pst_short_description')){
			echo $post->get('pst_short_description');
		}
		else{
			echo substr(strip_tags($post->get('pst_body')),0,300) . '...'; 
		}
		echo '</p>';
        echo '<div><a href="'.$post->get('pst_link').'">Read on</a></div>';
	} 
		
		
		
	if($pager->is_valid_page('-1')){
		echo '<a href="'.$pager->get_url('-1', '').'">Previous Page</a>';
	}
	
	
	if($pager->is_valid_page('+1')){
		echo ' <a href="'.$pager->get_url('+1', '').'">Next Page</a>';
	}
			

	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>