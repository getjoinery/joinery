<?php
/**
 * EmailListSubscriptions — putting a person on mailing lists and taking them
 * off. Every path that changes someone's lists comes through here.
 *
 * Double opt-in (spec email_lists_plugin §10, on by default): nobody is
 * subscribed until the address's owner confirms. request() decides, for each
 * way in:
 *
 *  - confirmed at once for the signed-in owner of a verified address asking
 *    for themselves (verified_owner), for the list's provider (provider, from
 *    MailChimp sync), for an admin marking a pending person confirmed (admin),
 *    and for everyone while double opt-in is off (single_opt_in);
 *  - otherwise pending (MailingListPending), and one confirmation email names
 *    every list in the request. A registration's request sends no email:
 *    verifying the new account confirms it (on_email_verified()).
 *
 * The confirmation email is limited: one per address per day, a number per
 * requester (an IPv6 /64) per day, and a number per site per day. Past a
 * limit the request is kept without its email (the list page shows it, with
 * a Resend; asking again mails it), and the held count goes up.
 *
 * @version 2.0 - double opt-in: request(), confirm(), resend(); set_lists() asks through request()
 * @version 1.0 - set_lists() from User::add_user_to_mailing_lists()
 */
class EmailListSubscriptions {

	// Where a request came from.
	const FROM_MEMBER       = 'member';        // the person, on their own preferences or a list page
	const FROM_FORM         = 'form';          // a signup form, from a visitor's typed address
	const FROM_REGISTER     = 'register';      // the registration form's box
	const FROM_ADMIN        = 'admin_request'; // an admin, on someone's behalf
	const FROM_CHECKOUT     = 'checkout';
	const FROM_WAITING_LIST = 'waiting_list';
	const FROM_PROVIDER     = 'provider';      // the list's provider (MailChimp sync)

	const SETTING_DOUBLE_OPT_IN = 'email_lists_double_opt_in';
	const SETTING_LIMIT_IP      = 'email_lists_confirm_limit_per_ip';
	const SETTING_LIMIT_SITE    = 'email_lists_confirm_limit_per_site';
	const SETTING_HELD          = 'email_lists_confirmations_held';

	/** The account.email_verified methods that are the person's own proof. */
	const PERSON_PROOFS = array('activation', 'password_reset', 'email_change', 'mailing_list_confirm');

	/** Hours between confirmation emails to one address. */
	const ADDRESS_LIMIT_HOURS = 24;

	public static function double_opt_in(): bool {
		return (string)Globalvars::get_instance()->get_setting(self::SETTING_DOUBLE_OPT_IN, true, true) !== '0';
	}

	/**
	 * Ask for $list_ids for a person: an account ($user), or an address typed
	 * by a visitor ($email with no account yet).
	 *
	 * @param array $opts first_name, last_name (a visitor's), by_user_id (an admin's), ip, visitor_id
	 * @return array{confirmed:int[], pending:int[], already:int[], sent:bool, held:bool}
	 */
	public static function request(?User $user, string $email, array $list_ids, string $source, array $opts = array()): array {
		$out = array('confirmed' => array(), 'pending' => array(), 'already' => array(), 'sent' => false, 'held' => false);
		$email = strtolower(trim($email !== '' ? $email : ($user ? (string)$user->get('usr_email') : '')));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return $out;
		}
		if (!$user) {
			$found = User::GetByEmail($email);
			$user = ($found && !$found->get('usr_delete_time')) ? $found : null;
		}

		$lists = array();
		foreach (array_unique(array_map('intval', $list_ids)) as $id) {
			$list = new MailingList($id, TRUE);
			if (!$list->key || $list->get('mlt_delete_time') || !$list->get('mlt_is_active')) {
				continue;
			}
			if ($user && $list->is_consented($user->key) && strtolower((string)$user->get('usr_email')) === $email) {
				$out['already'][] = (int)$list->key;
				continue;
			}
			$lists[] = $list;
		}
		if (!$lists) {
			return $out;
		}

		$consent = self::immediate_consent($user, $email, $source, $opts);
		if ($consent !== null) {
			if (!$user) {
				$user = self::account_for($email, $opts);
			}
			if (!$user) {
				return $out;
			}
			foreach ($lists as $list) {
				SystemBase::rule_checked_write(function () use ($list, $user, $consent) {
					return $list->add_registrant($user->key, $consent);
				});
				$out['confirmed'][] = (int)$list->key;
				if ($consent['source'] !== MailingListRegistrant::SOURCE_PROVIDER) {
					self::record_signup((int)$list->key, null);
				}
			}
			return $out;
		}

