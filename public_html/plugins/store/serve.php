<?php
/**
 * Store plugin request bootstrap.
 *
 * Core loads this on every request for the active store plugin (inside an
 * output buffer that is discarded — only the static registrations persist).
 * It is where the store wires itself into the platform's extension points and
 * runs its per-request marketing-coupon capture. Top-level store URLs are
 * declared in core serve.php with the `plugin => 'store'` option, not here —
 * plugins cannot own top-level dynamic routes.
 *
 * @version 1.0.0
 */

// ---- SEO entity: product (moved out of core SeoPageMetadata defaults) ----
require_once(PathHelper::getIncludePath('data/seo_page_metadata_class.php'));
SeoPageMetadata::register_entity_class(
	'product', 'Product', 'MultiProduct',
	'plugins/store/data/products_class.php', 'product',
	'/admin/admin_product_edit?pro_product_id=', 'product'
);

// ---- Tier gated-content summary: Products (moved out of core defaults) ----
require_once(PathHelper::getIncludePath('includes/TierGatedContentRegistry.php'));
TierGatedContentRegistry::register('Products', 'pro_products', 'pro_tier_min_level', 'pro_delete_time');

// ---- Entity photos: product (moved out of core EntityPhotoRegistry defaults) ----
require_once(PathHelper::getIncludePath('includes/EntityPhotoRegistry.php'));
EntityPhotoRegistry::register('product', 'Product', 'plugins/store/data/products_class.php');

// ---- Header menu: the cart link + item-count badge ----
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/header_menu_cart_provider.php'));
PublicPageBase::register_header_menu_provider('cart', 'store_header_menu_cart_provider');

// ---- Profile dashboard sections: recent orders + active subscriptions ----
// The native-app dashboard summary (profile_dashboard_logic) iterates this
// registry; with the store inactive nothing is contributed.
require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/profile_dashboard_provider.php'));
ProfileDashboardRegistry::register('recent_orders', 'store_dashboard_recent_orders');
ProfileDashboardRegistry::register('subscriptions', 'store_dashboard_subscriptions');

// ---- Admin-user detail panels (point 4/5) ----
// The admin-user Orders/Subscriptions panels are still built inline in
// adm/admin_user.php (setting-gated, so a store-inactive install shows nothing).
// Their provider conversion + AdminUserPanelRegistry registration land next.

// ---- Marketing-coupon capture (?coupon=CODE) ----
// Owned by the store now — moved out of SessionControl / RouteHelper.
if (isset($_GET['coupon'])) {
	ShoppingCart::capture_marketing_coupon(SessionControl::get_instance());
}
