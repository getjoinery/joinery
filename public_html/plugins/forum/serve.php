<?php
/**
 * Forum plugin request bootstrap and routes.
 *
 * Core loads this on every request while the plugin is active (inside a
 * discarded output buffer — only the static registrations and $routes persist).
 *
 * The forum home (/forum) and the search page (/forum/search) come from view
 * auto-discovery. The category and topic pages carry placeholders, so they are
 * routes here, inside the plugin's /forum namespace. A topic is found by its id;
 * the slug after it is for readers and search engines, and a stale one redirects
 * to the current one.
 *
 * @version 1.0.0
 */

// ---- Tier gated-content summary: tier-gated categories ----
require_once(PathHelper::getIncludePath('includes/TierGatedContentRegistry.php'));
TierGatedContentRegistry::register('Forum categories', 'fcg_forum_categories', 'fcg_tier_min_level', 'fcg_delete_time');

// ---- Content access gate: files attached to posts ----
require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
AccessGateRegistry::register(new ForumAttachmentGate());

$routes = array(
	'dynamic' => array(
		// Every query is a page of its own: never cached (StaticPageCache would keep one per query).
		'/forum/search'          => array('view' => 'views/search', 'check_setting' => 'forum_active', 'static_cache' => false),
		'/forum/t/{id}/{slug}'   => array('view' => 'views/topic', 'check_setting' => 'forum_active'),
		'/forum/t/{id}'          => array('view' => 'views/topic', 'check_setting' => 'forum_active'),
		'/forum/{slug}'          => array('view' => 'views/category', 'check_setting' => 'forum_active'),
		'/forum'                 => array('view' => 'views/index', 'check_setting' => 'forum_active'),
	),
);
