<?php
/**
 * Newsletter Signup Component Logic
 *
 * Resolves mailing list(s) based on config and checks subscription status.
 * Called by ComponentRenderer at render time.
 *
 * @param array $config Component configuration
 * @return array Data for the template
 * @version 1.1.0
 */
function newsletter_signup_logic($config) {
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	$data = [
		'mailing_lists' => null,
		'list_mode' => 'default',
		'is_active' => false,
		'session' => null,
		'user_subscribed_list' => [],
		'form_action' => '/lists',
		'list_options' => [],
		'config_errors' => [],
	];

	if (!$settings->get_setting('mailing_lists_active')) {
		$data['config_errors'][] = 'The "mailing_lists_active" setting is disabled. Enable it in Admin &gt; Settings &gt; Email.';
		return $data;
	}
	$data['is_active'] = true;

	$session = SessionControl::get_instance();
	$data['session'] = $session;

	$list_mode = $config['list_mode'] ?? 'default';
	$data['list_mode'] = $list_mode;

	if ($list_mode === 'all') {
		$lists = new MultiMailingList(
			['deleted' => false, 'visibility' => MailingList::VISIBILITY_PUBLIC],
			['mlt_name' => 'ASC']
		);
		$lists->load();
		if ($lists->count() === 0) {
			$data['config_errors'][] = 'No public mailing lists found. Create a mailing list in Admin &gt; Mailing Lists and set its visibility to Public.';
		} else {
			$data['mailing_lists'] = $lists;
			$data['list_options'] = $lists->get_dropdown_array();
			$data['form_action'] = '/lists';
		}
	} else {
		$list_id = null;
		if ($list_mode === 'specific' && !empty($config['mailing_list_id'])) {
			$list_id = (int) $config['mailing_list_id'];
		} else {
			$list_id = $settings->get_setting('default_mailing_list');
		}

		if (!$list_id) {
			if ($list_mode === 'specific') {
				$data['config_errors'][] = 'List mode is "specific" but no mailing list ID was provided in the component configuration.';
			} else {
				$data['config_errors'][] = 'No default mailing list is configured. Set one in Admin &gt; Settings &gt; Email &gt; "Default Mailing List".';
			}
		} else {
			if (!MailingList::check_if_exists($list_id)) {
				$data['config_errors'][] = 'Mailing list ID ' . (int)$list_id . ' does not exist. It may have been deleted.';
			} else {
				$list = new MailingList($list_id, TRUE);
				if ($list->get('mlt_delete_time')) {
					$data['config_errors'][] = 'Mailing list "' . htmlspecialchars($list->get('mlt_name')) . '" (ID ' . (int)$list_id . ') has been deleted.';
				} elseif (!$list->get('mlt_is_active')) {
					$data['config_errors'][] = 'Mailing list "' . htmlspecialchars($list->get('mlt_name')) . '" (ID ' . (int)$list_id . ') is inactive. Activate it in Admin &gt; Mailing Lists.';
				} else {
					$data['mailing_lists'] = $list;
					$data['form_action'] = $list->get_url();
				}
			}
		}
	}

	// Check subscription status for logged-in users
	if ($session->get_user_id() && $data['mailing_lists']) {
		if ($list_mode === 'all') {
			$search_criteria = ['deleted' => false, 'user_id' => $session->get_user_id()];
			$user_lists = new MultiMailingListRegistrant($search_criteria);
			$user_lists->load();
			foreach ($user_lists as $user_list) {
				$data['user_subscribed_list'][] = $user_list->get('mlr_mlt_mailing_list_id');
			}
		} else {
			$data['member_of_list'] = $data['mailing_lists']->is_user_in_list($session->get_user_id());
		}
	}

	return $data;
}
?>
