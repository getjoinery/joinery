<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/data/videos_class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/data/friend_reviews_class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/data/queued_email_class.php');

class RecurringMailerException extends SystemClassException {}

class RecurringMailerTooManyEmailsException extends RecurringMailerException {}

class RecurringMailer {

	public static function GenerateUserArray($user_id, $web_dir) {
		try {
			$user = new User($user_id, TRUE);
			$user_values = $user->export_as_array();
			$user_values['web_dir'] = $web_dir;

			// Now add in the address info!
			$default_address = new Address($user->get_default_address(), TRUE);
			$user_values['default_address'] = $default_address->export_as_array();

			$friend_reviews = new MultiFriendReview(array('reviewed' => $user->key));
			$user_values['friend_reviews'] = $friend_reviews->count_all();

			return array($user, $user_values);
		} catch (TTClassException $e) {
			return NULL;
		}
	}

	public static function GetDaysSinceLastEmail($user_id, $excluded_templates=NULL) {
		$sql = 'SELECT ers_template_name, MAX(ers_send_time) as ers_sent_time
			FROM ers_recurring_email_logs
			WHERE ers_usr_user_id = ? AND ers_send_time IS NOT NULL
			GROUP BY ers_template_name
			ORDER BY MAX(ers_send_time) DESC';

		$statement = DbConnector::GetPreparedStatement($sql);
		$statement->bindValue(1, $user_id, PDO::PARAM_INT);
		$statement->execute();
		foreach ($statement->fetchAll() as $row) {
			if ($excluded_templates !== NULL) {
				if (!in_array($row[0], $excluded_templates)) {
					return $row[1];
				}
			} else {
				return $row[1];
			}
		}

		return NULL;
	}

	public static function GetSentEmails($user_id, $template=NULL) {
		$sql = 'SELECT *
			FROM ers_recurring_email_logs
			WHERE ers_usr_user_id = ? AND ers_send_time IS NOT NULL ';
		if ($template) { 
			$sql .= 'AND ers_template_name = ?';
		} 

		$sql .= ' ORDER BY ers_send_time DESC';

		$statement = DbConnector::GetPreparedStatement($sql);
		$statement->bindValue(1, $user_id, PDO::PARAM_INT);
		if ($template) { 
			$statement->bindValue(2, $template, PDO::PARAM_STR);
		}
		$statement->execute();

		$results = array();
		foreach ($statement->fetchAll() as $row) {
			$results[] = $row;
		}
		return $results;
	}

