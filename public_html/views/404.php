<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

	$page = new PublicPage();
	$hoptions = array(
		//'title' => $sitename,
		//'description' => 'Integral Zen',
		'is_404' => 1,
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Page not found');
	?>

	<h2>This page may have moved or is no longer available</h2>

	<p>Here are links to pages you might be looking for:</p>
	<ul>
		<li><a href="/profile">Your profile, a list of all of your registrations and purchases</a>.</li>
		<li><a href="/events">View our upcoming retreats and events</a>.</li>
		<li><a href="/community/newsletter/">Make a donation</a>.</li>
		<li><a href="/resources/media-library/">Explore our videos</a>.</li>
		<li><a href="/community/newsletter/">Sign up for the newsletter</a>.</li>
		<li><a href="/offerings/scheduling/">Schedule a meeting</a>.</li>
	</ul>

	<?php
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE, 'is_404'=> 1));
?>