		// Pending. A list this address waits on with a link already mailed is
		// not asked again, and a later request never adds a list to a link
		// already sent. One that waits with no email yet (registration's, or
		// one the limits held) goes out with this request's email.
		$mailed = self::pending_list_ids($email, true);
		$unmailed = array();
		foreach (new MultiMailingListPending(array('mlp_email' => $email, 'live' => true)) as $row) {
			if (!$row->get('mlp_last_sent_time')) {
				$unmailed[(int)$row->get('mlp_mlt_mailing_list_id')] = $row;
			}
		}
		$new = array();
		$carry = array();
		foreach ($lists as $list) {
			$id = (int)$list->key;
			$out['pending'][] = $id;
			if (in_array($id, $mailed, true)) {
				continue;
			}
			if (isset($unmailed[$id])) {
				$carry[] = $unmailed[$id];
			} else {
				$new[] = $list;
			}
		}
		$send = $source !== self::FROM_REGISTER;
		if (!$new && (!$carry || !$send)) {
			return $out;
		}

		$ip = (string)($opts['ip'] ?? self::client_ip());
		$token = bin2hex(random_bytes(32));
		$visitor_id = (string)($opts['visitor_id'] ?? (session_id() !== '' ? SessionControl::get_instance()->get_uniqid() : ''));
		SystemBase::rule_checked_write(function () use ($new, $carry, $email, $user, $source, $token, $ip, $visitor_id, $send) {
			foreach ($new as $list) {
				$row = new MailingListPending(NULL);
				$row->set('mlp_email', $email);
				$row->set('mlp_usr_user_id', $user ? $user->key : null);
				$row->set('mlp_mlt_mailing_list_id', $list->key);
				$row->set('mlp_token_hash', hash('sha256', $token));
				$row->set('mlp_source', $source);
				$row->set('mlp_ip', filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null);
				$row->set('mlp_visitor_id', $visitor_id !== '' ? mb_substr($visitor_id, 0, 64) : null);
				$row->save();
			}
			if ($send) {
				foreach ($carry as $row) {
					$row->set('mlp_token_hash', hash('sha256', $token));
					$row->save();
				}
			}
		});
		if (!$send) {
			return $out;
		}

