<?php
/**
 * SendPostEventSurveys
 *
 * Scheduled task that sends survey emails to registrants of events
 * with evt_survey_display = 'after_event' once the event has ended.
 * Only sends to registrants where evr_survey_completed is false.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class SendPostEventSurveys implements ScheduledTaskInterface {

    public function run(array $config) {
        require_once(PathHelper::getIncludePath('data/events_class.php'));
        require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
        require_once(PathHelper::getIncludePath('data/users_class.php'));
        require_once(PathHelper::getIncludePath('data/surveys_class.php'));
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

        $settings = Globalvars::get_instance();
        $now_utc = gmdate('Y-m-d H:i:s');
        $emails_sent = 0;
        $events_checked = 0;

        // Find events with after_event surveys
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();

        $sql = "SELECT evt_event_id, evt_name, evt_svy_survey_id, evt_end_time, evt_start_time, evt_link
                FROM evt_events
                WHERE evt_survey_display = 'after_event'
                AND evt_svy_survey_id IS NOT NULL
                AND evt_delete_time IS NULL
                AND COALESCE(evt_end_time, evt_start_time) < :now";

        $q = $dblink->prepare($sql);
        $q->execute([':now' => $now_utc]);
        $events = $q->fetchAll(PDO::FETCH_ASSOC);

        foreach ($events as $event_row) {
            $events_checked++;
            $event_id = $event_row['evt_event_id'];
            $survey_id = $event_row['evt_svy_survey_id'];

            // Find registrants who haven't completed the survey
            $registrants = new MultiEventRegistrant(
                array('event_id' => $event_id, 'deleted' => false),
                array('evr_create_time' => 'ASC')
            );
            $registrants->load();

            foreach ($registrants as $registrant) {
                if ($registrant->get('evr_survey_completed')) continue;

                $user = new User($registrant->get('evr_usr_user_id'), TRUE);
                if (!$user || !$user->get('usr_email')) continue;

                // Send survey email
                $survey_url = $settings->get_setting('site_url') . '/survey?survey_id=' . $survey_id . '&event_id=' . $event_id;
                $email_fill = array(
                    'event_name' => $event_row['evt_name'],
                    'survey_url' => $survey_url,
                    'first_name' => $user->get('usr_fname'),
                    'site_name' => $settings->get_setting('site_name'),
                    'recipient' => $user->export_as_array(),
                );

                $success = EmailSender::sendTemplate('post_event_survey', $user->get('usr_email'), $email_fill);
                if ($success) {
                    $emails_sent++;
                }
            }
        }

        if ($emails_sent === 0 && $events_checked === 0) {
            return array('status' => 'skipped', 'message' => 'No events with post-event surveys found');
        }

        return array(
            'status' => 'success',
            'message' => "Checked $events_checked event(s), sent $emails_sent survey email(s)"
        );
    }
}
