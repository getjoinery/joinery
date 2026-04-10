<?php

	// Migrations file - only defines the $migrations array
	// No dependencies needed since this is just data


		//DATABASE MIGRATIONS
		//NOTE!!  ALL MIGRATIONS HAVE TO BE WRITTEN SUCH THAT THEY CAN BE RUN REPEATEDLY
		//IT BAILS ON ERROR AND STOPS MIGRATIONS, IN CASE SOME LATER ONES ARE DEPENDENT ON EARLIER ONES
		//IF THERE IS A TEST SQL AND IF IT RETURNS == 0, THEN WE RUN THE MIGRATION
		//IF THERE IS NO TEST SQL, IT IS ASSUMED THAT WE ALWAYS RUN THE MIGRATION
		//IF $migration['migration_file'] = 'SOME_FILE', THEN WE LOOK IN THE MIGRATIONS FOLDER AND RUN THAT MIGRATION
		//ALSO UPDATES LAST SYSTEM VERSION
		// Initialize migrations array only if it doesn't exist
		if (!isset($migrations)) {
			$migrations = array();
		}

	// =============================================================================
	// ARCHIVED MIGRATIONS (v12-v76)
	// =============================================================================
	// All legacy sites have been upgraded. These migrations are preserved for 
	// reference but are no longer executed. New installations use joinery-install.sql
	// which already includes all settings and data up to the current version.
	//
	// To add new migrations for FUTURE changes, add them after this archived block.
	// =============================================================================

