<?php
/**
 * MailingListsUserPanel — the email_lists panel on the admin user page
 * (AdminUserPanelRegistry, spec email_lists_plugin G2 and G3).
 *
 * Shows which lists the member is on, with the boxes to change them (one POST
 * action, update_mailing_lists), and the campaigns they were sent. Core's
 * user page lists only direct messages.
 *
 * @version 1.0 - from the Mailing List Subscriptions card and the received and
 *   sent email cards of adm/admin_user.php, and the list boxes of
 *   adm/admin_users_edit.php
 */
class MailingListsUserPanel implements AdminUserPanel {

	public function id(): string {
		return 'email_lists';
	}

	public function actions(): array {
		return array('update_mailing_lists');
	}

	public function handle(string $action, User $user, array $input): LogicResult {
		$session = SessionControl::get_instance();
		Access::demand('personal:write', $session);
		$offered = array();
		foreach (new MultiMailingList(array('deleted' => false, 'active' => true)) as $list) {
			$offered[] = (int)$list->key;
		}
		$chosen = $input['new_list_subscribes'] ?? array();
		// An admin ticking a list asks the member, who confirms by email; a
		// waiting request can be marked confirmed below for consent given elsewhere.
		$messages = EmailListSubscriptions::set_lists($user, is_array($chosen) ? $chosen : array(), $offered,
			EmailListSubscriptions::FROM_ADMIN, array('by_user_id' => $session->get_user_id()));
		if ($messages) {
			$session->save_message(new DisplayMessage(implode(' ', array_column($messages, 'message')), 'Mailing lists',
				'/\/admin\/admin_user/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
	}

	public function render(User $user, AdminPage $page, array $context = array()): string {
		$session = SessionControl::get_instance();
		$list_limit = array_key_exists('list_limit', $context) ? $context['list_limit'] : 10;

		$options = array();
		foreach (new MultiMailingList(array('deleted' => false, 'active' => true), array('name' => 'ASC')) as $list) {
			$options[$list->key] = $list->get('mlt_name');
		}
		$checked = array();
		foreach (new MultiMailingListRegistrant(array('deleted' => false, 'user_id' => $user->key)) as $registrant) {
			$checked[] = $registrant->get('mlr_mlt_mailing_list_id');
		}

		ob_start();
		echo '<div class="card mt-3"><div class="card-header bg-body-tertiary"><h6 class="mb-0"><span class="fas fa-envelope-open me-2"></span>Mailing Lists</h6></div><div class="card-body">';
		if (!$options) {
			echo '<p class="mb-0 text-muted">There are no active mailing lists.</p>';
		} elseif (Access::allows('personal:write', (int)$session->get_permission())) {
			$formwriter = $page->getFormWriter('email_lists_panel', array('deferred_output' => true, 'action' => '/admin/admin_user'));
			$formwriter->begin_form();
			$formwriter->hiddeninput('action', '', array('value' => 'update_mailing_lists'));
			$formwriter->hiddeninput('usr_user_id', '', array('value' => $user->key));
			$formwriter->checkboxList('new_list_subscribes', 'Subscribed to', array('options' => $options, 'checked' => $checked));
			$formwriter->submitbutton('email_lists_save', 'Save lists');
			$formwriter->end_form();
			echo $formwriter->getFieldsHTML();
		} else {
			$names = array();
			foreach ($checked as $id) {
				if (isset($options[$id])) {
					$names[] = $options[$id];
				}
			}
			echo '<p class="mb-0">' . ($names ? 'Subscribed to: <strong>' . htmlspecialchars(implode(', ', $names)) . '</strong>' : 'Not on any list.') . '</p>';
		}
		$waiting = EmailListPreferences::waiting_names($user);
		if ($waiting) {
			echo '<p class="mb-0 mt-2 text-muted">Waiting for the member to confirm by email: <strong>' . htmlspecialchars(implode(', ', $waiting))
				. '</strong>. A list\'s page can resend the link, or mark it confirmed for consent given another way.</p>';
		}
		echo '</div></div>';

		// Campaigns this member was sent.
		$received = new MultiEmailRecipient(array('user_id' => $user->key, 'sent' => TRUE), array('erc_email_recipient_id' => 'DESC'), $list_limit, 0);
		echo '<div class="card mt-3"><div class="card-header bg-body-tertiary"><h6 class="mb-0"><span class="fas fa-inbox me-2"></span>Campaigns Received</h6></div><div class="card-body p-0">';
		echo '<table class="table mb-0"><thead><tr><th>Subject</th><th class="text-center">Sent Date</th></tr></thead><tbody>';
		$any = false;
		foreach ($received as $recipient) {
			$email = new Email($recipient->get('erc_eml_email_id'), TRUE);
			// A past direct message copied into core's history is listed there.
			if (!$email->key || $email->get('eml_delete_time') || self::copied_to_direct_messages((int)$email->key)) {
				continue;
			}
			$any = true;
			echo '<tr><td><a href="/plugins/email_lists/admin/admin_email_view?eml_email_id=' . (int)$email->key . '">' . htmlspecialchars((string)$email->get('eml_subject')) . '</a></td>'
				. '<td class="text-center fs-11">' . ($recipient->get('erc_sent_time') ? htmlspecialchars(LibraryFunctions::convert_time($recipient->get('erc_sent_time'), 'UTC', $session->get_timezone(), 'M j')) : '') . '</td></tr>';
		}
		if (!$any) {
			echo '<tr><td colspan="2" class="text-muted">None.</td></tr>';
		}
		echo '</tbody></table></div></div>';
		return ob_get_clean();
	}

	/** True when a direct message in core's history was copied from this Email (spec R10). */
	public static function copied_to_direct_messages(int $email_id): bool {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM dmg_direct_messages WHERE dmg_eml_email_id = ? LIMIT 1');
		$q->execute(array($email_id));
		return (bool)$q->fetchColumn();
	}
}
