<?php
/**
 * Member chat per-turn action (copy/delete) — /profile/joinery_ai/chat_turn_action
 * (POST, JSON). Reuses the admin implementation verbatim (operates only on the
 * caller's own messages, owner-checked).
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_turn_action.php'));