/*
// ===== BEGIN ARCHIVED MIGRATIONS =====

	
		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'blog_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'blog_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		// REMOVED: blog_subdirectory migration - setting was deprecated and deleted in migration 32

		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'events_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'events_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'products_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'products_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'emails_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'emails_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'files_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'files_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'videos_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'videos_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'page_contents_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'page_contents_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'urls_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'urls_active\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '12';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'tracking'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'tracking\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migrations[] = $migration;

		$migration['database_version'] = '13';
		$migration['test'] = "SELECT count(1) as count FROM pag_pages WHERE pag_link = 'register-thanks'";
		$migration['migration_sql'] = 'INSERT INTO "public"."pag_pages"("pag_title", "pag_link", "pag_body", "pag_usr_user_id", "pag_published_time", "pag_create_time", "pag_script_filename", "pag_delete_time") VALUES (\'Registration Welcome Page\', \'register-thanks\', \'			<h2>Thanks for signing up!</h2>

			<p>You will receive an email within 5 minutes to activate your account.</p>

			<ul>
			<li>Click on the link in the email to activate.</li>
			<li><strong>If you do not receive this email, please check your email spam folder.</strong></li></ul>
\', 1, \'2020-12-23 19:46:30.894481\', \'2022-12-27 18:21:48.775604\', NULL, NULL);';
		$migrations[] = $migration;
		
		$migration['database_version'] = '13';
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

		$migration['database_version'] = '13';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE amu_admin_menus SET amu_slug= \'signups-by-date\' WHERE amu_icon= \'signups-by date\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;


		// Migrations 0.14 and 0.15 removed - were empty placeholders

		$migration['database_version'] = '16';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE amu_admin_menus SET amu_menudisplay= \'Events List\', amu_slug=\'events-list\' WHERE amu_menudisplay= \'Future Events\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '16';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'DELETE FROM amu_admin_menus WHERE amu_menudisplay= \'All Events\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '17';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'ALTER TABLE usa_users_addrs ALTER COLUMN usa_usr_user_id drop not null;';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
		$migration['database_version'] = '18';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'default_mailing_list'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'default_mailing_list\', \'1\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'force_https'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'force_https\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'hcaptcha_public'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'hcaptcha_public\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'hcaptcha_private'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'hcaptcha_private\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'captcha_public'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'captcha_public\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'captcha_private'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'captcha_private\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailchimp_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailchimp_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_api_pkey'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_api_pkey\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_api_key_test'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_api_key_test\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_api_pkey_test'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_api_pkey_test\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'stripe_endpoint_secret'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'stripe_endpoint_secret\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_organization_uri'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_organization_uri\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_organization_name'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_organization_name\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'calendly_api_token'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'calendly_api_token\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'acuity_user_id'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'acuity_user_id\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'acuity_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'acuity_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
		
		
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'composerAutoLoad'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'composerAutoLoad\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'node_dir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'node_dir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'apache_error_log'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'apache_error_log\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'site_name'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'site_name\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'site_description'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'site_description\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'logo_link'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'logo_link\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
		
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'baseDir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'baseDir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			

		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'site_template'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'site_template\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'webDir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'webDir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'siteDir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'siteDir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upload_dir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upload_dir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upload_web_dir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upload_web_dir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '19';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'static_files_dir'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'static_files_dir\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		


		$migration['database_version'] = '20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'webmaster_email'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'webmaster_email\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	


		$migration['database_version'] = '20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'defaultemail'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'defaultemail\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'defaultemailname'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'defaultemailname\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'debug'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'debug\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
	
		$migration['database_version'] = '20';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'standard_error'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'standard_error\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		$migration['database_version'] = '21';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_version'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailgun_version\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		
		$migration['database_version'] = '21';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_eu_api_link'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailgun_eu_api_link\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

		$migration['database_version'] = '21';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailgun_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
	
		$migration['database_version'] = '21';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_domain'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'mailgun_domain\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		$migration['database_version'] = '22';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'social_messenger_link'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'social_messenger_link\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

		$migration['database_version'] = '23';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'tracking_code'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'tracking_code\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

		$migration['database_version'] = '24';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscriptions_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'subscriptions_active\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

		$migration['database_version'] = '25';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'activation_required_login'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'activation_required_login\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '26';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'newsletter_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'newsletter_active\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '27';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'event_email_inner_template'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'event_email_inner_template\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration['database_version'] = '28';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'preview_image'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'preview_image\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		 
 		$migration['database_version'] = '29';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'show_errors'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'show_errors\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		 

 		$migration['database_version'] = '30';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_locations'";
		$migration['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon", "amu_slug", "amu_setting_activate") VALUES (\'Locations\', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = \'events\'), \'admin_locations\', 5, 5, 0, \'\', \'locations\', \'events_active\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '31';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'use_blog_as_homepage'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'use_blog_as_homepage\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
		// REMOVED: blog_subdirectory DELETE migration - INSERT was also removed from migration 12

 		$migration['database_version'] = '33';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'custom_css'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'custom_css\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

 		$migration['database_version'] = '34';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'paypal_api_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'paypal_api_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

 		$migration['database_version'] = '34';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'paypal_api_secret'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'paypal_api_secret\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '34';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'paypal_api_key_test'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'paypal_api_key_test\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '34';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'paypal_api_secret_test'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'paypal_api_secret_test\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		

 		$migration['database_version'] = '35';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'use_paypal_checkout'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'use_paypal_checkout\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;				
		
 		$migration['database_version'] = '36';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'preview_image'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'preview_image\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '37';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upgrade_source'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upgrade_source\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '37';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upgrade_server_active'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upgrade_server_active\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '37';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'upgrade_location'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'upgrade_location\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '38';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'show_errors'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'show_errors\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '39';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'ALTER TABLE usr_users ALTER COLUMN usr_password TYPE varchar(255);';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '39';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'database_version'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'database_version\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '39';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE stg_settings set stg_value=(select stg_value from stg_settings where stg_name = \'system_version\') where stg_name = \'database_version\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '39';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'UPDATE stg_settings set stg_value=\'\' where stg_name= \'system_version\'';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '40';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'SELECT 1 FROM stg_settings';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
	
 		$migration['database_version'] = '41';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'debug_css'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'debug_css\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
 		$migration['database_version'] = '42';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'theme_template'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'theme_template\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '43';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'events_label'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'events_label\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		
 		$migration['database_version'] = '44';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'blog_footer_text'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'blog_footer_text\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
	
 		$migration['database_version'] = '45';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'pricing_page'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'pricing_page\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

 		$migration['database_version'] = '45';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'alternate_homepage'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'alternate_homepage\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
		
 		$migration['database_version'] = '46';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'random_test_value'";
		$migration['migration_sql'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'random_test_value'";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		
 		$migration['database_version'] = '47';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'max_subscriptions_per_user'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'max_subscriptions_per_user\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
		
	 	$migration['database_version'] = '48';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'allowed_upload_extensions'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'allowed_upload_extensions\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			
		
	 	$migration['database_version'] = '48';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'form_style'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'form_style\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			

 		$migration['database_version'] = '49';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'alternate_loggedin_homepage'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'alternate_loggedin_homepage\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;			

 		$migration['database_version'] = '50';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_analytics_funnels.php'";
		$migration['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon", "amu_slug", "amu_setting_activate") VALUES (\'Funnels\', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = \'statistics\'), \'admin_locations\', 5, 5, 0, \'\', \'funnels\', \'\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	

		// Migrate force_https to protocol_mode
		$migration = array(); // Clear previous migration data
		$migration['database_version'] = '51';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'protocol_mode'";
		$migration['migration_file'] = 'protocol_mode_migration.php';
		$migration['migration_sql'] = NULL;
		$migrations[] = $migration;

		// Add SMTP configuration settings - individual migrations to match existing pattern
		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'smtp_host'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'smtp_host\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'smtp_port'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'smtp_port\', \'25\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'smtp_helo'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'smtp_helo\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'smtp_hostname'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'smtp_hostname\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'smtp_sender'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'smtp_sender\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'smtp_auth'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'smtp_auth\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'smtp_username'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'smtp_username\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'smtp_password'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'smtp_password\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add test mode settings
		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'email_test_mode'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'email_test_mode\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'email_test_recipient'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'email_test_recipient\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'email_dry_run'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'email_dry_run\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add debug setting
		$migration = array();
		$migration['database_version'] = '52';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'email_debug_mode'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'email_debug_mode\', \'0\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add email service selection settings
		$migration = array();
		$migration['database_version'] = '53';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'email_service'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'email_service\', \'mailgun\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		$migration = array();
		$migration['database_version'] = '53';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'email_fallback_service'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'email_fallback_service\', \'smtp\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add default email template setting for EmailSender::quickSend
		$migration = array();
		$migration['database_version'] = '54';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'default_email_template'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'default_email_template\', \'default_outer_template\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Extract subject lines from existing email template bodies
		$migration = array();
		$migration['database_version'] = '55';
		$migration['test'] = "SELECT count(1) as count FROM emt_email_templates WHERE emt_subject IS NOT NULL AND emt_subject != ''";
		$migration['migration_sql'] = NULL;
		$migration['migration_file'] = 'extract_email_subjects.php';
		$migrations[] = $migration;
		
		// Sync themes and plugins with database registry
		$migration = array();
		$migration['database_version'] = '56';
		$migration['test'] = NULL; // Rely on hash-based protection only
		$migration['migration_sql'] = NULL;
		$migration['migration_file'] = 'theme_plugin_registry_sync.php';
		$migrations[] = $migration;
		
		// Migration 1: Rename blank theme to plugin theme
		$migration = array();
		$migration['database_version'] = '57';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'theme_template' AND stg_value = 'blank'";
		$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = 'plugin' WHERE stg_name = 'theme_template' AND stg_value = 'blank';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Migration 2: Add active_theme_plugin setting
		$migration = array();
		$migration['database_version'] = '57';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'active_theme_plugin'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value) VALUES ('active_theme_plugin', '');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add System parent menu item
		$migration = array();
		$migration['database_version'] = '58';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'system'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug) VALUES ('System', NULL, '', 80, 9, 0, 'settings', 'system');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Plugins menu item under System
		$migration = array();
		$migration['database_version'] = '59';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'system-plugins'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug) VALUES ('Plugins', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'system'), 'admin_plugins', 1, 9, 0, '', 'system-plugins');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Themes menu item under System
		$migration = array();
		$migration['database_version'] = '60';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'system-themes'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug) VALUES ('Themes', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'system'), 'admin_themes', 2, 9, 0, '', 'system-themes');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Static Cache menu item under System
		$migration = array();
		$migration['database_version'] = '61';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'system-cache'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug) VALUES ('Static Cache', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'system'), 'admin_static_cache', 3, 9, 0, '', 'system-cache');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

 
		// Phase 2 Subscription Tier Settings
		// Setting 1: subscription_downgrades_enabled
		$migration = array();
		$migration['database_version'] = '62';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscription_downgrades_enabled'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('subscription_downgrades_enabled', '0', 1, now(), now(), 'subscriptions');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Setting 2: subscription_downgrade_timing
		$migration = array();
		$migration['database_version'] = '62';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscription_downgrade_timing'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('subscription_downgrade_timing', 'end_of_period', 1, now(), now(), 'subscriptions');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Setting 3: subscription_cancellation_enabled
		$migration = array();
		$migration['database_version'] = '62';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscription_cancellation_enabled'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('subscription_cancellation_enabled', '1', 1, now(), now(), 'subscriptions');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Setting 4: subscription_cancellation_timing
		$migration = array();
		$migration['database_version'] = '62';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscription_cancellation_timing'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('subscription_cancellation_timing', 'end_of_period', 1, now(), now(), 'subscriptions');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Setting 5: subscription_reactivation_enabled
		$migration = array();
		$migration['database_version'] = '62';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscription_reactivation_enabled'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('subscription_reactivation_enabled', '1', 1, now(), now(), 'subscriptions');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Setting 6: subscription_cancellation_prorate
		$migration = array();
		$migration['database_version'] = '62';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscription_cancellation_prorate'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('subscription_cancellation_prorate', '0', 1, now(), now(), 'subscriptions');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Subscription Tiers menu item under Products
		$migration = array();
		$migration['database_version'] = '63';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'subscription-tiers'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) VALUES ('Subscription Tiers', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'products'), 'admin_subscription_tiers', 10, 5, 0, '', 'subscription-tiers', 'subscriptions_active');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Setting: subscription_downgrade_prorate
		$migration = array();
		$migration['database_version'] = '64';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscription_downgrade_prorate'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('subscription_downgrade_prorate', '1', 1, now(), now(), 'subscriptions');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Setting: subscription_upgrade_prorate
		$migration = array();
		$migration['database_version'] = '64';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'subscription_upgrade_prorate'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('subscription_upgrade_prorate', '1', 1, now(), now(), 'subscriptions');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Remove .php extensions from amu_defaultpage
		$migration = array();
		$migration['database_version'] = '65';
		$migration['test'] = NULL;
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_defaultpage = REPLACE(amu_defaultpage, '.php', '') WHERE amu_defaultpage LIKE '%.php%';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Contact Types menu item under Emails
		$migration = array();
		$migration['database_version'] = '66';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'contact-types'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug) VALUES ('Contact Types', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'emails'), 'admin_contact_types', 3, 8, 0, '', 'contact-types');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add API Keys menu item under System
		$migration = array();
		$migration['database_version'] = '66';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'api_keys'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug) VALUES ('API Keys', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'system'), 'admin_api_keys', 4, 8, 0, '', 'api_keys');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Drop timezone table - dead table with no code references
		$migration = array();
		$migration['database_version'] = '67';
		$migration['test'] = "SELECT count(1) as count FROM pg_tables WHERE tablename = 'timezone' AND schemaname = 'public'";
		$migration['migration_sql'] = 'DROP TABLE IF EXISTS public.timezone CASCADE;';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Drop country table - consolidated into cco_country_codes
		$migration = array();
		$migration['database_version'] = '67';
		$migration['test'] = "SELECT count(1) as count FROM pg_tables WHERE tablename = 'country' AND schemaname = 'public'";
		$migration['migration_sql'] = 'DROP TABLE IF EXISTS public.country CASCADE;';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Update composerAutoLoad setting from /home/user1/vendor/ to ../vendor/ for per-site isolation
		// DISABLED: This migration should be done manually before deployment
		$migration = array();
		$migration['database_version'] = '68';
		$migration['test'] = "SELECT 1 as count"; // Always returns 1, causing migration to skip
		$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = '../vendor/' WHERE stg_name = 'composerAutoLoad';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Test Database menu item under System
		$migration = array();
		$migration['database_version'] = '69';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'test-database'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug) VALUES ('Test Database', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'system'), 'admin_test_database', 5, 10, 0, '', 'test-database');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// =============================================================================
		// VERSION CONSOLIDATION - Remove redundant settings
		// Note: All migration versions converted from decimals (0.XX) to integers (XX)
		// =============================================================================

		// Remove deprecated database_version setting
		$migration = array();
		$migration['database_version'] = '70';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'database_version'";
		$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'database_version';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Remove deprecated db_migration_version setting
		$migration = array();
		$migration['database_version'] = '71';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'db_migration_version'";
		$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'db_migration_version';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// =============================================================================
		// Fix admin menu icons to use valid Font Awesome 5 names
		// =============================================================================

		// Fix Emails icon: mail -> envelope
		$migration = array();
		$migration['database_version'] = '72';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_menudisplay = 'Emails' AND amu_icon = 'envelope'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_icon = 'envelope' WHERE amu_menudisplay = 'Emails' AND amu_icon = 'mail';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Fix Products icon: nut -> box
		$migration = array();
		$migration['database_version'] = '72';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_menudisplay = 'Products' AND amu_icon = 'box'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_icon = 'box' WHERE amu_menudisplay = 'Products' AND amu_icon = 'nut';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Fix Orders icon: cart -> shopping-cart
		$migration = array();
		$migration['database_version'] = '72';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_menudisplay = 'Orders' AND amu_icon = 'shopping-cart'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_icon = 'shopping-cart' WHERE amu_menudisplay = 'Orders' AND amu_icon = 'cart';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Fix Videos icon: video-camera -> video
		$migration = array();
		$migration['database_version'] = '72';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_menudisplay = 'Videos' AND amu_icon = 'video'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_icon = 'video' WHERE amu_menudisplay = 'Videos' AND amu_icon = 'video-camera';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Fix Pages icon: file-text -> file-alt
		$migration = array();
		$migration['database_version'] = '72';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_menudisplay = 'Pages' AND amu_icon = 'file-alt'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_icon = 'file-alt' WHERE amu_menudisplay = 'Pages' AND amu_icon = 'file-text';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Fix System icon: settings -> cog
		$migration = array();
		$migration['database_version'] = '72';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_menudisplay = 'System' AND amu_icon = 'cog'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_icon = 'cog' WHERE amu_menudisplay = 'System' AND amu_icon = 'settings';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Convert Pages menu to dropdown parent (clear defaultpage)
		$migration = array();
		$migration['database_version'] = '73';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'pages' AND (amu_defaultpage = '' OR amu_defaultpage IS NULL)";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_defaultpage = '' WHERE amu_slug = 'pages';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Pages list menu item under Pages
		$migration = array();
		$migration['database_version'] = '73';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'pages-list'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) VALUES ('Pages list', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'pages'), 'admin_pages', 1, 5, 0, '', 'pages-list', 'page_contents_active');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Page Components menu item under Pages
		$migration = array();
		$migration['database_version'] = '73';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'page-components'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) VALUES ('Page Components', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'pages'), 'admin_components', 2, 5, 0, '', 'page-components', 'page_contents_active');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add Components menu item under System
		$migration = array();
		$migration['database_version'] = '73';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'system-components'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug) VALUES ('Components', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'system'), 'admin_component_types', 6, 9, 0, 'puzzle-piece', 'system-components');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// ========== Statistics Menu Cleanup (v74) ==========
		// Fix: Remove duplicate Funnels menu entry (keep first, delete second duplicate)
		$migration = array();
		$migration['database_version'] = '74';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'funnels' HAVING count(1) <= 1";
		$migration['migration_sql'] = "DELETE FROM amu_admin_menus WHERE amu_admin_menu_id = (SELECT MAX(amu_admin_menu_id) FROM amu_admin_menus WHERE amu_slug = 'funnels');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Fix: Funnels menu entry points to wrong page (admin_locations instead of admin_analytics_funnels)
		$migration = array();
		$migration['database_version'] = '74';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'funnels' AND amu_defaultpage = 'admin_analytics_funnels'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_defaultpage = 'admin_analytics_funnels' WHERE amu_slug = 'funnels';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Fix: Slug typo 'signups-by date' should be 'signups-by-date' (space vs hyphen)
		$migration = array();
		$migration['database_version'] = '74';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'signups-by-date'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_slug = 'signups-by-date' WHERE amu_slug = 'signups-by date';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Fix: Slug typo 'email-debug logs' should be 'email-debug-logs' (space vs hyphen)
		$migration = array();
		$migration['database_version'] = '74';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'email-debug-logs'";
		$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_slug = 'email-debug-logs' WHERE amu_slug = 'email-debug logs';";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// ========== Cookie Consent Compliance (v75) ==========
		// Add cookie_consent_mode setting
		$migration = array();
		$migration['database_version'] = '75';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'cookie_consent_mode'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('cookie_consent_mode', 'off', 1, now(), now(), 'general');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Add cookie_privacy_policy_link setting
		$migration = array();
		$migration['database_version'] = '75';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'cookie_privacy_policy_link'";
		$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('cookie_privacy_policy_link', '', 1, now(), now(), 'general');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// ========== Component Types Menu Item (v76) ==========
		// Add Component Types menu item under Pages
		$migration = array();
		$migration['database_version'] = '76';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'component-types'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) VALUES ('Component Types', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'pages'), 'admin_component_types', 3, 5, 0, '', 'component-types', 'page_contents_active');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

// ===== END ARCHIVED MIGRATIONS =====
*/

	// =============================================================================
	// ACTIVE MIGRATIONS
	// =============================================================================
	// Add new migrations below this line. These will run on existing installations
	// when they upgrade. New installations already have everything via the SQL dump.
	// =============================================================================

	// ========== Remote Archive Refresh (v77) ==========
	// Add setting to enable remote archive refresh requests
	$migration = array();
	$migration['database_version'] = '77';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'allow_remote_archive_refresh'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('allow_remote_archive_refresh', '0', 1, now(), now(), 'general');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// Add setting for allowed IPs for archive refresh
	$migration = array();
	$migration['database_version'] = '77';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'archive_refresh_allowed_ips'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('archive_refresh_allowed_ips', '[]', 1, now(), now(), 'general');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Event Types Menu Item (v78) ==========
	// Add Event Types menu item under Events
	$migration = array();
	$migration['database_version'] = '78';
	$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'event-types'";
	$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) VALUES ('Event Types', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'events'), 'admin_event_types', 5, 8, 0, '', 'event-types', 'events_active');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Mailgun Webhook Signing Key Setting (v79) ==========
	$migration = array();
	$migration['database_version'] = '79';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'mailgun_webhook_signing_key'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('mailgun_webhook_signing_key', '', 1, now(), now(), 'email');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Entity Photos System (v80) ==========
	// Add max_entity_photos setting
	$migration = array();
	$migration['database_version'] = '80';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'max_entity_photos'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('max_entity_photos', '{\"user\": 6, \"event\": 10, \"location\": 10, \"mailing_list\": 10}', 1, now(), now(), 'general');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// Migrate existing FK data into eph_entity_photos
	$migration = array();
	$migration['database_version'] = '80';
	$migration['test'] = "SELECT count(1) as count FROM eph_entity_photos";
	$migration['migration_file'] = 'migrate_entity_photos.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Scheduled Tasks System (v81) ==========
	// Add admin menu item and cron heartbeat setting
	$migration = array();
	$migration['database_version'] = '81';
	$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_menudisplay = 'Scheduled Tasks'";
	$migration['migration_file'] = 'migration_scheduled_tasks_init.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Component Layout Controls (v82) ==========
	// Set pac_max_width='none' on custom_html instances that had container=false,
	// preserving their edge-to-edge layout now that the template always outputs a container.
	$migration = array();
	$migration['database_version'] = '82';
	$migration['test'] = "SELECT count(1) as count FROM pac_page_contents pac INNER JOIN com_components com ON pac.pac_com_component_id = com.com_component_id WHERE com.com_type_key = 'custom_html' AND pac.pac_config::text LIKE '%\"container\":false%' AND pac.pac_max_width IS NULL";
	$migration['migration_sql'] = "UPDATE pac_page_contents SET pac_max_width = 'none' FROM com_components WHERE pac_com_component_id = com_component_id AND com_type_key = 'custom_html' AND pac_config::text LIKE '%\"container\":false%'";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Add AVIF and WebP upload support (v83) ==========
	$migration = array();
	$migration['database_version'] = '83';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'allowed_upload_extensions' AND stg_value LIKE '%avif%'";
	$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = stg_value || ',avif,webp' WHERE stg_name = 'allowed_upload_extensions' AND stg_value NOT LIKE '%avif%'";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Fix unsubscribe URL in email footer template (v84) ==========
	$migration = array();
	$migration['database_version'] = '84';
	$migration['test'] = "SELECT count(1) as count FROM emt_email_templates WHERE emt_body LIKE '%/profile/mailing_lists_preferences%'";
	$migration['migration_sql'] = "UPDATE emt_email_templates SET emt_body = replace(emt_body, '/profile/mailing_lists_preferences', '/profile/contact_preferences') WHERE emt_body LIKE '%/profile/mailing_lists_preferences%'";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Add Venmo checkout setting (v85) ==========
	$migration = array();
	$migration['database_version'] = '85';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'use_venmo_checkout'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('use_venmo_checkout', '0', 1, 'now()', 'now()', 'general');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Product Requirements Refactor (v86) ==========
	// Migrate bitmask requirements and old prq/pri rows to new class_name-based system
	$migration = array();
	$migration['database_version'] = '86';
	$migration['test'] = "SELECT count(1) as count FROM pri_product_requirement_instances WHERE pri_class_name IS NOT NULL";
	$migration['migration_file'] = 'migrate_product_requirements.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Theme System Flags (v87) ==========
	// joinery-system is the system admin theme (not deletable); falcon is not a system theme

	// Insert joinery-system theme record if it doesn't exist
	$migration = array();
	$migration['database_version'] = '87';
	$migration['test'] = "SELECT count(1) as count FROM thm_themes WHERE thm_name = 'joinery-system'";
	$migration['migration_sql'] = "INSERT INTO thm_themes (thm_name, thm_display_name, thm_description, thm_version, thm_author, thm_is_active, thm_is_stock, thm_is_system, thm_create_time, thm_update_time) VALUES ('joinery-system', 'Joinery System', 'Vanilla HTML5+CSS admin theme for the Joinery system', '1.0.0', 'Joinery Team', false, true, true, now(), now())";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// Update falcon to not be a system theme
	$migration = array();
	$migration['database_version'] = '87';
	$migration['test'] = "SELECT count(1) as count FROM thm_themes WHERE thm_name = 'falcon' AND thm_is_system = false";
	$migration['migration_sql'] = "UPDATE thm_themes SET thm_is_system = false WHERE thm_name = 'falcon'";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== API Security Hardening Settings (v88) ==========
	$migration = array();
	$migration['database_version'] = '88';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'api_require_https'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('api_require_https', 'true', 1, now(), now(), 'api')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '88';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'api_allowed_origins'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('api_allowed_origins', '', 1, now(), now(), 'api')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '88';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'request_log_retention_days'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('request_log_retention_days', '90', 1, now(), now(), 'api')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== API Rate Limit Settings (v89) ==========
	$migration = array();
	$migration['database_version'] = '89';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'api_rate_limit_requests'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('api_rate_limit_requests', '1000', 1, now(), now(), 'api')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '89';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'api_rate_limit_window'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('api_rate_limit_window', '3600', 1, now(), now(), 'api')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '89';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'api_auth_rate_limit_requests'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('api_auth_rate_limit_requests', '10', 1, now(), now(), 'api')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '89';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'api_auth_rate_limit_window'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('api_auth_rate_limit_window', '900', 1, now(), now(), 'api')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Admin Bar Setting (v90) ==========
	$migration = array();
	$migration['database_version'] = '90';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'show_admin_bar'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('show_admin_bar', '1', 1, now(), now(), 'general')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Messaging Active Setting (v91) ==========
	$migration = array();
	$migration['database_version'] = '91';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'messaging_active'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('messaging_active', '1', 1, now(), now(), 'general')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Subscription Email Templates (v92) ==========
	$migration = array();
	$migration['database_version'] = '92';
	$migration['test'] = "SELECT count(1) as count FROM emt_email_templates WHERE emt_name = 'subscription_created'";
	$migration['migration_sql'] = NULL;
	$migration['migration_file'] = 'migration_subscription_email_templates.php';
	$migrations[] = $migration;

	// ========== Drop legacy paypal_webhook_events table (v93) ==========
	$migration = array();
	$migration['database_version'] = '93';
	$migration['test'] = "SELECT CASE WHEN EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'paypal_webhook_events' AND table_schema = 'public') THEN 0 ELSE 1 END as count";
	$migration['migration_sql'] = "DROP TABLE IF EXISTS paypal_webhook_events";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== ScrollDaddy blocklist version tracking (v94) ==========
	$migration = array();
	$migration['database_version'] = '94';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'scrolldaddy_blocklist_version'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) VALUES ('scrolldaddy_blocklist_version', '', 1, now(), now(), 'scrolldaddy')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Backfill sct_plugin_name for existing scheduled tasks (v95) ==========
	$migration = array();
	$migration['database_version'] = '95';
	$migration['migration_file'] = 'backfill_sct_plugin_name.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Marketplace admin menu entry (v96) ==========
	$migration = array();
	$migration['database_version'] = '96';
	$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'system-marketplace'";
	$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_slug, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon) SELECT 'Marketplace', 'system-marketplace', amu_admin_menu_id, 'admin_marketplace', 4, 8, 0, 'store' FROM amu_admin_menus WHERE amu_slug = 'system'";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// =============================================================================
	// VERSION CONSOLIDATION - Remove redundant settings
	// =============================================================================

	// ========== Remove deprecated database_version setting (v97) ==========
	$migration = array();
	$migration['database_version'] = '97';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'database_version'";
	$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'database_version';";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Remove deprecated db_migration_version setting (v98) ==========
	$migration = array();
	$migration['database_version'] = '98';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'db_migration_version'";
	$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'db_migration_version';";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// =============================================================================
	// TIER GATING SETTINGS
	// =============================================================================

	// ========== Tier gate preview length setting (v99) ==========
	$migration = array();
	$migration['database_version'] = '99';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'tier_gate_preview_length'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value) VALUES ('tier_gate_preview_length', '0');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Tier gate hide from listings setting (v100) ==========
	$migration = array();
	$migration['database_version'] = '100';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'tier_gate_hide_from_listings'";
	$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value) VALUES ('tier_gate_hide_from_listings', '0');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// =============================================================================
	// ADMIN MENU CLEANUP
	// =============================================================================

	// ========== Disable Marketplace sidebar menu item (v101) ==========
	$migration = array();
	$migration['database_version'] = '101';
	$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'system-marketplace' AND amu_disable = 1";
	$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_disable = 1 WHERE amu_slug = 'system-marketplace'";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

