<?php
	require_once( __DIR__ . '/class_list.php');
	error_reporting(E_ERROR | E_PARSE);
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	//error_reporting(E_ALL);
	
	//THIS SCRIPT ACCEPTS THREE POSSIBLE ARGUMENTS
	//VERBOSE PRINTS MISMATCHES TO THE SCREEN
	//UPGRADE FIXES MISMATCHES IN COLUMN TYPES
	//CLEANUP DELETES SUPERFLUOUS COLUMNS
	
	if($_REQUEST['verbose']){
		$verbose=$_REQUEST['verbose'];
	}
	else{
		$verbose=false;
	}

	if($_REQUEST['upgrade']){
		$upgrade=$_REQUEST['upgrade'];
	}
	else{
		$upgrade=false;
	}
	
	if($_REQUEST['cleanup']){
		$cleanup=$_REQUEST['cleanup'];
	}
	else{
		$cleanup=false;
	}

	/*
	THIS WILL CHECK THE SPECS IN THE $fields and $field_specifications VARIABLES AND CREATE AND/OR UPDATE THE TABELS AS NEEDED
	
	-IT WILL ONLY ADD COLUMNS.  IT WILL NOT DELETE THEM.
	-IF THE DATA TYPES DO NOT MATCH YOU WILL GET A WARNING BUT IT WILL NOT FIX
	-IF CHARACTER LENGTH DOES NOT MATCH YOU WILL GET A WARNING BUT IT WILL NOT FIX
	*/

	
	

	function update_database($classes, $verbose=false, $upgrade=false, $cleanup=false){
		error_reporting(E_ERROR | E_PARSE);
		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		
		$tables_and_columns = LibraryFunctions::get_tables_and_columns();
		
		foreach($classes as $class){
			$table_name = $class::$tablename;
			$pkey_column = $class::$pkey_column;
			$live_table_columns = $tables_and_columns[$table_name];
			if(!$live_table_columns){
				//THERE IS NO TABLE.  CREATE IT
				$sequence_name = $table_name.'_'.$pkey_column.'_seq';
				
				$sql = 'CREATE SEQUENCE IF NOT EXISTS '.$sequence_name.'
					INCREMENT BY 1
					NO MAXVALUE
					NO MINVALUE
					CACHE 1;';
				echo $sql."<br>\n";

				try{
					$q = $dblink->prepare($sql);
					$q->execute();
				}
				catch(PDOException $e){
					echo $e->getMessage();
				}				
					
				$sql = '
					CREATE TABLE "public"."'.$table_name.'" (';
					
				foreach($class::$field_specifications as $field_name=>$field_specs){
					
					$sql .= ' "'.$field_name.'" '.$field_specs[type];
						
					if(isset($field_specs[is_nullable]) && !$field_specs[is_nullable]){
						$sql .= ' NOT NULL ';
					}

					if(isset($field_specs[serial]) && $field_specs[serial]){
						$sql .= 'DEFAULT nextval(\''.$sequence_name.'\'::regclass)';
					}
					$sql .= ', ';
				}
				//REMOVE LAST COMMA
				$sql = substr($sql, 0, -2);
				$sql .= ');';
				
				echo $sql."<br>\n";
				
				try{
					$q = $dblink->prepare($sql);
					$q->execute();
				}
				catch(PDOException $e){
					echo $e->getMessage();
				}	
				
					
				$sql = 'ALTER TABLE "public"."'.$table_name.'" ADD CONSTRAINT "'.$table_name.'_pkey" PRIMARY KEY ("'.$pkey_column.'");';
				echo $sql."<br>\n";
				
				try{
					$q = $dblink->prepare($sql);
					$q->execute();
				}
				catch(PDOException $e){
					echo $e->getMessage();
				}	
				
				
				//NOW GET THE COLUMNS AGAIN
				$live_table_columns = $tables_and_columns[$table_name];
			}
		} 
		
		
		
		//REFRESH EVERYTHING AFTER ADDING TABLES
		$tables_and_columns = LibraryFunctions::get_tables_and_columns();
		foreach ($classes as $class){
			$table_name = $class::$tablename;
			$pkey_column = $class::$pkey_column;
			$live_table_columns = $tables_and_columns[$table_name];		
			//PULL THE COLUMN METADATA FOR CURRENT TABLE
				$sql = 'SELECT
					column_name,
					data_type,
					character_maximum_length
				FROM
					information_schema.columns
				WHERE
					table_name = \''.$table_name.'\'';
				try{
					$q = $dblink->prepare($sql);
					$q->execute();
					$q->setFetchMode(PDO::FETCH_OBJ);
				}
				catch(PDOException $e){
					echo $e->getMessage();
				}	
				
				
				$live_column_info = array();
				while ($row = $q->fetch()) {
					$live_column_info[$row->column_name][data_type] = $row->data_type;
					$live_column_info[$row->column_name][character_maximum_length] = $row->character_maximum_length;
				}			
			
		
			if($verbose){
				echo '---'.$table_name .'---<br>';
			}
			if(!isset($class::$field_specifications)){
				echo 'ERROR:  '.$table_name . ' has no field specifications.';
				exit;
			}
			$field_specifications = $class::$field_specifications;
			
			
			//MAKE SURE ALL OF THE SEQUENCE VALUES ARE CREATED AND ON THE RIGHT COLUMNS
			foreach($class::$field_specifications as $field_name=>$field_specs){
				if(isset($field_specs[serial]) && $field_specs[serial]){
					$sequence_name = $table_name.'_'.$pkey_column.'_seq';
					
					$sql = 'SELECT COUNT(*) as schema_found
						FROM information_schema.sequences 
						WHERE sequence_name= \''.$sequence_name.'\'';

					try{
						$q = $dblink->prepare($sql);
						$q->execute();
					}
					catch(PDOException $e){
						echo $e->getMessage();
					}	
					$row = $q->fetch();

					
					if(!$row[schema_found]){
						if($verbose){
							echo 'NOTICE: '. $sequence_name ." is missing.<br>\n"; 
						}

						//GET MAXIMUM VALUE FOR SEQUENCE
						$sql = 'SELECT MAX('.$field_name.') as max_val
							FROM '.$table_name;
							

						try{
							$q = $dblink->prepare($sql);
							$q->execute();
						}
						catch(PDOException $e){
							echo $e->getMessage();
						}	
						$row = $q->fetch();
						$max_val = $row[max_val];
						if(!$max_val){
							$max_val = 1;
						}
						
						//CREATE THE SEQUENCE
						$sql = 'CREATE SEQUENCE IF NOT EXISTS '.$sequence_name.'
							START WITH '.$max_val.'
							INCREMENT BY 1
							NO MAXVALUE
							NO MINVALUE
							CACHE 1;';
						echo $sql."<br>\n";

						try{
							$q = $dblink->prepare($sql);
							$q->execute();
						}
						catch(PDOException $e){
							echo $e->getMessage();
						}	

						//ADD IT TO THE COLUMN
						$sql = 'ALTER TABLE '.$table_name.' 
							ALTER COLUMN '.$field_name.' SET DEFAULT nextval(\''.$sequence_name.'\'::regclass);';
						echo $sql."<br>\n";

						try{
							$q = $dblink->prepare($sql);
							$q->execute();
						}
						catch(PDOException $e){
							echo $e->getMessage();
						}							
					
						
					}
				}
			}
			
			
			
			foreach($class::$fields as $field=>$description){
				$found=false;
				foreach($live_table_columns as $live_column){
					if($live_column == $field){
						$found=true;
					}
				}
				
				if($found){
					if($verbose || $upgrade){
						$upgrade_field = false;
						//CHECK THE COLUMN SPECS
						$field_length = LibraryFunctions::extract_length_from_spec($field_specifications[$field][type]);
						$field_without_length = preg_replace('/[^a-z ]/', '', $field_specifications[$field][type]);
						if(LibraryFunctions::translate_data_types($live_column_info[$field][data_type]) != $field_without_length){
							echo 'NOTICE: Data types do not match on field '.$field.' (live: '. $live_column_info[$field][data_type] .'<->spec:'. $field_without_length .")<br>\n";
							$upgrade_field = true;
						}
						
						//CHECK THE LENGTH
						$length_phrase = '';
						if($field_length){
							$length_phrase = '('.$field_length.')';
						}
						if($live_column_info[$field][character_maximum_length]){
							if($live_column_info[$field][character_maximum_length] != $field_length){
								echo 'NOTICE: Max character length does not match on field '.$field.' (live: '. $live_column_info[$field][character_maximum_length] .'<->spec: '. $field_length .")<br>\n";	
								$upgrade_field = true;								
							}
						}
						
						if($upgrade && $upgrade_field){
							//IF COLUMN LENGTH OR TYPE DOESN'T MATCH, UPGRADE IT

							$sql = 'ALTER TABLE '.$table_name.'
								ALTER COLUMN '.$field.' TYPE '.$field_specifications[$field][type];
								
							$sql .= ';';
							echo $sql."<br>\n";
							
							try{
								$q = $dblink->prepare($sql);
								$q->execute();
							}
							catch(PDOException $e){
								//DO NOT HALT THE PROGRAM, JUST NOTE IT
								echo 'ERROR: Could not alter column '.$field.' ('.$sql.')'. "<br>\n";
								//echo $e->getMessage();
							}								
							
						}
					}


				}
				else{
					if($verbose){
						echo $field.' needs to be added to live db<br>';
					}

					$sql = 'ALTER TABLE '.$table_name.'
						ADD COLUMN '.$field.' '.$field_specifications[$field][type];
						
					if(isset($field_specifications[$field][is_nullable]) && !$field_specifications[$field][is_nullable]){
						$sql .= ' NOT NULL ';
					}
						
					$sql .= ';';
					echo $sql."<br>\n";
					
					try{
						$q = $dblink->prepare($sql);
						$q->execute();
					}
					catch(PDOException $e){
						echo $e->getMessage();
					}	

				}
			}

			
			foreach($live_table_columns as $live_column){
				$found=false;
				foreach($class::$fields as $field=>$description){
					if($live_column == $field){
						$found=true;
					}
				}
				if(!$found){
					if($verbose || $cleanup){
						echo $live_column.' is in live table '.$table_name.' but not in class<br>';
							
						if($cleanup){
							$sql = 'ALTER TABLE '.$table_name.' DROP COLUMN '.$live_column;
								
							$sql .= ';';
							echo $sql."<br>\n";
							
							try{
								$q = $dblink->prepare($sql);
								$q->execute();
							}
							catch(PDOException $e){
								echo $e->getMessage();
							}							
						}
					}
				}
			}
		}


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

		
		
		echo "-----MIGRATIONS-----<br>\n";

		//GET THE LAST MIGRATION STOPPING POINT
		
		$sql = "SELECT * FROM stg_settings WHERE stg_name='db_migration_version'";
		$q = $dblink->prepare($sql);
		$q->execute();
		$row = $q->fetch();
		
		if($verbose){
			echo 'Last Migration ' . $row['stg_value'] . "<br>\n";
		}
		
		$next_row = 0;
		if($row['stg_value']){
			$next_row = $row['stg_value'];
			if($verbose){
				echo 'Starting Migration  at ' . $next_row . "<br>\n";
			}
		}

		
		foreach($migrations as $key=>$migration){
			
			if($next_row > $key){
				continue;
			}

			if($verbose){
				echo 'Checking Migration ' . $key . "<br>\n";
			}
			
			$run = true;
			if($migration[test]){
				if($verbose){
					echo 'Test: '.$migration[test]. "<br>\n";
				}
				
				try{
					$q = $dblink->prepare($migration[test]);
					$q->execute();
					$row = $q->fetch();
				}
				catch(PDOException $e){
					echo $e->getMessage();
					echo 'ABORTING MIGRATIONS at Migration '. $key ."<br>\n";
					exit;					
				}	

				if($row[count] == 0){
					$run = true;
				}
				else{
					$run = false;
				}
			}

			
			if($run && $migration[migration_sql]){
				try{
					$q = $dblink->prepare($migration[migration_sql]);
					$q->execute();
					echo 'Run: '.$migration[migration_sql]. "<br>\n";
				}
				catch(PDOException $e){
					echo $e->getMessage();
					echo 'ABORTING MIGRATIONS at Migration '. $key ."<br>\n";
					exit;
				}			
			}
			else{
				if($verbose){
					echo 'Skipping: '.$migration[migration_sql]. "<br>\n";
				}
			}

			//UPDATE THE SYSTEM VERSION
			$sql = "UPDATE stg_settings set stg_value='".$migrations[$key][system_version]."' WHERE stg_name='system_version'";
			try{
				$q = $dblink->prepare($sql);
				$q->execute();
				if($verbose){
					echo 'System version now '.$migrations[$key][system_version]."<br>\n";
				}
				
			}
			catch(PDOException $e){
				echo $e->getMessage();
				echo 'ABORTING MIGRATIONS.  Failed to set system version: '. $migrations[$key][system_version] ."<br>\n";
				exit;
			}	
				
			
			$next_row = $key+1;
			
			//UPDATE THE LAST DB MIGRATION POINT
			$sql = "UPDATE stg_settings set stg_value=".$next_row." WHERE stg_name='db_migration_version'";
			try{
				$q = $dblink->prepare($sql);
				$q->execute();
				if($verbose){
					echo 'Updating next migration to : '.$next_row. "<br>\n";
				}
			}
			catch(PDOException $e){
				echo $e->getMessage();
				echo 'ABORTING MIGRATIONS.  Failed to set next row: '. $key ."<br>\n";
				exit;
			}				
	
		}


		$sql = "SELECT * FROM stg_settings WHERE stg_name='system_version'";
		$q = $dblink->prepare($sql);
		$q->execute();
		$row = $q->fetch();		
		echo 'Database migration complete.  System version: '.$row['stg_value']. "<br>\n";
			
		return true;
	}
	
	update_database($classes, $verbose, $upgrade, $cleanup);
	echo 'Database update complete'. "<br>\n";
	return 0;


?>

