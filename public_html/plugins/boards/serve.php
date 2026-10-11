<?php
/**
 * Boards plugin request bootstrap.
 *
 * Core loads this on every request while the plugin is active (inside a
 * discarded output buffer — only the static registrations persist). The pages
 * come from view auto-discovery under /profile/boards, so this file declares no
 * routes; it wires the platform extension points Boards uses.
 *
 * @version 1.0.0
 */

// ---- Content access gate: files attached to a board's cards ----
// An attachment File is gated on its board, so core's ordinary /uploads/
// serving path asks this provider whether the viewer has a role on the board.
require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/boards/includes/BoardsAttachmentGate.php'));
AccessGateRegistry::register(new BoardsAttachmentGate());

// ---- Profile dashboard: "My cards due this week" ----
// The web profile and the native-app dashboard summary iterate this registry;
// with the plugin inactive the section is simply absent.
require_once(PathHelper::getIncludePath('includes/ProfileDashboardRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/boards/includes/profile_dashboard_provider.php'));
ProfileDashboardRegistry::register('boards_cards_due', 'boards_dashboard_cards_due');

$routes = array();
