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
			'MailingListRegistrant'
		);			


		//DATABASE MIGRATIONS
		//NOTE!!  ALL MIGRATIONS HAVE TO BE WRITTEN SUCH THAT THEY CAN BE RUN REPEATEDLY
		//IT BAILS ON ERROR AND STOPS MIGRATIONS, IN CASE SOME LATER ONES ARE DEPENDENT ON EARLIER ONES
		//IF THERE IS A TEST SQL AND IF IT RETURNS == 0, THEN WE RUN THE MIGRATION
		//IF THERE IS NO TEST SQL, IT IS ASSUMED THAT WE ALWAYS RUN THE MIGRATION
		//ALSO UPDATES LAST SYSTEM VERSION
		$migrations = array();
		$migrations[0][system_version] = '0.5';
		$migrations[0][test] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_product_requirements'";
		$migrations[0][migration_sql] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon") VALUES (\'Product Requirements\', 5, \'admin_product_requirements\', 5, 8, 0, \'\');';
		
		$migrations[1][system_version] = '0.5';
		$migrations[1][test] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'system_version'";
		$migrations[1][migration_sql] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'system_version\', \'0.5\', 1, \'now()\', \'now()\', \'general\');';
		
		$migrations[2][system_version] = '0.5';
		$migrations[2][test] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'db_migration_version'";
		$migrations[2][migration_sql] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'db_migration_version\', \'1\', 1, \'now()\', \'now()\', \'general\');';

		$migrations[3][system_version] = '0.5.1';
		$migrations[3][test] = NULL;
		$migrations[3][migration_sql] = NULL;		

		$migrations[4][system_version] = '0.5.2';
		$migrations[4][test] = NULL;
		$migrations[4][migration_sql] = NULL;				

		$migrations[5][system_version] = '0.5.2';
		$migrations[5][test] = NULL;
		$migrations[5][migration_sql] = NULL;			
	
		$migrations[6][system_version] = '0.5.3';
		$migrations[6][test] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_mailing_lists'";
		$migrations[6][migration_sql] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon") VALUES (\'Mailing Lists\', 11, \'admin_mailing_lists\', 7, 8, 0, \'\');';		
		
		$migrations[7][system_version] = '0.5.4';
		$migrations[7][test] = NULL;
		$migrations[7][migration_sql] = 'UPDATE emt_email_templates SET emt_body=\'<br/>----
<br/>
<small>
	{mailing_list_id}This email was sent to {~recipient}you{end}{recipient}*recipient->usr_email*{end} because you are subscribed to the list <i>*mailing_list_string*</i>.  Please <a href="*web_dir*/profile/mailing_lists_preferences?mailing_list_id=*mailing_list_id*&user=*recipient->key*&hash=*recipient->usr_authhash*&zone=ocu&*email_vars*">click here</a> to stop receiving <i>*mailing_list_string*</i> emails.  {end mailing_list_id}

{~mailing_list_id}This email was sent to {~recipient}you{end}{recipient}*recipient->usr_email*{end} because you are registered on our site.  Please <a href="*web_dir*/profile">click here</a> to manage your preferences.  {end ~mailing_list_id}
</small>
<br/>
<br/>\' WHERE emt_name=\'default_footer\';';				
		
		$migrations[8][system_version] = '0.5.4';
		$migrations[8][test] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_analytics_email_stats'";
		$migrations[8][migration_sql] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon") VALUES (\'Email Statistics\', 12, \'admin_analytics_email_stats\', 2, 5, 0, \'\');';	

		$migrations[9][system_version] = '0.5.5';
		$migrations[9][test] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailing_lists_active'";
		$migrations[9][migration_sql] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailing_lists_active\', 1, 1, \'now()\', \'now()\', \'general\');';		

		$migrations[10][system_version] = '0.5.6';
		$migrations[10][test] = NULL;
		$migrations[10][migration_sql] = 'UPDATE "public"."emt_email_templates" SET "emt_body" = \'<br/>----
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
		$migrations[10][migration_file] = NULL;	
		
		$migrations[10][system_version] = '0.5.7';
		$migrations[10][test] = NULL;
		$migrations[10][migration_sql] = NULL;
		$migrations[10][migration_file] = 'test_migration.php';	
?>

