<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');
	require_once( __DIR__ . '/../data/migrations_class.php');
	require_once( __DIR__ . '/../migrations/migrations.php');
	//error_reporting(E_ERROR | E_PARSE);
	error_reporting(E_ERROR | E_PARSE);
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	///error_reporting(E_ALL);

	//THIS SCRIPT ACCEPTS THREE POSSIBLE ARGUMENTS
	//VERBOSE PRINTS MISMATCHES TO THE SCREEN
	//UPGRADE FIXES MISMATCHES IN COLUMN TYPES
	//CLEANUP DELETES SUPERFLUOUS COLUMNS
	
	if(isset($_REQUEST['verbose']) && $_REQUEST['verbose']){
		$verbose=$_REQUEST['verbose'];
	}
	else{
		$verbose=false;
	}

	if(isset($_REQUEST['upgrade']) && $_REQUEST['upgrade']){
		$upgrade=$_REQUEST['upgrade'];
	}
	else{
		$upgrade=false;
	}
	
	if(isset($_REQUEST['cleanup']) && $_REQUEST['cleanup']){
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


	
	

	function update_database($migrations, $verbose=false, $upgrade=false, $cleanup=false){

		//LOAD ALL CLASSES 
		$db_structure_contents = ''; 
		$path =  realpath(__DIR__ . '/../data');
		$classes = array();
		if ($handle = opendir($path)) {
			while (false !== ($file = readdir($handle))) {
				if ('.' === $file) continue;
				if ('..' === $file) continue;
				$filepath = $path.'/'.$file;
				
				$file_parts = pathinfo($file);
				if($file_parts['extension'] == 'php'){
					if(file_exists($filepath)){
						if (str_contains($file, '_class')) {
							require_once($filepath);
							if($verbose){
								echo 'Requiring '.$filepath.'<br>'."\n";
							}
							
							$fileContent = file_get_contents($filepath);
							$tokens = token_get_all($fileContent);

							for ($i = 0; $i < count($tokens); $i++) {
								if ($tokens[$i][0] === T_CLASS && $tokens[$i + 2][0] === T_STRING) {
									$thisclass = $tokens[$i + 2][1];;
									//TABLENAME AND FIELD SPECIFICATIONS ARE REQUIRED 
									if(isset($thisclass::$tablename) && isset($thisclass::$field_specifications)){
										if($verbose){
											echo 'Loading '.$thisclass.'<br>';
										}
										$classes[] = $thisclass;
										$db_structure_contents .= serialize($thisclass::$field_specifications);
									}
								}
							}						
							
						}
					}
				}
			}
			closedir($handle);
		}
		
		
		//LOAD ALL CLASSES FROM PLUGINS
		$plugin_dir = realpath(__DIR__ . '/../plugins');
		$plugins = LibraryFunctions::list_plugins($plugin_dir);
		foreach($plugins as $plugin){
			$plugin_data_dir = $plugin_dir.'/'.$plugin.'/data';
			if($verbose){
				echo 'Loading classes from plugin '.$plugin.'<br>'."\n";
			}
			if(is_dir($plugin_data_dir)){
				if ($handle = opendir($plugin_data_dir)) {
					while (false !== ($file = readdir($handle))) {
						if ('.' === $file) continue;
						if ('..' === $file) continue;
						$filepath = $plugin_data_dir.'/'.$file;
						$file_parts = pathinfo($file);
						if ($file_parts['extension'] === 'php' && str_contains($file, '_class')) {
							require_once($filepath);
							if($verbose){
								echo 'Requiring '.$filepath.'<br>'."\n";
							}
							$fileContent = file_get_contents($filepath);
							$tokens = token_get_all($fileContent);

							for ($i = 0; $i < count($tokens); $i++) {
								if ($tokens[$i][0] === T_CLASS && $tokens[$i + 2][0] === T_STRING) {
									$thisclass = $tokens[$i + 2][1];;
									if(isset($thisclass::$tablename) && isset($thisclass::$field_specifications)){
										$classes[] = $thisclass;
										$db_structure_contents .= serialize($thisclass::$field_specifications);
										if($verbose){
											echo 'Loading plugin class '.$thisclass.'<br>';
										}
									}
								}
							}
						
						}

					}
					closedir($handle);
				}
			}
			else{
				if($verbose){
					echo 'Requiring '.$filepath.'<br>';
				}
			}
		}


		if($verbose){
			echo 'Finished loading classes<br>';
		}

		$db_structure_hash = md5($db_structure_contents);
		$sql_commands = '';
		$sql_output = '';
		echo 'DB Hash: '. $db_structure_hash."<br>\n";






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
				$sql_commands .= $sql;

				try{
					$q = $dblink->prepare($sql);
					$q->execute();
				}
				catch(PDOException $e){
					$sql_error = $e->getMessage();
					echo $sql_error;
					$sql_output .= $sql_error;
				}				
					
				$sql = '
					CREATE TABLE "public"."'.$table_name.'" (';
					
				foreach($class::$field_specifications as $field_name=>$field_specs){
					
					$sql .= ' "'.$field_name.'" '.$field_specs['type'];
						
					if(isset($field_specs['is_nullable']) && !$field_specs['is_nullable']){
						$sql .= ' NOT NULL ';
					}

					if(isset($field_specs['serial']) && $field_specs['serial']){
						$sql .= 'DEFAULT nextval(\''.$sequence_name.'\'::regclass)';
					}
					$sql .= ', ';
				}
				//REMOVE LAST COMMA
				$sql = substr($sql, 0, -2);
				$sql .= ');';
				
				echo $sql."<br>\n";
				$sql_commands .= $sql;
				
				try{
					$q = $dblink->prepare($sql);
					$q->execute();
				}
				catch(PDOException $e){
					$sql_error = $e->getMessage();
					echo $sql_error;
					$sql_output .= $sql_error;
				}	
				
					
				$sql = 'ALTER TABLE "public"."'.$table_name.'" ADD CONSTRAINT "'.$table_name.'_pkey" PRIMARY KEY ("'.$pkey_column.'");';
				echo $sql."<br>\n";
				$sql_commands .= $sql;
				
				try{
					$q = $dblink->prepare($sql);
					$q->execute();
				}
				catch(PDOException $e){
					$sql_error = $e->getMessage();
					echo $sql_error;
					$sql_output .= $sql_error;
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
					$sql_error = $e->getMessage();
					echo $sql_error;
					$sql_output .= $sql_error;
				}	
				
				
				$live_column_info = array();
				while ($row = $q->fetch()) {
					$live_column_info[$row->column_name]['data_type'] = $row->data_type;
					$live_column_info[$row->column_name]['character_maximum_length'] = $row->character_maximum_length;
				}			
			
		
			if($verbose){
				echo '---'.$table_name .'---<br>';
			}
			if(!isset($class::$field_specifications)){
				echo 'ERROR:  '.$table_name . ' has no field specifications.';
				return 0;
			}
			$field_specifications = $class::$field_specifications;
			
			
			//MAKE SURE ALL OF THE SEQUENCE VALUES ARE CREATED AND ON THE RIGHT COLUMNS
			foreach($class::$field_specifications as $field_name=>$field_specs){
				if(isset($field_specs['serial']) && $field_specs['serial']){
					$sequence_name = $table_name.'_'.$pkey_column.'_seq';
					
					$sql = 'SELECT COUNT(*) as schema_found
						FROM information_schema.sequences 
						WHERE sequence_name= \''.$sequence_name.'\'';

					try{
						$q = $dblink->prepare($sql);
						$q->execute();
					}
					catch(PDOException $e){
						$sql_error = $e->getMessage();
						echo $sql_error;
						$sql_output .= $sql_error;
					}	
					$row = $q->fetch();

					
					if(!$row['schema_found']){
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
							$sql_error = $e->getMessage();
							echo $sql_error;
							$sql_output .= $sql_error;
						}	
						$row = $q->fetch();
						$max_val = $row['max_val'];
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
						$sql_commands .= $sql;

						try{
							$q = $dblink->prepare($sql);
							$q->execute();
						}
						catch(PDOException $e){
							$sql_error = $e->getMessage();
							echo $sql_error;
							$sql_output .= $sql_error;
						}	

						//ADD IT TO THE COLUMN
						$sql = 'ALTER TABLE '.$table_name.' 
							ALTER COLUMN '.$field_name.' SET DEFAULT nextval(\''.$sequence_name.'\'::regclass);';
						echo $sql."<br>\n";
						$sql_commands .= $sql;

						try{
							$q = $dblink->prepare($sql);
							$q->execute();
						}
						catch(PDOException $e){
							$sql_error = $e->getMessage();
							echo $sql_error;
							$sql_output .= $sql_error;
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
						$field_length = LibraryFunctions::extract_length_from_spec($field_specifications[$field]['type']);
						$field_without_length = preg_replace('/[^a-z ]/', '', $field_specifications[$field]['type']);
						if(LibraryFunctions::translate_data_types($live_column_info[$field]['data_type']) != $field_without_length){
							echo 'NOTICE: Data types do not match on field '.$field.' (live: '. $live_column_info[$field]['data_type'] .'<->spec:'. $field_without_length .")<br>\n";
							$upgrade_field = true;
						}
						
						//CHECK THE LENGTH
						$length_phrase = '';
						if($field_length){
							$length_phrase = '('.$field_length.')';
						}
						if($live_column_info[$field]['character_maximum_length']){
							if($live_column_info[$field]['character_maximum_length'] != $field_length){
								echo 'NOTICE: Max character length does not match on field '.$field.' (live: '. $live_column_info[$field]['character_maximum_length'] .'<->spec: '. $field_length .")<br>\n";	
								$upgrade_field = true;								
							}
						}
						
						if($upgrade && $upgrade_field){
							//IF COLUMN LENGTH OR TYPE DOESN'T MATCH, UPGRADE IT

							$sql = 'ALTER TABLE '.$table_name.'
								ALTER COLUMN '.$field.' TYPE '.$field_specifications[$field]['type'];
								
							$sql .= ';';
							echo $sql."<br>\n";
							
							try{
								$q = $dblink->prepare($sql);
								$q->execute();
							}
							catch(PDOException $e){
								//DO NOT HALT THE PROGRAM, JUST NOTE IT
								echo 'ERROR: Could not alter column '.$field.' ('.$sql.')'. "<br>\n";
								$sql_error = $e->getMessage();
								echo $sql_error;
								$sql_output .= $sql_error;
							}								
							
						}
					}


				}
				else{
					if($verbose){
						echo $field.' needs to be added to live db<br>';
					}

					$sql = 'ALTER TABLE '.$table_name.'
						ADD COLUMN '.$field.' '.$field_specifications[$field]['type'];
						
					if(isset($field_specifications[$field]['is_nullable']) && !$field_specifications[$field]['is_nullable']){
						$sql .= ' NOT NULL ';
					}
						
					$sql .= ';';
					echo $sql."<br>\n";
					
					try{
						$q = $dblink->prepare($sql);
						$q->execute();
					}
					catch(PDOException $e){
						$sql_error = $e->getMessage();
						echo $sql_error;
						$sql_output .= $sql_error;
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
								$sql_error = $e->getMessage();
								echo $sql_error;
								$sql_output .= $sql_error;
							}							
						}
					}
				}
			}
		}



		//STORE THE DB CHANGE LOG 
		
		$migration_log = new Migration(NULL);
		//$migration_log->set('mig_version', $migration['database_version']);
		$migration_log->set('mig_db_hash', $db_structure_hash);
		$migration_log->set('mig_sql', $sql_commands);
		$migration_log->set('mig_output', $sql_output);
		if($sql_output == ''){
			$migration_log->set('mig_success', 1);
		}
		else{
			$migration_log->set('mig_success', 0);
		}
		$migration_log->prepare();
		$migration_log->save();




		
		echo "-----MIGRATIONS-----<br>\n";

		
		 
		//REQUIRE ALL OF THE PLUGIN MIGRATION SCRIPTS
		$plugin_dir = realpath(__DIR__ . '/../plugins');
		$plugins = LibraryFunctions::list_plugins($plugin_dir);
		foreach($plugins as $plugin){
			$product_script_file = $plugin_dir. '/'.$plugin.'/migrations/migrations.php';
			if(file_exists($product_script_file)){
				require_once($product_script_file);
			}
		}
		
		//GET THE LAST MIGRATION STOPPING POINT
		/*
		$sql = "SELECT * FROM stg_settings WHERE stg_name='db_migration_version'";
		$q = $dblink->prepare($sql);
		$q->execute();
		$row = $q->fetch();
		$starting_db_migration_version = $row['stg_value'];

		if($verbose){
			echo 'Starting database migration version: '.$starting_db_migration_version. "<br>\n";
		}
		*/
		
		/*
		$sql = "SELECT * FROM stg_settings WHERE stg_name='database_version'";
		$q = $dblink->prepare($sql);
		$q->execute();
		$row = $q->fetch();		
		$starting_database_version = $row['stg_value'];	
				
		
		echo 'Starting database version: '.$starting_database_version. "<br>\n";
		*/
		
		/*
		$next_row = 0;
		if($row['stg_value']){
			$next_row = $row['stg_value'];
			if($verbose){
				echo 'Starting Migration  at ' . $next_row . "<br>\n";
			}
		}
		*/
		
		$num_migrations_run = 0;
		$num_migrations_skipped = 0;
		foreach($migrations as $key=>$migration){
			
			//DO NOT RUN OLD DATABASE UPDATES
			/*
			if($migration['database_version'] < $starting_database_version){
				if($verbose){
					echo 'Skipping: '.$migration['test']. "<br>\n";
				}				
				continue;
			}
			*/
			
			/* WE ARE NOT GOING TO USE ORDERED MIGRATIONS ANYMORE.  
			if($key <= $starting_db_migration_version){
				continue;
			}
			*/
			if($verbose){
				echo 'Checking Migration ' . $key . "<br>\n";
				
			}
			
			$run = true;
			if($migration['test']){
				if($verbose){
					echo 'Test: ('.$key.') '.$migration['test']. "<br>\n";
				}
				
				try{
					$q = $dblink->prepare($migration['test']);
					$q->execute();
					$row = $q->fetch();
				}
				catch(PDOException $e){
					echo $e->getMessage();
					echo 'ABORTING MIGRATIONS at Migration '. $key ."<br>\n";
					return 0;					
				}	

				if($row['count'] == 0){
					$run = true;
				}
				else{
					$run = false;
					$num_migrations_skipped++;
				}
			}
			

			
			if($run && $migration['migration_sql']){
				$migration_log = new Migration(NULL);
				//$migration_log->set('mig_version', $migration['database_version']);
				$migration_log->set('mig_sql', $migration['migration_sql']);
				$migration_hash = md5($migration['migration_sql']);
				$migration_log->set('mig_hash', $migration_hash);
				$migration_log->set('mig_db_hash', $db_structure_hash);
				$migration_log->prepare();

				$search_criteria = array('hash' => $migration_hash, 'successful' => true);
				$past_migrations = new MultiMigration(
					$search_criteria,
					array($sort=>$sdirection),
					$numperpage,
					$offset);
				$numrecords = $past_migrations->count_all();				
				//IF WE GET SOMETHING BACK, THE MIGRATION HAS ALREADY BEEN run
				if($numrecords){
					$num_migrations_skipped++;
					if($verbose){
						echo 'Skipping migration.  Already run: '.$migration_hash. "<br>\n";
					}
				}
				else{
					try{
						$q = $dblink->prepare($migration['migration_sql']);
						$q->execute();
						$num_migrations_run++;
						echo 'Run: '.$migration['migration_sql']. "<br>\n";
					}
					catch(PDOException $e){
						echo $e->getMessage();
						$migration_log->set('mig_output', $e->getMessage());
						$migration_log->save();
						echo 'ABORTING MIGRATIONS at Migration '. $key ."<br>\n";
						return 0;
					}		
					
					$migration_log->set('mig_success', 1);
					$migration_log->save();
				}
			}
			else if($run && $migration['migration_file']){
				//MIGRATION FUNCTION NAMES ARE THE SAME AS THE FILE NAME, MINUS THE .PHP, UNIQUE IS REQUIRED
				require_once( __DIR__ . '/../migrations/'. $migration['migration_file']);
				$migration_log = new Migration(NULL);
				//$migration_log->set('mig_version', $migration['database_version']);
				$migration_log->set('mig_file', $migration['migration_file']);
				$migration_hash = md5_file(__DIR__ . '/../migrations/'. $migration['migration_file']);
				$migration_log->set('mig_hash', $migration_hash);
				$migration_log->set('mig_db_hash', $db_structure_hash);
				$migration_log->prepare();

				$search_criteria = array('hash' => $migration_hash, 'successful' => true);
				$past_migrations = new MultiMigration(
					$search_criteria,
					array($sort=>$sdirection),
					$numperpage,
					$offset);
				$numrecords = $past_migrations->count_all();				
				
				// DEBUG: Show what records were found
				if($verbose && $numrecords > 0){
					echo "DEBUG: Found $numrecords migration record(s) with hash '$migration_hash':<br>\n";
					$past_migrations->load();
					foreach($past_migrations as $found_migration) {
						echo "  - ID: " . $found_migration->get('mig_migration_id') . 
							 ", File: " . ($found_migration->get('mig_file') ?: 'NULL') .
							 ", Hash: " . ($found_migration->get('mig_hash') ?: 'NULL') .
							 ", Success: " . ($found_migration->get('mig_success') ? 'true' : 'false') . "<br>\n";
					}
				}
				
				//IF WE GET SOMETHING BACK, THE MIGRATION HAS ALREADY BEEN run
				if($numrecords){
					$num_migrations_skipped++;
					if($verbose){
						echo 'Skipping migration. Already run: '.$migration_hash. "<br>\n";
					}
				}
				else{
						
					$function_name = pathinfo($migration['migration_file']);
					$expected_function = $function_name['filename'];
					
					if(!function_exists($expected_function)){
						$migration_log->set('mig_success', 0);
						$migration_log->set('mig_output', 'MIGRATION ERROR: Function "' . $expected_function . '()" not found in file "' . $migration['migration_file'] . '". Migration files must define a function with the same name as the filename (without .php extension).');
						$migration_log->save();
						echo 'ABORTING MIGRATIONS at Migration '. $key .": Function \"" . $expected_function . "()\" does not exist in file \"" . $migration['migration_file'] . "\".<br>\n";
						echo 'Migration files must define a function with the same name as the filename (without .php extension).<br>\n';
						echo 'Expected function: function ' . $expected_function . '() { ... }<br>\n';
						throw new Exception('Migration validation failed: Missing required function "' . $expected_function . '()" in migration file "' . $migration['migration_file'] . '"');
					}
					$result = call_user_func($function_name['filename']);
					if(!$result){
						$migration_log->set('mig_success', 0);
						$migration_log->set('mig_output', 'MIGRATION ERROR: Function "' . $expected_function . '()" returned false, indicating migration failure.');
						$migration_log->save();
						echo 'ABORTING MIGRATIONS at Migration '. $key .": Function \"" . $expected_function . "()\" returned false (migration failed).<br>\n";
						echo 'Check the migration function for errors and ensure it returns true on success.<br>\n';
						throw new Exception('Migration execution failed: Function "' . $expected_function . '()" returned false');
					}
					$num_migrations_run++;
					$migration_log->set('mig_success', 1);
					$migration_log->save();
				}
			}
			else{
				if($verbose){
					echo 'Skipping migration: '.$key. "<br>\n";
				}
			}

			//UPDATE THE DATABASE VERSION
			/*
			$sql = "UPDATE stg_settings set stg_value='".$migrations[$key]['database_version']."' WHERE stg_name='database_version'";
			try{
				$q = $dblink->prepare($sql);
				$q->execute();
				if($verbose){
					echo 'Database version now '.$migrations[$key]['database_version']."<br>\n";
				}
				
			}
			catch(PDOException $e){
				echo $e->getMessage();
				echo 'ABORTING MIGRATIONS.  Failed to set system version: '. $migrations[$key]['database_version'] ."<br>\n";
				return 0;
			}	
			*/
			
			$next_row = $key+1;


			//UPDATE THE DB VERSION
			/*
			$sql = "UPDATE stg_settings set stg_value=".$migrations[$key]['database_version']." WHERE stg_name='database_version'";
			try{
				$q = $dblink->prepare($sql);
				$q->execute();
				if($verbose){
					echo 'Updating db version to : '.$migrations[$key]['database_version']. "<br>\n";
				}
			}
			catch(PDOException $e){
				echo $e->getMessage();
				echo 'ABORTING MIGRATIONS.  Failed to set db version: '. $migrations[$key]['database_version'] ."<br>\n";
				return 0;
			}
			*/
			
			//UPDATE THE LAST DB MIGRATION POINT
			/*
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
				return 0;
			}	
			*/
	
		}

		/*
		$sql = "SELECT * FROM stg_settings WHERE stg_name='database_version'";
		$q = $dblink->prepare($sql);
		$q->execute();
		$row = $q->fetch();		
		echo 'Database migration complete.<br>  #Run: '.$num_migrations_run.',<br> #Skipped: '.$num_migrations_skipped.'<br>Database version: '.$row['stg_value']. "<br>\n";
		*/
		echo 'Database migration complete.<br>  #Run: '.$num_migrations_run.',<br> #Skipped: '.$num_migrations_skipped."<br>\n";			
		return true;
	}
	

	
	if(!isset($noautorun)){
		if(update_database($migrations, $verbose, $upgrade, $cleanup)){
			echo 'Database update script successful'. "<br>\n";
			exit(1);;  //RETURN 1 FOR THE DEPLOY SCRIPT
		}
		else{
			echo 'Database update script failed'. "<br>\n";
			exit(0);;
		}
	}



?>

