<?php
/**
 * WeeklyEventsDigest
 *
 * Scheduled task that queries upcoming events for the next 7 days,
 * builds an HTML email, and queues it through the existing bulk email pipeline.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('data/events_class.php'));
require_once(PathHelper::getIncludePath('data/emails_class.php'));
require_once(PathHelper::getIncludePath('data/email_recipients_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_list_registrants_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

class WeeklyEventsDigest implements ScheduledTaskInterface {

	/**
	 * Run the weekly events digest.
	 *
	 * @param array $config  Task-specific configuration (expects 'mailing_list_id')
	 * @return array  Status array with 'status' and 'message'
	 */
	public function run(array $config) {
		// 1. Read mailing_list_id from config
		$mailing_list_id = $config['mailing_list_id'] ?? null;
		if (!$mailing_list_id) {
			return array('status' => 'skipped', 'message' => 'Mailing list not configured');
		}

		// Verify mailing list exists
		if (!MailingList::check_if_exists($mailing_list_id)) {
			return array('status' => 'skipped', 'message' => 'Mailing list ID ' . $mailing_list_id . ' not found');
		}

		$mailing_list = new MailingList($mailing_list_id, true);

		// 2. Query upcoming events
		$events = new MultiEvent(
			array(
				'upcoming' => true,
				'deleted' => false,
				'status_not_cancelled' => true,
				'exclude_recurring_parents' => true,
				'visibility' => 1,
			),
			array('evt_start_time' => 'ASC'),
			20
		);
		$events->load();

		// 3. Filter to events starting within the next 7 days
		$settings = Globalvars::get_instance();
		$site_tz_string = $settings->get_setting('default_timezone');
		if (!$site_tz_string) {
			$site_tz_string = 'America/New_York';
		}
		$site_tz = new DateTimeZone($site_tz_string);
		$now = new DateTime('now', $site_tz);
		$cutoff = new DateTime('+7 days', $site_tz);

		$upcoming_events = array();
		foreach ($events as $event) {
			$start_time_raw = $event->get('evt_start_time');
			if (!$start_time_raw) {
				continue;
			}
			$event_start = new DateTime($start_time_raw);
			if ($event_start <= $cutoff) {
				$upcoming_events[] = $event;
			}
		}

		// 4. If none, return success with message
		if (empty($upcoming_events)) {
			return array('status' => 'success', 'message' => 'No upcoming events in the next 7 days');
		}

		// 5. Build HTML for each event
		$site_url = LibraryFunctions::get_absolute_url('');
		$event_blocks = array();

		foreach ($upcoming_events as $event) {
			$name = htmlspecialchars($event->get('evt_name'));
			$event_url = $event->get_url('full');

			// Format date/time in site timezone
			$date_time = $event->get_time_string($site_tz_string);

			$location = $event->get('evt_location');
			$short_desc = $event->get('evt_short_description');

			$block = '<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 4px;">';
			$block .= '<h3 style="margin: 0 0 8px 0;"><a href="' . htmlspecialchars($event_url) . '" style="color: #2563eb; text-decoration: none;">' . $name . '</a></h3>';
			$block .= '<p style="margin: 0 0 4px 0; color: #555;">' . htmlspecialchars($date_time) . '</p>';

			if ($location) {
				$block .= '<p style="margin: 0 0 4px 0; color: #555;">' . htmlspecialchars($location) . '</p>';
			}

			if ($short_desc) {
				$block .= '<p style="margin: 8px 0 0 0;">' . htmlspecialchars($short_desc) . '</p>';
			}

			$block .= '</div>';
			$event_blocks[] = $block;
		}

		// 6. Wrap in heading, intro, and "View All Events" button
		$event_count = count($upcoming_events);
		$html = '<h2 style="margin-bottom: 16px;">Upcoming Events This Week</h2>';
		$html .= '<p style="margin-bottom: 20px;">Here are ' . $event_count . ' upcoming event' . ($event_count !== 1 ? 's' : '') . ' for the next 7 days:</p>';
		$html .= implode('', $event_blocks);
		$html .= '<div style="text-align: center; margin-top: 24px;">';
		$html .= '<a href="' . htmlspecialchars($site_url) . '/events" style="display: inline-block; padding: 12px 24px; background-color: #2563eb; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">View All Events</a>';
		$html .= '</div>';

		// 7. Create Email record
		$email = new Email(null);
		$email->set('eml_subject', 'Upcoming Events This Week');
		$email->set('eml_message_html', $html);
		$email->set('eml_status', Email::EMAIL_QUEUED);
		$email->set('eml_type', Email::TYPE_MARKETING);
		$email->set('eml_mlt_mailing_list_id', $mailing_list_id);
		$email->save();

		// 8. Populate EmailRecipient records from mailing list subscribers
		$subscribers = $mailing_list->get_subscribed_users('object');
		$recipient_count = 0;

		foreach ($subscribers as $user) {
			$user_email = $user->get('usr_email');
			if (!$user_email) {
				continue;
			}

			$recipient = new EmailRecipient(null);
			$recipient->set('erc_eml_email_id', $email->key);
			$recipient->set('erc_usr_user_id', $user->key);
			$recipient->set('erc_email', $user_email);
			$recipient->set('erc_name', $user->get('usr_first_name') . ' ' . $user->get('usr_last_name'));
			$recipient->save();
			$recipient_count++;
		}

		if ($recipient_count === 0) {
			// No recipients - clean up the email record
			$email->permanent_delete();
			return array('status' => 'skipped', 'message' => 'Mailing list has no subscribers with email addresses');
		}

		// 9. Return success
		return array('status' => 'success', 'message' => 'Queued digest with ' . $event_count . ' event(s) to ' . $recipient_count . ' recipient(s)');
	}
}
