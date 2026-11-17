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
 * @version 1.14 - payment comes first for every plan; an own-Linode buyer is told the Linode connection follows it
 * @version 1.13 - the summary's price is a table: plan, bundle and domain lines, then the total due today and after
 * @version 1.12 - buttons and colours follow the theme: the page sits in the jy-ui kit, buttons are btn btn-primary / btn-secondary, colours are theme tokens
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
echo '<div class="jy-ui">';
echo $steps_html;
?>

<?php if ($mode === 'summary'): ?>

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
	</dl>

	<?php if ($price_table !== null): ?>
	<table class="smc-prices">
		<thead>
			<tr><th scope="col"></th><th scope="col">Due today</th><th scope="col">After that</th></tr>
		</thead>
		<tbody>
		<?php foreach ($price_table['lines'] as $line): ?>
			<tr>
				<th scope="row"><?php echo htmlspecialchars($line['label']); ?>
					<?php if ($line['note'] !== ''): ?><span><?php echo htmlspecialchars($line['note']); ?></span><?php endif; ?></th>
				<td><?php echo htmlspecialchars($line['today']); ?></td>
				<td><?php echo $line['then'] !== '' ? htmlspecialchars($line['then']) : '&ndash;'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr><th scope="row">Total</th><td><?php echo htmlspecialchars($price_table['today']); ?></td>
				<td><?php echo htmlspecialchars($price_table['then']); ?></td></tr>
		</tfoot>
	</table>
	<?php endif; ?>

	<div class="smc-next">
	<?php if ($payment_form !== ''): ?>
		<?php if ($own_linode): ?>
		<p>After payment, you'll connect your Linode account and we'll build your site there.</p>
		<?php endif; ?>
		<?php if ($in_cart): ?>
			<a href="/cart" class="btn btn-primary">Finish payment</a>
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
			'class'   => 'btn btn-secondary',
		)); ?>
		<?php echo PublicPage::action_button('Delete', ManagedSiteDraft::CONFIGURE_URL, array(
			'hidden'  => array('action' => 'delete', 'cvp_customer_cloud_provision_id' => (int)$row->key),
			'confirm' => 'Remove this site setup? Nothing has been bought or created.',
			'class'   => 'btn btn-secondary',
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
.smc-lead { margin: 0 0 1.5rem; color: var(--jy-color-text-muted); }
.smc-card { background: var(--jy-color-bg); border: 1px solid var(--jy-color-border); border-radius: 10px; padding: 1.25rem 1.5rem 1.4rem;
	margin: 0 0 1.25rem; max-width: 760px; }
.smc-card .smc-title { margin: 0 0 1rem; font-size: 1.05rem; font-weight: 600; color: var(--jy-color-text); letter-spacing: 0; }
.smc-card .smc-title-lg { font-size: 1.4rem; margin-bottom: 1.25rem; }
/* Each choice is a tile: the whole box is the label, the chosen one is marked. */
.smc-card .form-check { position: relative; margin: 0 0 .6rem; padding: .8rem 1rem .8rem 2.6rem;
	border: 1px solid var(--jy-color-border); border-radius: 8px; background: var(--jy-color-bg); transition: border-color .15s, background .15s; }
.smc-card .form-check:last-child { margin-bottom: 0; }
.smc-card .form-check:hover { border-color: var(--jy-color-border-strong); }
.smc-card .form-check:has(input:checked) { border-color: var(--jy-color-primary); background: color-mix(in srgb, var(--jy-color-primary) 6%, var(--jy-color-bg)); }
.smc-card .form-check-input { position: absolute; left: 1rem; top: 1.05rem; margin: 0; }
.smc-card .form-check-label { display: block; line-height: 1.45; cursor: pointer; }
.smc-card .mb-3:last-child, .smc-card .form-group:last-child { margin-bottom: 0; }
.smc-subsection { margin-top: 1.25rem; padding-top: 1.1rem; border-top: 1px solid var(--jy-color-border); }
.smc-subhead { font-weight: 600; margin: 0 0 .75rem; }
.smc-subhead span { display: block; font-weight: 400; color: var(--jy-color-text-muted); font-size: .9em; margin-top: .15rem; }
.smc-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
@media (max-width: 600px) { .smc-row { grid-template-columns: 1fr; } }
.smc-indent { margin: .6rem 0 0; color: var(--jy-color-text-muted); font-size: .92em; }
/* What a plan or the bundle brings, as a short list under its choice. */
.smc-card .jy-radio-card-desc { display: block; margin-top: .4rem; }
.smc-card .jy-radio-card-desc span { display: block; position: relative; padding-left: 1.1rem; color: var(--jy-color-text-muted);
	font-size: .93em; line-height: 1.5; }
.smc-card .jy-radio-card-desc span::before { content: "\2713"; position: absolute; left: 0; color: var(--jy-color-primary); }
/* On your own Linode: what is left to the buyer is not ticked. */
.smc-card #plan_own_linode ~ label .jy-radio-card-desc span:nth-child(2)::before { content: "\2013"; color: var(--jy-color-text-subtle); }
.smc-features { list-style: none; margin: .75rem 0 0; padding: 0; }
.smc-features li { position: relative; padding-left: 1.1rem; color: var(--jy-color-text-muted); font-size: .93em; line-height: 1.6; }
.smc-features li::before { content: "\2713"; position: absolute; left: 0; color: var(--jy-color-primary); }
.smc-submit { margin: 1.5rem 0 2rem; }
.smc-facts { display: grid; grid-template-columns: max-content 1fr; gap: .6rem 2rem; margin: 0 0 1.5rem; }
.smc-facts dt { color: var(--jy-color-text-muted); font-weight: 500; }
.smc-facts dd { margin: 0; color: var(--jy-color-text); }
/* A receipt, not a data grid: the kit's table bands and capitals are left off. */
.jy-ui table.smc-prices { width: 100%; border-collapse: collapse; margin: 0 0 1.5rem; font-size: 1em;
	font-variant-numeric: tabular-nums; }
.jy-ui table.smc-prices th, .jy-ui table.smc-prices td { padding: .65rem 0; background: none; text-transform: none;
	letter-spacing: 0; font-size: 1em; color: var(--jy-color-text); border-bottom: 1px solid var(--jy-color-border);
	vertical-align: top; text-align: left; }
.jy-ui table.smc-prices tbody tr td, .jy-ui table.smc-prices tbody tr:hover td { background: none; }
.jy-ui table.smc-prices td { text-align: right; padding-left: 1.5rem; white-space: nowrap; }
.jy-ui table.smc-prices thead th { font-size: .85em; font-weight: 500; color: var(--jy-color-text-muted);
	text-align: right; padding-top: 0; white-space: nowrap; }
.jy-ui table.smc-prices thead th:first-child { text-align: left; }
.jy-ui table.smc-prices tbody th { font-weight: 500; }
.jy-ui table.smc-prices tbody th span { display: block; font-weight: 400; font-size: .88em;
	color: var(--jy-color-text-muted); margin-top: .2rem; white-space: normal; }
.jy-ui table.smc-prices tbody tr:last-child td, .jy-ui table.smc-prices tbody tr:last-child th { border-bottom: 0; }
.jy-ui table.smc-prices tfoot th, .jy-ui table.smc-prices tfoot td { font-weight: 700; border-bottom: 0;
	border-top: 2px solid var(--jy-color-border-strong); }
.smc-next { padding-top: 1.25rem; border-top: 1px solid var(--jy-color-border); }
.smc-next h3 { margin-bottom: .35rem; }
.smc-next p { margin: 0 0 1rem; color: var(--jy-color-text-muted); }
.smc-actions { margin-top: 1.5rem; }
.smc-actions form { display: inline-block; margin-right: .5em; }
.sms-error { padding: 12px 16px; background: var(--jy-color-danger-bg); border-radius: 6px; margin-bottom: 1.25rem; }
.sms-error ul { margin: 0; padding-left: 1.2em; }
.sms-note { color: var(--jy-color-text-muted); font-size: .9em; margin-left: .6em; }
.sms-pay { display: inline-block; margin: 0; }
#managed_domain_status[data-state="available"] { color: var(--jy-color-success); }
#managed_domain_status[data-state="unavailable"] { color: var(--jy-color-danger); }
</style>

<?php
echo '</div>';
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
