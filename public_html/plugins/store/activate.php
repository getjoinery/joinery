<?php
/**
 * Store plugin activation hook.
 *
 * Runs once, after the plugin's tables are created and its declared settings
 * are seeded (PluginManager::onActivate). Three jobs the declarative
 * seeders can't do:
 *
 *   1. Migrate the Stripe customer identity off the users table into the
 *      store-owned stc_stripe_customers table, then drop the old columns.
 *      This lives here (not a core migration) because the target table does
 *      not exist until the store plugin's tables are created at activation.
 *   2. Seed the two purchase-receipt email templates the checkout flow needs.
 *   3. Point the core tier upgrade CTA at /pricing (only if unset).
 *
 * All steps are idempotent and self-guarded so re-activation is safe.
 */
function store_activate() {
	$dblink = DbConnector::get_instance()->get_db_link();

	// ---- 1. Stripe customer identity backfill + column disposal -----------
	// Guarded on the source column still existing (post-drop this no-ops).
	$col_exists = $dblink->query(
		"SELECT 1 FROM information_schema.columns
		 WHERE table_name = 'usr_users' AND column_name = 'usr_stripe_customer_id' LIMIT 1"
	)->fetchColumn();

	if ($col_exists) {
		// Copy every user that has a live or test customer id into the new table.
		$dblink->exec(
			"INSERT INTO stc_stripe_customers (stc_usr_user_id, stc_customer_id, stc_customer_id_test)
			 SELECT usr_user_id, usr_stripe_customer_id, usr_stripe_customer_id_test
			 FROM usr_users u
			 WHERE (usr_stripe_customer_id IS NOT NULL OR usr_stripe_customer_id_test IS NOT NULL)
			   AND NOT EXISTS (
			       SELECT 1 FROM stc_stripe_customers s WHERE s.stc_usr_user_id = u.usr_user_id
			   )"
		);
		// Drop the old columns off the users table.
		$dblink->exec("ALTER TABLE usr_users DROP COLUMN IF EXISTS usr_stripe_customer_id");
		$dblink->exec("ALTER TABLE usr_users DROP COLUMN IF EXISTS usr_stripe_customer_id_test");
	}

	// ---- 1b. Product fulfillment provider backfill + old event FK disposal -
	// pro_evt_event_id -> pro_fulfillment_provider / pro_fulfillment_ref. This
	// lives here (not a core migration) because pro_products and its
	// pro_fulfillment_* columns are owned by the store plugin and only exist
	// after the plugin's tables are synced — which happens before this hook but
	// after the core migration chain. Guarded on the old column so it no-ops on
	// fresh installs and on re-activation.
	$pro_col_exists = $dblink->query(
		"SELECT 1 FROM information_schema.columns
		 WHERE table_name = 'pro_products' AND column_name = 'pro_evt_event_id' LIMIT 1"
	)->fetchColumn();

	if ($pro_col_exists) {
		// Single-event products carry the event id as the fulfillment ref.
		$dblink->exec(
			"UPDATE pro_products
			 SET pro_fulfillment_provider = 'event_registration', pro_fulfillment_ref = pro_evt_event_id
			 WHERE pro_evt_event_id IS NOT NULL AND pro_fulfillment_provider IS NULL"
		);
		// Event-bundle products (a product group, no single event) still need
		// the provider so checkout invokes fulfillment; ref stays null.
		$dblink->exec(
			"UPDATE pro_products
			 SET pro_fulfillment_provider = 'event_registration'
			 WHERE pro_grp_group_id IS NOT NULL AND pro_evt_event_id IS NULL AND pro_fulfillment_provider IS NULL"
		);
		$dblink->exec("ALTER TABLE pro_products DROP COLUMN IF EXISTS pro_evt_event_id");
	}

	// ---- 2. Purchase-receipt email templates ------------------------------
	$default_body = <<<'HTML'
<p>Hi *recipient->usr_first_name*,</p>

{is_billing}
<p>Thanks for your order. Your receipt is below.</p>
{end}
{~is_billing}
<p>You have access to the following items from a recent order:</p>
{end}

<table style="width:600px;border-collapse:collapse;margin:1rem 0;">
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

	$product_body = <<<'HTML'
<p>Hi *recipient->usr_first_name*,</p>

<p>Thank you for your purchase of <strong>*product_name*</strong>.</p>

{after_purchase_message}
<div style="margin:1rem 0;">*after_purchase_message*</div>
{end}

<p>Thanks,<br>*site_name*</p>
HTML;

	$insert_sql = "INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
	               SELECT ?, 2, ?, ?, now(), now()
	               WHERE NOT EXISTS (SELECT 1 FROM emt_email_templates WHERE emt_name = ?)";
	$inserts = array(
		array('purchase_receipt_default',         'Your purchase receipt',      $default_body),
		array('purchase_receipt_product_default', 'About your recent purchase', $product_body),
	);
	foreach ($inserts as $row) {
		$q = $dblink->prepare($insert_sql);
		$q->execute(array($row[0], $row[1], $row[2], $row[0]));
	}

	// ---- 3. Tier upgrade URL: point at /pricing if the admin hasn't set it -
	// Core declares tier_upgrade_url with an empty default (the gate prompt
	// reads it whether or not the store is installed); the store upgrades the
	// value to /pricing, without clobbering an admin-chosen URL.
	$dblink->exec(
		"UPDATE stg_settings SET stg_value = '/pricing'
		 WHERE stg_name = 'tier_upgrade_url' AND (stg_value IS NULL OR stg_value = '')"
	);
}
