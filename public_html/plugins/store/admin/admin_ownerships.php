<?php
/**
 * Ownership — who owns what.
 *
 * A product with an ownership tag can only be bought once per person. This page
 * lists those ownerships, lets you revoke one (which re-opens purchase, the
 * companion to a refund) and lets you grant one by hand for comps and support.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/store/admin/logic/admin_ownerships_logic.php'));

$page_vars = process_logic(admin_ownerships_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$self = '/plugins/store/admin/admin_ownerships';

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id' => 'ownership',
	'page_title' => 'Ownership',
	'readable_title' => 'Ownership',
	'breadcrumbs' => array(
		'Products' => '/plugins/store/admin/admin_products',
		'Ownership' => '',
	),
	'session' => $session,
)
);

$page->begin_box(array('title' => 'What ownership means'));
echo '<p>Some products can only sensibly be owned once — a course, a lifetime unlock, an all-access '
	. 'bundle. Give such a product an <strong>ownership tag</strong> on its edit page and the store '
	. 'guarantees one purchase per buyer per tag: the buyer sees &ldquo;You already own this&rdquo; '
	. 'instead of a buy button, and checkout refuses to charge them again. Products sharing a tag '
	. 'count as the same thing; the tag <code>*</code> covers every tag in this store.</p>';
echo '<p class="muted">Revoking an ownership re-opens purchase for that buyer — it is the companion '
	. 'to a refund or a chargeback. Ownership never changes a price; it only decides what may be bought.</p>';
$page->end_box();

// ---- Filters ----
$filter_form = $page->getFormWriter('filters', array('method' => 'GET', 'action' => $self));
$filter_form->begin_form();
$tag_options = array('' => '-- Any tag --');
foreach ($tags_in_use as $tag_in_use) {
	$tag_options[$tag_in_use] = $tag_in_use;
}
$tag_options[Ownership::TAG_ALL] = Ownership::TAG_ALL . ' (all-access)';
if ($filter_tag !== '' && !isset($tag_options[$filter_tag])) {
	$tag_options[$filter_tag] = $filter_tag;
}
$filter_form->dropinput('tag', 'Tag', array(
	'options' => $tag_options,
	'value' => $filter_tag,
));
$user_options = array();
if ($filter_user_id) {
	$filter_user = new User($filter_user_id, TRUE);
	if ($filter_user->key) {
		$user_options[$filter_user->display_name() . ' - ' . $filter_user->get('usr_email')] = $filter_user->key;
	}
}
$filter_form->dropinput('u', 'Owner', array(
	'options' => $user_options,
	'value' => $filter_user_id ?: '',
	'validation' => array('required' => false),
	'ajaxendpoint' => '/api/v1/action/user_search?includenone=1',
	'empty_option' => '-- Type 3+ characters to search users --',
));
$filter_form->dropinput('status', 'Status', array(
	'options' => array('' => '-- Any --', 'active' => 'Active', 'revoked' => 'Revoked'),
	'value' => $filter_status,
));
$filter_form->submitbutton('submit_button', 'Filter');
$filter_form->end_form();

// ---- List ----
$headers = array('Owner', 'Tag', 'Order', 'Created', 'Status', '');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$page->tableheader($headers, array('title' => 'Ownerships'), $pager);

foreach ($ownerships as $ownership) {
	$rowvalues = array();

	$owner = new User($ownership->get('own_usr_user_id'), TRUE);
	$rowvalues[] = $owner->key
		? '<a href="/admin/admin_user?usr_user_id=' . $owner->key . '">'
			. htmlspecialchars($owner->display_name()) . '</a>'
		: 'Unknown user';

	$tag = $ownership->get('own_tag');
	$tag_label = ($tag === Ownership::TAG_ALL)
		? '<code>*</code> (all-access)'
		: '<code>' . htmlspecialchars($tag) . '</code>';
	$rowvalues[] = '<a href="/plugins/store/admin/admin_ownership_edit?own_ownership_id='
		. $ownership->key . '">' . $tag_label . '</a>';

	$rowvalues[] = $ownership->get('own_ord_order_id')
		? '<a href="/plugins/store/admin/admin_order?ord_order_id=' . $ownership->get('own_ord_order_id') . '">#'
			. $ownership->get('own_ord_order_id') . '</a>'
		: '<span class="muted">Granted by hand</span>';

	$rowvalues[] = $ownership->get_local('own_create_time', 'M j, Y');

	$revoked = (bool)$ownership->get('own_revoked_time');
	$rowvalues[] = $revoked
		? 'Revoked ' . $ownership->get_local('own_revoked_time', 'M j, Y')
		: 'Active';

	if ($revoked) {
		$rowvalues[] = AdminPage::action_button('Un-revoke', $self, array(
			'hidden' => array('action' => 'unrevoke', 'own_ownership_id' => $ownership->key),
			'confirm' => 'Restore this ownership? ' . htmlspecialchars($owner->display_name())
				. ' will own this again and will not be able to purchase it.',
		));
	}
	else {
		$rowvalues[] = AdminPage::action_button('Revoke', $self, array(
			'hidden' => array('action' => 'revoke', 'own_ownership_id' => $ownership->key),
			'confirm' => 'Revoke this ownership? ' . htmlspecialchars($owner->display_name())
				. ' will be able to purchase this tag again.',
		));
	}

	$page->disprow($rowvalues);
}

$page->endtable($pager);

// ---- Manual grant ----
$page->begin_box(array('title' => 'Grant ownership by hand'));
echo '<p class="muted">For comps and support cases. The grant carries no order and no license key.</p>';
$grant_form = $page->getFormWriter('grant', array('method' => 'POST', 'action' => $self));
$grant_form->begin_form();
$grant_form->hiddeninput('action', '', array('value' => 'grant'));
$grant_form->dropinput('grant_usr_user_id', 'Owner', array(
	'options' => array(),
	'ajaxendpoint' => '/api/v1/action/user_search?includenone=1',
	'empty_option' => '-- Type 3+ characters to search users --',
	'validation' => array('required' => true),
));
$grant_tag_helptext = 'The tag to grant. A single asterisk grants every own-once product.';
if (!empty($tags_in_use)) {
	$grant_tag_helptext .= ' In use here: ' . htmlspecialchars(implode(', ', $tags_in_use)) . '.';
}
$grant_form->textinput('grant_tag', 'Tag', array(
	'validation' => array('required' => true, 'maxlength' => 64),
	'helptext' => $grant_tag_helptext,
));
$grant_form->submitbutton('btn_grant', 'Grant ownership');
$grant_form->end_form();
$page->end_box();

$page->admin_footer();
?>
