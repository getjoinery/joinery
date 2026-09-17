<?php
/**
 * Login — one sign-in, sign-out or cookie resume, as it happened.
 *
 * Every path that establishes a session records a row here (the form, a
 * passkey, a remembered cookie, the second-factor step) and every logout
 * records one of kind LOGIN_LOGOUT, so the admin user page can show a
 * person's sign-in history and the activity report can count active users
 * by day. The same call stamps usr_lastlogin_time on the user.
 *
 * A login row is a fact about the past: it is never edited, and it is
 * deleted only with the user it belongs to.
 *
 * @version 2.0 - a model on the log_logins table (was LoginClass, a hand-rolled
 *   class that created its own table): a serial log_login_id key in place of the
 *   (user, time) pair, log_ip as varchar(45) like every other IP column, and the
 *   deletion engine owning the cascade from the user
 */
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class LoginException extends SystemBaseException {}

class Login extends SystemBase {
	public static $prefix = 'log';
	public static $tablename = 'log_logins';
	public static $pkey_column = 'log_login_id';

	const LOGIN_FORM = 1;
	const LOGIN_COOKIE = 2;
	const LOGIN_LOGOUT = 3;
	const LOGIN_FACEBOOK_CONNECT = 4;

	// An audit table: staff read it, nothing edits it. Not a REST or AI resource.
	function authenticate_read($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in ' . static::$tablename);
		}
	}
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	protected static $foreign_key_actions = [
		// A person's sign-in history goes with the person.
		'log_usr_user_id' => ['action' => 'cascade'],
	];

	public static $field_specifications = array(
		'log_login_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'log_usr_user_id' => array('type'=>'int4', 'is_nullable'=>false, 'required'=>true),
		'log_login_time'  => array('type'=>'timestamp(6)', 'is_nullable'=>false, 'default'=>'now()'),
		'log_ip'          => array('type'=>'varchar(45)'),
		'log_login_type'  => array('type'=>'int2'),
	);

	public static $timestamp_fields = array('log_login_time');

	/** The admin user page reads one person's history newest first. */
	public static $index_specifications = array(
		array('columns' => array('log_usr_user_id', 'log_login_time')),
	);

	/**
	 * Record that a user signed in (or, with LOGIN_LOGOUT, out), and stamp
	 * usr_lastlogin_time. One transaction: the stamp and the row agree.
	 *
	 * A remembered cookie resumes a session on whatever GET arrives first, so
	 * the row is written on a read request; it is the server's own record of
	 * the event, not something a link asked for.
	 */
	public static function record($user_id, $login_type) {
		return SystemBase::server_initiated_write(function () use ($user_id, $login_type) {
			DbConnector::BeginTransaction();

			$statement = DbConnector::GetPreparedStatement("UPDATE usr_users
				SET usr_lastlogin_time = NOW()
				WHERE usr_user_id = :usr_user_id");
			$statement->bindValue(':usr_user_id', $user_id, PDO::PARAM_INT);
			$statement->execute();

			$login = new Login(NULL);
			$login->set('log_usr_user_id', (int)$user_id);
			$login->set('log_login_time', 'NOW()');
			$login->set('log_ip', isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null);
			$login->set('log_login_type', (int)$login_type);
			$login->save();

			DbConnector::Commit();
			return $login;
		});
	}

	public static function record_logout($user_id) {
		return self::record($user_id, self::LOGIN_LOGOUT);
	}
}

class MultiLogin extends SystemMultiBase {
	protected static $model_class = 'Login';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['user_id'])) {
			$filters['log_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['login_type'])) {
			$filters['log_login_type'] = [$this->options['login_type'], PDO::PARAM_INT];
		}
		return $this->_get_resultsv2('log_logins', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
