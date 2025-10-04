<?php

	require_once(PathHelper::getIncludePath('/includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$session = SessionControl::get_instance();
	$session_id = $_GET['session_id'] ?? null;

	$settings = Globalvars::get_instance();

	$cart = $session->get_shopping_cart();
	$receipts = $cart->last_receipt;

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => "Checkout confirmation"
	));
	echo PublicPage::BeginPage('Checkout confirmation');

	if($receipts){

		?>
		<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
			<p class="mt-3 text-base text-gray-500">Thank you for your purchase. An email has been sent to the email address of all registrants with your purchase confirmation and a link to provide any further info that we need.</p>

			<div class="mt-6 bg-white rounded-lg shadow-md overflow-hidden">
				<table class="min-w-full divide-y divide-gray-200">
					<thead class="bg-gray-50">
						<tr>
							<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
							<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
						</tr>
					</thead>
					<tbody class="bg-white divide-y divide-gray-200">
		<?php
		$total = 0;
		foreach($receipts as $rkey => $receipt) {
			$total += $receipt['price'];
			echo '<tr>';
			echo '<td class="px-6 py-4 text-sm text-gray-900">'.htmlspecialchars($receipt['pname'] . ' ('. $receipt['name']. ') ').'</td>';
			echo '<td class="px-6 py-4 text-sm text-gray-900">$' . number_format($receipt['price'], 2, '.', ',').'</td>';
			echo '</tr>';
		}
		echo '<tr class="bg-gray-50">';
		echo '<td class="px-6 py-4 text-sm font-bold text-gray-900">Total</td>';
		echo '<td class="px-6 py-4 text-sm font-bold text-gray-900">$' . number_format($total, 2, '.', ',').'</td>';
		echo '</tr>';
		?>
					</tbody>
				</table>
			</div>

			<div class="mt-6 text-base text-gray-500">
				<p>All of your purchases can be found in the <a href="/profile" class="text-blue-600 hover:text-blue-700">My Profile</a> section of the website.</p>
				<p class="mt-2"><a href="/profile" class="text-blue-600 hover:text-blue-700">See all of your purchases</a></p>
			</div>
		</div>
		<?php
	}
	else{
		$settings = Globalvars::get_instance();
		$defaultemail = $settings->get_setting('defaultemail');

		?>
		<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
			<p class="mt-3 text-base text-gray-500">Your recent purchase is not available. It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>
			<p class="mt-3 text-base text-gray-500">If you think something is wrong, please contact us at <a href="mailto:<?php echo htmlspecialchars($defaultemail); ?>" class="text-blue-600 hover:text-blue-700"><?php echo htmlspecialchars($defaultemail); ?></a>.</p>
		</div>
		<?php

	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>
