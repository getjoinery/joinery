<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once('includes/PublicPage.php');
	require_once('includes/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/page_contents_class.php');

	$session = SessionControl::get_instance();
	
	if($params[0] != 'page'){
		include("404.php");
		exit();
	}

	$page_content = PageContent::get_by_link($params[1]);

	if(!$page_content || !$page_content->get('pac_is_published')){
		include("404.php");
		exit();
	}
	
	if(!$page_content->get('pac_link') && $page_content->get('pac_script_filename')){
		//THIS IS A STANDALONE FILE
		include($page_content->get('pac_script_filename'));
		exit();
	}	
	
	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => $page_content->get('pac_title'),
	'profilenav' => TRUE,
	));
	

	?>
	
	<div class="section-content">
		<article id="post-746" class="typology-post typology-single-post post-746 post type-post status-publish format-standard hentry category-uncategorized">
            <header class="entry-header">
                <h1 class="entry-title entry-title-cover-empty"><?php echo $page_content->get('pac_title'); ?></h1>   
				<div class="post-letter"><?php echo $page_content->get('pac_title')[0]; ?></div>    
            </header>

                
			<div class="entry-content clearfix">
            <?php 
			echo '<div>'. $page_content->get_filled_content() . '</div>';
			?>
			</div>
		</article>	
	</div>
	<?php


	$page->public_footer($foptions=array('track'=>TRUE));
?>

