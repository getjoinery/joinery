<?php
/**
 * IncidentSourceEveryOccurrenceNew — a source whose every occurrence is its
 * own incident: the reconciler never reopens a recently cleared one for it
 * (IncidentReconciler::REOPEN_WINDOW), and always opens a new incident and
 * tells the superadmins.
 *
 * For a source where coming back is not flapping but a new event: the
 * never-do list clears only when a person marks the machine safe, so what it
 * holds after that is something new on the machine. Folded into the cleared
 * incident it would inherit that incident's timeline and notes ("a known
 * false alarm") and, had someone set it to Ignored, would reopen silently.
 *
 * @version 1.0
 */
interface IncidentSourceEveryOccurrenceNew extends IncidentSource {
}
?>
