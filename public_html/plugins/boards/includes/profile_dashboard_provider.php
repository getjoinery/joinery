<?php
/**
 * Boards profile-dashboard provider: "My cards due this week".
 *
 * Contributes one section to the member profile page and the native-app
 * dashboard summary. Registered from the plugin's serve.php; with the plugin
 * inactive the section is absent.
 *
 * @version 1.0.0
 */

/** The member's open cards due within the next seven days (or overdue) — first 5, with a count stat. */
function boards_dashboard_cards_due($user) {
	if (!Globalvars::get_instance()->get_setting('boards_active', true, true)) {
		return null;
	}
	$cards = BoardsPayload::myCards((int)$user->key, array('window' => 'week'));
	if (!$cards['count']) {
		return null;
	}

	$items = array();
	foreach (array_slice($cards['cards'], 0, 5) as $card) {
		$due = $card['due'] ? LibraryFunctions::convert_time(
			str_replace(array('T', 'Z'), array(' ', ''), $card['due']), 'UTC',
			SessionControl::get_instance()->get_timezone(), 'M j') : '';
		$items[] = new ProfileDashboardItem(
			array('card_id' => $card['id'], 'board_id' => $card['board_id'], 'title' => $card['title'], 'due' => $card['due']),
			$card['title'],
			$card['board_name'],
			$due ? ('Due ' . htmlspecialchars($due)) : null,
			$card['overdue'] ? 'Overdue' : null,
			'/profile/boards/board?id=' . (int)$card['board_id'] . '&card=' . (int)$card['id']
		);
	}

	return new ProfileDashboardSection(
		'boards_cards_due',
		'My cards due this week',
		'/profile/boards/my_cards',
		new ProfileDashboardStat('boards_cards_due', 'Cards due this week', (int)$cards['count'], '/profile/boards/my_cards'),
		$items
	);
}
