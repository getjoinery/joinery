<?php
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));

	$page_vars = blog_logic($_GET, $_POST);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	
	header('Content-type: application/rss+xml; charset=utf-8');
	
	$page_vars['settings'] = Globalvars::get_instance();

	//FORMAT:  https://www.tutorialspoint.com/rss/rss0.91-tag-syntax.htm
	//echo '<!DOCTYPE rss SYSTEM "http://www.silmaril.ie/software/rss2.dtd">'; //FOR RSS 2.0
 	
	echo '<?xml version="1.0" encoding="utf-8"?>';
	echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">';

	echo '<channel>';
	echo '<atom:link href="'.LibraryFunctions::get_absolute_url('/rss20_feed').'" rel="self" type="application/rss+xml" />';
	echo '<title>'.$page_vars['settings']->get_setting('site_name').'</title>
	<link>'.LibraryFunctions::get_absolute_url('').'</link>
	<description/>';

	foreach ($posts as $post){  

		//ESCAPE SPECIAL CHARACTERS
		$title = htmlentities( $post->get('pst_title'), ENT_QUOTES ); 

		echo '<item><title>'.$title.'</title>
		<description><![CDATA['.$post->get('pst_short_description').']]></description>
		<link>'.LibraryFunctions::get_absolute_url($post->get_url()).'</link>
		<guid>'.LibraryFunctions::get_absolute_url($post->get_url()).'</guid>
		<pubDate>'.LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York', DATE_RSS).'</pubDate>
		</item>';

	}

	echo '</channel>
	</rss>';

?>