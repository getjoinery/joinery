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
 * @version 1.11 - plans list what they bring; the bundle card is the Joinery Managed Bundle with the same list; the
 *                sign-in card asks for an existing, working email
 * @version 1.10 - the summary names the domain's yearly renewal price
 * @version 1.9 - less text and a calmer layout: the form is four cards (plan, domain, email and backups, sign-in),
 *                the summary one card; no server size, region or after-payment notes
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

<?php
// The price as the buyer pays it: the plan, the bundle when chosen, the domain year.
$price_parts = array();
if ($price_sentence === 'free') {
	$price_parts[] = 'Free';
} elseif ($price_sentence !== '') {
	$price_parts[] = preg_replace('/ from today$/', '', $price_sentence);
}
if ($own_linode && $row->get('cvp_services_wanted') && $bundle_price !== '') {
	$price_parts[] = $bundle_price . ' for the Joinery Managed Bundle';
}
if ($domain_sentence !== '') {
	$price_parts[] = preg_replace('/^and /', '', $domain_sentence);
}
?>
<section class="smc-card smc-summary">
	<h2 class="smc-title smc-title-lg"><?php echo htmlspecialchars($row->get('cvp_domain')); ?></h2>
	<dl class="smc-facts">
		<dt>Plan</dt>
		<dd><?php echo htmlspecialchars($plan_label); ?></dd>
		<dt>Domain</dt>
		<dd><?php echo (string)$row->get('cvp_domain_source') === 'register' ? 'We register it for you' : 'Your own'; ?></dd>
		<?php if ($own_linode): ?>
		<dt>Joinery Managed Bundle</dt>
		<dd><?php echo $row->get('cvp_services_wanted') ? 'Included' : 'Not included'; ?></dd>
		<?php endif; ?>
		<dt>Admin sign-in</dt>
		<dd><?php echo htmlspecialchars($row->get('cvp_buyer_email')); ?></dd>
		<dt>Price</dt>
		<dd><?php echo $price_parts ? htmlspecialchars(implode(', plus ', $price_parts)) : htmlspecialchars($plan_label) . ' is not on sale right now'; ?></dd>
		<?php if (($renewal = ManagedSiteDraft::domain_renewal_sentence($row)) !== ''): ?>
		<dt>Domain renewal</dt>
		<dd><?php echo htmlspecialchars($renewal); ?></dd>
		<?php endif; ?>
	</dl>

	<div class="smc-next">
	<?php if ($own_linode && !$linode_live && $product !== null): ?>
		<h3 class="smc-title">Connect your Linode account</h3>
		<p>We'll create your server there. We only use this access to set up your site.</p>
		<?php if ($linode_connected): ?>
		<p class="sms-error">That connection didn't work. Please connect again.</p>
		<?php endif; ?>
		<?php echo PublicPage::action_button('Connect your Linode account', '/profile/server_manager/connect_cloud', array(
			'hidden' => array('action' => 'connect', 'cvp_customer_cloud_provision_id' => (int)$row->key),
			'class'  => 'sms-visit',
		)); ?>
	<?php elseif ($payment_form !== ''): ?>
		<?php if ($in_cart): ?>
			<a href="/cart" class="sms-visit">Finish payment</a>
			<span class="sms-note">Already in your cart.</span>
		<?php else: ?>
			<?php echo $payment_form; ?>
		<?php endif; ?>
	<?php else: ?>
		<p class="sms-error"><?php echo htmlspecialchars($plan_label); ?> isn't available right now. Your setup is saved;
			edit it to choose another plan.</p>
	<?php endif; ?>
	</div>

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

<p class="smc-lead">Tell us about your site. Nothing is charged until checkout.</p>

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

