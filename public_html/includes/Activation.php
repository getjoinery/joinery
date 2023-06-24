<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/smtpmailer.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');

class ActivationError extends SystemClassException {}

class Activation {

	const NONE = 0;
	const PIC_UPLOAD = 1;
	const EMAIL_VERIFY = 2;
	const PHONE_VERIFY = 3;
	const EMAIL_CHANGE = 4;

	static function ActivateUser($act_code, $user_id_confirm=NULL) {
		$user_id = self::getIdFromTempCode(strtolower($act_code), Activation::EMAIL_VERIFY);
		if ($user_id) {
			$user = new User($user_id, TRUE);
		} else {
			return FALSE;
		}

		if ($user_id_confirm !== NULL && $user->key !== $user_id_confirm) {
			// If we have passed in a user id to confirm before doing the activation
			// then if it doesn't match return FALSE
			return FALSE;
		}

		// Attempt to activate a user 
		if (!$user->get('usr_email_is_verified')) {
			// The user is valid
			$user->email_verify_user(TRUE, TRUE);
		}

		return $user;
	}

	static function ChangeEmailUser($act_code) {
		$act_record = self::getTempCodeInfo(strtolower($act_code), Activation::EMAIL_CHANGE);

		if (!$act_record) {
			return FALSE;
		}

		$user_id = $act_record->act_usr_user_id;
		$new_email = $act_record->act_usr_email;
		$user = new User($user_id, TRUE);
		
		$log = new EmailChange(NULL);
		$log->set('ech_usr_user_id', $user_id);
		$log->set('ech_old_email', $user->get('usr_email'));
		$log->set('ech_new_email', $new_email);
		$log->save();

		$user->set('usr_email', $new_email);
		$user->prepare();
		$user->save();

		// Attempt to activate a user
		if (!$user->get('usr_email_is_verified')) {
			// The user is valid
			$user->email_verify_user(TRUE, TRUE);
		}

		Activation::deleteTempCode($act_code);
		return $user;
	}
	
