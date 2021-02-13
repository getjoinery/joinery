<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_logic_file_path('blog_logic.php'));

	header('Content-type: application/rss+xml; charset=utf-8');
	
	$settings = Globalvars::get_instance();

	//FORMAT:  https://www.tutorialspoint.com/rss/rss0.91-tag-syntax.htm
	//echo '<!DOCTYPE rss SYSTEM "http://www.silmaril.ie/software/rss2.dtd">'; //FOR RSS 2.0
 	
	echo '<?xml version="1.0" encoding="utf-8"?>';
	echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">';

	echo '<channel>';
	echo '<atom:link href="'.$settings->get_setting('webDir_SSL').'/rss20_feed" rel="self" type="application/rss+xml" />';
	echo '<title>'.$settings->get_setting('site_name').'</title>
	<link>'.$settings->get_setting('webDir_SSL').'</link>
	<description/>';

	foreach ($posts as $post){  

		//ESCAPE SPECIAL CHARACTERS
		$title = htmlentities( $post->get('pst_title'), ENT_QUOTES ); 


		echo '<item><title>'.$title.'</title>
		<description><![CDATA['.$post->get('pst_short_description').']]></description>
		<link>'.$settings->get_setting('webDir_SSL').'/'.$post->get('pst_link').'</link>
		<guid>'.$settings->get_setting('webDir_SSL').'/'.$post->get('pst_link').'</guid>
		<pubDate>'.LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York', DATE_RSS).'</pubDate>
		</item>';

	}

	echo '</channel>
	</rss>';

?>