		// Past a limit the request is kept, unmailed: the list page shows it,
		// with a Resend, and the next request for it mails it.
		if (!self::within_limits($email, $ip)) {
			$out['held'] = true;
			self::count_held();
			return $out;
		}
		$out['sent'] = self::mail_token($email, $token, null);
		return $out;
	}

	/**
	 * Mail the link for the rows a token names, and stamp them sent only once
	 * the provider took it: a refused send does not lock the address for a
	 * day. $days_left, when given, is what the email says about the link's life.
	 */
	private static function mail_token(string $email, string $token, ?int $days_left): bool {
		$lists = array();
		foreach (MailingListPending::for_token($token) as $row) {
			$lists[] = new MailingList($row->get('mlp_mlt_mailing_list_id'), TRUE);
		}
		if (!$lists || !self::send_confirmation($email, $lists, $token, $days_left)) {
			return false;
		}
		SystemBase::rule_checked_write(function () use ($token) {
			DbConnector::get_instance()->get_db_link()->prepare('UPDATE mlp_mailing_list_pending SET mlp_last_sent_time = now() WHERE mlp_token_hash = ?')
				->execute(array(hash('sha256', $token)));
		});
		return true;
	}

	/** The requester's address, as the platform reads it for a decision. */
	private static function client_ip(): string {
		return (string)SessionControl::get_client_ip(true);
	}

	/** The consent that confirms a request at once, or null when it waits. */
	private static function immediate_consent(?User $user, string $email, string $source, array $opts): ?array {
		$ip = (string)($opts['ip'] ?? self::client_ip());
		$base = array('ip' => $ip, 'email' => $email);
		if ($source === self::FROM_PROVIDER) {
			return array('source' => MailingListRegistrant::SOURCE_PROVIDER) + $base;
		}
		if (!self::double_opt_in()) {
			return array('source' => MailingListRegistrant::SOURCE_SINGLE_OPT_IN, 'by_user_id' => $opts['by_user_id'] ?? null) + $base;
		}
		// The signed-in owner of a verified address, asking for themselves.
		if ($user && in_array($source, array(self::FROM_MEMBER, self::FROM_FORM, self::FROM_CHECKOUT, self::FROM_WAITING_LIST), true)
			&& session_id() !== '' && (int)SessionControl::get_instance()->get_user_id() === (int)$user->key
			&& $user->get('usr_email_is_verified') && strtolower((string)$user->get('usr_email')) === $email) {
			return array('source' => MailingListRegistrant::SOURCE_VERIFIED_OWNER) + $base;
		}
		return null;
	}

	/**
	 * Confirm the request a link's token names: the Confirm button's POST.
	 * Creates the visitor's account if there is none yet (the click proves the
	 * inbox, and the inbox is that account's), subscribes, verifies the address
	 * when it is the account's, and deletes the pending rows.
	 *
	 * @return string[]|null the names of the lists confirmed, or null for a link that does not work
	 */
	public static function confirm(string $token, string $ip): ?array {
		$rows = MailingListPending::for_token($token);
		if (!$rows) {
			return null;
		}
		$email = (string)$rows[0]->get('mlp_email');
		$user = User::GetByEmail($email);
		if ($user && $user->get('usr_delete_time')) {
			return null;
		}
		$created = false;
		if (!$user) {
			// Named from the address only: nothing a visitor typed becomes the account's.
			$user = self::account_for($email, array());
			if (!$user) {
				return null;
			}
			$created = true;
		}

		$names = array();
		SystemBase::rule_checked_write(function () use ($rows, $user, $email, $ip, &$names) {
			foreach ($rows as $row) {
				$list = new MailingList($row->get('mlp_mlt_mailing_list_id'), TRUE);
				if ($list->key && !$list->get('mlt_delete_time') && $list->get('mlt_is_active') && !$list->is_consented($user->key)) {
					$list->add_registrant($user->key, array('source' => MailingListRegistrant::SOURCE_LINK, 'ip' => $ip, 'email' => $email));
					$names[] = (string)$list->get('mlt_name');
					self::record_signup((int)$list->key, $row->get('mlp_visitor_id'));
				}
				$row->permanent_delete();
			}
		});

		// The link proved this inbox, so an account made for it here is
		// verified. One that already existed is not: someone else may have
		// registered it with this address, and an email about a list is no
		// proof of who holds that account. Its own activation button is.
		if ($created && !$user->get('usr_email_is_verified')) {
			SystemBase::rule_checked_write(function () use ($user) {
				$user->email_verify_user(TRUE, TRUE, 'mailing_list_confirm');
			});
		}
		return $names;
	}

	/**
	 * A new link for every list this address waits on, sent now, within the
	 * same limits as a first request. True when it was sent.
	 */
	public static function resend(string $email): bool {
		$email = strtolower(trim($email));
		$rows = array();
		foreach (new MultiMailingListPending(array('mlp_email' => $email, 'live' => true)) as $row) {
			$rows[] = $row;
		}
		if (!$rows) {
			return false;
		}
		if (!self::within_limits($email, self::client_ip())) {
			self::count_held();
			return false;
		}
		// The link keeps its first request's date: a resend never extends it.
		$days_left = MailingListPending::LIFETIME_DAYS;
		foreach ($rows as $row) {
			$age = (int)floor((time() - strtotime((string)$row->get('mlp_request_time') . ' UTC')) / 86400);
			$days_left = min($days_left, max(1, MailingListPending::LIFETIME_DAYS - $age));
		}
		$token = bin2hex(random_bytes(32));
		SystemBase::rule_checked_write(function () use ($rows, $token) {
			foreach ($rows as $row) {
				$row->set('mlp_token_hash', hash('sha256', $token));
				$row->save();
			}
		});
		return self::mail_token($email, $token, $days_left);
	}

	/**
	 * An admin marks a waiting person confirmed, for consent collected
	 * elsewhere, recorded under the admin's id. Returns why not, or null when done.
	 */
	public static function admin_confirm(MailingListPending $row, User $admin): ?string {
		$email = (string)$row->get('mlp_email');
		$user = $row->get('mlp_usr_user_id') ? new User($row->get('mlp_usr_user_id'), TRUE) : User::GetByEmail($email);
		if ($user && $user->key && $user->get('usr_delete_time')) {
			return 'That account is closed.';
		}
		if (!$user || !$user->key) {
			$user = self::account_for($email, array());
		}
		if (!$user) {
			return 'No account could be made for ' . $email . '.';
		}
		$list = new MailingList($row->get('mlp_mlt_mailing_list_id'), TRUE);
		if (!$list->key || $list->get('mlt_delete_time') || !$list->get('mlt_is_active')) {
			return 'The list is not active.';
		}
		if (!$list->is_consented($user->key)) {
			// The admin vouches for the person: the consent is for the address
			// the account has now, so it reaches them.
			$list->add_registrant($user->key, array('source' => MailingListRegistrant::SOURCE_ADMIN,
				'by_user_id' => $admin->key, 'email' => (string)$user->get('usr_email')));
		}
		$row->permanent_delete();
		return null;
	}

	/**
	 * account.email_verified (spec email_lists_plugin G16). A proof by the
	 * person confirms their registration's requests; an admin marking an
	 * address verified proves nothing about consent and confirms nothing. A
	 * proven address change carries the account's consent to the new address.
	 */
	public static function on_email_verified(string $signal, array $payload): void {
		$user_id = (int)($payload['user_id'] ?? 0);
		$method = (string)($payload['method'] ?? '');
		$email = strtolower(trim((string)($payload['email'] ?? '')));
		// Only a proof the person made. An admin marking an address verified,
		// or any method not listed here, confirms nothing.
		if ($user_id <= 0 || $email === '' || !in_array($method, self::PERSON_PROOFS, true)) {
			return;
		}
		$user = new User($user_id, TRUE);
		if (!$user->key || strtolower((string)$user->get('usr_email')) !== $email) {
			return;
		}

		if ($method === 'email_change') {
			$q = DbConnector::get_instance()->get_db_link()->prepare('UPDATE mlr_mailing_list_registrants SET mlr_confirm_email = ?
				WHERE mlr_usr_user_id = ? AND mlr_delete_time IS NULL');
			$q->execute(array($email, $user_id));
		}

		$rows = new MultiMailingListPending(array('mlp_usr_user_id' => $user_id, 'mlp_source' => self::FROM_REGISTER, 'live' => true));
		SystemBase::rule_checked_write(function () use ($rows, $user, $email) {
			foreach ($rows as $row) {
				if ((string)$row->get('mlp_email') !== $email) {
					continue;
				}
				$list = new MailingList($row->get('mlp_mlt_mailing_list_id'), TRUE);
				if ($list->key && !$list->get('mlt_delete_time') && $list->get('mlt_is_active') && !$list->is_consented($user->key)) {
					$list->add_registrant($user->key, array('source' => MailingListRegistrant::SOURCE_ACTIVATION, 'email' => $email));
					self::record_signup((int)$list->key, $row->get('mlp_visitor_id'));
				}
				$row->permanent_delete();
			}
		});
	}

	/** List ids this address waits on (live requests). */
	public static function pending_list_ids(string $email, bool $mailed_only = false): array {
		$ids = array();
		foreach (new MultiMailingListPending(array('mlp_email' => strtolower(trim($email)), 'live' => true)) as $row) {
			if ($mailed_only && !$row->get('mlp_last_sent_time')) {
				continue;
			}
			$ids[] = (int)$row->get('mlp_mlt_mailing_list_id');
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Subscribe $user to $mailing_list_ids and unsubscribe them from the
	 * other lists within $scope (the lists a page offered; null: every list,
	 * for staff). 'all' asks for every active list. Unsubscribing is at once;
	 * asking for a list the person is not on goes through request() as
	 * $source, and a list they are on is left as it is.
	 *
	 * @return array messages for the page, each message_title, message, message_type
	 */
	public static function set_lists(User $user, $mailing_list_ids, ?array $scope = null, string $source = self::FROM_MEMBER, array $opts = array()): array {
		$want_all = ($mailing_list_ids === 'all');
		$wanted = $want_all ? array() : array_map('intval', is_array($mailing_list_ids) ? $mailing_list_ids : (empty($mailing_list_ids) ? array() : array($mailing_list_ids)));
		$scope = $scope === null ? null : array_map('intval', $scope);

		$messages = array();
		$ask = array();
		foreach (new MultiMailingList(array(), array('name' => 'ASC')) as $list) {
			$id = (int)$list->key;
			if ($scope !== null && !in_array($id, $scope, true)) {
				continue;
			}
			$joinable = $list->get('mlt_is_active') && !$list->get('mlt_delete_time');
			$on = (bool)$list->is_user_in_list($user->key);
			if ($want_all ? $joinable : in_array($id, $wanted, true)) {
				// A list the person is already on is kept as it is. One whose
				// consent lapsed with an address change is renewed only by its
				// own Resend, never as a side effect of saving other choices.
				if ($joinable && !$on) {
					$ask[$id] = (string)$list->get('mlt_name');
				}
			} else if ($on) {
				$status = $list->remove_registrant($user->key);
				$messages[] = $status
					? array('message_type' => 'success', 'message_title' => 'Success', 'message' => 'You are UNSUBSCRIBED from the following lists: ' . $list->get('mlt_name'))
					: array('message_type' => 'error', 'message_title' => 'Error', 'message' => 'There was an error removing you from the following lists: ' . $list->get('mlt_name'));
			}
		}
		if (!$ask) {
			return $messages;
		}

		$result = self::request($user, (string)$user->get('usr_email'), array_keys($ask), $source, $opts);
		$name = function (array $ids) use ($ask) {
			return implode(', ', array_map(function ($id) use ($ask) { return $ask[$id]; }, $ids));
		};
		if ($result['already']) {
			$messages[] = array('message_type' => 'warn', 'message_title' => 'Notice', 'message' => 'You are already SUBSCRIBED to the following lists: ' . $name($result['already']));
		}
		if ($result['confirmed']) {
			$messages[] = array('message_type' => 'success', 'message_title' => 'Success', 'message' => 'You are SUBSCRIBED to the following lists: ' . $name($result['confirmed']));
		}
		if ($result['pending']) {
			$messages[] = array('message_type' => 'success', 'message_title' => 'Check your email',
				'message' => ($source === self::FROM_REGISTER
					? 'Verifying your email address will confirm the following lists: '
					: 'We sent a confirmation link to ' . $user->get('usr_email') . '. Press Confirm on it to start getting: ') . $name($result['pending']));
		}
		if ($result['held']) {
			$messages[] = array('message_type' => 'warn', 'message_title' => 'Not sent yet',
				'message' => 'Too many confirmation emails went out today, so the link for ' . $user->get('usr_email') . ' could not be sent. Your request is kept: ask again tomorrow to get it.');
		}
		return $messages;
	}

	/**
	 * Subscribe $user to these lists and leave every other list as it is.
	 *
	 * @param int[]|string $mailing_list_ids list ids, or 'all'
	 */
	public static function subscribe(User $user, $mailing_list_ids, string $source = self::FROM_MEMBER, array $opts = array()): array {
		if ($mailing_list_ids === 'all') {
			$ids = array();
			foreach (new MultiMailingList(array('deleted' => false, 'active' => true)) as $list) {
				$ids[] = (int)$list->key;
			}
		} else {
			$ids = array_map('intval', is_array($mailing_list_ids) ? $mailing_list_ids : array($mailing_list_ids));
		}
		return $ids ? self::set_lists($user, $ids, $ids, $source, $opts) : array();
	}

	// ---- inside ------------------------------------------------------------

	/** Within the per-address, per-requester and per-site limits for a confirmation email? */
	private static function within_limits(string $email, string $ip): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT 1 FROM mlp_mailing_list_pending WHERE mlp_email = ? AND mlp_last_sent_time > now() - (? * interval '1 hour') LIMIT 1");
		$q->execute(array($email, self::ADDRESS_LIMIT_HOURS));
		if ($q->fetchColumn()) {
			return false;
		}
		$settings = Globalvars::get_instance();
		$per_ip = max(1, (int)($settings->get_setting(self::SETTING_LIMIT_IP, true, true) ?: 10));
		$per_site = max(1, (int)($settings->get_setting(self::SETTING_LIMIT_SITE, true, true) ?: 200));
		if (filter_var($ip, FILTER_VALIDATE_IP)) {
			// An IPv6 visitor holds a whole /64, so the allowance is the /64's.
			$is_v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
			$q = $db->prepare($is_v6
				? "SELECT count(DISTINCT mlp_token_hash) FROM mlp_mailing_list_pending WHERE family(mlp_ip) = 6 AND mlp_ip <<= network(set_masklen(?::inet, 64)) AND mlp_last_sent_time > now() - interval '1 day'"
				: "SELECT count(DISTINCT mlp_token_hash) FROM mlp_mailing_list_pending WHERE mlp_ip = ?::inet AND mlp_last_sent_time > now() - interval '1 day'");
			$q->execute(array($ip));
			if ((int)$q->fetchColumn() >= $per_ip) {
				return false;
			}
		}
		$sent_today = (int)$db->query("SELECT count(DISTINCT mlp_token_hash) FROM mlp_mailing_list_pending WHERE mlp_last_sent_time > now() - interval '1 day'")->fetchColumn();
		return $sent_today < $per_site;
	}

	/** Count a confirmation email the limits held back, by day, for the admin list page. */
	private static function count_held(): void {
		$today = gmdate('Y-m-d');
		$held = json_decode((string)Globalvars::get_instance()->get_setting(self::SETTING_HELD, true, true), true);
		$held = (is_array($held) && ($held['date'] ?? '') === $today) ? $held : array('date' => $today, 'count' => 0);
		$held['count'] = (int)$held['count'] + 1;
		SystemBase::rule_checked_write(function () use ($held) {
			Setting::put(self::SETTING_HELD, json_encode($held));
		});
		Globalvars::get_instance()->forget_setting(self::SETTING_HELD);
	}

	/** Confirmation emails the limits held back today. */
	public static function held_today(): int {
		$held = json_decode((string)Globalvars::get_instance()->get_setting(self::SETTING_HELD, true, true), true);
		return (is_array($held) && ($held['date'] ?? '') === gmdate('Y-m-d')) ? (int)$held['count'] : 0;
	}

	/**
	 * The confirmation email: greets by address and names only the lists, so
	 * it carries nothing a visitor typed. Everyday mail, allowed on included
	 * email.
	 */
	private static function send_confirmation(string $email, array $lists, string $token, ?int $days_left = null): bool {
		$days_left = $days_left ?? MailingListPending::LIFETIME_DAYS;
		$names = array();
		foreach ($lists as $list) {
			$names[] = htmlspecialchars((string)$list->get('mlt_name'), ENT_QUOTES, 'UTF-8');
		}
		$site = htmlspecialchars((string)Globalvars::get_instance()->get_setting('site_name', true, true), ENT_QUOTES, 'UTF-8');
		$link = LibraryFunctions::get_absolute_url('/email_lists/confirm?t=' . $token);
		$body = '<p>Hello ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . ',</p>'
			. '<p>Someone asked for this address to get email from ' . ($site !== '' ? $site : 'us') . ': <strong>' . implode(', ', $names) . '</strong>.</p>'
			. '<p>To start getting it, open this link and press Confirm:</p>'
			. '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
			. '<p>If you did not ask, ignore this email. Nothing is sent to you unless you confirm, and the link stops working after '
			. ($days_left === 1 ? '1 day' : $days_left . ' days') . '.</p>';
		$subject = 'Confirm your subscription' . ($site !== '' ? ' to ' . html_entity_decode($site) : '');
		try {
			$message = EmailMessage::fromTemplate('blank_template', array('subject' => $subject, 'body' => $body));
			$message->subject($subject)->to($email);
			$sender = new EmailSender();
			return (bool)$sender->send($message);
		} catch (Throwable $e) {
			error_log('[EmailListSubscriptions] confirmation email to a pending address failed: ' . $e->getMessage());
			return false;
		}
	}

	/** The account for an address, created for it when there is none. */
	private static function account_for(string $email, array $opts): ?User {
		$found = User::GetByEmail($email);
		if ($found) {
			return $found->get('usr_delete_time') ? null : $found;
		}
		$guessed = User::guessNameFromEmail($email);
		$data = array(
			'usr_first_name' => trim((string)($opts['first_name'] ?? '')) ?: $guessed['first_name'],
			'usr_last_name' => trim((string)($opts['last_name'] ?? '')) ?: $guessed['last_name'],
			'usr_email' => $email,
			'send_emails' => false,
		);
		// No one is signed in to own the new account: the confirmation link, or
		// an admin's decision, admitted it.
		return SystemBase::rule_checked_write(function () use ($data) {
			return User::CreateNew($data);
		});
	}

	/** The list-signup conversion, against the visitor who asked (spec §10 Attribution). */
	private static function record_signup(int $list_id, ?string $visitor_id): void {
		if (session_id() === '' && !$visitor_id) {
			return;
		}
		SessionControl::get_instance()->save_visitor_event(VisitorEvent::TYPE_LIST_SIGNUP, FALSE, 'mailing_list', $list_id, NULL, $visitor_id ?: NULL);
	}
}
