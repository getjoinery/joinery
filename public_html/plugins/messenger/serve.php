<?php
/**
 * Messenger plugin request bootstrap.
 *
 * Core loads this on every request while the plugin is active (inside a
 * discarded output buffer — only the static registrations persist). The
 * messenger's pages come from view auto-discovery under /profile/messenger, so
 * this file declares no routes; it exists to wire the two platform extension
 * points the messenger uses.
 *
 * @version 1.0.0
 */

// ---- Content access gate: conversation attachments ----
// An attachment File is gated on its conversation, so core's ordinary
// /uploads/ serving path asks this provider whether the viewer is in the room.
require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/includes/MessengerAttachmentGate.php'));
AccessGateRegistry::register(new MessengerAttachmentGate());

$routes = array();
