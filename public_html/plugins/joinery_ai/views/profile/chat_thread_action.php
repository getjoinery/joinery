<?php
/**
 * Member chat thread action (pin/rename/delete) — /profile/joinery_ai/chat_thread_action
 * (POST, JSON). Reuses the admin implementation verbatim (operates only on the
 * caller's own conversation, owner-checked).
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_thread_action.php'));
