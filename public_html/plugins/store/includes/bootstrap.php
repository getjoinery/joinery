<?php
/**
 * Store plugin bootstrap - the plugin's declared load point (the top-level
 * `bootstrap` key in plugin.json), loaded once per request or command by
 * PluginBootstraps whenever the plugin is active. Registrations only.
 *
 * It registers products as SEO entities (here rather than in serve.php, so the
 * command-line sitemap and SEO sweep know them too).
 *
 * It also names the pages of a purchase under way to the setup wizard, which
 * leaves them alone, so someone who signs up to buy pays before it starts,
 * and the subscription and billing pages, so someone who came to cancel or
 * change a card does that first.
 *
 * @version 1.2.0 - the subscription and billing pages are quiet for the setup wizard
 * @version 1.1.0 - the cart and checkout pages are quiet for the setup wizard (SetupSteps::quietOn)
 * @version 1.0.0
 */

// ---- SEO entity: product ----
SeoPageMetadata::register_entity_class(
	'product', 'Product', 'MultiProduct',
	'plugins/store/data/products_class.php', 'product',
	'/plugins/store/admin/admin_product_edit?pro_product_id=', 'product'
);

// ---- Setup wizard: a purchase under way is not interrupted, and neither is
// someone managing what they pay for (cancelling, changing the card) ----
foreach (array('/cart', '/checkout', '/cart_charge', '/cart_confirm',
		'/profile/subscriptions', '/profile/billing') as $store_quiet_path) {
	SetupSteps::quietOn($store_quiet_path);
}
