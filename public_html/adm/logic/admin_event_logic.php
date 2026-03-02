<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_event_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/log_form_errors_class.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('data/email_recipients_class.php'));
	require_once(PathHelper::getIncludePath('data/event_logs_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/locations_class.php'));
	require_once(PathHelper::getIncludePath('data/event_types_class.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$event = new Event($get_vars['evt_event_id'], TRUE);

	// Handle actions
	if($get_vars['action'] == 'delete'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->soft_delete();

		return LogicResult::redirect('/admin/admin_events');
	}
	else if($get_vars['action'] == 'undelete'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->undelete();

		return LogicResult::redirect('/admin/admin_events');
	}

	// Recurring event actions
	if($post_vars['action'] == 'cancel_instance'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$instance = $event->materialize_instance($post_vars['instance_date']);
		$instance->set('evt_status', Event::STATUS_CANCELED);
		$instance->save();
		return LogicResult::redirect('/admin/admin_event?evt_event_id='.$event->key);
	}

	if($post_vars['action'] == 'end_series'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->end_series();
		return LogicResult::redirect('/admin/admin_event?evt_event_id='.$event->key);
	}

	if($post_vars['action'] == 'remove_from_event'){

		$eventregistrant = new EventRegistrant($post_vars['evr_event_registrant_id'], TRUE);
		$eventregistrant->remove();

		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	if($post_vars['action'] == 'remove_from_waiting_list'){

		$waiting_list = new WaitingList($post_vars['ewl_waiting_list_id'], TRUE);
		$waiting_list->remove();

		$returnurl = $session->get_return();
		return LogicResult::redirect($returnurl);
	}

	if($post_vars['action'] == 'set_primary_photo'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->set_primary_photo((int)$post_vars['photo_id']);

		return LogicResult::redirect('/admin/admin_event?evt_event_id='.$event->key);
	}

	if($post_vars['action'] == 'clear_primary_photo'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->clear_primary_photo();

		return LogicResult::redirect('/admin/admin_event?evt_event_id='.$event->key);
	}

	//REGISTRANTS
	$rnumperpage = 50;
	$roffset = LibraryFunctions::fetch_variable('roffset', 0, 0, '');
	$rsort = LibraryFunctions::fetch_variable('rsort', 'event_registrant_id', 0, '');
	$rsdirection = LibraryFunctions::fetch_variable('rsdirection', 'DESC', 0, '');
	$rsearchterm = LibraryFunctions::fetch_variable('rsearchterm', '', 0, '');
	$rsearch_criteria = array();
	$rsearch_criteria['event_id'] = $event->key;

	$event_registrants = new MultiEventRegistrant(
		$rsearch_criteria,
		array($rsort=>$rsdirection),
		$rnumperpage,
		$roffset
		);
	$numregistrants = $event_registrants->count_all();
	$event_registrants->load();

	$rpager = new Pager(array('numrecords'=>$numregistrants, 'numperpage'=> $rnumperpage), 'r');

	//SESSIONS
	$event_sessions = new MultiEventSessions(
		array('event_id' => $event->key),
		array('evs_session_number' => 'ASC')
	);
	$numsessions = $event_sessions->count_all();

	//WAITING LIST
	$wnumperpage = 20;
	$woffset = LibraryFunctions::fetch_variable('woffset', 0, 0, '');
	$wsort = LibraryFunctions::fetch_variable('wsort', 'ewl_waiting_list_id', 0, '');
	$wsdirection = LibraryFunctions::fetch_variable('wsdirection', 'DESC', 0, '');
	$wsearchterm = LibraryFunctions::fetch_variable('wsearchterm', '', 0, '');
	$wsearch_criteria = array();
	$wsearch_criteria['event_id'] = $event->key;
	$waiting_lists = new MultiWaitingList(
		$wsearch_criteria,
		array($wsort=>$wsdirection),
		$wnumperpage,
		$woffset);
	$numwaitinglist = $waiting_lists->count_all();
	$waiting_lists->load();
	$wpager = new Pager(array('numrecords'=>$numwaitinglist, 'numperpage'=> $wnumperpage), 'w');

	$settings = Globalvars::get_instance();
	$webDir = $settings->get_setting('webDir');

	// Build altlinks array
	$options = array();
	$options['altlinks'] = array();

	if(!$event->get('evt_delete_time')) {
		if($_SESSION['permission'] > 7){
			$options['altlinks']['Edit Event'] = '/admin/admin_event_edit?evt_event_id='.$event->key;
		}
		if($_SESSION['permission'] >= 8) {
			$options['altlinks']['Email Registrants'] = '/admin/admin_event_emails?evt_event_id='.$event->key;
			$options['altlinks']['Soft Delete'] = '/admin/admin_event?action=delete&evt_event_id='.$event->key;
		}
	}
	else {
		if($_SESSION['permission'] >= 8) {
			$options['altlinks']['Undelete'] = '/admin/admin_event?action=undelete&evt_event_id='.$event->key;
		}
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-falcon-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $url) {
			$dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item">' . htmlspecialchars($label) . '</a>';
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	// Load related objects for display
	$event_leader = $event->get('evt_usr_user_id_leader') ? new User($event->get('evt_usr_user_id_leader'), TRUE) : null;
	$event_location = $event->get('evt_loc_location_id') ? new Location($event->get('evt_loc_location_id'), TRUE) : null;
	$event_type = $event->get('evt_ety_event_type_id') ? new EventType($event->get('evt_ety_event_type_id'), TRUE) : null;
	$event_group = $event->get('evt_grp_group_id') ? new Group($event->get('evt_grp_group_id'), TRUE) : null;
	$event_survey = $event->get('evt_svy_survey_id') ? new Survey($event->get('evt_svy_survey_id'), TRUE) : null;
	$event_image = $event->get('evt_fil_file_id') ? new File($event->get('evt_fil_file_id'), TRUE) : null;
	$event_photos = $event->get_photos();

	//MESSAGES
	$mnumperpage = 20;
	$moffset = LibraryFunctions::fetch_variable('moffset', 0, 0, '');
	$msort = LibraryFunctions::fetch_variable('msort', 'message_id', 0, '');
	$msdirection = LibraryFunctions::fetch_variable('msdirection', 'DESC', 0, '');
	$msearchterm = LibraryFunctions::fetch_variable('msearchterm', '', 0, '');
	$msearch_criteria = array();
	$msearch_criteria['event_id_only'] = $event->key;
	$messages = new MultiMessage(
		$msearch_criteria,
		array($msort=>$msdirection),
		$mnumperpage,
		$moffset);
	$nummessages = $messages->count_all();
	$messages->load();
	$mpager = new Pager(array('numrecords'=>$nummessages, 'numperpage'=> $mnumperpage), 'w');

	// Sessions for paged display
	$snumperpage = 20;
	$soffset = LibraryFunctions::fetch_variable('soffset', 0, 0, '');
	$ssort = LibraryFunctions::fetch_variable('ssort', 'evs_session_number', 0, '');
	$ssdirection = LibraryFunctions::fetch_variable('ssdirection', 'ASC', 0, '');

	$event_sessions_paged = new MultiEventSessions(
		array('event_id' => $event->key),
		array($ssort => $ssdirection),
		$snumperpage,
		$soffset
	);
	$event_sessions_paged->load();
	$spager = new Pager(array('numrecords'=>$numsessions, 'numperpage'=> $snumperpage), 's');

	$page_vars = array(
		'session' => $session,
		'event' => $event,
		'dropdown_button' => $dropdown_button,
		'event_leader' => $event_leader,
		'event_location' => $event_location,
		'event_type' => $event_type,
		'event_group' => $event_group,
		'event_survey' => $event_survey,
		'event_image' => $event_image,
		'event_photos' => $event_photos,
		'event_registrants' => $event_registrants,
		'numregistrants' => $numregistrants,
		'rpager' => $rpager,
		'event_sessions' => $event_sessions,
		'numsessions' => $numsessions,
		'waiting_lists' => $waiting_lists,
		'numwaitinglist' => $numwaitinglist,
		'wpager' => $wpager,
		'messages' => $messages,
		'nummessages' => $nummessages,
		'mpager' => $mpager,
		'event_sessions_paged' => $event_sessions_paged,
		'spager' => $spager,
	);

	return LogicResult::render($page_vars);
}
