<?php
/**
 * EventsPanel — the admin user-detail "Events" panel (event_manager-owned).
 *
 * The only admin-user panel with POST actions: add the user to an event or
 * remove them. Renders the user's event registrations (with an add-to-event
 * form) plus their event-session view history. Registered from event_manager's
 * serve.php, so it appears on /admin/admin_user only when the plugin is active.
 */

require_once(PathHelper::getIncludePath('includes/AdminUserPanelRegistry.php'));

class EventsPanel implements AdminUserPanel {

	public function id(): string {
		return 'event_registrations';
	}

	public function actions(): array {
		return array('add_to_event', 'remove_from_event');
	}

	public function handle(string $action, User $user, array $input): LogicResult {
		require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
		$event = new Event($input['evt_event_id'], TRUE);
		if ($action === 'add_to_event') {
			$event->add_registrant($user->key);
		} else {
			$event->remove_registrant($user->key);
		}
		return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
	}

	public function render(User $user, AdminPage $page, array $context = []): string {
		require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
		require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
		require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_sessions_class.php'));
		require_once(PathHelper::getIncludePath('includes/Pager.php'));

		$session = SessionControl::get_instance();

		// Page-level list state, derived once by admin_user_logic and passed in.
		$show_all = !empty($context['show_all']);
		$list_limit = array_key_exists('list_limit', $context) ? $context['list_limit'] : 10;
		$show_all_url = $context['show_all_url'] ?? null;

		$event_registrations = new MultiEventRegistrant(
			array('user_id' => $user->key),
			NULL,
			$list_limit,
			NULL);
		$numeventsregistrations = $event_registrations->count_all();
		$event_registrations->load();
		$events_pager = new Pager(array('numrecords' => $numeventsregistrations, 'numperpage' => $list_limit ?: $numeventsregistrations));

		// Session-visit rows (mirrors the old admin_user_logic derivation). Built
		// once here and reused by the render below — the registrations×sessions
		// fan-out (and its per-session last-visit lookup) runs a single time.
		$visited_session_rows = array();
		foreach ($event_registrations as $event_registration) {
			$sv_event = new Event($event_registration->get('evr_evt_event_id'), TRUE);
			$event_sessions_visit = new MultiEventSessions(
				array('event_id' => $event_registration->get('evr_evt_event_id')),
				array('evs_session_number' => 'DESC', 'evs_title' => 'DESC'));
			$event_sessions_visit->load();
			foreach ($event_sessions_visit as $event_session_visit) {
				if ($visit_time = $event_session_visit->get_last_visited_time_for_user($user->key)) {
					$session_num = $event_session_visit->get('evs_session_number') ? 'Session ' . $event_session_visit->get('evs_session_number') . ' - ' : '';
					$visited_session_rows[] = array(
						'label'      => $sv_event->get('evt_name') . ' - ' . $session_num . $event_session_visit->get('evs_title'),
						'visit_time' => $visit_time,
						'num_views'  => $event_session_visit->get_number_visits_for_user($user->key),
					);
				}
			}
		}
		$num_session_visits = count($visited_session_rows);
		$session_visits_pager = new Pager(array('numrecords' => $num_session_visits, 'numperpage' => $list_limit ?: $num_session_visits));

		ob_start();

		// ---- Events table ----
		$headers = array('Event', 'Added', 'Expires', 'Action');
		$table_options = array('title' => 'Events', 'card' => true);
		$page->tableheader($headers, $table_options, $events_pager);

		$event_ids_for_user = array();
		foreach ($event_registrations as $event_registration):
			$event = new Event($event_registration->get('evr_evt_event_id'), TRUE);
			$event_ids_for_user[] = $event->key;

			$event_cell = '<a href="/plugins/event_manager/admin/admin_event?evt_event_id=' . $event->key . '">' .
				LibraryFunctions::convert_time($event->get('evt_start_time'), "UTC", "UTC", 'M j, Y') . ' ' .
				'<strong>' . htmlspecialchars($event->getString('evt_name', 50)) . '</strong> ' .
				htmlspecialchars($event->get('evt_location')) .
				'</a>';

			$added_cell = $event_registration->get_local('evr_create_time', 'M j');
			$expires_cell = $event_registration->get_local('evr_expires_time', 'M j');

			$action_cell = AdminPage::action_button('Remove', '/admin/admin_user', array(
				'hidden'  => array('action' => 'remove_from_event', 'evt_event_id' => $event->key, 'usr_user_id' => $user->key),
				'confirm' => 'Remove user from this event?',
			));

			$page->disprow(array($event_cell, $added_cell, $expires_cell, $action_cell));
		endforeach;

		$formwriter = $page->getFormWriter('form3', array('deferred_output' => true));
		$events = new MultiEvent(
			array('deleted' => false),
			array('start_time' => 'DESC'),
			NULL,
			NULL);
		$events->load();
		foreach ($event_ids_for_user as $event_id) {
			if ($events->contains_key($event_id)) {
				$events->remove_by_key($event_id);
			}
		}
		$optionvals = $events->get_dropdown_array();
		$formwriter->hiddeninput('action', '', array('value' => 'add_to_event'));
		$formwriter->hiddeninput('usr_user_id', '', array('value' => $user->key));
		$formwriter->dropinput('evt_event_id', 'Add to event', array(
			'options' => $optionvals,
			'validation' => array('required' => true),
		));
		$formwriter->submitbutton('submit_button', 'Add');
		$add_form = $formwriter->getFieldsHTML();
		echo '<tr><td colspan="4" class="pt-3">' . $add_form . '</td></tr>';

		$page->endtable($events_pager);

		// ---- Event Session Visits card ----
		?>
		<div class="card mt-3">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0"><span class="fas fa-eye me-2"></span>Event Session Visits</h6>
			</div>
			<div class="card-body p-0">
				<div class="table-responsive">
					<table class="table mb-0">
						<thead>
							<tr>
								<th>Session</th>
								<th class="text-center">Last Viewed</th>
								<th class="text-center"># Views</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ($visited_session_rows as $visited_row):
							?>
								<tr>
									<td><?php echo htmlspecialchars($visited_row['label']); ?></td>
									<td class="text-center"><?php echo LibraryFunctions::convert_time($visited_row['visit_time'], 'UTC', $session->get_timezone()); ?></td>
									<td class="text-center"><?php echo $visited_row['num_views']; ?></td>
								</tr>
							<?php
							endforeach;
							?>
						</tbody>
					</table>
				</div>
				<?php echo $session_visits_pager->record_count_info($num_session_visits, array('show_all_url' => $show_all_url)); ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
