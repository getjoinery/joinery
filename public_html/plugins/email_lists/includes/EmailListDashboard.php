<?php
/**
 * EmailListDashboard — the member's mailing lists on their profile page and
 * the app dashboard (ProfileDashboardRegistry, spec email_lists_plugin G1).
 *
 * @version 1.0 - from the Mailing Lists card core built in profile_logic,
 *   profile_dashboard_logic and the ScrollDaddy profile
 */
class EmailListDashboard {

	public static function section(User $user): ?ProfileDashboardSection {
		if (!Globalvars::get_instance()->get_setting('mailing_lists_active', true, true)) {
			return null;
		}
		$items = array();
		foreach (new MultiMailingListRegistrant(array('deleted' => false, 'user_id' => $user->key)) as $registrant) {
			$list = new MailingList($registrant->get('mlr_mlt_mailing_list_id'), TRUE);
			if (!$list->key || $list->get('mlt_delete_time')) {
				continue;
			}
			$items[] = new ProfileDashboardItem(
				array('mailing_list_id' => (int)$list->key, 'name' => (string)$list->get('mlt_name')),
				(string)$list->get('mlt_name'));
		}
		return new ProfileDashboardSection('mailing_lists', 'Mailing Lists', '/profile/contact_preferences',
			null, $items, 'Not subscribed to any lists.');
	}
}
