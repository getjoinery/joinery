<?php
/** @joinery-admin class=personal */

require_once(PathHelper::getIncludePath('plugins/email_lists/admin/logic/admin_mailing_lists_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_mailing_lists_logic(array_merge($_GET, $_POST)));

$session = $page_vars['session'];
$mailing_lists = $page_vars['mailing_lists'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'mailing-lists',
	'page_title' => 'Mailing Lists',
	'readable_title' => 'Mailing Lists',
	'breadcrumbs' => array(
		'Emails'=>'/plugins/email_lists/admin/admin_emails',
		'Mailing Lists' => '',
	),
	'session' => $session,
)
);

$headers = array("Mailing List",  "Description", "# Registrants");
$altlinks = array('New Mailing List' => '/plugins/email_lists/admin/admin_mailing_list_edit');
if ($page_vars['has_provider']) {
	$altlinks['Sync with provider now'] = array('post' => '/plugins/email_lists/admin/admin_mailing_lists',
		'hidden' => array('action' => 'sync_now'), 'confirm' => 'Sync every list with the provider now? It can take a few minutes.');
}
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Mailing Lists',
);
$held = EmailListSubscriptions::held_today();
if ($held > 0) {
	echo '<div class="alert alert-warning">' . (int)$held . ' confirmation email(s) were not sent today: the daily limits on the plugin settings tab held them. Those people were told to check their email; they can sign up again tomorrow.</div>';
}
$page->tableheader($headers, $table_options, $pager);

foreach ($mailing_lists as $mailing_list){

	$deleted = '';
	if($mailing_list->get('mlt_delete_time')){
		$deleted = ' DELETED ';
	}

	$rowvalues = array();
	array_push($rowvalues, '<a href="/plugins/email_lists/admin/admin_mailing_list?mlt_mailing_list_id='.$mailing_list->key.'">'.$mailing_list->get('mlt_name').'</a>' . $deleted);
	array_push($rowvalues, $mailing_list->get('mlt_description'));

	$numusers = $mailing_list->count_subscribed_users();
	array_push($rowvalues, $numusers. ' registrants');

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
