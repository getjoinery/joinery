<?php
/**
 * IncidentSource — one condition this management node watches for on every
 * node, and turns into an incident while it holds (incident_triage.md,
 * Types).
 *
 * A source answers from what is already stored on the node's row (a column a
 * detector stamped): it never probes, because the reconciler asks every
 * source about every node on every tick. It knows only its own condition; the
 * reconciler owns opening, refreshing, clearing, reopening and notifying.
 *
 * Register one from a plugin bootstrap: IncidentSources::register($source).
 *
 * @version 1.0
 */
interface IncidentSource {

	/** The source name stored on its incidents: 'plane:<name>'. */
	public function name(): string;

	/**
	 * Whether the condition holds on this node now. Null when it does not;
	 * otherwise ['title' => plain words, 'severity' => critical|warning,
	 * 'detail' => label => scalar, shown as the incident's evidence]. Called
	 * only for an operational node (no install state, not removed).
	 */
	public function evaluate(ManagedNode $node): ?array;

	/** The words for the timeline when the condition is gone on this node. */
	public function cleared_text(ManagedNode $node): string;
}
?>
