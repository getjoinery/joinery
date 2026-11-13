<?php
/**
 * Email Lists plugin bootstrap - the plugin's declared load point (the top-level
 * `bootstrap` key in plugin.json), loaded once per request or command by
 * PluginBootstraps whenever the plugin is active. Registrations only.
 *
 * It registers mailing lists as SEO entities (here rather than in serve.php, so the
 * command-line sitemap and SEO sweep know them too).
 *
 * @version 1.0.0
 */

// ---- SEO entity: a mailing list's public page ----
SeoPageMetadata::register_entity_class(
	'mailing_list', 'MailingList', 'MultiMailingList',
	'plugins/email_lists/data/mailing_lists_class.php', 'list',
	'/plugins/email_lists/admin/admin_mailing_list_edit?mlt_mailing_list_id=', 'website'
);
