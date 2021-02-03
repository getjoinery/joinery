<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/includes/DbConnector.php');

class LoginClass {

	const LOGIN_FORM = 1;
	const LOGIN_COOKIE = 2;
	const LOGIN_LOGOUT = 3;
	const LOGIN_FACEBOOK_CONNECT = 4;

	static function StoreUserLogin($user_id, $login_type) {
		DbConnector::BeginTransaction();

		$statement = DbConnector::GetPreparedStatement("UPDATE usr_users
			SET usr_lastlogin_time = NOW()
			WHERE usr_user_id = :usr_user_id");
		$statement->bindValue(':usr_user_id', $user_id, PDO::PARAM_INT);
		$statement->execute();

		$statement = DbConnector::GetPreparedStatement(
			"INSERT INTO log_logins (log_usr_user_id, log_login_time, log_ip_address, log_login_type)
		VALUES (:usr_user_id, NOW(), :ip_address, :login_type)");
		$statement->bindValue(':usr_user_id', $user_id, PDO::PARAM_INT);
		$statement->bindValue(':ip_address', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
		$statement->bindValue(':login_type', $login_type, PDO::PARAM_INT);
		$statement->execute();


		DbConnector::Commit();
	}

	static function StoreUserLogout($user_id) {
		self::StoreUserLogin($user_id, self::LOGIN_LOGOUT);
	}
	
	static function InitDB($mode='structure'){
			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."log_logins" (
			  "log_usr_user_id" int4 NOT NULL,
			  "log_login_time" timestamp(6) NOT NULL DEFAULT now(),
			  "log_ip_address" inet,
			  "log_login_type" int2
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."log_logins" ADD CONSTRAINT "log_logins_pkey" PRIMARY KEY ("log_usr_user_id", "log_login_time");';
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
