<?php
	error_reporting(E_ERROR | E_PARSE);
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	//error_reporting(E_ALL);
	
	if($_REQUEST['verbose']){
		$verbose=$_REQUEST['verbose'];
	}
	else{
		$verbose=false;
	}


	/*
	THIS WILL CHECK THE SPECS IN THE $fields and $field_specifications VARIABLES AND CREATE AND/OR UPDATE THE TABELS AS NEEDED
	
	-IT WILL ONLY ADD COLUMNS.  IT WILL NOT DELETE THEM.
	-IF THE DATA TYPES DO NOT MATCH YOU WILL GET A WARNING BUT IT WILL NOT FIX
	-IF CHARACTER LENGTH DOES NOT MATCH YOU WILL GET A WARNING BUT IT WILL NOT FIX
	*/

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

	
	//TRANSLATES INTERNAL POSTGRES TYPES TO USER TYPES
	function translate_data_types($data_type){
		if($data_type == 'smallint'){
			return 'int';
		}
		else if($data_type == 'integer'){
			return 'int';
		}
		else if($data_type == 'bigint'){
			return 'int';
		}		
		else if($data_type == 'character varying'){
			return 'varchar';
		}		
		else if($data_type == 'boolean'){
			return 'bool';
		}		
		else if($data_type == 'timestamp without time zone'){
			return 'timestamp';
		}
		else if($data_type == 'text'){
			return 'text';
		}		
		else if($data_type == 'numeric'){
			return 'numeric';
		}	
		else if($data_type == 'date'){
			return 'date';
		}
		else if($data_type == 'character'){
			return 'character';
		}
		else{
			echo 'ERROR: Unrecognized data type '.$data_type;
		}					
	}
	
	function extract_length_from_spec($data_type){
	
		preg_match_all('!\d+!', $data_type, $matches);
		return $matches[0][0];
	
	}
	
	

	function update_database(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
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
		);		
		
		$tables_and_columns = LibraryFunctions::get_tables_and_columns();
		
		foreach($classes as $class){
			$table_name = $class::$tablename;
			$pkey_column = $class::$pkey_column;
			$live_table_columns = $tables_and_columns[$table_name];
			if(!$live_table_columns){
				//THERE IS NO TABLE.  CREATE IT
				$sequence_name = $table_name.'_'.$pkey_column;
				
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
					$dbhelper->handle_query_error($e);
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
					$dbhelper->handle_query_error($e);
				}	
				
					
				$sql = 'ALTER TABLE "public"."'.$table_name.'" ADD CONSTRAINT "'.$table_name.'_pkey" PRIMARY KEY ("'.$pkey_column.'");';
				echo $sql."<br>\n";
				
				try{
					$q = $dblink->prepare($sql);
					$q->execute();
				}
				catch(PDOException $e){
					$dbhelper->handle_query_error($e);
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
					$dbhelper->handle_query_error($e);
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
			
			foreach($class::$fields as $field=>$description){
				$found=false;
				foreach($live_table_columns as $live_column){
					if($live_column == $field){
						$found=true;
					}
				}
				
				if($found){
					if($verbose){
						//CHECK THE COLUMN SPECS
						$field_length = extract_length_from_spec($field_specifications[$field][type]);
						$field_without_length = preg_replace('/[^a-z ]/', '', $field_specifications[$field][type]);
						if(translate_data_types($live_column_info[$field][data_type]) != $field_without_length){
							echo 'NOTICE: Data types do not match on field '.$field.' (live: '. $live_column_info[$field][data_type] .'<->spec:'. $field_without_length .")<br>\n";
						}
						
						//CHECK THE LENGTH
						$length_phrase = '';
						if($field_length){
							$length_phrase = '('.$field_length.')';
						}
						if($live_column_info[$field][character_maximum_length]){
							if($live_column_info[$field][character_maximum_length] != $field_length){
								echo 'NOTICE: Max character length does not match on field '.$field.' (live: '. $live_column_info[$field][character_maximum_length] .'<->spec: '. $field_length .")<br>\n";						
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
						$dbhelper->handle_query_error($e);
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
					if($verbose){
						echo $live_column.' is in live table '.$table_name.' but not in class<br>';
					}
				}
			}
		}
		
		
		return true;
	}
	
	update_database();
	echo 'Database update complete'. "<br>\n";
	return 0;


?>

