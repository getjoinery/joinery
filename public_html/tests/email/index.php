<?php
/**
 * Retired per-suite index. The unified test dashboard at /tests/ lists and runs
 * every suite (this one included). This stub keeps the old URL working.
 */
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
SessionControl::get_instance()->check_permission(10);
header('Location: /tests/', true, 302);
echo '<!DOCTYPE html><meta charset="utf-8"><p>The test dashboard has moved to <a href="/tests/">/tests/</a>.</p>';