// ── Plan ──
// The choice shows when there is one to make, and when the draft's own plan
// is not on sale now: then its buyer picks the one that is, knowingly.
$show_plans = count($plans) > 1 || ($row !== null && !isset($plans[ManagedSiteDraft::plan_of($row)]));
if ($show_plans || count($regions) > 1) {
	echo '<section class="smc-card"><h3 class="smc-title">Plan</h3>';
	if ($show_plans) {
		$plan_options = array();
		$plan_features = array();
		foreach ($plans as $plan => $def) {
			$price = $plan_prices[$plan] === 'free' ? 'Free' : preg_replace('/ from today$/', '', $plan_prices[$plan]);
			$plan_options[$plan] = $def['label'] . ($price !== '' ? ' — ' . $price : '');
			$plan_features[$plan] = $def['features'];
		}
		$plan_rules = array();
		foreach ($plans as $plan => $def) {
			$plan_rules[$plan] = $plan === 'own_linode'
				? array('show' => array('smc_services'), 'hide' => array('cvp_region'))
				: array('hide' => array('smc_services'), 'show' => array('cvp_region'));
		}
		$formwriter->radioinput('plan', '', array(
			'options'          => $plan_options,
			'descriptions'     => $plan_features,
			'value'            => $values['plan'],
			'visibility_rules' => $plan_rules,
		));
	}
	if (count($regions) > 1) {
		$region_options = array();
		foreach ($regions as $region) {
			$region_options[$region] = $region;
		}
		$formwriter->dropinput('cvp_region', 'Where the site lives', array(
			'options' => $region_options, 'value' => $values['cvp_region']));
	}
	echo '</section>';
}

// ── Domain ──
echo '<section class="smc-card"><h3 class="smc-title">Domain</h3>';
$registrant_fields = array_merge(array('managed_domain_status', 'md_intro'), array_keys(ManagedDomainIntake::CONTACT_FIELDS));
if ($sellable) {
	$formwriter->radioinput('cvp_domain_source', '', array(
		'options' => array(
			'register' => 'Register a new domain for me',
			'own'      => 'I already own a domain',
		),
		'value' => $values['cvp_domain_source'],
		'visibility_rules' => array(
			'register' => array('show' => $registrant_fields),
			'own'      => array('hide' => $registrant_fields),
		),
	));
} else {
	$formwriter->hiddeninput('cvp_domain_source', '', array('value' => 'own'));
}

$formwriter->textinput('cvp_domain', 'Domain name', array(
	'value'       => $values['cvp_domain'],
	'maxlength'   => 253,
	'placeholder' => 'smithfamily.com',
	'validation'  => array('required' => true),
));
echo '<div id="managed_domain_status" class="form-text" aria-live="polite"></div>';

if ($sellable) {
	// Asked once the name is known to be free. A saved draft, or a form shown
	// again with errors, stays open: the name prefills from the account, so it
	// says nothing about whether the buyer has answered.
	$registrant_kept = $row !== null || !empty($errors);
	echo '<div id="md_registrant" class="smc-subsection"' . ($registrant_kept ? ' data-keep="1"' : '') . '>';
	echo '<p id="md_intro" class="smc-subhead">Registration contact <span>Kept private from public lookups, at no cost.</span></p>';
	echo '<div class="smc-row">';
	$formwriter->textinput('md_first_name', 'First name', array('value' => $values['md_first_name'] ?? '', 'maxlength' => 255));
	$formwriter->textinput('md_last_name', 'Last name', array('value' => $values['md_last_name'] ?? '', 'maxlength' => 255));
	echo '</div>';

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

	$formwriter->textinput('md_email', 'Contact email', array(
		'value' => $values['md_email'] ?? '', 'maxlength' => 255));
	echo '</div>';
}
echo '</section>';

// ── Email and backups (On your own Linode only; unticked, Q7) ──
if (isset($plans['own_linode']) && $bundle_price !== '') {
	echo '<section class="smc-card" id="smc_services"><h3 class="smc-title">Joinery Managed Bundle</h3>';
	$formwriter->checkboxinput('with_services', 'Add the Joinery Managed Bundle — ' . $bundle_price, array(
		'checked' => $values['with_services'] !== '',
	));
	echo '<ul class="smc-features">';
	foreach (ManagedSiteDraft::BUNDLE_FEATURES as $feature) {
		echo '<li>' . htmlspecialchars($feature) . '</li>';
	}
	echo '</ul>';
	echo '</section>';
}

// ── Sign-in ──
echo '<section class="smc-card"><h3 class="smc-title">Your sign-in and backup email</h3>';
$formwriter->textinput('cvp_buyer_email', 'Existing email', array(
	'value'      => $values['cvp_buyer_email'],
	'maxlength'  => 255,
	'helptext'   => 'This must be a currently working email.',
	'validation' => array('required' => true),
));
echo '</section>';

echo '<div class="smc-submit">';
$formwriter->submitbutton('btn_save', 'Continue');
echo '</div>';
echo $formwriter->end_form();
?>

