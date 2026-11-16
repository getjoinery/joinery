<?php
/**
 * Set up your site — /profile/server_manager/configure
 *
 * The one page describing the Managed site a buyer wants: the domain (theirs,
 * or one we register for them), the site's name, where it lives, the admin
 * email. Save and continue freezes the draft and shows the summary; Continue
 * to payment hands the frozen draft's id to the store's own add-to-cart path.
 *
 * Every question about the site lives here. Adding one later is a column on
 * the provision row and a field on this form — never a change to the store.
 *
 * @version 1.8 - On your own Linode (specs/own_linode_we_install.md): the plan shows its server note and the email and
 *                backups tickbox (unticked) and hides the region; the summary names the server and the bundle, and
 *                offers Connect your Linode account until the grant can carry the build
 * @version 1.7 - a bring-your-own domain is told it adds the address record and the email records, not one record
 * @version 1.6 - the domain check is a data block and assets/js/domain-availability.js
 * @version 1.5 - a plan choice (Starter or Managed) heads the form; the summary names the plan
 * @version 1.4 - not found where this site does not sell hosting (SmHosting)
 * @version 1.3 - the registrant's details wait for a free name; no Site name field (derived from the domain);
 *                an own domain says the site has no address or certificate until its A record is added
 * @version 1.2 - the registrant's address and phone are the platform's Address and PhoneNumber form blocks
 * @version 1.1 - the step strip (step 2 on the form, step 3 on the summary)
 * @version 1.0 - specs/managed_hosting_phase1_purchase.md §4.1, §4.2
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_configure_logic.php', 'logic', 'system', null, 'server_manager'));

// A buyer's page exists only where this site sells hosting (SmHosting).
SmHosting::not_found_unless_enabled();

$page_vars = process_logic(profile_configure_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Set up your site',
	'breadcrumbs' => array('Your Sites' => '/profile/server_manager', 'Set up your site' => ''),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Set up your site', $hoptions);
echo $steps_html;
?>

<?php if ($mode === 'summary'): ?>

<section class="smc-summary">
	<h2><?php echo htmlspecialchars($row->get('cvp_domain')); ?></h2>
	<p class="smc-lead">Here is the site we will build. Check it, then continue to payment.</p>
	<dl class="sms-facts">
		<dt>Domain</dt>
		<dd><?php echo htmlspecialchars($row->get('cvp_domain'));
			if ((string)$row->get('cvp_domain_source') === 'register') {
				echo ' — we register it for you, ' . htmlspecialchars(ManagedSiteDraft::money((string)$row->get('cvp_domain_quote')))
					. ' for the first year. You are its legal owner from day one.';
			} else {
				echo ' — your own domain. You add its DNS records where the domain is registered: one gives the site '
					. 'its address and certificate, the others are for its email. Your sites page lists them once the site '
					. 'is built, and we email you when it is.';
			} ?></dd>
		<?php if (count($regions) > 1): ?>
		<dt>Region</dt>
		<dd><?php echo htmlspecialchars($row->get('cvp_region')); ?></dd>
		<?php endif; ?>
		<dt>Site admin email</dt>
		<dd><?php echo htmlspecialchars($row->get('cvp_buyer_email')); ?></dd>
		<dt>Plan</dt>
		<dd><?php echo htmlspecialchars($plan_label); ?></dd>
		<?php if ($own_linode): ?>
		<dt>Server</dt>
		<dd>A 1 GB Linode in your own Linode account, in Newark (us-east). Linode bills you for it, from $5 a month.</dd>
		<dt>Email and backups</dt>
		<dd><?php if ($row->get('cvp_services_wanted')) {
				echo 'Yes: email from your site and offsite backups, through us'
					. ($bundle_price !== '' ? ', ' . htmlspecialchars($bundle_price) : '') . '.';
			} else {
				echo 'No. Your site has no email until you set up a mail provider, so a forgotten password cannot be '
					. 'reset by email. You can add ours later from your site\'s setup.';
			} ?></dd>
		<?php endif; ?>
		<dt>Price</dt>
		<dd><?php if ($price_sentence === 'free') {
				echo 'The setup is free; Linode bills you for the server';
				if ($row->get('cvp_services_wanted') && $bundle_price !== '') { echo ', and email and backups are ' . htmlspecialchars($bundle_price); }
				if ($domain_sentence !== '') { echo ', ' . htmlspecialchars($domain_sentence); }
				echo '.';
			} elseif ($price_sentence !== '') {
				echo htmlspecialchars($price_sentence);
				if ($domain_sentence !== '') { echo ', ' . htmlspecialchars($domain_sentence); }
				echo '.';
			} else {
				echo htmlspecialchars($plan_label) . ' hosting is not on sale right now.';
			} ?></dd>
	</dl>

	<?php if ($own_linode && !$linode_live && $product !== null): ?>
		<section class="smc-connect">
			<h3>Connect your Linode account</h3>
			<p>We create one server in your Linode account and build your site on it. Linode asks you to let us
				manage servers in your account; we use that access for the build only and forget it when your site is
				ready.</p>
			<?php if ($linode_connected): ?>
			<p class="sms-error">Your Linode connection did not come back usable. Connect again.</p>
			<?php endif; ?>
			<?php echo PublicPage::action_button('Connect your Linode account', '/profile/server_manager/connect_cloud', array(
				'hidden' => array('action' => 'connect', 'cvp_customer_cloud_provision_id' => (int)$row->key),
				'class'  => 'sms-visit',
			)); ?>
			<p class="sms-note">No Linode account? You can make one on the way.</p>
		</section>
	<?php elseif ($payment_form !== ''): ?>
		<?php if ($in_cart): ?>
			<p><a href="/cart" class="sms-visit">Finish payment</a>
				<span class="sms-note">This site is already in your cart.</span></p>
		<?php else: ?>
			<?php echo $payment_form; ?>
		<?php endif; ?>
	<?php else: ?>
		<p class="sms-error"><?php echo htmlspecialchars($plan_label); ?> hosting is not on sale right now, so this site
			cannot be paid for yet. Your setup is saved; Edit it to choose another plan.</p>
	<?php endif; ?>

	<div class="smc-actions">
		<?php echo PublicPage::action_button('Edit', ManagedSiteDraft::CONFIGURE_URL, array(
			'hidden'  => array('action' => 'edit', 'cvp_customer_cloud_provision_id' => (int)$row->key),
			'confirm' => 'This removes the site from your cart; you will continue to payment again when you are done.',
			'class'   => 'sms-secondary-btn',
		)); ?>
		<?php echo PublicPage::action_button('Delete', ManagedSiteDraft::CONFIGURE_URL, array(
			'hidden'  => array('action' => 'delete', 'cvp_customer_cloud_provision_id' => (int)$row->key),
			'confirm' => 'Remove this site setup? Nothing has been bought or created.',
			'class'   => 'sms-secondary-btn',
		)); ?>
	</div>
</section>

<?php else: ?>

<p class="smc-lead">Tell us about the site you want. Nothing is bought or created until you pay.
	<?php if ($plans_sentence !== ''): ?><strong><?php echo htmlspecialchars($plans_sentence); ?></strong><?php
		if ($sellable) { echo ', plus the domain year at cost if we register the name for you'; } ?>.<?php endif; ?></p>

<?php if (!empty($errors)): ?>
<div class="sms-error">
	<ul>
	<?php foreach ($errors as $error): ?>
		<li><?php echo $error; ?></li>
	<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>

<?php
$formwriter = $page->getFormWriter('smc_configure');
echo $formwriter->begin_form();
$formwriter->hiddeninput('action', '', array('value' => 'save'));
if ($row) {
	$formwriter->hiddeninput('cvp_customer_cloud_provision_id', '', array('value' => (int)$row->key));
}

// The choice shows when there is one to make, and when the draft's own plan
// is not on sale now: then its buyer picks the one that is, knowingly.
if (count($plans) > 1 || ($row !== null && !isset($plans[ManagedSiteDraft::plan_of($row)]))) {
	$plan_options = array();
	foreach ($plans as $plan => $def) {
		$plan_options[$plan] = $def['label'] . ($plan_prices[$plan] !== '' ? ', ' . preg_replace('/ from today$/', '', $plan_prices[$plan]) : '')
			. ' — ' . $def['blurb'];
	}
	$own_fields = array('with_services', 'with_services_note', 'own_linode_note');
	$plan_rules = array();
	foreach ($plans as $plan => $def) {
		$plan_rules[$plan] = $plan === 'own_linode'
			? array('show' => $own_fields, 'hide' => array('cvp_region'))
			: array('hide' => $own_fields, 'show' => array('cvp_region'));
	}
	$formwriter->radioinput('plan', 'Plan', array(
		'options'          => $plan_options,
		'value'            => $values['plan'],
		'visibility_rules' => $plan_rules,
	));
}

$registrant_fields = array_merge(array('managed_domain_status', 'md_intro'), array_keys(ManagedDomainIntake::CONTACT_FIELDS));
$own_domain_note = '<p id="own_domain_note" class="form-text">Your site has no address and no security certificate '
	. 'until your domain points at it. After payment, your sites page lists the DNS records to add where your '
	. 'domain is registered: one for the site\'s address, the others for its email. We email you when the site is '
	. 'built.</p>';
if ($sellable) {
	$formwriter->radioinput('cvp_domain_source', 'Your domain', array(
		'options' => array(
			'register' => 'Register one for me',
			'own'      => 'I own one already',
		),
		'value' => $values['cvp_domain_source'],
		'visibility_rules' => array(
			'register' => array('show' => $registrant_fields, 'hide' => array('own_domain_note')),
			'own'      => array('hide' => $registrant_fields, 'show' => array('own_domain_note')),
		),
	));
} else {
	$formwriter->hiddeninput('cvp_domain_source', '', array('value' => 'own'));
}

$formwriter->textinput('cvp_domain', 'Domain name', array(
	'value'       => $values['cvp_domain'],
	'maxlength'   => 253,
	'placeholder' => 'smithfamily.com',
	'helptext'    => $sellable
		? 'If we register it: ' . $offered_tlds . ' names, priced at cost for the first year. If you own it: exactly as you own it.'
		: 'Exactly as you own it.',
	'validation'  => array('required' => true),
));
echo '<div id="managed_domain_status" class="form-text" aria-live="polite"></div>';
echo $own_domain_note;

if ($sellable) {
	// Asked once the name is known to be free. A saved draft, or a form shown
	// again with errors, stays open: the name prefills from the account, so it
	// says nothing about whether the buyer has answered.
	$registrant_kept = $row !== null || !empty($errors);
	echo '<div id="md_registrant"' . ($registrant_kept ? ' data-keep="1"' : '') . '>';
	echo '<p id="md_intro" class="form-text">Who owns the domain. This becomes the public registration record, so it '
		. 'has to be a real contact — private registration keeps it hidden from WHOIS lookups, and we turn that on '
		. 'for you at no cost.</p>';
	$formwriter->textinput('md_first_name', 'First name', array('value' => $values['md_first_name'] ?? '', 'maxlength' => 255));
	$formwriter->textinput('md_last_name', 'Last name', array('value' => $values['md_last_name'] ?? '', 'maxlength' => 255));

	// The address and the phone are the platform's own form blocks, prefilled
	// through unsaved models: a country and a phone country code are chosen
	// from the country table, never typed.
	$registrant_address = new Address(NULL);
	foreach (array('usa_cco_country_code_id', 'usa_address1', 'usa_address2', 'usa_city', 'usa_state', 'usa_zip_code_id') as $col) {
		$registrant_address->set($col, $values[$col] ?? '');
	}
	Address::renderFormFields($formwriter, array('required' => true, 'model' => $registrant_address));

	$registrant_phone = new PhoneNumber(NULL);
	$registrant_phone->set('phn_cco_country_code_id', $values['phn_cco_country_code_id'] ?? '');
	$registrant_phone->set('phn_phone_number', $values['phn_phone_number'] ?? '');
	PhoneNumber::renderFormFields($formwriter, array('required' => true, 'model' => $registrant_phone));

	$formwriter->textinput('md_email', 'Email for the registration record', array(
		'value' => $values['md_email'] ?? '', 'maxlength' => 255));
	echo '</div>';
}

if (count($regions) > 1) {
	$region_options = array();
	foreach ($regions as $region) {
		$region_options[$region] = $region;
	}
	$formwriter->dropinput('cvp_region', 'Where the site lives', array(
		'options' => $region_options, 'value' => $values['cvp_region']));
}

// On your own Linode: where the server goes, and the email and backups bundle,
// offered unticked (Q7). Shown when the plan is chosen, or when it is the only one.
if (isset($plans['own_linode'])) {
	echo '<div id="own_linode_note" class="form-text">We build your site on a 1 GB Linode in your own Linode account, '
		. 'in Newark (us-east). Linode bills you for the server, from $5 a month; the setup is free. You connect your '
		. 'Linode account on the next step.</div>';
	if ($bundle_price !== '') {
		$formwriter->checkboxinput('with_services', 'Add email and backups through us, ' . $bundle_price, array(
			'checked'  => $values['with_services'] !== '',
		));
		echo '<p id="with_services_note" class="form-text">Your site sends email from your domain, and its backups are '
			. 'kept offsite and encrypted. Without it, your site has no email until you set up a mail provider, so a '
			. 'forgotten password cannot be reset by email.</p>';
	}
}

$formwriter->textinput('cvp_buyer_email', 'Site admin email', array(
	'value'      => $values['cvp_buyer_email'],
	'maxlength'  => 255,
	'helptext'   => 'The sign-in for your new site\'s admin account. Its first password is shown to you once, on your sites page.',
	'validation' => array('required' => true),
));

$formwriter->submitbutton('btn_save', 'Save and continue');
echo $formwriter->end_form();
?>

<?php echo $availability_html; ?>

<?php endif; ?>

<style>
.smc-lead { margin: 0 0 1.2em; }
.smc-summary .sms-facts { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: .5em 0 1.2em; }
.smc-summary .sms-facts dt { font-weight: 600; }
.smc-summary .sms-facts dd { margin: 0; }
.smc-actions { margin-top: 1.5em; }
.sms-error { padding: 12px 16px; background: #fef2f2; border-radius: 4px; }
.sms-error ul { margin: 0; padding-left: 1.2em; }
.sms-note { color: #555; font-size: .9em; margin-left: .6em; }
.sms-visit { display: inline-block; background: #02b159; color: #fff; padding: 10px 22px; border: 0;
	border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 1em; cursor: pointer; }
.sms-pay { display: inline-block; margin: .5em 0; }
.sms-secondary-btn { background: #fff; color: #1f2937; border: 1px solid #cbd5e1; padding: 8px 18px;
	border-radius: 4px; font-size: .95em; cursor: pointer; margin-right: .5em; }
#managed_domain_status[data-state="available"] { color: #1a7f37; }
#managed_domain_status[data-state="unavailable"] { color: #b42318; }
</style>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
