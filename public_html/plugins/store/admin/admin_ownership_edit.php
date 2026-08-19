<?php
/**
 * One ownership — the detail view, with revoke / un-revoke.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/store/admin/logic/admin_ownership_edit_logic.php'));

$page_vars = process_logic(admin_ownership_edit_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$self = '/plugins/store/admin/admin_ownership_edit?own_ownership_id=' . $ownership->key;
$is_revoked = (bool)$ownership->get('own_revoked_time');
$tag = $ownership->get('own_tag');

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id' => 'ownership',
	'page_title' => 'Ownership',
	'readable_title' => 'Ownership',
	'breadcrumbs' => array(
		'Products' => '/plugins/store/admin/admin_products',
		'Ownership' => '/plugins/store/admin/admin_ownerships',
		$tag => '',
	),
	'session' => $session,
)
);

$page->begin_box(array('title' => 'Ownership'));

echo '<p><strong>Owner:</strong> ';
if ($owner->key) {
	echo '<a href="/admin/admin_user?usr_user_id=' . $owner->key . '">'
		. htmlspecialchars($owner->display_name()) . '</a> ('
		. htmlspecialchars($owner->get('usr_email')) . ')';
}
else {
	echo 'Unknown user';
}
echo '</p>';

echo '<p><strong>Tag:</strong> <code>' . htmlspecialchars($tag) . '</code>';
if ($tag === Ownership::TAG_ALL) {
	echo ' &mdash; all-access: this covers every own-once product in the store.';
}
echo '</p>';

echo '<p><strong>Created:</strong> ' . $ownership->get_local('own_create_time') . '</p>';

echo '<p><strong>Came from:</strong> ';
if ($ownership->get('own_ord_order_id')) {
	echo '<a href="/plugins/store/admin/admin_order?ord_order_id=' . $ownership->get('own_ord_order_id')
		. '">Order #' . $ownership->get('own_ord_order_id') . '</a>';
	if ($ownership->get('own_odi_order_item_id')) {
		echo ' (line item #' . $ownership->get('own_odi_order_item_id') . ')';
	}
}
else {
	echo 'Granted by hand &mdash; no order attached.';
}
echo '</p>';

echo '<p><strong>Status:</strong> ';
if ($is_revoked) {
	echo 'Revoked ' . $ownership->get_local('own_revoked_time')
		. ' &mdash; this buyer can purchase this tag again.';
}
else {
	echo 'Active &mdash; this buyer will not be charged for this tag again.';
}
echo '</p>';

if ($ownership->get('own_license_key')) {
	echo '<p><strong>License key:</strong> '
		. AdminPage::copy_field($ownership->get('own_license_key')) . '</p>';
	echo '<p class="muted">Written once by the product\'s key-minting script. There is no reissue &mdash; '
		. 'revoke and grant again to replace it.</p>';
}

echo '<p>';
if ($is_revoked) {
	echo AdminPage::action_button('Un-revoke', $self, array(
		'hidden' => array('action' => 'unrevoke'),
		'confirm' => 'Restore this ownership? The buyer will own this again and will not be able to purchase it.',
		'class' => 'btn btn-primary btn-sm',
	));
}
else {
	echo AdminPage::action_button('Revoke', $self, array(
		'hidden' => array('action' => 'revoke'),
		'confirm' => 'Revoke this ownership? The buyer will be able to purchase this tag again.',
		'class' => 'btn btn-danger btn-sm',
	));
}
echo '</p>';

$page->end_box();

$page->begin_box(array('title' => 'What this covers'));
if ($covered_products->count()) {
	echo '<ul>';
	foreach ($covered_products as $covered_product) {
		echo '<li><a href="/plugins/store/admin/admin_product?pro_product_id=' . $covered_product->key . '">'
			. htmlspecialchars($covered_product->get('pro_name')) . '</a></li>';
	}
	echo '</ul>';
}
else {
	echo '<p class="muted">No product currently carries this tag. The ownership still stands &mdash; '
		. 'tag a product with <code>' . htmlspecialchars($tag) . '</code> and this buyer already owns it.</p>';
}
$page->end_box();

$page->admin_footer();
?>
