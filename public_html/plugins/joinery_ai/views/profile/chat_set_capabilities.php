<?php
/**
 * Member chat capability/control update — /profile/joinery_ai/chat_set_capabilities
 * (POST, JSON). Reuses the admin implementation verbatim (operates only on the
 * caller's own conversation, owner-checked).
 */
require(PathHelper::getIncludePath('plugins/joinery_ai/views/admin/chat_set_capabilities.php'));
