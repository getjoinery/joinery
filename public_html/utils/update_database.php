<?php
	require_once( __DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/migrations_class.php');
	PathHelper::requireOnce('migrations/migrations.php');
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

		//LOAD ALL CLASSES using centralized method
		$classes = LibraryFunctions::discover_model_classes(array(
			'require_tablename' => true,
			'require_field_specifications' => true,
			'include_plugins' => true,
			'verbose' => $verbose
		));
		
		// Build db structure hash from all field specifications
		$db_structure_contents = '';
		foreach ($classes as $class) {
			$db_structure_contents .= serialize($class::$field_specifications);
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
					character_maximum_length,
					is_nullable
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
					$live_column_info[$row->column_name]['is_nullable'] = $row->is_nullable;
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
					if($verbose || $upgrade || $cleanup){
						$upgrade_field_type = false;
						$upgrade_field_length = false;
						$upgrade_nullable = false;
						
						//CHECK THE COLUMN SPECS
						$field_length = LibraryFunctions::extract_length_from_spec($field_specifications[$field]['type']);
						$field_without_length = preg_replace('/[^a-z ]/', '', $field_specifications[$field]['type']);
						if(LibraryFunctions::translate_data_types($live_column_info[$field]['data_type']) != $field_without_length){
							echo 'NOTICE: Data types do not match on field '.$field.' (live: '. $live_column_info[$field]['data_type'] .'<->spec:'. $field_without_length .")<br>\n";
							$upgrade_field_type = true;
						}
						
						//CHECK THE LENGTH (only for character-based data types)
						$is_character_type = in_array($live_column_info[$field]['data_type'], [
							'character varying', 'varchar', 'character', 'char', 'text'
						]);
						
						if($is_character_type){
							$length_phrase = '';
							if($field_length){
								$length_phrase = '('.$field_length.')';
								
								// Check if model specifies a length but database has no length limit
								if(!$live_column_info[$field]['character_maximum_length']){
									echo 'NOTICE: Model specifies max length '.$field_length.' but database column has no length limit on field '.$field."<br>\n";
									$upgrade_field_length = true;
								}
								// Check if database has a different length limit than the model
								else if($live_column_info[$field]['character_maximum_length'] != $field_length){
									echo 'NOTICE: Max character length does not match on field '.$field.' (live: '. $live_column_info[$field]['character_maximum_length'] .'<->spec: '. $field_length .")<br>\n";	
									$upgrade_field_length = true;								
								}
							}
							// Check if database has a length limit but model doesn't specify one
							else if($live_column_info[$field]['character_maximum_length']){
								echo 'NOTICE: Database has length limit '.$live_column_info[$field]['character_maximum_length'].' but model specifies no length limit on field '.$field."<br>\n";
								$upgrade_field_length = true;
							}
						}
						
						//CHECK THE NULLABLE CONSTRAINT
						$spec_nullable = true; // Default to nullable if not specified
						if(isset($field_specifications[$field]['is_nullable'])){
							$spec_nullable = $field_specifications[$field]['is_nullable'];
						}
						
						$live_nullable = ($live_column_info[$field]['is_nullable'] == 'YES');
						
						if($spec_nullable != $live_nullable){
							if($spec_nullable){
								echo 'NOTICE: Column '.$field.' should allow NULL but currently has NOT NULL constraint (live: NOT NULL <-> spec: nullable)'."<br>\n";
							} else {
								echo 'NOTICE: Column '.$field.' should have NOT NULL constraint but currently allows NULL (live: nullable <-> spec: NOT NULL)'."<br>\n";
							}
							$upgrade_nullable = true;
						}
						
						if(($upgrade || $cleanup) && ($upgrade_field_type || $upgrade_field_length)){
							// DEBUG: Show what flags are set
							if($verbose){
								echo 'DEBUG: upgrade_field_type='.($upgrade_field_type ? 'true' : 'false').', upgrade_field_length='.($upgrade_field_length ? 'true' : 'false').', is_character_type='.($is_character_type ? 'true' : 'false')."<br>\n";
							}
							
							// Determine if this is a simple length change vs a real type conversion
							$current_type = LibraryFunctions::translate_data_types($live_column_info[$field]['data_type']);
							$target_type = $field_without_length;
							$is_simple_length_change = false;
							
							if($verbose){
								echo 'DEBUG: current_type="'.$current_type.'", target_type="'.$target_type.'"'."<br>\n";
							}
							
							// Check if this is just a varchar length change (not a real type conversion)
							if($current_type == $target_type && $is_character_type && $upgrade_field_length){
								$is_simple_length_change = true;
								echo 'NOTICE: Simple length change detected for '.$field.' - no type conversion needed'."<br>\n";
							}
							
							if($is_simple_length_change){
								//SIMPLE LENGTH CHANGE - no USING clause needed
								$sql = 'ALTER TABLE '.$table_name.'
									ALTER COLUMN '.$field.' TYPE '.$field_specifications[$field]['type'];
									
								$sql .= ';';
								echo $sql."<br>\n";
								$sql_commands .= $sql;
								
								try{
									$q = $dblink->prepare($sql);
									$q->execute();
									echo 'SUCCESS: Updated column '.$field.' length to match model specification'."<br>\n";
								}
								catch(PDOException $e){
									//DO NOT HALT THE PROGRAM, JUST NOTE IT
									echo 'ERROR: Could not alter column length for '.$field.' ('.$sql.')'. "<br>\n";
									$sql_error = $e->getMessage();
									echo $sql_error."<br>\n";
									$sql_output .= $sql_error;
									echo 'SUGGESTION: Check if existing data exceeds the new length limit'."<br>\n";
								}
							}
							else if($upgrade_field_type){
								//REAL TYPE CONVERSION - needs USING clause logic
								$needs_conversion_logic = false;
								$using_clause = '';
								
								// Check if we're converting from varchar to integer types
								if(strpos($current_type, 'varchar') !== false || strpos($current_type, 'text') !== false){
									if(in_array($target_type, ['integer', 'int4', 'int', 'bigint', 'int8', 'smallint', 'int2'])){
										$needs_conversion_logic = true;
										
										// For varchar to integer conversion, use USING clause with safe conversion
										// This handles empty strings and invalid values by converting them to NULL
										$using_clause = ' USING CASE 
											WHEN '.$field.' ~ \'^[0-9]+$\' THEN '.$field.'::integer 
											WHEN '.$field.' = \'\' OR '.$field.' IS NULL THEN NULL
											ELSE NULL 
										END';
										
										echo 'NOTICE: Converting '.$field.' from '.$current_type.' to '.$target_type.' - non-numeric values will become NULL'."<br>\n";
									}
									else if(in_array($target_type, ['numeric', 'decimal', 'float', 'real', 'double precision'])){
										$needs_conversion_logic = true;
										
										// For varchar to numeric conversion
										$using_clause = ' USING CASE 
											WHEN '.$field.' ~ \'^[0-9]*\.?[0-9]+$\' THEN '.$field.'::'.$target_type.' 
											WHEN '.$field.' = \'\' OR '.$field.' IS NULL THEN NULL
											ELSE NULL 
										END';
										
										echo 'NOTICE: Converting '.$field.' from '.$current_type.' to '.$target_type.' - non-numeric values will become NULL'."<br>\n";
									}
								}
								// Check if we're converting from integer types to varchar
								else if(in_array($current_type, ['integer', 'int4', 'int', 'bigint', 'int8', 'smallint', 'int2', 'numeric', 'decimal', 'float', 'real', 'double precision'])){
									if(strpos($target_type, 'varchar') !== false || strpos($target_type, 'text') !== false){
										$needs_conversion_logic = true;
										
										// For integer to varchar conversion, simple cast is sufficient
										// PostgreSQL handles this conversion safely
										$using_clause = ' USING '.$field.'::text';
										
										echo 'NOTICE: Converting '.$field.' from '.$current_type.' to '.$target_type.' - all values will be preserved as text'."<br>\n";
									}
								}
								
								// Validate data before conversion for critical types
								if($needs_conversion_logic && in_array($target_type, ['integer', 'int4', 'int', 'bigint', 'int8', 'smallint', 'int2'])){
									// Check for data that would be lost in conversion (varchar->int only)
									$validation_sql = 'SELECT COUNT(*) as invalid_count 
										FROM '.$table_name.' 
										WHERE '.$field.' IS NOT NULL 
										AND '.$field.' != \'\' 
										AND NOT ('.$field.' ~ \'^[0-9]+$\')';
									
									try{
										$validation_q = $dblink->prepare($validation_sql);
										$validation_q->execute();
										$validation_result = $validation_q->fetch();
										
										if($validation_result['invalid_count'] > 0){
											echo 'WARNING: Found '.$validation_result['invalid_count'].' non-integer values in '.$field.' that will become NULL during conversion'."<br>\n";
											
											// Optionally show sample invalid values
											$sample_sql = 'SELECT DISTINCT '.$field.' 
												FROM '.$table_name.' 
												WHERE '.$field.' IS NOT NULL 
												AND '.$field.' != \'\' 
												AND NOT ('.$field.' ~ \'^[0-9]+$\')
												LIMIT 5';
											$sample_q = $dblink->prepare($sample_sql);
											$sample_q->execute();
											echo 'Sample invalid values: ';
											while($sample_row = $sample_q->fetch()){
												echo '"'.$sample_row[$field].'" ';
											}
											echo "<br>\n";
										}
									}
									catch(PDOException $e){
										echo 'WARNING: Could not validate data for conversion: '.$e->getMessage()."<br>\n";
									}
								}
								else if($needs_conversion_logic && (strpos($target_type, 'varchar') !== false || strpos($target_type, 'text') !== false)){
									// Integer to varchar conversion - no validation needed, always safe
									echo 'INFO: Integer to varchar conversion is safe - all data will be preserved'."<br>\n";
								}

								$sql = 'ALTER TABLE '.$table_name.'
									ALTER COLUMN '.$field.' TYPE '.$field_specifications[$field]['type'].$using_clause;
									
								$sql .= ';';
								echo $sql."<br>\n";
								$sql_commands .= $sql;
								
								try{
									$q = $dblink->prepare($sql);
									$q->execute();
									
									if($needs_conversion_logic){
										echo 'SUCCESS: Converted column '.$field.' from '.$current_type.' to '.$target_type."<br>\n";
										
										// Additional success details based on conversion type
										if(strpos($target_type, 'varchar') !== false || strpos($target_type, 'text') !== false){
											echo 'INFO: All numeric data preserved as text values'."<br>\n";
										}
										else if(in_array($target_type, ['integer', 'int4', 'int', 'bigint', 'int8', 'smallint', 'int2'])){
											echo 'INFO: Non-numeric text values converted to NULL'."<br>\n";
										}
									}
									else{
										echo 'SUCCESS: Updated column '.$field.' type'."<br>\n";
									}
								}
								catch(PDOException $e){
									//DO NOT HALT THE PROGRAM, JUST NOTE IT
									echo 'ERROR: Could not alter column type for '.$field.' ('.$sql.')'. "<br>\n";
									$sql_error = $e->getMessage();
									echo $sql_error."<br>\n";
									$sql_output .= $sql_error;
									
									// Provide helpful suggestions for common conversion failures
									if($needs_conversion_logic){
										echo 'SUGGESTION: The conversion failed. Consider:'."<br>\n";
										
										if(in_array($target_type, ['integer', 'int4', 'int', 'bigint', 'int8', 'smallint', 'int2'])){
											echo '- Cleaning varchar data first (removing non-numeric values)'."<br>\n";
											echo '- Using a custom migration script for complex data transformation'."<br>\n";
											echo '- Manually converting problematic values before running upgrade'."<br>\n";
										}
										else if(strpos($target_type, 'varchar') !== false || strpos($target_type, 'text') !== false){
											echo '- Checking for column constraints that might prevent the conversion'."<br>\n";
											echo '- Verifying the target varchar length is sufficient'."<br>\n";
											echo '- Running the conversion manually to see detailed error messages'."<br>\n";
										}
										else{
											echo '- Using a custom migration script for this specific conversion'."<br>\n";
											echo '- Manually converting the column in stages'."<br>\n";
										}
									}
								}								
							}
						}
						
						if(($upgrade || $cleanup) && $upgrade_nullable){
							//UPDATE THE NULLABLE CONSTRAINT
							if($spec_nullable){
								// Remove NOT NULL constraint - this is always safe
								$sql = 'ALTER TABLE '.$table_name.'
									ALTER COLUMN '.$field.' DROP NOT NULL;';
								
								echo $sql."<br>\n";
								$sql_commands .= $sql;
								
								try{
									$q = $dblink->prepare($sql);
									$q->execute();
									echo 'SUCCESS: Removed NOT NULL constraint from '.$field."<br>\n";
								}
								catch(PDOException $e){
									//DO NOT HALT THE PROGRAM, JUST NOTE IT
									echo 'ERROR: Could not remove NOT NULL constraint for column '.$field.' ('.$sql.')'. "<br>\n";
									$sql_error = $e->getMessage();
									echo $sql_error."<br>\n";
									$sql_output .= $sql_error;
								}
							} else {
								// Add NOT NULL constraint - need to check for existing NULL values first
								
								// Check if there are any NULL values in the column
								$null_check_sql = 'SELECT COUNT(*) as null_count 
									FROM '.$table_name.' 
									WHERE '.$field.' IS NULL';
								
								try{
									$null_check_q = $dblink->prepare($null_check_sql);
									$null_check_q->execute();
									$null_result = $null_check_q->fetch();
									$null_count = $null_result['null_count'];
									
									if($null_count > 0){
										echo 'WARNING: Cannot add NOT NULL constraint to '.$field.' - found '.$null_count.' NULL values in column'."<br>\n";
										echo 'SUGGESTION: Choose one of these options:'."<br>\n";
										echo '- Update NULL values to a default value first, then re-run upgrade'."<br>\n";
										echo '- Change your model to allow NULL values (set is_nullable => true)'."<br>\n";
										echo '- Manually clean the data and re-run the migration'."<br>\n";
										
										// Show sample NULL rows for context
										$sample_sql = 'SELECT * FROM '.$table_name.' WHERE '.$field.' IS NULL LIMIT 3';
										try{
											$sample_q = $dblink->prepare($sample_sql);
											$sample_q->execute();
											$sample_rows = $sample_q->fetchAll();
											if($sample_rows){
												echo 'Sample rows with NULL values:<br>';
												foreach($sample_rows as $row){
													$row_info = [];
													foreach($row as $col => $val){
														if(!is_numeric($col)){ // Skip numeric indices from PDO
															$row_info[] = $col.': '.($val === null ? 'NULL' : '"'.$val.'"');
														}
													}
													echo '  - '.implode(', ', array_slice($row_info, 0, 3)).'...<br>';
												}
											}
										}
										catch(PDOException $e){
											echo 'Could not retrieve sample rows: '.$e->getMessage()."<br>\n";
										}
									} else {
										// No NULL values, safe to add NOT NULL constraint
										$sql = 'ALTER TABLE '.$table_name.'
											ALTER COLUMN '.$field.' SET NOT NULL;';
										
										echo $sql."<br>\n";
										$sql_commands .= $sql;
										
										try{
											$q = $dblink->prepare($sql);
											$q->execute();
											echo 'SUCCESS: Added NOT NULL constraint to '.$field."<br>\n";
										}
										catch(PDOException $e){
											//DO NOT HALT THE PROGRAM, JUST NOTE IT
											echo 'ERROR: Could not add NOT NULL constraint for column '.$field.' ('.$sql.')'. "<br>\n";
											$sql_error = $e->getMessage();
											echo $sql_error."<br>\n";
											$sql_output .= $sql_error;
										}
									}
								}
								catch(PDOException $e){
									echo 'ERROR: Could not check for NULL values in '.$field.': '.$e->getMessage()."<br>\n";
									$sql_output .= $e->getMessage();
								}
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