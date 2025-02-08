<?php

		//DATABASE MIGRATIONS
		//NOTE!!  ALL MIGRATIONS HAVE TO BE WRITTEN SUCH THAT THEY CAN BE RUN REPEATEDLY
		//IT BAILS ON ERROR AND STOPS MIGRATIONS, IN CASE SOME LATER ONES ARE DEPENDENT ON EARLIER ONES
		//IF THERE IS A TEST SQL AND IF IT RETURNS == 0, THEN WE RUN THE MIGRATION
		//IF THERE IS NO TEST SQL, IT IS ASSUMED THAT WE ALWAYS RUN THE MIGRATION
		//IF $migration['migration_file'] = 'SOME_FILE', THEN WE LOOK IN THE MIGRATIONS FOLDER AND RUN THAT MIGRATION
		
		
 		$migration['database_version'] = '20250104';
		$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'controld_key'";
		$migration['migration_sql'] = 'INSERT INTO "public"."stg_settings"("stg_name", "stg_value", "stg_usr_user_id", "stg_create_time", "stg_update_time", "stg_group_name") VALUES (\'controld_key\', \'\', 1, \'now()\', \'now()\', \'general\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		
		 

 		$migration['database_version'] = '20250201';
		$migration['test'] = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = '/plugins/controld/admin/admin_ctld_accounts'";
		$migration['migration_sql'] = 'INSERT INTO "public"."amu_admin_menus"("amu_menudisplay", "amu_parent_menu_id", "amu_defaultpage", "amu_order", "amu_min_permission", "amu_disable", "amu_icon", "amu_slug", "amu_setting_activate") VALUES (\'Accounts\', NULL, \'/plugins/controld/admin/admin_ctld_accounts\', 5, 8, 0, \'\', \'accounts\', \'controld_key\');';
		$migration['migration_file'] = NULL;
		$migrations[] = $migration;		 