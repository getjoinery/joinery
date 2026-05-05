<?php
/**
 * Migration: Unify receipt templates.
 *
 * Phase 2 of specs/receipts_refactor.md. Inserts the two new default
 * receipt templates and soft-deletes six legacy/orphan templates that
 * are no longer called from code:
 *
 *   - event_reciept_content (was at cart_charge_logic.php:531 — removed)
 *   - event_bundle_content  (was at cart_charge_logic.php:559 — removed)
 *   - subscription_created  (orphan — never called from cart flow)
 *   - event_deposit_reciept_content (orphan, typo'd name)
 *   - single_donation_reciept       (orphan, typo'd name)
 *   - monthly_donation_reciept      (orphan, typo'd name)
 *
 * Soft-delete preserves any customizations admins may have made — the
 * bodies remain in the database, queryable, but no code calls them.
 *
 * Idempotent: inserts use a duplicate-name guard, soft-delete uses an
 * IS NULL guard.
 */
function migration_receipt_templates_unify() {
	$dbconnector = DbConnector::get_instance();
	$dblink = $dbconnector->get_db_link();

	// ----- Template body: purchase_receipt_default -------------------------
	// One template, two render modes via {is_billing}. Iterates {loop line_items}
	// and dispatches per-line content via {line->outcome == "..."} conditionals.
	// Gift lines (line->is_gift_to set) get a "sent as gift" line and forwarded
	// info only; no activation token, no welcome content.
	$default_body = <<<'HTML'
<p>Hi *recipient->usr_first_name*,</p>

{is_billing}
<p>Thanks for your order. Your receipt is below.</p>
{end}
{~is_billing}
<p>You have access to the following items from a recent order:</p>
{end}

<table style="width:100%;border-collapse:collapse;margin:1rem 0;">
{loop line_items as line}
<tr style="border-bottom:1px solid #eee;">
  <td style="padding:0.5rem 0;vertical-align:top;">
    <strong>*line->product_name*</strong>
    {line->quantity > 1} (x*line->quantity*){end}

    {line->is_gift_to}
      <br><em>Sent as a gift to *line->is_gift_to*</em>
      {line->outcome == "digital"}{line->digital_link}
        <br>Forwardable link: <a href="*line->digital_link*">*line->digital_link*</a>
      {end}{end}
    {end}

    {~line->is_gift_to}
      {line->outcome == "event"}{line->act_code}
        <br><a href="*web_dir*/profile/event_register_finish?act_code=*line->act_code*&eventregistrantid=*line->event_registrant_id*">Confirm your registration for *line->event_name*</a>
      {end}{end}

      {line->outcome == "bundle"}
        <br>Includes:<br>*line->event_list*
        {line->act_code}
          <br><a href="*web_dir*/profile/event_register_finish?act_code=*line->act_code*&eventregistrantid=*line->event_registrant_id*">Confirm your bundle registrations</a>
        {end}
      {end}

      {line->outcome == "subscription"}
        <br>Your subscription is active.
      {end}

      {line->outcome == "digital"}{line->digital_link}
        <br><a href="*line->digital_link*">Access your purchase</a>
      {end}{end}
    {end}
  </td>
  {is_billing}
  <td style="padding:0.5rem 0;text-align:right;vertical-align:top;font-weight:600;">
    *currency_symbol**line->price*
  </td>
  {end}
</tr>
{end}
</table>

{is_billing}
<p style="text-align:right;margin-top:1rem;"><strong>Total: *currency_symbol**order_total*</strong></p>

{coupon_codes_used}
<p><small>Coupon codes applied: {loop coupon_codes_used as code}*code* {end}</small></p>
{end}
{end}

<p>Thanks,<br>*site_name*</p>
HTML;

	// ----- Template body: purchase_receipt_product_default -----------------
	// Generic per-product wrapper. Always to the billing user. Per spec the
	// admin-authored message in pro_after_purchase_message is the focus; the
	// rest is light framing.
	$product_body = <<<'HTML'
<p>Hi *recipient->usr_first_name*,</p>

<p>Thank you for your purchase of <strong>*product_name*</strong>.</p>

{after_purchase_message}
<div style="margin:1rem 0;">*after_purchase_message*</div>
{end}

<p>Thanks,<br>*site_name*</p>
HTML;

	// ----- Insert (idempotent: skip if name already present) ---------------
	$insert_sql = "INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
	               SELECT ?, 2, ?, ?, now(), now()
	               WHERE NOT EXISTS (SELECT 1 FROM emt_email_templates WHERE emt_name = ?)";

	// Static subjects — the EmailTemplate engine substitutes variables in the
	// body but not in emt_subject, so any *placeholders* in the subject would
	// render literally. Keep these plain.
	$inserts = array(
		array('purchase_receipt_default',         'Your purchase receipt',          $default_body),
		array('purchase_receipt_product_default', 'About your recent purchase',     $product_body),
	);
	foreach ($inserts as $row) {
		$q = $dblink->prepare($insert_sql);
		$q->execute(array($row[0], $row[1], $row[2], $row[0]));
	}

	// ----- Soft-delete legacy and orphan templates -------------------------
	$soft_delete_sql = "UPDATE emt_email_templates
	                    SET emt_delete_time = now()
	                    WHERE emt_name IN (
	                        'event_reciept_content',
	                        'event_bundle_content',
	                        'subscription_created',
	                        'event_deposit_reciept_content',
	                        'single_donation_reciept',
	                        'monthly_donation_reciept'
	                    ) AND emt_delete_time IS NULL";
	$dblink->prepare($soft_delete_sql)->execute();

	return true;
}
