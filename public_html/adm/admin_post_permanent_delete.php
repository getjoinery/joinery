<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_post_permanent_delete_logic.php'));

$page_vars = process_logic(admin_post_permanent_delete_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'blog-posts',
	'page_title' => 'Post',
	'readable_title' => 'Delete Post',
	'breadcrumbs' => array(
		'Posts'=>'/admin/admin_posts',
		'Delete ' . $post->get('pst_title') => '',
	),
	'session' => $session,
)
);

$pageoptions['title'] = 'Delete Post '.$post->get('pst_title');
$page->begin_box($pageoptions);

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form();

echo '<fieldset><h4>Confirm Delete</h4>';
	echo '<div class="fields full">';
	echo '<p>WARNING:  This will permanently delete this post ('.$post->get('pst_title') . ').</p>';

$formwriter->hiddeninput('confirm', '', ['value' => 1]);
$formwriter->hiddeninput('pst_post_id', '', ['value' => $pst_post_id]);

$formwriter->submitbutton('btn_submit', 'Submit');

	echo '</div>';
echo '</fieldset>';
echo $formwriter->end_form();

$page->end_box();

$page->admin_footer();
?>
