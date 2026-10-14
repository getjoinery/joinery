<?php
/**
 * Forum plugin bootstrap — the plugin's declared load point (the top-level
 * `bootstrap` key in plugin.json), loaded once per request by PluginBootstraps
 * whenever a registry needs the plugin's callbacks live.
 *
 * It names the forum's space type, so the shared mention picker can take
 * 'forum_category:{id}'; registers topics and categories as SEO entities
 * (here rather than in serve.php, so the command-line sitemap and SEO sweep
 * know them too); and counts the posts waiting for approval beside their
 * admin menu entry.
 *
 * @version 1.0.0
 */

SpaceAccess::registerType('forum_category', 'ForumCategory');

// ---- SEO: public topics get the single emitter's tags and a sitemap entry ----
// Only topics in public categories, live and approved (ForumTopic::seo_public_records).
require_once(PathHelper::getIncludePath('data/seo_page_metadata_class.php'));
SeoPageMetadata::register_entity_class(
	'forum_topic', 'ForumTopic', 'MultiForumTopic',
	'plugins/forum/data/forum_topics_class.php', 'forum/t',
	'/plugins/forum/admin/admin_forum_topics?ftp_forum_topic_id=', 'article'
);

SeoPageMetadata::register_entity_class(
	'forum_category', 'ForumCategory', 'MultiForumCategory',
	'plugins/forum/data/forum_categories_class.php', 'forum',
	'/plugins/forum/admin/admin_forum_category?fcg_forum_category_id=', 'website'
);


if (class_exists('ContentReportRegistry')) {
	ContentReportRegistry::register(new ForumReportHandler());
}

if (class_exists('AdminMenuCounts')) {
	AdminMenuCounts::register('forum-admin-held', function () {
		$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM fpo_forum_posts fpo
			JOIN ftp_forum_topics ftp ON ftp.ftp_forum_topic_id = fpo.fpo_ftp_forum_topic_id AND ftp.ftp_delete_time IS NULL
			WHERE fpo.fpo_is_approved = FALSE AND fpo.fpo_delete_time IS NULL');
		$q->execute();
		return (int)$q->fetchColumn();
	});
}
