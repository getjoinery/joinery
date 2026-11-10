<?php
/**
 * EmailListPreferences — the mailing-list parts of the forms core hosts:
 * the section on the member's email preferences page (ContactPreferencesRegistry,
 * spec email_lists_plugin G6) and the "add me to the list" box on the forms
 * that create an account (SignupFieldRegistry, G5). Registered from serve.php.
 *
 * @version 1.0 - from logic/contact_preferences_logic.php and the newsletter
 *   boxes of the registration and admin add-user forms
 */
class EmailListPreferences {

	/** The preferences-page section. */
	public static function register_preferences_section(): void {
		ContactPreferencesRegistry::register('mailing_lists', array(
			'render' => function ($formwriter, User $user, array $context) {
				$lists = self::list_choices($user, !empty($context['via_link']));
				if (empty($lists['options'])) {
					return;
				}
				// Waiting on the person: a confirmation link not yet used, or a
				// subscription whose address changed without proof. Said in the
				// field's help text: this form also serves the API's form
				// definition, which only fields can reach.
				$waiting = empty($context['via_link']) ? self::waiting_names($user) : array();
				$formwriter->checkboxList('new_list_subscribes',
					!empty($context['via_link']) ? 'Uncheck a list to stop receiving it:' : 'Check the box to subscribe:', [
					'options' => $lists['options'],
					'checked' => $lists['checked'],
					'helptext' => $waiting ? 'Check your email to confirm: ' . implode(', ', $waiting) . '.' : '',
				]);
				if ($waiting) {
					$formwriter->submitbutton('email_lists_resend', 'Resend the confirmation email', array('class' => 'btn btn-ghost'));
				}
			},
			// Only the offered lists change: a private list's id posted by hand
			// subscribes no one, and a list the page does not show is left alone.
			// Through a footer link (via_link) a save only takes the person off
			// lists: the link's hash never changes and travels with every forward.
			'save' => function (User $user, array $input, array $context): array {
				if (empty($context['via_link']) && !empty($input['email_lists_resend'])) {
					return self::resend_for($user);
				}
				$lists = self::list_choices($user, !empty($context['via_link']));
				$offered = array_keys($lists['options']);
				$chosen = $input['new_list_subscribes'] ?? array();
				$chosen = is_array($chosen) ? $chosen : array();
				if (!empty($context['via_link'])) {
					$chosen = array_values(array_intersect(array_map('strval', $chosen), array_map('strval', $lists['checked'])));
				}
				return EmailListSubscriptions::set_lists($user, $chosen, $offered);
			},
		));
	}

	/**
	 * Names of the lists waiting on this member: requests not yet confirmed,
	 * and subscriptions confirmed by an address the account no longer has.
	 */
	public static function waiting_names(User $user): array {
		$names = array();
		foreach (EmailListSubscriptions::pending_list_ids((string)$user->get('usr_email')) as $id) {
			$names[$id] = (string)(new MailingList($id, TRUE))->get('mlt_name');
		}
		foreach (self::unconsented_ids($user) as $id) {
			$names[$id] = (string)(new MailingList($id, TRUE))->get('mlt_name');
		}
		return array_values(array_filter($names));
	}

	/** Lists the member is on whose consent was given by another address. */
	public static function unconsented_ids(User $user): array {
		$ids = array();
		foreach (new MultiMailingListRegistrant(array('deleted' => false, 'user_id' => $user->key)) as $registrant) {
			if (strtolower((string)$registrant->get('mlr_confirm_email')) !== strtolower((string)$user->get('usr_email'))) {
				$ids[] = (int)$registrant->get('mlr_mlt_mailing_list_id');
			}
		}
		return $ids;
	}

	/** Resend: a fresh link for waiting requests, and a request for unconfirmed address changes. */
	public static function resend_for(User $user): array {
		$email = (string)$user->get('usr_email');
		$sent = EmailListSubscriptions::resend($email);
		$unconsented = self::unconsented_ids($user);
		if ($unconsented) {
			$result = EmailListSubscriptions::request($user, $email, $unconsented, EmailListSubscriptions::FROM_MEMBER);
			$sent = $sent || $result['sent'] || $result['confirmed'];
		}
		return array($sent
			? array('message_type' => 'success', 'message_title' => 'Sent', 'message' => 'A confirmation link is on its way to ' . $email . '.')
			: array('message_type' => 'warn', 'message_title' => 'Check your email', 'message' => 'A confirmation link was sent to ' . $email . ' recently; use that one, or try again tomorrow.'));
	}

