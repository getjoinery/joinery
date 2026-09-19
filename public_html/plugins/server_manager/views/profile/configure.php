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
 * @version 1.2 - the registrant's address and phone are the platform's Address and PhoneNumber form blocks
 * @version 1.1 - the step strip (step 2 on the form, step 3 on the summary)
 * @version 1.0 - specs/managed_hosting_phase1_purchase.md §4.1, §4.2
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_configure_logic.php', 'logic', 'system', null, 'server_manager'));

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
				echo ' — your own domain. When the site is ready we email you the one record to add at your registrar.';
			} ?></dd>
		<dt>Site name</dt>
		<dd><?php echo htmlspecialchars($row->get('cvp_sitename')); ?></dd>
		<?php if (count($regions) > 1): ?>
		<dt>Region</dt>
		<dd><?php echo htmlspecialchars($row->get('cvp_region')); ?></dd>
		<?php endif; ?>
		<dt>Site admin email</dt>
		<dd><?php echo htmlspecialchars($row->get('cvp_buyer_email')); ?></dd>
		<dt>Price</dt>
		<dd><?php if ($price_sentence !== '') {
				echo htmlspecialchars($price_sentence);
				if ($domain_sentence !== '') { echo ', ' . htmlspecialchars($domain_sentence); }
				echo '.';
			} else {
				echo 'Managed hosting is not on sale right now.';
			} ?></dd>
	</dl>

	<?php if ($payment_form !== ''): ?>
		<?php if ($in_cart): ?>
			<p><a href="/cart" class="sms-visit">Finish payment</a>
				<span class="sms-note">This site is already in your cart.</span></p>
		<?php else: ?>
			<?php echo $payment_form; ?>
		<?php endif; ?>
	<?php else: ?>
		<p class="sms-error">Managed hosting is not on sale right now, so this site cannot be paid for yet.
			Your setup is saved.</p>
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
	<?php if ($price_sentence !== ''): ?>Managed hosting is <strong><?php echo htmlspecialchars($price_sentence); ?></strong><?php
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

$registrant_fields = array_merge(array('managed_domain_status', 'md_intro'), array_keys(ManagedDomainIntake::CONTACT_FIELDS));
if ($sellable) {
	$formwriter->radioinput('cvp_domain_source', 'Your domain', array(
		'options' => array(
			'register' => 'Register one for me',
			'own'      => 'I own one already',
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
	'helptext'    => $sellable
		? 'If we register it: ' . $offered_tlds . ' names, priced at cost for the first year. If you own it: exactly as you own it.'
		: 'Exactly as you own it. When the site is ready we email you the one record to add at your registrar.',
	'validation'  => array('required' => true),
));
echo '<div id="managed_domain_status" class="form-text" aria-live="polite"></div>';

if ($sellable) {
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
}

$formwriter->textinput('cvp_sitename', 'Site name', array(
	'value'     => $values['cvp_sitename'],
	'maxlength' => 50,
	'helptext'  => 'Used internally as the site\'s folder and database name: letters, digits and underscores. '
		. 'Leave it blank to use the first part of the domain.',
));

if (count($regions) > 1) {
	$region_options = array();
	foreach ($regions as $region) {
		$region_options[$region] = $region;
	}
	$formwriter->dropinput('cvp_region', 'Where the site lives', array(
		'options' => $region_options, 'value' => $values['cvp_region']));
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

<script>
<?php echo $availability_js; ?>
</script>

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