<?php echo $availability_html; ?>

<?php endif; ?>

<style>
.smc-lead { margin: 0 0 1.5rem; color: #4b5563; }
.smc-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.25rem 1.5rem 1.4rem;
	margin: 0 0 1.25rem; max-width: 760px; }
.smc-card .smc-title { margin: 0 0 1rem; font-size: 1.05rem; font-weight: 600; color: #111827; letter-spacing: 0; }
.smc-card .smc-title-lg { font-size: 1.4rem; margin-bottom: 1.25rem; }
/* Each choice is a tile: the whole box is the label, the chosen one is marked. */
.smc-card .form-check { position: relative; margin: 0 0 .6rem; padding: .8rem 1rem .8rem 2.6rem;
	border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; transition: border-color .15s, background .15s; }
.smc-card .form-check:last-child { margin-bottom: 0; }
.smc-card .form-check:hover { border-color: #cbd5e1; }
.smc-card .form-check:has(input:checked) { border-color: #02b159; background: #f0fdf4; }
.smc-card .form-check-input { position: absolute; left: 1rem; top: 1.05rem; margin: 0; }
.smc-card .form-check-label { display: block; line-height: 1.45; cursor: pointer; }
.smc-card .mb-3:last-child, .smc-card .form-group:last-child { margin-bottom: 0; }
.smc-subsection { margin-top: 1.25rem; padding-top: 1.1rem; border-top: 1px solid #f0f1f3; }
.smc-subhead { font-weight: 600; margin: 0 0 .75rem; }
.smc-subhead span { display: block; font-weight: 400; color: #6b7280; font-size: .9em; margin-top: .15rem; }
.smc-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
@media (max-width: 600px) { .smc-row { grid-template-columns: 1fr; } }
.smc-indent { margin: .6rem 0 0; color: #6b7280; font-size: .92em; }
/* What a plan or the bundle brings, as a short list under its choice. */
.smc-card .jy-radio-card-desc { display: block; margin-top: .4rem; }
.smc-card .jy-radio-card-desc span { display: block; position: relative; padding-left: 1.1rem; color: #4b5563;
	font-size: .93em; line-height: 1.5; }
.smc-card .jy-radio-card-desc span::before { content: "\2713"; position: absolute; left: 0; color: #02b159; }
/* On your own Linode: what is left to the buyer is not ticked. */
.smc-card #plan_own_linode ~ label .jy-radio-card-desc span:nth-child(2)::before { content: "\2013"; color: #9ca3af; }
.smc-features { list-style: none; margin: .75rem 0 0; padding: 0; }
.smc-features li { position: relative; padding-left: 1.1rem; color: #4b5563; font-size: .93em; line-height: 1.6; }
.smc-features li::before { content: "\2713"; position: absolute; left: 0; color: #02b159; }
.smc-submit { margin: 1.5rem 0 2rem; }
.smc-facts { display: grid; grid-template-columns: max-content 1fr; gap: .6rem 2rem; margin: 0 0 1.5rem; }
.smc-facts dt { color: #6b7280; font-weight: 500; }
.smc-facts dd { margin: 0; color: #111827; }
.smc-next { padding-top: 1.25rem; border-top: 1px solid #f0f1f3; }
.smc-next h3 { margin-bottom: .35rem; }
.smc-next p { margin: 0 0 1rem; color: #4b5563; }
.smc-actions { margin-top: 1.5rem; }
.sms-error { padding: 12px 16px; background: #fef2f2; border-radius: 6px; margin-bottom: 1.25rem; }
.sms-error ul { margin: 0; padding-left: 1.2em; }
.sms-note { color: #6b7280; font-size: .9em; margin-left: .6em; }
.sms-visit { display: inline-block; background: #02b159; color: #fff; padding: 10px 22px; border: 0;
	border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 1em; cursor: pointer; }
.sms-pay { display: inline-block; margin: 0; }
.sms-secondary-btn { background: #fff; color: #1f2937; border: 1px solid #cbd5e1; padding: 8px 18px;
	border-radius: 6px; font-size: .95em; cursor: pointer; margin-right: .5em; }
#managed_domain_status[data-state="available"] { color: #1a7f37; }
#managed_domain_status[data-state="unavailable"] { color: #b42318; }
</style>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
