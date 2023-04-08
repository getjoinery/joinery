<?php

	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');

	require_once( __DIR__ . '/../data/activation_codes_class.php');	
	require_once( __DIR__ . '/../data/address_class.php');
	require_once( __DIR__ . '/../data/admin_menus_class.php');
	require_once( __DIR__ . '/../data/bookings_class.php');
	require_once( __DIR__ . '/../data/comments_class.php');
	require_once( __DIR__ . '/../data/content_versions_class.php');
	require_once( __DIR__ . '/../data/coupon_codes_class.php');
	require_once( __DIR__ . '/../data/coupon_code_products_class.php');
	require_once( __DIR__ . '/../data/debug_email_logs_class.php');
	require_once( __DIR__ . '/../data/emails_class.php');
	require_once( __DIR__ . '/../data/email_recipients_class.php');
	require_once( __DIR__ . '/../data/email_templates_class.php');
	require_once( __DIR__ . '/../data/events_class.php');
	require_once( __DIR__ . '/../data/event_logs_class.php');
	require_once( __DIR__ . '/../data/event_registrants_class.php');
	require_once( __DIR__ . '/../data/event_sessions_class.php');
	require_once( __DIR__ . '/../data/event_session_files_class.php');
	require_once( __DIR__ . '/../data/session_analytics_class.php');
	require_once( __DIR__ . '/../data/event_types_class.php');
	require_once( __DIR__ . '/../data/files_class.php');
	require_once( __DIR__ . '/../data/general_errors_class.php');
	require_once( __DIR__ . '/../data/groups_class.php');
	require_once( __DIR__ . '/../data/group_members_class.php');
	require_once( __DIR__ . '/../data/log_form_errors_class.php');
	require_once( __DIR__ . '/../data/messages_class.php');
	require_once( __DIR__ . '/../data/orders_class.php');
	require_once( __DIR__ . '/../data/order_items_class.php');
	require_once( __DIR__ . '/../data/page_contents_class.php');
	require_once( __DIR__ . '/../data/phone_number_class.php');
	require_once( __DIR__ . '/../data/posts_class.php');
	require_once( __DIR__ . '/../data/products_class.php');
	require_once( __DIR__ . '/../data/product_versions_class.php');
	require_once( __DIR__ . '/../data/product_details_class.php');
	require_once( __DIR__ . '/../data/product_groups_class.php');
	require_once( __DIR__ . '/../data/public_menus_class.php');
	require_once( __DIR__ . '/../data/questions_class.php');
	require_once( __DIR__ . '/../data/question_options_class.php');
	require_once( __DIR__ . '/../data/queued_email_class.php');
	//require_once( __DIR__ . '/../data/recurring_mailer_class.php');
	require_once( __DIR__ . '/../data/settings_class.php');	
	require_once( __DIR__ . '/../data/stripe_invoices_class.php');	
	require_once( __DIR__ . '/../data/surveys_class.php');	
	require_once( __DIR__ . '/../data/survey_answers_class.php');	
	require_once( __DIR__ . '/../data/survey_questions_class.php');	
	require_once( __DIR__ . '/../data/urls_class.php');	
	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/videos_class.php');	
	require_once( __DIR__ . '/../data/visitor_events_class.php');	
	require_once( __DIR__ . '/../data/pages_class.php');	
	require_once( __DIR__ . '/../data/components_class.php');
	require_once( __DIR__ . '/../data/product_requirements_class.php');
	require_once( __DIR__ . '/../data/product_requirement_instances_class.php');
	require_once( __DIR__ . '/../data/order_item_requirements_class.php');
	require_once( __DIR__ . '/../data/email_recipient_groups_class.php');
	require_once( __DIR__ . '/../data/contact_types_class.php');
	require_once( __DIR__ . '/../data/mailing_lists_class.php');
	require_once( __DIR__ . '/../data/mailing_list_registrants_class.php');
	require_once( __DIR__ . '/../data/booking_types_class.php');

	$classes = array(
			'Address',
			'AdminMenu', 
			'Booking',
			'Comment',
			'ContentVersion',
			'CouponCode',
			'CouponCodeProduct',
			'DebugEmailLog',
			'Email',
			'EmailRecipient',
			'EmailTemplateStore',
			'Event',
			'EventLog',
			'EventRegistrant',
			'EventSession',
			'EventSessionFile',
			'SessionAnalytic',
			'EventType',
			'File',
			'GeneralError',
			'Group',
			'GroupMember',
			'FormError',
			'Message',
			'Order',
			'OrderItem',
			'PageContent',
			'PhoneNumber',
			'Post',
			'Product',
			'ProductVersion',
			'ProductDetail',
			'ProductGroup',
			'PublicMenu',
			'Question',
			'QuestionOption',
			'QueuedEmail',
			'Setting',
			'StripeInvoice',
			'Survey',
			'SurveyAnswer',
			'SurveyQuestion',
			'Url',
			'User',
			'ActivationCode',
			'VisitorEvent',
			'Video',
			'Page',
			'Component', 
			'ProductRequirement',
			'ProductRequirementInstance',
			'OrderItemRequirement',
			'EmailRecipientGroup',
			'ContactType',
			'MailingList',
			'MailingListRegistrant',
			'BookingType'
		);			


		//DATABASE MIGRATIONS
		//NOTE!!  ALL MIGRATIONS HAVE TO BE WRITTEN SUCH THAT THEY CAN BE RUN REPEATEDLY
		//IT BAILS ON ERROR AND STOPS MIGRATIONS, IN CASE SOME LATER ONES ARE DEPENDENT ON EARLIER ONES
		//IF THERE IS A TEST SQL AND IF IT RETURNS == 0, THEN WE RUN THE MIGRATION
		//IF THERE IS NO TEST SQL, IT IS ASSUMED THAT WE ALWAYS RUN THE MIGRATION
		//ALSO UPDATES LAST SYSTEM VERSION
		$migrations = array();
		$migrations[0]['system_version'] = '0.5';
		$migrations[0]['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_product_requirements'";
		$migrations[0]['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon") VALUES (\'Product Requirements\', 5, \'admin_product_requirements\', 5, 8, 0, \'\');';
		
		$migrations[1]['system_version'] = '0.5';
		$migrations[1]['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'system_version'";
		$migrations[1]['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'system_version\', \'0.5\', 1, \'now()\', \'now()\', \'general\');';
		
		$migrations[2]['system_version'] = '0.5';
		$migrations[2]['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'db_migration_version'";
		$migrations[2]['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'db_migration_version\', \'1\', 1, \'now()\', \'now()\', \'general\');';

		$migrations[3]['system_version'] = '0.5.1';
		$migrations[3]['test'] = NULL;
		$migrations[3]['migration_sql'] = NULL;		

		$migrations[4]['system_version'] = '0.5.2';
		$migrations[4]['test'] = NULL;
		$migrations[4]['migration_sql'] = NULL;				

		$migrations[5]['system_version'] = '0.5.2';
		$migrations[5]['test'] = NULL;
		$migrations[5]['migration_sql'] = NULL;			
	
		$migrations[6]['system_version'] = '0.5.3';
		$migrations[6]['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_mailing_lists'";
		$migrations[6]['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon") VALUES (\'Mailing Lists\', 11, \'admin_mailing_lists\', 7, 8, 0, \'\');';		
		
		$migrations[7]['system_version'] = '0.5.4';
		$migrations[7]['test'] = NULL;
		$migrations[7]['migration_sql'] = 'UPDATE emt_email_templates SET emt_body=\'<br/>----
<br/>
<small>
	{mailing_list_id}This email was sent to {~recipient}you{end}{recipient}*recipient->usr_email*{end} because you are subscribed to the list <i>*mailing_list_string*</i>.  Please <a href="*web_dir*/profile/mailing_lists_preferences?mailing_list_id=*mailing_list_id*&user=*recipient->key*&hash=*recipient->usr_authhash*&zone=ocu&*email_vars*">click here</a> to stop receiving <i>*mailing_list_string*</i> emails.  {end mailing_list_id}

{~mailing_list_id}This email was sent to {~recipient}you{end}{recipient}*recipient->usr_email*{end} because you are registered on our site.  Please <a href="*web_dir*/profile">click here</a> to manage your preferences.  {end ~mailing_list_id}
</small>
<br/>
<br/>\' WHERE emt_name=\'default_footer\';';				
		
		$migrations[8]['system_version'] = '0.5.4';
		$migrations[8]['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_analytics_email_stats'";
		$migrations[8]['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon") VALUES (\'Email Statistics\', 12, \'admin_analytics_email_stats\', 2, 5, 0, \'\');';	

		$migrations[9]['system_version'] = '0.5.5';
		$migrations[9]['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailing_lists_active'";
		$migrations[9]['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailing_lists_active\', 1, 1, \'now()\', \'now()\', \'general\');';		

		$migrations[10]['system_version'] = '0.5.6';
		$migrations[10]['test'] = NULL;
		$migrations[10]['migration_sql'] = 'UPDATE "public"."emt_email_templates" SET "emt_body" = \'<br/>----
<br/>
<small>
{mailing_list_id}This email was sent to {~recipient}you{end}{recipient}*recipient->usr_email*{end} because you are subscribed to the list <i>*mailing_list_string*</i>.  Please <a href="*web_dir*/profile/mailing_lists_preferences?mailing_list_id=*mailing_list_id*&user=*recipient->key*&hash=*recipient->usr_authhash*&zone=ocu&*email_vars*">click here</a> to stop receiving <i>*mailing_list_string*</i> emails.  {end mailing_list_id}

{evr_event_registrant_id}This email was sent to {~recipient}you{end}{recipient}*recipient->usr_email*{end} because you are registered for an event or course.  To unsubscribe, you must withdraw from the event or course here:  <a href="*web_dir*/profile/event_withdraw?evr_event_registrant_id=*evr_event_registrant_id*&user=*recipient->key*&*email_vars*">unsubscribe</a>.  
{end evr_event_registrant_id}

{~mailing_list_id}
This email was sent to {~recipient}you{end}{recipient}*recipient->usr_email*{end} because you are registered on our site.  Please <a href="*web_dir*/profile">click here</a> to manage your preferences. 
{end ~mailing_list_id}
</small>
<br/>
<br/>\', "emt_create_time" = \'2020-12-19 20:03:41.97081\', "emt_update_time" = \'2020-12-19 20:03:41.97081\', "emt_delete_time" = NULL WHERE emt_name=\'default_footer\';
';
		$migrations[10]['migration_file'] = NULL;	
		
		$migrations[11]['system_version'] = '0.5.7';
		$migrations[11]['test'] = NULL;
		$migrations[11]['migration_sql'] = NULL;
		$migrations[11]['migration_file'] = 'test_migration.php';	
		
		$migrations[12]['system_version'] = '0.5.8';
		$migrations[12]['test'] = 'SELECT count(1) as count FROM emt_email_templates WHERE emt_name = \'mailing_list_subscribe\'';
		$migrations[12]['migration_sql'] = 'INSERT INTO "public"."emt_email_templates"("emt_name", "emt_type", "emt_body", "emt_create_time", "emt_update_time", "emt_delete_time") VALUES (\'mailing_list_subscribe\', 2, \'This is your confirmation that you are subscribed to the *mailing_list_string* mailing list.  

Welcome!

\', \'2023-03-30 16:12:22.701562\', \'2023-03-30 16:12:22.701562\', NULL)';
		$migrations[12]['migration_file'] = NULL;	
		

		$migrations[13]['system_version'] = '0.5.9';
		$migrations[13]['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'bookings_active'";
		$migrations[13]['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'bookings_active\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		
		$migrations[14]['system_version'] = '0.5.9';
		$migrations[14]['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_api_token'";
		$migrations[14]['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_api_token\', \'\', 1, \'now()\', \'now()\', \'general\');';
		
		$migrations[15]['system_version'] = '0.5.9';
		$migrations[15]['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_organization_uri'";
		$migrations[15]['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_organization_uri\', \'\', 1, \'now()\', \'now()\', \'general\');';

		$migrations[19]['system_version'] = '0.5.9';
		$migrations[19]['test'] = NULL;
		$migrations[19]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_slug=REGEXP_REPLACE(REPLACE(LOWER(amu_menudisplay), \'\'\'\', \'\'), \'[^a-z]+\', \'-\');';
		
		$migrations[23]['system_version'] = '0.5.9';
		$migrations[23]['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_bookings'";
		$migrations[23]['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon", "amu_slug", "amu_setting_activate") VALUES (\'Bookings\', NULL, \'\', 6, 8, 0, \'clock\', \'bookings-parent\', \'bookings_active\');';

		$migrations[24]['system_version'] = '0.5.9';
		$migrations[24]['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_booking_types'";
		$migrations[24]['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon", "amu_slug", "amu_setting_activate") VALUES (\'Booking Types\', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = \'bookings-parent\'), \'admin_booking_types\', 5, 8, 0, \'\', \'booking-types\', \'bookings_active\');';

		$migrations[25]['system_version'] = '0.5.9';
		$migrations[25]['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_bookings'";
		$migrations[25]['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon", "amu_slug", "amu_setting_activate") VALUES (\'Bookings\', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = \'bookings-parent\'), \'admin_bookings\', 3, 8, 0, \'\', \'bookings\', \'bookings_active\');';
	

		$migrations[26]['system_version'] = '0.5.10';
		$migrations[26]['test'] = NULL;
		$migrations[26]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'blog_active\' WHERE amu_menudisplay= \'Blog\'';

		$migrations[27]['system_version'] = '0.5.10';
		$migrations[27]['test'] = NULL;
		$migrations[27]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'blog_active\' WHERE amu_menudisplay= \'Blog Posts\'';

		$migrations[28]['system_version'] = '0.5.10';
		$migrations[28]['test'] = NULL;
		$migrations[28]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'blog_active\' WHERE amu_menudisplay= \'Comments\'';

		$migrations[29]['system_version'] = '0.5.10';
		$migrations[29]['test'] = NULL;
		$migrations[29]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'events_active\' WHERE amu_menudisplay= \'All Events\'';

		$migrations[30]['system_version'] = '0.5.10';
		$migrations[30]['test'] = NULL;
		$migrations[30]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'events_active\' WHERE amu_menudisplay= \'Future Events\'';

		$migrations[31]['system_version'] = '0.5.10';
		$migrations[31]['test'] = NULL;
		$migrations[31]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'events_active\' WHERE amu_menudisplay= \'Events\'';

		$migrations[32]['system_version'] = '0.5.10';
		$migrations[33]['test'] = NULL;
		$migrations[33]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'events_active\' WHERE amu_menudisplay= \'Event Bundles\'';

		$migrations[34]['system_version'] = '0.5.10';
		$migrations[34]['test'] = NULL;
		$migrations[34]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Products\'';

		$migrations[35]['system_version'] = '0.5.10';
		$migrations[35]['test'] = NULL;
		$migrations[35]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Orders\'';

		$migrations[36]['system_version'] = '0.5.10';
		$migrations[36]['test'] = NULL;
		$migrations[36]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Orders list\'';
		
		$migrations[37]['system_version'] = '0.5.10';
		$migrations[37]['test'] = NULL;
		$migrations[37]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Stripe Payments\'';
		
		$migrations[38]['system_version'] = '0.5.10';
		$migrations[38]['test'] = NULL;
		$migrations[38]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Shadow Sessions\'';

		$migrations[39]['system_version'] = '0.5.10';
		$migrations[39]['test'] = NULL;
		$migrations[39]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Products list\'';
		
		$migrations[40]['system_version'] = '0.5.10';
		$migrations[40]['test'] = NULL;
		$migrations[40]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Product Groups\'';		
		$migrations[41]['system_version'] = '0.5.10';
		$migrations[41]['test'] = NULL;
		$migrations[41]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Product Requirements\'';	
	
		$migrations[42]['system_version'] = '0.5.10';
		$migrations[42]['test'] = NULL;
		$migrations[42]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'emails_active\' WHERE amu_menudisplay= \'Emails list\'';

		$migrations[43]['system_version'] = '0.5.10';
		$migrations[43]['test'] = NULL;
		$migrations[43]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'emails_active\' WHERE amu_menudisplay= \'Emails\'';
		
		$migrations[44]['system_version'] = '0.5.10';
		$migrations[44]['test'] = NULL;
		$migrations[44]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'emails_active\' WHERE amu_menudisplay= \'Email Templates\'';
		
		$migrations[45]['system_version'] = '0.5.10';
		$migrations[45]['test'] = NULL;
		$migrations[45]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'files_active\' WHERE amu_menudisplay= \'Files\'';
	
		$migrations[46]['system_version'] = '0.5.10';
		$migrations[46]['test'] = NULL;
		$migrations[46]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'files_active\' WHERE amu_menudisplay= \'Images\'';
	
		$migrations[47]['system_version'] = '0.5.10';
		$migrations[47]['test'] = NULL;
		$migrations[47]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'videos_active\' WHERE amu_menudisplay= \'Products\'';
	
		$migrations[48]['system_version'] = '0.5.10';
		$migrations[48]['test'] = NULL;
		$migrations[48]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'videos_active\' WHERE amu_menudisplay= \'Videos\'';
	
		$migrations[49]['system_version'] = '0.5.10';
		$migrations[49]['test'] = NULL;
		$migrations[49]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'page_contents_active\' WHERE amu_menudisplay= \'Pages\'';
	
		$migrations[50]['system_version'] = '0.5.10';
		$migrations[50]['test'] = NULL;
		$migrations[50]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'urls_active\' WHERE amu_menudisplay= \'Urls\'';
	
		$migrations[51]['system_version'] = '0.5.10';
		$migrations[51]['test'] = NULL;
		$migrations[51]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Products\'';
	
		$migrations[52]['system_version'] = '0.5.10';
		$migrations[52]['test'] = NULL;
		$migrations[52]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'products_active\' WHERE amu_menudisplay= \'Coupon codes\'';

		$migrations[53]['system_version'] = '0.5.10';
		$migrations[53]['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'surveys_active'";
		$migrations[53]['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'surveys_active\', \'0\', 1, \'now()\', \'now()\', \'general\');';

		$migrations[54]['system_version'] = '0.5.10';
		$migrations[54]['test'] = NULL;
		$migrations[54]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'surveys_active\' WHERE amu_menudisplay= \'Surveys\'';

		$migrations[55]['system_version'] = '0.5.10';
		$migrations[55]['test'] = NULL;
		$migrations[55]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_setting_activate= \'surveys_active\' WHERE amu_menudisplay= \'Survey questions\'';

		$migrations[56]['system_version'] = '0.5.11';
		$migrations[56]['test'] = NULL;
		$migrations[56]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_slug= \'files-parent\' WHERE amu_icon= \'file-pdf\'';

		$migrations[57]['system_version'] = '0.5.11';
		$migrations[57]['test'] = NULL;
		$migrations[57]['migration_sql'] = 'UPDATE amu_admin_menus SET amu_slug= \'surveys-parent\' WHERE amu_icon= \'list\'';


		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'blog_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'blog_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'blog_subdirectory'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'blog_subdirectory\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'events_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'events_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'products_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'products_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'emails_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'emails_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'files_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'files_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'videos_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'videos_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'page_contents_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'page_contents_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'urls_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'urls_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'tracking'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'tracking\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.13';
		$migration['test'] = "SELECT count(1) as count FROM pag_pages WHERE pag_link = 'register-thanks'";
		$migration['migration_sql'] = 'INSERT INTO "public"."pag_pages"("pag_title", "pag_link", "pag_body", "pag_usr_user_id", "pag_published_time", "pag_create_time", "pag_script_filename", "pag_delete_time") VALUES (\'Registration Welcome Page\', \'register-thanks\', \'			<h2>Thanks for signing up!</h2>

			<p>You will receive an email within 5 minutes to activate your account.</p>

			<ul>
			<li>Click on the link in the email to activate.</li>
			<li><strong>If you do not receive this email, please check your email spam folder.</strong></li></ul>
\', 1, \'2020-12-23 19:46:30.894481\', \'2022-12-27 18:21:48.775604\', NULL, NULL);';
		$migrations[] = $migration;
		
		$migration['system_version'] = '0.5.13';
		$migration['test'] = "SELECT count(1) as count FROM pag_pages WHERE pag_link = 'verify-email-confirm'";
		$migration['migration_sql'] = 'INSERT INTO "public"."pag_pages"("pag_title", "pag_link", "pag_body", "pag_usr_user_id", "pag_published_time", "pag_create_time", "pag_script_filename", "pag_delete_time") VALUES (\'Verify Email Confirm\', \'verify-email-confirm\', \'<h2>Congratulations! Your email address is now verified.</h2> 
				<p>Your email has been verified. </p> 
				<p></p><hr><p></p> 
				<h2>What Next?</h2> 
<p>
						<a href="/events">Check out upcoming retreats and events</a>.&nbsp; 
						We have retreats happening all around the world and online courses if you can not travel.</p>				
					
			 \', 1, \'2020-12-23 19:44:22.427349\', \'2022-12-27 18:21:48.785528\', NULL, NULL);';
		$migrations[] = $migration;

		$migration['system_version'] = '0.5.13';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE amu_admin_menus SET amu_slug= \'signups-by-date\' WHERE amu_icon= \'signups-by date\'';
		$migrations[] = $migration;









?>

