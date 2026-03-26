<?php
/**
 * Migration: Insert subscription email templates
 *
 * Creates 7 email templates for subscription lifecycle events:
 * subscription_created, subscription_upgraded, subscription_downgraded,
 * subscription_cancelled, subscription_reactivated, subscription_expired,
 * subscription_payment_failed
 *
 * @version 1.0
 */
function migration_subscription_email_templates() {
	$dbconnector = DbConnector::get_instance();
	$dblink = $dbconnector->get_db_link();

	$templates = array(
		array(
			'name' => 'subscription_created',
			'subject' => 'Welcome to your new subscription',
			'body' => '<p>Thank you for subscribing! Your subscription is now active.</p>
<p><strong>Plan:</strong> *tier_name*</p>
<p><strong>Billing amount:</strong> $*billing_amount*</p>
<p><strong>Next billing date:</strong> *next_billing_date*</p>
<p>You can manage your subscription at any time from your account:</p>
<p><a href="*web_dir*/change-tier">Manage Subscription</a></p>
<p>Thanks,</p>
<p>*site_name*</p>'
		),
		array(
			'name' => 'subscription_upgraded',
			'subject' => 'Your subscription has been upgraded',
			'body' => '<p>Your subscription has been upgraded.</p>
<p><strong>New plan:</strong> *tier_name*</p>
<p><strong>Previous plan:</strong> *previous_tier_name*</p>
<p><strong>Effective:</strong> Immediately</p>
<p><strong>Next billing date:</strong> *next_billing_date*</p>
<p>You can manage your subscription at any time from your account:</p>
<p><a href="*web_dir*/change-tier">Manage Subscription</a></p>
<p>Thanks,</p>
<p>*site_name*</p>'
		),
		array(
			'name' => 'subscription_downgraded',
			'subject' => 'Your subscription has been changed',
			'body' => '<p>Your subscription has been changed.</p>
<p><strong>New plan:</strong> *tier_name*</p>
<p><strong>Previous plan:</strong> *previous_tier_name*</p>
<p><strong>Effective:</strong> *effective_date*</p>
<p>You will continue to have access to your previous plan features until the change takes effect.</p>
<p>You can manage your subscription at any time from your account:</p>
<p><a href="*web_dir*/change-tier">Manage Subscription</a></p>
<p>Thanks,</p>
<p>*site_name*</p>'
		),
		array(
			'name' => 'subscription_cancelled',
			'subject' => 'Your subscription has been cancelled',
			'body' => '<p>Your subscription has been cancelled.</p>
<p><strong>Plan:</strong> *tier_name*</p>
<p><strong>Access until:</strong> *access_end_date*</p>
<p>You will continue to have access to your plan features until the date above.</p>
<p>If you change your mind, you can resubscribe at any time from your account:</p>
<p><a href="*web_dir*/change-tier">Resubscribe</a></p>
<p>Thanks,</p>
<p>*site_name*</p>'
		),
		array(
			'name' => 'subscription_reactivated',
			'subject' => 'Your subscription has been reactivated',
			'body' => '<p>Your subscription has been reactivated.</p>
<p><strong>Plan:</strong> *tier_name*</p>
<p><strong>Next billing date:</strong> *next_billing_date*</p>
<p>You now have full access to all your plan features again.</p>
<p>You can manage your subscription at any time from your account:</p>
<p><a href="*web_dir*/change-tier">Manage Subscription</a></p>
<p>Thanks,</p>
<p>*site_name*</p>'
		),
		array(
			'name' => 'subscription_expired',
			'subject' => 'Your subscription has expired',
			'body' => '<p>Your subscription has expired and is no longer active.</p>
<p><strong>Previous plan:</strong> *tier_name*</p>
<p>You no longer have access to your plan features. If you would like to resubscribe, you can do so from your account:</p>
<p><a href="*web_dir*/change-tier">Resubscribe</a></p>
<p>Thanks,</p>
<p>*site_name*</p>'
		),
		array(
			'name' => 'subscription_payment_failed',
			'subject' => 'Action required: subscription payment failed',
			'body' => '<p>We were unable to process your subscription payment.</p>
<p><strong>Plan:</strong> *tier_name*</p>
<p><strong>Amount:</strong> $*billing_amount*</p>
<p>Please update your payment method to avoid any interruption to your subscription:</p>
<p><a href="*web_dir*/change-tier">Update Payment Method</a></p>
<p>If you have any questions, please contact us for assistance.</p>
<p>Thanks,</p>
<p>*site_name*</p>'
		),
	);

	$sql = "INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
			VALUES (?, 2, ?, ?, now(), now())";

	foreach ($templates as $template) {
		$q = $dblink->prepare($sql);
		$q->execute([$template['name'], $template['subject'], $template['body']]);
	}

	return true;
}