	static function CheckForActiveCode($user_id, $purpose, $email=NULL) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT * FROM act_activation_codes
			WHERE act_deleted=FALSE AND act_usr_user_id = :user_id AND
			act_purpose = :act_purpose AND
			act_expires_time > NOW() ' .
			(($email) ? 'AND act_usr_email = :usr_email' : ''). ' ORDER BY act_expires_time DESC LIMIT 1';

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':user_id', $user_id);
			$q->bindValue(':act_purpose', $purpose);
			if ($email) {
				$q->bindValue(':usr_email', $email);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$result = $q->fetch();
		return $result;
	}

	//timeinterval is pear date formatted interval
	static function getTempCode($usr_user_id, $time_interval='30 days', $purpose=Activation::NONE, $phn_phone_number_id=NULL, $email=NULL, $length=12){
		$expires_time = new DateTime();
		$expires_time->add(DateInterval::createFromDateString($time_interval));

		while(1) {
			$act_code = trim(LibraryFunctions::str_rand($length));
		
			$sql = "SELECT COUNT(1) as count FROM act_activation_codes
				WHERE act_code = '$act_code'";
	
			$q = DbConnector::GetPreparedStatement(
				'SELECT 1 FROM act_activation_codes WHERE act_code = ?');
			$q->bindValue(1, $act_code, PDO::PARAM_STR);
			$q->execute();
			if ($q->fetch() === FALSE) {
				break;
			}
		}	
			
		$statement = DbConnector::GetPreparedStatement(
			'INSERT INTO act_activation_codes (act_usr_email, act_usr_user_id,act_code,act_expires_time, act_purpose, act_phn_phone_number_id)
			VALUES (:act_usr_email, :usr_user_id,:act_code,:act_expires_time, :act_purpose, :act_phn_phone_number_id)');
		$statement->bindParam(':act_usr_email', $email, PDO::PARAM_STR);
		$statement->bindParam(':usr_user_id', $usr_user_id, PDO::PARAM_INT);
		$statement->bindParam(':act_code', strtolower($act_code), PDO::PARAM_STR);
		$statement->bindParam(':act_expires_time', $expires_time->format(DATE_ATOM), PDO::PARAM_STR);
		$statement->bindParam(':act_purpose', $purpose, PDO::PARAM_INT);
		$statement->bindParam(':act_phn_phone_number_id', $phn_phone_number_id, PDO::PARAM_INT);
		$statement->execute();

		return $act_code;
	}

	static function deleteTempCode($act_code){
		$statement = DbConnector::GetPreparedStatement(
			"UPDATE act_activation_codes SET act_deleted = TRUE WHERE act_code = :act_code");
		$statement->bindParam(':act_code', strtolower($act_code), PDO::PARAM_STR);
		$statement->execute();
	}
	
	static function deleteTempCodePhone($act_phn_phone_number_id) {
		$statement = DbConnector::GetPreparedStatement(
			'UPDATE act_activation_codes SET act_deleted=TRUE WHERE act_phn_phone_number_id=:act_phn_phone_number_id');
		$statement->bindParam(':act_phn_phone_number_id', $act_phn_phone_number_id, PDO::PARAM_STR);
		$statement->execute();
	}	

	static function getIdFromTempCode($act_code, $act_purpose){
		$statement = DbConnector::GetPreparedStatement(
			'SELECT act_usr_user_id FROM act_activation_codes WHERE
			act_code = :act_code AND act_expires_time > NOW() AND act_purpose = :act_purpose');
			
		$statement->bindParam(':act_code', strtolower($act_code), PDO::PARAM_STR);
		$statement->bindParam(':act_purpose', $act_purpose, PDO::PARAM_INT);
		$statement->execute();
		$statement->setFetchMode(PDO::FETCH_OBJ);
		$result = $statement->fetch();

		if ($result !== FALSE){
			return $result->act_usr_user_id;
		}

		return FALSE;
	}

	static function getTempCodeInfo($act_code, $act_purpose){
		$statement = DbConnector::GetPreparedStatement(
			'SELECT * FROM act_activation_codes WHERE act_code = :act_code AND act_expires_time > NOW() AND act_purpose = :act_purpose');
		$statement->bindParam(':act_code', strtolower($act_code), PDO::PARAM_STR);
		$statement->bindParam(':act_purpose', $act_purpose, PDO::PARAM_INT);
		$statement->execute();
		$statement->setFetchMode(PDO::FETCH_OBJ);
		return $statement->fetch();
	}	
	

	static function checkTempCode($code, $purpose){
		$statement = DbConnector::GetPreparedStatement('
			SELECT 1 FROM act_activation_codes WHERE act_deleted = FALSE AND act_code = :code AND act_expires_time > NOW() AND act_purpose = :act_purpose');
		$statement->bindParam(':code', strtolower($code), PDO::PARAM_STR);
		$statement->bindParam(':act_purpose', $purpose, PDO::PARAM_INT);
		$statement->execute();
		return $statement->fetch() !== FALSE;
	}

	static function phone_verify($act_code, $user_id) {
		$statement = DbConnector::GetPreparedStatement(
			'SELECT * FROM act_activation_codes
			 WHERE act_deleted = FALSE AND act_code = :act_code AND act_phn_phone_number_id IS NOT NULL');
		$statement->bindParam(':act_code', strtolower($act_code), PDO::PARAM_STR);
		$statement->execute();
		$statement->setFetchMode(PDO::FETCH_OBJ);
		$result = $statement->fetch();

		if ($result === FALSE) {
			return FALSE;
		}
		
		$phone = new PhoneNumber($result->act_phn_phone_number_id, TRUE);
		if ($phone->get('phn_usr_user_id') === $user_id) {
			$phone->set('phn_is_verified', TRUE);
			$phone->prepare();
			$phone->save();

			self::deleteTempCode(strtolower($act_code));
			self::deleteTempCodePhone($result->act_phn_phone_number_id);

			return $phone->key;
		}

		return FALSE;
	}

	// Email activation
	static function email_activate_send($user, $resend=FALSE) {
		//GENERATE SIGNUP CODE
		$act_code = self::getTempCode($user->key, '30 days', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));
		$activation_email = new EmailTemplate('activation_content', $user);
		$settings = Globalvars::get_instance();
		$activation_email->fill_template(array(
			'resend' => $resend,
			'act_code' => $act_code,
		));
		return $activation_email->send();
	}


	// Password reset
	static function email_forgotpw_send($usr_email){
		$user = User::GetByEmail(strtolower($usr_email));

		if (!$user) {
			return FALSE;
		}

		//GENERATE SIGNUP CODE
		$act_code = self::getTempCode($user->key, '30 day', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));

		$activation_email = new EmailTemplate('forgotpw_content', $user);
		$settings = Globalvars::get_instance();
		$activation_email->fill_template(array(
			'act_code' => $act_code,
			'usr_email' => $user->get('usr_email'),
			'usr_first_name' => $user->get('usr_first_name'),
			'web_dir' => $settings->get_setting('webDir'),
		));
		$activation_email->email_from = $settings->get_setting('defaultemail');
		$activation_email->email_from_name = $settings->get_setting('defaultemailname'); 
		$activation_email->add_recipient($user->get('usr_email'));
		$activation_email->send();

		return TRUE;
	}

	// Email change	
	static function email_change_send($usr_user_id, $new_email){
		$user = new User($usr_user_id, TRUE);
		$act_code = self::getTempCode($user->key, '30 days', Activation::EMAIL_CHANGE, NULL, $new_email);

		$activation_email = new EmailTemplate('email_change_content', $user);
		$settings = Globalvars::get_instance();
		$activation_email->fill_template(array(
			'act_code' => $act_code,
			'new_email' => $new_email,
			'usr_first_name' => $user->get('usr_first_name'),
			'web_dir' => $settings->get_setting('webDir'),
		));
		// Clear the addresses because we don't want to automatically send this to the user's
		// current email (as would happen since we pass in the recipient user to the email template)
		$activation_email->mailer->ClearAddresses();
		$activation_email->mailer->AddAddress($new_email);
		$activation_email->send();
	}	

	// Phone verification
	static function phone_verify_send($phn_phone_number_id){
		$phone = new PhoneNumber($phn_phone_number_id, TRUE);

		// For the phone verification only send a 6 digit code for easy of typing in!
		$gen_code = self::getTempCode($phone->get('phn_usr_user_id'), '30 day', Activation::PHONE_VERIFY, $phn_phone_number_id, NULL, 6);

		//SEND WELCOME MAIL
		$mail = new smtpmailer();

		$settings = Globalvars::get_instance();
		$mail->From = $settings->get_setting('defaultemail');
		$mail->FromName = $settings->get_setting('defaultemailname');
		$mail->AddAddress($phone->get('phn_phone_number') . '@' . $phone->get('phn_phone_carrier'));
		$mail->Subject = $settings->get_setting('site_name').' Verify Code';
		$mail->Body = "Code: $gen_code";
		$mail->Send();
	}
}

?>
