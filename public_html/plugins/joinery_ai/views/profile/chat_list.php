<?php
/**
 * Member chat list — /profile/joinery_ai/chat_list (GET, JSON). Reuses the admin
 * implementation verbatim (owner-scoped to the caller's own conversations).
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_list.php'));
