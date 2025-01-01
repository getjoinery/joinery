<?php

	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');


		//DATABASE MIGRATIONS
		//NOTE!!  ALL MIGRATIONS HAVE TO BE WRITTEN SUCH THAT THEY CAN BE RUN REPEATEDLY
		//IT BAILS ON ERROR AND STOPS MIGRATIONS, IN CASE SOME LATER ONES ARE DEPENDENT ON EARLIER ONES
		//IF THERE IS A TEST SQL AND IF IT RETURNS == 0, THEN WE RUN THE MIGRATION
		//IF THERE IS NO TEST SQL, IT IS ASSUMED THAT WE ALWAYS RUN THE MIGRATION
		//IF $migration['migration_file'] = 'SOME_FILE', THEN WE LOOK IN THE MIGRATIONS FOLDER AND RUN THAT MIGRATION
		//ALSO UPDATES LAST SYSTEM VERSION
		$migrations = array();

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'blog_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'blog_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'blog_subdirectory'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'blog_subdirectory\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'events_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'events_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'products_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'products_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'emails_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'emails_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'files_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'files_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'videos_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'videos_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'page_contents_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'page_contents_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'urls_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'urls_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'tracking'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'tracking\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '0.13';
		$migration['test'] = "SELECT count(1) as count FROM pag_pages WHERE pag_link = 'register-thanks'";
		$migration['migration_sql'] = 'INSERT INTO "public"."pag_pages"("pag_title", "pag_link", "pag_body", "pag_usr_user_id", "pag_published_time", "pag_create_time", "pag_script_filename", "pag_delete_time") VALUES (\'Registration Welcome Page\', \'register-thanks\', \'			<h2>Thanks for signing up!</h2>

			<p>You will receive an email within 5 minutes to activate your account.</p>

			<ul>
			<li>Click on the link in the email to activate.</li>
			<li><strong>If you do not receive this email, please check your email spam folder.</strong></li></ul>
\', 1, \'2020-12-23 19:46:30.894481\', \'2022-12-27 18:21:48.775604\', NULL, NULL);';
		$migrations[] = $migration;
		
		$migration['database_version'] = '0.13';
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

		$migration['database_version'] = '0.13';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE amu_admin_menus SET amu_slug= \'signups-by-date\' WHERE amu_icon= \'signups-by date\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;


		$migration['database_version'] = '0.14';
		$migration['test'] = NULL;
		$migration['migration_sql'] = NULL;
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '0.15';
		$migration['test'] = NULL;
		$migration['migration_sql'] = NULL;
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '0.16';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE amu_admin_menus SET amu_menudisplay= \'Events List\', amu_slug=\'events-list\' WHERE amu_menudisplay= \'Future Events\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '0.16';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'DELETE FROM amu_admin_menus WHERE amu_menudisplay= \'All Events\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '0.17';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'ALTER TABLE usa_users_addrs ALTER COLUMN usa_usr_user_id drop not null;';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
		$migration['database_version'] = '0.18';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'default_mailing_list'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'default_mailing_list\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'force_https'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'force_https\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'hcaptcha_public'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'hcaptcha_public\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'hcaptcha_private'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'hcaptcha_private\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'captcha_public'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'captcha_public\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'captcha_private'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'captcha_private\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailchimp_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailchimp_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_api_pkey'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_api_pkey\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_api_key_test'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_api_key_test\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_api_pkey_test'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_api_pkey_test\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_endpoint_secret'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_endpoint_secret\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_organization_uri'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_organization_uri\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_organization_name'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_organization_name\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_api_token'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_api_token\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'acuity_user_id'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'acuity_user_id\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'acuity_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'acuity_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
		
		
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'composerAutoLoad'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'composerAutoLoad\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'node_dir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'node_dir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'apache_error_log'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'apache_error_log\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'site_name'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'site_name\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'site_description'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'site_description\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'logo_link'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'logo_link\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
		
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'baseDir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'baseDir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			

		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'site_template'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'site_template\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'webDir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'webDir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'siteDir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'siteDir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upload_dir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upload_dir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upload_web_dir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upload_web_dir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'static_files_dir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'static_files_dir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		


		$migration['database_version'] = '0.20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'webmaster_email'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'webmaster_email\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		$migration['database_version'] = '0.20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'defaultemail'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'defaultemail\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'defaultemailname'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'defaultemailname\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '0.20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'debug'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'debug\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
	
		$migration['database_version'] = '0.20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'standard_error'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'standard_error\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		$migration['database_version'] = '0.21';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_version'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailgun_version\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '0.21';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_eu_api_link'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailgun_eu_api_link\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '0.21';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailgun_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
	
		$migration['database_version'] = '0.21';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_domain'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailgun_domain\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		$migration['database_version'] = '0.22';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'social_messenger_link'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'social_messenger_link\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

		$migration['database_version'] = '0.23';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'tracking_code'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'tracking_code\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

		$migration['database_version'] = '0.24';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscriptions_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'subscriptions_active\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

		$migration['database_version'] = '0.25';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'activation_required_login'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'activation_required_login\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '0.26';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'newsletter_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'newsletter_active\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '0.27';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'event_email_inner_template'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'event_email_inner_template\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '0.28';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'preview_image'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'preview_image\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		 
 		$migration['database_version'] = '0.29';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'show_errors'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'show_errors\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		 

 		$migration['database_version'] = '0.30';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_locations'";
		$migration['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon", "amu_slug", "amu_setting_activate") VALUES (\'Locations\', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = \'events\'), \'admin_locations\', 5, 5, 0, \'\', \'locations\', \'events_active\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.31';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'use_blog_as_homepage'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'use_blog_as_homepage\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.32';
		$migration['test'] = NULL;
		$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'blog_subdirectory'";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '0.33';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'custom_css'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'custom_css\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

 		$migration['database_version'] = '0.34';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'paypal_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'paypal_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

 		$migration['database_version'] = '0.34';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'paypal_api_secret'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'paypal_api_secret\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '0.34';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'paypal_api_key_test'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'paypal_api_key_test\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '0.34';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'paypal_api_secret_test'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'paypal_api_secret_test\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

 		$migration['database_version'] = '0.35';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'use_paypal_checkout'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'use_paypal_checkout\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;				
		
 		$migration['database_version'] = '0.36';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'preview_image'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'preview_image\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.37';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upgrade_source'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upgrade_source\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.37';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upgrade_server_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upgrade_server_active\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.37';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upgrade_location'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upgrade_location\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.38';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'show_errors'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'show_errors\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '0.39';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'ALTER TABLE usr_users ALTER COLUMN usr_password TYPE varchar(255);';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.39';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'database_version'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'database_version\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.39';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE stg_settings set stg_value=(select stg_value from stg_settings where stg_name = \'system_version\') where stg_name = \'database_version\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.39';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE stg_settings set stg_value=\'\' where stg_name= \'system_version\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '0.40';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'SELECT 1 FROM stg_settings';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
	
 		$migration['database_version'] = '0.41';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'debug_css'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'debug_css\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
 		$migration['database_version'] = '0.42';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'theme_template'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'theme_template\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '0.43';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'events_label'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'events_label\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '0.44';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'blog_footer_text'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'blog_footer_text\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
	
 		$migration['database_version'] = '0.45';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'pricing_page'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'pricing_page\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '0.45';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'alternate_homepage'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'alternate_homepage\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
		
 		$migration['database_version'] = '0.46';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'random_test_value'";
		$migration['migration_sql'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'random_test_value'";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		 
		 