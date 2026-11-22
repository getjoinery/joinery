<?php
/**
 * IncidentSourceQuiet — a source whose incidents open, refresh and clear like
 * any other, and wait in the inbox, but never tell anyone: the reconciler
 * sends no notification for them (IncidentReconciler::notify()).
 *
 * For a source whose news is said elsewhere, once, in words written for it:
 * the fix-ahead-of-Ubuntu incident's list changes with every CVE Ubuntu's
 * tracker adds or rates, and the automatic package fix runner already emails
 * what is new and what needs a person (specs/package_fixes_automatic.md WP5).
 *
 * @version 1.0
 */
interface IncidentSourceQuiet extends IncidentSource {
}
?>
