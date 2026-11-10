<?php
/**
 * Email Lists plugin request bootstrap.
 *
 * Core loads this on every request while the plugin is active (inside an
 * output buffer that is discarded; only the static registrations persist).
 * It wires mailing lists and campaigns into the platform's extension points.
 * The top-level list URLs (/list/{slug}, /lists) are declared in core
 * serve.php with `plugin => 'email_lists'`; plugins cannot own top-level
 * dynamic routes.
 *
 * @version 1.0.0
 */

// ---- SEO entity: a mailing list's public page ----
SeoPageMetadata::register_entity_class(
	'mailing_list', 'MailingList', 'MultiMailingList',
	'plugins/email_lists/data/mailing_lists_class.php', 'list',
	'/plugins/email_lists/admin/admin_mailing_list_edit?mlt_mailing_list_id=', 'website'
);

// ---- Entity photo: a mailing list's image ----
EntityPhotoRegistry::register('mailing_list', 'MailingList', 'plugins/email_lists/data/mailing_lists_class.php');

// ---- Audience: a mailing list's subscribers, for campaigns ----
RecipientGroupProviderRegistry::register(new MailingListRecipientProvider());

// ---- Checkout: "Keep me updated by email", when the store is active ----
if (class_exists('AbstractProductRequirement')) {
	AbstractProductRequirement::register('NewsletterSignupRequirement',
		PathHelper::getIncludePath('plugins/email_lists/includes/NewsletterSignupRequirement.php'));
}

// ---- Member email preferences: the mailing-list section (G6) ----
EmailListPreferences::register_preferences_section();

// ---- Account creation: "add me to the list" on registration and admin add-user (G5) ----
EmailListPreferences::register_signup_field();

// ---- Member dashboard: the lists the member is on (G1) ----
ProfileDashboardRegistry::register('mailing_lists', array('EmailListDashboard', 'section'));

// ---- Admin user page: the member's lists and the campaigns they got (G2, G3) ----
AdminUserPanelRegistry::register(new MailingListsUserPanel());

// ---- Group and event admin pages: the campaigns aimed at them (G4) ----
AdminEntityPanelRegistry::register('group', new CampaignsToAudiencePanel('group', 'Campaigns to this group'));
AdminEntityPanelRegistry::register('event', new CampaignsToAudiencePanel('event', 'Campaigns to registrants'));
AdminEntityPanelRegistry::register('event', new CampaignsToAudiencePanel('event_waiting_list', 'Campaigns to the waiting list'));
