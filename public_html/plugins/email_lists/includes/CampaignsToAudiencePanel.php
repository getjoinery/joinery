<?php
/**
 * CampaignsToAudiencePanel — on a group's or an event's admin page, the
 * campaigns aimed at that audience (AdminEntityPanelRegistry, spec
 * email_lists_plugin G4). Core's own box on those pages lists the direct
 * messages; a past direct message copied into that history is left out here,
 * so nothing shows twice.
 *
 * One instance per audience: ('group', 'group'), ('event', 'event'),
 * ('event', 'event_waiting_list').
 *
 * @version 1.0
 */
class CampaignsToAudiencePanel implements AdminEntityPanel {

	private $provider;
	private $title;

	public function __construct(string $provider, string $title) {
		$this->provider = $provider;
		$this->title = $title;
	}

	public function id(): string {
		return 'campaigns_to_' . $this->provider;
	}

	public function actions(): array {
		return array();
	}

	public function handle(string $action, $entity, array $input): LogicResult {
		return LogicResult::error('Nothing to do.');
	}

	public function render($entity, AdminPage $page, array $context = array()): string {
		$session = SessionControl::get_instance();
		$emails = new MultiEmail(
			array('recipient_group' => array('provider' => $this->provider, 'reference_id' => (int)$entity->key), 'deleted' => false),
			array('email_id' => 'DESC'), 20, 0);

		$rows = array();
		foreach ($emails as $email) {
			if (MailingListsUserPanel::copied_to_direct_messages((int)$email->key)) {
				continue;
			}
			$sent = count(new MultiEmailRecipient(array('email_id' => $email->key, 'sent' => true)));
			$total = count(new MultiEmailRecipient(array('email_id' => $email->key)));
			$when = $email->get('eml_sent_time') ?: $email->get('eml_scheduled_time');
			$rows[] = array(
				'<a href="/plugins/email_lists/admin/admin_email?eml_email_id=' . (int)$email->key . '">' . htmlspecialchars((string)$email->get('eml_subject')) . '</a>',
				htmlspecialchars((string)$email->get_status_text()),
				$sent . ' of ' . $total . ' sent',
				$when ? htmlspecialchars(LibraryFunctions::convert_time($when, 'UTC', $session->get_timezone())) : '',
			);
		}
		if (!$rows) {
			return '';
		}

		ob_start();
		$page->tableheader(array('Subject', 'Status', 'Recipients', 'Time'), array('title' => $this->title, 'card' => true));
		foreach ($rows as $row) {
			$page->disprow($row);
		}
		$page->endtable();
		return ob_get_clean();
	}
}
