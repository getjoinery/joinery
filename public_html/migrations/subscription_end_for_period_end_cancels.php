<?php
/**
 * A subscription cancelled at the period end ends at the period end.
 *
 * odi_subscription_cancelled_time is when a subscription ENDS: the change-tier
 * page writes the period end there, and the 'current subscription' filter reads
 * it that way. The Stripe sync wrote canceled_at instead, the moment the cancel
 * was asked for, so a buyer still billed through the period lost the
 * subscription from their page, and Reactivate could not find it, from the day
 * they asked. The sync is fixed; rows it wrote before are put right here: a
 * period-end cancel whose recorded end is earlier than its period end ends at
 * the period end.
 *
 * Only a subscription still running is moved (active, trialing, past due, in
 * grace): one that has ended (canceled, expired) carries the true moment it
 * ended, even when it was first scheduled to end at its period end and then
 * cancelled outright, and moving that to a future period end would make an
 * ended subscription read as current again.
 *
 * Idempotent: a row already ending at its period end is not touched again.
 *
 * odi_order_items belongs to the store plugin, so the table and every column
 * named are checked for first (a site without the store, or one that turned it
 * off, has nothing to put right).
 */
function subscription_end_for_period_end_cancels() {
	$db = DbConnector::get_instance()->get_db_link();

	$q = $db->prepare("SELECT to_regclass('odi_order_items')");
	$q->execute();
	if ($q->fetchColumn() === null) {
		echo "  subscription ends: no order items table here (store not active), nothing to put right\n";
		return;
	}
	$columns = array('odi_subscription_cancel_at_period_end', 'odi_subscription_cancelled_time', 'odi_subscription_period_end', 'odi_subscription_status');
	$q = $db->prepare(
		"SELECT count(1) FROM information_schema.columns
		  WHERE table_name = 'odi_order_items' AND column_name IN ('" . implode("','", $columns) . "')");
	$q->execute();
	if ((int)$q->fetchColumn() !== count($columns)) {
		echo "  subscription ends: the order items table lacks the subscription columns here, nothing to put right\n";
		return;
	}

	$q = $db->prepare(
		"UPDATE odi_order_items SET odi_subscription_cancelled_time = odi_subscription_period_end
		  WHERE odi_subscription_cancel_at_period_end = TRUE
		    AND odi_subscription_status IN ('active', 'trialing', 'past_due', 'grace_period')
		    AND odi_subscription_period_end IS NOT NULL
		    AND odi_subscription_cancelled_time IS NOT NULL
		    AND odi_subscription_cancelled_time < odi_subscription_period_end");
	$q->execute();
	echo "  subscription ends: " . $q->rowCount() . " period-end cancel(s) now end at their period end\n";
}
