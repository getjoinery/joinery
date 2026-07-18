<?php

require_once('PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/Activation.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/phone_number_class.php'));

class ActivationError extends SystemBaseException {}

class Activation {

	const NONE = 0;
	const PIC_UPLOAD = 1;
	const EMAIL_VERIFY = 2;
	const PHONE_VERIFY = 3;
	const EMAIL_CHANGE = 4;
	const RECOVERY_VERIFY = 5;

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

			$q = DbConnector::GetPreparedStatement(
				'SELECT 1 FROM act_activation_codes WHERE act_code = ?');
			$q->bindValue(1, $act_code, PDO::PARAM_STR);
			$q->execute();
			if ($q->fetch() === FALSE) {
				break;
			}
		}
		$expires_time_formatted = $expires_time->format(DATE_ATOM);
		$statement = DbConnector::GetPreparedStatement(
			'INSERT INTO act_activation_codes (act_usr_email, act_usr_user_id,act_code,act_expires_time, act_purpose, act_phn_phone_number_id)
			VALUES (:act_usr_email, :usr_user_id,:act_code,:act_expires_time, :act_purpose, :act_phn_phone_number_id)');
		$statement->bindParam(':act_usr_email', $email, PDO::PARAM_STR);
		$statement->bindParam(':usr_user_id', $usr_user_id, PDO::PARAM_INT);
		$statement->bindParam(':act_code', strtolower($act_code), PDO::PARAM_STR);
		$statement->bindParam(':act_expires_time', $expires_time_formatted, PDO::PARAM_STR);
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

	// Revoke every outstanding code of a given purpose for a user — used when a
	// recovery address is changed or removed so old confirmation links die at
	// once (specs/mailbox_security_levels.md § Password reset). recovery_verify
	// also reconciles against the current candidate, so this is defense in depth.
	static function deleteUserCodes($usr_user_id, $purpose){
		$statement = DbConnector::GetPreparedStatement(
			"UPDATE act_activation_codes SET act_deleted = TRUE WHERE act_usr_user_id = :uid AND act_purpose = :purpose AND act_deleted = FALSE");
		$statement->bindValue(':uid', (int)$usr_user_id, PDO::PARAM_INT);
		$statement->bindValue(':purpose', (int)$purpose, PDO::PARAM_INT);
		$statement->execute();
	}

	static function deleteTempCodePhone($act_phn_phone_number_id) {
		$statement = DbConnector::GetPreparedStatement(
			'UPDATE act_activation_codes SET act_deleted=TRUE WHERE act_phn_phone_number_id=:act_phn_phone_number_id');
		$statement->bindParam(':act_phn_phone_number_id', $act_phn_phone_number_id, PDO::PARAM_STR);
		$statement->execute();
	}

	// act_deleted = FALSE is part of what makes a code valid, not an extra the
	// caller may remember to add. Without it here, a consumed code stayed live
	// for the rest of its lifetime through every path that resolves a code
	// without calling checkTempCode first — ActivateUser among them, which
	// login_logic reaches directly. Consuming a code has to mean something
	// everywhere it is honoured.
	static function getIdFromTempCode($act_code, $act_purpose){
		$statement = DbConnector::GetPreparedStatement(
			'SELECT act_usr_user_id FROM act_activation_codes WHERE act_deleted = FALSE AND
			act_code = :act_code AND act_expires_time > NOW() AND act_purpose = :act_purpose');

		$act_code_lower = strtolower($act_code);
		$statement->bindParam(':act_code', $act_code_lower, PDO::PARAM_STR);
		$statement->bindParam(':act_purpose', $act_purpose, PDO::PARAM_INT);
		$statement->execute();
		$statement->setFetchMode(PDO::FETCH_OBJ);
		$result = $statement->fetch();

		if ($result !== FALSE){
			return $result->act_usr_user_id;
		}

		return FALSE;
	}

	// act_deleted = FALSE for the same reason as getIdFromTempCode above.
	static function getTempCodeInfo($act_code, $act_purpose){
		$statement = DbConnector::GetPreparedStatement(
			'SELECT * FROM act_activation_codes WHERE act_deleted = FALSE AND act_code = :act_code AND act_expires_time > NOW() AND act_purpose = :act_purpose');
		$act_code_lower = strtolower($act_code);
		$statement->bindParam(':act_code', $act_code_lower, PDO::PARAM_STR);
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
		$success = EmailSender::sendTemplate('activation_content',
			$user->get('usr_email'),
			[
				'resend' => $resend,
				'act_code' => $act_code,
				'recipient' => $user->export_as_array()
			]
		);
		return $success;
	}

	// Password reset
	static function email_forgotpw_send($usr_email){
		$user = User::GetByEmail(strtolower($usr_email));

		if (!$user) {
			return FALSE;
		}

		//GENERATE SIGNUP CODE
		$act_code = self::getTempCode($user->key, '30 day', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));

		$success = EmailSender::sendTemplate('forgotpw_content',
			$user->get('usr_email'),
			[
				'act_code' => $act_code,
				'web_dir' => LibraryFunctions::get_absolute_url(''),
				'recipient' => $user->export_as_array()
			]
		);

		// External recovery-address authorizer (specs/mailbox_security_levels.md
		// § Password reset, Population 3): a verified recovery address is a
		// deliberate out-of-band reset path, so the same link is also sent there.
		// This is what carries a Population-2 user (login email is a hosted
		// mailbox they may be locked out of) — the link lands in an inbox they
		// still control. Best-effort: a failed recovery copy never blocks the
		// primary send.
		$recovery = $user->recovery_email();
		if ($recovery !== '' && strtolower($recovery) !== strtolower((string)$user->get('usr_email'))) {
			try {
				EmailSender::sendTemplate('forgotpw_content',
					$recovery,
					[
						'act_code' => $act_code,
						'web_dir' => LibraryFunctions::get_absolute_url(''),
						'recipient' => $user->export_as_array()
					]
				);
			} catch (\Throwable $e) {
				error_log('email_forgotpw_send: recovery-address copy failed for user ' . $user->key . ': ' . $e->getMessage());
			}
		}
		return $success;
	}

	// External recovery-address verification (specs/mailbox_security_levels.md
	// § Password reset). Sends a verify link to the CANDIDATE recovery address;
	// clicking it (recovery_verify_logic) stamps usr_recovery_email_verified_time.
	// Mirrors email_change_send: the code proves control of the target inbox.
	static function email_recovery_verify_send($usr_user_id, $recovery_email){
		$user = new User($usr_user_id, TRUE);
		$act_code = self::getTempCode($user->key, '2 days', Activation::RECOVERY_VERIFY, NULL, strtolower(trim($recovery_email)));

		$settings = Globalvars::get_instance();
		$site = (string)$settings->get_setting('site_name');
		$link = LibraryFunctions::get_absolute_url('recovery-verify?act_code=' . rawurlencode($act_code));
		EmailSender::quickSend(
			strtolower(trim($recovery_email)),
			trim($site . ' — confirm your recovery address'),
			"You (or someone using your " . $site . " account) added this address as a password-recovery address.\n\n"
			. "Confirm it by opening this link:\n" . $link . "\n\n"
			. "If you did not request this, you can ignore this email."
		);
	}

	// Email change
	static function email_change_send($usr_user_id, $new_email){
		$user = new User($usr_user_id, TRUE);
		$act_code = self::getTempCode($user->key, '30 days', Activation::EMAIL_CHANGE, NULL, $new_email);

		$message = EmailMessage::fromTemplate('email_change_content', [
			'act_code' => $act_code,
			'new_email' => $new_email,
			'web_dir' => LibraryFunctions::get_absolute_url(''),
			'recipient' => $user->export_as_array()
		]);
		$message->to($new_email); // Send to new email, not user's current email

		$sender = new EmailSender();
		$sender->send($message);
	}

	// Phone verification
	static function phone_verify_send($phn_phone_number_id){
		$phone = new PhoneNumber($phn_phone_number_id, TRUE);

		// For the phone verification only send a 6 digit code for easy of typing in!
		$gen_code = self::getTempCode($phone->get('phn_usr_user_id'), '30 day', Activation::PHONE_VERIFY, $phn_phone_number_id, NULL, 6);

		// Send the code through the same outbound pipeline as all other mail
		// (active provider + fallback + retry queue) — no direct mailer bypass.
		// The recipient is the carrier's email-to-SMS gateway; a plain-text body
		// keeps it as an SMS, not an HTML email.
		$settings = Globalvars::get_instance();
		$to = $phone->get('phn_phone_number') . '@' . $phone->get('phn_phone_carrier');
		EmailSender::quickSend($to, $settings->get_setting('site_name') . ' Verify Code', "Code: $gen_code");
	}
}

?>
