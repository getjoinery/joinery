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
		// REMOVED: blog_subdirectory migration - setting was deprecated and deleted in migration 32
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
 		$migration['database_version'] = '30';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = 'admin_locations'";
		$migration['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon", "amu_slug", "amu_setting_activate") VALUES (\'Locations\', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = \'events\'), \'admin_locations\', 5, 5, 0, \'\', \'locations\', \'events_active\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;	
		// REMOVED: blog_subdirectory DELETE migration - INSERT was also removed from migration 12
 		$migration['database_version'] = '39';
		$migration['test'] = NULL;
		$migration['migration_sql'] = 'ALTER TABLE usr_users ALTER COLUMN usr_password TYPE varchar(255);';
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
 		$migration['database_version'] = '46';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'random_test_value'";
		$migration['migration_sql'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'random_test_value'";
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
		$migration = array();
		$migration = array();
		$migration = array();
		$migration = array();
		$migration = array();
		$migration = array();
		$migration = array();
		// Add test mode settings
		$migration = array();
		$migration = array();
		$migration = array();
		// Add debug setting
		$migration = array();
		// Add email service selection settings
		$migration = array();
		$migration = array();
		// Add default email template setting for EmailSender::quickSend
		$migration = array();
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
		// Setting 2: subscription_downgrade_timing
		$migration = array();
		// Setting 3: subscription_cancellation_enabled
		$migration = array();
		// Setting 4: subscription_cancellation_timing
		$migration = array();
		// Setting 5: subscription_reactivation_enabled
		$migration = array();
		// Setting 6: subscription_cancellation_prorate
		$migration = array();
		// Add Subscription Tiers menu item under Products
		$migration = array();
		$migration['database_version'] = '63';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'subscription-tiers'";
		$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) VALUES ('Subscription Tiers', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'products'), 'admin_subscription_tiers', 10, 5, 0, '', 'subscription-tiers', 'subscriptions_active');";
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Setting: subscription_downgrade_prorate
		$migration = array();
		// Setting: subscription_upgrade_prorate
		$migration = array();
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
		// Test: returns 0 while the table still exists (→ run), 1 after it has been dropped (→ skip)
		$migration = array();
		$migration['database_version'] = '67';
		$migration['test'] = "SELECT CASE WHEN EXISTS(SELECT 1 FROM pg_tables WHERE tablename = 'timezone' AND schemaname = 'public') THEN 0 ELSE 1 END as count";
		$migration['migration_sql'] = 'DROP TABLE IF EXISTS public.timezone CASCADE;';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;

		// Drop country table - consolidated into cco_country_codes
		// Test: returns 0 while the table still exists (→ run), 1 after it has been dropped (→ skip)
		$migration = array();
		$migration['database_version'] = '67';
		$migration['test'] = "SELECT CASE WHEN EXISTS(SELECT 1 FROM pg_tables WHERE tablename = 'country' AND schemaname = 'public') THEN 0 ELSE 1 END as count";
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
		// Add cookie_privacy_policy_link setting
		$migration = array();
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

	// ========== Event Types Menu Item (v78) ==========
	// Add Event Types menu item under Events
	$migration = array();
	$migration['database_version'] = '78';
	$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'event-types'";
	$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) VALUES ('Event Types', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'events'), 'admin_event_types', 5, 8, 0, '', 'event-types', 'events_active');";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Entity Photos System (v80) ==========
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
	$migration['migration_sql'] = "INSERT INTO thm_themes (thm_name, thm_display_name, thm_description, thm_version, thm_author, thm_is_active, thm_receives_upgrades, thm_is_system, thm_create_time, thm_update_time) VALUES ('joinery-system', 'Joinery System', 'Vanilla HTML5+CSS admin theme for the Joinery system', '1.0.0', 'Joinery Team', false, true, true, now(), now())";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// Update falcon to not be a system theme
	$migration = array();
	$migration['database_version'] = '87';
	$migration['test'] = "SELECT count(1) as count FROM thm_themes WHERE thm_name = 'falcon' AND thm_is_system = false";
	$migration['migration_sql'] = "UPDATE thm_themes SET thm_is_system = false WHERE thm_name = 'falcon'";
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

	// ========== Attribution menu entry under Statistics (v102) ==========
	$migration = array();
	$migration['database_version'] = '102';
	$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'attribution'";
	$migration['migration_sql'] = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) VALUES ('Attribution', (SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'statistics'), 'admin_analytics_attribution', 6, 5, 0, '', 'attribution', '')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Backfill pro_fil_file_id from EntityPhotos (v103) ==========
	// Populate primary photo FK on products that already have EntityPhoto rows
	// but no denormalized pro_fil_file_id pointer. No-op on deployments where
	// product photos have never been written. Idempotent — relies on hash-based
	// one-shot protection (no test condition needed).
	$migration = array();
	$migration['database_version'] = '103';
	$migration['test'] = NULL;
	$migration['migration_sql'] = "UPDATE pro_products p SET pro_fil_file_id = (SELECT eph_fil_file_id FROM eph_entity_photos WHERE eph_entity_type = 'product' AND eph_entity_id = p.pro_product_id AND eph_delete_time IS NULL ORDER BY eph_sort_order ASC LIMIT 1) WHERE pro_fil_file_id IS NULL AND EXISTS (SELECT 1 FROM eph_entity_photos WHERE eph_entity_type = 'product' AND eph_entity_id = p.pro_product_id AND eph_delete_time IS NULL)";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Drop pag_body column (v104) ==========
	// Page content is now stored entirely via pag_component_layout (JSON array of
	// pac_page_content_id values). All existing pag_body data was migrated to
	// custom_html components before this migration was added.
	$migration = array();
	$migration['database_version'] = '104';
	$migration['test'] = NULL;
	$migration['migration_sql'] = "ALTER TABLE pag_pages DROP COLUMN IF EXISTS pag_body";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Collapse legacy plg_status='uninstalled' rows (v105) ==========
	// The three-state lifecycle removes the 'uninstalled' status; any existing
	// rows represent an operator's committed intent to uninstall that was left
	// partially done under the old model. Finish the destructive half.
	$migration = array();
	$migration['database_version'] = '105';
	$migration['migration_file'] = 'cleanup_uninstalled_plugins.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// =============================================================================
	// DECLARATIVE PROFILE MENU — shared menu infrastructure
	// =============================================================================

	// ========== Backfill amu_location/amu_visibility on existing rows (v106) ==========
	// update_database adds the columns but does not backfill pre-existing rows.
	// Set every NULL row to the admin sidebar with visibility='in'. No test
	// condition — relies on hash-based one-shot protection.
	$migration = array();
	$migration['database_version'] = '106';
	$migration['test'] = NULL;
	$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_location = COALESCE(amu_location, 'admin_sidebar'), amu_visibility = COALESCE(amu_visibility, 'in') WHERE amu_location IS NULL OR amu_visibility IS NULL";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Seed core user_dropdown rows (v107) ==========
	// Migrates the hardcoded user-menu items from PublicPageBase::get_menu_data()
	// into amu_admin_menus rows tagged amu_location='user_dropdown'. Idempotent —
	// the per-row test guards each insert.
	$core_user_dropdown_rows = array(
		array('slug'=>'core-home',                'display'=>'Home',              'page'=>'/',                          'visibility'=>'both', 'permission'=>0, 'setting'=>'',                'icon'=>'home',           'order'=>10),
		array('slug'=>'core-signin',              'display'=>'Sign in',           'page'=>'/login',                     'visibility'=>'out',  'permission'=>0, 'setting'=>'',                'icon'=>'sign-in',        'order'=>20),
		array('slug'=>'core-forgot-password',     'display'=>'Forgot Password',   'page'=>'/password-reset-1',          'visibility'=>'out',  'permission'=>0, 'setting'=>'',                'icon'=>'key',            'order'=>30),
		array('slug'=>'core-signup',              'display'=>'Sign up',           'page'=>'/register',                  'visibility'=>'out',  'permission'=>0, 'setting'=>'register_active', 'icon'=>'user-plus',      'order'=>40),
		array('slug'=>'core-profile',             'display'=>'My Profile',        'page'=>'/profile',                   'visibility'=>'in',   'permission'=>1, 'setting'=>'',                'icon'=>'user',           'order'=>50),
		array('slug'=>'core-orders',              'display'=>'Orders',            'page'=>'/profile#orders',            'visibility'=>'in',   'permission'=>1, 'setting'=>'',                'icon'=>'shopping-bag',   'order'=>60),
		array('slug'=>'core-subscriptions',       'display'=>'Subscriptions',     'page'=>'/profile/subscriptions',     'visibility'=>'in',   'permission'=>1, 'setting'=>'',                'icon'=>'refresh',        'order'=>70),
		array('slug'=>'core-admin-dashboard',     'display'=>'Admin Dashboard',   'page'=>'/admin/admin_users',         'visibility'=>'in',   'permission'=>5, 'setting'=>'',                'icon'=>'dashboard',      'order'=>100),
		array('slug'=>'core-admin-help',          'display'=>'Admin Help',        'page'=>'/admin/admin_help',          'visibility'=>'in',   'permission'=>5, 'setting'=>'',                'icon'=>'question-circle','order'=>110),
		array('slug'=>'core-admin-settings',      'display'=>'Admin Settings',    'page'=>'/admin/admin_settings',      'visibility'=>'in',   'permission'=>6, 'setting'=>'',                'icon'=>'wrench',         'order'=>120),
		array('slug'=>'core-admin-utilities',     'display'=>'Admin Utilities',   'page'=>'/admin/admin_utilities',     'visibility'=>'in',   'permission'=>6, 'setting'=>'',                'icon'=>'tools',          'order'=>130),
		array('slug'=>'core-signout',             'display'=>'Sign out',          'page'=>'/logout',                    'visibility'=>'in',   'permission'=>1, 'setting'=>'',                'icon'=>'sign-out',       'order'=>200),
	);
	$version_counter = 107;
	foreach ($core_user_dropdown_rows as $row) {
		$migration = array();
		$migration['database_version'] = (string)$version_counter;
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = '" . $row['slug'] . "'";
		$setting_sql = $row['setting'] === '' ? 'NULL' : ("'" . $row['setting'] . "'");
		$migration['migration_sql'] = sprintf(
			"INSERT INTO amu_admin_menus (amu_menudisplay, amu_slug, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_setting_activate, amu_location, amu_visibility) VALUES ('%s', '%s', '%s', %d, %d, 0, '%s', %s, 'user_dropdown', '%s')",
			str_replace("'", "''", $row['display']),
			$row['slug'],
			$row['page'],
			$row['order'],
			$row['permission'],
			$row['icon'],
			$setting_sql,
			$row['visibility']
		);
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;
		$version_counter++;
	}

	// ========== Remove orphan Calendly settings (v121) ==========
	// Calendly integration was removed (dead utility files and disabled callers).
	// These four settings have no remaining UI or consumer; drop the orphan rows.
	// No test condition — relies on hash-based one-shot protection.
	$migration = array();
	$migration['database_version'] = '121';
	$migration['test'] = NULL;
	$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name IN ('calendly_organization_uri','calendly_organization_name','calendly_api_key','calendly_api_token')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// =============================================================================
	// MAILING LIST PROVIDER ABSTRACTION — rename provider-specific columns to
	// provider-neutral names. Coordinated with $field_specifications changes in
	// the same release.
	// =============================================================================

	// ========== Rename mlt_mailchimp_list_id → mlt_provider_list_id (v122) ==========
	$migration = array();
	$migration['database_version'] = '122';
	$migration['test'] = "SELECT count(1) as count FROM information_schema.columns WHERE table_name = 'mlt_mailing_lists' AND column_name = 'mlt_provider_list_id'";
	$migration['migration_sql'] = "ALTER TABLE mlt_mailing_lists RENAME COLUMN mlt_mailchimp_list_id TO mlt_provider_list_id";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Rename ctt_mailchimp_list_id → ctt_provider_list_id (v123) ==========
	$migration = array();
	$migration['database_version'] = '123';
	$migration['test'] = "SELECT count(1) as count FROM information_schema.columns WHERE table_name = 'ctt_contact_types' AND column_name = 'ctt_provider_list_id'";
	$migration['migration_sql'] = "ALTER TABLE ctt_contact_types RENAME COLUMN ctt_mailchimp_list_id TO ctt_provider_list_id";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Rename usr_mailchimp_user_id → usr_mailing_list_provider_id (v124) ==========
	$migration = array();
	$migration['database_version'] = '124';
	$migration['test'] = "SELECT count(1) as count FROM information_schema.columns WHERE table_name = 'usr_users' AND column_name = 'usr_mailing_list_provider_id'";
	$migration['migration_sql'] = "ALTER TABLE usr_users RENAME COLUMN usr_mailchimp_user_id TO usr_mailing_list_provider_id";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Rename scrolldaddy plugin → dns_filtering (v125) ==========
	$migration = array();
	$migration['database_version'] = '125';
	$migration['migration_file'] = 'rename_scrolldaddy_to_dns_filtering.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Rename plugin settings: scrolldaddy_* → dns_filtering_* (v126) ==========
	$migration = array();
	$migration['database_version'] = '126';
	$migration['migration_file'] = 'rename_scrolldaddy_settings.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Extension flag split: is_stock → receives_upgrades (v127) ==========
	// Copy data from the old plg_is_stock / thm_is_stock columns into the new
	// plg_receives_upgrades / thm_receives_upgrades columns (auto-created from
	// $field_specifications by Step 1 of update_database, which runs before this
	// migration), then drop the legacy columns. Spec:
	// specs/extension_flag_split_receives_upgrades_included_in_publish.md
	$migration = array();
	$migration['database_version'] = '127';
	$migration['test'] = "SELECT CASE WHEN EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='thm_themes' AND column_name='thm_is_stock') THEN 0 ELSE 1 END as count";
	$migration['migration_sql'] = "DO \$\$ BEGIN UPDATE thm_themes SET thm_receives_upgrades = thm_is_stock; UPDATE plg_plugins SET plg_receives_upgrades = plg_is_stock; ALTER TABLE thm_themes DROP COLUMN thm_is_stock; ALTER TABLE plg_plugins DROP COLUMN plg_is_stock; END \$\$;";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// Clear com_css_framework for base components converted to HTML5.
	// cta_banner, feature_grid, hero_static, page_title were Bootstrap-only;
	// they are now framework-agnostic HTML5. custom_html was also Bootstrap-wrapped
	// and is now a raw HTML passthrough — its framework field was already NULL.
	$migration = array();
	$migration['database_version'] = '128';
	$migration['test'] = "SELECT count(1) as count FROM com_components WHERE com_type_key = 'cta_banner' AND com_css_framework IS NULL";
	$migration['migration_sql'] = "UPDATE com_components SET com_css_framework = NULL WHERE com_type_key IN ('cta_banner', 'feature_grid', 'hero_static', 'page_title')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Unify receipt templates (v129) ==========
	// Phase 2 of specs/receipts_refactor.md. Inserts purchase_receipt_default
	// and purchase_receipt_product_default; soft-deletes six legacy/orphan
	// templates that no code calls anymore.
	$migration = array();
	$migration['database_version'] = '129';
	$migration['test'] = "SELECT count(1) as count FROM emt_email_templates WHERE emt_name = 'purchase_receipt_default'";
	$migration['migration_file'] = 'migration_receipt_templates_unify.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Fix reversed site_currency dropdown values (v130) ==========
	// admin_settings.php had the dropdown options array keys/values reversed
	// (display name as key, code as value) so any save through that page wrote
	// the display name into stg_settings. Stripe and code that indexes
	// CurrencyHelper both expect the ISO code. Convert any rows
	// that hold a display name back to its code; leave correct values alone.
	$migration = array();
	$migration['database_version'] = '130';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'site_currency' AND stg_value IN ('usd','eur','')";
	$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = CASE stg_value WHEN 'US Dollar' THEN 'usd' WHEN 'Euro' THEN 'eur' ELSE stg_value END WHERE stg_name = 'site_currency' AND stg_value IN ('US Dollar','Euro')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Fix purchase_receipt_default itemized list table width (v131) ==========
	// v129 seeded the receipt with width:100%, which inherits its rendered width
	// from whatever container the mail client puts it in — produces uneven /
	// stretched layouts. Lock the itemized table to a fixed 600px (industry-
	// standard email width). The seed in migration_receipt_templates_unify.php
	// is updated to match so fresh installs (which run v129 and find the template
	// already 600px) and upgrades (which had v129 install at 100% and now get
	// rewritten by v131) end in the same state.
	$migration = array();
	$migration['database_version'] = '131';
	$migration['test'] = "SELECT count(1) as count FROM emt_email_templates WHERE emt_name = 'purchase_receipt_default' AND emt_delete_time IS NULL AND emt_body LIKE '%width:600px;border-collapse:collapse;margin:1rem 0;%'";
	$migration['migration_sql'] = "UPDATE emt_email_templates SET emt_body = REPLACE(emt_body, '<table style=\"width:100%;border-collapse:collapse;margin:1rem 0;\">', '<table style=\"width:600px;border-collapse:collapse;margin:1rem 0;\">'), emt_update_time = now() WHERE emt_name = 'purchase_receipt_default' AND emt_delete_time IS NULL";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Backfill usr_terms_accepted_time + clean usr_lastlogin_time (v132) ==========
	// Spec: specs/implemented/terms_acceptance_capture.md.
	// Schema: usr_terms_accepted_time was added (and usr_lastlogin_time default
	// dropped) via $field_specifications, applied by update_database before this
	// migration. This step is the data backfill: stamp existing users who have
	// real log_logins entries, and null out fictional usr_lastlogin_time values
	// that the old default => now() left on every insert.
	$migration = array();
	$migration['database_version'] = '132';
	$migration['migration_file'] = 'migration_terms_accepted_backfill.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Backfill apk_type = 'machine' on pre-existing API keys (v133) ==========
	// Spec: specs/implemented/user_session_api_keys.md.
	// Schema: apk_type was added via $field_specifications, applied by
	// update_database before this migration. Every key that existed before the
	// type column is an admin-provisioned machine key; session keys are only
	// minted with an explicit type by ApiKey::CreateSessionKey(). Keeps the
	// fail-closed machine-key gate in ManagementApiRouter working for existing
	// integrations immediately after deploy.
	$migration = array();
	$migration['database_version'] = '133';
	$migration['migration_file'] = 'api_key_type_backfill.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Default chat thinking level off → medium (v134) ==========
	// The chat composer now defaults new conversations to medium reasoning.
	// Move the existing default only where it still holds the old 'off' value,
	// so a deployment that deliberately set something else is left alone. Fresh
	// installs get 'medium' from the plugin.json declared default (seed); the
	// new joinery_ai_default_web_search setting is also seeded, no migration.
	$migration = array();
	$migration['database_version'] = '134';
	$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'joinery_ai_default_thinking_level' AND stg_value <> 'off'";
	$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = 'medium' WHERE stg_name = 'joinery_ai_default_thinking_level' AND stg_value = 'off'";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// Collapse the per-store offload task pairs into one CloudOffloadRun tick.
	// Hash-tracked (runs once) and idempotent, so no test gate is needed.
	$migration['database_version'] = '135';
	$migration['test'] = NULL;
	$migration['migration_sql'] = NULL;
	$migration['migration_file'] = 'migrate_offload_single_task.php';
	$migrations[] = $migration;

	// ========== Member menu entries: min_permission 1 → 0 (v136) ==========
	// Members are permission 0 (usr_permission zero_on_create), and the user
	// dropdown filters on amu_min_permission <= permission — so entries seeded
	// at permission 1 were invisible to every real member. Signed-in-ness is
	// the visibility='in' field's job; permission gates only staff tiers.
	// admin_menus.json now declares 0, but core seeding is insert-only, so
	// existing rows move here. Scoped to the known core slugs; idempotent.
	$migration = array();
	$migration['database_version'] = '136';
	$migration['test'] = NULL;
	$migration['migration_sql'] = "UPDATE amu_admin_menus SET amu_min_permission = 0
		WHERE amu_location = 'user_dropdown' AND amu_min_permission = 1
		AND amu_slug IN ('core-profile','core-calendar','core-orders','core-subscriptions',
			'core-events','core-event-sessions','core-signout')";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Repair SQL-quoted fil_storage_driver values (v137) ==========
	// Field-spec defaults are plain PHP values, but fil_storage_driver's spec
	// was written SQL-quoted ("'local'"), and SystemBase::save() applies the
	// spec default to new rows verbatim — so every row created without an
	// explicit driver (File::createFromBytes: inbound-email attachments, AI
	// uploads) stored the literal six-character string 'local' INCLUDING the
	// quote characters. Those rows never match the cloud-offload eligibility
	// predicate (driver IS NULL OR driver = 'local'), so they silently never
	// offload. The spec is fixed to plain form; this repairs rows already
	// written. Idempotent; hash-tracked.
	// Guarded on column existence: fil_storage_driver has since moved to the
	// blob layer (fbb_file_blobs) and is dropped from fil_files, so on any DB
	// created after that change the column is absent and this repair is a no-op.
	$migration = array();
	$migration['database_version'] = '137';
	$migration['test'] = NULL;
	$migration['migration_sql'] = "DO \$\$ BEGIN
		IF EXISTS (SELECT 1 FROM information_schema.columns
		           WHERE table_name = 'fil_files' AND column_name = 'fil_storage_driver') THEN
			UPDATE fil_files SET fil_storage_driver = 'local' WHERE fil_storage_driver = '''local''';
		END IF;
	END \$\$;";
	$migration['migration_file'] = NULL;
	$migrations[] = $migration;

	// ========== Rename inbound_email plugin → mailbox (v138) ==========
	$migration = array();
	$migration['database_version'] = '138';
	$migration['migration_file'] = 'rename_inbound_email_to_mailbox.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Native member screen route flips + app_navigation slug (v139) ==========
	// Core menu seeding is insert-only, so the nativeScreen/URL changes in
	// admin_menus.json for the member-screens conversion move existing rows
	// here (same pattern as v136). Also repairs the app_navigation tab pinning
	// that still named the mailbox entry by its pre-rename slug.
	$migration = array();
	$migration['database_version'] = '139';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'native_member_screen_menu_flips.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Drop dead Event Sessions menu entry (v140) ==========
	// core-event-sessions is a deep link needing an event id, not a menu
	// destination — it errors when reached from the menu. admin_menus.json
	// dropped it, but core menu seeding never prunes, so the existing row is
	// deleted here (insert-only precedent, same as v136/v139).
	$migration = array();
	$migration['database_version'] = '140';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'drop_event_sessions_menu.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// ========== Generalize event FKs into polymorphic provider columns (v141-145) ==========
	// Each of these backfills the new provider/reference columns from the old
	// event FK and drops the old column. Self-guarded on information_schema so
	// they no-op once the column is gone. See the store/event_manager plugin
	// extraction spec, § Schema-change mechanics.
	$migration = array();
	$migration['database_version'] = '141';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'generalize_msg_event_context.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '142';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'generalize_erg_recipient_provider.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '143';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'generalize_vid_access_gate.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '144';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'generalize_fil_access_gate.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	$migration = array();
	$migration['database_version'] = '145';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'generalize_pro_fulfillment.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Rename ScrollDaddy tier-feature keys to the plugin-namespaced form so stored
	// values line up with what the admin form posts and every runtime read expects.
	$migration = array();
	$migration['database_version'] = '146';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'rename_scrolldaddy_tier_feature_keys.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Prune the admin/profile menu rows for the store + event_manager extractions.
	// Core menu seeding never prunes, so removing entries from admin_menus.json /
	// the imperative profile seeds would orphan the old rows; this removes them.
	// The plugins re-seed their own (renamed) menus on activation.
	$migration = array();
	$migration['database_version'] = '147';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'prune_extracted_menu_rows.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Remove the plugin-seeded 'event-manager-event-sessions' profile menu row.
	// event_manager no longer declares it; syncMenus can't prune an install whose
	// stored _menu_slugs already advanced past the slug, so drop it here.
	$migration = array();
	$migration['database_version'] = '148';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'drop_event_manager_event_sessions_menu.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Re-parent the core Subscription Tiers admin menu under Users (its former
	// 'products' parent now belongs to the store plugin and gets a new id each
	// sync). Core menu seeding is insert-only, so realign the existing row.
	$migration = array();
	$migration['database_version'] = '149';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'reparent_subscription_tiers_menu.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Delete rows violating declared foreign keys (orphaned vault wrappings,
	// stray test-fixture users and their descendants) so the FOREIGN KEYS step
	// can materialize the declared constraints in the same run.
	$migration = array();
	$migration['database_version'] = '150';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'cleanup_orphaned_fk_rows.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Required-ness of a question is the qst_is_required column; promote any
	// legacy 'required' key out of the qst_validate blob so every enforcement
	// surface (surveys, store requirements, browser JS) reads one source.
	$migration = array();
	$migration['database_version'] = '151';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'promote_question_required_to_column.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;


	// passkeys_enabled becomes an emergency kill switch defaulting to on
	// (specs/mailbox_protection_ceremony.md § 4): vault setup and protected
	// mailboxes depend on passkeys, so rows still at the old factory '0' flip.
	$migration = array();
	$migration['database_version'] = '152';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'passkeys_enabled_default_on.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Spam filtering collapses to one switch, on by default
	// (specs/mailbox_spam_filtering_simplification.md D5). Carries the stored
	// content-scanner preference over to its outcome-named replacement
	// (mailbox_spam_learning_enabled) and flips master rows still at the old
	// factory '0' — a deployment that never found the switch was filing spam
	// into the inbox while its relay scored every message.
	$migration = array();
	$migration['database_version'] = '153';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'spam_filtering_one_switch.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Management-job rows persist forever; rows built before credential
	// placeholders carried decrypted cloud-storage credentials inline in
	// mjb_commands. Replace stored secret values with __SM_SCRUBBED__ in
	// mjb_commands/mjb_output (idempotent, value-match).
	$migration = array();
	$migration['database_version'] = '154';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'scrub_job_row_inline_credentials.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// The settings save walked the whole POST, so form and request plumbing —
	// _csrf_token, submit_button, __route, the captcha response fields, and the
	// General page's *_readonly path mirrors — became settings rows and were
	// re-written on every save. The save path now refuses those names; this
	// removes the rows that accumulated before it did. Nothing reads them.
	$migration = array();
	$migration['database_version'] = '155';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'purge_reserved_setting_rows.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// The Acuity scheduling client and the Urbit endpoint settings are gone —
	// Acuity was a closed loop whose only reader was its own connection test,
	// and the Urbit rows never had a reader. Remove the rows they left behind.
	$migration = array();
	$migration['database_version'] = '156';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'purge_dead_integration_settings.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Menu redesign: core seeding is insert-only, so realign existing core
	// rows — the /profile entry is titled Dashboard, and the Admin Utilities
	// row's permission matches the page's permission-10 check.
	$migration = array();
	$migration['database_version'] = '157';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'menu_redesign_row_updates.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Menu rows on installs seeded under the old convention store -1 for
	// no parent; the current convention is NULL/0 (see the model's
	// has_no_parent_menu_id filter), and nav code treats any non-empty
	// parent as a child row — so -1 rows vanish from the member nav.
	// Normalize the sentinel. Row ids start at 1, so < 1 only matches
	// sentinels. Idempotent.
	$migration = array();
	$migration['database_version'] = '158';
	$migration['test'] = NULL;
	$migration['migration_file'] = NULL;
	$migration['migration_sql'] = 'UPDATE amu_admin_menus SET amu_parent_menu_id = NULL WHERE amu_parent_menu_id < 1;';
	$migrations[] = $migration;

	// Retained as version history only. Sweeping finished device-link ceremonies
	// is a retention rule declared on DeviceLink ($retention_policy), run by the
	// daily Retention Sweep — so there is no task row for this migration to
	// insert. Its slot stays occupied so version numbering is unbroken.
	$migration = array();
	$migration['database_version'] = '159';
	$migration['test'] = NULL;
	$migration['migration_file'] = NULL;
	$migration['migration_sql'] = "SELECT 1;";
	$migrations[] = $migration;

	// Retained as version history only. Clearing plaintext from runs that read
	// protected mail reads and writes rcr_recipe_runs, a PLUGIN table — and
	// migrations run several hundred lines before PluginManager::sync() adds or
	// alters plugin columns, so a migration here cannot see rcr_content_sealed.
	// The purge lives in joinery_ai's sync.php hook, which runs after that step.
	// specs/implemented/sealed_content_egress.md § resolved decision 2.
	$migration = array();
	$migration['database_version'] = '160';
	$migration['test'] = NULL;
	$migration['migration_file'] = NULL;
	$migration['migration_sql'] = "SELECT 1;";
	$migrations[] = $migration;

	// The recovery keypair became core infrastructure: a standalone site with no
	// server_manager still needs one before it can encrypt its own backups. Carry
	// the configured public key and its possession proof onto the core setting
	// names, so an operator who already ran the ceremony is not asked again.
	$migration = array();
	$migration['database_version'] = '161';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'adopt_core_backup_recovery_key.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// Per-node backup key escrow is retired — each backup seals its own key to
	// the recovery key as it is made. The recovery key moved to core setting
	// names in 161; this removes the two rows it left behind, so every stored
	// setting stays declared.
	$migration = array();
	$migration['database_version'] = '162';
	$migration['test'] = NULL;
	$migration['migration_file'] = 'purge_escrow_setting_rows.php';
	$migration['migration_sql'] = NULL;
	$migrations[] = $migration;

	// backup_output_dir shipped defaulting to /backups, which nothing the site
	// runs as can create — every backup failed with "does not exist and could
	// not be created" until someone made it as root. Blank now means the site's
	// own backups/ directory, which exists on every deployment shape. Only the
	// old default is cleared: an operator who chose their own path keeps it.
	$migration = array();
	$migration['database_version'] = '163';
	$migration['test'] = NULL;
	$migration['migration_file'] = NULL;
	$migration['migration_sql'] = "UPDATE stg_settings SET stg_value = ''
		WHERE stg_name = 'backup_output_dir' AND stg_value = '/backups';";
	$migrations[] = $migration;

	// Contacts became per-mailbox. Rows written before that carry no mailbox, and
	// every read is mailbox-scoped, so they can never be returned again. Nothing
	// re-creates them either: contacts are entered deliberately now, not harvested
	// from mail traffic. Left in place they would be permanently invisible dead
	// weight, so they go. Idempotent — a second run deletes nothing.
	$migration = array();
	$migration['database_version'] = '164';
	// Core migrations run on every site, including the ones that have never
	// installed the mailbox plugin and so have no contacts table at all. Without
	// this guard the statement is a hard error there, and a failed migration
	// rolls the whole upgrade back — a site is left on the old release over a
	// table it was never supposed to have. The guard is skip-when-absent: count
	// > 0 means "do not run".
	$migration['test'] = "SELECT CASE WHEN EXISTS (
			SELECT 1 FROM information_schema.tables
			WHERE table_name = 'imc_mailbox_contacts' AND table_schema = 'public'
		) THEN 0 ELSE 1 END as count";
	$migration['migration_file'] = NULL;
	$migration['migration_sql'] = "DELETE FROM imc_mailbox_contacts
		WHERE imc_iea_inbound_email_alias_id IS NULL;";
	$migrations[] = $migration;



	// debug_css is gone. It did one thing: on the two Tailwind page classes it
	// loaded Tailwind's browser JIT from a CDN and SKIPPED the theme's compiled
	// output.css, so a single setting could unstyle a production site. No theme
	// in use extends those classes, every Tailwind theme ships a compiled
	// stylesheet, and pulling a third-party script into a rendered page is not
	// something a setting should be able to switch on.
	//
	// Dropping the declaration alone would leave the row behind, and an
	// undeclared row fails the every-row-is-declared check (docs/settings.md).
	// Idempotent: a second run deletes nothing.
	$migration = array();
	$migration['database_version'] = '165';
	$migration['test'] = NULL;
	$migration['migration_file'] = NULL;
	$migration['migration_sql'] = "DELETE FROM stg_settings WHERE stg_name = 'debug_css';";
	$migrations[] = $migration;
