<?php
/**
 * Store plugin bootstrap - the plugin's declared load point (the top-level
 * `bootstrap` key in plugin.json), loaded once per request or command by
 * PluginBootstraps whenever the plugin is active. Registrations only.
 *
 * It registers products as SEO entities (here rather than in serve.php, so the
 * command-line sitemap and SEO sweep know them too).
 *
 * @version 1.0.0
 */

// ---- SEO entity: product ----
SeoPageMetadata::register_entity_class(
	'product', 'Product', 'MultiProduct',
	'plugins/store/data/products_class.php', 'product',
	'/plugins/store/admin/admin_product_edit?pro_product_id=', 'product'
);
