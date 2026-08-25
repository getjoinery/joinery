<?php
/**
 * Take ownership of your domain — /profile/server_manager/domain
 *
 * The buyer already owns the domain: their name is the public registration.
 * What still sits with us is management and billing, and this page is how they
 * take that over — three steps, of which only the middle one happens here.
 *
 * The page is honest about the stakes without being alarming: nobody is paying
 * the renewal until they are, and a lapsed domain takes the site and the email
 * address with it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_domain_logic.php', 'logic', 'system', null, 'server_manager'));

$page_vars = process_logic(profile_domain_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Your Domain',
	'breadcrumbs' => array('Your Domain' => ''),
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Your Domain', $hoptions);
?>

<?php if (count($domains) === 0): ?>
	<p>No domain was registered for you through this store. If you bought one and do not see it
	here, get in touch and we will sort it out.</p>
<?php endif; ?>

<?php foreach ($domains as $domain):
	$state = (string)$domain->get('rdm_graduation_state');
	$expiry = $domain->get_local('rdm_expiry_time', 'F j, Y');
	$days = $domain->days_to_expiry();
?>
<section class="smdm-domain">
	<h2><?php echo htmlspecialchars($domain->get('rdm_domain')); ?></h2>

	<p class="smdm-owned">You are the registered owner of this domain — your name is on its public
	registration record, and has been since the day it was bought.</p>

	<?php if ($expiry): ?>
		<p class="smdm-expiry">It is paid up until <strong><?php echo htmlspecialchars($expiry); ?></strong><?php
			if ($days !== null && $days <= 60) { echo ' — that is ' . (int)max(0, $days) . ' days away'; } ?>.</p>
	<?php endif; ?>

<?php if ($state === 'self_custody'): ?>

	<p class="smdm-done">✓ This domain is fully yours. It lives in your own registrar account and we
	are out of the loop entirely.</p>
	<p>One thing worth checking, if you have not already: make sure a payment method is on file and
	auto-renew is on. Nobody else renews this domain — if it lapses, this site and any email address
	on it stop working.</p>

<?php elseif ($state === 'push_requested'): ?>

	<p class="smdm-progress">We are sending <?php echo htmlspecialchars($domain->get('rdm_domain')); ?>
	to <strong><?php echo htmlspecialchars($domain->get('rdm_ncp_username')); ?></strong>, usually
	within a day. Watch for an invitation email from the registrar — you will need to accept it.</p>
	<p>Nothing changes while this happens. Your site and your email keep running, and your DNS
	records travel with the domain.</p>

<?php elseif ($state === 'push_sent'): ?>

	<h3>Finish it in your registrar account</h3>
	<ol class="smdm-steps">
		<li><strong>Accept the invitation.</strong> The registrar emailed a link to
			<strong><?php echo htmlspecialchars($domain->get('rdm_ncp_username')); ?></strong>. It is
			good for seven days — if it has lapsed, tell us and we will send it again.</li>
		<li><strong>Add a payment method.</strong> The domain is on your account now, so its renewal
			is billed to you.</li>
		<li><strong>Turn on auto-renew.</strong> This is the step that actually matters. Without it
			the domain lapses<?php if ($expiry) { echo ' on ' . htmlspecialchars($expiry); } ?> and
			takes this site and your email with it.</li>
	</ol>
	<p class="smdm-note">We will confirm automatically once the domain lands in your account — there
	is nothing to tell us.</p>

<?php else: ?>

	<h3>Move it into your own registrar account</h3>
	<p>Right now the domain is managed and billed through our registrar account. That was so buying
	it could be one click — but it also means we are the ones the renewal bill goes to, and we do not
	pay it. Moving the domain to your own account is what makes the renewal yours to control. It is
	free, it takes about five minutes, and nothing about your site or email changes.</p>

	<ol class="smdm-steps">
		<li><strong>Create a free Namecheap account</strong> if you do not have one —
			<a href="https://www.namecheap.com/myaccount/signup/" target="_blank" rel="noopener">sign up here</a>.
			No card is needed to create it.</li>
		<li><strong>Tell us the account</strong> below, and we will send the domain to it.</li>
		<li><strong>Accept it and turn on auto-renew</strong> in your own dashboard. We will walk you
			through that once the domain is on its way.</li>
	</ol>

<?php
	$formwriter = $page->getFormWriter('smdm_form_' . (int)$domain->key);
	echo $formwriter->begin_form();
	$formwriter->hiddeninput('action', '', array('value' => 'request_push'));
	$formwriter->hiddeninput('rdm_id', '', array('value' => (int)$domain->key));
	$formwriter->textinput('ncp_username', 'Your Namecheap username or account email', array(
		'maxlength' => 128,
		'helptext' => 'Exactly as it appears on your Namecheap account — that is where we send the domain.',
		'validation' => array('required' => true),
	));
	$formwriter->submitbutton('btn_request_push', 'Send the domain to my account');
	echo $formwriter->end_form();
?>

<?php endif; ?>
</section>
<?php endforeach; ?>

<style>
.smdm-domain { margin: 0 0 2.5rem; padding: 0 0 2rem; border-bottom: 1px solid #e2e2e2; }
.smdm-domain:last-child { border-bottom: 0; }
.smdm-owned { color: #1a7f37; }
.smdm-done { color: #1a7f37; font-weight: 600; }
.smdm-progress { background: #eef6ff; padding: 12px 16px; border-radius: 4px; }
.smdm-steps { line-height: 1.7; padding-left: 1.4rem; }
.smdm-steps li { margin-bottom: .5rem; }
.smdm-note { color: #555; }
</style>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
