<?php
/**
 * Member chat poll — /profile/joinery_ai/chat_poll (GET, JSON).
 * Reuses the admin implementation verbatim (operates only on the caller's own
 * conversation, owner-checked).
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_poll.php'));