	public static function SaveRecurringEmailLog($user_email, $user_id, $template_name, $trackers, $is_sent=FALSE) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'INSERT INTO ers_recurring_email_logs
			(ers_usr_email, ers_usr_user_id, ers_template_name, ers_tracka, ers_trackb, ers_trackc,
			 ers_trackd, ers_tracke, ers_send_time)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . ($is_sent ? 'NOW()' : 'NULL') . ')
			 RETURNING ers_recurring_email_log_id';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $user_email, PDO::PARAM_STR);
			$q->bindValue(2, $user_id, PDO::PARAM_INT);
			$q->bindValue(3, $template_name, PDO::PARAM_STR);
			$q->bindValue(4, $trackers['a'], PDO::PARAM_INT);
			$q->bindValue(5, $trackers['b'], PDO::PARAM_INT);
			$q->bindValue(6, $trackers['c'], PDO::PARAM_INT);
			$q->bindValue(7, $trackers['d'], PDO::PARAM_INT);
			$q->bindValue(8, $trackers['e'], PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
			$row = $q->fetch();
			return $row->ers_recurring_email_log_id;
		} catch (PDOException $e) {
			throw new RecurringMailerException(
				'Could not store Recurring Mail Log for user ' . $user_id . ':' . $e->getMessage());
		}
	}

	public function __construct() {
		$settings = Globalvars::get_instance();
		$this->web_dir = $settings->get_setting('webDir');
		$this->email_template_contents = array();
		$this->email_templates = array();
		//$this->_load_templates($_SERVER['DOCUMENT_ROOT'] . '/theme/emailtemplates/recurring_emails');
	}

	public function get_send_counts($range) {
		$template_send = array(); 
		$good_user = 0;

		foreach($range as $user_id) {
			$user_user_data = RecurringMailer::GenerateUserArray($user_id, $this->web_dir);

			if ($user_user_data === NULL) {
				continue;
			}
			list($user, $user_data) = $user_user_data;
			$good_user++;

			foreach($this->email_template_contents as $template_name => $template_contents) {
				$template = new TemplateSandbox($template_contents, NULL, FALSE, $user);
				$returned_values = $template->fill_template($user_data);
				if ($template->is_sendable()) {
					$template_name = isset($returned_values['template_name']) ? $returned_values['template_name'] : $template_name;

					if (!array_key_exists($template_name, $template_send)) {
						$template_send[$template_name] = 1;
					} else {
						$template_send[$template_name]++;
					}

					continue 2;
				}
			}
		}

		return array(
			$good_user,
			$template_send
		);
	}

	public function queue_emails($range, $max_emails=NULL) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$dblink->beginTransaction();

		$template_send = array();

		try {
			$template_to_pos = array_flip(array_keys($this->email_templates));
			foreach($range as $user_id) {
				foreach($this->email_templates as $template_name => $template) {
					list($user, $user_data) = RecurringMailer::GenerateUserArray($user_id, $this->web_dir);
					if ($user_data === NULL) {
						continue 2;
					}

					$template->reset($user);
					
					$returned_values = $template->fill_template($user_data);

					$trackers_to_array = array(
						'tracka' => 'a',
						'trackb' => 'b',
						'trackc' => 'c',
						'trackd' => 'd',
						'tracke' => 'e'
					);

					$trackers = array();

					foreach($trackers_to_array as $tracker_name => $array_key) {
						if (array_key_exists($tracker_name, $returned_values)) {
							$trackers[$array_key] = $returned_values[$tracker_name];
						} else {
							$trackers[$array_key] = NULL;
						}
					}

					if ($template->is_sendable()) {
						// If the email is sendable, first write the stats, then link the email to the stats
						// so we can update the stats with the sent time when the email is sent (and we free up
						// the memory when this email is deleted/archived).
						$template_name = isset($returned_values['template_name']) ? $returned_values['template_name'] : $template->template_name;
						$log_entry = self::SaveRecurringEmailLog(
							$user->get('usr_email'), $user->key, $template_name, $trackers);
						$template->save_email_as_queued($log_entry, QueuedEmail::READY_TO_SEND);

						if (!array_key_exists($template_name, $template_send)) {
							$template_send[$template_name] = 1;
						} else {
							$template_send[$template_name]++;
						}

						continue 2;
					}
				}
			}
		} catch (EmailTemplateError $e) {
			// If there is a template exception, roll the transaction back
			// before re-throwing it
			$dblink->rollBack();
			throw $e;
		}

		if ($max_emails && array_sum($template_send) > $max_emails) {
			// If we have set a max # of emails to send and we cross it,
			// dont add any of these to the queue and throw an exception
			$dblink->rollBack();
			throw new RecurringMailerTooManyEmailsException(
				'This mailing generated ' . array_sum($template_send) . ' emails, ' .
				'more than the ' . $max_emails . ' set as the maximum.  Mailing ' .
				"aborted.\n\nTemplate counts: " . print_r($template_send, TRUE));	
		}

		$dblink->commit();
		return $template_send;
	}

	private function _load_templates($templates_dir) {
		$this->email_template_contents['main_template'] = file_get_contents($templates_dir . '/' . 'main_template.html');
		$this->email_templates['main_template'] = new EmailTemplate('recurring_emails/main_template.html');
		return;
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS ers_recurring_email_logs_ers_recurring_email_log_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."ers_recurring_email_logs" (
			  "ers_recurring_email_log_id" int4 NOT NULL DEFAULT nextval(\'ers_recurring_email_logs_ers_recurring_email_log_id_seq\'::regclass),
			  "ers_usr_email" varchar(100) COLLATE "pg_catalog"."default",
			  "ers_usr_user_id" int4,
			  "ers_template_name" varchar(100) COLLATE "pg_catalog"."default",
			  "ers_timestamp" timestamp(6) DEFAULT now(),
			  "ers_send_time" timestamp(6),
			  "ers_tracka" int4,
			  "ers_trackb" int4,
			  "ers_trackc" int4,
			  "ers_trackd" int4,
			  "ers_tracke" int4
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."ers_recurring_email_logs" ADD CONSTRAINT "ers_recurring_email_logs_pkey" PRIMARY KEY ("ers_recurring_email_log_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}	
}

?>