	/**
	 * The "add me to the list" box on the registration and admin add-user
	 * forms, shown only while a default list is set. The label names the list:
	 * ticking it is the consent.
	 */
	public static function register_signup_field(): void {
		SignupFieldRegistry::register('email_lists_newsletter', array(
			'render' => function ($formwriter, string $context, array $input) {
				$label = self::default_list_label();
				if ($label === null) {
					return;
				}
				$formwriter->checkboxinput('newsletter',
					$context === SignupFieldRegistry::CONTEXT_ADMIN_ADD ? 'Add to ' . $label : 'Please add me to ' . $label,
					array('value' => 'yes'));
			},
			'handle' => function (User $user, array $input, string $context) {
				$default = Globalvars::get_instance()->get_setting('default_mailing_list', true, true);
				if (empty($input['newsletter']) || !$default) {
					return;
				}
				// Registration's box waits for the account's address to be verified;
				// an admin adding someone sends them the confirmation email.
				EmailListSubscriptions::subscribe($user, $default === 'all' ? 'all' : (int)$default,
					$context === SignupFieldRegistry::CONTEXT_ADMIN_ADD ? EmailListSubscriptions::FROM_ADMIN : EmailListSubscriptions::FROM_REGISTER,
					$context === SignupFieldRegistry::CONTEXT_ADMIN_ADD ? array('by_user_id' => SessionControl::get_instance()->get_user_id()) : array());
			},
		));
	}

	/** "the Newsletter mailing list", "every mailing list", or null with no default. */
	private static function default_list_label(): ?string {
		$default = Globalvars::get_instance()->get_setting('default_mailing_list', true, true);
		if (!$default) {
			return null;
		}
		if ($default === 'all') {
			return 'every mailing list';
		}
		$list = new MailingList((int)$default, TRUE);
		if (!$list->key || $list->get('mlt_delete_time') || !$list->get('mlt_is_active')) {
			return null;
		}
		return 'the ' . $list->get('mlt_name') . ' mailing list';
	}

	/**
	 * The lists the section offers. Through a footer link, only the ones the
	 * person is on.
	 *
	 * @return array ['options' => [id => name], 'checked' => [id, ...]]
	 */
	public static function list_choices(User $user, bool $via_link): array {
		$lists = self::form_options($user);
		if ($via_link) {
			$on = array_map('strval', $lists['checked']);
			foreach (array_keys($lists['options']) as $id) {
				if (!in_array((string)$id, $on, true)) {
					unset($lists['options'][$id]);
				}
			}
		}
		return $lists;
	}

	/**
	 * The user's subscribable mailing lists and current subscriptions.
	 * Private and unlisted lists appear only when the user is already subscribed.
	 *
	 * @return array ['options' => [id => name], 'checked' => [id, ...]]
	 */
	public static function form_options(User $user): array {
		$subscribed = array();
		foreach (new MultiMailingListRegistrant(array('deleted' => false, 'user_id' => $user->key)) as $registrant) {
			$subscribed[] = $registrant->get('mlr_mlt_mailing_list_id');
		}

		$options = array();
		foreach (new MultiMailingList(array('deleted' => false, 'active' => true), array('name' => 'ASC')) as $list) {
			$hidden = in_array((int)$list->get('mlt_visibility'), array(MailingList::VISIBILITY_PRIVATE, MailingList::VISIBILITY_PUBLIC_UNLISTED), true);
			if ($hidden && !in_array($list->key, $subscribed)) {
				continue;
			}
			$label = $list->get('mlt_name');
			if ($list->get('mlt_description')) {
				$label .= ' - ' . $list->get('mlt_description');
			}
			$options[$list->key] = $label;
		}
		return array('options' => $options, 'checked' => $subscribed);
	}
}
